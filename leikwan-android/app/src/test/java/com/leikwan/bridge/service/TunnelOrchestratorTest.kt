package com.leikwan.bridge.service

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonParser
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
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
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

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

        override fun start(forwardId: Long, runtimeContext: RuntimeContext, cleanupStale: Boolean) {
            if (forwardId in failForProcessKeys) {
                throw IllegalStateException("planned failure for process $forwardId")
            }
            started += Started(forwardId, runtimeContext, cleanupStale)
        }
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
}
