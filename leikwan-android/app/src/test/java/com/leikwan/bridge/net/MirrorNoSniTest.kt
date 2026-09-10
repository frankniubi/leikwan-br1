package com.leikwan.bridge.net

import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MirrorNoSniTest {

    @Test
    fun `rewrites mirror request to edge ip while preserving Host header and path`() {
        val original = Request.Builder()
            .url("https://v3-mirror.leikwanhost.com/api/client/login")
            .post(ByteArray(0).toRequestBody(null))
            .build()

        val rewritten = MirrorNoSni.rewrite(original, edgeIp = "23.147.52.11")

        assertEquals("23.147.52.11", rewritten.url.host)
        assertEquals("/api/client/login", rewritten.url.encodedPath)
        assertEquals("v3-mirror.leikwanhost.com", rewritten.header("Host"))
    }

    @Test
    fun `only the mirror host is eligible for no-sni rewrite`() {
        assertTrue(MirrorNoSni.shouldRewrite("v3-mirror.leikwanhost.com"))
        assertTrue(MirrorNoSni.shouldRewrite("V3-MIRROR.LEIKWANHOST.COM"))
        assertFalse(MirrorNoSni.shouldRewrite("v3.leikwanhost.com"))
        assertFalse(MirrorNoSni.shouldRewrite("example.com"))
    }

    @Test
    fun `edge fallback is pinned to the observed Photon edge certificate`() {
        assertTrue(MirrorNoSni.edgeIps.contains("23.147.52.11"))
        assertTrue(
            MirrorNoSni.pinnedLeafSha256.contains(
                "58CC3F15EFC94E18836E72C2E71703D1E5DA90DECC0149476089689F6D85A642"
            )
        )
    }
}
