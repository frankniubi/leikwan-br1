package com.leikwan.bridge.diagnostics

import androidx.test.core.app.ApplicationProvider
import android.content.Context
import com.leikwan.bridge.service.LeikwanVpnService
import org.junit.After
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class DebugLogBundleTest {
    private val context: Context = ApplicationProvider.getApplicationContext()

    @After
    fun cleanUp() {
        context.filesDir.listFiles { file -> file.name.startsWith("vpn-events.log") }?.forEach { it.delete() }
        LeikwanVpnService.configJson = null
    }

    @Test
    fun `an uploaded bundle says how the earlier tunnel ended`() {
        val service = Robolectric.buildService(LeikwanVpnService::class.java).create().get()
        service.stopVpn("rebuild gave up")

        val bundle = String(DebugLogUploader.collectAppBundle(context) { "logcat line" }, Charsets.UTF_8)

        assertTrue(bundle, bundle.contains("=== vpn events ==="))
        assertTrue(bundle, bundle.contains("vpn stop: rebuild gave up (running=false recovering=false)"))
        assertTrue(bundle, bundle.indexOf("=== vpn events ===") < bundle.indexOf("=== logcat (app process) ==="))
    }
}
