package com.leikwan.bridge.data

import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.routing.RoutingExplainabilityEntity
import com.leikwan.bridge.data.routing.RulePackSnapshotEntity
import com.leikwan.bridge.data.routing.UserRoutingRuleEntity
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
class AppDatabaseRoutingTablesTest {
    private lateinit var db: AppDatabase

    @Before
    fun setUp() {
        db = Room.inMemoryDatabaseBuilder(
            ApplicationProvider.getApplicationContext(),
            AppDatabase::class.java,
        ).allowMainThreadQueries().build()
    }

    @After
    fun tearDown() {
        db.close()
    }

    @Test
    fun `routing tables are available in version 2 schema`() = runBlocking {
        db.rulePackDao().upsert(
            RulePackSnapshotEntity(
                slot = "active",
                countryPackVersion = "country-2026-05-30a",
                categoryPackVersion = "category-2026-05-30a",
                sourceLabel = "panel://routing/rule-packs",
                fetchedAtMs = 1717027200000,
                validatedAtMs = 1717027205000,
                integrityState = "VERIFIED",
                stale = false,
                rollbackAvailable = false,
                payloadJson = "{}",
            )
        )
        db.routingExplainabilityDao().upsert(
            RoutingExplainabilityEntity(
                slot = "latest",
                revision = 1L,
                compiledAtMs = 1717027300000,
                rulePackCountryVersion = "country-2026-05-30a",
                rulePackCategoryVersion = "category-2026-05-30a",
                activeUserRulesJson = "[]",
                protectedPathsJson = "[]",
                warningsJson = "[]",
                payloadJson = "{}",
            )
        )
        db.userRoutingRuleDao().upsert(
            UserRoutingRuleEntity(
                id = "rule-1",
                kind = "COUNTRY",
                matchValue = "CN",
                outcome = "DIRECT",
                enabled = true,
                createdAt = 1717027400000,
            )
        )

        assertEquals("active", db.rulePackDao().active()!!.slot)
        assertEquals("latest", db.routingExplainabilityDao().latest()!!.slot)
        assertEquals("rule-1", db.userRoutingRuleDao().all().single().id)
    }
}
