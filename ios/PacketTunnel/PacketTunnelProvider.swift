import Foundation
import LeikwanIOSCore
import Libbox
import NetworkExtension

/// The packet tunnel: starts the forward runtimes the app planned, then
/// sing-box (libbox) on top of them.
final class PacketTunnelProvider: NEPacketTunnelProvider {
    private var commandServer: LibboxCommandServer?
    private lazy var platform = TunnelPlatformInterface(self)
    private let runtimes = RuntimeHost()
    private let statusMonitor = StatusMonitor()
    private var launch: TunnelLaunchOptions?
    private var controlLoop: TunnelControlLoop?
    private var ultraLeases: UltraLeaseManager?
    private var nativeLeases: NativeOutboundLeaseManager?
    /// Non-default bindings whose runtimes failed to start (binding id -> error).
    private var failedBindings: [String: String] = [:]
    private let refreshLock = NSLock()
    private var lastPathRefresh = Date.distantPast
    /// Ultra usage counters -> heartbeat rows; shared by the control loop and the lease manager.
    private lazy var usageLedger = UltraUsageLedger(
        statusDirectory: AppGroup.runtimeDirectory,
        stateFile: AppGroup.sharedDirectory.appendingPathComponent("ultra-usage-state.json")
    )

    override func startTunnel(options: [String: NSObject]?) async throws {
        do {
            try await startTunnelRecordingFailure(options)
        } catch {
            TunnelLog.shared.write("tunnel start failed: \(error.localizedDescription)", level: 1)
            throw error
        }
    }

    private func startTunnelRecordingFailure(_ options: [String: NSObject]?) async throws {
        let launch = try resolveLaunch(options)
        self.launch = launch

        let setup = LibboxSetupOptions()
        setup.basePath = AppGroup.sharedDirectory.path
        setup.workingPath = AppGroup.workingDirectory.path
        setup.tempPath = AppGroup.tempDirectory.path
        setup.logMaxLines = 3000
        var setupError: NSError?
        LibboxSetup(setup, &setupError)
        if let setupError {
            throw RuntimeHostError("setup libbox: \(setupError.localizedDescription)")
        }
        var stderrError: NSError?
        LibboxRedirectStderr(AppGroup.tempDirectory.appendingPathComponent("stderr.log").path, &stderrError)
        // The extension has a ~50 MB budget; let libbox enforce it.
        LibboxSetMemoryLimit(true)
        RuntimeHost.installLogSink()
        TunnelLog.shared.write("tunnel starting: \(launch.runtimes.count) runtime(s), libbox \(LibboxVersion())")
        try RuleSetInstaller.ensureInstalled()

        UltraLeaseManager.settleRuntimeFiles(launch.ultra, ledger: usageLedger)
        NativeOutboundLeaseManager.settleRuntimeFiles(launch.nativeOutbound, ledger: usageLedger)
        failedBindings = try runtimes.start(launch.runtimes, optionalGroups: launch.optionalRuntimeGroups)
        // Lease delivery starts before sing-box: an Ultra runtime binds its local listener only
        // after its first lease is installed, so starting sing-box first routes into a closed
        // port (Android: deliver the lease, await the local port, then start sing-box). Both
        // loops are torn down again on every start failure below.
        startUltraLeases(launch)
        startNativeLeases(launch)
        await waitForLocalListeners(launch)

        var serverError: NSError?
        let server = LibboxNewCommandServer(platform, platform, &serverError)
        if let serverError {
            stopUltraLeases()
            stopNativeLeases()
            runtimes.stopAll()
            throw RuntimeHostError("create command server: \(serverError.localizedDescription)")
        }
        guard let server else {
            stopUltraLeases()
            stopNativeLeases()
            runtimes.stopAll()
            throw RuntimeHostError("create command server failed")
        }
        do {
            try server.start()
        } catch {
            stopUltraLeases()
            stopNativeLeases()
            runtimes.stopAll()
            throw RuntimeHostError("start command server: \(error.localizedDescription)")
        }
        commandServer = server

        do {
            try startService()
        } catch {
            stopUltraLeases()
            stopNativeLeases()
            server.close()
            commandServer = nil
            runtimes.stopAll()
            throw error
        }
        statusMonitor.start()
        if let client = launch.panel?.makeClient() {
            let loop = TunnelControlLoop(client: client, usageLedger: usageLedger) { [weak self] alert in
                self?.handleTerminal(alert)
            }
            controlLoop = loop
            loop.start()
        }
        TunnelLog.shared.write("tunnel started")
    }

