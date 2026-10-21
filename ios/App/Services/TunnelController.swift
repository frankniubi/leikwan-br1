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

    var isConnected: Bool { status == .connected }
    var isBusy: Bool { status == .connecting || status == .disconnecting || status == .reasserting }

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

    func disconnect() {
        manager?.connection.stopVPNTunnel()
    }

    /// Hands a new plan to a running tunnel without dropping the VPN.
    func reload(_ launch: TunnelLaunchOptions) async {
        guard isConnected else { return }
        let reply = await send(TunnelRequest(command: .reload, launch: launch))
        if let reply, let message = String(data: reply, encoding: .utf8), !message.isEmpty {
            lastError = message
        }
    }

    func fetchLogs() async -> [String] {
        guard let reply = await send(TunnelRequest(command: .logs, launch: nil)),
              let logs = try? JSONDecoder().decode(TunnelLogsSnapshot.self, from: reply)
        else {
            return []
        }
        return logs.lines
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
        guard let session = manager?.connection as? NETunnelProviderSession,
              let data = try? JSONEncoder().encode(request)
        else {
            return nil
        }
        return await withCheckedContinuation { continuation in
            do {
                try session.sendProviderMessage(data) { reply in
                    continuation.resume(returning: reply)
                }
            } catch {
                continuation.resume(returning: nil)
            }
        }
    }

    private static func isOurs(_ manager: NETunnelProviderManager) -> Bool {
        (manager.protocolConfiguration as? NETunnelProviderProtocol)?.providerBundleIdentifier == tunnelBundleIdentifier
    }
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
