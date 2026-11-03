import Foundation

// Native outbound lease request/validation, runtime config and planning.
// Ports of Android `PanelRepository.resolveNativeOutboundLeases`, `UltraForwardRunner.buildPlan`
// (nativeSocks5 = true), `UltraLeaseClient.sendNative` framing and the checks of
// `TunnelOrchestrator.startNativeForForward`.

extension UltraLeaseRequest {
    /// Native lease request exactly as Android builds it: the target digest is the carrier's scope
    /// digest (v2 when unrestricted), transport is always tcp+udp.
    public static func native(
        policy: UltraPolicy,
        carrier: NativeOutboundCarrier,
        routeScope: NativeRouteScope,
        routePolicy: NativeRoutePolicy,
        deviceId: String,
        maxCandidates: Int = NativeOutboundConstants.maxLeaseCandidates,
        nonceHex: String = UltraLeaseRequest.randomNonceHex()
    ) throws -> UltraLeaseRequest {
        if maxCandidates < 1 || maxCandidates > NativeOutboundConstants.maxLeaseCandidates {
            throw UltraLeaseError("native_candidate_limit_invalid")
        }
        return UltraLeaseRequest(deviceId: deviceId, forwards: [
            UltraLeaseForwardRequest(
                forwardId: policy.forwardId,
                sessionTicket: policy.sessionTicket,
                policyGeneration: policy.policyGeneration,
                nonce: nonceHex,
                targetDigest: NativeOutboundLease.expectedScopeDigest(carrier: carrier, routeScope: routeScope, routePolicy: routePolicy),
                transport: NativeOutboundConstants.transport,
                purpose: NativeOutboundConstants.purpose,
                routeScope: routeScope.rawValue,
                routePolicy: routePolicy.rawValue,
                selectionMode: maxCandidates > 1 ? "auto" : "single",
                maxCandidates: maxCandidates
            ),
        ])
    }
}

extension UltraLeaseResponse {
    /// Validates and opens every native lease (all-or-nothing, wiped on failure).
    public func validatedNativeLeases(
        policy: UltraPolicy,
        carrier: NativeOutboundCarrier,
        routeScope: NativeRouteScope,
        routePolicy: NativeRoutePolicy,
        maxCandidates: Int,
        sealer: UltraLeaseSealer,
        now: Date = Date()
    ) throws -> [NativeOutboundLease] {
        if !ok {
            throw UltraLeaseError(error ?? "native_lease_invalid")
        }
        if leases.isEmpty || leases.count > maxCandidates {
            throw UltraLeaseError("native_lease_invalid")
        }
        var result: [NativeOutboundLease] = []
        do {
            for raw in leases {
                let opened = try sealer.openLease(raw)
                guard let lease = NativeOutboundLease.from(
                    opened,
                    policy: policy,
                    carrier: carrier,
                    routeScope: routeScope,
                    routePolicy: routePolicy,
                    now: now
                ) else {
                    throw UltraLeaseError("native_lease_invalid")
                }
                result.append(lease)
            }
        } catch {
            for index in result.indices { result[index].wipe() }
            throw error
        }
        return result
    }
}

extension PullConfigsResponse {
    /// The `native_outbound` entitlement of this pull (nil when absent or unusable).
    public var nativeOutbound: NativeOutboundAvailability? {
        return NativeOutboundAvailability.from(raw["native_outbound"])
    }
}

