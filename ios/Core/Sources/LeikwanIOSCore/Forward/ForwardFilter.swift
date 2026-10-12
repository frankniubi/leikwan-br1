import Foundation

/// The two runtime families the iOS client can run.
public enum IOSForwardKind: String, Equatable {
    /// `backend_mode=current_mode`, `client_mode=bond`, bond ingress `plain_tcp_mux`.
    case bondPlainTcpMux = "bond_plain_tcp_mux"
    /// `backend_mode=ultra`.
    case ultra = "ultra"
}

/// Why a pulled forward is hidden on iOS. `isUnsupported` separates "unsupported on iOS"
/// from forwards Android hides as well (helper-only / unparseable).
public enum IOSForwardFilterReason: Equatable {
    case routeRuleHelperOnly
    case unparseable
    case backendModeUnsupported(String)
    case clientModeMismatch(rawMode: String, clientMode: String)
    case currentModeSingle
    case unsupportedClientMode(String)
    /// Bond ingress is `wss_path_mux` (explicit, or empty which Android defaults to it). wstunnel is not on iOS.
    case bondWssPathMux(explicit: Bool)
    case bondIngressUnsupported(String)

    public var isUnsupportedOnIOS: Bool {
        switch self {
        case .routeRuleHelperOnly, .unparseable: return false
        default: return true
        }
    }

    /// Short machine-readable code for logs/UI mapping.
    public var code: String {
        switch self {
        case .routeRuleHelperOnly: return "route_rule_helper_only"
        case .unparseable: return "unparseable"
        case .backendModeUnsupported: return "backend_mode_unsupported"
        case .clientModeMismatch: return "client_mode_mismatch"
        case .currentModeSingle: return "current_mode_single_unsupported"
        case .unsupportedClientMode: return "client_mode_unsupported"
        case .bondWssPathMux: return "bond_wss_path_mux_unsupported"
        case .bondIngressUnsupported: return "bond_ingress_unsupported"
        }
    }

    public var message: String {
        switch self {
        case .routeRuleHelperOnly: return "Route-rule helper forward"
        case .unparseable: return "Forward could not be parsed"
        case .backendModeUnsupported(let mode):
            return "Backend mode '\(mode.isEmpty ? "(empty)" : mode)' is not supported on iOS"
        case .clientModeMismatch(let rawMode, let clientMode):
            return "Forward mode '\(rawMode)' does not match client mode '\(clientMode)'"
        case .currentModeSingle: return "Single mode is not supported on iOS"
        case .unsupportedClientMode(let mode):
            return "Client mode '\(mode.isEmpty ? "(empty)" : mode)' is not supported on iOS"
        case .bondWssPathMux: return "Bond over WSS (wstunnel) is not supported on iOS"
        case .bondIngressUnsupported(let mode): return "Bond ingress '\(mode)' is not supported on iOS"
        }
    }
}

public struct IOSSupportedForward {
    public let kind: IOSForwardKind
    /// The contract with `mode` set to the runtime mode (`.bond` / `.ultra`).
    public let contract: ForwardContract
    /// Hosts that must stay direct (endpoint hosts, cdn hosts, sni, bond quic server name).
    public let bypassHosts: [String]
    public let protectedConnectivity: ProtectedConnectivitySet

    public var forwardId: Int64 { return contract.forwardId }
    public var serviceName: String { return contract.serviceName }
    public var methodDisplay: String { return kind == .ultra ? "Ultra" : "Host" }

    /// A native outbound rule (Windows `IsNativeOutboundRule`): it only carries the native
    /// outbound relay, so it is never paired with a node or run as an ordinary binding.
    public var isNativeOutboundRule: Bool { return kind == .ultra && contract.nativeOutbound }
}

public struct IOSHiddenForward: Equatable {
    public let forwardId: Int64?
    public let serviceName: String
    public let reason: IOSForwardFilterReason

    public init(forwardId: Int64?, serviceName: String, reason: IOSForwardFilterReason) {
        self.forwardId = forwardId
        self.serviceName = serviceName
        self.reason = reason
    }
}

public struct IOSForwardCatalog {
    public let supported: [IOSSupportedForward]
    public let hidden: [IOSHiddenForward]
    public let selectedForwardId: Int64?
    /// The pull's `native_outbound` entitlement (nil when the plan has none or it is unusable).
    public var nativeOutbound: NativeOutboundAvailability? = nil

    /// Count for "N forwards hidden (unsupported on iOS)".
    public var unsupportedHiddenCount: Int {
        return hidden.filter { $0.reason.isUnsupportedOnIOS }.count
    }

    public func supportedForward(id: Int64) -> IOSSupportedForward? {
        return supported.first { $0.forwardId == id }
    }

    /// Forwards a node can be bound to: `supported` minus native outbound rules.
    public var bindableForwards: [IOSSupportedForward] {
        return supported.filter { !$0.isNativeOutboundRule }
    }

    public func bindableForward(id: Int64) -> IOSSupportedForward? {
        return bindableForwards.first { $0.forwardId == id }
    }
}

/// iOS replacement for `AndroidEffectiveForwardBuilder` + `ForwardFilter`.
public enum IOSForwardFilter {
    public static let plainTcpMux = "plain_tcp_mux"
    /// Android default when the bond ingress mode is empty (BondForwardRunner.kt).
    public static let defaultBondIngressMode = "wss_path_mux"

