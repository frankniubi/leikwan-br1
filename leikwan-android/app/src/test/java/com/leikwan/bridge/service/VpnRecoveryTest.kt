package com.leikwan.bridge.service

import android.content.Intent
import android.net.VpnService
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class VpnRecoveryTest {

    @Test
    fun `a sticky restart or boot start rebuilds only when the user left the vpn connected`() {
        assertTrue(VpnRecovery.shouldRecover(null, connectIntent = true))
        assertTrue(VpnRecovery.shouldRecover(Intent(VpnRecovery.ACTION_RECONNECT), connectIntent = true))
        assertTrue(VpnRecovery.shouldRecover(Intent(VpnService.SERVICE_INTERFACE), connectIntent = true))
        assertFalse(VpnRecovery.shouldRecover(null, connectIntent = false))
    }

    @Test
    fun `a start without a config from the app itself is not a recovery`() {
        assertFalse(VpnRecovery.shouldRecover(Intent("STOP"), connectIntent = true))
        assertFalse(VpnRecovery.shouldRecover(Intent(), connectIntent = true))
    }
}
