package com.leikwan.bridge.service

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
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
}
