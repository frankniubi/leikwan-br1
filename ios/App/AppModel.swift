import Foundation
import LeikwanIOSCore
import UIKit

/// App state and the actions the screens call. Planning (which runtimes to
/// start, which sing-box config to run) happens here with LeikwanIOSCore; the
/// packet tunnel only executes the resulting TunnelLaunchOptions.
@MainActor
final class AppModel: ObservableObject {
    let tunnel = TunnelController()

    /// Saved server profiles and which one is signed in (`profiles.active`).
    @Published private(set) var profiles = PanelProfileList() {
        didSet { JSONStore.save(profiles, name: "profiles") }
    }
    @Published private(set) var catalog: IOSForwardCatalog?
    @Published private(set) var catalogError: String?
    @Published private(set) var isRefreshing = false
    /// True while the App Review demo account is signed in (`DemoAccount`): no panel requests,
    /// no Network Extension.
    @Published private(set) var isDemo = false
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

    /// CFBundleVersion: the build number (CI sets it to the run number, MARKETING_VERSION stays
    /// "1.0"). Blank when missing, which omits X-Client-Build and never triggers the update gate.
    var appBuild: String {
        (Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String) ?? ""
    }

    /// The signed-in profile; nil shows the sign-in screen.
    var profile: PanelProfile? { profiles.active }

    var selectedForward: IOSSupportedForward? {
        guard let id = selectedForwardID else { return nil }
        return catalog?.bindableForward(id: id)
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
        profiles = Self.withPresetBackups(JSONStore.load(PanelProfileList.self, name: "profiles") ?? PanelProfileList())
        if let active = profiles.active {
            if let token = Keychain.string(active.tokenAccount) {
                if DemoAccount.matches(clientId: active.clientId, token: token) {
                    startDemo()
                } else {
                    client = makeClient(active, token: token)
                    await refreshCatalog()
                }
            } else {
                // The token is gone (Keychain reset): back to the profile list.
                profiles.activeId = nil
            }
        }
    }

    // MARK: Profiles

    /// A profile's backup is the built-in pairing of its server (`PanelPreset`), never a saved
    /// value: applied on load, so a file written before the pairing existed follows the app.
    private static func withPresetBackups(_ list: PanelProfileList) -> PanelProfileList {
        var updated = list
        updated.profiles = list.profiles.map { profile in
            var profile = profile
            profile.backupServer = PanelPreset.backup(for: profile.server)
            return profile
        }
        return updated
    }

