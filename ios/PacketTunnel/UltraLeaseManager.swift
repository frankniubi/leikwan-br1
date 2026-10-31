import Foundation
import LeikwanIOSCore
import Security

/// Keeps every Ultra runtime of the running plan supplied with leases, like Android's
/// TunnelOrchestrator (initial delivery + `refreshUltraIfDue`):
///
/// * the first lease uses the policy the app planned with while it is still valid, otherwise a
///   configs/pull first; every renewal pulls configs again for a fresh `ultra_access`;
/// * a healthy lease is re-checked every 30 s and renewed 30 s before the policy's
///   `renew_after`, or at once when the runtime's status file reports it lost its policy
///   (`awaiting` / `lapsed` written after our last delivery);
/// * failures retry with ControlPlaneBackoff (full jitter, 5 s doubling to 60 s), device-limit
///   refusals every 60 s; the runtime keeps serving its current lease meanwhile (offline survival);
/// * a terminal panel control stops the forward's loop (TunnelControlLoop tears the tunnel down);
/// * an essential forward (the default binding) left without a usable lease past the 180 s
///   floor AND `unusableGraceAttempts` failed attempts stops the tunnel, so iOS shows
///   Disconnected (and on-demand can start it again) instead of a Connected tunnel that
///   carries nothing. The attempt count keeps a burst of panel timeouts (heavy uplink on
///   cellular, a speed test) from tearing the tunnel down while it is otherwise healthy.
///
/// Leases are opened with the device lease key (`X-Leikwan-Lease-Key`) from this extension's
/// Keychain and delivered over the runtime's control socket; they are never persisted.
final class UltraLeaseManager {
    /// Stops the tunnel; the argument is the user-facing reason.
    typealias UnusableHandler = (_ message: String) -> Void

    static let unusableGraceSeconds: TimeInterval = 180
    /// The unusable tunnel is stopped only after both: the wall-clock floor above and this many
    /// failed attempts (each attempt is a full pull+deliver round trip). A transient burst of
    /// timeouts burns attempts slowly; a panel that truly refuses the device burns one per try.
    static let unusableGraceAttempts = 6
    /// Wall-clock floor: never stop before the old 180 s window has passed, whatever the
    /// attempt count says (an attempt can fail in well under a second).
    static let minimumUnusableSeconds: TimeInterval = 180

    private let client: PanelClient
    private let forwards: [UltraForwardLaunch]
    private let essentialForwardIDs: Set<Int64>
    private let onUnusable: UnusableHandler
    private let wakeup = LoopWakeup()
    private let deliveryQueue = DispatchQueue(label: "org.forfoxsakesantuary.foxbridgeinternal.tunnel.ultra-lease", attributes: .concurrent)
    private let lock = NSLock()
    private var tasks: [Task<Void, Never>] = []
    private var alerts: [Int64: String] = [:]
    /// Forward id → (lease hash → "ip:port") of the last delivered candidates.
    private var candidateEndpoints: [Int64: [String: String]] = [:]

    init(client: PanelClient, forwards: [UltraForwardLaunch], essentialForwardIDs: Set<Int64>, onUnusable: @escaping UnusableHandler) {
        self.client = client
        self.forwards = forwards
        self.essentialForwardIDs = essentialForwardIDs
        self.onUnusable = onUnusable
    }

    /// Problem the app should show (no usable lease for a forward), if any.
    var alert: String? {
        lock.lock()
        defer { lock.unlock() }
        guard let forwardID = alerts.keys.sorted().first, let message = alerts[forwardID] else { return nil }
        return L("Ultra forward %@: %@", String(describing: forwardID), String(describing: message))
    }

    func start() {
        lock.lock()
        defer { lock.unlock() }
        guard tasks.isEmpty else { return }
        for forward in forwards {
            let task = Task<Void, Never>.detached { [weak self] in
                guard let self = self else { return }
                await self.run(forward)
            }
            tasks.append(task)
        }
    }

    func stop() {
        lock.lock()
        let running = tasks
        tasks = []
        alerts = [:]
        lock.unlock()
        for task in running { task.cancel() }
    }

    /// Re-checks every forward now instead of at the end of its wait (device wake, network
    /// change): an overdue renewal or a failing one is retried at once.
    func nudge() {
        wakeup.fire()
    }

    /// Captures the final usage counters of these runtimes, then deletes their status files
    /// (Android `UltraUsageLedger.captureBeforeDelete`). Call while the runtimes are stopped:
    /// before starting them and after stopping them. Control sockets are removed by the runtime.
    static func settleRuntimeFiles(_ forwards: [UltraForwardLaunch], ledger: UltraUsageLedger?) {
        for forward in forwards {
            let statusURL = URL(fileURLWithPath: forward.statusPath)
            ledger?.captureFinal(statusFile: statusURL)
            try? FileManager.default.removeItem(at: statusURL)
        }
    }

