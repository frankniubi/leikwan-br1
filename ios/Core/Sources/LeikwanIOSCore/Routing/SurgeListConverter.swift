import Foundation

/// Converts a Surge / Clash rule list (e.g. blackmatrix7 `ChinaMax_All.list`, or a Clash
/// rule-provider `payload:` YAML) into a sing-box `source` rule-set: `{"version":2,"rules":[...]}`.
///
/// The whole list maps to ONE outcome chosen at import (a rule-set match has no per-rule
/// policy), so each line's policy column is only counted for the import summary. Plain
/// rules are merged into a few headless rules (domain family / ip_cidr / source / port,
/// which sing-box ORs across separate rules); AND/OR/NOT become logical rules. Types
/// sing-box cannot express here (PROCESS-NAME, URL-REGEX, IP-ASN, GEOIP, USER-AGENT,
/// SUB-RULE, nested RULE-SET …) are skipped and counted, never fatal.
///
/// Validation never resolves names. Same spec as Android `SurgeListConverter.kt`.
public enum SurgeListConverter {
    public static let maxBytes = 4 * 1024 * 1024
    public static let maxRules = 20_000

    public struct Conversion {
        /// Headless rules for the rule-set file.
        public let rules: [[String: Any]]
        /// Number of source entries that became part of `rules`.
        public let convertedCount: Int
        /// Skipped entries by rule type (or "INVALID" / "LIMIT").
        public let skipped: [String: Int]
        /// Policy column counts (DIRECT / REJECT / PROXY) of the converted entries.
        public let policies: [String: Int]
        /// FINAL / MATCH policy if the list had one.
        public let finalPolicy: String?
        /// First few problems, for display.
        public let samples: [String]

        public var isEmpty: Bool { rules.isEmpty }
        public var skippedCount: Int { skipped.values.reduce(0, +) }
    }

    public struct TooLargeError: Error, LocalizedError {
        public var errorDescription: String? { "规则列表超过 4 MB" }
    }

    static let unsupportedTypes: Set<String> = [
        "PROCESS-NAME", "PROCESS-PATH", "URL-REGEX", "USER-AGENT", "IP-ASN", "GEOIP", "GEOSITE",
        "SUB-RULE", "RULE-SET", "DOMAIN-SET", "SRC-PORT", "IN-PORT", "IN-TYPE", "IN-USER", "IN-NAME",
        "PROTOCOL", "NETWORK", "DSCP", "UID", "DEVICE-NAME", "SUBNET", "CELLULAR-RADIO", "HOSTNAME-TYPE",
        "SCRIPT", "DEST-PORT-RANGE",
    ]

    private static let maxSamples = 8

