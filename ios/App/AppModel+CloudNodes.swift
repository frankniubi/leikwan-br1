import Foundation
import LeikwanIOSCore

/// Cloud sync progress shown next to the node list ("同步中…" / "上次同步 12:30" / error).
struct CloudSyncStatus: Equatable {
    /// A cloud call is running.
    var isSyncing: Bool = false
    /// Stable error code of the last failed cloud call (nil after a success), e.g.
    /// "device_untrusted", "hosted_node_invalid", "hosted_nodes_network".
    var lastErrorCode: String?
    /// Chinese text for `lastErrorCode`.
    var lastError: String?
}

/// One row of the bindings screen: a supported forward with its node, or a node used directly.
struct BindingRow: Identifiable {
    let id: BindingID
    /// Nil for a direct-node binding ("直连").
    let forward: IOSSupportedForward?
    /// The node this binding routes to; nil when none is chosen.
    let node: ProxyNode?
    /// Where `node` comes from (this device / cloud explicit / cloud target match / old selection).
    let nodeSource: BindingNodeSource
    /// The panel pairs this forward with a node iOS cannot run (e.g. VLESS).
    let unsupportedCloudNode: HostedNodeDTO?
    /// Will run on Connect (part of the enabled list, or the legacy selection).
    let isEnabled: Bool
    /// Route final + untargeted "proxy" rules.
    let isDefault: Bool
    /// Enabled but will not run, and why (Chinese); nil when it runs or is disabled.
    let skipReason: String?

    /// "<forward> → <node>" / "直连 → <node>".
    var title: String {
        let nodeName = node.map { $0.name.isEmpty ? "\($0.server):\($0.port)" : $0.name }
            ?? unsupportedCloudNode.map { "\($0.name)（iOS 不支持）" }
            ?? "未选择节点"
        guard let forward = forward else { return "直连 → " + nodeName }
        let direction = forward.contract.direction.trimmingCharacters(in: .whitespacesAndNewlines)
        let name = forward.serviceName.trimmingCharacters(in: .whitespacesAndNewlines)
        let forwardName = !name.isEmpty ? name : (!direction.isEmpty ? direction : "转发 #\(forward.forwardId)")
        return forwardName + " → " + nodeName
    }

    /// "本机选择" / "云端绑定" / "云端自动匹配" / "当前节点" / "".
    var nodeSourceLabel: String {
        switch nodeSource {
        case .local: return "本机选择"
        case .cloudExplicit: return "云端绑定"
        case .cloudTargetMatch: return "云端自动匹配"
        case .selection: return "当前节点"
        case .unset: return ""
        }
    }
}

// MARK: - Cloud nodes ("云端节点") and multi-binding routing ("多绑定分流")
//
// Cloud: the panel is the source of truth for hosted nodes while signed in; `nodes` keeps the
// device copy (offline start works from it). Every write goes to the panel first and then
// applies the listing it returns; nothing is queued offline. Cloud -> device sync runs after
// each configs/pull when `client.hosted_nodes_revision` moves.
//
// Bindings: every enabled (forward, node) binding runs at the same time; the default binding is
// the route final; app/country/custom rules can target a binding's node.
extension AppModel {
    // MARK: Cloud node lists

    /// Nodes that are copies of panel nodes (synced to every device of the client).
    var cloudNodes: [ProxyNode] {
        return nodes.filter { $0.isHosted }
    }

    /// Nodes only on this device (can be uploaded with `uploadNodeToCloud` / `uploadAllLocalNodesToCloud`).
    var localOnlyNodes: [ProxyNode] {
        return nodes.filter { !$0.isHosted }
    }

    /// Cloud nodes iOS cannot run (e.g. VLESS): show read-only ("iOS 暂不支持"); never edited or
    /// deleted from iOS.
    var unsupportedCloudNodes: [HostedNodeDTO] {
        guard let clientID = login?.clientID, cloudNodeState.clientId == clientID else { return [] }
        return cloudNodeState.unsupportedNodes
    }

    /// Cloud features need a signed-in panel session.
    var isCloudAvailable: Bool {
        return client != nil && login != nil
    }

    /// Last successful cloud sync for the signed-in client.
    var cloudLastSyncedAt: Date? {
        guard let clientID = login?.clientID, cloudNodeState.clientId == clientID else { return nil }
        return cloudNodeState.syncedAt
    }

