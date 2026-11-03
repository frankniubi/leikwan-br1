import Foundation

/// User-imported node types supported on iOS (Android also has vmess/vless/wireguard; dropped here).
public enum ProxyNodeType: String, Codable, Equatable {
    case shadowsocks = "ss"
    case socks5 = "socks5"
}

public struct ShadowsocksSettings: Codable, Equatable {
    public var method: String
    public var password: String
    public var plugin: String?
    public var pluginOpts: String?
    public var network: String?
    /// `uot` / `udp_over_tcp` query flag.
    public var udpOverTcp: Bool?
    /// `multiplex` query flag (Android `multiplex_enabled`).
    public var multiplexEnabled: Bool?
    /// Other query parameters, kept for round-tripping (not emitted to sing-box, like Android).
    public var extra: [String: String]

    public init(
        method: String,
        password: String,
        plugin: String? = nil,
        pluginOpts: String? = nil,
        network: String? = nil,
        udpOverTcp: Bool? = nil,
        multiplexEnabled: Bool? = nil,
        extra: [String: String] = [:]
    ) {
        self.method = method
        self.password = password
        self.plugin = plugin
        self.pluginOpts = pluginOpts
        self.network = network
        self.udpOverTcp = udpOverTcp
        self.multiplexEnabled = multiplexEnabled
        self.extra = extra
    }
}

public struct SocksSettings: Codable, Equatable {
    public var username: String?
    public var password: String?

    public init(username: String? = nil, password: String? = nil) {
        self.username = username
        self.password = password
    }
}

public enum ProxyNodeSettings: Codable, Equatable {
    case shadowsocks(ShadowsocksSettings)
    case socks(SocksSettings)
}

/// Port of `data/ProxyNode` restricted to shadowsocks + socks5.
public struct ProxyNode: Codable, Equatable, Identifiable {
    public var id: Int64
    public var name: String
    public var server: String
    public var port: Int
    public var settings: ProxyNodeSettings
    public var subscriptionId: Int64?
    /// When set (SERVICES mode), the node relays through this forward's runtime.
    public var forwardId: Int64?
    public var enabled: Bool
    /// Panel id when this node is a hosted ("cloud") node; nil = local-only. Optional, so node
    /// files written before cloud sync still decode (synthesized Codable uses decodeIfPresent).
    public var hostedId: Int64?

    public init(
        id: Int64 = 0,
        name: String,
        server: String,
        port: Int,
        settings: ProxyNodeSettings,
        subscriptionId: Int64? = nil,
        forwardId: Int64? = nil,
        enabled: Bool = true,
        hostedId: Int64? = nil
    ) {
        self.id = id
        self.name = name
        self.server = server
        self.port = port
        self.settings = settings
        self.subscriptionId = subscriptionId
        self.forwardId = forwardId
        self.enabled = enabled
        self.hostedId = hostedId
    }

    /// True when this node is the device copy of a panel-hosted node.
    public var isHosted: Bool { return (hostedId ?? 0) > 0 }

    public var type: ProxyNodeType {
        switch settings {
        case .shadowsocks: return .shadowsocks
        case .socks: return .socks5
        }
    }

    /// sing-box outbound tag (`proxy-<id>`), same as Android.
    public var outboundTag: String { return "proxy-\(id)" }
}
