package com.leikwan.bridge.service

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.api.ClientRouteRuleDto
import com.leikwan.bridge.api.ClientRuleWriteRequest
import com.leikwan.bridge.api.ClientRuleWriteResponse
import com.leikwan.bridge.api.ClientSelectionRequest
import com.leikwan.bridge.api.ClientSelectionResponse
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.DirectionRuntimeRefDto
import com.leikwan.bridge.api.HeartbeatRequest
import com.leikwan.bridge.api.HeartbeatResponse
import com.leikwan.bridge.api.LoginRequest
import com.leikwan.bridge.api.LoginResponse
import com.leikwan.bridge.api.PanelApi
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.api.PullConfigsResponse
import com.leikwan.bridge.api.RouteDirectionDto
import com.leikwan.bridge.api.RouteRuleCapabilitiesDto
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.runtime.BondingContract
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardEndpoint
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.NodeLeg
import com.leikwan.bridge.runtime.ProcessRunner
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import com.leikwan.bridge.runtime.RuntimeBinaryPaths
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import com.leikwan.bridge.singbox.SingboxSettings
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import retrofit2.Response

/**
 * Exercises the [TunnelOrchestrator] DI seam: with an in-memory database, a real
 * [UserRoutingRuleStore] seeded with a CN->DIRECT rule, and a [Prefs] whose global default is TUNNEL,
 * [TunnelOrchestrator.buildRoutingSnapshot] produces the authoritative snapshot that
 * `SingboxConfigBuilder.build(...)` consumes. (startForForward is not driven here — it needs network.)
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class TunnelOrchestratorTest {

    private lateinit var db: AppDatabase

    @Before
    fun resetVpnState() {
        LeikwanVpnService.startupState = VpnStartupState.RUNNING
        LeikwanVpnService.lastStartError = null
        LeikwanVpnService.configJson = ""
    }

    @After
    fun tearDown() {
        if (::db.isInitialized) db.close()
    }

    @Test
    fun `buildRoutingSnapshot reflects seeded user rules and global default`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()

        val sharedPrefs = context.getSharedPreferences("orchestrator-test-prefs", Context.MODE_PRIVATE)
        sharedPrefs.edit().clear().apply()
        val prefs = Prefs(sharedPrefs)
        prefs.routingDefaultOutcome = UserTrafficOutcome.TUNNEL
        prefs.routingSelectorEnabled = true

        val userRuleStore = UserRoutingRuleStore(db)
        userRuleStore.upsert(
            RoutingRule(
                id = "cn-direct",
                kind = RoutingRuleKind.COUNTRY,
                matchValue = "CN",
                outcome = UserTrafficOutcome.DIRECT,
                enabled = true,
            )
        )

        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = userRuleStore,
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
        )

        val snapshot = orchestrator.buildRoutingSnapshot(1L)

        assertEquals(UserTrafficOutcome.TUNNEL, snapshot.globalDefaultOutcome)
        assertEquals(1L, snapshot.compileTimestampMs)
        assertTrue(
            "expected CN->DIRECT country rule in snapshot, got ${snapshot.userCountryRules}",
            snapshot.userCountryRules.any {
                it.matchValue == "CN" && it.outcome == UserTrafficOutcome.DIRECT
            },
        )
    }

    @Test
    fun `startNodeLegs starts one isolated runtime per target node`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-leg-test-prefs", Context.MODE_PRIVATE))
        val processRunner = RecordingProcessRunner()
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            processRunnerFactory = { processRunner },
        )

        val result = orchestrator.startNodeLegs(
            nodeForwards = listOf(1L to sampleBondForward(), 2L to sampleBondForward()),
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
            ),
        )

        val legs = result.legs
        assertEquals(setOf(1L, 2L), legs.keys)
        assertEquals(11025, legs.getValue(1L).localListenPort)
        assertEquals(11026, legs.getValue(2L).localListenPort)
        assertEquals(
            listOf(
                legs.getValue(1L).processKey,
                legs.getValue(2L).processKey,
            ),
            processRunner.started.map { it.forwardId },
        )
        assertEquals(
            listOf(true, false),
            processRunner.started.map { it.cleanupStale },
        )
        assertTrue(legs.getValue(1L).localLegPorts.single() != legs.getValue(2L).localLegPorts.single())
    }

    @Test
    fun `services mode legs reach distinct upstream targets per node`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-distinct-leg-test-prefs", Context.MODE_PRIVATE))
        val processRunner = RecordingProcessRunner()
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            processRunnerFactory = { processRunner },
        )

        val result = orchestrator.startNodeLegs(
            nodeForwards = listOf(
                1L to sampleBondForward(forwardId = 26L, remoteTarget = "server-a.example:9000"),
                2L to sampleBondForward(forwardId = 27L, listenPort = 11124, remoteTarget = "server-b.example:9000"),
            ),
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
            ),
        )

        val node1Command = result.legs.getValue(1L).processPlans.flatMap { it.command }.joinToString(" ")
        val node2Command = result.legs.getValue(2L).processPlans.flatMap { it.command }.joinToString(" ")
        assertTrue(node1Command.contains("server-a.example:9000"))
        assertFalse(node1Command.contains("server-b.example:9000"))
        assertTrue(node2Command.contains("server-b.example:9000"))
        assertFalse(node2Command.contains("server-a.example:9000"))
    }

    @Test
    fun `a leg that fails to start is dropped and the tunnel continues`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-failed-leg-test-prefs", Context.MODE_PRIVATE))
        val processRunner = RecordingProcessRunner(
            failForProcessKeys = setOf(NodeLeg.processKey(forwardId = 26L, nodeId = 2L)),
        )
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            processRunnerFactory = { processRunner },
        )

        val result = orchestrator.startNodeLegs(
            nodeForwards = listOf(1L to sampleBondForward(), 2L to sampleBondForward()),
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
            ),
        )

        assertEquals(setOf(1L), result.legs.keys)
        assertEquals(listOf(2L), result.failedNodeIds)
        assertEquals(listOf(NodeLeg.processKey(26L, 1L)), processRunner.started.map { it.forwardId })
        assertEquals(listOf(true), processRunner.started.map { it.cleanupStale })
    }

    @Test
    fun `node leg ports are allocated by index to avoid modulo collisions`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-port-collision-test-prefs", Context.MODE_PRIVATE))
        val processRunner = RecordingProcessRunner()
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            processRunnerFactory = { processRunner },
        )

        val result = orchestrator.startNodeLegs(
            nodeForwards = listOf(1L to sampleBondForward(), 1001L to sampleBondForward()),
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
            ),
        )

        assertEquals(11025, result.legs.getValue(1L).localListenPort)
        assertEquals(11026, result.legs.getValue(1001L).localListenPort)
        assertTrue(
            result.legs.getValue(1L).localLegPorts.single() !=
                result.legs.getValue(1001L).localLegPorts.single(),
        )
    }

    @Test
    fun `buildRoutingSnapshot warns and falls back when a target node is unavailable`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val sharedPrefs = context.getSharedPreferences("orchestrator-warning-test-prefs", Context.MODE_PRIVATE)
        sharedPrefs.edit().clear().apply()
        val prefs = Prefs(sharedPrefs)
        prefs.routingDefaultOutcome = UserTrafficOutcome.TUNNEL
        prefs.routingSelectorEnabled = true

        db.proxyNodeDao().insert(ssNode("default"))
        val failedNodeId = db.proxyNodeDao().insert(ssNode("failed"))

        val userRuleStore = UserRoutingRuleStore(db)
        userRuleStore.upsert(
            RoutingRule(
                id = "category-video",
                kind = RoutingRuleKind.WEBSITE_CATEGORY,
                matchValue = "video",
                outcome = UserTrafficOutcome.TUNNEL,
                enabled = true,
                targetNodeId = failedNodeId,
            )
        )

        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = userRuleStore,
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
        )

        val snapshot = orchestrator.buildRoutingSnapshot(7L, unavailableNodeIds = setOf(failedNodeId))

        assertEquals(null, snapshot.userCategoryRules.single().targetNodeId)
        assertTrue(
            snapshot.warnings.any {
                it.contains("category-video") && it.contains(failedNodeId.toString())
            },
        )
    }

    @Test
    fun `buildRoutingSnapshot ignores stale user routing state for dedicated leigod startup`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val sharedPrefs = context.getSharedPreferences("orchestrator-dedicated-leigod-test-prefs", Context.MODE_PRIVATE)
        sharedPrefs.edit().clear().apply()
        val prefs = Prefs(sharedPrefs)
        val staleNodeId = db.proxyNodeDao().insert(ssNode("stale"))
        prefs.selectedProxyId = staleNodeId
        prefs.routingDefaultOutcome = UserTrafficOutcome.DIRECT
        prefs.routingDefaultNodeId = staleNodeId
        prefs.routingSelectorEnabled = true

        val userRuleStore = UserRoutingRuleStore(db)
        userRuleStore.upsert(
            RoutingRule(
                id = "country-cn",
                kind = RoutingRuleKind.COUNTRY,
                matchValue = "CN",
                outcome = UserTrafficOutcome.DIRECT,
                enabled = true,
            )
        )
        userRuleStore.upsert(
            RoutingRule(
                id = "category-ai",
                kind = RoutingRuleKind.WEBSITE_CATEGORY,
                matchValue = "ai",
                outcome = UserTrafficOutcome.TUNNEL,
                enabled = true,
                targetNodeId = staleNodeId,
            )
        )

        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = userRuleStore,
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
        )

        val snapshot = orchestrator.buildRoutingSnapshot(
            nowMs = 11L,
            ignoreProxyNodeTargets = true,
        )
        assertEquals(UserTrafficOutcome.TUNNEL, snapshot.globalDefaultOutcome)
        assertEquals(null, snapshot.globalDefaultNodeId)
        assertEquals(emptyList<RoutingRule>(), snapshot.userCountryRules)
        assertEquals(emptyList<RoutingRule>(), snapshot.userCategoryRules)
        assertEquals(emptyList<String>(), snapshot.warnings)

        val runtimeContext = RuntimeContext(
            forwardId = 65L,
            mode = ForwardMode.SINGLE,
            localListenPort = 11065,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = linkedSetOf("162.245.221.78"),
        )
        val config = JsonParser.parseString(
            SingboxConfigBuilder(
                settings = SingboxSettings(
                    panelUrl = prefs.panelUrl,
                    dnsServer = prefs.dnsServer,
                    dnsFallback = prefs.dnsFallback,
                    dnsStrategy = prefs.dnsStrategy,
                    bypassLan = prefs.bypassLan,
                    bypassChina = prefs.bypassChina,
                    customBypassRules = prefs.customBypassRules,
                )
            ).build(
                proxyNodes = emptyList(),
                runtimeContext = runtimeContext,
                routingPolicySnapshot = snapshot,
                connectionMode = ProxyConnectionMode.SERVICES,
                runtimeOnlyProxy = true,
            ).configJson
        ).asJsonObject
        val proxy = config.getAsJsonArray("outbounds")
            .map { it.asJsonObject }
            .first { it.get("tag").asString == "proxy" }
        val route = config.getAsJsonObject("route")

        assertEquals("127.0.0.1", proxy.get("override_address").asString)
        assertEquals(11065, proxy.get("override_port").asInt)
        assertEquals("proxy", route.get("final").asString)
        assertFalse(route.has("rule_set"))
        assertFalse(
            route.getAsJsonArray("rules").any {
                it.asJsonObject.has("rule_set")
            }
        )
    }

    @Test
    fun `route rule runtime plan is passed into sing-box after helper runtimes start`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-route-rule-test-prefs", Context.MODE_PRIVATE))
        prefs.token = "agent-token"
        val processRunner = RecordingProcessRunner()
        val binaries = RuntimeBinaryPaths("/runtime/wstunnel", "/runtime/leikwan-ingress", "/runtime/leikwan-bond")
        val binaryInstaller = RecordingBinaryInstaller(context, binaries)
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val routeRuleStore = ClientRouteRuleStore(
            context.getSharedPreferences("orchestrator-route-rule-store", Context.MODE_PRIVATE).also { it.edit().clear().commit() },
            clock = { 1L },
        )
        routeRuleStore.replaceFromPull(
            routeDirections = listOf(RouteDirectionDto("dir_sh_jp_1", "沪日1", "available", 10)),
            entitlements = emptyList(),
            rules = listOf(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L)),
            bindings = listOf(
                DirectionRuntimeBindingDto(
                    directionId = "dir_sh_jp_1",
                    bindingState = "ready",
                    runtimeRef = DirectionRuntimeRefDto("forward_ref", 77L, false, "tcp_udp"),
                    localTcpPort = 12001,
                    localUdpPort = 12001,
                )
            ),
            capabilities = RouteRuleCapabilitiesDto(true, true),
        )
        val baseForward = sampleBondForward(
            forwardId = 65L,
            listenPort = 11065,
            remoteTarget = "base.example:9000",
            routeRuleHelperOnly = false,
        ).copy(
            backendMode = "leigod_mode",
            targetContract = "dedicated_forward_node",
            sidecarRequired = false,
            aggregationEnabled = false,
            mode = ForwardMode.SINGLE,
            transport = "tcp_udp",
        )
        val helperForward = sampleBondForward(
            forwardId = 77L,
            listenPort = 11077,
            remoteTarget = "helper.example:9000",
            routeRuleHelperOnly = true,
        ).copy(transport = "tcp_udp")
        val panelApi = FakePanelApi(
            forwards = listOf(baseForward.toPanelJson(), helperForward.toPanelJson()),
            routeDirections = listOf(RouteDirectionDto("dir_sh_jp_1", "沪日1", "available", 10)),
            clientRules = listOf(ClientRouteRuleDto("rule_1", "svc_scope_7", "93.184.216.34", 443, "tcp_udp", "dir_sh_jp_1", true, "active", 1L)),
            bindings = listOf(
                DirectionRuntimeBindingDto(
                    directionId = "dir_sh_jp_1",
                    bindingState = "ready",
                    runtimeRef = DirectionRuntimeRefDto("forward_ref", 77L, false, "tcp_udp"),
                    localTcpPort = 12001,
                    localUdpPort = 12001,
                )
            ),
            capabilities = RouteRuleCapabilitiesDto(true, true),
        )
        val panelRepo = PanelRepository(context, prefsOverride = prefs, apiOverride = panelApi, routeRuleStore = routeRuleStore)
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = { binaryInstaller },
            processRunnerFactory = { processRunner },
            routeRuleStore = routeRuleStore,
        )

        orchestrator.startForForward(65L)

        val routeProcessKey = com.leikwan.bridge.runtime.RouteDirectionRuntimePlanner.processKey("dir_sh_jp_1")
        assertEquals(1, binaryInstaller.prepareCalls)
        assertEquals(listOf(65L, routeProcessKey), processRunner.started.map { it.forwardId })
        assertEquals(listOf(true, false), processRunner.started.map { it.cleanupStale })
        val helperRuntime = processRunner.started.single { it.forwardId == routeProcessKey }.runtimeContext
        assertEquals(12001, helperRuntime.localListenPort)
        assertEquals(routeProcessKey, helperRuntime.routeRuleProcessKey)

        val config = JsonParser.parseString(LeikwanVpnService.configJson).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }
        assertTrue(outbounds.any { it.get("tag").asString == "route-direction-dir_sh_jp_1-tcp" })
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }
        assertTrue(
            rules.any { rule ->
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "8.163.90.132/32" } == true &&
                    rule.get("outbound")?.asString == "direct"
            }
        )
        assertTrue(
            rules.any { rule ->
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "93.184.216.34/32" } == true &&
                    rule.getAsJsonArray("port")?.any { it.asInt == 443 } == true &&
                    rule.get("outbound")?.asString == "route-direction-dir_sh_jp_1-tcp"
            }
        )

        orchestrator.stop()

        assertEquals(listOf(routeProcessKey), processRunner.stopped)
        assertTrue(processRunner.stopAllCalled)
    }

    @Test
    fun `buildRoutingSnapshot gates user rules behind selector switch`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val sharedPrefs = context.getSharedPreferences("orchestrator-selector-gate-test-prefs", Context.MODE_PRIVATE)
        sharedPrefs.edit().clear().apply()
        val prefs = Prefs(sharedPrefs)
        val selectedNodeId = db.proxyNodeDao().insert(ssNode("selected"))
        prefs.selectedProxyId = selectedNodeId
        prefs.routingDefaultOutcome = UserTrafficOutcome.DIRECT

        val userRuleStore = UserRoutingRuleStore(db)
        userRuleStore.upsert(
            RoutingRule(
                id = "category-video",
                kind = RoutingRuleKind.WEBSITE_CATEGORY,
                matchValue = "video",
                outcome = UserTrafficOutcome.TUNNEL,
                enabled = true,
            )
        )

        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = userRuleStore,
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
        )

        prefs.routingSelectorEnabled = false
        val disabledSnapshot = orchestrator.buildRoutingSnapshot(8L)
        assertEquals(emptyList<RoutingRule>(), disabledSnapshot.userCategoryRules)
        assertEquals(UserTrafficOutcome.TUNNEL, disabledSnapshot.globalDefaultOutcome)
        assertEquals(selectedNodeId, disabledSnapshot.globalDefaultNodeId)

        prefs.routingSelectorEnabled = true
        val enabledSnapshot = orchestrator.buildRoutingSnapshot(9L)
        assertEquals(listOf("category-video"), enabledSnapshot.userCategoryRules.map { it.id })
        assertEquals(UserTrafficOutcome.DIRECT, enabledSnapshot.globalDefaultOutcome)
    }

    private class RecordingProcessRunner(
        private val failForProcessKeys: Set<Long> = emptySet(),
    ) : ProcessRunner() {
        data class Started(
            val forwardId: Long,
            val runtimeContext: RuntimeContext,
            val cleanupStale: Boolean,
        )

        val started = mutableListOf<Started>()
        val stopped = mutableListOf<Long>()
        var stopAllCalled = false

        override fun start(forwardId: Long, runtimeContext: RuntimeContext, cleanupStale: Boolean) {
            if (forwardId in failForProcessKeys) {
                throw IllegalStateException("planned failure for process $forwardId")
            }
            started += Started(forwardId, runtimeContext, cleanupStale)
        }

        override fun stopForward(forwardId: Long) {
            stopped += forwardId
        }

        override fun stopAll() {
            stopAllCalled = true
        }
    }

    private class RecordingBinaryInstaller(
        context: Context,
        private val binaries: RuntimeBinaryPaths,
    ) : RuntimeBinaryInstaller(context) {
        var prepareCalls = 0

        override fun prepareBinaryPaths(): RuntimeBinaryPaths {
            prepareCalls += 1
            return binaries
        }
    }

    private class FakePanelApi(
        private val forwards: List<JsonElement>,
        private val routeDirections: List<RouteDirectionDto> = emptyList(),
        private val clientRules: List<ClientRouteRuleDto> = emptyList(),
        private val bindings: List<DirectionRuntimeBindingDto> = emptyList(),
        private val capabilities: RouteRuleCapabilitiesDto? = null,
    ) : PanelApi {
        override suspend fun login(request: LoginRequest): Response<LoginResponse> = Response.success(LoginResponse(ok = true))
        override suspend fun selectClient(request: ClientSelectionRequest): Response<ClientSelectionResponse> = throw UnsupportedOperationException()
        override suspend fun heartbeat(auth: String, request: HeartbeatRequest): HeartbeatResponse = HeartbeatResponse(ok = true)
        override suspend fun pullConfigs(auth: String): PullConfigsResponse = PullConfigsResponse(
            ok = true,
            client = JsonObject(),
            forwards = forwards,
            routeDirections = routeDirections,
            clientRules = clientRules,
            directionRuntimeBindings = bindings,
            routeRuleCapabilities = capabilities,
        )
        override suspend fun createClientRule(auth: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> = throw UnsupportedOperationException()
        override suspend fun updateClientRule(auth: String, ruleId: String, request: ClientRuleWriteRequest): Response<ClientRuleWriteResponse> = throw UnsupportedOperationException()
        override suspend fun deleteClientRule(auth: String, ruleId: String): Response<ClientRuleWriteResponse> = throw UnsupportedOperationException()
        override suspend fun getForwardConfig(auth: String, forwardId: Long): JsonElement = JsonObject()
    }

    private fun ssNode(name: String): ProxyNode = ProxyNode(
        name = name,
        type = "ss",
        originalServer = "$name.example.com",
        originalPort = 443,
        configJson = """{"method":"aes-128-gcm","password":"secret"}""",
        enabled = true,
    )

    private fun sampleBondForward(
        forwardId: Long = 26L,
        listenPort: Int = 11024,
        remoteTarget: String = "138.252.162.120:9000",
        routeRuleHelperOnly: Boolean = false,
    ): ForwardContract = ForwardContract(
        forwardId = forwardId,
        serviceName = "bond-26",
        target = "193.227.109.82:5201",
        listenPort = listenPort,
        mode = ForwardMode.BOND,
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "manual",
        sni = "relay.example.com",
        pathPrefix = "/route-key",
        speedLimitMbps = 500,
        endpoints = listOf(
            ForwardEndpoint(
                endpointId = 184,
                node = "gz-1",
                host = "8.163.90.132",
                port = 7001,
                cdnHost = null,
                priority = 1,
                dynamicPriority = null,
                weight = 1,
                healthStatus = "healthy",
                supportsQuicLeg = false,
                ingressProtocol = null,
                ingressAuthToken = null,
            ),
        ),
        routeRuleHelperOnly = routeRuleHelperOnly,
        bonding = BondingContract(
            ingressMode = "wss_path_mux",
            routeKey = "/route-key",
            remoteTarget = remoteTarget,
            sessionToken = "tcp-token",
            udpSessionToken = "udp-token",
            chunkSize = null,
            maxWindowBytes = null,
            retransmitMs = null,
            connectTimeoutMs = null,
            joinTimeoutMs = null,
            adaptiveJoinTimeout = false,
            joinTimeoutFloorMs = null,
            joinTimeoutCeilingMs = null,
            joinTimeoutPerLegMs = null,
            interactiveChunkBytes = null,
            latencyGuardBytes = null,
            interactiveRttSlackMs = null,
            warmupTriggerBytes = null,
            warmupSliceBytes = null,
            warmupLegDelayMs = null,
            bulkPromoteBytes = null,
            legCooldownMs = null,
            quicEnabled = false,
            quicViaSlave = false,
            activeLegLimit = 0,
            quicActiveLegLimit = 0,
            wssStandbyEnabled = true,
            disableLocalRateLimit = true,
            quicServerName = null,
            quicInsecure = true,
            quicPriority = 0,
            wsPriority = 10,
        ),
        mptcp = null,
    )

    private fun ForwardContract.toPanelJson(): JsonElement = JsonParser.parseString(
        """
        {
          "forward_id": $forwardId,
          "service_name": "$serviceName",
          "target": "$target",
          "listen_port": $listenPort,
          "mode": "${mode.wireValue}",
          "backend_mode": "$backendMode",
          "contract_version": $contractVersion,
          "client_mode": "$clientMode",
          "runtime_preference": "$runtimePreference",
          "bond_ingress_mode": "$bondIngressMode",
          "active_family": "$activeFamily",
          "aggregation_enabled": $aggregationEnabled,
          "target_contract": "$targetContract",
          "sidecar_required": $sidecarRequired,
          "transport": "$transport",
          "service_status": "$serviceStatus",
          "endpoint_mode": "$endpointMode",
          "sni": "$sni",
          "path_prefix": "$pathPrefix",
          "speed_limit_mbps": $speedLimitMbps,
          "route_rule_helper_only": $routeRuleHelperOnly,
          "endpoints": [
            {"endpoint_id":184,"node":"gz-1","host":"8.163.90.132","port":7001,"priority":1,"weight":1,"health_status":"healthy","supports_quic_leg":false}
          ],
          "bonding": {
            "ingress_mode": "wss_path_mux",
            "route_key": "/route-key",
            "remote_target": "${bonding?.remoteTarget}",
            "session_token": "tcp-token",
            "udp_session_token": "udp-token",
            "wss_standby_enabled": true,
            "disable_local_rate_limit": true,
            "quic_enabled": false,
            "quic_via_slave": false,
            "active_leg_limit": 0,
            "quic_active_leg_limit": 0,
            "quic_insecure": true,
            "quic_priority": 0,
            "ws_priority": 10
          }
        }
        """.trimIndent()
    )
}
