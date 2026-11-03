import Foundation

/// Non-overridable network paths that must stay out of the tunnel (port of
/// `runtime/ProtectedConnectivitySet.kt`). Insertion order is preserved.
public struct ProtectedConnectivitySet: Equatable {
    public private(set) var hosts: [String]
    public private(set) var ipCidrs: [String]

    private init(hosts: [String], ipCidrs: [String]) {
        self.hosts = hosts
        self.ipCidrs = ipCidrs
    }

    public static let empty = ProtectedConnectivitySet(hosts: [], ipCidrs: [])

    public var isEmpty: Bool { return hosts.isEmpty && ipCidrs.isEmpty }

    public static func fromEndpoints<S: Sequence>(_ values: S) -> ProtectedConnectivitySet where S.Element == String {
        return of(hosts: Array(values), cidrs: [])
    }

    public static func of(hosts: [String] = [], cidrs: [String] = []) -> ProtectedConnectivitySet {
        var normalizedHosts: [String] = []
        var normalizedCidrs: [String] = []
        for value in hosts {
            guard let endpoint = normalizeEndpoint(value) else { continue }
            if endpoint.isCidr {
                if !normalizedCidrs.contains(endpoint.value) { normalizedCidrs.append(endpoint.value) }
            } else {
                if !normalizedHosts.contains(endpoint.value) { normalizedHosts.append(endpoint.value) }
            }
        }
        for value in cidrs {
            if let cidr = normalizeCidr(value), !normalizedCidrs.contains(cidr) {
                normalizedCidrs.append(cidr)
            }
        }
        return ProtectedConnectivitySet(hosts: normalizedHosts, ipCidrs: normalizedCidrs)
    }

    public func merge(_ other: ProtectedConnectivitySet) -> ProtectedConnectivitySet {
        return ProtectedConnectivitySet.of(hosts: hosts + other.hosts, cidrs: ipCidrs + other.ipCidrs)
    }

    /// Lowercase hostname matcher shared with the sing-box builder.
    public static func isHostname(_ value: String) -> Bool {
        return value.range(of: "^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$", options: .regularExpression) != nil
    }

    static func normalizeEndpoint(_ value: String) -> (value: String, isCidr: Bool)? {
        var trimmed = value.trimmingCharacters(in: .whitespacesAndNewlines)
        if trimmed.hasPrefix("[") && trimmed.hasSuffix("]") && trimmed.count >= 2 {
            trimmed = String(trimmed.dropFirst().dropLast())
        }
        if trimmed.isEmpty { return nil }
        if let cidr = normalizeCidr(trimmed) { return (cidr, true) }
        if let literal = normalizeIp(trimmed) {
            return (literal + (literal.contains(":") ? "/128" : "/32"), true)
        }
        var host = trimmed.lowercased()
        if host.hasSuffix(".") { host.removeLast() }
        return isHostname(host) ? (host, false) : nil
    }

    /// Narrow CIDRs only: IPv4 prefix 8...32, IPv6 prefix 32...128.
    public static func normalizeCidr(_ value: String) -> String? {
        let parts = value.trimmingCharacters(in: .whitespacesAndNewlines).split(separator: "/", maxSplits: 1, omittingEmptySubsequences: false)
        if parts.count != 2 { return nil }
        guard let literal = normalizeIp(parts[0].trimmingCharacters(in: .whitespaces)) else { return nil }
        guard let prefix = Int(parts[1].trimmingCharacters(in: .whitespaces)) else { return nil }
        let isIPv6 = literal.contains(":")
        let maxPrefix = isIPv6 ? 128 : 32
        let minPrefix = isIPv6 ? 32 : 8
        if prefix < minPrefix || prefix > maxPrefix { return nil }
        return "\(literal)/\(prefix)"
    }

    static func normalizeIp(_ value: String) -> String? {
        if value.isEmpty || (!value.contains(".") && !value.contains(":")) { return nil }
        if value.range(of: "^[0-9a-fA-F:.]+$", options: .regularExpression) == nil { return nil }
        return IPLiteral.normalize(value)
    }
}