    /// Effective bond ingress mode exactly as Android's BondForwardRunner resolves it.
    public static func effectiveBondIngressMode(_ contract: ForwardContract) -> (mode: String, explicit: Bool) {
        let fromBonding = contract.bonding?.ingressMode?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let fromTop = contract.bondIngressMode.trimmingCharacters(in: .whitespacesAndNewlines)
        let explicitValue = !fromBonding.isEmpty ? fromBonding : fromTop
        if explicitValue.isEmpty { return (defaultBondIngressMode, false) }
        return (explicitValue.lowercased(), true)
    }

    public static func classify(_ contract: ForwardContract) -> Result<IOSSupportedForward, IOSForwardRejection> {
        if contract.routeRuleHelperOnly { return .failure(IOSForwardRejection(.routeRuleHelperOnly)) }
        let backend = contract.backendMode.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        switch backend {
        case "ultra":
            var runtime = contract
            runtime.mode = .ultra
            return .success(makeSupported(.ultra, runtime))
        case "current_mode":
            let clientMode = contract.clientMode.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            let rawMode = contract.rawMode.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if !rawMode.isEmpty && rawMode != clientMode {
                return .failure(IOSForwardRejection(.clientModeMismatch(rawMode: rawMode, clientMode: clientMode)))
            }
            switch clientMode {
            case "single":
                return .failure(IOSForwardRejection(.currentModeSingle))
            case "bond":
                let ingress = effectiveBondIngressMode(contract)
                if ingress.mode == plainTcpMux {
                    var runtime = contract
                    runtime.mode = .bond
                    return .success(makeSupported(.bondPlainTcpMux, runtime))
                }
                if ingress.mode == defaultBondIngressMode {
                    return .failure(IOSForwardRejection(.bondWssPathMux(explicit: ingress.explicit)))
                }
                return .failure(IOSForwardRejection(.bondIngressUnsupported(ingress.mode)))
            default:
                return .failure(IOSForwardRejection(.unsupportedClientMode(clientMode)))
            }
        default:
            return .failure(IOSForwardRejection(.backendModeUnsupported(backend)))
        }
    }

    public static func buildCatalog(contracts: [ForwardContract], selectedForwardId: Int64?) -> IOSForwardCatalog {
        var supported: [IOSSupportedForward] = []
        var hidden: [IOSHiddenForward] = []
        for contract in contracts {
            switch classify(contract) {
            case .success(let forward):
                supported.append(forward)
            case .failure(let rejection):
                hidden.append(IOSHiddenForward(forwardId: contract.forwardId, serviceName: contract.serviceName, reason: rejection.reason))
            }
        }
        var selected: Int64?
        if let desired = selectedForwardId, supported.contains(where: { $0.forwardId == desired }) {
            selected = desired
        }
        return IOSForwardCatalog(supported: supported, hidden: hidden, selectedForwardId: selected)
    }

    /// Parses the raw `forwards` array of `configs/pull` and filters it for iOS.
    public static func buildCatalog(rawForwards: [Any], selectedForwardId: Int64?) -> IOSForwardCatalog {
        var contracts: [ForwardContract] = []
        var unparseable: [IOSHiddenForward] = []
        for raw in rawForwards {
            if let contract = ForwardContractParser.parse(raw) {
                contracts.append(contract)
            } else {
                let obj = raw as? [String: Any]
                unparseable.append(IOSHiddenForward(
                    forwardId: nil,
                    serviceName: JSONLenient.stringOrEmpty(obj?["service_name"]),
                    reason: .unparseable
                ))
            }
        }
        let catalog = buildCatalog(contracts: contracts, selectedForwardId: selectedForwardId)
        return IOSForwardCatalog(supported: catalog.supported, hidden: catalog.hidden + unparseable, selectedForwardId: catalog.selectedForwardId)
    }

    /// Port of AndroidEffectiveForwardBuilder.bypassHosts.
    public static func bypassHosts(_ contract: ForwardContract) -> [String] {
        var hosts: [String] = []
        func add(_ value: String?) {
            guard let value = value, !value.trimmingCharacters(in: .whitespaces).isEmpty else { return }
            if !hosts.contains(value) { hosts.append(value) }
        }
        for endpoint in contract.endpoints {
            add(endpoint.host)
            add(endpoint.cdnHost)
        }
        add(contract.sni)
        if contract.mode == .bond {
            add(contract.bonding?.quicServerName)
        }
        return hosts
    }

    private static func makeSupported(_ kind: IOSForwardKind, _ runtime: ForwardContract) -> IOSSupportedForward {
        let hosts = bypassHosts(runtime)
        return IOSSupportedForward(
            kind: kind,
            contract: runtime,
            bypassHosts: hosts,
            protectedConnectivity: ProtectedConnectivitySet.fromEndpoints(hosts)
        )
    }
}

/// Error wrapper so `classify` can return a `Result`.
public struct IOSForwardRejection: Error, Equatable {
    public let reason: IOSForwardFilterReason
    public init(_ reason: IOSForwardFilterReason) { self.reason = reason }
}