extension PanelClient {
    /// `POST api/client/ultra/lease` with `purpose = native_outbound`; returns validated leases.
    /// Throws `UltraLeaseError` with Android's codes (`native_outbound_not_allowed`,
    /// `native_route_scope_invalid`, ...) for local refusals and the panel's `error` otherwise.
    public func resolveNativeOutboundLeases(
        forward: ForwardContract,
        availability: NativeOutboundAvailability?,
        routeScope: NativeRouteScope,
        routePolicy: NativeRoutePolicy,
        maxCandidates: Int = NativeOutboundConstants.maxLeaseCandidates,
        sealer: UltraLeaseSealer = PlaintextLeaseOpener(),
        nonceHex: String = UltraLeaseRequest.randomNonceHex(),
        now: Date = Date()
    ) async throws -> [NativeOutboundLease] {
        if maxCandidates < 1 || maxCandidates > NativeOutboundConstants.maxLeaseCandidates {
            throw UltraLeaseError("native_candidate_limit_invalid")
        }
        guard let availability = availability, availability.isUsable,
              let carrier = availability.carrier(forwardId: forward.forwardId)
        else {
            throw UltraLeaseError("native_outbound_not_allowed")
        }
        if !nativeDirectionsMatch(forward.direction, carrier.direction) {
            throw UltraLeaseError("native_forward_direction_mismatch")
        }
        if !carrier.routeScopes.contains(routeScope)
            || carrier.transport != NativeOutboundConstants.transport
            || availability.transport != NativeOutboundConstants.transport {
            throw UltraLeaseError("native_route_scope_invalid")
        }
        if routePolicy == .unrestricted && !carrier.unrestrictedAllowed {
            throw UltraLeaseError("native_route_policy_not_allowed")
        }
        guard let policy = UltraPolicy.from(forward, now: now) else {
            throw UltraLeaseError("native_policy_invalid")
        }
        if policy.transport != NativeOutboundConstants.transport {
            throw UltraLeaseError("native_transport_invalid")
        }
        let request = try UltraLeaseRequest.native(
            policy: policy,
            carrier: carrier,
            routeScope: routeScope,
            routePolicy: routePolicy,
            deviceId: credentials.deviceId,
            maxCandidates: maxCandidates,
            nonceHex: nonceHex
        )
        var headers: [String: String] = [
            UltraConstants.offlineValidityHeaderName: String(UltraConstants.offlineValiditySeconds),
        ]
        if let leaseKey = sealer.leaseKeyHeaderValue() {
            headers[UltraConstants.leaseKeyHeaderName] = leaseKey
        }
        let (data, response) = try await perform(path: PanelClient.ultraLeasePath, method: "POST", jsonBody: request.jsonObject(), extraHeaders: headers)
        guard let body = UltraLeaseResponse.parse(data) else {
            throw PanelError.http(status: response.statusCode, message: PanelErrorParser.messageFromHttp(status: response.statusCode, reason: nil, body: data))
        }
        if !body.ok {
            let message = body.error ?? PanelErrorParser.messageFromHttp(status: response.statusCode, reason: nil, body: data)
            throw UltraLeaseError(message)
        }
        return try body.validatedNativeLeases(
            policy: policy,
            carrier: carrier,
            routeScope: routeScope,
            routePolicy: routePolicy,
            maxCandidates: maxCandidates,
            sealer: sealer,
            now: now
        )
    }
}

