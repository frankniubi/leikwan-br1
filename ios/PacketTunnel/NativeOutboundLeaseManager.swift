import Foundation
import LeikwanIOSCore

/// Keeps the native outbound runtime supplied with native SOCKS5 leases, like Android's
/// TunnelOrchestrator (`startNativeForForward` + `refreshNativeIfDue`):
///
/// * the first lease uses the plan-time `ultra_access` and carrier while the policy is valid and
///   not due for renewal, otherwise configs/pull first; renewals always pull again;
/// * up to 4 candidates are requested with `purpose = native_outbound` and the chosen scope and
///   policy, and delivered as one batch (the runtime probes them and keeps one path);
/// * renewal is due at min(shortest lease expiry - 90 s, policy `renew_after` - 30 s), checked
///   every 30 s, or at once when the runtime's status file says it lost its policy;
/// * a newer policy generation (or carrier direction) restarts the runtime with a rebuilt config
///   before the new batch is delivered (Android restarts the native runtime the same way);
/// * authorization failures (plan no longer allows native, scope/policy refused, forward inactive)
///   and a lease that expires before a renewal succeeds stop the tunnel (Android stops the VPN);
///   other failures retry with ControlPlaneBackoff while the current lease keeps serving.
///
/// Leases are opened with the device lease key and never persisted.
final class NativeOutboundLeaseManager {
    /// Restarts the native runtime with a new configuration JSON.
    typealias RuntimeRestarter = (_ configJSON: String) throws -> Void
    /// Stops the tunnel; the argument is the user-facing reason.
    typealias FatalHandler = (_ message: String) -> Void

    private let client: PanelClient
    private let launch: NativeOutboundLaunch
    private let restartRuntime: RuntimeRestarter
    private let onFatal: FatalHandler
    private let deliveryQueue = DispatchQueue(label: "com.leikwan.tunnel.native-lease")
    private let lock = NSLock()
    private var task: Task<Void, Never>?
    private var currentAlert: String?

    init(
        session: TunnelPanelSession,
        launch: NativeOutboundLaunch,
        restartRuntime: @escaping RuntimeRestarter,
        onFatal: @escaping FatalHandler
    ) {
        let credentials = PanelCredentials(
            clientId: session.clientID,
            agentToken: session.agentToken,
            installId: session.installID,
            deviceName: session.deviceName,
            appVersion: session.appVersion
        )
        client = PanelClient(endpoint: PanelEndpoint.fromStorage(session.endpoint), credentials: credentials)
        self.launch = launch
        self.restartRuntime = restartRuntime
        self.onFatal = onFatal
    }

    /// Problem the app should show (no usable native lease), if any.
    var alert: String? {
        lock.lock()
        defer { lock.unlock() }
        guard let message = currentAlert else { return nil }
        return "原生出口：\(message)"
    }

    func start() {
        lock.lock()
        defer { lock.unlock() }
        guard task == nil else { return }
        task = Task<Void, Never>.detached { [weak self] in
            guard let self = self else { return }
            await self.run()
        }
    }

    func stop() {
        lock.lock()
        let running = task
        task = nil
        currentAlert = nil
        lock.unlock()
        running?.cancel()
    }

    /// Captures the runtime's final usage counters, then deletes its status file. Call while the
    /// runtime is stopped (before starting it and after stopping it).
    static func settleRuntimeFiles(_ launch: NativeOutboundLaunch?, ledger: UltraUsageLedger?) {
        guard let launch = launch else { return }
        let statusURL = URL(fileURLWithPath: launch.statusPath)
        ledger?.captureFinal(statusFile: statusURL)
        try? FileManager.default.removeItem(at: statusURL)
    }

    // MARK: Loop

    private struct LeaseContext {
        let forward: ForwardContract
        let availability: NativeOutboundAvailability
        let carrier: NativeOutboundCarrier
        let policy: UltraPolicy
    }

    private struct Active {
        var policy: UltraPolicy
        /// Shortest expiry of the accepted batch.
        var leaseExpiresAt: Date
        var sentAt: Date
    }

