package com.leikwan.bridge.ui.proxies

import com.leikwan.bridge.data.ProxyNode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Test

class ProxyNodeDuplicatorTest {

    @Test
    fun `duplicator clones proxy node with new identity`() {
        val node = ProxyNode(
            id = 9,
            name = "HKG-HKG6",
            type = "ss",
            originalServer = "remote.example.com",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
            subscriptionId = 3,
            forwardId = 45L,
            enabled = true,
            createdAt = 1L,
        )

        val duplicated = ProxyNodeDuplicator.duplicate(node)

        assertEquals(0L, duplicated.id)
        assertEquals("HKG-HKG6 Copy", duplicated.name)
        assertEquals(node.type, duplicated.type)
        assertEquals(node.originalServer, duplicated.originalServer)
        assertEquals(node.originalPort, duplicated.originalPort)
        assertNotEquals(node.createdAt, duplicated.createdAt)
    }
}
