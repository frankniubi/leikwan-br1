package com.leikwan.bridge.data

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.routing.UserTrafficOutcome
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class PrefsRoutingStateTest {
    private lateinit var prefs: Prefs

    @Before
    fun setUp() {
        val shared = ApplicationProvider.getApplicationContext<Context>()
            .getSharedPreferences("prefs-routing-state-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared)
    }

    @Test
    fun `routing default outcome defaults to tunnel and round-trips`() {
        assertEquals(UserTrafficOutcome.TUNNEL, prefs.routingDefaultOutcome)
        prefs.routingDefaultOutcome = UserTrafficOutcome.DIRECT
        assertEquals(UserTrafficOutcome.DIRECT, prefs.routingDefaultOutcome)
    }

    @Test
    fun `routing selector enabled defaults false and round-trips`() {
        assertEquals(false, prefs.routingSelectorEnabled)
        prefs.routingSelectorEnabled = true
        assertEquals(true, prefs.routingSelectorEnabled)
    }

    @Test
    fun `logout preserves routing metadata but clears auth fields and selector switch`() {
        prefs.token = "token-123"
        prefs.routingDefaultOutcome = UserTrafficOutcome.DIRECT
        prefs.routingSelectorEnabled = true
        prefs.activeRulePackCountryVersion = "country-2026-05-30a"
        prefs.activeRulePackCategoryVersion = "category-2026-05-30a"
        prefs.activeRulePackSourceLabel = "panel://routing/rule-packs"
        prefs.activeRulePackFetchedAtMs = 1717027200000L
        prefs.activeRulePackStale = true
        prefs.latestExplainabilityRevision = 7L
        prefs.latestExplainabilityCompiledAtMs = 1717027300000L

        prefs.logout()

        assertNull(prefs.token)
        assertEquals(UserTrafficOutcome.DIRECT, prefs.routingDefaultOutcome)
        assertEquals(false, prefs.routingSelectorEnabled)
        assertEquals("country-2026-05-30a", prefs.activeRulePackCountryVersion)
        assertEquals("category-2026-05-30a", prefs.activeRulePackCategoryVersion)
        assertEquals("panel://routing/rule-packs", prefs.activeRulePackSourceLabel)
        assertEquals(1717027200000L, prefs.activeRulePackFetchedAtMs)
        assertEquals(true, prefs.activeRulePackStale)
        assertEquals(7L, prefs.latestExplainabilityRevision)
        assertEquals(1717027300000L, prefs.latestExplainabilityCompiledAtMs)
    }
}
