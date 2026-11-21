package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.nio.file.Files
import java.security.MessageDigest
import java.time.Instant

class UltraForwardRunnerTest {
    @Test
    fun `ports runner is loopback raw forwarding with strict socket broker and scoped DNS`() {
        val runtimeDir = Files.createTempDirectory("leikwan-ports-test").toFile()
        val forward = sampleForward()
        val policy = UltraPolicy.from(forward, Instant.parse("2026-08-27T00:00:00Z"))!!
        val plan = UltraForwardRunner.buildPlan(forward, policy,
            RuntimeBinaryPaths(null, null, null, "/runtime/ultra-client"), runtimeDir,
            directEgress = DirectEgressConfig("/data/user/0/com.leikwan.bridge/files/eg.sock", listOf("192.0.2.53:53", "[2001:db8::53]:53")))
        try {
            val config = JsonParser.parseString(plan.configFile.readText()).asJsonObject
            assertEquals("127.0.0.1", config.get("listen_host").asString)
            assertEquals(forward.listenPort, config.get("listen_port").asInt)
            assertEquals(forward.target, config.get("target").asString)
            assertTrue(config.get("direct_egress_required").asBoolean)
            assertEquals("/data/user/0/com.leikwan.bridge/files/eg.sock", config.get("egress_socket_broker").asString)
            assertEquals(2, config.getAsJsonArray("egress_dns_servers").size())
            assertFalse(config.has("native_socks5"))
        } finally { UltraForwardRunner.cleanup(plan.runtimeContext); runtimeDir.deleteRecursively() }
    }

    @Test
    fun `native runner enables bounded automatic candidate selection`() {
        val runtimeDir = Files.createTempDirectory("leikwan-ultra-native-test").toFile()
        val forward = sampleForward()
        val policy = UltraPolicy.from(forward, Instant.parse("2026-08-27T00:00:00Z"))!!

        val plan = UltraForwardRunner.buildPlan(
            forward = forward,
            policy = policy,
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
                ultraBinary = "/runtime/ultra-client",
            ),
            runtimeDir = runtimeDir,
            nativeSocks5 = true,
        )

        val config = JsonParser.parseString(plan.configFile.readText()).asJsonObject
        assertTrue(config.get("native_socks5").asBoolean)
        assertTrue(config.get("auto_select").asBoolean)
        assertEquals(5, config.get("probe_duration_seconds").asInt)
        assertEquals(4, config.get("probe_workers").asInt)
        assertEquals(250, config.get("probe_interval_ms").asInt)
        assertEquals("restricted", config.get("native_route_policy").asString)
        assertEquals(1000, config.get("native_port_min").asInt)
        assertEquals("opengfw_tcp", config.get("native_filter_profile").asString)

