import Darwin
import Foundation
import LeikwanIOSCore

/// Persisted native outbound choices (Android Prefs `nativeOutbound*`). Raw strings are kept as
/// stored; `AppModel.nativeOutboundSelection` reconciles them with the current entitlement.
struct NativeOutboundPreferences: Codable, Equatable {
    /// The user switched native outbound on ("使用原生出口").
    var enabled: Bool = false
    /// Preferred carrier forward; nil = the panel default.
    var forwardID: Int64?
    /// "cn_only" / "intl_only" / "global".
    var routeScope: String = NativeRouteScope.global.rawValue
    /// "restricted" / "unrestricted".
    var routePolicy: String = NativeRoutePolicy.restricted.rawValue
    /// Bypass mainland China (global scope only).
    var bypassChina: Bool = false

    enum CodingKeys: String, CodingKey {
        case enabled
        case forwardID
        case routeScope
        case routePolicy
        case bypassChina
    }

    static let storeName = "native_outbound"

    static func load() -> NativeOutboundPreferences {
        return JSONStore.load(NativeOutboundPreferences.self, name: storeName) ?? NativeOutboundPreferences()
    }

    func save() {
        JSONStore.save(self, name: NativeOutboundPreferences.storeName)
    }
}

extension NativeOutboundPreferences {
    /// Tolerates missing keys (older or newer files). In an extension to keep the memberwise init.
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        enabled = try container.decodeIfPresent(Bool.self, forKey: .enabled) ?? false
        forwardID = try container.decodeIfPresent(Int64.self, forKey: .forwardID)
        routeScope = try container.decodeIfPresent(String.self, forKey: .routeScope) ?? NativeRouteScope.global.rawValue
        routePolicy = try container.decodeIfPresent(String.self, forKey: .routePolicy) ?? NativeRoutePolicy.restricted.rawValue
        bypassChina = try container.decodeIfPresent(Bool.self, forKey: .bypassChina) ?? false
    }
}

// MARK: - Native outbound surface for the UI

extension AppModel {
    /// The plan's native outbound entitlement from the last configs/pull; nil = not offered.
    var nativeOutbound: NativeOutboundAvailability? {
        return catalog?.nativeOutbound
    }

    /// Carriers the user can pick: native carriers whose forward this device can run (Ultra
    /// forwards in `catalog.supported`), in panel order.
    var nativeOutboundCarriers: [NativeOutboundCarrier] {
        return NativeOutboundPlanner.eligibleCarriers(nativeOutbound, eligibleForwardIds: nativeOutboundEligibleForwardIDs)
    }

    /// Show the native outbound card at all.
    var isNativeOutboundAvailable: Bool {
        return !nativeOutboundCarriers.isEmpty
    }

    /// The effective choice (carrier, scope, policy, bypass) after reconciling the stored
    /// preferences with the entitlement; nil when native outbound is not offered.
    var nativeOutboundSelection: NativeOutboundSelection? {
        let preferences = nativeOutboundPreferences
        return NativeOutboundPlanner.effectiveSelection(
            availability: nativeOutbound,
            eligibleForwardIds: nativeOutboundEligibleForwardIDs,
            preferredForwardId: preferences.forwardID,
            preferredScope: preferences.routeScope,
            preferredPolicy: preferences.routePolicy,
            bypassChina: preferences.bypassChina
        )
    }

    /// Connect will start native outbound (switched on and offered).
    var isNativeOutboundActive: Bool {
        return nativeOutboundPreferences.enabled && nativeOutboundSelection != nil
    }

    /// The carrier of `nativeOutboundSelection`.
    var selectedNativeOutboundCarrier: NativeOutboundCarrier? {
        guard let selection = nativeOutboundSelection else { return nil }
        return nativeOutbound?.carrier(forwardId: selection.forwardId)
    }

    /// Scopes the selected carrier allows (picker options), in panel order.
    var nativeOutboundRouteScopes: [NativeRouteScope] {
        return selectedNativeOutboundCarrier?.routeScopes ?? []
    }

    /// Whether the "无审全路由" (unrestricted) toggle may be offered for the selected carrier.
    var nativeOutboundUnrestrictedAllowed: Bool {
        return selectedNativeOutboundCarrier?.unrestrictedAllowed ?? false
    }

    /// The Ultra forward behind a carrier (for its service name).
    func nativeOutboundForward(for carrier: NativeOutboundCarrier) -> IOSSupportedForward? {
        return catalog?.supportedForward(id: carrier.forwardId)
    }

    /// Row title for a carrier: the forward's service name, else its direction, else "Native line".
    func nativeOutboundTitle(for carrier: NativeOutboundCarrier) -> String {
        let name = nativeOutboundForward(for: carrier)?.serviceName.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !name.isEmpty { return name }
        let direction = carrier.direction.trimmingCharacters(in: .whitespacesAndNewlines)
        return direction.isEmpty ? "Native line" : direction
    }