    /// Whether a local node can be uploaded (type/cipher the panel accepts).
    func canUploadToCloud(_ node: ProxyNode) -> Bool {
        return !node.isHosted && ProxyShareLink.isCloudUploadable(node)
    }

    // MARK: Cloud actions

    /// "从云端刷新": fetches the listing and applies it. Returns false on failure (see `cloudStatus`).
    @discardableResult
    func refreshCloudNodes() async -> Bool {
        return await performCloud(userInitiated: true) { api in
            let listing = try await api.listHostedNodes()
            self.applyCloudListing(listing)
        }
    }

    /// "上传到云端": uploads one local node; the same row becomes the cloud row. Forwards this
    /// node is chosen for on this device are paired on the panel too.
    @discardableResult
    func uploadNodeToCloud(_ nodeID: Int64) async -> Bool {
        guard let node = nodes.first(where: { $0.id == nodeID }), !node.isHosted else { return false }
        guard ProxyShareLink.isCloudUploadable(node), let uri = ProxyShareLink.format(node) else {
            reportCloudError(HostedNodeError("hosted_node_invalid"), userInitiated: true)
            return false
        }
        return await performCloud(userInitiated: true) { api in
            let listing = try await api.createHostedNode(uri: uri, name: node.name, pairForwardId: nil)
            guard let hostedID = listing.nodeId else { throw HostedNodeError("hosted_node_invalid") }
            self.nodes = CloudNodeMerge.linkUploaded(localId: nodeID, hostedId: hostedID, in: self.nodes)
            self.applyCloudListing(listing)
            let survivor = self.nodes.first { $0.hostedId == hostedID }?.id ?? nodeID
            var preferences = self.bindingPreferences
            let forwards = preferences.nodeByForward.filter { $0.nodeId == nodeID || $0.nodeId == survivor }.map { $0.forwardId }
            for forwardID in forwards {
                preferences.assign(nodeId: survivor, forwardId: forwardID)
            }
            self.bindingPreferences = preferences
            for forwardID in forwards {
                if let pairing = self.cloudNodeState.pairing(forwardId: forwardID), pairing.isExplicit, pairing.hostedNodeId == hostedID {
                    continue
                }
                self.applyCloudListing(try await api.pairHostedNode(forwardId: forwardID, nodeId: hostedID))
            }
        }
    }

    /// "全部同步到云端": uploads every local node the panel accepts (batches of 32).
    /// Returns nil when the call failed before any result.
    func uploadAllLocalNodesToCloud() async -> CloudBulkUploadResult? {
        let outcome = CloudUploadOutcomeBox()
        let ok = await performCloud(userInitiated: true) { api in
            let plan = CloudNodeMerge.bulkUploadBatches(self.nodes)
            var result = CloudBulkUploadResult(unsupported: plan.unsupported)
            for batch in plan.batches {
                let listing = try await api.batchCreateHostedNodes(batch)
                for entry in listing.results {
                    guard let localID = Int64(entry.ref) else { continue }
                    if entry.ok, let hostedID = entry.nodeId {
                        self.nodes = CloudNodeMerge.linkUploaded(localId: localID, hostedId: hostedID, in: self.nodes)
                        if entry.created == true { result.uploaded += 1 } else { result.alreadyHosted += 1 }
                    } else {
                        result.failed += 1
                        result.failures.append(CloudUploadFailure(localNodeId: localID, code: entry.error ?? "hosted_node_invalid", field: entry.field))
                    }
                }
                self.applyCloudListing(listing)
            }
            outcome.value = result
        }
        if ok, let result = outcome.value {
            notice = result.summary
        }
        return ok ? outcome.value : nil
    }

    /// Saves an edited cloud node: the panel first, the device copy once it accepted.
    /// A change of device-only fields (enabled) is saved locally without a call.
    @discardableResult
    func saveCloudNode(_ edited: ProxyNode) async -> Bool {
        guard let hostedID = edited.hostedId, let index = nodes.firstIndex(where: { $0.id == edited.id }) else { return false }
        let current = nodes[index]
        if ProxyShareLink.format(current) == ProxyShareLink.format(edited) {
            nodes[index] = edited
            return true
        }
        guard ProxyShareLink.isCloudUploadable(edited), let uri = ProxyShareLink.format(edited) else {
            reportCloudError(HostedNodeError("hosted_node_invalid"), userInitiated: true)
            return false
        }
        let enabled = edited.enabled
        return await performCloud(userInitiated: true) { api in
            let listing = try await api.updateHostedNode(id: hostedID, uri: uri, name: edited.name)
            self.applyCloudListing(listing)
            if let row = self.nodes.firstIndex(where: { $0.id == edited.id }), self.nodes[row].enabled != enabled {
                self.nodes[row].enabled = enabled
            }
        }
    }

