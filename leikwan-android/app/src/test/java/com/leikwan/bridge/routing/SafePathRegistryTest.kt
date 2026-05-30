package com.leikwan.bridge.routing

import org.junit.Assert.assertTrue
import org.junit.Test

class SafePathRegistryTest {

    @Test
    fun `android baseline marks panel host, mirror, and loopback as protected`() {
        val registry = SafePathRegistry.androidBaseline(
            panelUrl = "https://panel.example.com/api/v1",
            mirrorHost = "v3-mirror.leikwanhost.com",
        )

        assertTrue(registry.containsDomain("panel.example.com"))
        assertTrue(registry.containsDomain("v3-mirror.leikwanhost.com"))
        assertTrue(registry.containsIpCidr("127.0.0.0/8"))
        assertTrue(registry.containsIpCidr("10.0.0.0/8"))
        assertTrue(registry.containsIpCidr("172.16.0.0/12"))
        assertTrue(registry.containsIpCidr("192.168.0.0/16"))
    }

    @Test
    fun `baseline exposes panel-api, panel-mirror, and loopback-control protected entries`() {
        val registry = SafePathRegistry.androidBaseline(
            panelUrl = "https://panel.example.com",
            mirrorHost = "v3-mirror.leikwanhost.com",
        )
        val ids = registry.protectedEntries().map { it.id }
        assertTrue(ids.contains("panel-api"))
        assertTrue(ids.contains("panel-mirror"))
        assertTrue(ids.contains("loopback-control"))
    }

    @Test
    fun `domain matching is case insensitive`() {
        val registry = SafePathRegistry.androidBaseline(
            panelUrl = "https://Panel.Example.COM",
            mirrorHost = "v3-mirror.leikwanhost.com",
        )
        assertTrue(registry.containsDomain("PANEL.example.com"))
    }
}