    public static func parse(_ text: String) throws -> Conversion {
        if text.utf16.count > maxBytes { throw TooLargeError() }

        var domain = OrderedSet(), domainSuffix = OrderedSet(), domainKeyword = OrderedSet(), domainRegex = OrderedSet()
        var ipCidr = OrderedSet(), sourceIpCidr = OrderedSet(), portRanges = OrderedSet()
        var ports: [Int] = []
        var portSeen = Set<Int>()
        var logical: [[String: Any]] = []
        var skipped: [String: Int] = [:]
        var policies: [String: Int] = [:]
        var samples: [String] = []
        var finalPolicy: String?
        var converted = 0

        func skip(_ type: String, _ lineNo: Int, _ line: String) {
            skipped[type, default: 0] += 1
            if samples.count < maxSamples { samples.append("第 \(lineNo) 行 (\(type))：\(line.prefix(80))") }
        }

        var body = text
        if body.hasPrefix("\u{FEFF}") { body.removeFirst() }
        let lines = body.split(omittingEmptySubsequences: false, whereSeparator: { $0 == "\n" || $0 == "\r\n" })
        for (index, rawLine) in lines.enumerated() {
            let lineNo = index + 1
            guard let line = cleanLine(String(rawLine)) else { continue }
            if converted >= maxRules {
                skip("LIMIT", lineNo, line)
                continue
            }
            let parts = splitTopLevel(line)
            let type = parts[0].trimmingCharacters(in: .whitespaces).uppercased()
            let value = parts.count > 1 ? parts[1].trimmingCharacters(in: .whitespaces) : ""
            let policy = parts.count > 2 ? parts[2] : nil

            if type == "FINAL" || type == "MATCH" {
                finalPolicy = value.isEmpty ? "DIRECT" : value
                continue
            }
            if type == "AND" || type == "OR" || type == "NOT" {
                if parts.count > 1, let rule = convertLogical(mode: type, operandsText: parts[1]) {
                    logical.append(rule)
                    converted += 1
                    countPolicy(&policies, policy)
                } else {
                    skip("LOGICAL-UNSUPPORTED", lineNo, line)
                }
                continue
            }
            if unsupportedTypes.contains(type) {
                skip(type, lineNo, line)
                continue
            }
            let ok: Bool
            switch type {
            case "DOMAIN": ok = normalizeDomain(value).map { domain.insert($0) } != nil
            case "DOMAIN-SUFFIX": ok = normalizeDomain(stripLeadingDot(value)).map { domainSuffix.insert($0) } != nil
            case "DOMAIN-KEYWORD": ok = normalizeKeyword(value).map { domainKeyword.insert($0) } != nil
            case "DOMAIN-REGEX": ok = normalizeRegex(value).map { domainRegex.insert($0) } != nil
            case "DOMAIN-WILDCARD": ok = wildcardToRegex(value).map { domainRegex.insert($0) } != nil
            case "IP-CIDR", "IP-CIDR6", "IP6-CIDR": ok = normalizeCidr(value).map { ipCidr.insert($0) } != nil
            case "SRC-IP", "SRC-IP-CIDR": ok = normalizeCidr(value).map { sourceIpCidr.insert($0) } != nil
            case "DST-PORT", "DEST-PORT":
                if let parsed = parsePort(value) {
                    switch parsed {
                    case .single(let port): if portSeen.insert(port).inserted { ports.append(port) }
                    case .range(let range): portRanges.insert(range)
                    }
                    ok = true
                } else {
                    ok = false
                }
            default:
                skip(type.isEmpty ? "INVALID" : type, lineNo, line)
                continue
            }
            if ok {
                converted += 1
                countPolicy(&policies, policy)
            } else {
                skip("INVALID", lineNo, line)
            }
        }

        var rules: [[String: Any]] = []
        if !domain.isEmpty || !domainSuffix.isEmpty || !domainKeyword.isEmpty || !domainRegex.isEmpty {
            // Domain fields of one headless rule are ORed together by sing-box.
            var rule: [String: Any] = [:]
            if !domain.isEmpty { rule["domain"] = domain.items }
            if !domainSuffix.isEmpty { rule["domain_suffix"] = domainSuffix.items }
            if !domainKeyword.isEmpty { rule["domain_keyword"] = domainKeyword.items }
            if !domainRegex.isEmpty { rule["domain_regex"] = domainRegex.items }
            rules.append(rule)
        }
        if !ipCidr.isEmpty { rules.append(["ip_cidr": ipCidr.items]) }
        if !sourceIpCidr.isEmpty { rules.append(["source_ip_cidr": sourceIpCidr.items]) }
        // port and port_range are different field groups (ANDed), so keep them apart.
        if !ports.isEmpty { rules.append(["port": ports]) }
        if !portRanges.isEmpty { rules.append(["port_range": portRanges.items]) }
        rules.append(contentsOf: logical)
        return Conversion(
            rules: rules,
            convertedCount: converted,
            skipped: skipped,
            policies: policies,
            finalPolicy: finalPolicy,
            samples: samples
        )
    }

    /// The rule-set file body sing-box loads with `type: local, format: source`.
    public static func json(_ conversion: Conversion) throws -> Data {
        try JSONSerialization.data(withJSONObject: ["version": 2, "rules": conversion.rules], options: [.sortedKeys])
    }

    // MARK: Line handling

    /// Strips comments, YAML list markers and quotes; nil for lines that carry no rule.
    public static func cleanLine(_ raw: String) -> String? {
        var line = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        if line.isEmpty || line.hasPrefix("#") || line.hasPrefix("//") || line.hasPrefix(";") { return nil }
        if line.hasPrefix("payload:") { return nil }
        if line.hasPrefix("- ") { line = String(line.dropFirst(2)).trimmingCharacters(in: .whitespaces) }
        // Trailing inline comment ("DOMAIN,a.com // note"), before unquoting a YAML entry.
        if let range = line.range(of: " //") { line = String(line[..<range.lowerBound]) }
        if let range = line.range(of: " #") { line = String(line[..<range.lowerBound]) }
        line = line.trimmingCharacters(in: .whitespaces)
        if line.count >= 2, let first = line.first, first == "'" || first == "\"", line.last == first {
            line = String(line.dropFirst().dropLast()).trimmingCharacters(in: .whitespaces)
        }
        return line.isEmpty ? nil : line
    }