    private func run() async {
        guard let scope = NativeRouteScope.normalize(launch.routeScope) else {
            fail("native_route_scope_invalid")
            return
        }
        let routePolicy = NativeRoutePolicy.normalize(launch.routePolicy)
        var backoff = ControlPlaneBackoff()
        var active: Active?
        var runtimeGeneration = launch.policyGeneration
        var runtimeDirection = launch.carrierDirection
        var preferPlan = true
        while !Task.isCancelled {
            if let current = active {
                let now = Date()
                let interval = UltraLeaseSchedule.checkIntervalSeconds
                let sinceSent = now.timeIntervalSince(current.sentAt)
                let status = UltraRuntimeStatus.read(path: launch.statusPath)
                let lost = sinceSent >= 5 && UltraLeaseSchedule.runtimeLostPolicy(status, leaseSentAt: current.sentAt)
                let renewAt = Self.renewalTime(current)
                let due = now >= renewAt
                // Never renew more often than one check interval per delivery.
                if !lost && !(due && sinceSent >= interval) {
                    let wait = due
                        ? max(1, interval - sinceSent)
                        : max(1, min(interval, renewAt.timeIntervalSince(now)))
                    await pause(seconds: wait)
                    continue
                }
                if lost {
                    TunnelLog.shared.write("native outbound: runtime reports \(status?.policyState.rawValue ?? "?"), delivering a fresh lease")
                }
            }
            let delayMs: Int64
            do {
                let context = try await resolveContext(preferPlan: preferPlan, scope: scope, routePolicy: routePolicy)
                preferPlan = false
                let maxCandidates = min(max(launch.maxCandidates, 1), NativeOutboundConstants.maxLeaseCandidates)
                let leases = try await client.resolveNativeOutboundLeases(
                    forward: context.forward,
                    availability: context.availability,
                    routeScope: scope,
                    routePolicy: routePolicy,
                    maxCandidates: maxCandidates,
                    sealer: Self.sealer()
                )
                if Task.isCancelled {
                    Self.wipe(leases)
                    return
                }
                let leaseExpiry = leases.map { $0.expiresAt }.min() ?? Date()
                if context.policy.policyGeneration != runtimeGeneration
                    || !nativeDirectionsMatch(context.carrier.direction, runtimeDirection) {
                    do {
                        try rotateRuntime(context, routePolicy: routePolicy)
                    } catch {
                        Self.wipe(leases)
                        throw error
                    }
                    TunnelLog.shared.write("native outbound: policy rotated, runtime restarted for generation \(context.policy.policyGeneration.prefix(12))")
                    runtimeGeneration = context.policy.policyGeneration
                    runtimeDirection = context.carrier.direction
                    active = nil
                }
                let accepted = await deliver(leases)
                if Task.isCancelled { return }
                if !accepted {
                    if let current = active, Date() >= current.leaseExpiresAt {
                        fail("native_lease_refresh_expired")
                        return
                    }
                    throw UltraLeaseError("native_lease_rejected_by_runtime")
                }
                if Date() >= leaseExpiry {
                    fail("native_lease_refresh_expired")
                    return
                }
                active = Active(policy: context.policy, leaseExpiresAt: leaseExpiry, sentAt: Date())
                backoff.reset()
                setAlert(nil)
                let remaining = Int(leaseExpiry.timeIntervalSinceNow)
                TunnelLog.shared.write("native outbound: \(leases.count) candidate(s) delivered (\(scope.rawValue), \(routePolicy.rawValue)), lease expires in \(remaining)s")
                continue
            } catch is CancellationError {
                return
            } catch let error as PanelError {
                preferPlan = false
                switch error {
                case let .terminalControl(code, _):
                    // TunnelControlLoop sees the same refusal and tears the tunnel down.
                    TunnelLog.shared.write("native outbound: panel revoked the device (\(code))", level: 1)
                    setAlert(code)
                    return
                case .deviceLimitReached:
                    TunnelLog.shared.write("native outbound: lease refused, device limit reached", level: 2)
                    backoff.reset()
                    delayMs = 60_000
                case let .http(_, message) where NativeOutboundErrors.isAuthorizationFailure(message):
                    fail(message)
                    return
                default:
                    if Self.expired(active) {
                        fail("native_lease_refresh_expired")
                        return
                    }
                    reportFailure(error.description, active: active)
                    delayMs = backoff.nextDelayMs()
                }
            } catch {
                preferPlan = false
                let code = Self.describe(error)
                if NativeOutboundErrors.isAuthorizationFailure(code) {
                    fail(code)
                    return
                }
                if Self.expired(active) {
                    fail("native_lease_refresh_expired")
                    return
                }
                reportFailure(code, active: active)
                delayMs = backoff.nextDelayMs()
            }
            if Task.isCancelled { return }
            await pause(seconds: Double(max(delayMs, 1_000)) / 1_000)
        }
    }