    // MARK: Per-forward loop

    private struct Active {
        var policy: UltraPolicy
        var sentAt: Date
    }

    private func run(_ forward: UltraForwardLaunch) async {
        var backoff = ControlPlaneBackoff()
        var active: Active?
        var pullFirst = false
        var first = true
        var failedAttempts = 0
        let startedAt = Date()
        while !Task.isCancelled {
            if let current = active {
                // Healthy: wait for the next check, renew only when due or when the runtime lost it.
                // Never more often than one check interval per delivery (a panel whose renew
                // window is shorter than the margin must not cause a renewal loop).
                let interval = UltraLeaseSchedule.checkIntervalSeconds
                let sinceSent = Date().timeIntervalSince(current.sentAt)
                let status = UltraRuntimeStatus.read(path: forward.statusPath)
                let lost = sinceSent >= 5 && UltraLeaseSchedule.runtimeLostPolicy(status, leaseSentAt: current.sentAt)
                let scheduleDue = UltraLeaseSchedule.renewalDue(current.policy)
                if !lost && !(scheduleDue && sinceSent >= interval) {
                    let wait = scheduleDue
                        ? max(1, interval - sinceSent)
                        : UltraLeaseSchedule.nextCheckDelay(current.policy)
                    await pause(seconds: wait)
                    continue
                }
                if lost {
                    TunnelLog.shared.write("ultra forward \(forward.forwardID): runtime reports \(status?.policyState.rawValue ?? "?"), delivering a fresh lease")
                }
            }
            let delayMs: Int64
            do {
                let policy = try await resolvePolicy(forward, preferLaunch: first && !pullFirst)
                first = false
                let accepted = try await deliverLeases(forward, policy: policy)
                if Task.isCancelled { return }
                if !accepted { throw UltraLeaseError("ultra_lease_rejected_by_runtime") }
                active = Active(policy: policy, sentAt: Date())
                backoff.reset()
                pullFirst = false
                failedAttempts = 0
                setAlert(nil, for: forward.forwardID)
                let remaining = Int(policy.expiresAt.timeIntervalSinceNow)
                TunnelLog.shared.write("ultra forward \(forward.forwardID): lease delivered, policy expires in \(remaining)s")
                continue
            } catch is CancellationError {
                return
            } catch let error as PanelError {
                first = false
                pullFirst = true
                switch error {
                case let .terminalControl(code, _):
                    // TunnelControlLoop sees the same refusal and stops the tunnel.
                    TunnelLog.shared.write("ultra forward \(forward.forwardID): panel revoked the device (\(code))", level: 1)
                    setAlert(code, for: forward.forwardID)
                    return
                case .deviceLimitReached:
                    // The control loop owns the device-limit banner; retry at its steady cadence.
                    TunnelLog.shared.write("ultra forward \(forward.forwardID): lease refused, device limit reached", level: 2)
                    backoff.reset()
                    delayMs = 60_000
                default:
                    failedAttempts += 1
                    if stopIfUnusable(error.description, forward: forward, active: active, startedAt: startedAt, failedAttempts: failedAttempts) { return }
                    reportFailure(error.description, forward: forward, active: active)
                    delayMs = backoff.nextDelayMs()
                }
            } catch {
                first = false
                pullFirst = true
                let message = Self.describe(error)
                failedAttempts += 1
                if stopIfUnusable(message, forward: forward, active: active, startedAt: startedAt, failedAttempts: failedAttempts) { return }
                reportFailure(message, forward: forward, active: active)
                delayMs = backoff.nextDelayMs()
            }
            if Task.isCancelled { return }
            await pause(seconds: Double(max(delayMs, 1_000)) / 1_000)
        }
    }

    /// The policy to lease against. The plan-time `ultra_access` is used for the first lease
    /// while it is valid and not yet due for renewal; otherwise configs/pull supplies a fresh one.
    private func resolvePolicy(_ forward: UltraForwardLaunch, preferLaunch: Bool) async throws -> UltraPolicy {
        if preferLaunch, let planned = Self.plannedPolicy(forward), !UltraLeaseSchedule.renewalDue(planned) {
            return planned
        }
        let response = try await client.pullConfigs()
        let catalog = response.catalog(selectedForwardId: forward.forwardID)
        guard let supported = catalog.supportedForward(id: forward.forwardID),
              let policy = UltraPolicy.from(supported.contract)
        else {
            throw UltraLeaseError("ultra_policy_unavailable")
        }
        // Static bindings are baked into the runtime config; only the app can re-plan them.
        if policy.targetDigest != forward.targetDigest || policy.transport != forward.transport {
            throw UltraLeaseError("ultra_forward_changed_reconnect")
        }
        return policy
    }

