package com.leikwan.bridge.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase
import com.leikwan.bridge.data.routing.RoutingExplainabilityDao
import com.leikwan.bridge.data.routing.RoutingExplainabilityEntity
import com.leikwan.bridge.data.routing.RulePackDao
import com.leikwan.bridge.data.routing.RulePackSnapshotEntity
import com.leikwan.bridge.data.routing.UserRoutingRuleDao
import com.leikwan.bridge.data.routing.UserRoutingRuleEntity

@Database(
    entities = [
        ProxyNode::class,
        Subscription::class,
        RulePackSnapshotEntity::class,
        RoutingExplainabilityEntity::class,
        UserRoutingRuleEntity::class,
    ],
    version = 2,
    exportSchema = false,
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun proxyNodeDao(): ProxyNodeDao
    abstract fun subscriptionDao(): SubscriptionDao
    abstract fun rulePackDao(): RulePackDao
    abstract fun routingExplainabilityDao(): RoutingExplainabilityDao
    abstract fun userRoutingRuleDao(): UserRoutingRuleDao

    companion object {
        @Volatile private var INSTANCE: AppDatabase? = null

        val MIGRATION_1_2 = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `rule_pack_snapshots` (
                        `slot` TEXT NOT NULL,
                        `country_pack_version` TEXT NOT NULL,
                        `category_pack_version` TEXT NOT NULL,
                        `source_label` TEXT NOT NULL,
                        `fetched_at_ms` INTEGER NOT NULL,
                        `validated_at_ms` INTEGER NOT NULL,
                        `integrity_state` TEXT NOT NULL,
                        `stale` INTEGER NOT NULL,
                        `rollback_available` INTEGER NOT NULL,
                        `payload_json` TEXT NOT NULL,
                        PRIMARY KEY(`slot`)
                    )
                    """.trimIndent()
                )
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `routing_explainability` (
                        `slot` TEXT NOT NULL,
                        `revision` INTEGER NOT NULL,
                        `compiled_at_ms` INTEGER NOT NULL,
                        `rule_pack_country_version` TEXT NOT NULL,
                        `rule_pack_category_version` TEXT NOT NULL,
                        `active_user_rules_json` TEXT NOT NULL,
                        `protected_paths_json` TEXT NOT NULL,
                        `warnings_json` TEXT NOT NULL,
                        `payload_json` TEXT NOT NULL,
                        PRIMARY KEY(`slot`)
                    )
                    """.trimIndent()
                )
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS `user_routing_rules` (
                        `id` TEXT NOT NULL,
                        `kind` TEXT NOT NULL,
                        `match_value` TEXT NOT NULL,
                        `outcome` TEXT NOT NULL,
                        `enabled` INTEGER NOT NULL,
                        `created_at` INTEGER NOT NULL,
                        PRIMARY KEY(`id`)
                    )
                    """.trimIndent()
                )
            }
        }

        fun get(context: Context): AppDatabase =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "leikwan_bridge.db"
                ).addMigrations(MIGRATION_1_2)
                    .build()
                    .also { INSTANCE = it }
            }
    }
}
