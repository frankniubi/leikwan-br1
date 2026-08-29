package com.leikwan.bridge.service

import android.content.Context
import android.content.Intent
import android.util.Log
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ForwardStrategyStore
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
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
import com.leikwan.bridge.runtime.ProtectedConnectivitySet
import com.leikwan.bridge.runtime.RouteDirectionRuntimePlan
import com.leikwan.bridge.runtime.RouteDirectionRuntimePlanner
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import com.leikwan.bridge.runtime.RuntimeBinaryPaths
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.runtime.SingleForwardRunner
import com.leikwan.bridge.runtime.UltraForwardRunner
import com.leikwan.bridge.runtime.UltraLease
import com.leikwan.bridge.runtime.UltraLeaseClient
import com.leikwan.bridge.runtime.UltraPolicy
import com.leikwan.bridge.session.ClientControlLoop
import com.leikwan.bridge.session.PanelControlException
import com.leikwan.bridge.session.SessionGate
import com.leikwan.bridge.session.SessionRevocationCoordinator
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.Instant
import java.util.IdentityHashMap

/** Keeps one runtime owner per Android application process. */
internal class ApplicationScoped<T> {
    private val lock = Any()
    private val instances = IdentityHashMap<Context, T>()

    fun get(context: Context, factory: () -> T): T {
        val application = context.applicationContext ?: context
        synchronized(lock) {
            return instances[application] ?: factory().also { instances[application] = it }
        }
    }
}

private const val TAG = "TunnelOrchestrator"

data class NodeLegsResult(
    val legs: Map<Long, NodeLeg>,
    val failedNodeIds: List<Long>,
)

private data class ActiveUltraRuntime(
    val forwardId: Long,
    val policy: UltraPolicy,
    val controlSocketPath: String,
    val protectedConnectivity: ProtectedConnectivitySet,
)

