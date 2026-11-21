import Combine
import Darwin
import Foundation
import LeikwanIOSCore
import Network

/// Ordinary App-process listeners. Never installs/starts a Network Extension.
@MainActor
final class LocalPortController: ObservableObject {
    enum PhysicalNetwork: String, CaseIterable { case wifi, cellular
        var type: NWInterface.InterfaceType { self == .wifi ? .wifi : .cellular }
        var title: String { self == .wifi ? "Wi-Fi" : "Cellular" }
    }
    enum State: Equatable { case stopped, starting, listening, paused, unavailable }
    @Published private(set) var state: State = .stopped
    @Published private(set) var message: String?
    @Published private(set) var interfaceName: String?
    @Published private(set) var endpoints: [Int64: String] = [:]
    @Published private(set) var portStates: [Int64: String] = [:]
    @Published private(set) var isCleaningUp = false
    @Published var network: PhysicalNetwork = .wifi {
        didSet { if network != oldValue { stop(reason: "Physical network choice changed. Start again.") } }
    }
    @Published var dnsServer = "223.5.5.5" {
        didSet { if dnsServer != oldValue { stop(reason: "Physical DNS changed. Start again.") } }
    }

    var isRunning: Bool { state == .starting || state == .listening }
    private var lifecycle = LocalPortRunGate()
    private var visible: Bool { lifecycle.isForeground }
    private var generation: UUID { lifecycle.generation }
    private var monitor: NWPathMonitor?
    private var interface: NWInterface?
    private var startup: Task<Void, Never>?
    private var poll: Task<Void, Never>?
    private var session: LocalPortSession?
    private var transport: PhysicalPanelTransport?
    private var transportScope: LocalPortEgress?
    private var leases: UltraLeaseManager?
    private var control: TunnelControlLoop?
    private var forwards: [UltraForwardLaunch] = []
    private var ledger: UltraUsageLedger?

    /// Also used by catalog/login refreshes in Local Port mode: no default-route client.
    func makeClient(_ panel: TunnelPanelSession) async throws -> PanelClient {
        guard visible else { throw AppError("Local Port is foreground-only") }
        let epoch = generation
        let selected: NWInterface
        if let interface { selected = interface } else { selected = try await selectInterface() }
        guard lifecycle.permits(epoch) else { throw CancellationError() }
        guard let endpoint = PanelEndpoint(storage: panel.endpoint) else { throw AppError("Invalid panel address") }
        let scope = try egress(selected)
        if transport == nil || transportScope != scope {
            transport?.invalidate()
            transport = PhysicalPanelTransport(interface: selected, egress: scope)
            transportScope = scope
        }
        guard let transport else { throw AppError("Physical network unavailable") }
        return PanelClient(endpoint: endpoint, fallback: PanelEndpoint(storage: panel.backupEndpoint), credentials: PanelCredentials(
            clientId: panel.clientID, agentToken: panel.agentToken, installId: panel.installID,
            deviceName: panel.deviceName, appVersion: panel.appVersion, appBuild: panel.appBuild ?? ""
        ), transport: transport)
    }