    /// Deletes cloud nodes on the panel (for every device of the client).
    func deleteCloudNodes(_ ids: Set<Int64>) async {
        let hostedIDs = nodes.filter { ids.contains($0.id) }.compactMap { $0.hostedId }
        if hostedIDs.isEmpty { return }
        _ = await performCloud(userInitiated: true) { api in
            for hostedID in hostedIDs.sorted() {
                do {
                    self.applyCloudListing(try await api.deleteHostedNode(id: hostedID))
                } catch let error as HostedNodeError where error.code == "hosted_node_not_found" {
                    // Already gone (deleted on another device): resync.
                    self.applyCloudListing(try await api.listHostedNodes())
                }
            }
        }
    }

    // MARK: Bindings

    /// What Connect will run (default first) and which enabled bindings are skipped, with reasons.
    var resolvedBindings: ResolvedBindings {
        return BindingResolver.resolve(
            preferences: bindingPreferences,
            forwards: catalog?.supported ?? [],
            nodes: nodes,
            cloud: effectiveCloudState,
            legacyForwardId: selectedForwardID,
            legacyNodeId: selectedNodeID
        )
    }

    /// One row per supported forward (in panel order), then the enabled direct-node bindings.
    var bindingRows: [BindingRow] {
        let resolved = resolvedBindings
        let enabledIDs = effectiveEnabledBindingIDs
        let cloud = effectiveCloudState
        let legacy = bindingPreferences.enabled.isEmpty
        let defaultID = resolved.defaultBinding?.id
        var rows: [BindingRow] = []
        for forward in catalog?.supported ?? [] {
            let id = BindingID.forward(forward.forwardId)
            let fallback = legacy && forward.forwardId == selectedForwardID ? selectedNodeID : nil
            let choice = BindingResolver.effectiveNode(forwardId: forward.forwardId, preferences: bindingPreferences,
                                                       cloud: cloud, fallbackNodeId: fallback)
            let node = choice.nodeId.flatMap { nodeID in nodes.first { $0.id == nodeID } }
            var unsupported: HostedNodeDTO?
            if node == nil, let pairing = cloud.pairing(forwardId: forward.forwardId), pairing.localNodeId == nil {
                unsupported = cloud.unsupportedNodes.first { $0.id == pairing.hostedNodeId }
            }
            rows.append(makeRow(id: id, forward: forward, node: node, source: node == nil ? .unset : choice.source,
                                unsupported: unsupported, enabledIDs: enabledIDs, defaultID: defaultID, resolved: resolved))
        }
        for id in enabledIDs {
            guard let nodeID = id.directNodeId else { continue }
            let node = nodes.first { $0.id == nodeID }
            rows.append(makeRow(id: id, forward: nil, node: node, source: .local, unsupported: nil,
                                enabledIDs: enabledIDs, defaultID: defaultID, resolved: resolved))
        }
        return rows
    }

    /// Enabled bindings that have a node: the choices for a rule's target picker.
    var routingTargetBindings: [BindingRow] {
        return bindingRows.filter { $0.isEnabled && $0.node != nil && $0.skipReason == nil }
    }

    /// Chooses the node a forward uses (nil clears it). A cloud node is paired on the panel for
    /// every device; a local-only node stays a choice on this device; clearing also clears an
    /// explicit panel pairing. Applies to a running tunnel.
    func setNode(_ nodeID: Int64?, forForward forwardID: Int64) async {
        bindingPreferences.assign(nodeId: nodeID, forwardId: forwardID)
        if bindingPreferences.enabled.isEmpty && forwardID == selectedForwardID, let nodeID = nodeID {
            selectedNodeID = nodeID
        }
        await applyToRunningTunnel()
        guard isCloudAvailable else { return }
        let pairing = cloudNodeState.pairing(forwardId: forwardID)
        if let nodeID = nodeID {
            guard let hostedID = nodes.first(where: { $0.id == nodeID })?.hostedId else { return }
            if let pairing = pairing, pairing.isExplicit, pairing.hostedNodeId == hostedID { return }
            _ = await performCloud(userInitiated: false) { api in
                self.applyCloudListing(try await api.pairHostedNode(forwardId: forwardID, nodeId: hostedID))
            }
        } else if let pairing = pairing, pairing.isExplicit {
            _ = await performCloud(userInitiated: false) { api in
                self.applyCloudListing(try await api.pairHostedNode(forwardId: forwardID, nodeId: nil))
            }
        }
    }

