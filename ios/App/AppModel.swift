import Foundation
import LeikwanIOSCore
import UIKit

/// App state and the actions the screens call. Planning (which runtimes to
/// start, which sing-box config to run) happens here with LeikwanIOSCore; the
/// packet tunnel only executes the resulting TunnelLaunchOptions.
@MainActor
final class AppModel: ObservableObject {
    let tunnel = TunnelController()

    @Published private(set) var login: StoredLogin?
    @Published private(set) var catalog: IOSForwardCatalog?
    @Published private(set) var catalogError: String?
    @Published private(set) var isRefreshing = false
    @Published var nodes: [ProxyNode] = [] {
        didSet { JSONStore.save(nodes, name: "nodes") }
    }
    @Published var subscriptions: [Subscription] = [] {
        didSet { JSONStore.save(subscriptions, name: "subscriptions") }
    }
    @Published var routing = RoutingPreferences() {
        didSet { JSONStore.save(routing, name: "routing") }
    }
    @Published var selectedForwardID: Int64? {
        didSet { UserDefaults.standard.set(selectedForwardID.map { NSNumber(value: $0) }, forKey: "selected_forward_id") }
    }
    @Published var selectedNodeID: Int64? {
        didSet { UserDefaults.standard.set(selectedNodeID.map { NSNumber(value: $0) }, forKey: "selected_node_id") }
    }
    /// Transient banner text.
    @Published var notice: String?

    // MARK: Cloud nodes + bindings (stored state only; logic and the UI surface live in
    // AppModel+CloudNodes.swift). Write these through the methods there, not directly.

    /// Which (forward, node) bindings run, the default binding and rule targets.
    @Published var bindingPreferences = BindingPreferences() {
        didSet { JSONStore.save(bindingPreferences, name: "bindings") }
    }
    /// Last applied cloud listing: revision, panel pairings, cloud nodes iOS cannot run.
    @Published var cloudNodeState = CloudNodeSyncState() {
        didSet { JSONStore.save(cloudNodeState, name: "cloud_nodes_state") }
    }
    /// Cloud sync progress for the UI (syncing / last synced / last error).
    @Published var cloudStatus = CloudSyncStatus()
    /// Serializes cloud operations (see `performCloud`).
    var cloudQueue: Task<Void, Never>?

    private(set) var client: PanelClient?

    var appVersion: String {
        (Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String) ?? "0.0.0"
    }

    var selectedForward: IOSSupportedForward? {
        guard let id = selectedForwardID else { return nil }
        return catalog?.supportedForward(id: id)
    }

    var selectedNode: ProxyNode? {
        guard let id = selectedNodeID else { return nil }
        return nodes.first { $0.id == id }
    }

    // MARK: Lifecycle

    func bootstrap() async {
        nodes = JSONStore.load([ProxyNode].self, name: "nodes") ?? []
        subscriptions = JSONStore.load([Subscription].self, name: "subscriptions") ?? []
        routing = JSONStore.load(RoutingPreferences.self, name: "routing") ?? RoutingPreferences()
        selectedForwardID = (UserDefaults.standard.object(forKey: "selected_forward_id") as? NSNumber)?.int64Value
        selectedNodeID = (UserDefaults.standard.object(forKey: "selected_node_id") as? NSNumber)?.int64Value
        loadCloudNodeCaches()
        await tunnel.load()
        if let stored = JSONStore.load(StoredLogin.self, name: "login"), let token = Keychain.string("agent_token") {
            login = stored
            client = makeClient(clientID: stored.clientID, token: token, endpoint: PanelEndpoint.fromStorage(stored.endpoint))
            await refreshCatalog()
        }
    }

    // MARK: Account

    func signIn(clientID: Int64, token: String, endpoint: PanelEndpoint, remember: Bool) async throws {
        let trimmedToken = token.trimmingCharacters(in: .whitespacesAndNewlines)
        guard clientID > 0, !trimmedToken.isEmpty else {
            throw AppError("请填写客户端 ID 和 Agent Token")
        }
        let candidate = makeClient(clientID: clientID, token: trimmedToken, endpoint: endpoint)
        let result = try await candidate.pullCatalog(
            selectedForwardId: selectedForwardID,
            heartbeat: HeartbeatRequest(osRelease: Self.osRelease)
        )
        Keychain.set(trimmedToken, for: "agent_token")
        let stored = StoredLogin(clientID: clientID, endpoint: endpoint.storageKey, rememberToken: remember)
        JSONStore.save(stored, name: "login")
        client = candidate
        login = stored
        apply(result.catalog)
        scheduleCloudSync(after: result.response)
    }