    func start(catalog: IOSForwardCatalog, selectedIDs: Set<Int64>, panel: TunnelPanelSession) async {
        guard !isRunning, !isCleaningUp, visible else { return }
        state = .starting; message = nil; portStates = [:]
        lifecycle.invalidate()
        let id = generation
        startup = Task {
            do {
                let selectedInterface = try await self.selectInterface()
                try Task.checkCancellation()
                let scope = try self.egress(selectedInterface)
                let client = try await self.makeClient(panel)
                // Fresh device/session admission before any listener or lease: normal identity,
                // update/terminal/device-limit checks, never a separate exempt client.
                let pulled = try await client.pullCatalog(selectedForwardId: nil, heartbeat: HeartbeatRequest(osRelease: AppModel.osRelease))
                try Task.checkCancellation()
                if ClientUpdatePolicy.merged(previous: nil, raw: pulled.response.raw)?.requiresUpdate(currentBuild: panel.appBuild ?? "") == true {
                    throw AppError("A client update is required by the panel before starting Local Port")
                }
                guard self.lifecycle.permits(id) else { return }
                let selected = selectedIDs.sorted().compactMap { pulled.catalog.supportedForward(id: $0) }
                guard !selected.isEmpty, selected.count == selectedIDs.count else { throw AppError("Select available forwards first") }
                var runtimes: [RuntimeLaunch] = []
                var forwards: [UltraForwardLaunch] = []
                var usedPorts = Set<Int>()
                for forward in selected {
                    if let reason = LocalPortSupport.unavailableReason(forward) { throw AppError(reason) }
                    guard let policy = UltraPolicy.from(forward.contract) else { throw AppError("Forward authorization unavailable") }
                    guard usedPorts.insert(forward.contract.listenPort).inserted else { throw AppError("Selected forwards have conflicting local ports") }
                    guard !UltraLocalListener.canConnect(port: forward.contract.listenPort) else {
                        throw AppError("Local port \(forward.contract.listenPort) is already in use")
                    }
                    let paths = UltraRuntimePaths.compact(directory: AppGroup.localPortRuntimeDirectory.path, forwardId: forward.forwardId)
                    let plan = try UltraRuntimeConfigBuilder.buildPlan(forward: forward.contract, policy: policy, paths: paths, autoSelect: true)
                    let name = "local-\(forward.forwardId)"
                    runtimes.append(RuntimeLaunch(name: name, kind: .ultra, arguments: [], configJSON: try scope.applying(to: plan.configJSON), forwardID: forward.forwardId))
                    forwards.append(UltraForwardLaunch(forwardID: forward.forwardId, runtimeName: name, target: forward.contract.target,
                        backendMode: forward.contract.backendMode, serviceStatus: forward.contract.serviceStatus, listenPort: plan.localListenPort,
                        ultraAccessJSON: try CanonicalJSON.string(forward.contract.ultraAccess ?? [:]), targetDigest: policy.targetDigest,
                        transport: policy.transport, controlSocketPath: paths.controlSocketPath, statusPath: paths.statusPath,
                        maxCandidates: UltraConstants.maxLeaseCandidates, autoSelect: true))
                }
                let ledger = UltraUsageLedger(statusDirectory: AppGroup.localPortRuntimeDirectory, stateFile: AppGroup.sharedDirectory.appendingPathComponent("ultra-usage-state.json"))
                self.ledger = ledger; self.forwards = forwards
                UltraLeaseManager.settleRuntimeFiles(forwards, ledger: ledger)
                let session = LocalPortSession(runtimes: runtimes, egress: scope)
                self.session = session
                try await session.start()
                try Task.checkCancellation()
                guard self.lifecycle.permits(id) else { return }
                let leases = UltraLeaseManager(client: client, forwards: forwards, essentialForwardIDs: Set(forwards.map(\.forwardID)), onConfigurationChanged: { [weak self] in
                    Task { @MainActor in guard self?.generation == id else { return }; self?.stop(reason: "Forward target/port changed. Refresh and start again.", unavailable: true) }
                }, restartBootstrap: { forward, external in
                    try session.restart(forward: forward, external: external)
                }, onUnusable: { [weak self] reason in
                    Task { @MainActor in guard self?.generation == id else { return }; self?.stop(reason: reason, unavailable: true) }
                })
                self.leases = leases; leases.start()
                let feedback = UltraCandidateFeedbackLedger(statusDirectory: AppGroup.localPortRuntimeDirectory, stateFile: AppGroup.sharedDirectory.appendingPathComponent("ultra-feedback-state.json"))
                let control = TunnelControlLoop(client: client, usageLedger: ledger, feedbackLedger: feedback, onUltraRefresh: { ids in leases.requestRefresh(ids) }) { [weak self] reason in
                    Task { @MainActor in guard self?.generation == id else { return }; self?.stop(reason: reason, unavailable: true) }
                }
                self.control = control; control.start()
                self.beginPolling(id: id)
            } catch is CancellationError { /* stop() owns cleanup */ }
            catch {
                guard self.generation == id else { return }
                self.stop(reason: AppModel.describe(error), unavailable: true)
            }
        }
    }