extension UltraRuntimeConfigBuilder {
    /// Static runtime config for native SOCKS5 mode: the same keys Android writes with
    /// `nativeSocks5 = true` (no tcp_first_flight; 5 s probe window over the candidate batch).
    public static func buildNativePlan(
        forward: ForwardContract,
        policy: UltraPolicy,
        paths: UltraRuntimePaths,
        listenPort: Int,
        routePolicy: NativeRoutePolicy
    ) throws -> UltraRuntimePlan {
        if forward.forwardId != policy.forwardId {
            throw UltraLeaseError("ultra_policy_forward_mismatch")
        }
        if listenPort < 1 || listenPort > 65535 {
            throw UltraLeaseError("ultra_listen_port_invalid")
        }
        let config: [String: Any] = [
            "forward_id": forward.forwardId,
            "listen_host": "127.0.0.1",
            "listen_port": listenPort,
            "target": forward.target.trimmingCharacters(in: .whitespacesAndNewlines),
            "native_socks5": true,
            "native_route_policy": routePolicy.rawValue,
            "native_port_min": NativeOutboundConstants.restrictedPortMin,
            "native_filter_profile": routePolicy.filterProfile,
            "control_socket": paths.controlSocketPath,
            "policy_generation": policy.policyGeneration,
            "target_digest": policy.targetDigest,
            "transport": policy.transport,
            "max_tcp_connections": 512,
            "udp_queue_packets": 1024,
            "connect_timeout_seconds": 5,
            "location_cache_path": paths.locationCachePath,
            "location_lock_path": paths.locationLockPath,
            "status_path": paths.statusPath,
            "probe_timeout_ms": 800,
            "probe_target": "1.1.1.1:53",
            "auto_select": true,
            "probe_duration_seconds": 5,
            "probe_samples": 20,
            "probe_interval_ms": 250,
            "probe_workers": 4,
        ]
        let json = try CanonicalJSON.string(config)
        return UltraRuntimePlan(
            forwardId: forward.forwardId,
            localListenPort: listenPort,
            paths: paths,
            configJSON: json,
            config: config,
            invocation: RuntimeInvocation(role: .ultra, arguments: ["--config", paths.configPath])
        )
    }

    /// Length-prefixed control frame for one native lease or a candidate batch
    /// (Android `UltraLeaseClient.sendNative`). Nil when the batch is out of bounds or mixed.
    public static func nativeControlFrame(leases: [NativeOutboundLease], now: Date = Date()) throws -> Data? {
        guard let first = leases.first, leases.count <= NativeOutboundConstants.maxLeaseCandidates else { return nil }
        for lease in leases {
            if !lease.isUsable(now: now) || lease.forwardId != first.forwardId || lease.policyGeneration != first.policyGeneration {
                return nil
            }
        }
        let body: Data
        if leases.count == 1 {
            body = try CanonicalJSON.data(first.controlPayload())
        } else {
            body = try CanonicalJSON.data(["leases": leases.map { $0.controlPayload() }])
        }
        if body.isEmpty || body.count > 64 * 1024 { return nil }
        var frame = Data()
        let length = UInt32(body.count)
        frame.append(UInt8((length >> 24) & 0xff))
        frame.append(UInt8((length >> 16) & 0xff))
        frame.append(UInt8((length >> 8) & 0xff))
        frame.append(UInt8(length & 0xff))
        frame.append(body)
        return frame
    }
}

/// The user's effective native choice after reconciling stored preferences with the pull.
public struct NativeOutboundSelection: Equatable {
    public var forwardId: Int64
    public var routeScope: NativeRouteScope
    public var routePolicy: NativeRoutePolicy
    /// Only meaningful (and only ever true) for `.global`.
    public var bypassChina: Bool

    public init(forwardId: Int64, routeScope: NativeRouteScope, routePolicy: NativeRoutePolicy, bypassChina: Bool) {
        self.forwardId = forwardId
        self.routeScope = routeScope
        self.routePolicy = routePolicy
        self.bypassChina = bypassChina && routeScope == .global
    }
}

public struct NativeOutboundPlan {
    public let carrier: NativeOutboundCarrier
    public let policy: UltraPolicy
    public let selection: NativeOutboundSelection
    public let runtime: UltraRuntimePlan
    public let singbox: CompiledSingboxConfig
}

public enum NativeOutboundPlanner {
    /// Carriers whose forward this device can run (nil = no filter), in panel order.
    public static func eligibleCarriers(_ availability: NativeOutboundAvailability?, eligibleForwardIds: Set<Int64>?) -> [NativeOutboundCarrier] {
        guard let availability = availability, availability.isUsable else { return [] }
        return availability.forwards.filter { carrier in
            guard let eligible = eligibleForwardIds else { return true }
            return eligible.contains(carrier.forwardId)
        }
    }