    override func stopTunnel(with reason: NEProviderStopReason) async {
        TunnelLog.shared.write("tunnel stopping, reason \(reason.rawValue)")
        controlLoop?.stop()
        controlLoop = nil
        stopUltraLeases()
        stopNativeLeases()
        statusMonitor.stop()
        stopService()
        if let server = commandServer {
            try? await Task.sleep(nanoseconds: 100 * NSEC_PER_MSEC)
            server.close()
            commandServer = nil
        }
        runtimes.stopAll()
        UltraLeaseManager.settleRuntimeFiles(launch?.ultra ?? [], ledger: usageLedger)
        NativeOutboundLeaseManager.settleRuntimeFiles(launch?.nativeOutbound, ledger: usageLedger)
    }

    override func handleAppMessage(_ messageData: Data) async -> Data? {
        guard let request = try? JSONDecoder().decode(TunnelRequest.self, from: messageData) else {
            return nil
        }
        let encoder = JSONEncoder()
        switch request.command {
        case .status:
            let libbox = statusMonitor.snapshot()
            var snapshot = TunnelStatusSnapshot()
            snapshot.uplink = libbox.uplink
            snapshot.downlink = libbox.downlink
            snapshot.uplinkTotal = libbox.uplinkTotal
            snapshot.downlinkTotal = libbox.downlinkTotal
            snapshot.connectionsIn = libbox.connectionsIn
            snapshot.connectionsOut = libbox.connectionsOut
            snapshot.memory = libbox.memory
            snapshot.runtimes = runtimes.statuses()
            snapshot.alert = controlLoop?.alert ?? ultraLeases?.alert ?? nativeLeases?.alert
            var byForward: [String: String] = [:]
            for (forwardID, endpoint) in ultraLeases?.activeEndpoints() ?? [:] {
                byForward[String(forwardID)] = endpoint
            }
            if let native = nativeLeases?.activeEndpoint() {
                byForward[String(native.forwardID)] = native.endpoint
            }
            if !byForward.isEmpty {
                snapshot.ultraEndpoints = byForward
            }
            if !failedBindings.isEmpty {
                let failures = failedBindings
                snapshot.bindingErrors = failures.keys.sorted().map { key in "\(key): \(failures[key] ?? "")" }
            }
            return try? encoder.encode(snapshot)
        case .logs:
            return try? encoder.encode(TunnelLogsSnapshot(lines: TunnelLog.shared.snapshot()))
        case .reload:
            guard let next = request.launch else { return nil }
            do {
                try await apply(next)
                return nil
            } catch {
                return error.localizedDescription.data(using: .utf8)
            }
        }
    }

    override func sleep() async {
        commandServer?.pause()
    }

    override func wake() {
        commandServer?.wake()
        refreshControlPlane("device woke")
    }

    /// The default network changed (TunnelPlatformInterface). Path updates come in bursts, so
    /// at most one refresh per 5 s.
    func networkPathChanged() {
        refreshLock.lock()
        let now = Date()
        let due = now.timeIntervalSince(lastPathRefresh) >= 5
        if due { lastPathRefresh = now }
        refreshLock.unlock()
        if due { refreshControlPlane("network changed") }
    }

    /// Heartbeat and re-check the leases now rather than at the end of their waits: after a
    /// sleep a renewal may already be overdue, and after a network change failed renewals can
    /// succeed again.
    private func refreshControlPlane(_ reason: String) {
        TunnelLog.shared.write("\(reason): refreshing heartbeat and leases")
        controlLoop?.nudge()
        ultraLeases?.nudge()
        nativeLeases?.nudge()
    }

    // MARK: Service lifecycle

    private func startService() throws {
        guard let configContent = launch?.configContent, let commandServer else {
            throw RuntimeHostError("missing sing-box configuration")
        }
        do {
            try commandServer.startOrReloadService(configContent, options: LibboxOverrideOptions())
        } catch {
            throw RuntimeHostError("start sing-box: \(error.localizedDescription)")
        }
    }

    private func stopService() {
        do {
            try commandServer?.closeService()
        } catch {
            TunnelLog.shared.write("stop sing-box: \(error.localizedDescription)", level: 2)
        }
        platform.reset()
    }

    func stopServiceFromCommand() {
        stopUltraLeases()
        stopNativeLeases()
        stopService()
        runtimes.stopAll()
        cancelTunnelWithError(nil)
    }

    func reloadService() async throws {
        reasserting = true
        defer { reasserting = false }
        try startService()
    }