    /// Signs in with a new or edited profile. `token` nil or blank reuses the token saved for
    /// that profile. On success the profile is saved (an existing one for the same server and
    /// Client ID is updated instead) and becomes the signed-in profile.
    func signIn(_ draft: PanelProfile, token: String?) async throws {
        let typed = (token ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        let saved = profiles.profile(id: draft.id).flatMap { Keychain.string($0.tokenAccount) }
        let agentToken = typed.isEmpty ? (saved ?? "") : typed
        guard draft.clientId > 0, !agentToken.isEmpty else {
            throw AppError(L("Enter the Client ID and Agent Token"))
        }
        if DemoAccount.matches(clientId: draft.clientId, token: agentToken) {
            var list = profiles
            let stored = list.upsert(draft)
            list.activeId = stored.id
            Keychain.set(agentToken, for: stored.tokenAccount)
            adoptAccount(stored)
            profiles = list
            startDemo()
            return
        }
        let candidate = makeClient(draft, token: agentToken)
        let sameAccount = UserDefaults.standard.string(forKey: Self.accountKeyDefaultsKey) == draft.accountKey
        let result = try await candidate.pullCatalog(
            selectedForwardId: sameAccount ? selectedForwardID : nil,
            heartbeat: HeartbeatRequest(osRelease: Self.osRelease)
        )
        var list = profiles
        let stored = list.upsert(draft)
        list.activeId = stored.id
        Keychain.set(agentToken, for: stored.tokenAccount)
        adoptAccount(stored)
        profiles = list
        client = candidate
        apply(result.catalog)
        scheduleCloudSync(after: result.response)
    }

    /// Signs in with a saved profile and its saved token.
    func signIn(profileID: UUID) async throws {
        guard let saved = profiles.profile(id: profileID) else { throw AppError(L("Profile not found")) }
        guard Keychain.string(saved.tokenAccount) != nil else {
            throw AppError(L("No Agent Token is saved for this profile. Edit the profile to enter it"))
        }
        try await signIn(saved, token: nil)
    }

    /// Whether `profile` has an Agent Token on this device.
    func hasSavedToken(_ profile: PanelProfile) -> Bool {
        return Keychain.string(profile.tokenAccount) != nil
    }

    /// Back to the profile list. The token stays saved unless the profile opted out.
    func signOut() {
        tunnel.disconnect()
        if let current = profile, !current.rememberToken {
            Keychain.set(nil, for: current.tokenAccount)
        }
        profiles.activeId = nil
        client = nil
        catalog = nil
        isDemo = false
    }

    /// The demo session: the catalog comes from `DemoAccount`, and no client exists, so nothing
    /// reaches the panel (`refreshCatalog` and cloud sync both need a client).
    private func startDemo() {
        isDemo = true
        client = nil
        apply(DemoAccount.catalog(selectedForwardId: selectedForwardID))
    }

    /// Removes a saved profile and its token.
    func deleteProfile(_ id: UUID) {
        if profiles.activeId == id { signOut() }
        if let removed = profiles.profile(id: id) {
            Keychain.set(nil, for: removed.tokenAccount)
        }
        profiles.remove(id: id)
    }

    /// UserDefaults key of the account (`PanelProfile.accountKey`) the cached forward choices,
    /// bindings and cloud nodes belong to.
    private static let accountKeyDefaultsKey = "account_key"

    /// Signing in to another account drops what was cached for the previous one; nodes added
    /// on this device stay.
    private func adoptAccount(_ profile: PanelProfile) {
        let defaults = UserDefaults.standard
        guard defaults.string(forKey: Self.accountKeyDefaultsKey) != profile.accountKey else { return }
        nodes.removeAll { $0.isHosted }
        if let selected = selectedNodeID, !nodes.contains(where: { $0.id == selected }) {
            selectedNodeID = nodes.first?.id
        }
        cloudNodeState = CloudNodeSyncState()
        bindingPreferences = BindingPreferences()
        nativeOutboundPreferences = NativeOutboundPreferences()
        selectedForwardID = nil
        catalog = nil
        defaults.set(profile.accountKey, forKey: Self.accountKeyDefaultsKey)
    }

    func refreshCatalog() async {
        if isDemo {
            apply(DemoAccount.catalog(selectedForwardId: selectedForwardID))
            return
        }
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
        // A chosen forward that is no longer offered stays chosen, so Connect reports it
        // instead of silently exiting through another forward; only a first choice is made here.
        if selectedForwardID == nil {
            selectedForwardID = catalog.bindableForwards.first?.forwardId
        }
        dropNativeOutboundBindings(catalog)
    }

    private func makeClient(_ profile: PanelProfile, token: String) -> PanelClient {
        PanelClient(
            endpoint: profile.server,
            fallback: profile.backupServer,
            credentials: credentials(clientID: profile.clientId, token: token)
        )
    }

    /// sing-box builder for the signed-in profile: its server hosts stay direct, rule-sets are
    /// the bundled copies in the app group.
    func makeSingboxBuilder() -> SingboxConfigBuilder {
        let settings = SingboxSettings(
            dnsServer: routing.dnsServer,
            bypassChina: routing.bypassChina,
            customBypassRules: routing.customBypass,
            enableIPv6: routing.enableIPv6,
            cacheFilePath: AppGroup.workingDirectory.appendingPathComponent("cache.db").path,
            ruleSetDirectory: AppGroup.ruleSetDirectory.path
        )
        return SingboxConfigBuilder(settings: settings, safePathRegistry: .iosBaseline(panelHosts: profile?.panelHosts ?? []))
    }

    private func credentials(clientID: Int64, token: String) -> PanelCredentials {
        PanelCredentials(
            clientId: clientID,
            agentToken: token,
            installId: Keychain.installID(),
            deviceName: Self.deviceName,
            appVersion: appVersion,
            appBuild: appBuild
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
                return L("Subscription import failed: %@", String(describing: Self.describe(error)))
            }
        }
        if added == 0 {
            return result.skippedLines > 0 ? L("No Shadowsocks / SOCKS5 nodes found (skipped %@ lines)", String(describing: result.skippedLines)) : L("Nothing to import on the clipboard")
        }
        return result.skippedLines > 0
            ? L("Imported %@ node(s), skipped %@ lines", String(added), String(result.skippedLines))
            : L("Imported %@ node(s)", String(added))
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
        let name = URL(string: url)?.host ?? L("Subscription %@", String(describing: id))
        let subscription = Subscription(id: id, name: name, url: url, lastUpdated: nil)
        subscriptions.append(subscription)
        return try await refreshSubscription(subscription)
    }

    @discardableResult
    func refreshSubscription(_ subscription: Subscription) async throws -> Int {
        guard let url = URL(string: subscription.url) else { throw AppError(L("Invalid subscription URL")) }
        var request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 15)
        request.setValue(ImportRouter.subscriptionUserAgent, forHTTPHeaderField: "User-Agent")
        let (data, response) = try await URLSession.shared.data(for: request)
        if let http = response as? HTTPURLResponse, !(200 ..< 300).contains(http.statusCode) {
            throw AppError(L("Subscription returned HTTP %@", String(describing: http.statusCode)))
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
        if isDemo {
            tunnel.simulateConnection(true)
            return
        }
        do {
            let launch = try buildLaunch()
            await tunnel.connect(launch)
        } catch {
            notice = Self.describe(error)
        }
    }

    /// Re-plans and hands the new plan to a running tunnel (routing edits). Failures show in the
    /// banner: a change that did not reach the tunnel must not look applied.
    func applyToRunningTunnel() async {
        guard tunnel.isRunning else { return }
        do {
            let launch = try buildLaunch()
            if let error = await tunnel.reload(launch) {
                notice = L("The change was not applied to the current connection: %@", error)
            }
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
        guard let profile, let token = Keychain.string(profile.tokenAccount) else { return nil }
        return TunnelPanelSession(
            endpoint: profile.server.storageKey,
            backupEndpoint: profile.backupServer?.storageKey,
            clientID: profile.clientId,
            agentToken: token,
            installID: Keychain.installID(),
            deviceName: Self.deviceName,
            appVersion: appVersion,
            appBuild: appBuild
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
            throw AppError(L("Sign in to use native outbound"))
        }
        guard let availability = nativeOutbound, let selection = nativeOutboundSelection else {
            if nativeOutboundPreferences.forwardID != nil {
                throw AppError(L("The native outbound line you chose is unavailable. Turn on another line or turn native outbound off"))
            }
            throw AppError(L("Native outbound is not available for this forward"))
        }
        guard let forward = catalog?.supportedForward(id: selection.forwardId), forward.kind == .ultra else {
            throw AppError(L("The native outbound forward is unavailable. Refresh and try again"))
        }
        let builder = makeSingboxBuilder()
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
