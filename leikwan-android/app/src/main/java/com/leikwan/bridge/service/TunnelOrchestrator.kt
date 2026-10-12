package com.leikwan.bridge.service

import android.content.Context
import android.content.Intent
import android.util.Log
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
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
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NativeOutboundLease
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_RESTRICTED
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_UNRESTRICTED
import com.leikwan.bridge.runtime.isNativeRoutePolicyValue
import com.leikwan.bridge.runtime.normalizeNativeRoutePolicy
import com.leikwan.bridge.runtime.ProcessRunner
import com.leikwan.bridge.runtime.ProtectedConnectivitySet
import com.leikwan.bridge.runtime.RouteDirectionRuntimePlan
import com.leikwan.bridge.runtime.RouteDirectionRuntimePlanner
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import com.leikwan.bridge.runtime.RuntimeBinaryPaths
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.runtime.SingleForwardRunner
import com.leikwan.bridge.runtime.UltraCredentialFailureSignal
import com.leikwan.bridge.runtime.UltraFailureRenewalGate
import com.leikwan.bridge.runtime.UltraForwardRunner
import com.leikwan.bridge.runtime.UltraLease
import com.leikwan.bridge.runtime.UltraLeaseClient
import com.leikwan.bridge.runtime.UltraPolicy
import com.leikwan.bridge.runtime.UltraRuntimeStatus
import com.leikwan.bridge.session.ClientControlLoop
import com.leikwan.bridge.session.ControlPlaneState
import com.leikwan.bridge.session.DeviceLimitReachedException
import com.leikwan.bridge.session.PanelControlException
import com.leikwan.bridge.session.SessionGate
import com.leikwan.bridge.session.SessionRevocationCoordinator
import com.leikwan.bridge.singbox.NativeOutboundLocalProxy
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.Instant
import java.time.temporal.ChronoUnit
import java.net.InetAddress
import java.net.ServerSocket
import java.io.IOException
import java.util.IdentityHashMap
import java.util.concurrent.atomic.AtomicLong

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

/** Logs when a lease delivery had to wait for the runtime to start listening (cold start). */
private fun loggingUltraLeaseClient(): UltraLeaseClient = UltraLeaseClient(
    onReadyAfterWait = { waitedMs -> Log.i(TAG, "Ultra control socket ready after ${waitedMs}ms") },
)

private const val NATIVE_REFRESH_MARGIN_SECONDS = 90L
/** Renew this long before the panel's `renew_after`; the control loop ticks every 30 s. */
private const val ULTRA_RENEW_MARGIN_SECONDS = 30L

data class NodeLegsResult(
    val legs: Map<Long, NodeLeg>,
    val failedNodeIds: List<Long>,
)

private data class ActiveUltraRuntime(
    val forwardId: Long,
    val policy: UltraPolicy,
    val controlSocketPath: String,
    val protectedConnectivity: ProtectedConnectivitySet,
    /** Runtime status file; read to spot a runtime that lost its policy (e.g. a guard restart). */
    val statusPath: String = "",
    /** When the current lease batch was accepted by the runtime. */
    val leaseSentAt: Instant = Instant.EPOCH,
    /** A runtime-only restart failed; the next renewal must start the runtime again. */
    val runtimeRestartPending: Boolean = false,
)

/** An Ultra runtime started for an app group's line, next to the selected service's runtime. */
private data class ExtraUltraRuntime(
    val active: ActiveUltraRuntime,
    val runtime: RuntimeContext,
)

/** The selected Ultra runtime plus the app-group lines started next to it. */
private data class UltraAppGroupStart(
    /** The selected runtime with one node leg per routed node. */
    val runtime: RuntimeContext,
    /** Nodes with an outbound, the selected node first (it stays the default). */
    val nodes: List<ProxyNode>,
    val extras: Map<Long, ExtraUltraRuntime>,
)

