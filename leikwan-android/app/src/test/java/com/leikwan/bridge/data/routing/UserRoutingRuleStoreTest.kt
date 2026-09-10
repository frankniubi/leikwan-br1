package com.leikwan.bridge.data.routing

import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
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

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class UserRoutingRuleStoreTest {
    private lateinit var db: AppDatabase
    private lateinit var store: UserRoutingRuleStore

    @Before
    fun setUp() {
        db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
        store = UserRoutingRuleStore(db)
    }

    @After
    fun tearDown() {
        db.close()
    }

    private val countryRule = RoutingRule(
        id = "rule-country",
        kind = RoutingRuleKind.COUNTRY,
        matchValue = "CN",
        outcome = UserTrafficOutcome.DIRECT,
        enabled = true,
    )
    private val categoryRule = RoutingRule(
        id = "rule-category",
        kind = RoutingRuleKind.WEBSITE_CATEGORY,
        matchValue = "video",
        outcome = UserTrafficOutcome.TUNNEL,
        enabled = true,
    )

    @Test
    fun `upsert and per-kind queries round-trip outcome and kind`() = runBlocking {
        store.upsert(countryRule)
        store.upsert(categoryRule)

        assertEquals(2, store.all().size)

        val countries = store.countryRules()
        assertEquals(1, countries.size)
        assertEquals(RoutingRuleKind.COUNTRY, countries.single().kind)
        assertEquals("CN", countries.single().matchValue)
        assertEquals(UserTrafficOutcome.DIRECT, countries.single().outcome)

        val categories = store.categoryRules()
        assertEquals(1, categories.size)
        assertEquals(RoutingRuleKind.WEBSITE_CATEGORY, categories.single().kind)
        assertEquals("video", categories.single().matchValue)
        assertEquals(UserTrafficOutcome.TUNNEL, categories.single().outcome)
    }

    @Test
    fun `upsert with same id replaces`() = runBlocking {
        store.upsert(countryRule)
        store.upsert(countryRule.copy(matchValue = "JP", outcome = UserTrafficOutcome.TUNNEL))

        val all = store.all()
        assertEquals(1, all.size)
        assertEquals("JP", all.single().matchValue)
        assertEquals(UserTrafficOutcome.TUNNEL, all.single().outcome)
    }

    @Test
    fun `setEnabled toggles and delete removes`() = runBlocking {
        store.upsert(countryRule)
        store.setEnabled(countryRule.id, false)
        assertFalse(store.all().single().enabled)

        store.setEnabled(countryRule.id, true)
        assertTrue(store.all().single().enabled)

        store.delete(countryRule.id)
        assertTrue(store.all().isEmpty())
    }

    @Test
    fun `clear removes all rules`() = runBlocking {
        store.upsert(countryRule)
        store.upsert(categoryRule)
        store.clear()
        assertTrue(store.all().isEmpty())
    }
}
