import Combine
import Foundation
import NetworkExtension

/// Owns the app's NETunnelProviderManager: installs the VPN profile, starts
/// and stops the packet tunnel, and polls its status.
@MainActor
final class TunnelController: ObservableObject {
    /// The extension's bundle id is the app's plus ".tunnel" (project.yml).
    static let tunnelBundleIdentifier = (Bundle.main.bundleIdentifier ?? "org.forfoxsakesantuary.foxbridgeinternal") + ".tunnel"

    @Published private(set) var status: NEVPNStatus = .invalid
    @Published private(set) var snapshot = TunnelStatusSnapshot()
    @Published private(set) var lastError: String?
    /// Last 60 one-second traffic samples, oldest first, for the speed chart.
    @Published private(set) var speedHistory: [SpeedSample] = []
    /// Ultra forward id → relay entry "ip:port" last reported by the tunnel; kept across
    /// launches so the card still shows it while disconnected.
    @Published private(set) var ultraEndpoints: [Int64: String] = TunnelController.loadUltraEndpoints()

    /// When the current connection came up (for the run-time counter).
    var connectedSince: Date? { manager?.connection.connectedDate }

    private var manager: NETunnelProviderManager?
    private var statusObserver: NSObjectProtocol?
    private var pollTask: Task<Void, Never>?
    /// Reloads run one after another (see `reload`).
    private var reloadQueue: Task<Void, Never>?

    var isConnected: Bool { status == .connected }
    var isBusy: Bool { status == .connecting || status == .disconnecting || status == .reasserting }
    /// Up or coming up: a new plan has to reach it. The tunnel reports `.reasserting` while it
    /// applies a reload, so a second change made meanwhile must not be dropped.
    var isRunning: Bool { status == .connected || status == .connecting || status == .reasserting }

    func load() async {
        do {
            let managers = try await NETunnelProviderManager.loadAllFromPreferences()
            if let existing = managers.first(where: Self.isOurs) {
                attach(existing)
            }
        } catch {
            lastError = error.localizedDescription
        }
    }

    func connect(_ launch: TunnelLaunchOptions) async {
        lastError = nil
        do {
            let manager = try await installedManager()
            let data = try launch.encoded()
            // Also persisted by the tunnel; written here so a system-initiated
            // restart works even if the first start fails early.
            try? data.write(to: AppGroup.launchSnapshotURL, options: .atomic)
            try manager.connection.startVPNTunnel(options: [TunnelLaunchOptions.optionKey: data as NSData])
        } catch {
            lastError = error.localizedDescription
        }
    }

    /// Demo sessions (`DemoAccount`) never start the Network Extension; the status is simulated.
    private(set) var isSimulated = false

    func simulateConnection(_ connected: Bool) {
        isSimulated = connected
        status = connected ? .connected : .disconnected
        if !connected {
            snapshot = TunnelStatusSnapshot()
            speedHistory = []
        }
    }

    func disconnect() {
        if isSimulated {
            simulateConnection(false)
            return
        }
        manager?.connection.stopVPNTunnel()
    }

    /// Hands a new plan to a running tunnel without dropping the VPN. Reloads are serialized,
    /// and one requested while the tunnel is connecting or applying a previous reload waits
    /// for it. Returns why the plan was not applied; nil when it was, or when no tunnel runs
    /// (the next Connect uses the new plan anyway).
    func reload(_ launch: TunnelLaunchOptions) async -> String? {
        if isSimulated { return nil }
        let previous = reloadQueue
        let task = Task { () -> String? in
            _ = await previous?.value
            guard await self.waitWhileTransitioning() else {
                return self.isRunning ? L("The connection is still reconnecting, so the change was not applied. Try again") : nil
            }
            switch await self.deliver(TunnelRequest(command: .reload, launch: launch)) {
            case let .success(reply):
                guard let reply, let message = String(data: reply, encoding: .utf8), !message.isEmpty else { return nil }
                return message
            case let .failure(error):
                return error.localizedDescription
            }
        }
        reloadQueue = Task { _ = await task.value }
        return await task.value
    }

    /// Waits (bounded) while the tunnel is connecting or reasserting; true once it is connected.
    private func waitWhileTransitioning(timeoutSeconds: Double = 30) async -> Bool {
        let deadline = Date().addingTimeInterval(timeoutSeconds)
        while (status == .connecting || status == .reasserting) && Date() < deadline {
            try? await Task.sleep(nanoseconds: 200_000_000)
        }
        return status == .connected
    }

    func fetchLogs() async -> [String] {
        if let reply = await send(TunnelRequest(command: .logs, launch: nil)),
           let logs = try? JSONDecoder().decode(TunnelLogsSnapshot.self, from: reply),
           !logs.lines.isEmpty {
            return logs.lines
        }
        // The tunnel process is not answering (e.g. it failed to start): use the shared file.
        guard let data = try? Data(contentsOf: AppGroup.tunnelLogURL) else { return [] }
        return String(decoding: data, as: UTF8.self)
            .split(separator: "\n", omittingEmptySubsequences: true)
            .suffix(400)
            .map(String.init)
    }

