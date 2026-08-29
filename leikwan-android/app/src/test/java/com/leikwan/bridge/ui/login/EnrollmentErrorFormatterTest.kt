package com.leikwan.bridge.ui.login

import java.security.ProviderException
import org.junit.Assert.assertEquals
import org.junit.Test

class EnrollmentErrorFormatterTest {
    @Test
    fun `maps keystore provider failures to hardware attestation guidance`() {
        val message = enrollmentErrorMessage(
            ProviderException("Failed to generate key pair."),
            generic = "Login failed",
            hardwareAttestationMessage = "Hardware attestation is unavailable on this device",
        )

        assertEquals("Hardware attestation is unavailable on this device", message)
    }
}