    /// Turns a binding on or off. The first change turns the legacy single selection into an
    /// explicit list (so the binding that ran before keeps running). Applies to a running tunnel.
    func setBindingEnabled(_ id: BindingID, enabled: Bool) {
        var preferences = bindingPreferences
        if preferences.enabled.isEmpty {
            preferences.enabled = legacyBindingIDs
            if preferences.defaultBinding == nil { preferences.defaultBinding = legacyBindingIDs.first }
        }
        if enabled {
            if !preferences.enabled.contains(id) { preferences.enabled.append(id) }
        } else {
            preferences.enabled.removeAll { $0 == id }
            if preferences.defaultBinding == id { preferences.defaultBinding = preferences.enabled.first }
        }
        bindingPreferences = preferences
        scheduleTunnelReload()
    }

    /// "使用此节点直连": a node without a forward as its own binding.
    func addDirectBinding(nodeID: Int64) {
        setBindingEnabled(.direct(nodeId: nodeID), enabled: true)
    }

    /// Makes a binding the default (route final). Enables it, and mirrors it into
    /// `selectedForwardID` / `selectedNodeID` so screens built on the single selection show it.
    func setDefaultBinding(_ id: BindingID) {
        var preferences = bindingPreferences
        if preferences.enabled.isEmpty { preferences.enabled = legacyBindingIDs }
        if !preferences.enabled.contains(id) { preferences.enabled.append(id) }
        preferences.defaultBinding = id
        bindingPreferences = preferences
        if let forwardID = id.forwardId {
            selectedForwardID = forwardID
            if let nodeID = BindingResolver.effectiveNode(forwardId: forwardID, preferences: preferences, cloud: effectiveCloudState).nodeId {
                selectedNodeID = nodeID
            }
        } else if let nodeID = id.directNodeId {
            selectedNodeID = nodeID
        }
        scheduleTunnelReload()
    }

    // MARK: Rule targets ("走哪个绑定")

    /// Node an app preset's TUNNEL rule is pinned to (nil = default binding).
    func appPresetTarget(_ key: String) -> Int64? {
        return bindingPreferences.appPresetTargets[key]
    }

    func setAppPresetTarget(_ key: String, nodeID: Int64?) {
        bindingPreferences.appPresetTargets[key] = nodeID
        scheduleTunnelReload()
    }

    /// Node a country's TUNNEL rule is pinned to (nil = default binding). Codes are upper-case.
    func countryTarget(_ code: String) -> Int64? {
        return bindingPreferences.countryTargets[code.uppercased()]
    }

    func setCountryTarget(_ code: String, nodeID: Int64?) {
        bindingPreferences.countryTargets[code.uppercased()] = nodeID
        scheduleTunnelReload()
    }

    /// Pins a custom PROXY rule to a binding's node (nil = default binding).
    func setCustomRuleTarget(ruleID: String, nodeID: Int64?) {
        guard let index = routing.customRules.firstIndex(where: { $0.id == ruleID }) else { return }
        routing.customRules[index].targetNodeId = nodeID
        scheduleTunnelReload()
    }

    /// Picker label for a target: the binding title, or "默认绑定".
    func routingTargetTitle(nodeID: Int64?) -> String {
        guard let nodeID = nodeID else { return "默认绑定" }
        if let row = bindingRows.first(where: { $0.node?.id == nodeID && $0.isEnabled }) { return row.title }
        if let node = nodes.first(where: { $0.id == nodeID }) { return "\(node.name)（未启用）" }
        return "节点已删除"
    }

    // MARK: Launch planning

