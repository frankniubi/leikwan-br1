package com.leikwan.bridge.api

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
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

    @Test
    fun `maps a 429 online limit refusal with flat counts`() {
        val limit = PanelErrorParser.deviceLimitFromHttp(
            429,
            """{"ok":false,"error":"client_online_limit_reached","protocol_version":1,"online_device_limit":2,"active_online_devices":3,"details":{"online_device_limit":9,"active_online_devices":9}}""",
        )

        assertNotNull(limit)
        assertEquals(2, limit?.limit)
        assertEquals(3, limit?.active)
        assertEquals("client_online_limit_reached", limit?.message)
    }

    @Test
    fun `falls back to nested details counts and tolerates missing counts`() {
        val nested = PanelErrorParser.deviceLimitFromHttp(
            429,
            """{"ok":false,"error":"client_online_limit_reached","details":{"online_device_limit":"4","active_online_devices":4}}""",
        )
        assertEquals(4, nested?.limit)
        assertEquals(4, nested?.active)

        val bare = PanelErrorParser.deviceLimitFromHttp(429, """{"ok":false,"error":"client_online_limit_reached"}""")
        assertNotNull(bare)
        assertNull(bare?.limit)
        assertNull(bare?.active)
    }

    @Test
    fun `other 429s and other statuses are not a device limit`() {
        assertNull(PanelErrorParser.deviceLimitFromHttp(429, """{"ok":false,"error":"rate_limited"}"""))
        assertNull(PanelErrorParser.deviceLimitFromHttp(429, "not json"))
        assertNull(PanelErrorParser.deviceLimitFromHttp(429, null))
        assertNull(PanelErrorParser.deviceLimitFromHttp(403, """{"ok":false,"error":"client_online_limit_reached"}"""))
    }
}
