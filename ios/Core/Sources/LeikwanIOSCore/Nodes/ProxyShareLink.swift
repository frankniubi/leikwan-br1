import Foundation

/// Canonical share links for iOS nodes, in the panel's `HostedNodeSpec::toShareUri` format, so an
/// upload and the listing the panel returns describe the node the same way. `ProxyUriParser`
/// reads them back.
public enum ProxyShareLink {
    /// AEAD / 2022 methods the panel accepts for hosted Shadowsocks nodes (HostedNodeSpec::SS_METHODS).
    public static let cloudShadowsocksMethods: Set<String> = [
        "2022-blake3-aes-128-gcm",
        "2022-blake3-aes-256-gcm",
        "2022-blake3-chacha20-poly1305",
        "aes-128-gcm",
        "aes-192-gcm",
        "aes-256-gcm",
        "chacha20-ietf-poly1305",
        "xchacha20-ietf-poly1305",
    ]

    /// Plugins the panel accepts (simple-obfs is stored as obfs-local).
    public static let cloudShadowsocksPlugins: Set<String> = ["obfs-local", "v2ray-plugin"]

    /// `ss://base64url(method:password)@host:port?plugin=..&uot=1#name` or
    /// `socks5://user:pass@host:port#name`. Nil when the node has no server or a bad port.
    public static func format(_ node: ProxyNode) -> String? {
        let server = node.server.trimmingCharacters(in: .whitespacesAndNewlines)
        if server.isEmpty || node.port < 1 || node.port > 65535 { return nil }
        let host = uriHost(server)
        let fragment = "#" + rawURLEncode(node.name.trimmingCharacters(in: .whitespacesAndNewlines))
        switch node.settings {
        case .shadowsocks(let ss):
            let userInfo = base64URLNoPadding(ss.method + ":" + ss.password)
            var query: [String] = []
            let plugin = normalizePlugin(ss.plugin)
            if !plugin.isEmpty {
                var value = plugin
                let opts = (ss.pluginOpts ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                if !opts.isEmpty { value += ";" + opts }
                query.append("plugin=" + rawURLEncode(value))
            }
            if ss.udpOverTcp == true {
                query.append("uot=1")
            }
            let queryText = query.isEmpty ? "" : "?" + query.joined(separator: "&")
            return "ss://" + userInfo + "@" + host + ":" + String(node.port) + queryText + fragment
        case .socks(let socks):
            var auth = ""
            let user = socks.username ?? ""
            if !user.isEmpty {
                auth = rawURLEncode(user) + ":" + rawURLEncode(socks.password ?? "") + "@"
            }
            return "socks5://" + auth + host + ":" + String(node.port) + fragment
        }
    }

    /// Whether the panel would accept this node as a hosted node (checked before uploading so
    /// "sync all" can count unsupported nodes instead of failing them).
    public static func isCloudUploadable(_ node: ProxyNode) -> Bool {
        guard format(node) != nil else { return false }
        switch node.settings {
        case .shadowsocks(let ss):
            let method = ss.method.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if !cloudShadowsocksMethods.contains(method) || ss.password.isEmpty { return false }
            let plugin = normalizePlugin(ss.plugin)
            return plugin.isEmpty || cloudShadowsocksPlugins.contains(plugin)
        case .socks(let socks):
            let user = socks.username ?? ""
            let pass = socks.password ?? ""
            return user.isEmpty == pass.isEmpty
        }
    }

    /// Lowercased plugin name; `simple-obfs` is the SIP003 alias of `obfs-local`.
    public static func normalizePlugin(_ plugin: String?) -> String {
        let name = (plugin ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return name == "simple-obfs" ? "obfs-local" : name
    }

    /// PHP `rawurlencode`: everything except RFC 3986 unreserved characters is escaped.
    static func rawURLEncode(_ value: String) -> String {
        var allowed = CharacterSet()
        allowed.insert(charactersIn: "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.~")
        return value.addingPercentEncoding(withAllowedCharacters: allowed) ?? value
    }

    static func base64URLNoPadding(_ text: String) -> String {
        var encoded = Data(text.utf8).base64EncodedString()
        encoded = encoded.replacingOccurrences(of: "+", with: "-").replacingOccurrences(of: "/", with: "_")
        while encoded.hasSuffix("=") { encoded.removeLast() }
        return encoded
    }

    static func uriHost(_ server: String) -> String {
        if server.hasPrefix("[") { return server }
        return server.contains(":") ? "[" + server + "]" : server
    }
}

/// Connection identity of a node, independent of its name and of how a share link spelled it
/// (port of Android `HostedNodeIdentity` / desktop `UserNodeIdentity` for ss + socks5).
///
/// Used for dedup in both directions: a cloud node equal to a node already on this device adopts
/// that row (keeps its local id, so bindings and the selection stay valid), and an imported link
/// equal to a cloud node is skipped.
public enum ProxyNodeIdentity {
    public static func key(_ node: ProxyNode) -> String {
        var server = node.server.trimmingCharacters(in: .whitespacesAndNewlines)
        if server.hasPrefix("[") && server.hasSuffix("]") && server.count >= 2 {
            server = String(server.dropFirst().dropLast())
        }
        var parts: [String] = []
        switch node.settings {
        case .shadowsocks(let ss):
            let plugin = ProxyShareLink.normalizePlugin(ss.plugin)
            parts = [
                "ss", server.lowercased(), String(node.port),
                ss.method.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
                ss.password.trimmingCharacters(in: .whitespacesAndNewlines),
                plugin,
                plugin.isEmpty ? "" : (ss.pluginOpts ?? "").trimmingCharacters(in: .whitespacesAndNewlines),
            ]
        case .socks(let socks):
            parts = [
                "socks5", server.lowercased(), String(node.port),
                (socks.username ?? "").trimmingCharacters(in: .whitespacesAndNewlines),
                (socks.password ?? "").trimmingCharacters(in: .whitespacesAndNewlines),
            ]
        }
        return parts.joined(separator: "\u{0}")
    }

    /// Drops incoming nodes equal to a node already hosted on this device.
    public static func withoutHostedDuplicates(_ incoming: [ProxyNode], existing: [ProxyNode]) -> [ProxyNode] {
        var hostedKeys = Set<String>()
        for node in existing where node.isHosted {
            hostedKeys.insert(key(node))
        }
        if hostedKeys.isEmpty { return incoming }
        return incoming.filter { !hostedKeys.contains(key($0)) }
    }
}
