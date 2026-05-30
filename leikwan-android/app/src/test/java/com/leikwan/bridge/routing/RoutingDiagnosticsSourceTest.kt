package com.leikwan.bridge.routing

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Verifies [RoutingDiagnosticsSource] returns a deterministic, non-null default-from-registry
 * summary when no explainability snapshot has been recorded yet (first launch).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RoutingDiagnosticsSourceTest {

    private lateinit var db: AppDatabase
    private lateinit var prefs: Prefs

    @Before
    fun setUp() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        prefs = Prefs(context.getSharedPreferences("diagnostics-test", Context.MODE_PRIVATE))
    }

    @After
    fun tearDown() {
        db.close()
    }

    private fun newSource(): RoutingDiagnosticsSource =
        RoutingDiagnosticsSource(
            prefs = prefs,
            explainabilityStore = RoutingExplainabilityStore(db, prefs),
            userRuleStore = UserRoutingRuleStore(db),
        )

    @Test
    fun `empty store yields deterministic default summary`() {
        val model = newSource().latest()

        assertEquals(prefs.routingDefaultOutcome.name, model.defaultActionLabel)
        assertEquals("DEFAULT", model.decisionReasonLabel)
        assertEquals("Panel, bootstrap, and rule-pack endpoints", model.protectedTrafficLabel)
        assertEquals("none", model.activeRulePackLabel)
        assertTrue(model.warnings.contains("system-protected traffic is enforced"))
    }

    @Test
    fun `default action reflects the configured global default outcome`() {
        prefs.routingDefaultOutcome = UserTrafficOutcome.DIRECT
        val model = newSource().latest()
        assertEquals("DIRECT", model.defaultActionLabel)
    }
}
