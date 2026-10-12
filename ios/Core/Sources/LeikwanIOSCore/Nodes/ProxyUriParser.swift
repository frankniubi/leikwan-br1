import Foundation

/// Nodes parsed from a text blob plus the number of non-empty lines that were not importable.
public struct ProxyParseResult: Equatable {
    public var nodes: [ProxyNode]
    public var skippedLines: Int

    public init(nodes: [ProxyNode], skippedLines: Int) {
        self.nodes = nodes
        self.skippedLines = skippedLines
    }
}

/// Port of `proxy/ProxyUriParser.kt` for `ss://` and `socks://` / `socks5://`.
/// Other schemes (vmess, vless, trojan, wg, ...) return nil and are counted as skipped by `parseMultiple`.
public enum ProxyUriParser {
    public static func parse(_ uri: String) -> ProxyNode? {
        let trimmed = uri.trimmingCharacters(in: .whitespacesAndNewlines)
        let lower = trimmed.lowercased()
        if trimmed.hasPrefix("ss://") {
            return parseShadowsocks(trimmed)
        }
        if lower.hasPrefix("socks://") || lower.hasPrefix("socks5://") {
            return parseSocks(trimmed)
        }
        return nil
    }

    /// Subscription body / pasted text: whole-text base64 first, then one URI per line.
    public static func parseMultiple(_ text: String) -> ProxyParseResult {
        let trimmedInput = text.trimmingCharacters(in: .whitespacesAndNewlines)
        let decoded = FlexibleBase64.decodeString(trimmedInput) ?? text
        let decodedTrimmed = decoded.trimmingCharacters(in: .whitespacesAndNewlines)
        if let single = parse(decodedTrimmed), !decodedTrimmed.contains("\n") {
            return ProxyParseResult(nodes: [single], skippedLines: 0)
        }
        var nodes: [ProxyNode] = []
        var skipped = 0
        for line in lines(of: decoded) {
            if let node = parse(line) {
                nodes.append(node)
            } else {
                skipped += 1
            }
        }
        return ProxyParseResult(nodes: nodes, skippedLines: skipped)
    }