class TunnelOrchestrator internal constructor(
    private val context: Context,
    private val prefs: Prefs,
    private val db: AppDatabase,
    private val userRuleStore: UserRoutingRuleStore,
    private val rulePackManager: RulePackManager,
    private val explainabilityStore: RoutingExplainabilityStore,
    private val routingEngine: RoutingPolicyEngine,
    private val routeRuleStore: ClientRouteRuleStore = ClientRouteRuleStore.from(context),
    // Network/process collaborators are only exercised by startForForward(). They are constructed
    // lazily so snapshot-only seams (buildRoutingSnapshot) stay unit-testable without an
    // EncryptedSharedPreferences-backed PanelRepository (no AndroidKeyStore under Robolectric).
    panelRepoFactory: () -> PanelRepository = { PanelRepository(context) },
    binaryInstallerFactory: () -> RuntimeBinaryInstaller = { RuntimeBinaryInstaller(context) },
    processRunnerFactory: () -> ProcessRunner = { ProcessRunner() },
    private val ultraLeaseSender: (String, UltraPolicy, UltraLease) -> Boolean = { socketPath, policy, lease ->
        UltraLeaseClient().send(socketPath, policy, lease)
    },
    private val vpnServiceStarter: (Context) -> Unit = { appContext ->
        val intent = Intent(appContext, LeikwanVpnService::class.java)
        appContext.startForegroundService(intent)
    },
    private val vpnStartupAwaiter: suspend () -> VpnStartupAwaitResult = {
        VpnStartupMonitor.await(
            timeoutMs = 10_000,
            readState = { LeikwanVpnService.startupSnapshot() },
        )
    },
) {
    companion object {
        private val applicationInstances = ApplicationScoped<TunnelOrchestrator>()

        fun forApplication(context: Context): TunnelOrchestrator =
            applicationInstances.get(context) {
                TunnelOrchestrator(context.applicationContext ?: context)
            }
    }

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
        routeRuleStore = ClientRouteRuleStore.from(context),
    )

    private val panelRepo: PanelRepository by lazy(panelRepoFactory)
    private val binaryInstaller: RuntimeBinaryInstaller by lazy(binaryInstallerFactory)
    private val processRunner: ProcessRunner by lazy(processRunnerFactory)
    private val controlScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val controlLoop by lazy {
        ClientControlLoop(
            scope = controlScope,
            poll = {
                panelRepo.heartbeat()
                refreshUltraIfDue()
            },
            onTerminal = ::revokeFromPanel,
            shouldContinue = { LeikwanVpnService.isRunning },
        )
    }
    private val revocationCoordinator by lazy {
        SessionRevocationCoordinator(
            stopVpn = {
                runCatching { LeikwanVpnService.instance?.stopVpn() }
            },
            stopRuntimes = {
                activeRouteRuleRuntimeKeys.forEach(processRunner::stopForward)
                activeRouteRuleRuntimeKeys = emptySet()
                processRunner.stopAll()
            },
            clearLeaseState = {
                activeRuntime?.let(UltraForwardRunner::cleanup)
                activeRuntime = null
                activeUltra = null
            },
            clearEnrollment = { panelRepo.logout() },
            onRevoked = SessionGate::markTerminal,
        )
    }

    @Volatile private var activeRuntime: RuntimeContext? = null
    private var activeRouteRuleRuntimeKeys: Set<Long> = emptySet()
    @Volatile private var activeUltra: ActiveUltraRuntime? = null

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

    suspend fun startForForward(forwardId: Long) = startForForward(forwardId, preserveControlLoop = false)

    private suspend fun startForForward(
        forwardId: Long,
        preserveControlLoop: Boolean,
    ) = withContext(Dispatchers.IO) {
        try {
        Log.i(TAG, "Pulling forward configs...")
        val connectionMode = prefs.proxyConnectionMode
        val proxyNodes = db.proxyNodeDao().getAllList()
        val selectedProxyId = prefs.selectedProxyId
        if (selectedProxyId > 0L && proxyNodes.none { it.id == selectedProxyId }) {
            prefs.selectedProxyId = 0L
        }

        val catalog = panelRepo.pullCatalog(forwardId.takeIf { it > 0L } ?: prefs.selectedForwardId)
        val selectedForward = forwardId.takeIf { it > 0L }
            ?: catalog.selectedForwardId
            ?: catalog.visibleForwards.firstOrNull()?.forwardId
        if (connectionMode == ProxyConnectionMode.SERVICES &&
            selectedForward != null && prefs.selectedForwardId != selectedForward
        ) {
            prefs.selectedForwardId = selectedForward
        }

        if (connectionMode == ProxyConnectionMode.SERVICES &&
            (selectedForward == null || catalog.visibleForwards.none { it.forwardId == selectedForward })
        ) {
            throw Exception("Select a service before starting in Services mode")
        }
        val selectedEffective = if (connectionMode == ProxyConnectionMode.SERVICES) {
            val serviceId = requireNotNull(selectedForward)
            panelRepo.getCachedEffectiveForward(serviceId)
                ?: throw Exception("Forward $serviceId is not supported on Android")
        } else {
            null
        }
        val ultraPolicy = selectedEffective
            ?.takeIf { it.mode == ForwardMode.ULTRA }
            ?.runtimeContract
            ?.let(UltraPolicy::from)
        if (selectedEffective?.mode == ForwardMode.ULTRA && ultraPolicy == null) {
            throw Exception("Ultra authorization is unavailable")
        }

        val selection = ProxySelectionPolicy.validate(
            selectedProxyId = prefs.selectedProxyId,
            forwardId = selectedForward ?: 0L,
            connectionMode = connectionMode,
            proxyNodes = proxyNodes,
            // Ultra owns the provider hop, but its fixed target may itself be a
            // protocol endpoint (the production 30522 target is Shadowsocks). When a
            // node is selected, keep exactly that one protocol adapter and point its
            // physical server at the local Ultra listener. With no node configured,
            // retain the raw fixed-target path.
            proxyOptional = selectedEffective?.mode == ForwardMode.ULTRA && prefs.selectedProxyId == 0L,
            singleNodeForServices = selectedEffective?.mode == ForwardMode.ULTRA,
        )
        if (selection is ProxySelectionResult.Error) {
            throw Exception(selection.message)
        }
        selection as ProxySelectionResult.Success

        stop(keepControlLoop = preserveControlLoop)

        var unavailableNodeIds: Set<Long> = emptySet()
        var ultraRuntimeState: ActiveUltraRuntime? = null
        val binaries = binaryInstaller.prepareBinaryPaths()
        val runtimeContext = if (connectionMode == ProxyConnectionMode.SERVICES) run {
            val effective = requireNotNull(selectedEffective)
            val forward = effective.runtimeContract
            Log.i(TAG, "Starting service ${forward.forwardId}: ${forward.serviceName}")
            if (effective.mode == ForwardMode.ULTRA) {
                val policy = requireNotNull(ultraPolicy)
                val planned = UltraForwardRunner.buildPlan(
                    forward = forward,
                    policy = policy,
                    binaries = binaries,
                    runtimeDir = context.filesDir,
                )
                processRunner.start(forward.forwardId, planned.runtimeContext)
                try {
                    val lease = panelRepo.resolveUltraLease(policy)
                    val protectedConnectivity = lease.directConnectivity
                    val accepted = try {
                        ultraLeaseSender(planned.controlSocketFile.absolutePath, policy, lease)
                    } finally {
                        lease.wipe()
                    }
                    if (!accepted) {
                        throw Exception("Ultra lease was rejected by the local runtime")
                    }
                    if (policy.transport != "udp") {
                        processRunner.awaitLocalPort(planned.runtimeContext.localListenPort)
                    }
                    val runtime = planned.runtimeContext.copy(protectedConnectivity = protectedConnectivity)
                    ultraRuntimeState = ActiveUltraRuntime(
                        forwardId = forward.forwardId,
                        policy = policy,
                        controlSocketPath = planned.controlSocketFile.absolutePath,
                        protectedConnectivity = protectedConnectivity,
                    )
                    return@run runtime
                } catch (error: Exception) {
                    processRunner.stopForward(forward.forwardId)
                    throw error
                }
            }
            val relayNodes = selection.activeNodes.filter { it.type.lowercase() != "wireguard" }
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
        } else {
            null
        }

        val routeRuleSnapshot = routeRuleStore.current()
        val routeRuleForwardIds = routeRuleSnapshot.bindings.mapNotNull { it.runtimeForwardId }.distinct()
        val routeRuleForwardContracts = routeRuleForwardIds.mapNotNull { routeForwardId ->
            panelRepo.getCachedContract(routeForwardId)?.let { routeForwardId to it }
        }.toMap()
        val routeRuleRuntimePlan = buildRouteDirectionRuntimePlan(routeRuleSnapshot, routeRuleForwardContracts, binaries)
        routeRuleRuntimePlan.runtimeContexts.forEach { routeRuntime ->
            val processKey = routeRuntime.routeRuleProcessKey ?: return@forEach
            processRunner.start(processKey, routeRuntime, cleanupStale = false)
        }
        activeRouteRuleRuntimeKeys = routeRuleRuntimePlan.runtimeContexts.mapNotNull { it.routeRuleProcessKey }.toSet()
        val singboxRuntimeContext = runtimeContext?.copy(
            bypassHosts = runtimeContext.bypassHosts + routeRuleRuntimePlan.bypassHosts,
            protectedConnectivity = runtimeContext.protectedConnectivity
                .merge(ProtectedConnectivitySet.fromEndpoints(routeRuleRuntimePlan.bypassHosts)),
        )

        val activeRulePackManifest = RulePackManifest.fromSnapshot(rulePackManager.current())
        val routingSnapshot = buildRoutingSnapshot(
            System.currentTimeMillis(),
            unavailableNodeIds = unavailableNodeIds,
            ignoreProxyNodeTargets = selectedEffective?.mode == ForwardMode.ULTRA,
        )
        val compiled = SingboxConfigBuilder
            .fromPrefs(prefs, activeRulePackManifest)
            .build(
                proxyNodes = selection.activeNodes,
                runtimeContext = singboxRuntimeContext,
                routingPolicySnapshot = routingSnapshot,
                connectionMode = connectionMode,
                routeRuleSnapshot = routeRuleSnapshot,
                routeRuleRuntimeBindings = routeRuleRuntimePlan.singboxBindings,
                runtimeOnlyProxy = selectedEffective?.mode == ForwardMode.ULTRA,
            )
        recordExplainability(routingSnapshot)
        val configJson = compiled.configJson
        Log.d(TAG, "Generated sing-box config for $connectionMode")

        LeikwanVpnService.configJson = configJson
        vpnServiceStarter(context)

        when (val vpnResult = vpnStartupAwaiter()) {
            VpnStartupAwaitResult.Running -> {
                activeRuntime = runtimeContext
                activeUltra = ultraRuntimeState
                controlLoop.start()
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
        } catch (terminal: PanelControlException) {
            revokeFromPanel(terminal)
            throw terminal
        }
    }

    fun stop(keepControlLoop: Boolean = false) {
        if (!keepControlLoop) controlLoop.stop()
        try {
            LeikwanVpnService.instance?.stopVpn()
        } catch (e: Exception) {
            Log.e(TAG, "Error stopping VPN service", e)
        }
        activeRouteRuleRuntimeKeys.forEach { processRunner.stopForward(it) }
        activeRouteRuleRuntimeKeys = emptySet()
        processRunner.stopAll()
        activeRuntime?.let(UltraForwardRunner::cleanup)
        activeRuntime = null
        activeUltra = null
    }

    fun isRunning(): Boolean = LeikwanVpnService.isRunning

    private fun buildRuntimePlan(
        forward: ForwardContract,
        binaries: RuntimeBinaryPaths,
        portSalt: Int = 0,
    ): RuntimeContext = when (forward.mode) {
        ForwardMode.SINGLE -> SingleForwardRunner.buildPlan(forward, binaries)
        ForwardMode.BOND -> {
            val strategyFile = ForwardStrategyStore.ensureRuntimeFile(
                context,
                prefs.panelUrl,
                prefs.clientId,
                forward.forwardId,
            )
            BondForwardRunner.buildPlan(
                forward,
                binaries,
                portSalt,
                strategyFilePath = strategyFile.absolutePath,
            )
        }
        ForwardMode.ULTRA -> throw Exception("Ultra can only run as the selected service")
        else -> throw Exception("Forward ${forward.forwardId} mode ${forward.mode.wireValue} is not supported on Android")
    }

    internal fun buildRouteDirectionRuntimePlanForTest(
        snapshot: ClientRouteRuleSnapshot,
        forwardContractsById: Map<Long, ForwardContract>,
        binaries: RuntimeBinaryPaths,
    ): RouteDirectionRuntimePlan = buildRouteDirectionRuntimePlan(snapshot, forwardContractsById, binaries)

    private fun buildRouteDirectionRuntimePlan(
        snapshot: ClientRouteRuleSnapshot,
        forwardContractsById: Map<Long, ForwardContract>,
        binaries: RuntimeBinaryPaths,
    ): RouteDirectionRuntimePlan = RouteDirectionRuntimePlanner.plan(
        snapshot = snapshot,
        forwardContractsById = forwardContractsById,
        buildRuntimeContext = { directionId, forward, listenPort ->
            buildRuntimePlan(forward.withListenPort(listenPort), binaries).copy(
                routeRuleProcessKey = RouteDirectionRuntimePlanner.processKey(directionId),
            )
        },
    )

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

    private fun revokeFromPanel(terminal: PanelControlException) {
        val acknowledgement = panelRepo.captureTerminalAcknowledgement()
        revocationCoordinator.revoke(terminal) { commandVersion ->
            acknowledgement?.let { captured ->
                controlScope.launch {
                    runCatching { panelRepo.acknowledgeTerminal(captured, commandVersion) }
                }
            }
        }
    }

    /** Refreshes an in-memory Ultra lease before it reaches its policy renewal point. */
    private suspend fun refreshUltraIfDue() {
        val active = activeUltra ?: return
        if (Instant.now().isBefore(active.policy.renewAfter.minusSeconds(30))) return

        panelRepo.pullCatalog(active.forwardId)
        val forward = panelRepo.getCachedEffectiveForward(active.forwardId)?.runtimeContract
            ?: throw IllegalStateException("ultra_policy_unavailable")
        val nextPolicy = UltraPolicy.from(forward) ?: throw IllegalStateException("ultra_policy_unavailable")
        if (nextPolicy.policyGeneration != active.policy.policyGeneration) {
            startForForward(active.forwardId, preserveControlLoop = true)
            return
        }

        val lease = panelRepo.resolveUltraLease(nextPolicy)
        val nextProtectedConnectivity = lease.directConnectivity
        if (nextProtectedConnectivity != active.protectedConnectivity) {
            lease.wipe()
            startForForward(active.forwardId, preserveControlLoop = true)
            return
        }
        val accepted = try {
            ultraLeaseSender(active.controlSocketPath, nextPolicy, lease)
        } finally {
            lease.wipe()
        }
        if (!accepted) throw IllegalStateException("ultra_lease_refresh_rejected")
        activeUltra = active.copy(policy = nextPolicy)
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