    /// Splits on commas outside parentheses: `AND,((A,b),(C,d)),DIRECT` → [AND, ((A,b),(C,d)), DIRECT].
    static func splitTopLevel(_ line: String) -> [String] {
        var parts: [String] = []
        var depth = 0
        var current = ""
        for ch in line {
            switch ch {
            case "(": depth += 1; current.append(ch)
            case ")": depth = max(0, depth - 1); current.append(ch)
            case "," where depth == 0: parts.append(current); current = ""
            default: current.append(ch)
            }
        }
        parts.append(current)
        return parts
    }

    private static func countPolicy(_ policies: inout [String: Int], _ raw: String?) {
        let policy = (raw ?? "").trimmingCharacters(in: .whitespaces).uppercased()
        let bucket: String
        if policy == "DIRECT" {
            bucket = "DIRECT"
        } else if policy.hasPrefix("REJECT") {
            bucket = "REJECT"
        } else {
            bucket = "PROXY"
        }
        policies[bucket, default: 0] += 1
    }

    // MARK: Logical rules

    /// `((DOMAIN,a.com),(DST-PORT,443))` → sing-box logical rule. NOT takes exactly one
    /// operand and becomes that operand with `invert: true`. nil when any operand is a type
    /// sing-box cannot express (the whole rule is skipped, never partially applied).
    private static func convertLogical(mode: String, operandsText: String) -> [String: Any]? {
        let inner = unwrapParens(operandsText.trimmingCharacters(in: .whitespaces))
        let operands = splitTopLevel(inner).map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
        if operands.isEmpty { return nil }
        var converted: [[String: Any]] = []
        for operand in operands {
            guard let rule = convertOperand(unwrapParens(operand)) else { return nil }
            converted.append(rule)
        }
        if mode == "NOT" {
            guard converted.count == 1 else { return nil }
            var rule = converted[0]
            rule["invert"] = !((rule["invert"] as? Bool) ?? false)
            return rule
        }
        return ["type": "logical", "mode": mode.lowercased(), "rules": converted]
    }

    private static func convertOperand(_ text: String) -> [String: Any]? {
        let parts = splitTopLevel(text)
        let type = parts[0].trimmingCharacters(in: .whitespaces).uppercased()
        if type == "AND" || type == "OR" || type == "NOT" {
            return parts.count > 1 ? convertLogical(mode: type, operandsText: parts[1]) : nil
        }
        let value = parts.count > 1 ? parts[1].trimmingCharacters(in: .whitespaces) : ""
        func single(_ key: String, _ v: String?) -> [String: Any]? { v.map { [key: [$0]] } }
        switch type {
        case "DOMAIN": return single("domain", normalizeDomain(value))
        case "DOMAIN-SUFFIX": return single("domain_suffix", normalizeDomain(stripLeadingDot(value)))
        case "DOMAIN-KEYWORD": return single("domain_keyword", normalizeKeyword(value))
        case "DOMAIN-REGEX": return single("domain_regex", normalizeRegex(value))
        case "DOMAIN-WILDCARD": return single("domain_regex", wildcardToRegex(value))
        case "IP-CIDR", "IP-CIDR6", "IP6-CIDR": return single("ip_cidr", normalizeCidr(value))
        case "SRC-IP", "SRC-IP-CIDR": return single("source_ip_cidr", normalizeCidr(value))
        case "DST-PORT", "DEST-PORT":
            switch parsePort(value) {
            case .single(let port)?: return ["port": [port]]
            case .range(let range)?: return ["port_range": [range]]
            case nil: return nil
            }
        default: return nil
        }
    }

    private static func unwrapParens(_ text: String) -> String {
        if text.hasPrefix("("), text.hasSuffix(")"), text.count >= 2 {
            return String(text.dropFirst().dropLast())
        }
        return text
    }

    // MARK: Value validation

    private static func stripLeadingDot(_ value: String) -> String {
        value.hasPrefix(".") ? String(value.dropFirst()) : value
    }

    static func normalizeDomain(_ value: String) -> String? {
        var host = value.trimmingCharacters(in: .whitespaces).lowercased()
        while host.hasSuffix(".") { host.removeLast() }
        guard !host.isEmpty, host.count <= 253 else { return nil }
        for label in host.split(separator: ".", omittingEmptySubsequences: false) {
            guard !label.isEmpty, label.count <= 63 else { return nil }
            let chars = Array(label)
            guard chars.allSatisfy({ ($0.isASCII && ($0.isLetter || $0.isNumber)) || $0 == "-" || $0 == "_" }),
                  chars.first != "-", chars.last != "-"
            else { return nil }
        }
        return host
    }