    /// The forward, carrier and policy to lease against: the plan's while its policy is valid and
    /// not yet due for renewal, otherwise a fresh configs/pull.
    private func resolveContext(preferPlan: Bool, scope: NativeRouteScope, routePolicy: NativeRoutePolicy) async throws -> LeaseContext {
        if preferPlan, let contract = launch.plannedContract, let carrier = launch.plannedCarrier,
           let policy = UltraPolicy.from(contract), !UltraLeaseSchedule.renewalDue(policy) {
            return LeaseContext(forward: contract, availability: .single(carrier), carrier: carrier, policy: policy)
        }
        let response = try await client.pullConfigs()
        guard let availability = response.nativeOutbound,
              let carrier = availability.carrier(forwardId: launch.forwardID)
        else {
            throw UltraLeaseError("native_outbound_not_allowed")
        }
        let catalog = response.catalog(selectedForwardId: launch.forwardID)
        guard let supported = catalog.supportedForward(id: launch.forwardID), supported.kind == .ultra else {
            throw UltraLeaseError("native_policy_unavailable")
        }
        if !carrier.routeScopes.contains(scope) {
            throw UltraLeaseError("native_route_scope_invalid")
        }
        if routePolicy == .unrestricted && !carrier.unrestrictedAllowed {
            throw UltraLeaseError("native_route_policy_not_allowed")
        }
        guard let policy = UltraPolicy.from(supported.contract) else {
            throw UltraLeaseError("native_policy_unavailable")
        }
        return LeaseContext(forward: supported.contract, availability: availability, carrier: carrier, policy: policy)
    }

    /// Rebuilds the runtime config for the new generation and restarts the runtime on the same
    /// SOCKS5 port (sing-box keeps pointing at it).
    private func rotateRuntime(_ context: LeaseContext, routePolicy: NativeRoutePolicy) throws {
        let directory = (launch.controlSocketPath as NSString).deletingLastPathComponent
        let paths = UltraRuntimePaths.compact(directory: directory, forwardId: launch.forwardID)
        if paths.controlSocketPath != launch.controlSocketPath {
            throw UltraLeaseError("native_forward_changed_reconnect")
        }
        let plan = try UltraRuntimeConfigBuilder.buildNativePlan(
            forward: context.forward,
            policy: context.policy,
            paths: paths,
            listenPort: launch.socksPort,
            routePolicy: routePolicy
        )
        try restartRuntime(plan.configJSON)
    }

    private func deliver(_ leases: [NativeOutboundLease]) async -> Bool {
        let socketPath = launch.controlSocketPath
        let queue = deliveryQueue
        return await withCheckedContinuation { (continuation: CheckedContinuation<Bool, Never>) in
            queue.async {
                let accepted = UltraControlDelivery.deliverNative(socketPath: socketPath, leases: leases)
                continuation.resume(returning: accepted)
            }
        }
    }

    // MARK: Helpers

    private static func renewalTime(_ active: Active) -> Date {
        let byExpiry = active.leaseExpiresAt.addingTimeInterval(-NativeOutboundConstants.refreshMarginSeconds)
        let byPolicy = active.policy.renewAfter.addingTimeInterval(-UltraLeaseSchedule.renewMarginSeconds)
        return min(byExpiry, byPolicy)
    }

    private static func expired(_ active: Active?) -> Bool {
        guard let active = active else { return false }
        return Date() >= active.leaseExpiresAt
    }

