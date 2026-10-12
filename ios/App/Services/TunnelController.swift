import Combine
import Foundation
import NetworkExtension

/// Owns the app's NETunnelProviderManager: installs the VPN profile, starts
/// and stops the packet tunnel, and polls its status.
@MainActor
final class TunnelController: ObservableObject {
    static let tunnelBundleIdentifier = "com.leikwan.bridge.ios.tunnel"

    @Published private(set) var status: NEVPNStatus = .invalid
    @Published private(set) var snapshot = TunnelStatusSnapshot()
    @Published private(set) var lastError: String?

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
        proto.serverAddress = "LeiKwan"
        proto.disconnectOnSleep = false
        manager.protocolConfiguration = proto
        manager.localizedDescription = "LeiKwan"
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
