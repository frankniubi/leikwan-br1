package com.leikwan.bridge.data

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.runtime.ForwardStrategy
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ForwardStrategyStoreTest {
    @Test
    fun `missing choice defaults to latency best and choices stay per forward`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val shared = context.getSharedPreferences("forward-strategy-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        val runtimeDir = File(context.cacheDir, "forward-strategy-${System.nanoTime()}")
        val store = ForwardStrategyStore(shared, "panel-a", 9, runtimeDir)

        assertEquals(ForwardStrategy.LATENCY_BEST, store.get(77))
        store.set(77, ForwardStrategy.RANDOM)
        assertEquals(ForwardStrategy.RANDOM, store.get(77))
        assertEquals(ForwardStrategy.LATENCY_BEST, store.get(78))
        assertEquals("random\n", store.runtimeFile(77).readText())
    }

    @Test
    fun `choices are isolated by panel and client`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val shared = context.getSharedPreferences("forward-strategy-isolation-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        val runtimeDir = File(context.cacheDir, "forward-strategy-isolation-${System.nanoTime()}")
        ForwardStrategyStore(shared, "panel-a", 9, runtimeDir).set(77, ForwardStrategy.SPEED_MAX)

        assertEquals(ForwardStrategy.LATENCY_BEST, ForwardStrategyStore(shared, "panel-b", 9, runtimeDir).get(77))
        assertEquals(ForwardStrategy.LATENCY_BEST, ForwardStrategyStore(shared, "panel-a", 10, runtimeDir).get(77))
    }
}