    func signOut() {
        tunnel.disconnect()
        if login?.rememberToken != true {
            Keychain.set(nil, for: "agent_token")
        }
        JSONStore.save(Optional<StoredLogin>.none, name: "login")
        login = nil
        client = nil
        catalog = nil
    }

    func refreshCatalog() async {
        guard let client else { return }
        isRefreshing = true
        defer { isRefreshing = false }
        do {
            let result = try await client.pullCatalog(
                selectedForwardId: selectedForwardID,
                heartbeat: HeartbeatRequest(osRelease: Self.osRelease)
            )
            apply(result.catalog)
            catalogError = nil
            scheduleCloudSync(after: result.response)
        } catch {
            catalogError = Self.describe(error)
        }
    }

    private func apply(_ catalog: IOSForwardCatalog) {
        self.catalog = catalog
        if let id = selectedForwardID, catalog.supportedForward(id: id) == nil {
            selectedForwardID = nil
        }
        if selectedForwardID == nil {
            selectedForwardID = catalog.supported.first?.forwardId
        }
    }

    private func makeClient(clientID: Int64, token: String, endpoint: PanelEndpoint) -> PanelClient {
        PanelClient(endpoint: endpoint, credentials: credentials(clientID: clientID, token: token))
    }

    private func credentials(clientID: Int64, token: String) -> PanelCredentials {
        PanelCredentials(
            clientId: clientID,
            agentToken: token,
            installId: Keychain.installID(),
            deviceName: Self.deviceName,
            appVersion: appVersion
        )
    }

    // MARK: Nodes

    @discardableResult
    func importText(_ text: String) async -> String {
        let result = ImportRouter.process(text)
        var added = addNodes(result.nodes, subscriptionID: nil)
        for url in result.subscriptionURLs {
            do {
                added += try await addSubscription(url: url)
            } catch {
                return "订阅导入失败：\(Self.describe(error))"
            }
        }
        if added == 0 {
            return result.skippedLines > 0 ? "没有识别到 Shadowsocks / SOCKS5 节点（跳过 \(result.skippedLines) 行）" : "剪贴板里没有可导入的内容"
        }
        return "已导入 \(added) 个节点" + (result.skippedLines > 0 ? "，跳过 \(result.skippedLines) 行" : "")
    }

    func addNodes(_ incoming: [ProxyNode], subscriptionID: Int64?) -> Int {
        // A link equal to a cloud node already on this device is not added twice.
        let fresh = ProxyNodeIdentity.withoutHostedDuplicates(incoming, existing: nodes)
        var nextID = (nodes.map(\.id).max() ?? 0) + 1
        var appended: [ProxyNode] = []
        for var node in fresh {
            node.id = nextID
            node.subscriptionId = subscriptionID
            node.enabled = true
            nextID += 1
            appended.append(node)
        }
        nodes.append(contentsOf: appended)
        if selectedNodeID == nil {
            selectedNodeID = appended.first?.id
        }
        return appended.count
    }

    func saveNode(_ node: ProxyNode) {
        // Cloud nodes are written through the panel first (AppModel+CloudNodes.swift).
        if node.isHosted, nodes.contains(where: { $0.id == node.id }) {
            Task { _ = await self.saveCloudNode(node) }
            return
        }
        if let index = nodes.firstIndex(where: { $0.id == node.id }) {
            nodes[index] = node
        } else {
            _ = addNodes([node], subscriptionID: nil)
        }
    }

    func deleteNodes(_ ids: Set<Int64>) {
        // Cloud nodes are deleted on the panel (every device of the client), then resynced.
        let hosted = Set(nodes.filter { ids.contains($0.id) && $0.isHosted }.map { $0.id })
        if !hosted.isEmpty {
            Task { await self.deleteCloudNodes(hosted) }
        }
        let localIDs = ids.subtracting(hosted)
        nodes.removeAll { localIDs.contains($0.id) }
        bindingPreferences.forgetNodes(localIDs)
        if let selected = selectedNodeID, localIDs.contains(selected) {
            selectedNodeID = nodes.first?.id
        }
    }

