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

    private var client: PanelClient?

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
        var nextID = (nodes.map(\.id).max() ?? 0) + 1
        var appended: [ProxyNode] = []
        for var node in incoming {
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
        if let index = nodes.firstIndex(where: { $0.id == node.id }) {
            nodes[index] = node
        } else {
            _ = addNodes([node], subscriptionID: nil)
        }
    }

    func deleteNodes(_ ids: Set<Int64>) {
        nodes.removeAll { ids.contains($0.id) }
        if let selected = selectedNodeID, ids.contains(selected) {
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
        guard let node = selectedNode else {
            throw AppError("请先添加并选择一个节点")
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

        var nodeForRun = node
        nodeForRun.forwardId = nil
        guard let forward = selectedForward else {
            // No forward selected: dial the node directly.
            let config = try builder.build(proxyNodes: [nodeForRun], runtime: nil, routing: snapshot, connectionMode: .direct)
            return TunnelLaunchOptions(configContent: config.configJSON, runtimes: [], panel: panelSession())
        }
        switch forward.kind {
        case .ultra:
            // The tunnel requests and renews the leases itself (UltraLeaseManager); the plan
            // only carries the static runtime config and the policy it was built from. Lease
            // endpoints need no sing-box bypass: the tunnel process's own sockets skip the tunnel.
            guard let panel = panelSession() else {
                throw AppError("Ultra 转发需要先登录")
            }
            guard let policy = UltraPolicy.from(forward.contract) else {
                throw AppError("Ultra 授权不可用，请刷新后重试")
            }
            let paths = UltraRuntimePaths.compact(directory: AppGroup.runtimeDirectory.path, forwardId: forward.forwardId)
            if paths.controlSocketPath.utf8.count > UltraRuntimePaths.maxSocketPathBytes {
                throw AppError("Ultra 控制通道路径过长")
            }
            let maxCandidates = UltraConstants.maxLeaseCandidates
            // Android turns auto_select on for multi-candidate batches; iOS plans before the
            // leases exist, so it always asks for candidates and lets the runtime choose.
            let autoSelect = maxCandidates > 1
            let plan = try UltraRuntimeConfigBuilder.buildPlan(
                forward: forward.contract,
                policy: policy,
                paths: paths,
                autoSelect: autoSelect
            )
            let config = try builder.build(
                proxyNodes: [nodeForRun],
                runtime: .ultra(plan, leases: []),
                routing: snapshot,
                connectionMode: .services,
                runtimeOnlyProxy: true
            )
            let runtimeName = "fwd-\(forward.forwardId)-ultra"
            let runtime = RuntimeLaunch(
                name: runtimeName,
                kind: .ultra,
                arguments: [],
                configJSON: plan.configJSON,
                files: [],
                forwardID: forward.forwardId
            )
            let entry = UltraForwardLaunch(
                forwardID: forward.forwardId,
                runtimeName: runtimeName,
                target: forward.contract.target,
                backendMode: forward.contract.backendMode,
                serviceStatus: forward.contract.serviceStatus,
                listenPort: plan.localListenPort,
                ultraAccessJSON: try CanonicalJSON.string(forward.contract.ultraAccess ?? [:]),
                targetDigest: policy.targetDigest,
                transport: policy.transport,
                controlSocketPath: paths.controlSocketPath,
                statusPath: paths.statusPath,
                maxCandidates: maxCandidates,
                autoSelect: autoSelect
            )
            return TunnelLaunchOptions(configContent: config.configJSON, runtimes: [runtime], panel: panel, ultra: [entry])
        default:
            let plan = try bondPlan(for: forward)
            let config = try builder.build(proxyNodes: [nodeForRun], runtime: .bond(plan), routing: snapshot, connectionMode: .services)
            let runtimes = plan.invocations.enumerated().map { index, invocation in
                RuntimeLaunch(
                    name: "fwd-\(forward.forwardId)-\(invocation.role.rawValue)-\(index)",
                    kind: RuntimeLaunch.Kind(rawValue: invocation.role.rawValue) ?? .bond,
                    arguments: invocation.arguments,
                    configJSON: nil,
                    files: invocation.role == .bond ? plan.strategyFile.map { [RuntimeFile(path: $0.path, contents: $0.contents)] } ?? [] : [],
                    forwardID: forward.forwardId
                )
            }
            return TunnelLaunchOptions(configContent: config.configJSON, runtimes: runtimes, panel: panelSession())
        }
    }

    private func bondPlan(for forward: IOSSupportedForward) throws -> BondRuntimePlan {
        let strategy = BondStrategyFile.make(
            directory: AppGroup.runtimeDirectory.path,
            panelId: PanelEndpoint.fromStorage(login?.endpoint).urlString,
            clientId: login?.clientID ?? 0,
            forwardId: forward.forwardId
        )
        return try BondPlanBuilder.buildPlan(forward: forward.contract, strategyFile: strategy)
    }

    private func panelSession() -> TunnelPanelSession? {
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