    /// The multi-binding launch (called by `buildLaunch()` when native outbound is off).
    func buildBindingsLaunch() throws -> TunnelLaunchOptions {
        let settings = SingboxSettings(
            dnsServer: routing.dnsServer,
            bypassChina: routing.bypassChina,
            customBypassRules: routing.customBypass,
            enableIPv6: routing.enableIPv6,
            cacheFilePath: AppGroup.workingDirectory.appendingPathComponent("cache.db").path
        )
        let builder = SingboxConfigBuilder(settings: settings)
        let preferences = bindingPreferences
        let snapshot = builder.routingEngine.compile(
            globalMode: routing.mode,
            customRules: routing.customRules,
            appPresetRules: routing.appPresets.sorted { $0.key < $1.key }.map { key, outcome in
                RoutingRule(id: "app-\(key)", kind: .appPreset, matchValue: key, outcome: outcome,
                            targetNodeId: outcome == .tunnel ? preferences.appPresetTargets[key] : nil)
            },
            countryRules: routing.countries.sorted { $0.key < $1.key }.map { code, outcome in
                RoutingRule(id: "country-\(code)", kind: .country, matchValue: code, outcome: outcome,
                            targetNodeId: outcome == .tunnel ? preferences.countryTargets[code.uppercased()] : nil)
            }
        )
        let panel = panelSession()
        let environment = MultiBindingEnvironment(
            runtimeDirectory: AppGroup.runtimeDirectory.path,
            panelId: PanelEndpoint.fromStorage(login?.endpoint).urlString,
            clientId: login?.clientID ?? 0,
            hasPanelSession: panel != nil
        )
        let plan: MultiBindingPlan
        do {
            plan = try MultiBindingPlanner.plan(resolved: resolvedBindings, environment: environment, builder: builder, routing: snapshot)
        } catch let error as MultiBindingPlanError {
            throw AppError(error.message)
        }
        if !plan.skipped.isEmpty {
            let first = plan.skipped[0]
            let title = bindingRows.first { $0.id == first.id }?.title ?? first.id.rawValue
            notice = "已跳过 \(plan.skipped.count) 个绑定：\(title)（\(first.reason.message)）"
        }
        let runtimes = plan.runtimes.map { runtime in
            RuntimeLaunch(
                name: runtime.name,
                kind: RuntimeLaunch.Kind(rawValue: runtime.role.rawValue) ?? .bond,
                arguments: runtime.arguments,
                configJSON: runtime.configJSON,
                files: runtime.files.map { RuntimeFile(path: $0.path, contents: $0.contents) },
                forwardID: runtime.forwardId
            )
        }
        let ultra = plan.ultra.map { entry in
            UltraForwardLaunch(
                forwardID: entry.forwardId,
                runtimeName: entry.runtimeName,
                target: entry.target,
                backendMode: entry.backendMode,
                serviceStatus: entry.serviceStatus,
                listenPort: entry.listenPort,
                ultraAccessJSON: entry.ultraAccessJSON,
                targetDigest: entry.targetDigest,
                transport: entry.transport,
                controlSocketPath: entry.controlSocketPath,
                statusPath: entry.statusPath,
                maxCandidates: entry.maxCandidates,
                autoSelect: entry.autoSelect
            )
        }
        let bindings = plan.bindings.map { binding in
            TunnelBindingInfo(
                id: binding.id.rawValue,
                forwardID: binding.forwardId,
                nodeID: binding.nodeId,
                outboundTag: binding.outboundTag,
                runtimeNames: binding.runtimeNames,
                isDefault: binding.isDefault
            )
        }
        var launch = TunnelLaunchOptions(configContent: plan.singbox.configJSON, runtimes: runtimes, panel: panel, ultra: ultra)
        launch.bindings = bindings
        return launch
    }

    // MARK: Sync plumbing (called from AppModel.swift)

    /// Loads the cached binding and cloud state at launch (offline start uses the cached nodes).
    func loadCloudNodeCaches() {
        bindingPreferences = JSONStore.load(BindingPreferences.self, name: "bindings") ?? BindingPreferences()
        cloudNodeState = JSONStore.load(CloudNodeSyncState.self, name: "cloud_nodes_state") ?? CloudNodeSyncState()
    }

    /// After every configs/pull: syncs in the background when `hosted_nodes_revision` moved.
    func scheduleCloudSync(after response: PullConfigsResponse) {
        let revision = response.hostedNodesRevision
        Task { await self.syncCloudNodesIfChanged(revision: revision) }
    }

    /// Cloud -> device half of the sync. Never surfaces a banner; failures land in `cloudStatus`
    /// and the next pull retries (the stored revision only moves after a successful apply).
    func syncCloudNodesIfChanged(revision: String?) async {
        guard let clientID = login?.clientID, isCloudAvailable else { return }
        switch CloudNodeMerge.decide(revision: revision, clientId: clientID, state: cloudNodeState, nodes: nodes) {
        case .upToDate:
            return
        case .recordEmpty:
            cloudNodeState = CloudNodeSyncState(clientId: clientID, revision: "", syncedAt: Date())
        case .fetch:
            _ = await performCloud(userInitiated: false) { api in
                self.applyCloudListing(try await api.listHostedNodes())
            }
        }
    }

