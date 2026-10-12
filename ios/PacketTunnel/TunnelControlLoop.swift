import Foundation
import LeikwanIOSCore

/// Keeps the device online on the panel while the tunnel runs, like Android's
/// ClientControlLoop: heartbeat every 30 s when healthy, jittered backoff on
/// transient failures, 60 s retries while the device limit is reached, and a
/// hard stop when the panel kicks, bans or distrusts the device.
final class TunnelControlLoop {
    private let client: PanelClient
    private let onTerminal: (String) -> Void
    private var policy = ControlPlanePolicy()
    private var task: Task<Void, Never>?
    private let lock = NSLock()
    private var currentAlert: String?
    /// Ultra usage rides the heartbeat; unacknowledged rows stay pending and are resent.
    private let usageLedger: UltraUsageLedger?

    init(session: TunnelPanelSession, usageLedger: UltraUsageLedger? = nil, onTerminal: @escaping (String) -> Void) {
        self.usageLedger = usageLedger
        let credentials = PanelCredentials(
            clientId: session.clientID,
            agentToken: session.agentToken,
            installId: session.installID,
            deviceName: session.deviceName,
            appVersion: session.appVersion
        )
        client = PanelClient(endpoint: PanelEndpoint.fromStorage(session.endpoint), credentials: credentials)
        self.onTerminal = onTerminal
    }

    /// Problem the app should show (device limit, revocation), if any.
    var alert: String? {
        lock.lock()
        defer { lock.unlock() }
        return currentAlert
    }

    func start() {
        guard task == nil else { return }
        policy.start()
        task = Task.detached { [weak self] in
            await self?.run()
        }
    }

    func stop() {
        task?.cancel()
        task = nil
        policy.stop()
    }

    private func run() async {
        while !Task.isCancelled {
            let outcome: ControlPollOutcome
            do {
                let usage = usageLedger?.collect() ?? []
                let response = try await client.heartbeat(
                    HeartbeatRequest(osRelease: Self.osRelease, ultraUsage: usage.isEmpty ? nil : usage)
                )
                if !usage.isEmpty {
                    usageLedger?.acknowledge(usage, acknowledged: response.usageAcknowledged)
                }
                outcome = .success
            } catch {
                outcome = ControlPollOutcome.from(error: error)
                if case .transientFailure = outcome {
                    TunnelLog.shared.write("heartbeat failed: \(error)", level: 2)
                }
            }
            if Task.isCancelled { return }
            let decision = policy.record(outcome)
            if let change = decision.deviceLimitChange {
                switch change {
                case let .reached(limit, active):
                    let detail = limit.map { "\(active ?? $0)/\($0)" } ?? ""
                    setAlert("device_limit_reached \(detail)".trimmingCharacters(in: .whitespaces))
                case .cleared:
                    setAlert(nil)
                }
            }
            if let code = decision.terminalCode {
                setAlert(code)
                onTerminal(code)
                return
            }
            guard let delayMs = decision.delayMs else { return }
            try? await Task.sleep(nanoseconds: UInt64(max(delayMs, 1_000)) * 1_000_000)
        }
    }

    private func setAlert(_ value: String?) {
        lock.lock()
        currentAlert = value
        lock.unlock()
    }

    static var osRelease: String {
        let version = ProcessInfo.processInfo.operatingSystemVersion
        return "iOS \(version.majorVersion).\(version.minorVersion).\(version.patchVersion)"
    }
}