    /// Switches native outbound on or off. Switching on is ignored when it is not offered;
    /// switching off while a tunnel runs stops the tunnel (Android stops the VPN the same way).
    func setNativeOutboundEnabled(_ enabled: Bool) {
        if enabled {
            guard let selection = nativeOutboundSelection else { return }
            var preferences = nativeOutboundPreferences
            preferences.enabled = true
            preferences.forwardID = selection.forwardId
            preferences.routeScope = selection.routeScope.rawValue
            preferences.routePolicy = selection.routePolicy.rawValue
            preferences.bypassChina = selection.bypassChina
            nativeOutboundPreferences = preferences
            return
        }
        let wasEnabled = nativeOutboundPreferences.enabled
        nativeOutboundPreferences.enabled = false
        if wasEnabled && (tunnel.isConnected || tunnel.isBusy) {
            tunnel.disconnect()
        }
    }

    /// Picks the carrier forward; keeps the scope/policy when it allows them, else its defaults.
    func selectNativeOutboundForward(_ forwardID: Int64) {
        guard let carrier = nativeOutboundCarriers.first(where: { $0.forwardId == forwardID }) else { return }
        var preferences = nativeOutboundPreferences
        let scope = carrier.effectiveScope(preferred: preferences.routeScope)
        preferences.forwardID = forwardID
        preferences.routeScope = scope.rawValue
        preferences.routePolicy = carrier.effectivePolicy(preferred: preferences.routePolicy).rawValue
        preferences.bypassChina = preferences.bypassChina && scope == .global
        nativeOutboundPreferences = preferences
    }

    /// Sets the traffic scope; ignored when the selected carrier does not allow it. Leaving
    /// `.global` clears bypass-China.
    func setNativeOutboundRouteScope(_ scope: NativeRouteScope) {
        guard nativeOutboundRouteScopes.contains(scope) else { return }
        var preferences = nativeOutboundPreferences
        preferences.routeScope = scope.rawValue
        preferences.bypassChina = preferences.bypassChina && scope == .global
        nativeOutboundPreferences = preferences
    }

    /// Sets restricted / unrestricted; unrestricted is ignored unless the carrier allows it.
    func setNativeOutboundRoutePolicy(_ policy: NativeRoutePolicy) {
        if policy == .unrestricted && !nativeOutboundUnrestrictedAllowed { return }
        nativeOutboundPreferences.routePolicy = policy.rawValue
    }

    /// Bypass mainland China; only accepted while the effective scope is `.global`.
    func setNativeOutboundBypassChina(_ enabled: Bool) {
        guard nativeOutboundSelection?.routeScope == .global else { return }
        nativeOutboundPreferences.bypassChina = enabled
    }

    // MARK: Planning helpers

    var nativeOutboundEligibleForwardIDs: Set<Int64> {
        var ids = Set<Int64>()
        for forward in catalog?.supported ?? [] where forward.kind == .ultra {
            ids.insert(forward.forwardId)
        }
        return ids
    }

    /// Loopback port for the runtime's SOCKS5 facade. A running tunnel keeps the port it was
    /// planned with, so a re-plan (routing edit) does not restart the runtime; otherwise a free
    /// ephemeral port is picked (Android allocates one per start the same way).
    func nativeOutboundSocksPort(forwardID: Int64) throws -> Int {
        if tunnel.isConnected || tunnel.isBusy,
           NativeOutboundPortCache.forwardID == forwardID, let cached = NativeOutboundPortCache.port {
            return cached
        }
        guard let port = NativeOutboundPortCache.allocateLoopbackPort() else {
            throw AppError("Could not allocate a local port for native outbound")
        }
        NativeOutboundPortCache.forwardID = forwardID
        NativeOutboundPortCache.port = port
        return port
    }
}

/// Last native SOCKS5 port handed to the tunnel (main-actor use only).
enum NativeOutboundPortCache {
    static var forwardID: Int64?
    static var port: Int?

    /// Binds 127.0.0.1:0, reads the port the kernel chose and releases it.
    static func allocateLoopbackPort() -> Int? {
        let fd = Darwin.socket(AF_INET, SOCK_STREAM, 0)
        if fd < 0 { return nil }
        defer { _ = Darwin.close(fd) }
        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = 0
        address.sin_addr.s_addr = inet_addr("127.0.0.1")
        let length = socklen_t(MemoryLayout<sockaddr_in>.size)
        let bound = withUnsafePointer(to: &address) { pointer -> Int32 in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) { socketAddress in
                Darwin.bind(fd, socketAddress, length)
            }
        }
        if bound != 0 { return nil }
        var assigned = sockaddr_in()
        var assignedLength = socklen_t(MemoryLayout<sockaddr_in>.size)
        let named = withUnsafeMutablePointer(to: &assigned) { pointer -> Int32 in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) { socketAddress in
                Darwin.getsockname(fd, socketAddress, &assignedLength)
            }
        }
        if named != 0 { return nil }
        let port = Int(UInt16(bigEndian: assigned.sin_port))
        return port > 0 ? port : nil
    }
}