    static func plannedPolicy(_ forward: UltraForwardLaunch) -> UltraPolicy? {
        guard let access = JSONLenient.parseObject(forward.ultraAccessJSON) else { return nil }
        let contract = ForwardContract(
            forwardId: forward.forwardID,
            target: forward.target,
            listenPort: forward.listenPort,
            mode: .ultra,
            backendMode: forward.backendMode,
            serviceStatus: forward.serviceStatus,
            ultraAccess: access
        )
        return UltraPolicy.from(contract)
    }

    private func deliverLeases(_ forward: UltraForwardLaunch, policy: UltraPolicy) async throws -> Bool {
        let opener = UltraLeaseKeychain.opener()
        let sealer: UltraLeaseSealer
        if let opener = opener {
            sealer = opener
        } else {
            // Like Android without a usable key: request without the header and let the panel
            // decide (`lease_sealing_required` once sealing is mandatory).
            sealer = PlaintextLeaseOpener()
        }
        let maxCandidates = min(max(forward.maxCandidates, 1), UltraConstants.maxLeaseCandidates)
        let leases = try await client.resolveUltraLeases(policy: policy, maxCandidates: maxCandidates, sealer: sealer)
        if Task.isCancelled {
            var discarded = leases
            for index in discarded.indices { discarded[index].wipe() }
            throw CancellationError()
        }
        recordEndpoints(leases, for: forward.forwardID)
        let socketPath = forward.controlSocketPath
        let slow = forward.autoSelect
        return await withCheckedContinuation { (continuation: CheckedContinuation<Bool, Never>) in
            self.deliveryQueue.async {
                let accepted = UltraControlDelivery.deliver(
                    socketPath: socketPath,
                    policy: policy,
                    leases: leases,
                    slowSelection: slow
                )
                continuation.resume(returning: accepted)
            }
        }
    }

    // MARK: Endpoints

    /// Remembers which relay endpoint each delivered candidate points at, keyed by lease hash,
    /// so the app can show the entry the runtime actually selected.
    private func recordEndpoints(_ leases: [UltraLease], for forwardID: Int64) {
        var map: [String: String] = [:]
        for lease in leases {
            let host = lease.endpointIp.contains(":") ? "[\(lease.endpointIp)]" : lease.endpointIp
            map[lease.leaseHash.lowercased()] = "\(host):\(lease.endpointPort)"
        }
        lock.lock()
        candidateEndpoints[forwardID] = map
        lock.unlock()
    }

    /// Forward id → "ip:port" of the relay entry in use: the candidate the runtime's status file
    /// reports as selected (UDP first, then TCP), or the only candidate when there was one.
    func activeEndpoints() -> [Int64: String] {
        lock.lock()
        let candidates = candidateEndpoints
        lock.unlock()
        var result: [Int64: String] = [:]
        for forward in forwards {
            guard let map = candidates[forward.forwardID], !map.isEmpty else { continue }
            if let hash = Self.selectedLeaseHash(statusPath: forward.statusPath), let endpoint = map[hash] {
                result[forward.forwardID] = endpoint
            } else if map.count == 1, let only = map.values.first {
                result[forward.forwardID] = only
            }
        }
        return result
    }

    private static func selectedLeaseHash(statusPath: String) -> String? {
        guard let data = try? Data(contentsOf: URL(fileURLWithPath: statusPath)),
              let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
        else {
            return nil
        }
        for key in ["active_udp", "udp_runtime_session", "latest_tcp"] {
            if let selection = object[key] as? [String: Any],
               let hash = selection["lease_hash"] as? String,
               !hash.isEmpty {
                return hash.lowercased()
            }
        }
        return nil
    }

    // MARK: Helpers

    /// Renewal failures while the current lease is still valid are only logged (the runtime
    /// keeps serving); with no valid lease the app shows the reason.
    private func reportFailure(_ message: String, forward: UltraForwardLaunch, active: Active?) {
        if let active = active, active.policy.expiresAt > Date() {
            let remaining = Int(active.policy.expiresAt.timeIntervalSinceNow)
            TunnelLog.shared.write("ultra forward \(forward.forwardID): renewal failed (\(message)); current policy expires in \(remaining)s", level: 2)
            return
        }
        TunnelLog.shared.write("ultra forward \(forward.forwardID): lease failed (\(message))", level: 2)
        setAlert(Self.userMessage(message), for: forward.forwardID)
    }

