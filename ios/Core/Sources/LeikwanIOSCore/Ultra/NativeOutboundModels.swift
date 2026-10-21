import Foundation

/// Native outbound ("原生出口"): the plan lets this client use an Ultra carrier forward as a
/// SOCKS5 exit for all of its traffic, with no user node. Port of Android
/// `runtime/NativeOutboundModels.kt`.
///
/// Flow: configs/pull carries a non-secret `native_outbound` entitlement
/// (`NativeOutboundAvailability`); the host runs the Ultra runtime with `native_socks5` on a
/// loopback port, asks `POST api/client/ultra/lease` for `purpose = native_outbound` candidates
/// (`NativeOutboundLease`, up to 4) and hands them to the runtime's control socket; sing-box
/// sends traffic to the runtime's SOCKS5 facade through the `proxy-native` outbound.
public enum NativeOutboundConstants {
    public static let leaseProtocol = "leikwan-ultra-native-socks5-v1"
    public static let purpose = "native_outbound"
    public static let socksProtocol = "socks5"
    public static let transport = "tcp+udp"
    public static let maxLeaseCandidates = 4
    /// Restricted leases only carry destination ports from here up (`native_port_min`).
    public static let restrictedPortMin = 1000
    /// Renew this long before the shortest lease expires (Android `NATIVE_REFRESH_MARGIN_SECONDS`).
    public static let refreshMarginSeconds: TimeInterval = 90
    /// Lease expiry may exceed the policy TTL ceiling by this much (historical skew allowance).
    public static let leaseExpirySkewSeconds: TimeInterval = 30
    /// sing-box outbound tag of the runtime's SOCKS5 facade.
    public static let outboundTag = "proxy-native"
}

/// Which destinations go through the native exit.
public enum NativeRouteScope: String, Codable, CaseIterable, Equatable {
    /// Only mainland-China destinations use the exit; everything else goes direct.
    case cnOnly = "cn_only"
    /// Only non-mainland destinations use the exit; mainland China goes direct.
    case intlOnly = "intl_only"
    /// Everything uses the exit (optionally bypassing mainland China).
    case global = "global"

    /// Android `NativeOutboundCapability.normalizeScope` (accepts the panel's legacy aliases).
    public static func normalize(_ value: String?) -> NativeRouteScope? {
        guard let value = value else { return nil }
        switch value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "cn_only", "mainland_only", "china_only": return .cnOnly
        case "intl_only", "international_only", "overseas_only": return .intlOnly
        case "global": return .global
        default: return nil
        }
    }

    /// UI label (Android `connections_native_scope_*`).
    public var displayName: String {
        switch self {
        case .cnOnly: return L("Mainland China only")
        case .intlOnly: return L("International only")
        case .global: return L("Global")
        }
    }

    /// cn_only / intl_only split traffic by China rule-sets and resolve before the SOCKS handoff.
    public var isSplit: Bool {
        return self != .global
    }
}

/// Restricted (default) relays only ports >= 1000 with the runtime's traffic filter on;
/// unrestricted (plan-gated, `unrestricted_allowed`) relays every port with the filter off.
public enum NativeRoutePolicy: String, Codable, CaseIterable, Equatable {
    case restricted
    case unrestricted

