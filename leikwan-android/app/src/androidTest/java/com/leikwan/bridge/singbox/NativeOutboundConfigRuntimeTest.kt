package com.leikwan.bridge.singbox

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.SetupOptions
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
}
