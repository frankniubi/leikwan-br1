package com.leikwan.bridge.service

import com.leikwan.bridge.data.ProxyNode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
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
        assertEquals(1L, result.selectedNode?.id)
        assertEquals(listOf(1L), result.activeNodes.map { it.id })
    }

    @Test
    fun `policy returns all enabled nodes so services mode can start per-node forward legs`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 1L,
            forwardId = 45L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 1,
                    name = "selected",
                    type = "ss",
                    originalServer = "127.0.0.1",
                    originalPort = 32215,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = true,
                ),
                ProxyNode(
                    id = 2,
                    name = "same-forward",
                    type = "ss",
                    originalServer = "127.0.0.2",
                    originalPort = 32216,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = true,
                ),
                ProxyNode(
                    id = 3,
                    name = "global",
                    type = "ss",
                    originalServer = "127.0.0.3",
                    originalPort = 32217,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = null,
                    enabled = true,
                ),
                ProxyNode(
                    id = 4,
                    name = "other-forward",
                    type = "ss",
                    originalServer = "127.0.0.4",
                    originalPort = 32218,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 44L,
                    enabled = true,
                ),
                ProxyNode(
                    id = 5,
                    name = "disabled",
                    type = "ss",
                    originalServer = "127.0.0.5",
                    originalPort = 32219,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = false,
                ),
            ),
        )

        require(result is ProxySelectionResult.Success)
        assertEquals(listOf(1L, 2L, 3L, 4L), result.activeNodes.map { it.id })
    }

    @Test
    fun `policy restricts ultra protocol adapter to selected node`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 2L,
            forwardId = 90L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 1,
                    name = "other",
                    type = "ss",
                    originalServer = "other.example.com",
                    originalPort = 443,
                    configJson = """{"method":"aes-128-gcm","password":"other"}""",
                    enabled = true,
                ),
                ProxyNode(
                    id = 2,
                    name = "selected",
                    type = "ss",
                    originalServer = "relay.example.com",
                    originalPort = 30522,
                    configJson = """{"method":"2022-blake3-aes-256-gcm","password":"secret"}""",
                    enabled = true,
                ),
            ),
            singleNodeForServices = true,
        )

        require(result is ProxySelectionResult.Success)
        assertEquals(2L, result.selectedNode?.id)
        assertEquals(listOf(2L), result.activeNodes.map { it.id })
    }

    @Test
    fun `policy allows dedicated leigod service without selected proxy`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 0L,
            forwardId = 65L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 1,
                    name = "unrelated-proxy",
                    type = "ss",
                    originalServer = "127.0.0.1",
                    originalPort = 32215,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = true,
                )
            ),
            proxyOptional = true,
        )

        require(result is ProxySelectionResult.Success)
        assertNull(result.selectedNode)
        assertEquals(emptyList<Long>(), result.activeNodes.map { it.id })
    }

    @Test
    fun `policy ignores stale selected proxy for dedicated leigod service`() {
        val result = ProxySelectionPolicy.validate(
            selectedProxyId = 1L,
            forwardId = 65L,
            connectionMode = ProxyConnectionMode.SERVICES,
            proxyNodes = listOf(
                ProxyNode(
                    id = 1,
                    name = "stale-proxy",
                    type = "ss",
                    originalServer = "127.0.0.1",
                    originalPort = 32215,
                    configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                    forwardId = 45L,
                    enabled = true,
                )
            ),
            proxyOptional = true,
        )

        require(result is ProxySelectionResult.Success)
        assertNull(result.selectedNode)
        assertEquals(emptyList<Long>(), result.activeNodes.map { it.id })
    }

    @Test
    fun `policy rejects when no proxy id is selected and proxy is required`() {
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
    fun `policy allows a selected proxy with its own service binding in services mode`() {
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

        require(result is ProxySelectionResult.Success)
        assertEquals(3L, result.selectedNode?.id)
        assertEquals(listOf(3L), result.activeNodes.map { it.id })
    }

    @Test
    fun `policy still validates direct mode as an internal config builder path`() {
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
        assertEquals(4L, result.selectedNode?.id)
    }
}