    func addSubscription(url: String) async throws -> Int {
        let id = (subscriptions.map(\.id).max() ?? 0) + 1
        let name = URL(string: url)?.host ?? "订阅 \(id)"
        let subscription = Subscription(id: id, name: name, url: url, lastUpdated: nil)
        subscriptions.append(subscription)
        return try await refreshSubscription(subscription)
    }

    @discardableResult
    func refreshSubscription(_ subscription: Subscription) async throws -> Int {
        guard let url = URL(string: subscription.url) else { throw AppError("订阅地址无效") }
        var request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 15)
        request.setValue(ImportRouter.subscriptionUserAgent, forHTTPHeaderField: "User-Agent")
        let (data, response) = try await URLSession.shared.data(for: request)
        if let http = response as? HTTPURLResponse, !(200 ..< 300).contains(http.statusCode) {
            throw AppError("订阅返回 HTTP \(http.statusCode)")
        }
        let body = String(decoding: data, as: UTF8.self)
        let parsed = ImportRouter.parseSubscriptionBody(body)
        let selectedWasFromSubscription = selectedNode?.subscriptionId == subscription.id
        nodes.removeAll { $0.subscriptionId == subscription.id }
        if selectedWasFromSubscription { selectedNodeID = nil }
        let count = addNodes(parsed.nodes, subscriptionID: subscription.id)
        if let index = subscriptions.firstIndex(where: { $0.id == subscription.id }) {
            subscriptions[index].lastUpdated = Date()
        }
        return count
    }

    func deleteSubscription(_ subscription: Subscription) {
        subscriptions.removeAll { $0.id == subscription.id }
        let removed = Set(nodes.filter { $0.subscriptionId == subscription.id }.map(\.id))
        deleteNodes(removed)
    }

    // MARK: Connect

    func toggleConnection() async {
        if tunnel.isConnected || tunnel.isBusy {
            tunnel.disconnect()
            return
        }
        do {
            let launch = try buildLaunch()
            await tunnel.connect(launch)
        } catch {
            notice = Self.describe(error)
        }
    }

    /// Re-plans and hands the new plan to a running tunnel (routing edits).
    func applyToRunningTunnel() async {
        guard tunnel.isConnected else { return }
        do {
            await tunnel.reload(try buildLaunch())
        } catch {
            notice = Self.describe(error)
        }
    }

    func buildLaunch() throws -> TunnelLaunchOptions {
        // Native outbound needs no node and has its own plan (see "MARK: Native outbound").
        if nativeOutboundPreferences.enabled {
            return try buildNativeOutboundLaunch()
        }
        // Every enabled (forward, node) binding runs at once; with none configured this is the
        // single selected forward + node, exactly as before (AppModel+CloudNodes.swift).
        return try buildBindingsLaunch()
    }

    func panelSession() -> TunnelPanelSession? {
        guard let login, let token = Keychain.string("agent_token") else { return nil }
        return TunnelPanelSession(
            endpoint: login.endpoint,
            clientID: login.clientID,
            agentToken: token,
            installID: Keychain.installID(),
            deviceName: Self.deviceName,
            appVersion: appVersion
        )
    }

    // MARK: Native outbound
    //
    // Native outbound ("原生出口") uses a plan-approved Ultra carrier forward as a SOCKS5 exit for
    // all traffic, with no user node (Android ConnectionsViewModel / TunnelOrchestrator native path).
    // The UI binds to `nativeOutboundPreferences` and to the read-only members and setters in
    // AppModel+NativeOutbound.swift. While `nativeOutboundPreferences.enabled` is true,
    // buildLaunch() returns the native plan below instead of the node/forward plan.

    /// The user's native outbound choices, persisted across launches. Write it through the
    /// `setNativeOutbound…` methods so the values stay consistent with the entitlement.
    @Published var nativeOutboundPreferences: NativeOutboundPreferences = NativeOutboundPreferences.load() {
        didSet { nativeOutboundPreferences.save() }
    }

    /// One native-mode ultra runtime (SOCKS5 facade on 127.0.0.1) + sing-box `proxy-native`.
    /// The tunnel requests, delivers and renews the native leases (NativeOutboundLeaseManager).
    private func buildNativeOutboundLaunch() throws -> TunnelLaunchOptions {
        guard let panel = panelSession() else {
            throw AppError("原生出口需要先登录")
        }
        guard let availability = nativeOutbound, let selection = nativeOutboundSelection else {
            throw AppError("当前转发不可用原生出口")
        }
        guard let forward = catalog?.supportedForward(id: selection.forwardId), forward.kind == .ultra else {
            throw AppError("原生出口转发不可用，请刷新后重试")
        }
        let settings = SingboxSettings(
            dnsServer: routing.dnsServer,
            bypassChina: routing.bypassChina,
            customBypassRules: routing.customBypass,
            enableIPv6: routing.enableIPv6,
            cacheFilePath: AppGroup.workingDirectory.appendingPathComponent("cache.db").path
        )
        let builder = SingboxConfigBuilder(settings: settings)
        let snapshot = builder.routingEngine.compile(
            globalMode: routing.mode,
            customRules: routing.customRules,
            appPresetRules: routing.appPresets.sorted { $0.key < $1.key }.map { key, outcome in
                RoutingRule(id: "app-\(key)", kind: .appPreset, matchValue: key, outcome: outcome)
            },
            countryRules: routing.countries.sorted { $0.key < $1.key }.map { code, outcome in
                RoutingRule(id: "country-\(code)", kind: .country, matchValue: code, outcome: outcome)
            }
        )
        let paths = UltraRuntimePaths.compact(directory: AppGroup.runtimeDirectory.path, forwardId: forward.forwardId)
        let socksPort = try nativeOutboundSocksPort(forwardID: forward.forwardId)
        let plan: NativeOutboundPlan
        do {
            plan = try NativeOutboundPlanner.plan(
                availability: availability,
                forward: forward.contract,
                selection: selection,
                paths: paths,
                socksPort: socksPort,
                builder: builder,
                routing: snapshot
            )
        } catch let error as UltraLeaseError {
            throw AppError(NativeOutboundErrors.userMessage(error.message))
        }
        let runtimeName = "fwd-\(forward.forwardId)-native"
        let runtime = RuntimeLaunch(
            name: runtimeName,
            kind: .ultra,
            arguments: [],
            configJSON: plan.runtime.configJSON,
            files: [],
            forwardID: forward.forwardId
        )
        let native = NativeOutboundLaunch(
            forwardID: forward.forwardId,
            runtimeName: runtimeName,
            target: forward.contract.target,
            backendMode: forward.contract.backendMode,
            serviceStatus: forward.contract.serviceStatus,
            direction: forward.contract.direction,
            forwardListenPort: forward.contract.listenPort,
            ultraAccessJSON: try CanonicalJSON.string(forward.contract.ultraAccess ?? [:]),
            socksPort: socksPort,
            carrierDirection: plan.carrier.direction,
            carrierScopeDigest: plan.carrier.scopeDigest,
            carrierTransport: plan.carrier.transport,
            carrierRouteScopes: plan.carrier.routeScopes.map { $0.rawValue },
            carrierDefaultRouteScope: plan.carrier.defaultRouteScope.rawValue,
            carrierUnrestrictedAllowed: plan.carrier.unrestrictedAllowed,
            routeScope: plan.selection.routeScope.rawValue,
            routePolicy: plan.selection.routePolicy.rawValue,
            policyGeneration: plan.policy.policyGeneration,
            controlSocketPath: paths.controlSocketPath,
            statusPath: paths.statusPath,
            maxCandidates: NativeOutboundConstants.maxLeaseCandidates
        )
        return TunnelLaunchOptions(configContent: plan.singbox.configJSON, runtimes: [runtime], panel: panel, nativeOutbound: native)
    }

    // MARK: Helpers

    static var osRelease: String {
        "iOS \(UIDevice.current.systemVersion)"
    }

    static var deviceName: String {
        "\(UIDevice.current.model) (\(UIDevice.current.systemName) \(UIDevice.current.systemVersion))"
    }

    static func describe(_ error: Error) -> String {
        if let error = error as? AppError { return error.message }
        if let error = error as? PanelError { return error.description }
        if let error = error as? LocalizedError, let description = error.errorDescription { return description }
        return String(describing: error)
    }
}

struct AppError: LocalizedError {
    let message: String

    init(_ message: String) {
        self.message = message
    }

    var errorDescription: String? { message }
}