    // MARK: Internals

    /// Cloud state of the signed-in client only (another client's pairings never apply).
    var effectiveCloudState: CloudNodeSyncState {
        guard let clientID = login?.clientID, cloudNodeState.clientId == clientID else { return .empty }
        return cloudNodeState
    }

    /// The enabled list Connect uses: the stored list, or the legacy single selection.
    var effectiveEnabledBindingIDs: [BindingID] {
        return bindingPreferences.enabled.isEmpty ? legacyBindingIDs : bindingPreferences.enabled
    }

    var legacyBindingIDs: [BindingID] {
        if let forwardID = selectedForwardID, catalog?.supportedForward(id: forwardID) != nil {
            return [.forward(forwardID)]
        }
        if let nodeID = selectedNodeID { return [.direct(nodeId: nodeID)] }
        return []
    }

    /// Materializes a panel listing into `nodes`, `cloudNodeState` and the per-forward choices.
    func applyCloudListing(_ listing: HostedNodesListing) {
        guard let clientID = login?.clientID else { return }
        let result = CloudNodeMerge.apply(listing, to: nodes, previous: cloudNodeState, clientId: clientID)
        nodes = result.nodes
        cloudNodeState = result.state
        var preferences = bindingPreferences
        preferences.forgetNodes(Set(result.removedLocalIds))
        // A pairing set or changed on the panel (console or another device) becomes the forward's
        // node here; a local pick made afterwards wins on this device until it changes again.
        for pairing in result.changedPairings {
            preferences.assign(nodeId: pairing.localNodeId, forwardId: pairing.forwardId)
            if preferences.enabled.isEmpty, pairing.forwardId == selectedForwardID, let local = pairing.localNodeId {
                selectedNodeID = local
            }
        }
        if preferences != bindingPreferences { bindingPreferences = preferences }
        if let selected = selectedNodeID, !nodes.contains(where: { $0.id == selected }) {
            selectedNodeID = nodes.first?.id
        }
        if !result.changedPairings.isEmpty || !result.removedLocalIds.isEmpty {
            scheduleTunnelReload()
        }
    }

    /// Runs one cloud operation after the previous one finished, with busy/error bookkeeping.
    /// `userInitiated` failures also show the banner. Returns true on success.
    func performCloud(userInitiated: Bool, _ operation: @escaping @MainActor (HostedNodeAPI) async throws -> Void) async -> Bool {
        guard let api = client else {
            reportCloudError(HostedNodeError("hosted_nodes_offline"), userInitiated: userInitiated)
            return false
        }
        let previous = cloudQueue
        let task = Task { () -> Error? in
            _ = await previous?.value
            self.cloudStatus.isSyncing = true
            defer { self.cloudStatus.isSyncing = false }
            do {
                try await operation(api)
                return nil
            } catch {
                return error
            }
        }
        cloudQueue = Task { _ = await task.value }
        if let error = await task.value {
            if error is CancellationError { return false }
            reportCloudError(error, userInitiated: userInitiated)
            return false
        }
        cloudStatus.lastErrorCode = nil
        cloudStatus.lastError = nil
        return true
    }

    func reportCloudError(_ error: Error, userInitiated: Bool) {
        let code = HostedNodeError.code(for: error)
        cloudStatus.lastErrorCode = code
        cloudStatus.lastError = HostedNodeError.userMessage(code)
        if userInitiated {
            notice = HostedNodeError.userMessage(code)
        }
    }

    func scheduleTunnelReload() {
        Task { await self.applyToRunningTunnel() }
    }

    private func makeRow(
        id: BindingID,
        forward: IOSSupportedForward?,
        node: ProxyNode?,
        source: BindingNodeSource,
        unsupported: HostedNodeDTO?,
        enabledIDs: [BindingID],
        defaultID: BindingID?,
        resolved: ResolvedBindings
    ) -> BindingRow {
        let enabled = enabledIDs.contains(id)
        let skip = enabled ? resolved.skipped.first(where: { $0.id == id })?.reason.message : nil
        return BindingRow(id: id, forward: forward, node: node, nodeSource: source, unsupportedCloudNode: unsupported,
                          isEnabled: enabled, isDefault: defaultID == id, skipReason: skip)
    }
}

/// Carries the bulk-upload result out of the cloud operation closure (no captured `var`).
final class CloudUploadOutcomeBox {
    var value: CloudBulkUploadResult?
}
