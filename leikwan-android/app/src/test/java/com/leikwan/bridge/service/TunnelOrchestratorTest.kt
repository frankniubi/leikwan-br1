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
import com.leikwan.bridge.api.DirectionRuntimeBindingDto
import com.leikwan.bridge.api.DirectionRuntimeRefDto
import com.leikwan.bridge.api.HeartbeatRequest
import com.leikwan.bridge.api.HeartbeatResponse
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
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.session.DeviceAckRequest
import com.leikwan.bridge.session.DeviceAckResponse
import com.leikwan.bridge.session.DeviceChallengeRequest
import com.leikwan.bridge.session.DeviceChallengeResponse
import com.leikwan.bridge.session.DeviceEnrollRequest
import com.leikwan.bridge.session.DeviceEnrollResponse
import com.leikwan.bridge.session.DeviceIdentityEnrollRequest
import com.leikwan.bridge.session.DeviceRenewRequest
import com.leikwan.bridge.session.EnrollmentCodeChallengeRequest
import com.leikwan.bridge.session.EnrollmentCodeEnrollRequest
import com.leikwan.bridge.session.EnrollmentCodeIdentityEnrollRequest
import com.leikwan.bridge.runtime.BondingContract
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardEndpoint
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.NativeOutboundCapabilityDto
import com.leikwan.bridge.runtime.NativeOutboundCarrierDto
import com.leikwan.bridge.runtime.NativeOutboundLease
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
import org.junit.Assert.assertNull
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
        val bridgeRule = route.getAsJsonArray("rules")
            .map { it.asJsonObject }
            .first { it.get("action")?.asString == "route" && it.get("outbound")?.asString == "proxy" }

        assertFalse(proxy.has("override_address"))
        assertFalse(proxy.has("override_port"))
        assertEquals("127.0.0.1", bridgeRule.get("override_address").asString)
        assertEquals(11065, bridgeRule.get("override_port").asInt)
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
        val deviceId = "android-client-7-aabbccddeeff00112233445566778899"
        prefs.clientId = 7L
        prefs.agentToken = "agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "device-session"
        prefs.deviceSessionExpiresAtMs = Long.MAX_VALUE
        prefs.selectedProxyId = db.proxyNodeDao().insert(ssNode("route-rule-proxy"))
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
            backendMode = "current_mode",
            clientMode = "single",
            targetContract = "",
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
        ).copy(
            backendMode = "current_mode",
            clientMode = "bond",
            transport = "tcp_udp",
        )
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
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            routeRuleStore = routeRuleStore,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
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
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
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
        assertNull(LeikwanVpnService.configJson)
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

    @Test
    fun `ultra starts without a proxy node and protects its provider CIDR`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-ultra-test-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-ultra-test"
        prefs.clientId = 7L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        val target = "origin.example:443"
        val generation = "a".repeat(64)
        val forward = JsonParser.parseString(
            """
            {
              "forward_id":90,
              "service_name":"Ultra service",
              "target":"$target",
              "listen_port":11090,
              "mode":"ultra",
              "backend_mode":"ultra",
              "client_mode":"ultra",
              "transport":"tcp",
              "service_status":"active",
              "endpoint_mode":"managed",
              "endpoints":[],
              "ultra_access":{
                "protocol":"leikwan-ultra-policy-v2",
                "forward_id":90,
                "session_ticket":"ticket-90",
                "policy_generation":"$generation",
                "issued_at":"${now.minusSeconds(1)}",
                "renew_after":"${now.plusSeconds(60)}",
                "expires_at":"${now.plusSeconds(180)}",
                "service_state":"active",
                "transport":"tcp",
                "rate_limit_mbps":20,
                "quota_remaining_bytes":1048576,
                "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}"
              }
            }
            """.trimIndent(),
        )
        val lease = JsonParser.parseString(
            """
            {
              "protocol":"leikwan-ultra-policy-v2",
              "forward_id":90,
              "session_ticket":"ticket-90",
              "policy_generation":"$generation",
              "lease_id":"ul2_test_lease_1234567890",
              "expires_at":"${now.plusSeconds(180)}",
              "transport":"tcp",
              "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576,
              "selection_ref":"selection_ref_1234567890",
              "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}",
              "nonce_hash":"${"b".repeat(64)}",
              "endpoint":{"ip":"203.0.113.90","port":5438},
              "credential":{"user":"line-user","password":"line-password"},
              "direct_ip_cidrs":["203.0.113.90/32"]
            }
            """.trimIndent(),
        ).asJsonObject
        val panelApi = FakePanelApi(
            forwards = listOf(forward),
            ultraLeaseResponse = Response.success(
                com.leikwan.bridge.api.UltraLeaseResponse(ok = true, leases = listOf(lease)),
            ),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val processRunner = RecordingProcessRunner()
        var sentSocketPath = ""
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            ultraLeaseSender = { socketPath, policy, runtimeLease ->
                sentSocketPath = socketPath
                assertEquals(90L, policy.forwardId)
                assertEquals("203.0.113.90/32", runtimeLease.directConnectivity.ipCidrs.single())
                true
            },
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
        )

        orchestrator.startForForward(90L)

        assertEquals(listOf(90L), processRunner.started.map { it.forwardId })
        assertTrue(processRunner.started.single().runtimeContext.processPlans.single().command.contains("--config"))
        assertTrue(sentSocketPath.endsWith(".control.sock"))
        assertEquals(listOf(11090), processRunner.awaitedPorts)
        val config = JsonParser.parseString(LeikwanVpnService.configJson).asJsonObject
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }
        assertTrue(
            rules.any { rule ->
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "203.0.113.90/32" } == true
                    && rule.get("outbound")?.asString == "direct"
            }
        )

        orchestrator.stop()
    }

    @Test
    fun `native refresh replaces leases in place without restarting the runtime`() = runBlocking {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        val prefs = Prefs(context.getSharedPreferences("orchestrator-native-refresh-test-prefs", Context.MODE_PRIVATE))
        val deviceId = "android-client-native-refresh"
        prefs.clientId = 91L
        prefs.agentToken = "test-agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "test-device-session"
        prefs.deviceSessionExpiresAtMs = System.currentTimeMillis() + 300_000L

        val now = java.time.Instant.now()
        val forwardId = 91L
        val target = "native.example:443"
        val generation = "c".repeat(64)
        val direction = "China Mainland -> International"
        val routeScope = "intl_only"
        val scopeDigest = NativeOutboundLease.scopeDigest(forwardId, direction, "tcp+udp", routeScope)
        val forward = JsonParser.parseString(
            """
            {
              "forward_id":$forwardId,
              "service_name":"Native international service",
              "target":"$target",
              "forward_combination_direction":"$direction",
              "listen_port":11091,
              "mode":"ultra",
              "backend_mode":"ultra",
              "client_mode":"ultra",
              "transport":"tcp+udp",
              "service_status":"active",
              "endpoint_mode":"managed",
              "endpoints":[],
              "ultra_access":{
                "protocol":"leikwan-ultra-policy-v2",
                "forward_id":$forwardId,
                "session_ticket":"ticket-native-91",
                "policy_generation":"$generation",
                "issued_at":"${now.minusSeconds(1)}",
                "renew_after":"${now.plusSeconds(120)}",
                "expires_at":"${now.plusSeconds(270)}",
                "service_state":"active",
                "transport":"tcp+udp",
                "rate_limit_mbps":100,
                "quota_remaining_bytes":1048576,
                "target_digest":"${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}"
              }
            }
            """.trimIndent(),
        )
        fun leaseResponse(expiresAt: java.time.Instant, suffix: String) = Response.success(
            com.leikwan.bridge.api.UltraLeaseResponse(
                ok = true,
                leases = listOf("20" to "8443", "21" to "8444").mapIndexed { index, (hostSuffix, port) ->
                    JsonParser.parseString(
                        """
                        {
                          "protocol":"leikwan-ultra-native-socks5-v1",
                          "purpose":"native_outbound",
                          "forward_id":$forwardId,
                          "lease_id":"ul2_native_${suffix}_${index}",
                          "policy_generation":"$generation",
                          "expires_at":"$expiresAt",
                          "transport":"tcp+udp",
                          "rate_limit_mbps":100,
                          "quota_remaining_bytes":1048576,
                          "direction":"$direction",
                          "route_scope":"$routeScope",
                          "scope_digest":"$scopeDigest",
                          "selection_ref":"native-selection-${index}-stable",
                          "selection_tier":"mixed",
                          "endpoint":{"ip":"198.51.100.$hostSuffix","port":$port},
                          "credential":{"user":"user-$suffix-$index","password":"password-$suffix-$index"}
                        }
                        """.trimIndent(),
                    ).asJsonObject
                },
            ),
        )
        val panelApi = FakePanelApi(
            forwards = listOf(forward),
            nativeOutbound = NativeOutboundCapabilityDto(
                enabled = true,
                protocol = "socks5",
                leaseProtocol = "leikwan-ultra-native-socks5-v1",
                transport = "tcp+udp",
                forwardIds = listOf(forwardId),
                defaultForwardId = forwardId,
                routeScopes = listOf(routeScope, "global"),
                defaultRouteScope = routeScope,
                forwards = listOf(
                    NativeOutboundCarrierDto(
                        forwardId = forwardId,
                        direction = direction,
                        scopeDigest = scopeDigest,
                        transport = "tcp+udp",
                        routeScopes = listOf(routeScope, "global"),
                        defaultRouteScope = routeScope,
                    )
                ),
            ),
            ultraLeaseResponses = listOf(
                leaseResponse(now.plusSeconds(120), "initial"),
                leaseResponse(now.plusSeconds(240), "refresh"),
            ),
        )
        val panelRepo = PanelRepository(
            context = context,
            prefsOverride = prefs,
            apiOverride = panelApi,
            deviceIdProvider = { deviceId },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        )
        val processRunner = RecordingProcessRunner()
        val deliveredPaths = mutableListOf<String>()
        val deliveredBatches = mutableListOf<List<NativeOutboundLease>>()
        val registry = SafePathRegistry.androidBaseline(prefs.panelUrl, "v3-mirror.leikwanhost.com")
        val orchestrator = TunnelOrchestrator(
            context = context,
            prefs = prefs,
            db = db,
            userRuleStore = UserRoutingRuleStore(db),
            rulePackManager = RulePackManager(db, prefs),
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            routingEngine = RoutingPolicyEngine(registry, RoutingCategoryCatalog.default()),
            panelRepoFactory = { panelRepo },
            binaryInstallerFactory = {
                RecordingBinaryInstaller(
                    context,
                    RuntimeBinaryPaths(
                        wstunnelBinary = "/runtime/wstunnel",
                        ingressBinary = "/runtime/leikwan-ingress",
                        bondBinary = "/runtime/leikwan-bond",
                        ultraBinary = "/runtime/ultra-client",
                    ),
                )
            },
            processRunnerFactory = { processRunner },
            nativeLeaseBatchSender = { socketPath, leases ->
                deliveredPaths += socketPath
                deliveredBatches += leases.toList()
                true
            },
            vpnServiceStarter = { _ -> },
            vpnStartupAwaiter = { VpnStartupAwaitResult.Running },
        )

        orchestrator.startNativeForForward(forwardId, routeScope, bypassChina = false)
        val stopAllCallsAfterStart = processRunner.stopAllCalls

        assertEquals(1, processRunner.started.size)
        assertEquals(1, deliveredBatches.size)
        assertTrue(deliveredBatches.flatten().all { lease ->
            lease.user.all { it.toInt() == 0 } && lease.password.all { it.toInt() == 0 }
        })

        orchestrator.refreshNativeForTest(now.plusSeconds(61))
        orchestrator.refreshNativeForTest(now.plusSeconds(100))

        assertEquals(1, processRunner.started.size)
        assertEquals(stopAllCallsAfterStart, processRunner.stopAllCalls)
        assertEquals(2, deliveredBatches.size)
        assertEquals(deliveredPaths.first(), deliveredPaths.last())
        assertEquals(2, panelApi.resolveUltraLeaseCalls)
        assertTrue(deliveredBatches.flatten().all { lease ->
            lease.user.all { it.toInt() == 0 } && lease.password.all { it.toInt() == 0 }
        })

        orchestrator.stop()
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
        val awaitedPorts = mutableListOf<Int>()
        var stopAllCalled = false
        var stopAllCalls = 0

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
            stopAllCalls += 1
        }

        override fun awaitLocalPort(port: Int, timeoutMs: Long) {
            awaitedPorts += port
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
        private val ultraLeaseResponse: Response<com.leikwan.bridge.api.UltraLeaseResponse> = Response.success(
            com.leikwan.bridge.api.UltraLeaseResponse(ok = false),
        ),
        private val nativeOutbound: NativeOutboundCapabilityDto? = null,
        private val ultraLeaseResponses: List<Response<com.leikwan.bridge.api.UltraLeaseResponse>> = emptyList(),
    ) : PanelApi {
        var resolveUltraLeaseCalls = 0
            private set

        override suspend fun deviceChallenge(request: DeviceChallengeRequest): Response<DeviceChallengeResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollDevice(request: DeviceEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollDeviceIdentity(request: DeviceIdentityEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun deviceRenewalChallenge(request: DeviceChallengeRequest): Response<DeviceChallengeResponse> =
            throw UnsupportedOperationException()

        override suspend fun renewDevice(request: DeviceRenewRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollmentCodeChallenge(request: EnrollmentCodeChallengeRequest): Response<DeviceChallengeResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollmentCodeEnroll(request: EnrollmentCodeEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun enrollmentCodeIdentityEnroll(request: EnrollmentCodeIdentityEnrollRequest): Response<DeviceEnrollResponse> =
            throw UnsupportedOperationException()

        override suspend fun acknowledgeDevice(auth: String, request: DeviceAckRequest): Response<DeviceAckResponse> =
            throw UnsupportedOperationException()

        override suspend fun heartbeat(auth: String, request: HeartbeatRequest): HeartbeatResponse = HeartbeatResponse(ok = true)
        override suspend fun resolveUltraLease(
            auth: String,
            request: com.leikwan.bridge.api.UltraLeaseRequest,
        ): Response<com.leikwan.bridge.api.UltraLeaseResponse> {
            val index = resolveUltraLeaseCalls++
            return ultraLeaseResponses.getOrNull(index)
                ?: ultraLeaseResponses.lastOrNull()
                ?: ultraLeaseResponse
        }
        override suspend fun pullConfigs(auth: String): PullConfigsResponse = PullConfigsResponse(
            ok = true,
            client = JsonObject(),
            forwards = forwards,
            routeDirections = routeDirections,
            clientRules = clientRules,
            directionRuntimeBindings = bindings,
            routeRuleCapabilities = capabilities,
            nativeOutbound = nativeOutbound,
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
