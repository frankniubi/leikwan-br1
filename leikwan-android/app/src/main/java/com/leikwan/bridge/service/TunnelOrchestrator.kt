package com.leikwan.bridge.service

import android.content.Context
import android.content.Intent
import android.util.Log
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RoutingExplainabilitySnapshot
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingPolicySnapshot
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.SafePathRegistry
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
import java.io.File

private const val TAG = "TunnelOrchestrator"

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
    internal suspend fun buildRoutingSnapshot(nowMs: Long): RoutingPolicySnapshot {
        val enabledNodeIds = db.proxyNodeDao().getEnabled().map { it.id }.toSet()
        return routingEngine.compile(
            globalDefault = prefs.routingDefaultOutcome,
            globalDefaultNodeId = prefs.routingDefaultNodeId?.takeIf { it in enabledNodeIds },
            countryRules = userRuleStore.countryRules().sanitizeNodeTargets(enabledNodeIds),
            categoryRules = userRuleStore.categoryRules().sanitizeNodeTargets(enabledNodeIds),
            rulePackVersions = rulePackManager.activeVersions(),
            compiledAtMs = nowMs,
        )
    }

    suspend fun startForForward(forwardId: Long) = withContext(Dispatchers.IO) {
        Log.i(TAG, "Pulling forward configs...")
        val connectionMode = prefs.proxyConnectionMode
        val proxyNodes = db.proxyNodeDao().getAllList()
        val selectedProxyId = prefs.selectedProxyId
        if (selectedProxyId > 0L && proxyNodes.none { it.id == selectedProxyId }) {
            prefs.selectedProxyId = 0L
        }

        val catalog = panelRepo.pullCatalog(forwardId.takeIf { it > 0L } ?: prefs.selectedForwardId)
        val selectedForward = forwardId.takeIf { it > 0L }

        if (
            connectionMode == ProxyConnectionMode.SERVICES &&
            (selectedForward == null || catalog.visibleForwards.none { it.forwardId == selectedForward })
        ) {
            throw Exception("Select a service before starting in Services mode")
        }

        val selection = ProxySelectionPolicy.validate(
            selectedProxyId = prefs.selectedProxyId,
            forwardId = selectedForward ?: 0L,
            connectionMode = connectionMode,
            proxyNodes = proxyNodes,
        )
        if (selection is ProxySelectionResult.Error) {
            throw Exception(selection.message)
        }
        selection as ProxySelectionResult.Success

        stop()

        val runtimeContext = if (connectionMode == ProxyConnectionMode.SERVICES) {
            val forward = panelRepo.getCachedContract(selectedForward ?: 0L)
                ?: throw Exception("Forward ${selectedForward ?: 0L} not found")
            Log.i(TAG, "Starting forward ${forward.forwardId}: ${forward.serviceName}")
            val binaries = binaryInstaller.prepareBinaryPaths()
            val relayNodes = selection.activeNodes.filter { it.type.lowercase() != "wireguard" }
            if (relayNodes.size > 1) {
                val legs = startNodeLegs(forward, relayNodes.map { it.id }, binaries)
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
                val planned = buildRuntimePlan(forward, binaries)
                processRunner.start(forward.forwardId, planned)
                planned
            }
        } else {
            null
        }

        val routingSnapshot = buildRoutingSnapshot(System.currentTimeMillis())
        val compiled = SingboxConfigBuilder
            .fromPrefs(prefs)
            .build(
                proxyNodes = selection.activeNodes,
                runtimeContext = runtimeContext,
                routingPolicySnapshot = routingSnapshot,
                connectionMode = connectionMode,
            )
        recordExplainability(routingSnapshot, compiled.configJson)
        val configJson = compiled.configJson
        writeDebugConfig(configJson)
        Log.d(TAG, "Generated sing-box config: $configJson")

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
        forward: ForwardContract,
        nodeIds: List<Long>,
        binaries: RuntimeBinaryPaths,
    ): Map<Long, NodeLeg> = nodeIds.mapIndexed { index, nodeId ->
        val legForward = forward.withListenPort(listenPortForNode(forward.listenPort, nodeId))
        val legRuntime = buildRuntimePlan(
            forward = legForward,
            binaries = binaries,
            portSalt = portSaltForNode(nodeId),
        )
        val processKey = NodeLeg.processKey(forward.forwardId, nodeId)
        processRunner.start(processKey, legRuntime, cleanupStale = index == 0)
        nodeId to NodeLeg(
            nodeId = nodeId,
            forwardId = forward.forwardId,
            processKey = processKey,
            mode = legRuntime.mode,
            localListenPort = legRuntime.localListenPort,
            localLegPorts = legRuntime.localLegPorts,
            processPlans = legRuntime.processPlans,
            bypassHosts = legRuntime.bypassHosts,
        )
    }.toMap()

    private fun stopRuntime(runtimeContext: RuntimeContext?) {
        runtimeContext ?: return
        if (runtimeContext.nodeLegs.isNotEmpty()) {
            runtimeContext.nodeLegs.values.forEach { processRunner.stopForward(it.processKey) }
        } else {
            processRunner.stopForward(runtimeContext.forwardId)
        }
    }

    private fun listenPortForNode(baseListenPort: Int, nodeId: Long): Int =
        baseListenPort + 1 + (nodeId % 1000L).toInt()

    private fun portSaltForNode(nodeId: Long): Int = ((nodeId % 1000L) * 20).toInt()

    private fun List<RoutingRule>.sanitizeNodeTargets(enabledNodeIds: Set<Long>): List<RoutingRule> =
        map { rule ->
            val targetNodeId = rule.targetNodeId?.takeIf { it in enabledNodeIds }
            if (targetNodeId == rule.targetNodeId) rule else rule.copy(targetNodeId = targetNodeId)
        }

    private fun writeDebugConfig(configJson: String) {
        runCatching {
            File(context.filesDir, "latest-singbox-config.json").writeText(configJson)
        }
    }

    /**
     * Persist a routing explainability snapshot for the diagnostics UI. Best-effort: a failure here
     * must never break VPN startup.
     */
    private suspend fun recordExplainability(snapshot: RoutingPolicySnapshot, configJson: String) {
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
                    payloadJson = configJson,
                )
            )
        }.onFailure { Log.w(TAG, "Failed to record routing explainability snapshot", it) }
    }
}
