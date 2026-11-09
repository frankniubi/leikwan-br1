package com.leikwan.bridge.runtime

import com.google.gson.JsonParser
import org.junit.Assert.*
import org.junit.Test

class ExternalUotGrantTest {
    private fun wire(target: String = "sidecar.test:7443", token: String = "fixture-token") =
        JsonParser.parseString("""{"enabled":true,"sidecar_target":"$target","token":"$token","delivery_mode":"reliable_datagram_v1"}""").asJsonObject

    @Test fun bootstrapAndIdentity() {
        val first = ExternalUotGrant.from(wire())
        assertEquals("sidecar.test:7443", first.toBootstrapJson()["sidecar_target"].asString)
        assertEquals("reliable_datagram_v1", first.toBootstrapJson()["delivery_mode"].asString)
        assertEquals(first.fingerprint, ExternalUotGrant.from(wire()).fingerprint)
        assertNotEquals(first.fingerprint, ExternalUotGrant.from(wire("standby.test:7443")).fingerprint)
        assertNotEquals(first.fingerprint, ExternalUotGrant.from(wire(token = "rotated")).fingerprint)
        assertFalse(first.toString().contains("fixture-token"))
    }
    @Test fun malformedDoesNotDowngrade() {
        for (raw in listOf(wire("bad:0"), wire(token = ""), wire("bad:99999"))) {
            assertTrue(runCatching { ExternalUotGrant.from(raw) }.isFailure)
        }
        val unsupported = wire().apply { addProperty("delivery_mode", "unknown") }
        assertTrue(runCatching { ExternalUotGrant.from(unsupported) }.isFailure)
    }
}
