package com.leikwan.bridge.data

import android.content.Context
import androidx.sqlite.db.SupportSQLiteDatabase
import androidx.sqlite.db.SupportSQLiteOpenHelper
import androidx.sqlite.db.framework.FrameworkSQLiteOpenHelperFactory
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AppDatabaseHostedNodeMigrationTest {

    @Test
    fun `migration 3 to 4 adds a nullable hosted id and keeps existing nodes local`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val dbName = "migration-3-4-hosted-test.db"
        context.deleteDatabase(dbName)

        open(context, dbName, 3, onCreate = { db ->
            db.execSQL(
                "CREATE TABLE IF NOT EXISTS `proxy_nodes` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, " +
                    "`name` TEXT NOT NULL, `type` TEXT NOT NULL, `originalServer` TEXT NOT NULL, " +
                    "`originalPort` INTEGER NOT NULL, `configJson` TEXT NOT NULL, `subscriptionId` INTEGER, " +
                    "`forwardId` INTEGER, `enabled` INTEGER NOT NULL, `createdAt` INTEGER NOT NULL)",
            )
            db.execSQL(
                "INSERT INTO proxy_nodes (name, type, originalServer, originalPort, configJson, enabled, createdAt) " +
                    "VALUES ('n', 'socks5', 'a.example.com', 1080, '{}', 1, 1)",
            )
        }).close()

        open(context, dbName, 4, onUpgrade = { db -> AppDatabase.MIGRATION_3_4.migrate(db) }).use { helper ->
            val db = helper.writableDatabase
            val columns = mutableListOf<String>()
            db.query("PRAGMA table_info(proxy_nodes)").use { cursor ->
                while (cursor.moveToNext()) columns += cursor.getString(cursor.getColumnIndexOrThrow("name"))
            }
            assertTrue(columns.contains("hostedId"))
            db.query("SELECT hostedId FROM proxy_nodes").use { cursor ->
                assertEquals(1, cursor.count)
                assertTrue(cursor.moveToFirst())
                assertTrue(cursor.isNull(0))
            }
        }
        context.deleteDatabase(dbName)
    }

    private fun open(
        context: Context,
        dbName: String,
        version: Int,
        onCreate: (SupportSQLiteDatabase) -> Unit = {},
        onUpgrade: (SupportSQLiteDatabase) -> Unit = {},
    ): SupportSQLiteOpenHelper {
        val config = SupportSQLiteOpenHelper.Configuration.builder(context)
            .name(dbName)
            .callback(object : SupportSQLiteOpenHelper.Callback(version) {
                override fun onCreate(db: SupportSQLiteDatabase) = onCreate(db)
                override fun onUpgrade(db: SupportSQLiteDatabase, oldVersion: Int, newVersion: Int) = onUpgrade(db)
            })
            .build()
        return FrameworkSQLiteOpenHelperFactory().create(config).also { it.writableDatabase }
    }
}
