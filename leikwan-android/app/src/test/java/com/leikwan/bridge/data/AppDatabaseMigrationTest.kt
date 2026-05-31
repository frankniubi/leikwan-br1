package com.leikwan.bridge.data

import android.content.Context
import androidx.sqlite.db.SupportSQLiteDatabase
import androidx.sqlite.db.SupportSQLiteOpenHelper
import androidx.sqlite.db.framework.FrameworkSQLiteOpenHelperFactory
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AppDatabaseMigrationTest {

    @Test
    fun `migration 2 to 3 adds nullable target node id to user routing rules`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val dbName = "migration-2-3-routing-test.db"
        context.deleteDatabase(dbName)

        openAtVersion(
            context = context,
            dbName = dbName,
            version = 2,
            onCreateCallback = { db ->
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
                db.execSQL(
                    """
                    INSERT INTO user_routing_rules
                    (id, kind, match_value, outcome, enabled, created_at)
                    VALUES ('category-video', 'WEBSITE_CATEGORY', 'video', 'TUNNEL', 1, 123)
                    """.trimIndent()
                )
            },
        ).close()

        openAtVersion(
            context = context,
            dbName = dbName,
            version = 3,
            onUpgradeCallback = { db, oldVersion, newVersion ->
                assertEquals(2, oldVersion)
                assertEquals(3, newVersion)
                AppDatabase.MIGRATION_2_3.migrate(db)
            },
        ).use { helper ->
            val db = helper.writableDatabase
            val columns = mutableListOf<String>()
            db.query("PRAGMA table_info(user_routing_rules)").use { cursor ->
                while (cursor.moveToNext()) {
                    columns += cursor.getString(cursor.getColumnIndexOrThrow("name"))
                }
            }
            assertTrue(columns.contains("target_node_id"))

            db.query("SELECT target_node_id FROM user_routing_rules WHERE id = 'category-video'").use { cursor ->
                assertTrue(cursor.moveToFirst())
                assertNull(cursor.getString(0))
            }
        }

        File(context.getDatabasePath(dbName).absolutePath).delete()
    }

    private fun openAtVersion(
        context: Context,
        dbName: String,
        version: Int,
        onCreateCallback: (SupportSQLiteDatabase) -> Unit = {},
        onUpgradeCallback: (SupportSQLiteDatabase, Int, Int) -> Unit = { _, _, _ -> },
    ): SupportSQLiteOpenHelper {
        val helper = FrameworkSQLiteOpenHelperFactory().create(
            SupportSQLiteOpenHelper.Configuration.builder(context)
                .name(dbName)
                .callback(object : SupportSQLiteOpenHelper.Callback(version) {
                    override fun onCreate(db: SupportSQLiteDatabase) = onCreateCallback(db)
                    override fun onUpgrade(db: SupportSQLiteDatabase, oldVersion: Int, newVersion: Int) =
                        onUpgradeCallback(db, oldVersion, newVersion)
                })
                .build()
        )
        helper.writableDatabase.close()
        return helper
    }
}