    /// Anything other than "unrestricted" (any case) is restricted.
    public static func normalize(_ value: String?) -> NativeRoutePolicy {
        let normalized = (value ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return normalized == "unrestricted" ? .unrestricted : .restricted
    }

    public static func isValidValue(_ value: String?) -> Bool {
        let normalized = (value ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return normalized == "restricted" || normalized == "unrestricted"
    }

    /// UI label (Android `connections_native_unrestricted` for the unrestricted toggle).
    public var displayName: String {
        switch self {
        case .restricted: return L("Standard routing")
        case .unrestricted: return L("Unrestricted routing")
        }
    }

    /// `native_filter_profile` written into the runtime config.
    public var filterProfile: String {
        return self == .unrestricted ? "off" : "opengfw_tcp"
    }
}

/// One plan-approved carrier forward (Android `NativeOutboundCarrier`).
public struct NativeOutboundCarrier: Equatable {
    public var forwardId: Int64
    /// Opaque panel label of the forward's direction ("HK -> CN"); part of the lease binding.
    public var direction: String
    public var scopeDigest: String
    public var transport: String
    public var routeScopes: [NativeRouteScope]
    public var defaultRouteScope: NativeRouteScope
    public var unrestrictedAllowed: Bool

    public init(
        forwardId: Int64,
        direction: String,
        scopeDigest: String,
        transport: String,
        routeScopes: [NativeRouteScope],
        defaultRouteScope: NativeRouteScope,
        unrestrictedAllowed: Bool = false
    ) {
        self.forwardId = forwardId
        self.direction = direction
        self.scopeDigest = scopeDigest
        self.transport = transport
        self.routeScopes = routeScopes
        self.defaultRouteScope = defaultRouteScope
        self.unrestrictedAllowed = unrestrictedAllowed
    }

    public var isUsable: Bool {
        return forwardId > 0
            && !direction.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            && Hashing.isLowerHex64(scopeDigest)
            && transport == NativeOutboundConstants.transport
            && !routeScopes.isEmpty
            && routeScopes.contains(defaultRouteScope)
    }

    /// The scope to use for a stored preference: the preference when this carrier allows it,
    /// otherwise the carrier default (Android ConnectionsViewModel).
    public func effectiveScope(preferred: String?) -> NativeRouteScope {
        if let wanted = NativeRouteScope.normalize(preferred), routeScopes.contains(wanted) {
            return wanted
        }
        return defaultRouteScope
    }

    /// Unrestricted only when asked for and allowed by this carrier.
    public func effectivePolicy(preferred: String?) -> NativeRoutePolicy {
        if NativeRoutePolicy.normalize(preferred) == .unrestricted && unrestrictedAllowed {
            return .unrestricted
        }
        return .restricted
    }

    /// Parses one `native_outbound.forwards[]` entry; nil when it has no id or no known scope.
    static func parse(_ raw: Any) -> NativeOutboundCarrier? {
        guard let item = raw as? [String: Any] else { return nil }
        guard let forwardId = JSONLenient.int64(item["forward_id"]), forwardId > 0 else { return nil }
        let scopes = NativeOutboundAvailability.scopes(item["route_scopes"])
        guard let firstScope = scopes.first else { return nil }
        // Android DTO default: a missing default_route_scope reads as "global".
        let rawDefault: String? = item["default_route_scope"] == nil ? "global" : JSONLenient.string(item["default_route_scope"])
        return NativeOutboundCarrier(
            forwardId: forwardId,
            direction: String(JSONLenient.stringOrEmpty(item["direction"]).prefix(191)),
            scopeDigest: JSONLenient.stringOrEmpty(item["scope_digest"]).lowercased(),
            transport: JSONLenient.stringOrEmpty(item["transport"]).lowercased(),
            routeScopes: scopes,
            defaultRouteScope: NativeRouteScope.normalize(rawDefault) ?? firstScope,
            unrestrictedAllowed: JSONLenient.bool(item["unrestricted_allowed"]) ?? false
        )
    }
}

/// The pull-scoped, non-secret `native_outbound` entitlement (Android `NativeOutboundCapability`).
/// Absent (nil) when the plan does not allow native exit or any part of it is unusable.
public struct NativeOutboundAvailability: Equatable {
    public var enabled: Bool
    public var socksProtocol: String
    public var leaseProtocol: String
    public var transport: String
    public var forwardIds: [Int64]
    public var defaultForwardId: Int64?
    public var routeScopes: [NativeRouteScope]
    public var defaultRouteScope: NativeRouteScope
    public var unrestrictedAllowed: Bool
    public var forwards: [NativeOutboundCarrier]

    public init(
        enabled: Bool,
        socksProtocol: String,
        leaseProtocol: String,
        transport: String,
        forwardIds: [Int64],
        defaultForwardId: Int64?,
        routeScopes: [NativeRouteScope],
        defaultRouteScope: NativeRouteScope,
        unrestrictedAllowed: Bool,
        forwards: [NativeOutboundCarrier]
    ) {
        self.enabled = enabled
        self.socksProtocol = socksProtocol
        self.leaseProtocol = leaseProtocol
        self.transport = transport
        self.forwardIds = forwardIds
        self.defaultForwardId = defaultForwardId
        self.routeScopes = routeScopes
        self.defaultRouteScope = defaultRouteScope
        self.unrestrictedAllowed = unrestrictedAllowed
        self.forwards = forwards
    }

    /// A one-carrier entitlement, used by the tunnel for the plan-time lease.
    public static func single(_ carrier: NativeOutboundCarrier) -> NativeOutboundAvailability {
        return NativeOutboundAvailability(
            enabled: true,
            socksProtocol: NativeOutboundConstants.socksProtocol,
            leaseProtocol: NativeOutboundConstants.leaseProtocol,
            transport: NativeOutboundConstants.transport,
            forwardIds: [carrier.forwardId],
            defaultForwardId: carrier.forwardId,
            routeScopes: carrier.routeScopes,
            defaultRouteScope: carrier.defaultRouteScope,
            unrestrictedAllowed: carrier.unrestrictedAllowed,
            forwards: [carrier]
        )
    }

    public func carrier(forwardId: Int64) -> NativeOutboundCarrier? {
        return forwards.first { $0.forwardId == forwardId }
    }

    public var isUsable: Bool {
        return enabled
            && socksProtocol.lowercased() == NativeOutboundConstants.socksProtocol
            && leaseProtocol == NativeOutboundConstants.leaseProtocol
            && transport == NativeOutboundConstants.transport
            && !forwards.isEmpty
            && forwards.allSatisfy { $0.isUsable }
    }

    /// Parses `configs/pull`'s `native_outbound`; nil when absent or not usable.
    public static func from(_ raw: Any?) -> NativeOutboundAvailability? {
        guard let obj = raw as? [String: Any] else { return nil }
        var forwardIds: [Int64] = []
        for item in JSONLenient.array(obj["forward_ids"]) {
            if let id = JSONLenient.int64(item), id > 0, !forwardIds.contains(id) {
                forwardIds.append(id)
            }
        }
        var defaultForwardId: Int64?
        if let id = JSONLenient.int64(obj["default_forward_id"]), id > 0 {
            defaultForwardId = id
        }
        let result = NativeOutboundAvailability(
            enabled: JSONLenient.bool(obj["enabled"]) ?? false,
            socksProtocol: JSONLenient.stringOrEmpty(obj["protocol"]),
            leaseProtocol: JSONLenient.stringOrEmpty(obj["lease_protocol"]),
            transport: JSONLenient.stringOrEmpty(obj["transport"]).lowercased(),
            forwardIds: forwardIds,
            defaultForwardId: defaultForwardId,
            routeScopes: scopes(obj["route_scopes"]),
            defaultRouteScope: NativeRouteScope.normalize(JSONLenient.string(obj["default_route_scope"])) ?? .global,
            unrestrictedAllowed: JSONLenient.bool(obj["unrestricted_allowed"]) ?? false,
            forwards: JSONLenient.array(obj["forwards"]).compactMap { NativeOutboundCarrier.parse($0) }
        )
        return result.isUsable ? result : nil
    }

    static func scopes(_ raw: Any?) -> [NativeRouteScope] {
        var result: [NativeRouteScope] = []
        for item in JSONLenient.array(raw) {
            if let scope = NativeRouteScope.normalize(JSONLenient.string(item)), !result.contains(scope) {
                result.append(scope)
            }
        }
        return result
    }
}

/// The direction label is part of the forward binding; only presentation differences
/// (case, repeated whitespace) are normalized and an empty direction never matches.
public func nativeDirectionsMatch(_ forwardDirection: String, _ carrierDirection: String) -> Bool {
    func key(_ value: String) -> String {
        let parts = value.lowercased().split(whereSeparator: { $0 == " " || $0 == "\t" || $0 == "\n" || $0 == "\r" })
        return parts.joined(separator: " ")
    }
    let forwardKey = key(forwardDirection)
    return !forwardKey.isEmpty && forwardKey == key(carrierDirection)
}

/// One-shot native SOCKS5 credential lease (Android `NativeOutboundLease`). Never persist it;
/// call `wipe()` once it has been delivered.
public struct NativeOutboundLease {
    public let forwardId: Int64
    public let leaseId: String
    public let policyGeneration: String
    public let expiresAt: Date
    public let transport: String
    public let direction: String
    public let routeScope: NativeRouteScope
    public let routePolicy: NativeRoutePolicy
    public let scopeDigest: String
    public let endpointIp: String
    public let endpointPort: Int
    public var user: [UInt8]
    public var password: [UInt8]
    public let rateLimitMbps: Int64
    public let quotaRemainingBytes: Int64
    public let selectionRef: String
    public let selectionTier: String

    public mutating func wipe() {
        for index in user.indices { user[index] = 0 }
        for index in password.indices { password[index] = 0 }
    }

    public func isUsable(now: Date = Date()) -> Bool {
        if forwardId <= 0 { return false }
        if leaseId.trimmingCharacters(in: .whitespaces).isEmpty { return false }
        if !Hashing.isLowerHex64(policyGeneration) { return false }
        if !(expiresAt > now) { return false }
        if expiresAt > now.addingTimeInterval(UltraConstants.maxTTLSeconds + NativeOutboundConstants.leaseExpirySkewSeconds) { return false }
        if transport != NativeOutboundConstants.transport { return false }
        if !Hashing.isLowerHex64(scopeDigest) { return false }
        if direction.trimmingCharacters(in: .whitespaces).isEmpty { return false }
        if IPLiteral.normalize(endpointIp) == nil { return false }
        if endpointPort < 1 || endpointPort > 65535 { return false }
        if user.isEmpty || user.count > 255 { return false }
        if password.isEmpty || password.count > 255 { return false }
        if rateLimitMbps < 0 || rateLimitMbps > UltraConstants.maxRateMbps { return false }
        if quotaRemainingBytes < 1 || quotaRemainingBytes > UltraConstants.maxQuotaBytes { return false }
        if !UltraLease.isSelectionRef(selectionRef) { return false }
        if !UltraConstants.allowedSelectionTiers.contains(selectionTier) { return false }
        return true
    }

    /// Control-socket payload, same keys as Android `toControlPayload`. Contains secrets.
    public func controlPayload() -> [String: Any] {
        return [
            "protocol": NativeOutboundConstants.leaseProtocol,
            "purpose": NativeOutboundConstants.purpose,
            "forward_id": forwardId,
            "lease_id": leaseId,
            "policy_generation": policyGeneration,
            "expires_at": ISOInstant.format(expiresAt),
            "transport": transport,
            "rate_limit_mbps": rateLimitMbps,
            "quota_remaining_bytes": quotaRemainingBytes,
            "target_digest": scopeDigest,
            "selection_ref": selectionRef,
            "selection_tier": selectionTier,
            "direction": direction,
            "route_scope": routeScope.rawValue,
            "route_policy": routePolicy.rawValue,
            "endpoint": ["ip": endpointIp, "port": endpointPort] as [String: Any],
            "credential": [
                "user": String(decoding: user, as: UTF8.self),
                "password": String(decoding: password, as: UTF8.self),
            ],
        ]
    }

    /// `sha256("native-outbound:v1\n<id>\n<direction>\n<transport>\n<scope>")` (restricted leases).
    public static func scopeDigest(forwardId: Int64, direction: String, transport: String, routeScope: String) -> String {
        let canonicalScope = NativeRouteScope.normalize(routeScope)?.rawValue
            ?? routeScope.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let canonical = [
            "native-outbound:v1",
            String(forwardId),
            direction.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
            transport.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
            canonicalScope,
        ].joined(separator: "\n")
        return Hashing.sha256Hex(canonical)
    }

    /// v2 digest, which also binds the route policy (unrestricted leases).
    public static func scopeDigestV2(
        forwardId: Int64,
        direction: String,
        transport: String,
        routeScope: String,
        routePolicy: NativeRoutePolicy
    ) -> String {
        let canonicalScope = NativeRouteScope.normalize(routeScope)?.rawValue
            ?? routeScope.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let canonical = [
            "native-outbound:v2",
            String(forwardId),
            direction.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
            transport.trimmingCharacters(in: .whitespacesAndNewlines).lowercased(),
            canonicalScope,
            routePolicy.rawValue,
        ].joined(separator: "\n")
        return Hashing.sha256Hex(canonical)
    }

    /// The digest the panel must return for this request (v2 for unrestricted, else v1).
    public static func expectedScopeDigest(carrier: NativeOutboundCarrier, routeScope: NativeRouteScope, routePolicy: NativeRoutePolicy) -> String {
        if routePolicy == .unrestricted {
            return scopeDigestV2(
                forwardId: carrier.forwardId,
                direction: carrier.direction,
                transport: carrier.transport,
                routeScope: routeScope.rawValue,
                routePolicy: routePolicy
            )
        }
        return scopeDigest(forwardId: carrier.forwardId, direction: carrier.direction, transport: carrier.transport, routeScope: routeScope.rawValue)
    }

    /// Validates an (already opened) native lease like Android `NativeOutboundLease.from`.
    public static func from(
        _ raw: [String: Any],
        policy: UltraPolicy,
        carrier: NativeOutboundCarrier,
        routeScope: NativeRouteScope,
        routePolicy: NativeRoutePolicy,
        now: Date = Date()
    ) -> NativeOutboundLease? {
        guard let proto = JSONLenient.requiredString(raw["protocol"]),
              let purpose = JSONLenient.requiredString(raw["purpose"]),
              let forwardId = JSONLenient.strictInt64(raw["forward_id"]),
              let generation = JSONLenient.requiredString(raw["policy_generation"]),
              let leaseId = JSONLenient.requiredString(raw["lease_id"]),
              let expiresRaw = JSONLenient.requiredString(raw["expires_at"]), let expiresAt = ISOInstant.parse(expiresRaw),
              let transportRaw = JSONLenient.requiredString(raw["transport"]),
              let direction = JSONLenient.requiredString(raw["direction"])
        else { return nil }
        if !carrier.routeScopes.contains(routeScope) { return nil }
        guard let returnedScope = NativeRouteScope.normalize(JSONLenient.requiredString(raw["route_scope"])),
              let digest = JSONLenient.requiredString(raw["scope_digest"])?.lowercased()
        else { return nil }
        let rawReturnedPolicy = JSONLenient.requiredString(raw["route_policy"])
        if rawReturnedPolicy != nil && !NativeRoutePolicy.isValidValue(rawReturnedPolicy) { return nil }
        let returnedPolicy = NativeRoutePolicy.normalize(rawReturnedPolicy)
        if routePolicy == .unrestricted && !carrier.unrestrictedAllowed { return nil }
        guard let endpoint = raw["endpoint"] as? [String: Any],
              let endpointIpRaw = JSONLenient.requiredString(endpoint["ip"]),
              let endpointIp = UltraLease.normalizedIpLiteral(endpointIpRaw),
              let endpointPort64 = JSONLenient.strictInt64(endpoint["port"]),
              let credential = raw["credential"] as? [String: Any],
              let userText = JSONLenient.requiredString(credential["user"]),
              let passwordText = JSONLenient.requiredString(credential["password"])
        else { return nil }
        let transport = transportRaw.lowercased()
        let rateLimit = JSONLenient.strictInt64(raw["rate_limit_mbps"]) ?? policy.rateLimitMbps
        let quota = JSONLenient.strictInt64(raw["quota_remaining_bytes"]) ?? policy.quotaRemainingBytes
        let selectionRef = JSONLenient.requiredString(raw["selection_ref"]) ?? derivedSelectionRef(leaseId: leaseId)
        let selectionTier = JSONLenient.requiredString(raw["selection_tier"])?.lowercased() ?? "unknown"
        let expected: String
        if routePolicy == .unrestricted {
            expected = scopeDigestV2(forwardId: forwardId, direction: carrier.direction, transport: transport, routeScope: routeScope.rawValue, routePolicy: routePolicy)
        } else {
            expected = scopeDigest(forwardId: forwardId, direction: carrier.direction, transport: transport, routeScope: routeScope.rawValue)
        }
        var lease = NativeOutboundLease(
            forwardId: forwardId,
            leaseId: leaseId,
            policyGeneration: generation,
            expiresAt: expiresAt,
            transport: transport,
            direction: direction,
            routeScope: returnedScope,
            routePolicy: returnedPolicy,
            scopeDigest: digest,
            endpointIp: endpointIp,
            endpointPort: Int(clamping: endpointPort64),
            user: Array(userText.utf8),
            password: Array(passwordText.utf8),
            rateLimitMbps: rateLimit,
            quotaRemainingBytes: quota,
            selectionRef: selectionRef,
            selectionTier: selectionTier
        )
        if !isValid(lease, raw: (proto, purpose, leaseId.count), policy: policy, carrier: carrier, routeScope: routeScope, routePolicy: routePolicy, expected: expected, now: now) {
            lease.wipe()
            return nil
        }
        return lease
    }

    private static func isValid(
        _ lease: NativeOutboundLease,
        raw: (proto: String, purpose: String, leaseIdLength: Int),
        policy: UltraPolicy,
        carrier: NativeOutboundCarrier,
        routeScope: NativeRouteScope,
        routePolicy: NativeRoutePolicy,
        expected: String,
        now: Date
    ) -> Bool {
        if raw.proto != NativeOutboundConstants.leaseProtocol { return false }
        if raw.purpose != NativeOutboundConstants.purpose { return false }
        if lease.forwardId != policy.forwardId { return false }
        if lease.policyGeneration != policy.policyGeneration { return false }
        if lease.direction != carrier.direction { return false }
        if lease.routeScope != routeScope { return false }
        if lease.routePolicy != routePolicy { return false }
        if lease.scopeDigest != expected { return false }
        if lease.rateLimitMbps < 0 || lease.rateLimitMbps > policy.rateLimitMbps { return false }
        if lease.quotaRemainingBytes < 1 || lease.quotaRemainingBytes > policy.quotaRemainingBytes { return false }
        if raw.leaseIdLength < 1 || raw.leaseIdLength > 128 { return false }
        if !(lease.expiresAt > now) { return false }
        if lease.expiresAt > policy.expiresAt.addingTimeInterval(1) { return false }
        return lease.isUsable(now: now)
    }

    static func derivedSelectionRef(leaseId: String) -> String {
        return "native-" + Hashing.sha256Hex("native-selection\n" + leaseId)
    }
}

/// Error codes after which Android stops native outbound instead of retrying
/// (`TunnelOrchestrator.isNativeAuthorizationFailure`), plus user-facing messages for the app.
public enum NativeOutboundErrors {
    public static let authorizationFailureCodes: Set<String> = [
        "native_outbound_not_allowed",
        "native_route_scope_invalid",
        "native_route_policy_invalid",
        "native_route_policy_not_allowed",
        "native_policy_invalid",
        "native_transport_invalid",
        "native_policy_unavailable",
        "forward_inactive",
        "native_forward_unavailable",
    ]

    /// True when `message` (an error code, optionally followed by ":detail") is an authorization failure.
    public static func isAuthorizationFailure(_ message: String) -> Bool {
        let code = message.trimmingCharacters(in: .whitespacesAndNewlines)
            .split(separator: ":", maxSplits: 1, omittingEmptySubsequences: false)
            .first.map(String.init) ?? ""
        return authorizationFailureCodes.contains(code)
    }

    public static func userMessage(_ code: String) -> String {
        switch code {
        case "native_outbound_not_allowed", "native_forward_unavailable", "native_outbound_carrier_unavailable":
            return L("Native outbound is not available for this plan or forward")
        case "native_route_scope_invalid":
            return L("The selected traffic scope is not available. Refresh and try again")
        case "native_route_policy_invalid", "native_route_policy_not_allowed":
            return L("This plan does not include unrestricted routing")
        case "native_policy_invalid", "native_policy_unavailable", "forward_inactive", "native_transport_invalid":
            return L("Native outbound authorization unavailable (service inactive or expired)")
        case "native_forward_direction_mismatch":
            return L("The native outbound line changed. Refresh and try again")
        case "native_lease_refresh_expired":
            return L("The native outbound line expired. Reconnect")
        case "native_lease_rejected_by_runtime":
            return L("The local runtime rejected the native outbound line")
        case "native_control_path_too_long":
            return L("Native outbound control socket path is too long")
        case "lease_sealing_required", "lease_key_invalid", "lease_unseal_failed", "lease_device_key_conflict":
            return L("Line key check failed (%@)", String(describing: code))
        case "no_approved_candidate":
            return L("No native line available yet; retrying automatically")
        default:
            return L("Could not get a native outbound line (%@); retrying automatically", String(describing: code))
        }
    }
}
