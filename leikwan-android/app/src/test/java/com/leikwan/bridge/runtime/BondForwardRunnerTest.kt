package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class BondForwardRunnerTest {

    @Test
    fun `bond runner mirrors latest linux quic standby tcp launch shape`() {
        val forward = ForwardContract(
            forwardId = 26L,
            serviceName = "bond-26",
            target = "193.227.109.82:5201",
            listenPort = 11024,
            mode = ForwardMode.BOND,
            transport = "tcp",
            serviceStatus = "active",
            endpointMode = "manual",
            sni = "relay.example.com",
            pathPrefix = "/route-key",
            speedLimitMbps = 500,
            endpoints = listOf(
                ForwardEndpoint(
                    endpointId = 184,
                    node = "gz-1",
                    host = "8.163.90.132",
                    port = 7001,
                    cdnHost = "br1.nbwly.com",
                    priority = 1,
                    dynamicPriority = null,
                    weight = 1,
                    healthStatus = "healthy",
                    supportsQuicLeg = true,
                    ingressProtocol = "plain_tcp_mux",
                    ingressAuthToken = "auth-1",
                ),
                ForwardEndpoint(
                    endpointId = 185,
                    node = "gz-2",
                    host = "8.163.90.235",
                    port = 7001,
                    cdnHost = null,
                    priority = 2,
                    dynamicPriority = null,
                    weight = 1,
                    healthStatus = "healthy",
                    supportsQuicLeg = true,
                    ingressProtocol = "plain_tcp_mux",
                    ingressAuthToken = "auth-2",
                )
            ),
            bonding = BondingContract(
                ingressMode = "plain_tcp_mux",
                routeKey = "/route-key",
                remoteTarget = "138.252.162.120:9000",
                sessionToken = "tcp-token",
                udpSessionToken = "udp-token",
                chunkSize = 131072,
                maxWindowBytes = 16777216,
                retransmitMs = 333,
                connectTimeoutMs = 3500,
                joinTimeoutMs = 400,
                adaptiveJoinTimeout = true,
                joinTimeoutFloorMs = 35,
                joinTimeoutCeilingMs = 220,
                joinTimeoutPerLegMs = 20,
                interactiveChunkBytes = 20000,
                latencyGuardBytes = 300000,
                interactiveRttSlackMs = 45,
                warmupTriggerBytes = 65536,
                warmupSliceBytes = 65536,
                warmupLegDelayMs = 80,
                bulkPromoteBytes = 262144,
                legCooldownMs = 3000,
                quicEnabled = true,
                quicViaSlave = true,
                activeLegLimit = 1,
                quicActiveLegLimit = 1,
                wssStandbyEnabled = true,
                disableLocalRateLimit = true,
                quicServerName = "sidecar.example.com",
                quicInsecure = true,
                quicPriority = 0,
                wsPriority = 10,
            ),
            mptcp = null,
        )

        val context = BondForwardRunner.buildPlan(
            forward = forward,
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
            ),
            strategyFilePath = "/data/user/0/com.leikwan.bridge/files/forward-strategies/26.strategy",
        )

        assertEquals(ForwardMode.BOND, context.mode)
        assertEquals(11024, context.localListenPort)
        assertEquals(listOf(20962, 20963), context.localLegPorts)
        assertEquals(3, context.processPlans.size)

        val ingressCommand = context.processPlans.first().command
        assertEquals("/runtime/leikwan-ingress", ingressCommand.first())
        assertTrue(ingressCommand.contains("--listen"))
        assertTrue(ingressCommand.contains("127.0.0.1:20962"))
        assertTrue(ingressCommand.contains("--remote"))
        assertTrue(ingressCommand.contains("8.163.90.132:7001"))
        assertTrue(ingressCommand.contains("--route-key"))
        assertTrue(ingressCommand.contains("/route-key"))
        assertTrue(ingressCommand.contains("--auth-token"))
        assertTrue(ingressCommand.contains("auth-1"))

        val bondCommand = context.processPlans.last().command
        assertEquals("/runtime/leikwan-bond", bondCommand.first())
        assertTrue(bondCommand.contains("--listen"))
        assertTrue(bondCommand.contains("127.0.0.1:11024"))
        assertTrue(bondCommand.contains("--session-token"))
        assertTrue(bondCommand.contains("tcp-token"))
        assertTrue(bondCommand.contains("--strategy-file"))
        assertTrue(bondCommand.contains("/data/user/0/com.leikwan.bridge/files/forward-strategies/26.strategy"))
        assertTrue(bondCommand.contains("--default-strategy"))
        assertTrue(bondCommand.contains("latency_best"))
        assertTrue(bondCommand.contains("--retransmit-ms"))
        assertTrue(bondCommand.contains("333"))
        assertTrue(bondCommand.contains("--connect-timeout-ms"))
        assertTrue(bondCommand.contains("3500"))
        assertTrue(bondCommand.contains("--join-timeout-ms"))
        assertTrue(bondCommand.contains("95"))
        assertTrue(bondCommand.contains("--interactive-chunk-bytes"))
        assertTrue(bondCommand.contains("20000"))
        assertTrue(bondCommand.contains("--warmup-trigger-bytes"))
        assertTrue(bondCommand.contains("--bulk-promote-bytes"))
        assertTrue(bondCommand.any { it.contains("quic://8.163.90.132:7001?priority=0&endpoint_id=184&display_host=8.163.90.132&server_name=sidecar.example.com&insecure=1") })
        assertFalse(bondCommand.any { it.contains("quic://8.163.90.235:7001?priority=0&endpoint_id=185&display_host=8.163.90.235&server_name=sidecar.example.com&insecure=1") })
        assertFalse(bondCommand.any { it.contains("tcp://127.0.0.1:20962?priority=") })
        assertFalse(bondCommand.any { it.contains("tcp://127.0.0.1:20963?priority=") })
        assertTrue(context.bypassHosts.contains("8.163.90.132"))
        assertTrue(context.bypassHosts.contains("br1.nbwly.com"))
        assertTrue(context.bypassHosts.contains("8.163.90.235"))
        assertTrue(context.bypassHosts.contains("relay.example.com"))
        assertTrue(context.bypassHosts.contains("sidecar.example.com"))
    }

    @Test
    fun `bond runner builds dual tcp and udp runtimes with wss remote target`() {
        val forward = ForwardContract(
            forwardId = 26L,
            serviceName = "bond-26",
            target = "193.227.109.82:5201",
            listenPort = 11024,
            mode = ForwardMode.BOND,
            transport = "tcp+udp",
            serviceStatus = "active",
            endpointMode = "manual",
            sni = "relay.example.com",
            pathPrefix = "/route-key",
            speedLimitMbps = 500,
            endpoints = listOf(
                ForwardEndpoint(
                    endpointId = 184,
                    node = "gz-1",
                    host = "8.163.90.132",
                    port = 7001,
                    cdnHost = null,
                    priority = 1,
                    dynamicPriority = null,
                    weight = 1,
                    healthStatus = "healthy",
                    supportsQuicLeg = false,
                    ingressProtocol = null,
                    ingressAuthToken = null,
                ),
                ForwardEndpoint(
                    endpointId = 185,
                    node = "gz-2",
                    host = "8.163.90.235",
                    port = 7001,
                    cdnHost = null,
                    priority = 2,
                    dynamicPriority = null,
                    weight = 1,
                    healthStatus = "healthy",
                    supportsQuicLeg = false,
                    ingressProtocol = null,
                    ingressAuthToken = null,
                )
            ),
            bonding = BondingContract(
                ingressMode = "wss_path_mux",
                routeKey = "/route-key",
                remoteTarget = "138.252.162.120:9000",
                sessionToken = "tcp-token",
                udpSessionToken = "udp-token",
                chunkSize = 131072,
                maxWindowBytes = 16777216,
                retransmitMs = 333,
                connectTimeoutMs = 3500,
                joinTimeoutMs = 400,
                adaptiveJoinTimeout = false,
                joinTimeoutFloorMs = 35,
                joinTimeoutCeilingMs = 220,
                joinTimeoutPerLegMs = 20,
                interactiveChunkBytes = 20000,
                latencyGuardBytes = 300000,
                interactiveRttSlackMs = 45,
                warmupTriggerBytes = 65536,
                warmupSliceBytes = 65536,
                warmupLegDelayMs = 80,
                bulkPromoteBytes = 262144,
                legCooldownMs = 3000,
                quicEnabled = false,
                quicViaSlave = false,
                activeLegLimit = 0,
                quicActiveLegLimit = 0,
                wssStandbyEnabled = true,
                disableLocalRateLimit = true,
                quicServerName = null,
                quicInsecure = true,
                quicPriority = 0,
                wsPriority = 10,
            ),
            mptcp = null,
        )

        val context = BondForwardRunner.buildPlan(
            forward = forward,
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
            ),
        )

        assertEquals(listOf(20962, 20963, 21162, 21163), context.localLegPorts)
        assertEquals(6, context.processPlans.size)

        val tcpIngress = context.processPlans.first().command
        assertEquals("/runtime/wstunnel", tcpIngress.first())
        assertTrue(tcpIngress.contains("tcp://127.0.0.1:20962:138.252.162.120:9000"))

        val udpIngress = context.processPlans[2].command
        assertEquals("/runtime/wstunnel", udpIngress.first())
        assertTrue(udpIngress.contains("tcp://127.0.0.1:21162:138.252.162.120:9000"))

        val bondCommands = context.processPlans.filter { it.role == RuntimeProcessRole.BOND }.map { it.command }
        assertEquals(2, bondCommands.size)
        assertTrue(bondCommands.any { command ->
            command.contains("--protocol") &&
                command.contains("tcp") &&
                command.contains("tcp-token") &&
                command.any { it.contains("tcp://127.0.0.1:20962?priority=10&endpoint_id=184&display_host=8.163.90.132") }
        })
        assertTrue(bondCommands.any { command ->
            command.contains("--protocol") &&
                command.contains("udp") &&
                command.contains("udp-token") &&
                command.any { it.contains("tcp://127.0.0.1:21162?priority=10&endpoint_id=184&display_host=8.163.90.132") }
        })
    }

    @Test
    fun `bond runner port salt separates per-node leg ingress ports`() {
        val forward = ForwardContract(
            forwardId = 26L,
            serviceName = "bond-26",
            target = "193.227.109.82:5201",
            listenPort = 11024,
            mode = ForwardMode.BOND,
            transport = "tcp",
            serviceStatus = "active",
            endpointMode = "manual",
            sni = "relay.example.com",
            pathPrefix = "/route-key",
            speedLimitMbps = 500,
            endpoints = listOf(
                ForwardEndpoint(
                    endpointId = 184,
                    node = "gz-1",
                    host = "8.163.90.132",
                    port = 7001,
                    cdnHost = null,
                    priority = 1,
                    dynamicPriority = null,
                    weight = 1,
                    healthStatus = "healthy",
                    supportsQuicLeg = false,
                    ingressProtocol = null,
                    ingressAuthToken = null,
                ),
            ),
            bonding = BondingContract(
                ingressMode = "wss_path_mux",
                routeKey = "/route-key",
                remoteTarget = "138.252.162.120:9000",
                sessionToken = "tcp-token",
                udpSessionToken = "udp-token",
                chunkSize = null,
                maxWindowBytes = null,
                retransmitMs = null,
                connectTimeoutMs = null,
                joinTimeoutMs = null,
                adaptiveJoinTimeout = false,
                joinTimeoutFloorMs = null,
                joinTimeoutCeilingMs = null,
                joinTimeoutPerLegMs = null,
                interactiveChunkBytes = null,
                latencyGuardBytes = null,
                interactiveRttSlackMs = null,
                warmupTriggerBytes = null,
                warmupSliceBytes = null,
                warmupLegDelayMs = null,
                bulkPromoteBytes = null,
                legCooldownMs = null,
                quicEnabled = false,
                quicViaSlave = false,
                activeLegLimit = 0,
                quicActiveLegLimit = 0,
                wssStandbyEnabled = true,
                disableLocalRateLimit = true,
                quicServerName = null,
                quicInsecure = true,
                quicPriority = 0,
                wsPriority = 10,
            ),
            mptcp = null,
        )
        val binaries = RuntimeBinaryPaths(
            wstunnelBinary = "/runtime/wstunnel",
            ingressBinary = "/runtime/leikwan-ingress",
            bondBinary = "/runtime/leikwan-bond",
        )

        val nodeA = BondForwardRunner.buildPlan(forward, binaries, portSalt = 20)
        val nodeB = BondForwardRunner.buildPlan(forward, binaries, portSalt = 40)

        assertEquals(listOf(20982), nodeA.localLegPorts)
        assertEquals(listOf(21002), nodeB.localLegPorts)
        assertTrue(nodeA.processPlans.first().command.contains("tcp://127.0.0.1:20982:138.252.162.120:9000"))
        assertTrue(nodeB.processPlans.first().command.contains("tcp://127.0.0.1:21002:138.252.162.120:9000"))
    }
}