    /// Replaces the running plan: runtimes are restarted only when they
    /// changed, sing-box is reloaded in place.
    private func apply(_ next: TunnelLaunchOptions) async throws {
        try next.encoded().write(to: AppGroup.launchSnapshotURL, options: .atomic)
        let previous = launch
        launch = next
        reasserting = true
        defer { reasserting = false }
        let runtimesChanged = previous?.runtimes != next.runtimes
        if runtimesChanged || previous?.ultra != next.ultra || previous?.panel != next.panel {
            stopUltraLeases()
        }
        if runtimesChanged || previous?.nativeOutbound != next.nativeOutbound || previous?.panel != next.panel {
            stopNativeLeases()
        }
        if runtimesChanged {
            runtimes.stopAll()
            UltraLeaseManager.settleRuntimeFiles((previous?.ultra ?? []) + next.ultra, ledger: usageLedger)
            NativeOutboundLeaseManager.settleRuntimeFiles(previous?.nativeOutbound, ledger: usageLedger)
            NativeOutboundLeaseManager.settleRuntimeFiles(next.nativeOutbound, ledger: usageLedger)
            failedBindings = try runtimes.start(next.runtimes, optionalGroups: next.optionalRuntimeGroups)
        }
        startUltraLeases(next)
        startNativeLeases(next)
        // A restarted Ultra runtime has no listener until its lease lands again; keep sing-box
        // from routing into the gap (the tunnel is already up, so the budget is shorter).
        await waitForLocalListeners(next, budgetSeconds: Self.reloadListenerBudgetSeconds)
        do {
            try startService()
        } catch {
            stopUltraLeases()
            stopNativeLeases()
            throw error
        }
        if previous?.panel != next.panel {
            controlLoop?.stop()
            controlLoop = nil
            if let client = next.panel?.makeClient() {
                let loop = TunnelControlLoop(client: client, usageLedger: usageLedger) { [weak self] alert in
                    self?.handleTerminal(alert)
                }
                controlLoop = loop
                loop.start()
            }
        }
    }

    /// How long the tunnel start may spend waiting for the first Ultra listener. The Network
    /// Extension must produce its network settings soon after `startTunnel`; the wait is a
    /// head start, not a hard requirement, so a slow panel/probe window ends the wait and
    /// sing-box starts with the runtime still working on its lease.
    private static let startListenerBudgetSeconds: TimeInterval = 15
    private static let reloadListenerBudgetSeconds: TimeInterval = 8

    /// Waits (bounded, shared deadline) until the local listeners sing-box is about to dial
    /// accept connections. See `UltraLocalListener` for why the runtime binds them late.
    private func waitForLocalListeners(
        _ plan: TunnelLaunchOptions,
        budgetSeconds: TimeInterval = PacketTunnelProvider.startListenerBudgetSeconds
    ) async {
        let optional = plan.optionalRuntimeGroups
        // Only the default binding gates the start: an optional binding that is slow to come up
        // must not hold the tunnel, and it was already skipped when it failed to start.
        var listeners: [(runtime: String, port: Int)] = plan.ultra.compactMap { forward in
            if optional[forward.runtimeName] != nil { return nil }
            return (forward.runtimeName, forward.listenPort)
        }
        if let native = plan.nativeOutbound {
            listeners.append((native.runtimeName, native.socksPort))
        }
        let deadline = Date().addingTimeInterval(budgetSeconds)
        for listener in listeners {
            if let status = runtimes.status(named: listener.runtime), !status.running {
                // A dead runtime never binds; the lease loop reports why on its own.
                TunnelLog.shared.write("runtime \(listener.runtime) stopped: \(status.error ?? "unknown error")", level: 1)
                continue
            }
            let remaining = deadline.timeIntervalSinceNow
            if remaining <= 0 {
                TunnelLog.shared.write("listener \(listener.port) not ready; sing-box starts and the runtime keeps leasing", level: 2)
                continue
            }
            let outcome = await UltraRuntimeReadiness.waitForListenerAsync(
                budgetSeconds: remaining,
                probe: { UltraLocalListener.canConnect(port: listener.port) },
                shouldAbort: { runtimes.status(named: listener.runtime)?.running == false }
            )
            switch outcome {
            case let .ready(milliseconds):
                TunnelLog.shared.write("listener \(listener.port) ready after \(milliseconds)ms")
            case .timedOut:
                TunnelLog.shared.write("listener \(listener.port) not ready after \(Int(remaining))s; sing-box starts and the runtime keeps leasing", level: 2)
            case .aborted:
                TunnelLog.shared.write("runtime \(listener.runtime) stopped while waiting for port \(listener.port)", level: 1)
            }
        }
    }