    /// Android ConnectionsViewModel reconciliation: the preferred carrier; with no preference
    /// yet, the panel default, else the first. A preferred carrier that is no longer offered
    /// gives nil (Connect fails visibly) instead of silently exiting through another region.
    /// The preferred scope when the carrier allows it, else its default; unrestricted only when
    /// allowed; bypass-China only for global.
    public static func effectiveSelection(
        availability: NativeOutboundAvailability?,
        eligibleForwardIds: Set<Int64>?,
        preferredForwardId: Int64?,
        preferredScope: String?,
        preferredPolicy: String?,
        bypassChina: Bool
    ) -> NativeOutboundSelection? {
        let carriers = eligibleCarriers(availability, eligibleForwardIds: eligibleForwardIds)
        var chosen: NativeOutboundCarrier?
        if let preferred = preferredForwardId {
            chosen = carriers.first { $0.forwardId == preferred }
        } else {
            if let defaultId = availability?.defaultForwardId {
                chosen = carriers.first { $0.forwardId == defaultId }
            }
            if chosen == nil {
                chosen = carriers.first
            }
        }
        guard let carrier = chosen else { return nil }
        let scope = carrier.effectiveScope(preferred: preferredScope)
        return NativeOutboundSelection(
            forwardId: carrier.forwardId,
            routeScope: scope,
            routePolicy: carrier.effectivePolicy(preferred: preferredPolicy),
            bypassChina: bypassChina
        )
    }

    /// Validates the selection against the carrier and forward (Android `startNativeForForward`)
    /// and builds the runtime config plus the native sing-box config. No credentials involved:
    /// the tunnel requests the leases.
    public static func plan(
        availability: NativeOutboundAvailability,
        forward: ForwardContract,
        selection: NativeOutboundSelection,
        paths: UltraRuntimePaths,
        socksPort: Int,
        builder: SingboxConfigBuilder,
        routing: RoutingPolicySnapshot?,
        now: Date = Date()
    ) throws -> NativeOutboundPlan {
        guard availability.isUsable, let carrier = availability.carrier(forwardId: forward.forwardId) else {
            throw UltraLeaseError("native_outbound_carrier_unavailable")
        }
        if selection.forwardId != forward.forwardId {
            throw UltraLeaseError("native_forward_unavailable")
        }
        if !carrier.routeScopes.contains(selection.routeScope) {
            throw UltraLeaseError("native_route_scope_invalid")
        }
        if selection.routePolicy == .unrestricted && !carrier.unrestrictedAllowed {
            throw UltraLeaseError("native_route_policy_not_allowed")
        }
        if !nativeDirectionsMatch(forward.direction, carrier.direction) {
            throw UltraLeaseError("native_forward_direction_mismatch")
        }
        guard let policy = UltraPolicy.from(forward, now: now) else {
            throw UltraLeaseError("native_policy_unavailable")
        }
        if policy.transport != NativeOutboundConstants.transport {
            throw UltraLeaseError("native_transport_invalid")
        }
        if paths.controlSocketPath.utf8.count > UltraRuntimePaths.maxSocketPathBytes {
            throw UltraLeaseError("native_control_path_too_long")
        }
        let runtime = try buildRuntime(forward: forward, policy: policy, paths: paths, socksPort: socksPort, routePolicy: selection.routePolicy)
        let singbox = try builder.buildNative(
            NativeSingboxOptions(
                localProxyPort: socksPort,
                routeScope: selection.routeScope,
                routePolicy: selection.routePolicy,
                bypassChina: selection.bypassChina
            ),
            routing: routing
        )
        return NativeOutboundPlan(carrier: carrier, policy: policy, selection: selection, runtime: runtime, singbox: singbox)
    }

    static func buildRuntime(forward: ForwardContract, policy: UltraPolicy, paths: UltraRuntimePaths, socksPort: Int, routePolicy: NativeRoutePolicy) throws -> UltraRuntimePlan {
        return try UltraRuntimeConfigBuilder.buildNativePlan(
            forward: forward,
            policy: policy,
            paths: paths,
            listenPort: socksPort,
            routePolicy: routePolicy
        )
    }
}