    func stop(reason: String? = nil, paused: Bool = false, unavailable: Bool = false) {
        lifecycle.invalidate()
        startup?.cancel(); startup = nil
        poll?.cancel(); poll = nil
        monitor?.pathUpdateHandler = nil; monitor?.cancel(); monitor = nil; interface = nil; interfaceName = nil
        transport?.invalidate(); transport = nil; transportScope = nil
        leases?.stop(); leases = nil
        control?.stop(); control = nil
        state = paused ? .paused : (unavailable ? .unavailable : .stopped)
        message = reason
        let stopping = session; session = nil
        let finalForwards = forwards; forwards = []
        let finalLedger = ledger; ledger = nil
        portStates = [:]
        guard let stopping else { return }
        // Cancel every Go runtime immediately, then bounded joins off the main actor.
        // No audio/location/VoIP or background-service entitlement is used.
        isCleaningUp = true
        stopping.stop { [weak self] in
            UltraLeaseManager.settleRuntimeFiles(finalForwards, ledger: finalLedger)
            Task { @MainActor in self?.isCleaningUp = false }
        }
    }

    func catalogDidChange(_ catalog: IOSForwardCatalog) {
        guard isRunning else { return }
        for active in forwards {
            guard let current = catalog.supportedForward(id: active.forwardID),
                  current.kind == .ultra, !current.isNativeOutboundRule,
                  current.contract.target == active.target,
                  current.contract.listenPort == active.listenPort,
                  let policy = UltraPolicy.from(current.contract),
                  policy.targetDigest == active.targetDigest, policy.transport == active.transport else {
                stop(reason: "Selected forward changed or became unavailable. Review and start again.", unavailable: true)
                return
            }
        }
    }

    func setAppVisible(_ visible: Bool) {
        lifecycle.setForeground(visible)
        if !visible { stop(reason: "Paused in background. Return to the app and start again; switching apps cannot keep this helper running.", paused: true) }
    }

    private func egress(_ selected: NWInterface) throws -> LocalPortEgress {
        // Explicit literal resolver: never use the VPN/system default resolver for the runtime.
        var address = in_addr()
        let server = dnsServer.trimmingCharacters(in: .whitespacesAndNewlines)
        guard inet_pton(AF_INET, server, &address) == 1 else { throw AppError("Enter a physical-interface DNS server as an IPv4 address") }
        return try LocalPortEgress(interfaceName: selected.name, interfaceIndex: Int(selected.index), dnsServers: [server + ":53"])
    }

    private func selectInterface() async throws -> NWInterface {
        monitor?.pathUpdateHandler = nil; monitor?.cancel()
        let monitor = NWPathMonitor(requiredInterfaceType: network.type)
        self.monitor = monitor
        let expectedType = network.type
        let selected: NWInterface = try await withCheckedThrowingContinuation { continuation in
            let once = PathSelection(continuation)
            monitor.pathUpdateHandler = { [weak self] path in
                let candidate = path.status == .satisfied ? path.availableInterfaces.first(where: {
                    $0.type == expectedType && !$0.name.hasPrefix("utun") && !$0.name.hasPrefix("lo")
                }) : nil
                once.resolve(candidate)
                Task { @MainActor in
                    guard let self, self.monitor === monitor else { return }
                    if let active = self.interface,
                       candidate?.name != active.name || candidate?.index != active.index {
                        self.stop(reason: "Selected physical network changed or is unavailable. No VPN/default-route fallback; select and start again.", unavailable: true)
                    }
                }
            }
            monitor.start(queue: DispatchQueue(label: "bridge.local-ports.path"))
            DispatchQueue.global().asyncAfter(deadline: .now() + 5) { once.resolve(nil) }
        }
        try Task.checkCancellation()
        guard visible, self.monitor === monitor else { throw CancellationError() }
        guard if_nametoindex(selected.name) == UInt32(selected.index) else { throw AppError("Physical network unavailable") }
        interface = selected; interfaceName = selected.name
        return selected
    }

