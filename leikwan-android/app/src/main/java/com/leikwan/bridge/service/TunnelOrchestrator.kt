package com.leikwan.bridge.service

import android.content.Context
import android.content.Intent
import android.util.Log
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RoutingExplainabilitySnapshot
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.RulePackManifest
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingPolicySnapshot
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.runtime.BondForwardRunner
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.NodeLeg
import com.leikwan.bridge.runtime.ProcessRunner
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import com.leikwan.bridge.runtime.RuntimeBinaryPaths
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.runtime.SingleForwardRunner
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

private const val TAG = "TunnelOrchestrator"

data class NodeLegsResult(
    val legs: Map<Long, NodeLeg>,
    val failedNodeIds: List<Long>,
)

class TunnelOrchestrator internal constructor(
    private val context: Context,
    private val prefs: Prefs,
    private val db: AppDatabase,
    private val userRuleStore: UserRoutingRuleStore,
    private val rulePackManager: RulePackManager,
    private val explainabilityStore: RoutingExplainabilityStore,
    private val routingEngine: RoutingPolicyEngine,
    // Network/process collaborators are only exercised by startForForward(). They are constructed
    // lazily so snapshot-only seams (buildRoutingSnapshot) stay unit-testable without an
    // EncryptedSharedPreferences-backed PanelRepository (no AndroidKeyStore under Robolectric).
    panelRepoFactory: () -> PanelRepository = { PanelRepository(context) },
    binaryInstallerFactory: () -> RuntimeBinaryInstaller = { RuntimeBinaryInstaller(context) },
    processRunnerFactory: () -> ProcessRunner = { ProcessRunner() },
) {
    constructor(context: Context) : this(
        context = context,
        prefs = Prefs.get(context),
        db = AppDatabase.get(context),
        userRuleStore = UserRoutingRuleStore.from(context),
        rulePackManager = RulePackManager(AppDatabase.get(context), Prefs.get(context)),
        explainabilityStore = RoutingExplainabilityStore(AppDatabase.get(context), Prefs.get(context)),
        routingEngine = RoutingPolicyEngine(
            SafePathRegistry.androidBaseline(Prefs.get(context).panelUrl, "v3-mirror.leikwanhost.com"),
            RoutingCategoryCatalog.default(),
        ),
    )

    private val panelRepo: PanelRepository by lazy(panelRepoFactory)
    private val binaryInstaller: RuntimeBinaryInstaller by lazy(binaryInstallerFactory)
    private val processRunner: ProcessRunner by lazy(processRunnerFactory)

    private var activeRuntime: RuntimeContext? = null

    /**
     * Build the authoritative [RoutingPolicySnapshot] for one startup/rebuild cycle from the
     * persisted user rules, active rule-pack versions, and the user-settable global default.
     */
    internal suspend fun buildRoutingSnapshot(
        nowMs: Long,
        unavailableNodeIds: Set<Long> = emptySet(),
        ignoreProxyNodeTargets: Boolean = false,
    ): RoutingPolicySnapshot {
        val enabledNodeIds = if (ignoreProxyNodeTargets) {
            emptySet()
        } else {
            db.proxyNodeDao().getEnabled().map { it.id }.toSet()
        }
        val availableNodeIds = enabledNodeIds - unavailableNodeIds
        val effectiveRoutingEngine = RulePackManifest.fromSnapshot(rulePackManager.current())
            ?.let { manifest -> routingEngine.withCategoryCatalog(RoutingCategoryCatalog.fromManifest(manifest)) }
            ?: routingEngine
        if (ignoreProxyNodeTargets) {
            return effectiveRoutingEngine.compile(
                globalDefault = UserTrafficOutcome.TUNNEL,
                globalDefaultNodeId = null,
                countryRules = emptyList(),
                categoryRules = emptyList(),
                rulePackVersions = rulePackManager.activeVersions(),
                compiledAtMs = nowMs,
            )
        }
        if (!prefs.routingSelectorEnabled) {
            return effectiveRoutingEngine.compile(
                globalDefault = UserTrafficOutcome.TUNNEL,
                globalDefaultNodeId = prefs.selectedProxyId.takeIf { it > 0L && it in availableNodeIds },
                countryRules = emptyList(),
                categoryRules = emptyList(),
                rulePackVersions = rulePackManager.activeVersions(),
                compiledAtMs = nowMs,
            )
        }
        val warnings = mutableListOf<String>()
        val snapshot = effectiveRoutingEngine.compile(
            globalDefault = prefs.routingDefaultOutcome,
            globalDefaultNodeId = prefs.routingDefaultNodeId?.takeIf { it in availableNodeIds },
            countryRules = userRuleStore.countryRules().sanitizeNodeTargets(availableNodeIds, warnings),
            categoryRules = userRuleStore.categoryRules().sanitizeNodeTargets(availableNodeIds, warnings),
            rulePackVersions = rulePackManager.activeVersions(),
            compiledAtMs = nowMs,
        )
        return if (warnings.isEmpty()) snapshot else snapshot.copy(warnings = snapshot.warnings + warnings)
    }

    suspend fun startForForward(forwardId: Long) = withContext(Dispatchers.IO) {
        Log.i(TAG, "Pulling forward configs...")
        val connectionMode = ProxyConnectionMode.SERVICES
        if (prefs.proxyConnectionMode != ProxyConnectionMode.SERVICES) {
            prefs.proxyConnectionMode = ProxyConnectionMode.SERVICES
        }
        val proxyNodes = db.proxyNodeDao().getAllList()
        val selectedProxyId = prefs.selectedProxyId
        if (selectedProxyId > 0L && proxyNodes.none { it.id == selectedProxyId }) {
            prefs.selectedProxyId = 0L
        }

        val catalog = panelRepo.pullCatalog(forwardId.takeIf { it > 0L } ?: prefs.selectedForwardId)
        val selectedForward = forwardId.takeIf { it > 0L }
            ?: catalog.selectedForwardId
            ?: catalog.visibleForwards.firstOrNull()?.forwardId
        if (selectedForward != null && prefs.selectedForwardId != selectedForward) {
            prefs.selectedForwardId = selectedForward
        }

        if (selectedForward == null || catalog.visibleForwards.none { it.forwardId == selectedForward }) {
            throw Exception("Select a service before starting in Services mode")
        }
        val selectedEffective = panelRepo.getCachedEffectiveForward(selectedForward)
            ?: throw Exception("Forward $selectedForward is not supported on Android")
        val isDedicatedLeiGod = selectedEffective.isDedicatedLeiGod

        val selection = ProxySelectionPolicy.validate(
            selectedProxyId = prefs.selectedProxyId,
            forwardId = selectedForward,
            connectionMode = connectionMode,
            proxyNodes = proxyNodes,
            proxyOptional = isDedicatedLeiGod,
        )
        if (selection is ProxySelectionResult.Error) {
            throw Exception(selection.message)
        }
        selection as ProxySelectionResult.Success

        stop()

        var unavailableNodeIds: Set<Long> = emptySet()
        val runtimeContext = run {
            val forward = selectedEffective.runtimeContract
            Log.i(TAG, "Starting LeiGod forward ${forward.forwardId}: ${forward.serviceName}")
            val binaries = binaryInstaller.prepareBinaryPaths()
            val relayNodes = if (isDedicatedLeiGod) {
                emptyList()
            } else {
                selection.activeNodes.filter { it.type.lowercase() != "wireguard" }
            }
            val missingRelayNodeIds = mutableSetOf<Long>()
            val relayNodeForwards = relayNodes.mapNotNull { node ->
                val nodeForwardId = node.forwardId
                val nodeForward = if (nodeForwardId != null) {
                    panelRepo.getCachedEffectiveForward(nodeForwardId)?.runtimeContract
                } else {
                    forward
                }
                if (nodeForward == null) {
                    Log.w(TAG, "Relay node ${node.id} references unavailable forward $nodeForwardId; dropping it")
                    missingRelayNodeIds += node.id
                    null
                } else {
                    node.id to nodeForward
                }
            }
            if (relayNodes.size > 1) {
                val result = startNodeLegs(relayNodeForwards, binaries)
                val legs = result.legs
                if (legs.isEmpty()) {
                    throw Exception("No relay legs could be started")
                }
                unavailableNodeIds = missingRelayNodeIds + result.failedNodeIds
                RuntimeContext(
                    forwardId = forward.forwardId,
                    mode = forward.mode,
                    localListenPort = legs.values.firstOrNull()?.localListenPort ?: forward.listenPort,
                    localLegPorts = legs.values.flatMap { it.localLegPorts },
                    processPlans = emptyList(),
                    bypassHosts = legs.values.flatMapTo(linkedSetOf()) { it.bypassHosts },
                    nodeLegs = legs,
                )
            } else {
                if (relayNodes.isNotEmpty() && relayNodeForwards.isEmpty()) {
                    throw Exception("Selected relay node forward is unavailable")
                }
                val singleForward = relayNodeForwards.singleOrNull()?.second ?: forward
                val planned = buildRuntimePlan(singleForward, binaries)
                processRunner.start(singleForward.forwardId, planned)
                planned
            }
        }

        val activeRulePackManifest = RulePackManifest.fromSnapshot(rulePackManager.current())
        val routingSnapshot = buildRoutingSnapshot(
            System.currentTimeMillis(),
            unavailableNodeIds = unavailableNodeIds,
            ignoreProxyNodeTargets = isDedicatedLeiGod,
        )
        val compiled = SingboxConfigBuilder
            .fromPrefs(prefs, activeRulePackManifest)
            .build(
                proxyNodes = if (isDedicatedLeiGod) emptyList() else selection.activeNodes,
                runtimeContext = runtimeContext,
                routingPolicySnapshot = routingSnapshot,
                connectionMode = connectionMode,
                runtimeOnlyProxy = isDedicatedLeiGod,
            )
        recordExplainability(routingSnapshot)
        val configJson = compiled.configJson
        Log.d(TAG, "Generated sing-box config for LeiGod forward ${runtimeContext.forwardId}")

        LeikwanVpnService.configJson = configJson
        val intent = Intent(context, LeikwanVpnService::class.java)
        context.startForegroundService(intent)

        when (val vpnResult = VpnStartupMonitor.await(
            timeoutMs = 10_000,
            readState = { LeikwanVpnService.startupSnapshot() },
        )) {
            VpnStartupAwaitResult.Running -> {
                activeRuntime = runtimeContext
            }
            is VpnStartupAwaitResult.Failed -> {
                stopRuntime(runtimeContext)
                throw Exception(vpnResult.errorMessage)
            }
            VpnStartupAwaitResult.Timeout -> {
                stopRuntime(runtimeContext)
                throw Exception("VPN service start timed out")
            }
        }
    }

    fun stop() {
        try {
            LeikwanVpnService.instance?.stopVpn()
        } catch (e: Exception) {
            Log.e(TAG, "Error stopping VPN service", e)
        }
        processRunner.stopAll()
        activeRuntime = null
    }

    fun isRunning(): Boolean = LeikwanVpnService.isRunning

    private fun buildRuntimePlan(
        forward: ForwardContract,
        binaries: RuntimeBinaryPaths,
        portSalt: Int = 0,
    ): RuntimeContext = when (forward.mode) {
        ForwardMode.SINGLE -> SingleForwardRunner.buildPlan(forward, binaries)
        ForwardMode.BOND -> BondForwardRunner.buildPlan(forward, binaries, portSalt)
        else -> throw Exception("Forward ${forward.forwardId} mode ${forward.mode.wireValue} is not supported on Android")
    }

    internal fun startNodeLegs(
        nodeForwards: List<Pair<Long, ForwardContract>>,
        binaries: RuntimeBinaryPaths,
    ): NodeLegsResult {
        val legs = linkedMapOf<Long, NodeLeg>()
        val failed = mutableListOf<Long>()
        nodeForwards.forEachIndexed { index, (nodeId, forward) ->
            try {
                val legForward = forward.withListenPort(listenPortForNode(forward.listenPort, index))
                val legRuntime = buildRuntimePlan(
                    forward = legForward,
                    binaries = binaries,
                    portSalt = portSaltForIndex(index),
                )
                val processKey = NodeLeg.processKey(forward.forwardId, nodeId)
                processRunner.start(processKey, legRuntime, cleanupStale = legs.isEmpty())
                legs[nodeId] = NodeLeg(
                    nodeId = nodeId,
                    forwardId = forward.forwardId,
                    processKey = processKey,
                    mode = legRuntime.mode,
                    localListenPort = legRuntime.localListenPort,
                    localLegPorts = legRuntime.localLegPorts,
                    processPlans = legRuntime.processPlans,
                    bypassHosts = legRuntime.bypassHosts,
                )
            } catch (e: Exception) {
                Log.w(TAG, "Relay leg for node $nodeId failed; dropping it", e)
                failed += nodeId
            }
        }
        return NodeLegsResult(legs, failed)
    }

    private fun stopRuntime(runtimeContext: RuntimeContext?) {
        runtimeContext ?: return
        if (runtimeContext.nodeLegs.isNotEmpty()) {
            runtimeContext.nodeLegs.values.forEach { processRunner.stopForward(it.processKey) }
        } else {
            processRunner.stopForward(runtimeContext.forwardId)
        }
    }

    private fun listenPortForNode(baseListenPort: Int, index: Int): Int =
        baseListenPort + 1 + index

    private fun portSaltForIndex(index: Int): Int = (index + 1) * 40

    private fun List<RoutingRule>.sanitizeNodeTargets(
        availableNodeIds: Set<Long>,
        warnings: MutableList<String>,
    ): List<RoutingRule> =
        map { rule ->
            val targetNodeId = rule.targetNodeId?.takeIf { it in availableNodeIds }
            if (targetNodeId == rule.targetNodeId) {
                rule
            } else {
                warnings += "Rule '${rule.id}': target node ${rule.targetNodeId} unavailable; using default."
                rule.copy(targetNodeId = targetNodeId)
            }
        }

    /**
     * Persist a routing explainability snapshot for the diagnostics UI. Best-effort: a failure here
     * must never break VPN startup.
     */
    private suspend fun recordExplainability(snapshot: RoutingPolicySnapshot) {
        runCatching {
            val activeUserRules = (snapshot.userCountryRules + snapshot.userCategoryRules).map { rule ->
                "${rule.kind.name}:${rule.matchValue}->${rule.outcome.name}"
            }
            explainabilityStore.record(
                RoutingExplainabilitySnapshot(
                    revision = prefs.latestExplainabilityRevision + 1,
                    compiledAtMs = snapshot.compileTimestampMs,
                    rulePackCountryVersion = snapshot.activeRulePackVersions.getOrNull(0) ?: "",
                    rulePackCategoryVersion = snapshot.activeRulePackVersions.getOrNull(1) ?: "",
                    activeUserRules = activeUserRules,
                    protectedPaths = snapshot.protectedPaths.map { it.id },
                    warnings = snapshot.warnings,
                    payloadJson = "{}",
                )
            )
        }.onFailure { Log.w(TAG, "Failed to record routing explainability snapshot", it) }
    }
}
