package com.leikwan.bridge

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.AppDatabase
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Proves the Robolectric + androidx.test test infrastructure works under :app:testDebugUnitTest,
 * so subsequent tasks can write Android-context unit tests (Prefs, Room, ViewModels, orchestrator).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class InfraSmokeTest {

    @Test
    fun `application context is available under robolectric`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        assertNotNull(context)
    }

    @Test
    fun `in-memory room database builds`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val db = Room.inMemoryDatabaseBuilder(context, AppDatabase::class.java)
            .allowMainThreadQueries()
            .build()
        try {
            // Room opens the underlying SQLite lazily; force it open to prove Room works under Robolectric.
            db.openHelper.writableDatabase
            assertTrue(db.isOpen)
        } finally {
            db.close()
        }
    }
}
