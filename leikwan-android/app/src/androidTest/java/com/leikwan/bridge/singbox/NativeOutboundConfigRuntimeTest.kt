package com.leikwan.bridge.singbox

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.RuntimeContext
import com.google.gson.JsonParser
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.SetupOptions
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/** Verifies the bundled libbox accepts native restricted and unrestricted profiles. */
@RunWith(AndroidJUnit4::class)
class NativeOutboundConfigRuntimeTest {
    @Test
    fun libboxAcceptsRestrictedNativeDnsProfiles() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val options = SetupOptions().apply {
            basePath = context.filesDir.absolutePath
            workingPath = context.cacheDir.absolutePath
            tempPath = context.cacheDir.absolutePath
        }
        Libbox.setup(options)

        val settings = SingboxSettings(
            panelUrl = "https://panel.example.test",
            dnsServer = "9.9.9.9",
            dnsFallback = "8.8.8.8",
            dnsStrategy = "prefer_ipv4",
            bypassLan = true,
            bypassChina = false,
            customBypassRules = "example.cn",
        )
        val builder = SingboxConfigBuilder(settings)
        listOf("cn_only", "intl_only").forEach { scope ->
            val config = builder.build(
                proxyNodes = emptyList(),
                runtimeContext = null,
                nativeLocalProxy = NativeOutboundLocalProxy(port = 31245),
                nativeProtectedEndpoints = setOf("203.0.113.10"),
                nativeRouteScope = scope,
            ).configJson
            Libbox.checkConfig(config)
        }
        val unrestricted = builder.build(
            proxyNodes = emptyList(),
            runtimeContext = null,
            nativeLocalProxy = NativeOutboundLocalProxy(port = 31245),
            nativeProtectedEndpoints = setOf("203.0.113.10"),
            nativeRouteScope = "intl_only",
            nativeRoutePolicy = "unrestricted",
        ).configJson
        Libbox.checkConfig(unrestricted)
    }

    @Test
    fun libboxAcceptsOrdinaryUltraProfiles() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val options = SetupOptions().apply {
            basePath = context.filesDir.absolutePath
            workingPath = context.cacheDir.absolutePath
            tempPath = context.cacheDir.absolutePath
        }
        Libbox.setup(options)

        val builder = SingboxConfigBuilder(
            SingboxSettings(
                panelUrl = "https://panel.example.test",
                dnsServer = "9.9.9.9",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            ),
        )
        val runtime = RuntimeContext(
            forwardId = 90L,
            mode = ForwardMode.ULTRA,
            localListenPort = 30522,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
        )

        val rawBridge = builder.build(
            proxyNodes = emptyList(),
            runtimeContext = runtime,
            connectionMode = com.leikwan.bridge.service.ProxyConnectionMode.SERVICES,
            runtimeOnlyProxy = true,
        ).configJson
        Libbox.checkConfig(rawBridge)
        val rawRoot = JsonParser.parseString(rawBridge).asJsonObject
        val rawBridgeRule = rawRoot.getAsJsonObject("route").getAsJsonArray("rules")
            .map { it.asJsonObject }
            .first { it.get("override_port")?.asInt == 30522 }
        assertEquals("127.0.0.1", rawBridgeRule.get("override_address").asString)
        assertEquals("proxy", rawRoot.getAsJsonObject("route").get("final").asString)

        val selectedProtocolNode = ProxyNode(
            id = 7L,
            name = "selected-target-adapter",
            type = "ss",
            originalServer = "198.51.100.7",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"test-password"}""",
            forwardId = 90L,
            enabled = true,
        )
        val protocolBridge = builder.build(
            proxyNodes = listOf(selectedProtocolNode),
            runtimeContext = runtime,
            connectionMode = com.leikwan.bridge.service.ProxyConnectionMode.SERVICES,
            runtimeOnlyProxy = true,
        ).configJson
        Libbox.checkConfig(protocolBridge)
        val protocolRoot = JsonParser.parseString(protocolBridge).asJsonObject
        val protocolOutbound = protocolRoot.getAsJsonArray("outbounds")
            .map { it.asJsonObject }
            .first { it.get("tag")?.asString == "proxy-7" }
        assertEquals("127.0.0.1", protocolOutbound.get("server").asString)
        assertEquals(30522, protocolOutbound.get("server_port").asInt)
        assertEquals("proxy-7", protocolRoot.getAsJsonObject("route").get("final").asString)
        assertTrue(
            protocolRoot.getAsJsonArray("outbounds").count { it.asJsonObject.get("tag")?.asString?.startsWith("proxy-") == true } == 1,
        )
    }
}
