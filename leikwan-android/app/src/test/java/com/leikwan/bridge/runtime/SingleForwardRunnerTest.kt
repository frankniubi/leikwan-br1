package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SingleForwardRunnerTest {

    @Test
    fun `single runner mirrors linux wstunnel shape`() {
        val forward = ForwardContract(
            forwardId = 41L,
            serviceName = "single-41",
            target = "203.0.113.8:443",
            listenPort = 11041,
            mode = ForwardMode.SINGLE,
            transport = "tcp",
            serviceStatus = "active",
            endpointMode = "manual",
            sni = "relay.example.com",
            pathPrefix = "/forward-41",
            speedLimitMbps = null,
            endpoints = listOf(
                ForwardEndpoint(
                    endpointId = 401,
                    node = "node-401",
                    host = "198.51.100.10",
                    port = 8443,
                    cdnHost = null,
                    priority = 1,
                    weight = 1,
                    healthStatus = "healthy",
                    supportsQuicLeg = false,
                    ingressProtocol = null,
                    ingressAuthToken = null,
                )
            ),
            bonding = null,
            mptcp = null,
        )

        val context = SingleForwardRunner.buildPlan(
            forward = forward,
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = null,
                bondBinary = null,
            ),
        )

        assertEquals(ForwardMode.SINGLE, context.mode)
        assertEquals(11041, context.localListenPort)
        assertEquals(1, context.processPlans.size)
        val command = context.processPlans.single().command
        assertEquals("/runtime/wstunnel", command.first())
        assertTrue(command.contains("client"))
        assertTrue(command.contains("tcp://127.0.0.1:11041:203.0.113.8:443"))
        assertTrue(command.contains("--tls-sni-override"))
        assertTrue(command.contains("relay.example.com"))
        assertTrue(command.contains("--http-upgrade-path-prefix"))
        assertTrue(command.contains("/forward-41"))
        assertTrue(command.contains("wss://198.51.100.10:8443"))
        assertTrue(context.bypassHosts.contains("198.51.100.10"))
    }
}
