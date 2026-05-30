package com.leikwan.bridge.service

import com.leikwan.bridge.data.ProxyNode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProxySelectionPolicyTest {

    @Test
    fun `policy accepts enabled proxy that matches forward`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 1L,
            forwardId = 45L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 1,
                    name = "proxy",
                    type = "ss",
                    originalServer = "127.0.0.1",
                    originalPort = 32215,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = true,
                )
            ),
        )

        require(result is ProxySelectionResult.Success)
        assertEquals(1L, result.selectedNode.id)
    }

    @Test
    fun `policy rejects when no proxy id is selected`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 0L,
            forwardId = 45L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 1,
                    name = "proxy",
                    type = "ss",
                    originalServer = "127.0.0.1",
                    originalPort = 32215,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = true,
                )
            ),
        )

        require(result is ProxySelectionResult.Error)
        assertEquals("Select a proxy before starting this service", result.message)
    }

    @Test
    fun `policy rejects when no enabled proxy is selected`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 2L,
            forwardId = 45L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 2,
                    name = "proxy",
                    type = "ss",
                    originalServer = "127.0.0.1",
                    originalPort = 32215,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = false,
                )
            ),
        )

        require(result is ProxySelectionResult.Error)
        assertTrue(result.message.contains("disabled", ignoreCase = true))
    }

    @Test
    fun `policy rejects mismatched service proxy in services mode`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 3L,
            forwardId = 45L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 3,
                    name = "proxy",
                    type = "ss",
                    originalServer = "remote.example.com",
                    originalPort = 443,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 44L,
                    enabled = true,
                )
            ),
        )

        require(result is ProxySelectionResult.Error)
        assertTrue(result.message.contains("match", ignoreCase = true))
    }

    @Test
    fun `policy allows direct mode without service binding`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 4L,
            forwardId = 0L,
            connectionMode = ProxyConnectionMode.DIRECT,
            proxyNodes = listOf(
                ProxyNode(
                    id = 4,
                    name = "proxy",
                    type = "ss",
                    originalServer = "remote.example.com",
                    originalPort = 443,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 44L,
                    enabled = true,
                )
            ),
        )

        require(result is ProxySelectionResult.Success)
        assertEquals(4L, result.selectedNode.id)
    }
}