    private func installedManager() async throws -> NETunnelProviderManager {
        let manager = self.manager ?? NETunnelProviderManager()
        let proto = (manager.protocolConfiguration as? NETunnelProviderProtocol) ?? NETunnelProviderProtocol()
        proto.providerBundleIdentifier = Self.tunnelBundleIdentifier
        proto.serverAddress = "Bridge"
        proto.disconnectOnSleep = false
        manager.protocolConfiguration = proto
        manager.localizedDescription = "Bridge"
        manager.isEnabled = true
        try await manager.saveToPreferences()
        // Reload so the connection object reflects the saved configuration.
        try await manager.loadFromPreferences()
        attach(manager)
        return manager
    }

    private func attach(_ manager: NETunnelProviderManager) {
        self.manager = manager
        if let statusObserver {
            NotificationCenter.default.removeObserver(statusObserver)
        }
        statusObserver = NotificationCenter.default.addObserver(
            forName: .NEVPNStatusDidChange,
            object: manager.connection,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor in
                self?.statusChanged()
            }
        }
        statusChanged()
    }

    private func statusChanged() {
        status = manager?.connection.status ?? .invalid
        if status == .connected {
            startPolling()
        } else {
            pollTask?.cancel()
            pollTask = nil
            if status == .disconnected {
                snapshot = TunnelStatusSnapshot()
                speedHistory = []
                fetchDisconnectError()
            }
        }
    }

    private func fetchDisconnectError() {
        guard #available(iOS 16.0, *), let connection = manager?.connection else { return }
        connection.fetchLastDisconnectError { [weak self] error in
            guard let error else { return }
            Task { @MainActor in
                self?.lastError = error.localizedDescription
            }
        }
    }

    private func startPolling() {
        guard pollTask == nil else { return }
        pollTask = Task { [weak self] in
            while !Task.isCancelled {
                await self?.pollStatus()
                try? await Task.sleep(nanoseconds: 1_000_000_000)
            }
        }
    }

    private func pollStatus() async {
        guard let reply = await send(TunnelRequest(command: .status, launch: nil)),
              let snapshot = try? JSONDecoder().decode(TunnelStatusSnapshot.self, from: reply)
        else {
            return
        }
        self.snapshot = snapshot
        if let reported = snapshot.ultraEndpoints, !reported.isEmpty {
            var merged = ultraEndpoints
            for (key, endpoint) in reported {
                if let forwardID = Int64(key) {
                    merged[forwardID] = endpoint
                }
            }
            if merged != ultraEndpoints {
                ultraEndpoints = merged
                Self.saveUltraEndpoints(merged)
            }
        }
        speedHistory.append(SpeedSample(date: Date(), uplink: snapshot.uplink, downlink: snapshot.downlink))
        if speedHistory.count > 60 {
            speedHistory.removeFirst(speedHistory.count - 60)
        }
    }

    private func send(_ request: TunnelRequest) async -> Data? {
        if case let .success(reply) = await deliver(request) {
            return reply
        }
        return nil
    }

    /// Like `send`, but tells "the tunnel replied nothing" apart from "the message never got there".
    private func deliver(_ request: TunnelRequest) async -> Result<Data?, Error> {
        guard let session = manager?.connection as? NETunnelProviderSession else {
            return .failure(TunnelMessageError())
        }
        let data: Data
        do {
            data = try JSONEncoder().encode(request)
        } catch {
            return .failure(error)
        }
        return await withCheckedContinuation { continuation in
            do {
                try session.sendProviderMessage(data) { reply in
                    continuation.resume(returning: .success(reply))
                }
            } catch {
                continuation.resume(returning: .failure(error))
            }
        }
    }

    private static func isOurs(_ manager: NETunnelProviderManager) -> Bool {
        (manager.protocolConfiguration as? NETunnelProviderProtocol)?.providerBundleIdentifier == tunnelBundleIdentifier
    }
}

struct TunnelMessageError: LocalizedError {
    var errorDescription: String? { L("The VPN connection is not available. Reconnect and try again") }
}

struct SpeedSample: Identifiable, Equatable {
    let date: Date
    let uplink: Int64
    let downlink: Int64

    var id: Date { date }
}

extension TunnelController {
    nonisolated private static let ultraEndpointsKey = "ultra_endpoints"

    nonisolated static func loadUltraEndpoints() -> [Int64: String] {
        guard let stored = UserDefaults.standard.dictionary(forKey: ultraEndpointsKey) as? [String: String] else {
            return [:]
        }
        var result: [Int64: String] = [:]
        for (key, value) in stored {
            if let forwardID = Int64(key) {
                result[forwardID] = value
            }
        }
        return result
    }

    nonisolated static func saveUltraEndpoints(_ endpoints: [Int64: String]) {
        var stored: [String: String] = [:]
        for (forwardID, value) in endpoints {
            stored[String(forwardID)] = value
        }
        UserDefaults.standard.set(stored, forKey: ultraEndpointsKey)
    }
}
