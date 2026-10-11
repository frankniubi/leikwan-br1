package com.leikwan.bridge.ui.login

import com.leikwan.bridge.session.DeviceLimitNotice
import com.leikwan.bridge.session.DeviceLimitReachedException
import com.leikwan.bridge.ui.shared.deviceLimitMessage
import java.io.IOException
import java.security.ProviderException
import org.junit.Assert.assertEquals
import org.junit.Test

class EnrollmentErrorFormatterTest {
    private val limitMessage: (DeviceLimitNotice) -> String = { notice ->
        deviceLimitMessage(
            notice,
            withCounts = { active, limit -> "limit $active/$limit" },
            withoutCounts = { "limit" },
        )
    }

    @Test
    fun `maps keystore provider failures to hardware attestation guidance`() {
        val message = enrollmentErrorMessage(
            ProviderException("Failed to generate key pair."),
            generic = "Login failed",
            hardwareAttestationMessage = "Hardware attestation is unavailable on this device",
            deviceLimitMessage = limitMessage,
        )

        assertEquals("Hardware attestation is unavailable on this device", message)
    }

    @Test
    fun `device limit refusal shows the localized guidance instead of the raw code`() {
        val counted = enrollmentErrorMessage(
            DeviceLimitReachedException(limit = 2, active = 3),
            generic = "Login failed",
            hardwareAttestationMessage = "attestation",
            deviceLimitMessage = limitMessage,
        )
        val wrapped = enrollmentErrorMessage(
            IOException("enroll failed", DeviceLimitReachedException(limit = null, active = null)),
            generic = "Login failed",
            hardwareAttestationMessage = "attestation",
            deviceLimitMessage = limitMessage,
        )

        assertEquals("limit 3/2", counted)
        assertEquals("limit", wrapped)
    }

    @Test
    fun `device limit message needs both counts for the counted variant`() {
        assertEquals("limit", limitMessage(DeviceLimitNotice(limit = 2, active = null)))
        assertEquals("limit 0/1", limitMessage(DeviceLimitNotice(limit = 1, active = 0)))
    }
}
