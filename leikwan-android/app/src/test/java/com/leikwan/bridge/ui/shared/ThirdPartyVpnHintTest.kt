package com.leikwan.bridge.ui.shared

import com.leikwan.bridge.session.DeviceLimitReachedException
import com.leikwan.bridge.ui.login.enrollmentErrorMessage
import java.io.IOException
import java.net.ConnectException
import java.security.ProviderException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class ThirdPartyVpnHintTest {
    private val hint = "Another VPN is running"

    private fun enrollError(exception: Throwable, thirdPartyVpnHint: String?): String = enrollmentErrorMessage(
        exception,
        generic = "Login failed",
        hardwareAttestationMessage = "attestation",
        deviceLimitMessage = { "limit" },
        thirdPartyVpnHint = thirdPartyVpnHint,
    )

    @Test
    fun `hint is appended to a failure message only when present`() {
        assertEquals("boom\n\n$hint", withThirdPartyVpnHint("boom", hint))
        assertEquals("boom", withThirdPartyVpnHint("boom", null))
        assertEquals(hint, withThirdPartyVpnHint(null, hint))
        assertNull(withThirdPartyVpnHint(null, null))
    }

    @Test
    fun `network sign-in failure carries the hint when a third party VPN blocks`() {
        assertEquals(
            "Failed to connect\n\n$hint",
            enrollError(IOException("Failed to connect", ConnectException("refused")), hint),
        )
        assertEquals("Login failed\n\n$hint", enrollError(IOException(), hint))
    }

    @Test
    fun `no hint without a blocking third party VPN`() {
        assertEquals("Failed to connect", enrollError(IOException("Failed to connect"), null))
    }

    @Test
    fun `panel and device answers never carry the hint`() {
        assertEquals("limit", enrollError(DeviceLimitReachedException(limit = 1, active = 1), hint))
        assertEquals("attestation", enrollError(ProviderException("keystore"), hint))
    }
}
