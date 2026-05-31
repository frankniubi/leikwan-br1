package com.leikwan.bridge.proxy

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ImportRouterTest {

    @Test
    fun `router recognizes wireguard uri as proxy import`() {
        val encoded = java.util.Base64.getUrlEncoder().withoutPadding().encodeToString(
            """
            [Interface]
            PrivateKey = private
            Address = 10.7.0.2/32
            [Peer]
            PublicKey = public
            Endpoint = wg.example.com:51820
            AllowedIPs = 0.0.0.0/0
            """.trimIndent().toByteArray()
        )

        assertTrue(ImportRouter.looksImportable("wg://$encoded#WG"))
        val result = ImportRouter.process("wg://$encoded#WG")

        require(result is ImportRouter.ImportResult.ProxyNodes)
        assertEquals(listOf("wireguard"), result.nodes.map { it.type })
    }
}