    private static func sealer() -> UltraLeaseSealer {
        if let opener = UltraLeaseKeychain.opener() {
            return opener
        }
        // Like Android without a usable key: let the panel decide (`lease_sealing_required`).
        return PlaintextLeaseOpener()
    }

    private static func wipe(_ leases: [NativeOutboundLease]) {
        var owned = leases
        for index in owned.indices { owned[index].wipe() }
    }

    /// Renewal failures while the current lease is valid are only logged (the runtime keeps
    /// serving); with no valid lease the app shows the reason.
    private func reportFailure(_ message: String, active: Active?) {
        if let active = active, active.leaseExpiresAt > Date() {
            let remaining = Int(active.leaseExpiresAt.timeIntervalSinceNow)
            TunnelLog.shared.write("native outbound: renewal failed (\(message)); current lease expires in \(remaining)s", level: 2)
            return
        }
        TunnelLog.shared.write("native outbound: lease failed (\(message))", level: 2)
        setAlert(NativeOutboundErrors.userMessage(message))
    }

    private func fail(_ code: String) {
        let message = NativeOutboundErrors.userMessage(code)
        TunnelLog.shared.write("native outbound stopped (\(code))", level: 1)
        setAlert(message)
        onFatal(message)
    }

    private func setAlert(_ message: String?) {
        lock.lock()
        currentAlert = message
        lock.unlock()
    }

    private func pause(seconds: Double) async {
        let nanoseconds = UInt64(max(seconds, 0) * 1_000_000_000)
        try? await Task.sleep(nanoseconds: nanoseconds)
    }

    static func describe(_ error: Error) -> String {
        if let error = error as? UltraLeaseError { return error.message }
        if let error = error as? PanelError { return error.description }
        if let error = error as? LocalizedError, let text = error.errorDescription { return text }
        return String(describing: error)
    }
}

extension NativeOutboundLaunch {
    /// The forward as planned, for validating the plan-time `ultra_access`.
    var plannedContract: ForwardContract? {
        guard let access = JSONLenient.parseObject(ultraAccessJSON) else { return nil }
        return ForwardContract(
            forwardId: forwardID,
            direction: direction,
            target: target,
            listenPort: forwardListenPort,
            mode: .ultra,
            backendMode: backendMode,
            serviceStatus: serviceStatus,
            ultraAccess: access
        )
    }

    /// The carrier entry as planned; nil when it no longer validates.
    var plannedCarrier: NativeOutboundCarrier? {
        guard let defaultScope = NativeRouteScope.normalize(carrierDefaultRouteScope) else { return nil }
        let carrier = NativeOutboundCarrier(
            forwardId: forwardID,
            direction: carrierDirection,
            scopeDigest: carrierScopeDigest,
            transport: carrierTransport,
            routeScopes: carrierRouteScopes.compactMap { NativeRouteScope.normalize($0) },
            defaultRouteScope: defaultScope,
            unrestrictedAllowed: carrierUnrestrictedAllowed
        )
        return carrier.isUsable ? carrier : nil
    }
}

extension UltraControlDelivery {
    /// Delivers one native lease or a candidate batch (Android `UltraLeaseClient.sendNative`).
    /// The native runtime probes before it answers (auto_select is always on), so every delivery
    /// uses the batch timeout. Wipes the leases (best effort) whether they are accepted or not.
    static func deliverNative(socketPath: String, leases: [NativeOutboundLease]) -> Bool {
        var owned = leases
        defer {
            for index in owned.indices { owned[index].wipe() }
        }
        guard !socketPath.isEmpty, let first = owned.first, owned.count <= NativeOutboundConstants.maxLeaseCandidates else { return false }
        // try? flattens the optional result (Swift 5): nil on throw or an out-of-bounds payload.
        guard let built = try? UltraRuntimeConfigBuilder.nativeControlFrame(leases: owned) else { return false }
        var frame = built
        defer { frame.resetBytes(in: 0..<frame.count) }
        return send(
            socketPath: socketPath,
            frame: frame,
            forwardId: first.forwardId,
            policyGeneration: first.policyGeneration,
            timeoutMs: batchTimeoutMs,
            attempts: batchAttempts
        )
    }
}
