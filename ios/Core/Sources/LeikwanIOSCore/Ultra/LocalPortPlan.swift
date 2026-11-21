import Foundation

/// Local ports are fixed-target forwards, never a native SOCKS facade or a TUN.
public struct LocalPortEgress: Equatable {
    public let interfaceName: String
    public let interfaceIndex: Int
    public let dnsServers: [String]

    public init(interfaceName: String, interfaceIndex: Int, dnsServers: [String]) throws {
        guard interfaceIndex > 0,
              !interfaceName.isEmpty,
              !interfaceName.hasPrefix("utun"), !interfaceName.hasPrefix("lo"),
              !dnsServers.isEmpty else { throw UltraLeaseError("physical_network_unavailable") }
        self.interfaceName = interfaceName
        self.interfaceIndex = interfaceIndex
        self.dnsServers = dnsServers
    }

    /// Re-apply on EVERY bootstrap restart; never rebuild an unscoped config.
    public func applying(to json: String) throws -> String {
        guard var config = JSONLenient.parseObject(json),
              config["native_socks5"] == nil,
              (config["listen_host"] as? String) == "127.0.0.1" else {
            throw UltraLeaseError("local_port_config_invalid")
        }
        config["egress_interface"] = interfaceName
        config["egress_interface_index"] = interfaceIndex
        config["direct_egress_required"] = true
        config["egress_dns_servers"] = dnsServers
        return try CanonicalJSON.string(config)
    }
}

/// Invalidates async starts/callbacks across stop/background; foreground does not resume them.
public struct LocalPortRunGate {
    public private(set) var isForeground = true
    public private(set) var generation = UUID()
    public init() {}
    public mutating func invalidate() { generation = UUID() }
    public mutating func setForeground(_ value: Bool) {
        isForeground = value
        if !value { invalidate() }
    }
    public func permits(_ epoch: UUID) -> Bool { isForeground && epoch == generation }
}

public enum LocalPortSupport {
    public static func unavailableReason(_ forward: IOSSupportedForward) -> String? {
        if forward.kind != .ultra { return "Local Port supports fixed-target Ultra forwards only; use VPN mode for Bond" }
        if forward.isNativeOutboundRule { return "Native outbound requires VPN mode; it is not a fixed-target port" }
        guard let policy = UltraPolicy.from(forward.contract) else { return "Forward authorization is unavailable; refresh the catalog" }
        if policy.transport == "udp" { return "UDP-only forwards are not available in this Local Port version; use VPN mode" }
        return nil
    }
}