        UltraForwardRunner.cleanup(plan.runtimeContext)
    }

    @Test
    fun `ordinary automatic selection uses the shared probe contract`() {
        val runtimeDir = Files.createTempDirectory("leikwan-ultra-auto-test").toFile()
        val forward = sampleForward()
        val policy = UltraPolicy.from(forward, Instant.parse("2026-08-27T00:00:00Z"))!!

        val plan = UltraForwardRunner.buildPlan(
            forward = forward,
            policy = policy,
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
                ultraBinary = "/runtime/ultra-client",
            ),
            runtimeDir = runtimeDir,
            autoSelect = true,
        )

        val config = JsonParser.parseString(plan.configFile.readText()).asJsonObject
        assertTrue(config.get("auto_select").asBoolean)
        assertEquals("1.1.1.1:53", config.get("probe_target").asString)
        assertEquals(30, config.get("probe_duration_seconds").asInt)
        assertEquals(800, config.get("probe_timeout_ms").asInt)
        assertEquals(30, config.get("probe_samples").asInt)
        assertEquals(1000, config.get("probe_interval_ms").asInt)
        assertEquals(4, config.get("probe_workers").asInt)

        UltraForwardRunner.cleanup(plan.runtimeContext)
    }

    @Test
    fun `native runner disables opengfw and port gate only for explicit unrestricted policy`() {
        val runtimeDir = Files.createTempDirectory("leikwan-ultra-native-unrestricted-test").toFile()
        val forward = sampleForward()
        val policy = UltraPolicy.from(forward, Instant.parse("2026-08-27T00:00:00Z"))!!

        val plan = UltraForwardRunner.buildPlan(
            forward = forward,
            policy = policy,
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
                ultraBinary = "/runtime/ultra-client",
            ),
            runtimeDir = runtimeDir,
            nativeSocks5 = true,
            nativeRoutePolicy = "unrestricted",
        )

        val config = JsonParser.parseString(plan.configFile.readText()).asJsonObject
        assertEquals("unrestricted", config.get("native_route_policy").asString)
        assertEquals(1000, config.get("native_port_min").asInt)
        assertEquals("off", config.get("native_filter_profile").asString)

        UltraForwardRunner.cleanup(plan.runtimeContext)
    }

    @Test
    fun `runner writes private static config without lease credentials or provider endpoint`() {
        val runtimeDir = Files.createTempDirectory("leikwan-ultra-test").toFile()
        val forward = sampleForward()
        val policy = UltraPolicy.from(forward, Instant.parse("2026-08-27T00:00:00Z"))!!

        val plan = UltraForwardRunner.buildPlan(
            forward = forward,
            policy = policy,
            binaries = RuntimeBinaryPaths(
                wstunnelBinary = "/runtime/wstunnel",
                ingressBinary = "/runtime/leikwan-ingress",
                bondBinary = "/runtime/leikwan-bond",
                ultraBinary = "/runtime/ultra-client",
            ),
            runtimeDir = runtimeDir,
        )

        val config = plan.configFile.readText()
        assertEquals(ForwardMode.ULTRA, plan.runtimeContext.mode)
        assertEquals(RuntimeProcessRole.ULTRA, plan.runtimeContext.processPlans.single().role)
        assertTrue(plan.runtimeContext.processPlans.single().command.contains("--config"))
        assertTrue(config.contains("\"control_socket\""))
        assertFalse(config.contains("ticket-42"))
        assertFalse(config.contains("line-password"))
        assertFalse(config.contains("203.0.113.20"))
        assertTrue(plan.runtimeContext.privateRuntimePaths.contains(plan.configFile.absolutePath))
        assertTrue(plan.runtimeContext.privateRuntimePaths.contains(plan.controlSocketFile.absolutePath))

        UltraForwardRunner.cleanup(plan.runtimeContext)
        assertFalse(plan.configFile.exists())
    }

    @Test
    fun `ordinary runtime forwards sidecar bootstrap and rejects malformed opt in`() {
        val forward = sampleForward()
        val sidecar = JsonParser.parseString("""{"enabled":true,"sidecar_target":"standby.test:7443","token":"fixture","delivery_mode":"reliable_datagram_v1"}""").asJsonObject
        forward.ultraAccess!!.add("external_uot", sidecar)
        val policy = UltraPolicy.from(forward, Instant.parse("2026-08-27T00:00:00Z"))!!
        val dir = Files.createTempDirectory("ultra-sidecar-test").toFile()
        val plan = UltraForwardRunner.buildPlan(
            forward, policy, RuntimeBinaryPaths("/wstunnel", "/ingress", "/bond", "/ultra"), dir,
        )
        try {
            val config = JsonParser.parseString(plan.configFile.readText()).asJsonObject
            assertEquals("standby.test:7443", config["external_uot"].asJsonObject["sidecar_target"].asString)
            assertEquals("reliable_datagram_v1", config["external_uot"].asJsonObject["delivery_mode"].asString)
            sidecar.addProperty("token", "")
            assertTrue(UltraPolicy.from(forward, Instant.parse("2026-08-27T00:00:00Z")) == null)
        } finally { UltraForwardRunner.cleanup(plan.runtimeContext); dir.deleteRecursively() }
    }

    private fun sampleForward(): ForwardContract = ForwardContract(
        forwardId = 42L,
        serviceName = "Ultra test",
        target = "origin.example:443",
        listenPort = 11042,
        mode = ForwardMode.ULTRA,
        rawMode = "ultra",
        backendMode = "ultra",
        clientMode = "ultra",
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "managed",
        sni = "",
        pathPrefix = "",
        speedLimitMbps = 20,
        endpoints = emptyList(),
        bonding = null,
        mptcp = null,
        ultraAccess = policyJson(),
    )

    private fun policyJson(): JsonObject = JsonParser.parseString(
        """
        {
          "protocol":"leikwan-ultra-policy-v2",
          "forward_id":42,
          "session_ticket":"ticket-42",
          "policy_generation":"${"a".repeat(64)}",
          "issued_at":"2026-08-27T00:00:00Z",
          "renew_after":"2026-08-27T00:02:00Z",
          "expires_at":"2026-08-27T00:04:00Z",
          "service_state":"active",
          "transport":"tcp",
          "rate_limit_mbps":20,
          "quota_remaining_bytes":1048576,
          "target_digest":"${digest("origin.example:443")}"
        }
        """.trimIndent(),
    ).asJsonObject

    private fun digest(value: String): String = MessageDigest.getInstance("SHA-256")
        .digest(value.toByteArray(Charsets.UTF_8))
        .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
}
