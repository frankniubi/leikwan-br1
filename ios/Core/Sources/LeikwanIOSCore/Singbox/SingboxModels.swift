import Foundation

/// Port of `service/ProxyConnectionMode.kt`.
public enum ProxyConnectionMode: String, Codable, Equatable {
    /// User nodes are reached through the forward runtime on 127.0.0.1:<runtime listen port>.
    case services
    /// User nodes are dialled directly.
    case direct

    public static func fromStored(_ value: String?) -> ProxyConnectionMode {
        return ProxyConnectionMode(rawValue: value ?? "") ?? .services
    }
}

/// User-tunable sing-box settings (Android `SingboxSettings` + iOS TUN/log/cache knobs).
public struct SingboxSettings: Equatable {
    public var dnsServer: String
    public var dnsFallback: String
    public var dnsStrategy: String
    public var bypassChina: Bool
    /// One destination per line: IP / CIDR or exact domain, always routed direct.
    public var customBypassRules: String
    public var logLevel: String
    /// sing-box TUN stack: "mixed" (default), "system" or "gvisor".
    public var tunStack: String
    public var tunMTU: Int
    public var enableIPv6: Bool
    /// `experimental.cache_file.path`. nil omits cache_file.
    public var cacheFilePath: String?
    /// Directory holding the bundled `<tag>.srs` files (the app group copy the tunnel installs).
    /// Empty writes bare file names.
    public var ruleSetDirectory: String

    public init(
        dnsServer: String = "1.1.1.1",
        dnsFallback: String = "8.8.8.8",
        dnsStrategy: String = "prefer_ipv4",
        bypassChina: Bool = false,
        customBypassRules: String = "",
        logLevel: String = "info",
        tunStack: String = "mixed",
        tunMTU: Int = 9000,
        enableIPv6: Bool = false,
        cacheFilePath: String? = nil,
        ruleSetDirectory: String = ""
    ) {
        self.dnsServer = dnsServer
        self.dnsFallback = dnsFallback
        self.dnsStrategy = dnsStrategy
        self.bypassChina = bypassChina
        self.customBypassRules = customBypassRules
        self.logLevel = logLevel
        self.tunStack = tunStack
        self.tunMTU = tunMTU
        self.enableIPv6 = enableIPv6
        self.cacheFilePath = cacheFilePath
        self.ruleSetDirectory = ruleSetDirectory
    }
}

/// What sing-box needs to know about the running forward runtime (Android `RuntimeContext` subset).
public struct SingboxRuntimeContext: Equatable {
    public var forwardId: Int64
    /// The port user nodes point at in SERVICES mode (bond `--listen` / ultra `listen_port`).
    public var localListenPort: Int
    /// Hosts/IPs that must stay direct (runtime reachability bypass).
    public var bypassHosts: [String]
    public var protectedConnectivity: ProtectedConnectivitySet
    /// Optional per-node relay ports (Android `nodeLegs`); empty = every node uses `localListenPort`.
    /// Multi-binding: each bound node -> the loopback port of its own forward runtime.
    public var nodeListenPorts: [Int64: Int]
    /// Multi-binding: nodes bound without a forward; dialled at their own server:port even in
    /// SERVICES mode.
    public var directNodeIds: Set<Int64>

    public init(
        forwardId: Int64,
        localListenPort: Int,
        bypassHosts: [String] = [],
        protectedConnectivity: ProtectedConnectivitySet = .empty,
        nodeListenPorts: [Int64: Int] = [:],
        directNodeIds: Set<Int64> = []
    ) {
        self.forwardId = forwardId
        self.localListenPort = localListenPort
        self.bypassHosts = bypassHosts
        self.protectedConnectivity = protectedConnectivity
        self.nodeListenPorts = nodeListenPorts
        self.directNodeIds = directNodeIds
    }

    public static func bond(_ plan: BondRuntimePlan) -> SingboxRuntimeContext {
        return SingboxRuntimeContext(
            forwardId: plan.forwardId,
            localListenPort: plan.localListenPort,
            bypassHosts: plan.bypassHosts,
            protectedConnectivity: plan.protectedConnectivity
        )
    }

    /// Ultra: the protected set is the union of the leases' direct connectivity (endpoint IP + CIDRs).
    public static func ultra(_ plan: UltraRuntimePlan, leases: [UltraLease]) -> SingboxRuntimeContext {
        var combined = ProtectedConnectivitySet.empty
        for lease in leases {
            combined = combined.merge(lease.directConnectivity)
        }
        return SingboxRuntimeContext(
            forwardId: plan.forwardId,
            localListenPort: plan.localListenPort,
            bypassHosts: [],
            protectedConnectivity: combined
        )
    }

    /// Several runtimes behind one sing-box: `primary` supplies forwardId / localListenPort; the
    /// protected connectivity and bypass hosts of every runtime are merged (each runtime's
    /// endpoints must stay out of the tunnel).
    public static func merged(primary: SingboxRuntimeContext, others: [SingboxRuntimeContext]) -> SingboxRuntimeContext {
        var result = primary
        for other in others {
            result.protectedConnectivity = result.protectedConnectivity.merge(other.protectedConnectivity)
            for host in other.bypassHosts where !result.bypassHosts.contains(host) {
                result.bypassHosts.append(host)
            }
            for (node, port) in other.nodeListenPorts where result.nodeListenPorts[node] == nil {
                result.nodeListenPorts[node] = port
            }
            result.directNodeIds.formUnion(other.directNodeIds)
        }
        return result
    }

    /// Android `relayListenPortForNode`.
    public func relayListenPort(forNode nodeId: Int64) -> Int {
        return nodeListenPorts[nodeId] ?? localListenPort
    }

    /// Android `allProtectedConnectivity`.
    public var allProtectedConnectivity: ProtectedConnectivitySet {
        return protectedConnectivity.merge(ProtectedConnectivitySet.fromEndpoints(bypassHosts))
    }
}

public struct CompiledSingboxConfig: Equatable {
    public let configJSON: String
    public let finalProxyTag: String
    public let warnings: [String]
}

public struct SingboxConfigError: Error, Equatable, CustomStringConvertible {
    public let message: String
    public init(_ message: String) { self.message = message }
    public var description: String { return message }
}
