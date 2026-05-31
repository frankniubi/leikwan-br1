package com.leikwan.bridge.service

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.runtime.BondingContract
import com.leikwan.bridge.runtime.ForwardContract
import com.leikwan.bridge.runtime.ForwardEndpoint
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.ProcessRunner
import com.leikwan.bridge.runtime.RuntimeBinaryPaths
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
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

        val legs = orchestrator.startNodeLegs(
            forward = sampleBondForward(),
            nodeIds = listOf(1L, 2L),
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
            ),
        )

        assertEquals(setOf(1L, 2L), legs.keys)
        assertEquals(11026, legs.getValue(1L).localListenPort)
        assertEquals(11027, legs.getValue(2L).localListenPort)
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

    private class RecordingProcessRunner : ProcessRunner() {
        data class Started(
            val forwardId: Long,
            val runtimeContext: RuntimeContext,
            val cleanupStale: Boolean,
        )

        val started = mutableListOf<Started>()

        override fun start(forwardId: Long, runtimeContext: RuntimeContext, cleanupStale: Boolean) {
            started += Started(forwardId, runtimeContext, cleanupStale)
        }
    }

    private fun sampleBondForward(): ForwardContract = ForwardContract(
        forwardId = 26L,
        serviceName = "bond-26",
        target = "193.227.109.82:5201",
        listenPort = 11024,
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
            remoteTarget = "138.252.162.120:9000",
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
