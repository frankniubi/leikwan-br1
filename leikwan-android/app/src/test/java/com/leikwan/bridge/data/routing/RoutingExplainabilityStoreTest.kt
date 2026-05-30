package com.leikwan.bridge.data.routing

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RoutingExplainabilityStoreTest {
    private lateinit var db: AppDatabase
    private lateinit var prefs: Prefs
    private lateinit var store: RoutingExplainabilityStore

    @Before
    fun setUp() {
        db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
        val shared = ApplicationProvider.getApplicationContext<Context>()
            .getSharedPreferences("routing-explainability-store-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared)
        store = RoutingExplainabilityStore(db, prefs)
    }

    @After
    fun tearDown() {
        db.close()
    }

    @Test
    fun `record replaces latest snapshot and mirrors revision into prefs`() = runBlocking {
        val first = RoutingExplainabilitySnapshot(
            revision = 1L,
            compiledAtMs = 1717027400000,
            rulePackCountryVersion = "country-2026-05-30a",
            rulePackCategoryVersion = "category-2026-05-30a",
            activeUserRules = listOf("country:JP=tunnel"),
            protectedPaths = listOf("panel", "heartbeat"),
            warnings = emptyList(),
            payloadJson = "{}",
        )
        val second = first.copy(
            revision = 2L,
            compiledAtMs = 1717027500000,
            warnings = listOf("stale country pack"),
        )

        store.record(first)
        store.record(second)

        assertEquals(2L, store.latest()!!.revision)
        assertEquals(listOf("stale country pack"), store.latest()!!.warnings)
        assertEquals(2L, prefs.latestExplainabilityRevision)
        assertEquals(1717027500000L, prefs.latestExplainabilityCompiledAtMs)
    }
}
