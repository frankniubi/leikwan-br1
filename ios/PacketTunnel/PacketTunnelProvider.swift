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
    /// Ultra usage counters -> heartbeat rows; shared by the control loop and the lease manager.
    private lazy var usageLedger = UltraUsageLedger(
        statusDirectory: AppGroup.runtimeDirectory,
        stateFile: AppGroup.sharedDirectory.appendingPathComponent("ultra-usage-state.json")
    )

    override func startTunnel(options: [String: NSObject]?) async throws {
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

        UltraLeaseManager.settleRuntimeFiles(launch.ultra, ledger: usageLedger)
        try runtimes.start(launch.runtimes)

        var serverError: NSError?
        let server = LibboxNewCommandServer(platform, platform, &serverError)
        if let serverError {
            runtimes.stopAll()
            throw RuntimeHostError("create command server: \(serverError.localizedDescription)")
        }
        guard let server else {
            runtimes.stopAll()
            throw RuntimeHostError("create command server failed")
        }
        do {
            try server.start()
        } catch {
            runtimes.stopAll()
            throw RuntimeHostError("start command server: \(error.localizedDescription)")
        }
        commandServer = server

        do {
            try startService()
        } catch {
            server.close()
            commandServer = nil
            runtimes.stopAll()
            throw error
        }
        // Started once the start can no longer fail, so a failed start leaks no lease loop.
        startUltraLeases(launch)
        statusMonitor.start()
        if let panel = launch.panel {
            let loop = TunnelControlLoop(session: panel, usageLedger: usageLedger) { [weak self] alert in
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
        statusMonitor.stop()
        stopService()
        if let server = commandServer {
            try? await Task.sleep(nanoseconds: 100 * NSEC_PER_MSEC)
            server.close()
            commandServer = nil
        }
        runtimes.stopAll()
        UltraLeaseManager.settleRuntimeFiles(launch?.ultra ?? [], ledger: usageLedger)
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
            snapshot.alert = controlLoop?.alert ?? ultraLeases?.alert
            return try? encoder.encode(snapshot)
        case .logs:
            return try? encoder.encode(TunnelLogsSnapshot(lines: TunnelLog.shared.snapshot()))
        case .reload:
            guard let next = request.launch else { return nil }
            do {
                try apply(next)
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
    private func apply(_ next: TunnelLaunchOptions) throws {
        try next.encoded().write(to: AppGroup.launchSnapshotURL, options: .atomic)
        let previous = launch
        launch = next
        reasserting = true
        defer { reasserting = false }
        let runtimesChanged = previous?.runtimes != next.runtimes
        if runtimesChanged || previous?.ultra != next.ultra || previous?.panel != next.panel {
            stopUltraLeases()
        }
        if runtimesChanged {
            runtimes.stopAll()
            UltraLeaseManager.settleRuntimeFiles((previous?.ultra ?? []) + next.ultra, ledger: usageLedger)
            try runtimes.start(next.runtimes)
        }
        try startService()
        startUltraLeases(next)
        if previous?.panel != next.panel {
            controlLoop?.stop()
            controlLoop = nil
            if let panel = next.panel {
                let loop = TunnelControlLoop(session: panel, usageLedger: usageLedger) { [weak self] alert in
                    self?.handleTerminal(alert)
                }
                controlLoop = loop
                loop.start()
            }
        }
    }

    /// Starts lease delivery for the plan's Ultra forwards (no-op when one is already running).
    private func startUltraLeases(_ plan: TunnelLaunchOptions) {
        guard ultraLeases == nil, !plan.ultra.isEmpty, let panel = plan.panel else { return }
        let manager = UltraLeaseManager(session: panel, forwards: plan.ultra)
        ultraLeases = manager
        manager.start()
    }

    private func stopUltraLeases() {
        ultraLeases?.stop()
        ultraLeases = nil
    }

    private func resolveLaunch(_ options: [String: NSObject]?) throws -> TunnelLaunchOptions {
        if let data = options?[TunnelLaunchOptions.optionKey] as? Data {
            let launch = try TunnelLaunchOptions.decode(data)
            try? data.write(to: AppGroup.launchSnapshotURL, options: .atomic)
            return launch
        }
        // Started by the system (on-demand, "Connect" in Settings): reuse the
        // last plan the app supplied.
        guard let data = try? Data(contentsOf: AppGroup.launchSnapshotURL) else {
            throw RuntimeHostError("no tunnel configuration; open LeiKwan to connect")
        }
        return try TunnelLaunchOptions.decode(data)
    }

    /// The panel revoked this device (kicked/banned/untrusted): stop
    /// forwarding immediately, like Android's SessionRevocationCoordinator.
    private func handleTerminal(_ alert: String) {
        TunnelLog.shared.write("panel revoked this device: \(alert)", level: 1)
        try? FileManager.default.removeItem(at: AppGroup.launchSnapshotURL)
        stopUltraLeases()
        stopService()
        runtimes.stopAll()
        cancelTunnelWithError(RuntimeHostError(alert))
    }
}