private data class ActiveNativeRuntime(
    val forwardId: Long,
    val direction: String,
    val routeScope: String,
    val bypassChina: Boolean,
    val routePolicy: String,
    val expiresAt: Instant,
    val controlSocketPath: String,
    val policyGeneration: String,
    /** Panel `renew_after` of the policy the lease is bound to; drives the online cadence. */
    val renewAfter: Instant = Instant.MAX,
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
        loggingUltraLeaseClient().send(socketPath, policy, lease)
    },
    private val nativeLeaseSender: (String, NativeOutboundLease) -> Boolean = { socketPath, lease ->
        loggingUltraLeaseClient().sendNative(socketPath, lease)
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
    private val nativeLeaseBatchSender: (String, List<NativeOutboundLease>) -> Boolean = { socketPath, leases ->
        loggingUltraLeaseClient().sendNative(socketPath, leases)
    },
    private val ultraLeaseBatchSender: (String, UltraPolicy, List<UltraLease>) -> Boolean = { socketPath, policy, leases ->
        loggingUltraLeaseClient().send(socketPath, policy, leases)
    },
    /** Clock for pacing failure-driven renewals (test seam). */
    private val ultraFailureClock: () -> Instant = Instant::now,
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
    private val processRunner: ProcessRunner by lazy {
        processRunnerFactory().also { runner -> runner.runtimeLineObserver = ::onRuntimeLine }
    }

    /** Paces failure-driven Ultra renewals: one per burst, >= 20 s apart per forward. */
    private val ultraFailureRenewals = UltraFailureRenewalGate()

    /** Forward whose runtime reported a credential-suspect failure; 0 when none is pending. */
    private val ultraFailureRenewalForward = AtomicLong(0L)
    private val controlScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val controlLoop by lazy {
        ClientControlLoop(
            scope = controlScope,
            poll = {
                // A failed heartbeat must not skip a due renewal: the pull/lease endpoints may
                // still answer, and each renewal attempt is what keeps the grant alive offline.
                var refused: DeviceLimitReachedException? = null
                val heartbeatOk = try {
                    panelRepo.heartbeat()
                } catch (limit: DeviceLimitReachedException) {
                    refused = limit
                    false
                }
                try {
                    refreshUltraIfDue()
                    refreshNativeIfDue()
                } catch (error: Exception) {
                    // While the device limit refuses this device, the renewal endpoints refuse
                    // it too; report the limit (what the user must act on), not their failure.
                    if (refused == null || error is CancellationException || error is PanelControlException) {
                        throw error
                    }
                }
                refused?.let { throw it }
                if (!heartbeatOk) {
                    throw IOException("control_plane_unavailable")
                }
            },
            onTerminal = ::revokeFromPanel,
            shouldContinue = { LeikwanVpnService.isRunning },
            onStateChanged = { state ->
                controlPlaneState = state
                Log.i(TAG, "control plane state: $state")
            },
            onDeviceLimitChanged = { refused ->
                // PanelRepository already published the refusal to DeviceLimitGate for the UI.
                Log.w(TAG, "online device limit ${if (refused != null) "reached (${refused.active}/${refused.limit})" else "cleared"}")
                LeikwanVpnService.instance?.showDeviceLimitNotice(refused?.notice)
            },
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
                clearUltraExtras()
                activeNative = null
            },
            clearEnrollment = { panelRepo.logout() },
            onRevoked = SessionGate::markTerminal,
        )
    }

    @Volatile private var activeRuntime: RuntimeContext? = null
    private var activeRouteRuleRuntimeKeys: Set<Long> = emptySet()
    @Volatile private var activeUltra: ActiveUltraRuntime? = null
    /** App-group Ultra runtimes by forward id; renewed with [activeUltra]. */
    @Volatile private var activeUltraExtras: Map<Long, ExtraUltraRuntime> = emptyMap()
    @Volatile private var activeNative: ActiveNativeRuntime? = null

    @Volatile
    var controlPlaneState: ControlPlaneState = ControlPlaneState.STOPPED
        private set

    /**
     * Build the authoritative [RoutingPolicySnapshot] for one startup/rebuild cycle from the
     * persisted user rules, active rule-pack versions, and the user-settable global default.
     */
    internal suspend fun buildRoutingSnapshot(
        nowMs: Long,
        unavailableNodeIds: Set<Long> = emptySet(),
        ignoreProxyNodeTargets: Boolean = false,
        /** With [ignoreProxyNodeTargets] (Ultra): nodes app rules may still pin (app-group lines). */
        pinnableNodeIds: Set<Long> = emptySet(),
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
        // Per-app rules apply in every mode (their own setting, not the routing selector). A node
        // pin only applies where proxy nodes do; elsewhere the app just takes the tunnel.
        val pinnable = if (ignoreProxyNodeTargets) pinnableNodeIds else availableNodeIds
        val appRules = prefs.appRouting.routingRules().map { rule ->
            rule.copy(targetNodeId = rule.targetNodeId?.takeIf { it in pinnable })
        }
        if (ignoreProxyNodeTargets) {
            return effectiveRoutingEngine.compile(
                globalDefault = UserTrafficOutcome.TUNNEL,
                globalDefaultNodeId = null,
                countryRules = emptyList(),
                categoryRules = emptyList(),
                rulePackVersions = rulePackManager.activeVersions(),
                compiledAtMs = nowMs,
                appRules = appRules,
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
                appRules = appRules,
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
            appRules = appRules,
        )
        return if (warnings.isEmpty()) snapshot else snapshot.copy(warnings = snapshot.warnings + warnings)
    }

    suspend fun startForForward(forwardId: Long) = startForForward(forwardId, preserveControlLoop = false)

    /** Starts the plan-authorized native SOCKS5 adapter and points TUN at its loopback facade. */
    suspend fun startNativeForForward(
        forwardId: Long,
        routeScope: String = prefs.nativeOutboundRouteScope,
        bypassChina: Boolean = prefs.nativeOutboundBypassChina,
        routePolicy: String = prefs.nativeOutboundRoutePolicy,
    ) = startNativeForForward(forwardId, routeScope, bypassChina, routePolicy, preserveControlLoop = false)

    private suspend fun startNativeForForward(
        forwardId: Long,
        routeScope: String,
        bypassChina: Boolean,
        routePolicy: String,
        preserveControlLoop: Boolean,
    ) = withContext(Dispatchers.IO) {
        var nativeConfigPublished = false
        var nativeRuntime: RuntimeContext? = null
        var nativeControlSocketPath = ""
        var nativePolicyGeneration = ""
        try {
            val requestedForwardId = forwardId.takeIf { it > 0L } ?: prefs.selectedForwardId
            val catalog = panelRepo.pullCatalog(requestedForwardId)
            val selectedForwardId = requestedForwardId.takeIf { it > 0L }
                ?: catalog.selectedForwardId
                ?: catalog.nativeOutbound?.defaultForwardId
                ?: catalog.nativeOutbound?.forwards?.firstOrNull()?.forwardId
                ?: throw Exception("Select a service before starting native outbound")
            if (catalog.visibleForwards.none { it.forwardId == selectedForwardId }) {
                throw Exception("Selected native forward is unavailable")
            }
            val forward = panelRepo.getCachedEffectiveForward(selectedForwardId)?.runtimeContract
                ?: throw Exception("Forward $selectedForwardId is not supported on Android")
            val capability = catalog.nativeOutbound ?: panelRepo.getCachedNativeOutbound()
                ?: throw Exception("Native outbound is not allowed for this plan")
            val normalizedScope = NativeOutboundCapability.normalizeScope(routeScope)
                ?.takeIf { it in (capability.carrier(selectedForwardId)?.routeScopes ?: emptyList()) }
                ?: throw Exception("Native route scope is unavailable")
            val carrier = capability.carrier(selectedForwardId)
                ?: throw Exception("Native outbound carrier is unavailable")
            if (!isNativeRoutePolicyValue(routePolicy)) {
                throw Exception("native_route_policy_invalid")
            }
            val normalizedRoutePolicy = normalizeNativeRoutePolicy(routePolicy)
            if (normalizedRoutePolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED && !carrier.unrestrictedAllowed) {
                throw Exception("native_route_policy_not_allowed")
            }
            val effectiveBypassChina = bypassChina && normalizedScope == "global"
            val policy = UltraPolicy.from(forward)
                ?: throw Exception("Native authorization is unavailable")
            val leases = panelRepo.resolveNativeOutboundLeases(
                forward,
                capability,
                normalizedScope,
                maxCandidates = 4,
                routePolicy = normalizedRoutePolicy,
            )
            val leaseExpiresAt = leases.minOf { it.expiresAt }
            try {
                stop(keepControlLoop = preserveControlLoop)
                val binaries = binaryInstaller.prepareBinaryPaths()
                val nativeListenPort = allocateLoopbackPort()
                val planned = UltraForwardRunner.buildPlan(
                    forward = forward,
                    policy = policy,
                    binaries = binaries,
                    runtimeDir = context.filesDir,
                    nativeSocks5 = true,
                    listenPortOverride = nativeListenPort,
                    nativeRoutePolicy = normalizedRoutePolicy,
                )
                nativeControlSocketPath = planned.controlSocketFile.absolutePath
                nativePolicyGeneration = policy.policyGeneration
                nativeRuntime = planned.runtimeContext
                // Publish ownership before process start and lease/config work. Any
                // exception after this point can then use the common cleanup path;
                // this prevents a partially started native runtime from surviving
                // a failed startup.
                activeRuntime = planned.runtimeContext
                processRunner.start(selectedForwardId, planned.runtimeContext)
                try {
                    val accepted = if (leases.size == 1) {
                        nativeLeaseSender(planned.controlSocketFile.absolutePath, leases.first())
                    } else {
                        ConnectProgress.selectingRoutes(leases.size) {
                            nativeLeaseBatchSender(planned.controlSocketFile.absolutePath, leases)
                        }
                    }
                    if (!accepted) {
                        throw Exception("Native lease was rejected by the local runtime")
                    }
                    processRunner.awaitLocalPort(nativeListenPort)
                } catch (error: Exception) {
                    processRunner.stopForward(selectedForwardId)
                    throw error
                }
                val routeRuleSnapshot = routeRuleStore.current()
                val activeRulePackManifest = RulePackManifest.fromSnapshot(rulePackManager.current())
                val routingSnapshot = buildRoutingSnapshot(
                    System.currentTimeMillis(),
                    ignoreProxyNodeTargets = true,
                )
                val compiled = SingboxConfigBuilder
                    .fromPrefs(prefs, activeRulePackManifest)
                    .build(
                        proxyNodes = emptyList(),
                        runtimeContext = null,
                        routingPolicySnapshot = routingSnapshot,
                        connectionMode = ProxyConnectionMode.SERVICES,
                        routeRuleSnapshot = routeRuleSnapshot,
                        routeRuleRuntimeBindings = emptyList(),
                        runtimeOnlyProxy = false,
                        nativeLocalProxy = NativeOutboundLocalProxy(port = nativeListenPort),
                        nativeProtectedEndpoints = leases.map { it.endpointIp }.toSet(),
                        nativeRouteScope = normalizedScope,
                        nativeBypassChina = effectiveBypassChina,
                        nativeRoutePolicy = normalizedRoutePolicy,
                    )
                recordExplainability(routingSnapshot)
                LeikwanVpnService.configJson = compiled.configJson
                nativeConfigPublished = true
                Log.d(TAG, "Generated native sing-box config for forward $selectedForwardId")
            } finally {
                leases.forEach(NativeOutboundLease::wipe)
            }

            prefs.selectedForwardId = selectedForwardId
            prefs.nativeOutboundSelected = true
            prefs.nativeOutboundForwardId = selectedForwardId
            prefs.nativeOutboundRouteScope = normalizedScope
            prefs.nativeOutboundBypassChina = effectiveBypassChina
            prefs.nativeOutboundRoutePolicy = normalizedRoutePolicy
            vpnServiceStarter(context)
            when (val vpnResult = vpnStartupAwaiter()) {
                VpnStartupAwaitResult.Running -> {
                    activeUltra = null
                    activeNative = ActiveNativeRuntime(
                        forwardId = selectedForwardId,
                        direction = carrier.direction,
                        routeScope = normalizedScope,
                        bypassChina = effectiveBypassChina,
                        routePolicy = normalizedRoutePolicy,
                        expiresAt = leaseExpiresAt,
                        controlSocketPath = nativeControlSocketPath,
                        policyGeneration = nativePolicyGeneration,
                        renewAfter = policy.renewAfter,
                    )
                    controlLoop.start()
                }
                is VpnStartupAwaitResult.Failed -> {
                    activeNative = null
                    stopRuntime(nativeRuntime)
                    throw Exception(vpnResult.errorMessage)
                }
                VpnStartupAwaitResult.Timeout -> {
                    activeNative = null
                    stopRuntime(nativeRuntime)
                    throw Exception("VPN service start timed out")
                }
            }
        } catch (terminal: PanelControlException) {
            clearNativeConfigHandoff(nativeConfigPublished)
            revokeFromPanel(terminal)
            throw terminal
        } catch (error: Exception) {
            clearNativeConfigHandoff(nativeConfigPublished)
            throw error
        }
    }

    /** Clear a native credential-bearing config when startup aborts before VpnService owns it. */
    private fun clearNativeConfigHandoff(published: Boolean) {
        if (published) {
            runCatching { LeikwanVpnService.instance?.stopVpn() }
                .onFailure { Log.w(TAG, "Error clearing native VPN handoff", it) }
        }
        LeikwanVpnService.configJson = null
        activeRuntime?.let { runtime ->
            processRunner.stopForward(runtime.forwardId)
            UltraForwardRunner.cleanup(runtime)
        }
        activeRuntime = null
        activeNative = null
    }

    private fun allocateLoopbackPort(): Int = ServerSocket(0, 1, InetAddress.getByName("127.0.0.1")).use {
        it.localPort
    }

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
            // Ordinary Ultra owns the provider hop, but its fixed target may itself be a
            // protocol endpoint. Preserve the pre-native behavior: with a selected protocol
            // node, use exactly that one adapter against the local Ultra listener; with no
            // selected node, keep the raw fixed-target bridge path.
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
        val baseRuntimeContext = if (connectionMode == ProxyConnectionMode.SERVICES) run {
            val effective = requireNotNull(selectedEffective)
            val forward = effective.runtimeContract
            Log.i(TAG, "Starting service ${forward.forwardId}: ${forward.serviceName}")
            if (effective.mode == ForwardMode.ULTRA) {
                val policy = requireNotNull(ultraPolicy)
                val leases = panelRepo.resolveUltraLeases(policy, maxCandidates = 4)
                var processStarted = false
                try {
                    val planned = UltraForwardRunner.buildPlan(
                        forward = forward,
                        policy = policy,
                        binaries = binaries,
                        runtimeDir = context.filesDir,
                        autoSelect = leases.size > 1,
                    )
                    processRunner.start(forward.forwardId, planned.runtimeContext)
                    processStarted = true
                    val protectedConnectivity = leases
                        .map(UltraLease::directConnectivity)
                        .fold(ProtectedConnectivitySet.empty(), ProtectedConnectivitySet::merge)
                    val accepted = if (leases.size == 1) {
                        ultraLeaseSender(planned.controlSocketFile.absolutePath, policy, leases.first())
                    } else {
                        ConnectProgress.selectingRoutes(leases.size) {
                            ultraLeaseBatchSender(planned.controlSocketFile.absolutePath, policy, leases)
                        }
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
                        statusPath = planned.statusFile.absolutePath,
                        leaseSentAt = Instant.now(),
                    )
                    return@run runtime
                } catch (error: Exception) {
                    if (processStarted) processRunner.stopForward(forward.forwardId)
                    throw error
                } finally {
                    leases.forEach(UltraLease::wipe)
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
        // App groups on other lines get their own Ultra runtime next to the selected one.
        val ultraGroups = selectedEffective?.takeIf { it.mode == ForwardMode.ULTRA }?.let { effective ->
            val mainNode = selection.selectedNode ?: return@let null
            val mainRuntime = baseRuntimeContext ?: return@let null
            startUltraAppGroups(effective.runtimeContract, mainRuntime, mainNode, proxyNodes, binaries)
        }
        val runtimeContext = ultraGroups?.runtime ?: baseRuntimeContext

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
            pinnableNodeIds = ultraGroups?.nodes.orEmpty().mapTo(hashSetOf()) { it.id },
        )
        val compiled = SingboxConfigBuilder
            .fromPrefs(prefs, activeRulePackManifest)
            .build(
                proxyNodes = ultraGroups?.nodes ?: selection.activeNodes,
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
                activeUltraExtras = ultraGroups?.extras.orEmpty()
                controlLoop.start()
            }
            is VpnStartupAwaitResult.Failed -> {
                stopRuntime(runtimeContext)
                ultraGroups?.extras?.values?.forEach { UltraForwardRunner.cleanup(it.runtime) }
                throw Exception(vpnResult.errorMessage)
            }
            VpnStartupAwaitResult.Timeout -> {
                stopRuntime(runtimeContext)
                ultraGroups?.extras?.values?.forEach { UltraForwardRunner.cleanup(it.runtime) }
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
        // The service instance may be absent during a failed start or test
        // replacement. Clear the static handoff in that case as well.
        LeikwanVpnService.configJson = null
        activeRouteRuleRuntimeKeys.forEach { processRunner.stopForward(it) }
        activeRouteRuleRuntimeKeys = emptySet()
        processRunner.stopAll()
        activeRuntime?.let(UltraForwardRunner::cleanup)
        activeRuntime = null
        activeUltra = null
        clearUltraExtras()
        activeNative = null
    }

    /** Forgets the app-group runtimes; their processes stop with [ProcessRunner.stopAll]. */
    private fun clearUltraExtras() {
        activeUltraExtras.values.forEach { UltraForwardRunner.cleanup(it.runtime) }
        activeUltraExtras = emptyMap()
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

    /**
     * Runtime output hook. When the source host re-acquires a line it invalidates the credential
     * the runtime holds and uploads the new one within seconds; the relay then resets or aborts
     * every stream on that line. Waiting for `renew_after` kept a dead credential for minutes, so a
     * credential-suspect failure makes the next renewal due and wakes the control loop. The wake
     * is ignored during a control-plane retry backoff (the scheduled retry renews instead), and
     * [ultraFailureRenewals] turns a burst of failures into one renewal.
     */
    private fun onRuntimeLine(processKey: Long, line: String) {
        val active = activeUltra ?: return
        if (processKey != active.forwardId && processKey !in activeUltraExtras) return
        val kind = UltraCredentialFailureSignal.credentialSuspectKind(line) ?: return
        if (!ultraFailureRenewals.tryAdmit(processKey, ultraFailureClock())) return
        ultraFailureRenewalForward.set(processKey)
        val woke = controlLoop.requestPollSoon()
        Log.i(TAG, "Ultra runtime reported $kind for forward $processKey; renewing its lease (immediate=$woke)")
    }

    /** Test seam for Ultra renewal; [now] only moves the due-time gate. */
    internal suspend fun refreshUltraForTest(now: Instant = Instant.now()) = refreshUltraIfDue(now)

    /**
     * Refreshes an in-memory Ultra lease before it reaches its policy renewal point.
     *
     * Offline survival (W1): any non-terminal failure here (panel unreachable, 5xx, a lease the
     * runtime did not accept) leaves the running runtime and [activeUltra] untouched and is
     * rethrown so [ClientControlLoop] retries with jittered backoff. The runtime keeps serving on
     * its current policy until that policy really expires; past expiry the Go runtime keeps its
     * listeners, fails connections closed and reports `policy_lapsed`, and the next successful
     * renewal is injected in place. Only a terminal panel control (revocation) or a changed
     * static contract after a successful pull stops or restarts it.
     */
    private suspend fun refreshUltraIfDue(now: Instant = Instant.now()) {
        val main = activeUltra ?: return
        val extras = activeUltraExtras
        val pending = ultraFailureRenewalForward.get()
        if (pending != 0L && pending != main.forwardId && pending !in extras) {
            ultraFailureRenewalForward.compareAndSet(pending, 0L)
        }
        // Every runtime gets its turn: one line failing to renew must not starve the others.
        var failure: Exception? = null
        for (active in listOf(main) + extras.values.map { it.active }) {
            try {
                if (refreshUltraRuntimeIfDue(active, main.forwardId, now)) return
            } catch (error: Exception) {
                if (error is CancellationException || error is PanelControlException) throw error
                if (failure == null) failure = error
            }
        }
        failure?.let { throw it }
    }

    /** Renews one Ultra runtime; true when it rebuilt the whole tunnel instead. */
    private suspend fun refreshUltraRuntimeIfDue(
        active: ActiveUltraRuntime,
        mainForwardId: Long,
        now: Instant,
    ): Boolean {
        val failureDriven = ultraFailureRenewalForward.compareAndSet(active.forwardId, 0L)
        val renewalDue = !now.isBefore(active.policy.renewAfter.minusSeconds(ULTRA_RENEW_MARGIN_SECONDS))
        val lostPolicy = runtimeLostPolicy(active)
        if (!renewalDue && !failureDriven && !active.runtimeRestartPending && !lostPolicy) return false

        try {
            panelRepo.pullCatalog(mainForwardId)
            val forward = panelRepo.getCachedEffectiveForward(active.forwardId)?.runtimeContract
                ?: throw IllegalStateException("ultra_policy_unavailable")
            val nextPolicy = UltraPolicy.from(forward) ?: throw IllegalStateException("ultra_policy_unavailable")
            // Generation and ticket rotate with provider material/quota. The adapter
            // can replace them in place; only static forwarding bindings need a restart.
            if (nextPolicy.forwardId != active.policy.forwardId
                || nextPolicy.targetDigest != active.policy.targetDigest
                || nextPolicy.transport != active.policy.transport) {
                startForForward(mainForwardId, preserveControlLoop = true)
                return true
            }
            // The panel hands back the running grant until its renew_after has passed, so a
            // renewal inside the early margin only fetched the same leases again. The runtime's
            // lease lives as long as that grant; the next tick, past renew_after, rotates it.
            if (!failureDriven && !lostPolicy && !active.runtimeRestartPending
                && nextPolicy.sessionTicket == active.policy.sessionTicket
                && nextPolicy.renewAfter == active.policy.renewAfter
                && now.isBefore(active.policy.renewAfter)) {
                return false
            }

            val leases = panelRepo.resolveUltraLeases(nextPolicy, maxCandidates = 4)
            try {
                val nextProtectedConnectivity = leases
                    .map(UltraLease::directConnectivity)
                    .fold(ProtectedConnectivitySet.empty(), ProtectedConnectivitySet::merge)
                // A changed relay set never needs a new VpnService: this app's UID is excluded
                // from its own VPN, so the runtime reaches any relay directly. Tearing the VPN
                // down here dropped every Android tunnel each few renewals, and for good when a
                // step of the rebuild failed.
                val accepted = !active.runtimeRestartPending && if (leases.size == 1) {
                    ultraLeaseSender(active.controlSocketPath, nextPolicy, leases.first())
                } else {
                    ultraLeaseBatchSender(active.controlSocketPath, nextPolicy, leases)
                }
                if (accepted) {
                    storeUltra(
                        active.copy(
                            policy = nextPolicy,
                            protectedConnectivity = nextProtectedConnectivity,
                            leaseSentAt = Instant.now(),
                        ),
                    )
                    return false
                }
                if (!active.runtimeRestartPending && nextProtectedConnectivity == active.protectedConnectivity) {
                    throw IllegalStateException("ultra_lease_refresh_rejected")
                }
                // The runtime will not move one policy generation to a relay set that lacks its
                // selected path. Restart only the runtime, on the port sing-box already uses.
                restartUltraRuntime(active, forward, nextPolicy, leases, nextProtectedConnectivity)
                return false
            } finally {
                leases.forEach(UltraLease::wipe)
            }
        } catch (error: Exception) {
            if (error is CancellationException || error is PanelControlException) throw error
            // Keep a failure-driven renewal pending: the loop's retry (after its backoff) renews.
            if (failureDriven) ultraFailureRenewalForward.compareAndSet(0L, active.forwardId)
            val remaining = active.policy.expiresAt.epochSecond - now.epochSecond
            Log.w(
                TAG,
                "Ultra renewal failed; keeping the runtime on its current policy " +
                    "(${if (remaining > 0) "expires in ${remaining}s" else "lapsed"})",
                error,
            )
            throw error
        }
    }

    /**
     * Replaces the Ultra runtime process in place while the VpnService, TUN and sing-box keep
     * running; only flows through the runtime reset. On failure the next renewal retries the
     * restart, since the old process is already gone.
     */
    private fun restartUltraRuntime(
        active: ActiveUltraRuntime,
        forward: ForwardContract,
        policy: UltraPolicy,
        leases: List<UltraLease>,
        protectedConnectivity: ProtectedConnectivitySet,
    ) {
        val isMain = active.forwardId == activeUltra?.forwardId
        val listenPort = if (isMain) {
            activeRuntime?.localListenPort
        } else {
            activeUltraExtras[active.forwardId]?.runtime?.localListenPort
        } ?: forward.listenPort
        Log.i(TAG, "Restarting the Ultra runtime for forward ${active.forwardId} on a new relay set")
        // Stop first: a same-generation plan reuses the running runtime's file names.
        processRunner.stopForward(active.forwardId)
        storeUltra(active.copy(runtimeRestartPending = true))
        val planned = UltraForwardRunner.buildPlan(
            forward = forward,
            policy = policy,
            binaries = binaryInstaller.prepareBinaryPaths(),
            runtimeDir = context.filesDir,
            autoSelect = leases.size > 1,
            listenPortOverride = listenPort,
        )
        // Route-rule runtimes keep running; do not let the stale-process janitor kill them.
        processRunner.start(active.forwardId, planned.runtimeContext, cleanupStale = false)
        try {
            val accepted = if (leases.size == 1) {
                ultraLeaseSender(planned.controlSocketFile.absolutePath, policy, leases.first())
            } else {
                ultraLeaseBatchSender(planned.controlSocketFile.absolutePath, policy, leases)
            }
            if (!accepted) throw IllegalStateException("ultra_lease_refresh_rejected")
            if (policy.transport != "udp") processRunner.awaitLocalPort(listenPort)
        } catch (error: Exception) {
            processRunner.stopForward(active.forwardId)
            throw error
        }
        val restarted = active.copy(
            policy = policy,
            controlSocketPath = planned.controlSocketFile.absolutePath,
            protectedConnectivity = protectedConnectivity,
            statusPath = planned.statusFile.absolutePath,
            leaseSentAt = Instant.now(),
            runtimeRestartPending = false,
        )
        val runtime = planned.runtimeContext.copy(protectedConnectivity = protectedConnectivity)
        if (isMain) {
            // App-group node legs point at their own runtimes' ports, which did not move.
            activeRuntime = runtime.copy(nodeLegs = activeRuntime?.nodeLegs.orEmpty())
            activeUltra = restarted
        } else {
            activeUltraExtras = activeUltraExtras + (active.forwardId to ExtraUltraRuntime(restarted, runtime))
        }
    }

    /** Records [updated] as the selected runtime's or its app-group runtime's state. */
    private fun storeUltra(updated: ActiveUltraRuntime) {
        if (updated.forwardId == activeUltra?.forwardId) {
            activeUltra = updated
            return
        }
        val extra = activeUltraExtras[updated.forwardId] ?: return
        activeUltraExtras = activeUltraExtras + (updated.forwardId to extra.copy(active = updated))
    }

    /**
     * Starts one Ultra runtime per other line that an app group rides, and gives every routed node
     * a leg on its line's local port. A line that cannot start is skipped: its apps fall back to
     * the selected node. Returns null when no group needs anything beyond the selected runtime.
     */
    private suspend fun startUltraAppGroups(
        mainForward: ForwardContract,
        mainRuntime: RuntimeContext,
        mainNode: ProxyNode,
        proxyNodes: List<ProxyNode>,
        binaries: RuntimeBinaryPaths,
    ): UltraAppGroupStart? {
        val groups = prefs.appRouting.groups.filter { it.packages.isNotEmpty() }
        if (groups.isEmpty()) return null
        val legs = linkedMapOf(mainNode.id to ultraNodeLeg(mainNode.id, mainForward.forwardId, mainRuntime))
        val nodes = mutableListOf(mainNode)
        val extras = linkedMapOf<Long, ExtraUltraRuntime>()
        for (group in groups) {
            // One outbound per node: a node already routed (the selected one included) keeps its line.
            if (group.nodeId in legs) continue
            val node = proxyNodes.firstOrNull {
                it.id == group.nodeId && it.enabled && !it.type.equals("wireguard", ignoreCase = true)
            } ?: continue
            val runtime = if (group.forwardId == mainForward.forwardId) {
                mainRuntime
            } else {
                extras[group.forwardId]?.runtime
                    ?: startExtraUltraRuntime(group.forwardId, binaries)?.also { extras[group.forwardId] = it }?.runtime
                    ?: continue
            }
            legs[node.id] = ultraNodeLeg(node.id, group.forwardId, runtime)
            nodes += node
        }
        if (nodes.size == 1) return null
        val protectedConnectivity = extras.values
            .map { it.runtime.protectedConnectivity }
            .fold(mainRuntime.protectedConnectivity, ProtectedConnectivitySet::merge)
        return UltraAppGroupStart(
            runtime = mainRuntime.copy(nodeLegs = legs, protectedConnectivity = protectedConnectivity),
            nodes = nodes,
            extras = extras,
        )
    }

    private fun ultraNodeLeg(nodeId: Long, forwardId: Long, runtime: RuntimeContext): NodeLeg = NodeLeg(
        nodeId = nodeId,
        forwardId = forwardId,
        processKey = forwardId,
        mode = ForwardMode.ULTRA,
        localListenPort = runtime.localListenPort,
        localLegPorts = runtime.localLegPorts,
        processPlans = emptyList(),
        bypassHosts = runtime.bypassHosts,
        protectedConnectivity = runtime.protectedConnectivity,
    )

    /** Starts and leases an Ultra runtime for an app-group line; null when it cannot run. */
    private suspend fun startExtraUltraRuntime(forwardId: Long, binaries: RuntimeBinaryPaths): ExtraUltraRuntime? {
        val forward = panelRepo.getCachedEffectiveForward(forwardId)
            ?.takeIf { it.mode == ForwardMode.ULTRA }
            ?.runtimeContract
        val policy = forward?.let(UltraPolicy::from)
        if (forward == null || policy == null) {
            Log.w(TAG, "App-group line $forwardId is not an available Ultra service; its apps use the selected node")
            return null
        }
        var processStarted = false
        return try {
            val leases = panelRepo.resolveUltraLeases(policy, maxCandidates = 4)
            try {
                val planned = UltraForwardRunner.buildPlan(
                    forward = forward,
                    policy = policy,
                    binaries = binaries,
                    runtimeDir = context.filesDir,
                    autoSelect = leases.size > 1,
                )
                // The selected runtime is already up; do not let the stale-process janitor kill it.
                processRunner.start(forwardId, planned.runtimeContext, cleanupStale = false)
                processStarted = true
                val accepted = if (leases.size == 1) {
                    ultraLeaseSender(planned.controlSocketFile.absolutePath, policy, leases.first())
                } else {
                    ultraLeaseBatchSender(planned.controlSocketFile.absolutePath, policy, leases)
                }
                if (!accepted) throw IllegalStateException("ultra_lease_rejected")
                if (policy.transport != "udp") processRunner.awaitLocalPort(planned.runtimeContext.localListenPort)
                val protectedConnectivity = leases
                    .map(UltraLease::directConnectivity)
                    .fold(ProtectedConnectivitySet.empty(), ProtectedConnectivitySet::merge)
                ExtraUltraRuntime(
                    active = ActiveUltraRuntime(
                        forwardId = forwardId,
                        policy = policy,
                        controlSocketPath = planned.controlSocketFile.absolutePath,
                        protectedConnectivity = protectedConnectivity,
                        statusPath = planned.statusFile.absolutePath,
                        leaseSentAt = Instant.now(),
                    ),
                    runtime = planned.runtimeContext.copy(protectedConnectivity = protectedConnectivity),
                )
            } finally {
                leases.forEach(UltraLease::wipe)
            }
        } catch (error: Exception) {
            if (error is CancellationException || error is PanelControlException) throw error
            if (processStarted) processRunner.stopForward(forwardId)
            Log.w(TAG, "App-group line $forwardId failed to start; its apps use the selected node", error)
            null
        }
    }

    /**
     * True when the runtime reports it holds no policy (`awaiting` after a guard restart, or
     * `lapsed`) in a status written after our last accepted lease. That is idle on purpose, not
     * broken: the fix is a fresh lease, never a process restart.
     */
    private fun runtimeLostPolicy(active: ActiveUltraRuntime): Boolean {
        val status = UltraRuntimeStatus.read(active.statusPath) ?: return false
        if (!status.needsLease) return false
        // The status writer is throttled, so the file can still show the pre-injection
        // `awaiting` for a few seconds. Only a status written after the injection counts.
        val updatedAt = status.updatedAt ?: return false
        return updatedAt.isAfter(active.leaseSentAt.truncatedTo(ChronoUnit.SECONDS))
    }

    /** Test seam for exercising renewal without waiting for the short lease TTL. */
    internal suspend fun refreshNativeForTest(now: Instant = Instant.now()) = refreshNativeIfDue(now)

    /** Renews native credentials in place; the adapter accepts safe generation rotation. */
    private suspend fun refreshNativeIfDue(now: Instant = Instant.now()) {
        val active = activeNative ?: return
        // With long offline grants the lease can outlive renew_after by many minutes. Renew on
        // the panel's cadence (renew_after) so revocation/suspension still land promptly, and
        // fall back to the expiry margin for panels that send a short grant.
        val renewAt = minOf(
            active.expiresAt.minusSeconds(NATIVE_REFRESH_MARGIN_SECONDS),
            active.renewAfter.minusSeconds(ULTRA_RENEW_MARGIN_SECONDS),
        )
        if (now.isBefore(renewAt)) return
        try {
            panelRepo.pullCatalog(active.forwardId)
            val forward = panelRepo.getCachedEffectiveForward(active.forwardId)?.runtimeContract
                ?: throw IllegalStateException("native_policy_unavailable")
            val capability = panelRepo.getCachedNativeOutbound()
                ?: throw IllegalStateException("native_outbound_not_allowed")
            val carrier = capability.carrier(active.forwardId)
                ?: throw IllegalStateException("native_outbound_not_allowed")
            if (carrier.direction != active.direction) {
                startNativeForForward(
                    forwardId = active.forwardId,
                    routeScope = active.routeScope,
                    bypassChina = active.bypassChina,
                    routePolicy = active.routePolicy,
                    preserveControlLoop = true,
                )
                return
            }
            val normalizedScope = NativeOutboundCapability.normalizeScope(active.routeScope)
                ?.takeIf { it in carrier.routeScopes }
                ?: throw IllegalStateException("native_route_scope_invalid")
            val normalizedRoutePolicy = normalizeNativeRoutePolicy(active.routePolicy)
            if (normalizedRoutePolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED && !carrier.unrestrictedAllowed) {
                throw IllegalStateException("native_route_policy_not_allowed")
            }
            val policy = UltraPolicy.from(forward)
                ?: throw IllegalStateException("native_policy_unavailable")

            if (policy.policyGeneration != active.policyGeneration) {
                // The Go adapter deliberately keeps one selected endpoint per policy
                // generation. Rotate the static runtime only after the new policy has
                // been pulled and validated; same-generation renewals stay in place.
                startNativeForForward(
                    forwardId = active.forwardId,
                    routeScope = normalizedScope,
                    bypassChina = active.bypassChina,
                    routePolicy = normalizedRoutePolicy,
                    preserveControlLoop = true,
                )
                return
            }

            val leases = panelRepo.resolveNativeOutboundLeases(
                forward = forward,
                capability = capability,
                routeScope = normalizedScope,
                maxCandidates = 4,
                routePolicy = normalizedRoutePolicy,
            )
            try {
                val accepted = nativeLeaseBatchSender(active.controlSocketPath, leases)
                if (!accepted) {
                    if (!Instant.now().isBefore(active.expiresAt)) {
                        stop()
                        throw IllegalStateException("native_lease_refresh_expired")
                    }
                    Log.w(TAG, "Native lease refresh was rejected; retaining the current runtime")
                    return
                }
                val refreshedExpiry = leases.minOf { it.expiresAt }
                if (!Instant.now().isBefore(refreshedExpiry)) {
                    stop()
                    throw IllegalStateException("native_lease_refresh_expired")
                }
                activeNative = active.copy(
                    routeScope = normalizedScope,
                    routePolicy = normalizedRoutePolicy,
                    expiresAt = refreshedExpiry,
                    policyGeneration = policy.policyGeneration,
                    renewAfter = policy.renewAfter,
                )
            } finally {
                leases.forEach(NativeOutboundLease::wipe)
            }
        } catch (error: Exception) {
            if (error is CancellationException) throw error
            if (error is PanelControlException || isNativeAuthorizationFailure(error)) {
                Log.w(TAG, "Native outbound authorization changed; stopping VPN", error)
                stop()
                throw error
            }
            if (!Instant.now().isBefore(active.expiresAt)) {
                Log.w(TAG, "Native outbound lease expired before refresh succeeded; stopping VPN", error)
                stop()
                throw error
            }
            // Keep the existing data plane alive across a transient panel or local
            // control-socket failure. The next control-loop tick retries before expiry.
            Log.w(TAG, "Native outbound lease refresh failed; retaining the current runtime", error)
        }
    }

    private fun isNativeAuthorizationFailure(error: Throwable): Boolean {
        val code = error.message?.trim()?.substringBefore(':') ?: return false
        return code in setOf(
            "native_outbound_not_allowed",
            "native_route_scope_invalid",
            "native_route_policy_invalid",
            "native_route_policy_not_allowed",
            "native_policy_invalid",
            "native_transport_invalid",
            "native_policy_unavailable",
            "forward_inactive",
            "native_forward_unavailable",
        )
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