    /// Starts lease delivery for the plan's Ultra forwards (no-op when one is already running).
    private func startUltraLeases(_ plan: TunnelLaunchOptions) {
        guard ultraLeases == nil, let client = plan.panel?.makeClient() else { return }
        // Bindings whose runtime did not start get no lease loop (it would only raise alerts).
        let groups = plan.optionalRuntimeGroups
        let forwards = plan.ultra.filter { forward in
            guard let group = groups[forward.runtimeName] else { return true }
            return failedBindings[group] == nil
        }
        guard !forwards.isEmpty else { return }
        let essential = Set(forwards.filter { groups[$0.runtimeName] == nil }.map { $0.forwardID })
        let manager = UltraLeaseManager(client: client, forwards: forwards, essentialForwardIDs: essential) { [weak self] message in
            self?.handleUltraUnusable(message)
        }
        ultraLeases = manager
        manager.start()
    }

    private func stopUltraLeases() {
        ultraLeases?.stop()
        ultraLeases = nil
    }

    /// Starts native lease delivery for the plan's native outbound runtime (no-op when running).
    private func startNativeLeases(_ plan: TunnelLaunchOptions) {
        guard nativeLeases == nil, let native = plan.nativeOutbound, let client = plan.panel?.makeClient(),
              let baseRuntime = plan.runtimes.first(where: { $0.name == native.runtimeName })
        else { return }
        let manager = NativeOutboundLeaseManager(
            client: client,
            launch: native,
            restartRuntime: { [weak self] configJSON in
                guard let self = self else { return }
                var restarted = baseRuntime
                restarted.configJSON = configJSON
                self.runtimes.stop(named: native.runtimeName)
                NativeOutboundLeaseManager.settleRuntimeFiles(native, ledger: self.usageLedger)
                try self.runtimes.start([restarted])
            },
            onFatal: { [weak self] message, permanent in
                self?.handleNativeFatal(message, permanent: permanent)
            }
        )
        nativeLeases = manager
        manager.start()
    }

    private func stopNativeLeases() {
        nativeLeases?.stop()
        nativeLeases = nil
    }

    /// Native outbound lost its authorization or its lease expired unrenewed: stop forwarding,
    /// like Android stopping the VPN. After an expiry the launch snapshot is kept, so VPN
    /// On Demand can start the tunnel again; after a lost authorization it is removed, so On
    /// Demand does not restart a plan that cannot work (the app turns On Demand off).
    private func handleNativeFatal(_ message: String, permanent: Bool) {
        TunnelLog.shared.write("native outbound stopping the tunnel: \(message)", level: 1)
        if permanent {
            try? FileManager.default.removeItem(at: AppGroup.launchSnapshotURL)
        }
        stopUltraLeases()
        stopNativeLeases()
        stopService()
        runtimes.stopAll()
        cancelTunnelWithError(RuntimeHostError(message))
    }

    /// The default binding's Ultra forward has had no usable lease past its grace period: stop,
    /// so iOS shows Disconnected rather than a Connected tunnel that carries nothing. The launch
    /// snapshot is kept; VPN On Demand starts the tunnel again with a fresh lease attempt.
    private func handleUltraUnusable(_ message: String) {
        TunnelLog.shared.write("ultra lease unusable, stopping the tunnel: \(message)", level: 1)
        stopUltraLeases()
        stopNativeLeases()
        stopService()
        runtimes.stopAll()
        cancelTunnelWithError(RuntimeHostError(message))
    }

    private func resolveLaunch(_ options: [String: NSObject]?) throws -> TunnelLaunchOptions {
        let launch: TunnelLaunchOptions
        if let data = options?[TunnelLaunchOptions.optionKey] as? Data {
            launch = try TunnelLaunchOptions.decode(data)
            try? data.write(to: AppGroup.launchSnapshotURL, options: .atomic)
        } else {
            // Started by the system (on-demand, "Connect" in Settings): reuse the
            // last plan the app supplied.
            guard let data = try? Data(contentsOf: AppGroup.launchSnapshotURL) else {
                throw RuntimeHostError("no tunnel configuration; open Bridge to connect")
            }
            launch = try TunnelLaunchOptions.decode(data)
        }
        if let panel = launch.panel, panel.makeClient() == nil {
            throw RuntimeHostError("invalid server address; open Bridge and sign in again")
        }
        return launch
    }

    /// The panel revoked this device (kicked/banned/untrusted): stop
    /// forwarding immediately, like Android's SessionRevocationCoordinator.
    private func handleTerminal(_ alert: String) {
        TunnelLog.shared.write("panel revoked this device: \(alert)", level: 1)
        try? FileManager.default.removeItem(at: AppGroup.launchSnapshotURL)
        stopUltraLeases()
        stopNativeLeases()
        stopService()
        runtimes.stopAll()
        cancelTunnelWithError(RuntimeHostError(alert))
    }
}
