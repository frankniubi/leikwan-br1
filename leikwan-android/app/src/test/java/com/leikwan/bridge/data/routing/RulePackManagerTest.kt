package com.leikwan.bridge.data.routing

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
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
class RulePackManagerTest {
    private lateinit var db: AppDatabase
    private lateinit var prefs: Prefs
    private lateinit var manager: RulePackManager

    @Before
    fun setUp() {
        db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
        val shared = ApplicationProvider.getApplicationContext<Context>()
            .getSharedPreferences("rule-pack-manager-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared)
        manager = RulePackManager(db, prefs) { 1717027300000 }
    }

    @After
    fun tearDown() {
        db.close()
    }

    private fun snapshot() = RulePackSnapshot(
        countryPackVersion = "country-2026-05-30a",
        categoryPackVersion = "category-2026-05-30a",
        sourceLabel = "panel://routing/rule-packs",
        fetchedAtMs = 1717027200000,
        validatedAtMs = 1717027205000,
        integrityState = RulePackSnapshot.IntegrityState.VERIFIED,
        stale = false,
        rollbackAvailable = false,
        payloadJson = "{}",
    )

    @Test
    fun `first activation has no previous and no rollback`() = runBlocking {
        val activated = manager.activate(snapshot())

        assertFalse(activated.rollbackAvailable)
        assertEquals("country-2026-05-30a", manager.current()!!.countryPackVersion)
        assertEquals(null, manager.previous())
        assertEquals("country-2026-05-30a", prefs.activeRulePackCountryVersion)
        assertEquals("category-2026-05-30a", prefs.activeRulePackCategoryVersion)
        assertEquals("panel://routing/rule-packs", prefs.activeRulePackSourceLabel)
    }

    @Test
    fun `second activation keeps previous snapshot and updates prefs`() = runBlocking {
        val first = snapshot()
        val second = first.copy(
            countryPackVersion = "country-2026-05-30b",
            validatedAtMs = 1717027300000,
        )

        manager.activate(first)
        manager.activate(second)

        assertEquals("country-2026-05-30b", manager.current()!!.countryPackVersion)
        assertEquals("country-2026-05-30a", manager.previous()!!.countryPackVersion)
        assertEquals("country-2026-05-30b", prefs.activeRulePackCountryVersion)
        assertTrue(manager.current()!!.rollbackAvailable)
    }

    @Test
    fun `activeVersions lists country and category versions`() = runBlocking {
        assertEquals(emptyList<String>(), manager.activeVersions())
        manager.activate(snapshot())
        assertEquals(
            listOf("country-2026-05-30a", "category-2026-05-30a"),
            manager.activeVersions(),
        )
    }
}