    private func beginPolling(id: UUID) {
        poll = Task {
            while !Task.isCancelled, self.generation == id {
                var allReady = true
                for forward in self.forwards {
                    let runtime = self.session?.status(named: forward.runtimeName)
                    let status = UltraRuntimeStatus.read(path: forward.statusPath)
                    let ready = runtime?.running == true && status?.policyState == .active && status?.errorCode.isEmpty == true && UltraLocalListener.canConnect(port: forward.listenPort)
                    self.portStates[forward.forwardID] = ready ? "Listening · authorized" : (runtime?.error ?? self.leases?.alert ?? (status?.errorCode.isEmpty == false ? status?.errorCode : nil) ?? "Awaiting authorization/listener")
                    allReady = allReady && ready
                    if runtime?.running == false {
                        self.stop(reason: runtime?.error ?? "Local runtime stopped", unavailable: true); return
                    }
                }
                self.state = allReady ? .listening : .starting
                self.message = self.control?.alert ?? self.leases?.alert
                self.endpoints = self.leases?.activeEndpoints() ?? [:]
                try? await Task.sleep(nanoseconds: 1_000_000_000)
            }
        }
    }
}

private final class PathSelection {
    private let lock = NSLock()
    private var continuation: CheckedContinuation<NWInterface, Error>?
    init(_ continuation: CheckedContinuation<NWInterface, Error>) { self.continuation = continuation }
    func resolve(_ interface: NWInterface?) {
        lock.lock(); let continuation = self.continuation; self.continuation = nil; lock.unlock()
        guard let continuation else { return }
        if let interface { continuation.resume(returning: interface) }
        else { continuation.resume(throwing: AppError("Selected Wi-Fi/cellular physical network is unavailable")) }
    }
}

/// Serializes bootstrap restarts against shutdown. stop() never waits on the main actor.
private final class LocalPortSession {
    private let host = RuntimeHost()
    private let queue = DispatchQueue(label: "bridge.local-ports.runtime")
    private let egress: LocalPortEgress
    private var runtimes: [RuntimeLaunch]
    private let stopLock = NSLock()
    private var stopRequested = false
    private var stopped: Bool { stopLock.lock(); defer { stopLock.unlock() }; return stopRequested }
    init(runtimes: [RuntimeLaunch], egress: LocalPortEgress) { self.runtimes = runtimes; self.egress = egress }
    func start() async throws {
        try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
            queue.async {
                do {
                    for runtime in self.runtimes {
                        guard !self.stopped else { throw CancellationError() }
                        try self.host.start([runtime])
                        if self.stopped { self.host.cancelAllUltra(); throw CancellationError() }
                    }
                    continuation.resume()
                } catch { self.host.cancelAllUltra(); continuation.resume(throwing: error) }
            }
        }
    }
    func status(named name: String) -> RuntimeStatus? { host.status(named: name) }
    func restart(forward: UltraForwardLaunch, external: ExternalUotGrant?) throws {
        try queue.sync {
            guard !stopped, var runtime = runtimes.first(where: { $0.name == forward.runtimeName }),
                  let original = runtime.configJSON, var config = JSONLenient.parseObject(original) else { throw CancellationError() }
            config["external_uot"] = external?.bootstrapJSON()
            runtime.configJSON = try egress.applying(to: CanonicalJSON.string(config))
            host.stop(named: runtime.name)
            guard !stopped else { throw CancellationError() }
            try host.start([runtime])
            if stopped { host.cancelAllUltra(); throw CancellationError() }
            if let index = runtimes.firstIndex(where: { $0.name == runtime.name }) { runtimes[index] = runtime }
        }
    }
    func stop(completion: @escaping () -> Void) {
        stopLock.lock(); stopRequested = true; stopLock.unlock()
        host.cancelAllUltra()
        queue.async { self.host.cancelAllUltra(); self.host.stopAllUltra(completion: completion) }
    }
}