    private static func normalizeKeyword(_ value: String) -> String? {
        let keyword = value.trimmingCharacters(in: .whitespaces).lowercased()
        guard !keyword.isEmpty, !keyword.contains(where: { $0.isWhitespace || $0 == "," }) else { return nil }
        return keyword
    }

    private static func normalizeRegex(_ value: String) -> String? {
        guard !value.isEmpty, (try? NSRegularExpression(pattern: value)) != nil else { return nil }
        return CustomRoutingRule.hasUnsupportedRE2Syntax(value) ? nil : value
    }

    /// Surge DOMAIN-WILDCARD: `*` = any run of characters, `?` = one character.
    private static func wildcardToRegex(_ value: String) -> String? {
        let pattern = value.trimmingCharacters(in: .whitespaces).lowercased()
        guard !pattern.isEmpty, !pattern.contains(where: { $0.isWhitespace || $0 == "," }) else { return nil }
        var regex = "^"
        for ch in pattern {
            switch ch {
            case "*": regex += ".*"
            case "?": regex += "."
            default:
                if (ch.isASCII && (ch.isLetter || ch.isNumber)) || ch == "-" || ch == "_" {
                    regex.append(ch)
                } else {
                    regex += "\\" + String(ch)
                }
            }
        }
        return regex + "$"
    }

    static func normalizeCidr(_ value: String) -> String? {
        let text = value.trimmingCharacters(in: .whitespaces)
        let pieces = text.split(separator: "/", maxSplits: 1, omittingEmptySubsequences: false).map(String.init)
        let address = pieces[0]
        let isV6 = address.contains(":")
        if isV6 {
            guard isIPv6Literal(address) else { return nil }
        } else {
            guard isIPv4Literal(address) else { return nil }
        }
        let max = isV6 ? 128 : 32
        let prefix: Int
        if pieces.count == 2 {
            guard let parsed = Int(pieces[1]), (0...max).contains(parsed) else { return nil }
            prefix = parsed
        } else {
            prefix = max
        }
        return "\(address.lowercased())/\(prefix)"
    }

    private static func isIPv4Literal(_ address: String) -> Bool {
        let octets = address.split(separator: ".", omittingEmptySubsequences: false)
        return octets.count == 4 && octets.allSatisfy { part in
            !part.isEmpty && part.count <= 3 && part.allSatisfy(\.isASCIIDigit) && (Int(part) ?? 256) <= 255
        }
    }

    private static func isIPv6Literal(_ address: String) -> Bool {
        guard !address.isEmpty, address.filter({ $0 == ":" }).count >= 2 else { return false }
        guard address.components(separatedBy: "::").count <= 2 else { return false }
        let groups = address.split(separator: ":", omittingEmptySubsequences: false)
        var hextets = 0
        for (i, group) in groups.enumerated() {
            if group.isEmpty { continue }
            if i == groups.count - 1, group.contains(".") {
                guard isIPv4Literal(String(group)) else { return false }
                hextets += 2
            } else if group.count <= 4, group.allSatisfy(\.isHexDigit) {
                hextets += 1
            } else {
                return false
            }
        }
        return address.contains("::") ? hextets < 8 : hextets == 8
    }

    private enum PortValue {
        case single(Int)
        case range(String)
    }

    /// `443`, `8000-9000` or `8000:9000` (Surge uses `-`, sing-box `:`).
    private static func parsePort(_ value: String) -> PortValue? {
        let text = value.trimmingCharacters(in: .whitespaces)
        if let port = Int(text) {
            return (1...65535).contains(port) ? .single(port) : nil
        }
        let bounds = text.split(omittingEmptySubsequences: false, whereSeparator: { $0 == "-" || $0 == ":" })
        guard bounds.count == 2,
              let start = Int(bounds[0].trimmingCharacters(in: .whitespaces)),
              let end = Int(bounds[1].trimmingCharacters(in: .whitespaces)),
              (1...65535).contains(start), (1...65535).contains(end), start < end
        else { return nil }
        return .range("\(start):\(end)")
    }

    /// Insertion-ordered, de-duplicated string list.
    private struct OrderedSet {
        private(set) var items: [String] = []
        private var seen = Set<String>()
        var isEmpty: Bool { items.isEmpty }
        @discardableResult
        mutating func insert(_ value: String) -> Bool {
            guard seen.insert(value).inserted else { return false }
            items.append(value)
            return true
        }
    }
}

private extension Character {
    var isASCIIDigit: Bool { isASCII && isNumber }
}