    /// Non-empty trimmed lines (handles \n, \r\n and \r).
    static func lines(of text: String) -> [String] {
        let normalized = text.replacingOccurrences(of: "\r\n", with: "\n").replacingOccurrences(of: "\r", with: "\n")
        return normalized.components(separatedBy: "\n")
            .map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }
    }

    // MARK: Shadowsocks (SIP002, legacy base64, plugin, SS-2022 percent-encoded keys)

    static func parseShadowsocks(_ uri: String) -> ProxyNode? {
        let withoutScheme = String(uri.dropFirst("ss://".count))
        let (mainPart, fragment) = split(withoutScheme, at: "#")
        var name: String?
        if let fragment = fragment {
            guard let decodedName = PercentCoding.formDecode(fragment) else { return nil }
            name = decodedName.isEmpty ? nil : decodedName
        }
        let (rawAuthority, queryPart) = split(mainPart, at: "?")
        // SIP002 allows `ss://userinfo@host:port/?plugin=...`.
        var authorityPart = rawAuthority
        while authorityPart.hasSuffix("/") { authorityPart.removeLast() }

        let server: String
        let port: Int
        let method: String
        let password: String

        if let at = authorityPart.firstIndex(of: "@") {
            let userInfo = String(authorityPart[..<at])
            let hostPort = String(authorityPart[authorityPart.index(after: at)...])
            if let decoded = tryBase64Text(userInfo), let colon = decoded.firstIndex(of: ":") {
                method = String(decoded[..<colon])
                password = String(decoded[decoded.index(after: colon)...])
            } else {
                // Percent-decoding only: a raw '+' in an SS-2022 key must survive.
                guard let plaintext = PercentCoding.percentDecode(userInfo), let colon = plaintext.firstIndex(of: ":") else {
                    return nil
                }
                method = String(plaintext[..<colon])
                password = String(plaintext[plaintext.index(after: colon)...])
            }
            guard let parsed = parseHostPort(hostPort) else { return nil }
            server = parsed.host
            port = parsed.port
        } else {
            guard let decoded = FlexibleBase64.decodeString(authorityPart),
                  let colon = decoded.firstIndex(of: ":"),
                  let at = decoded.lastIndex(of: "@"),
                  colon < at
            else { return nil }
            method = String(decoded[..<colon])
            password = String(decoded[decoded.index(after: colon)..<at])
            guard let parsed = parseHostPort(String(decoded[decoded.index(after: at)...])) else { return nil }
            server = parsed.host
            port = parsed.port
        }
        if method.trimmingCharacters(in: .whitespaces).isEmpty { return nil }

        var settings = ShadowsocksSettings(method: method, password: password)
        if let query = queryPart {
            guard applyShadowsocksQuery(query, to: &settings) else { return nil }
        }
        return ProxyNode(
            name: name ?? "\(server):\(port)",
            server: server,
            port: port,
            settings: .shadowsocks(settings)
        )
    }

    static func applyShadowsocksQuery(_ query: String, to settings: inout ShadowsocksSettings) -> Bool {
        for pair in query.components(separatedBy: "&") where !pair.trimmingCharacters(in: .whitespaces).isEmpty {
            let (rawKey, rawValue) = split(pair, at: "=")
            guard let keyDecoded = PercentCoding.formDecode(rawKey),
                  let valueDecoded = PercentCoding.formDecode(rawValue ?? "")
            else { return false }
            let key = keyDecoded.trimmingCharacters(in: .whitespaces)
            let value = valueDecoded.trimmingCharacters(in: .whitespaces)
            switch key {
            case "plugin":
                let (pluginName, pluginOpts) = split(value, at: ";")
                let nameTrimmed = pluginName.trimmingCharacters(in: .whitespaces)
                let optsTrimmed = (pluginOpts ?? "").trimmingCharacters(in: .whitespaces)
                if !nameTrimmed.isEmpty { settings.plugin = nameTrimmed }
                if !optsTrimmed.isEmpty { settings.pluginOpts = optsTrimmed }
            case "plugin_opts":
                if !value.isEmpty { settings.pluginOpts = value }
            case "network":
                if !value.isEmpty { settings.network = value }
            case "uot", "udp_over_tcp":
                settings.udpOverTcp = truthy(value)
            case "multiplex":
                settings.multiplexEnabled = truthy(value)
            default:
                if !value.isEmpty && !key.isEmpty { settings.extra[key] = value }
            }
        }
        return true
    }

    // MARK: SOCKS5 (plain, percent-encoded or base64 userinfo, Shadowrocket whole-authority base64)

    static func parseSocks(_ uri: String) -> ProxyNode? {
        guard let schemeEnd = uri.range(of: "://") else { return nil }
        let rest = String(uri[schemeEnd.upperBound...])
        let (beforeFragment, fragment) = split(rest, at: "#")
        let (beforeQuery, query) = split(beforeFragment, at: "?")
        var authority = beforeQuery
        // `socks5://host:port/` - drop the empty path (base64 authorities may contain '/').
        while authority.hasSuffix("/") { authority.removeLast() }

        var userInfo: String?
        var hostPortText = authority
        if let at = authority.lastIndex(of: "@") {
            userInfo = String(authority[..<at])
            hostPortText = String(authority[authority.index(after: at)...])
        }
        var hostPort = parseHostPort(hostPortText)
        // Credentials that are already plaintext (no further percent-decoding).
        var plainCredentials: String?
        if hostPort == nil && userInfo == nil {
            // Shadowrocket: socks://base64(user:pass@host:port) or base64(host:port).
            guard let decoded = tryBase64Text(PercentCoding.percentDecode(authority) ?? authority) else { return nil }
            if let at = decoded.lastIndex(of: "@") {
                plainCredentials = String(decoded[..<at])
                hostPort = parseHostPort(String(decoded[decoded.index(after: at)...]))
            } else {
                hostPort = parseHostPort(decoded)
            }
        }
        guard let target = hostPort else { return nil }

        if plainCredentials == nil, let info = userInfo, !info.isEmpty, !info.contains(":"),
           let decoded = tryBase64Text(PercentCoding.percentDecode(info) ?? info), decoded.contains(":") {
            // Shadowrocket: socks5://base64(user:pass)@host:port
            plainCredentials = decoded
        }

        var username: String?
        var password: String?
        if let plain = plainCredentials {
            let (user, pass) = split(plain, at: ":")
            username = user
            password = pass ?? ""
        } else if let info = userInfo, !info.isEmpty {
            let (rawUser, rawPass) = split(info, at: ":")
            guard let user = PercentCoding.formDecode(rawUser) else { return nil }
            guard let pass = PercentCoding.formDecode(rawPass ?? "") else { return nil }
            username = user
            password = pass
        }

        var name: String?
        if let fragment = fragment, let decoded = PercentCoding.formDecode(fragment),
           !decoded.trimmingCharacters(in: .whitespaces).isEmpty {
            name = decoded
        } else if let query = query {
            for pair in query.components(separatedBy: "&") {
                let (key, value) = split(pair, at: "=")
                if key == "remarks", let value = value, let decoded = PercentCoding.formDecode(value),
                   !decoded.trimmingCharacters(in: .whitespaces).isEmpty {
                    name = decoded
                }
            }
        }

        let settings = SocksSettings(
            username: (username?.isEmpty ?? true) ? nil : username,
            password: (password?.isEmpty ?? true) ? nil : password
        )
        return ProxyNode(
            name: name ?? "\(target.host):\(target.port)",
            server: target.host,
            port: target.port,
            settings: .socks(settings)
        )
    }

    // MARK: Helpers

    /// Base64 text without control characters (Android `tryBase64Decode`).
    static func tryBase64Text(_ input: String) -> String? {
        guard let decoded = FlexibleBase64.decodeString(input) else { return nil }
        for scalar in decoded.unicodeScalars where scalar.value < 32 && scalar != "\n" && scalar != "\r" && scalar != "\t" {
            return nil
        }
        return decoded
    }

    /// `host:port` / `[v6]:port`. Brackets are stripped from IPv6 hosts; port must be 1...65535.
    static func parseHostPort(_ text: String) -> (host: String, port: Int)? {
        let trimmed = text.trimmingCharacters(in: .whitespaces)
        if trimmed.hasPrefix("[") {
            guard let close = trimmed.firstIndex(of: "]") else { return nil }
            let host = String(trimmed[trimmed.index(after: trimmed.startIndex)..<close])
            let after = trimmed[trimmed.index(after: close)...]
            guard after.hasPrefix(":"), let port = Int(after.dropFirst()), port >= 1, port <= 65535, !host.isEmpty else { return nil }
            return (host, port)
        }
        guard let colon = trimmed.lastIndex(of: ":") else { return nil }
        let host = String(trimmed[..<colon])
        guard let port = Int(trimmed[trimmed.index(after: colon)...]), port >= 1, port <= 65535,
              !host.trimmingCharacters(in: .whitespaces).isEmpty
        else { return nil }
        return (host, port)
    }

    /// Splits at the first `separator`; second part is nil when absent.
    static func split(_ text: String, at separator: Character) -> (String, String?) {
        guard let index = text.firstIndex(of: separator) else { return (text, nil) }
        return (String(text[..<index]), String(text[text.index(after: index)...]))
    }

    static func truthy(_ value: String) -> Bool {
        switch value.lowercased() {
        case "1", "true", "yes", "on": return true
        default: return false
        }
    }
}
