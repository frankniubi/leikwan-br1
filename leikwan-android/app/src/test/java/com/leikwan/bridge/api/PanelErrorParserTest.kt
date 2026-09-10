package com.leikwan.bridge.api

import org.junit.Assert.assertEquals
import org.junit.Test

class PanelErrorParserTest {
    @Test
    fun `extracts api error message from json body`() {
        val message = PanelErrorParser.messageFromBody("""{"ok":false,"error":"invalid credentials"}""")

        assertEquals("invalid credentials", message)
    }

    @Test
    fun `falls back to http status when body has no error field`() {
        val message = PanelErrorParser.messageFromHttp(401, "Unauthorized", """{"ok":false}""")

        assertEquals("HTTP 401 Unauthorized", message)
    }

    @Test
    fun `extracts code and reason from structured api error`() {
        val message = PanelErrorParser.messageFromBody(
            """{"error":{"code":"device_untrusted","details":{"reason":"attestation_configuration_missing"}}}""",
        )

        assertEquals("device_untrusted: attestation_configuration_missing", message)
    }
}