    /// True when the tunnel is being stopped: an essential forward has had no usable lease for
    /// `unusableGraceSeconds`. Device-limit refusals never get here (the device waits for a slot).
    ///
    /// The grace is measured in retry attempts, not wall clock: with the backoff capped at 60 s
    /// the old 180 s wall-clock window was only ~5 attempts, which a burst of panel timeouts
    /// (heavy uplink like a speed test on cellular) could burn through while the tunnel was
    /// otherwise fine. `unusableGraceAttempts` attempts span roughly 4-5 minutes and each one is
    /// a real round trip, so surviving them means the panel really is refusing this device.
    private func stopIfUnusable(_ message: String, forward: UltraForwardLaunch, active: Active?, startedAt: Date, failedAttempts: Int) -> Bool {
        guard essentialForwardIDs.contains(forward.forwardID) else { return false }
        let unusableSince = active?.policy.expiresAt ?? startedAt
        let unusableFor = Date().timeIntervalSince(unusableSince)
        guard unusableFor >= Self.minimumUnusableSeconds, failedAttempts >= Self.unusableGraceAttempts else {
            return false
        }
        TunnelLog.shared.write("ultra forward \(forward.forwardID): no usable lease for \(Int(unusableFor))s over \(failedAttempts) attempts (\(message)); stopping the tunnel", level: 1)
        let reason = Self.userMessage(message)
        setAlert(reason, for: forward.forwardID)
        onUnusable(L("Ultra forward %@: %@", String(describing: forward.forwardID), String(describing: reason)))
        return true
    }

    private func setAlert(_ message: String?, for forwardID: Int64) {
        lock.lock()
        alerts[forwardID] = message
        lock.unlock()
    }

    private func pause(seconds: Double) async {
        await wakeup.sleep(seconds: seconds)
    }

    static func describe(_ error: Error) -> String {
        if let error = error as? UltraLeaseError { return error.message }
        if let error = error as? PanelError { return error.description }
        if let error = error as? LocalizedError, let text = error.errorDescription { return text }
        return String(describing: error)
    }

    static func userMessage(_ code: String) -> String {
        switch code {
        case "ultra_forward_changed_reconnect":
            return L("The forward settings changed. Reconnect")
        case "ultra_policy_unavailable", "policy_unavailable":
            return L("Ultra authorization unavailable (service inactive or expired)")
        case "lease_sealing_required", "lease_key_invalid", "lease_unseal_failed", "lease_device_key_conflict":
            return L("Line key check failed (%@)", String(describing: code))
        case "ultra_lease_rejected_by_runtime":
            return L("The local Ultra runtime rejected the line")
        case "no_approved_candidate":
            return L("No line available yet; retrying automatically")
        default:
            return L("Could not get a line (%@); retrying automatically", String(describing: code))
        }
    }
}

/// The device lease key: a P-256 private scalar in this extension's Keychain (generic password,
/// after-first-unlock, this device only). Generated once; regenerated only when no key exists or
/// the stored one is unusable, never because the panel reported a key conflict.
enum UltraLeaseKeychain {
    private static let service = "org.forfoxsakesantuary.foxbridgeinternal.ultra-lease-key"
    private static let account = "lease-key-v1"
    private static let lock = NSLock()
    private static var cached: UltraSealedLeaseOpener?

    /// The opener, or nil when the Keychain cannot provide a key right now (e.g. before the
    /// first unlock); the lease is then requested without the header.
    static func opener() -> UltraSealedLeaseOpener? {
        lock.lock()
        defer { lock.unlock() }
        if let cached = cached { return cached }
        switch load() {
        case let .found(raw):
            if let opener = try? UltraSealedLeaseOpener(privateKeyRaw: raw), opener.selfTest() {
                cached = opener
                return opener
            }
            TunnelLog.shared.write("stored ultra lease key is unusable; replacing it", level: 2)
        case .missing:
            break
        case let .unavailable(status):
            TunnelLog.shared.write("ultra lease key unavailable (keychain status \(status))", level: 2)
            return nil
        }
        let raw = UltraSealedLeaseOpener.generatePrivateKeyRaw()
        guard store(raw), let opener = try? UltraSealedLeaseOpener(privateKeyRaw: raw), opener.selfTest() else {
            TunnelLog.shared.write("ultra lease key could not be stored", level: 2)
            return nil
        }
        cached = opener
        return opener
    }

    private enum LoadResult {
        case found(Data)
        case missing
        case unavailable(OSStatus)
    }

    private static func baseQuery() -> [String: Any] {
        return [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
    }

    private static func load() -> LoadResult {
        var query = baseQuery()
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        if status == errSecItemNotFound { return .missing }
        guard status == errSecSuccess else { return .unavailable(status) }
        guard let data = item as? Data else { return .missing }
        return .found(data)
    }

    private static func store(_ raw: Data) -> Bool {
        SecItemDelete(baseQuery() as CFDictionary)
        var attributes = baseQuery()
        attributes[kSecValueData as String] = raw
        attributes[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        return SecItemAdd(attributes as CFDictionary, nil) == errSecSuccess
    }
}
