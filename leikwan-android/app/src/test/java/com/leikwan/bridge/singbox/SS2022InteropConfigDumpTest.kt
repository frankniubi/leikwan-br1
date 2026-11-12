package com.leikwan.bridge.singbox

import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.service.ProxyConnectionMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.nio.file.Files

/**
 * Dumps the sing-box client config the SS2022 interop workflow
 * (.github/workflows/ss2022-interop.yml) runs against real servers:
 *
 *  - `android-ss2022-overlong.json`: a node whose stored iPSK decodes to 47 bytes. sing-box
 *    servers hash over-long keys (SHA256 truncated) while the strict sing-box client library
 *    rejects them with `bad key length`, so the builder must emit the normalized key
 *    base64(SHA256(decoded)[:32]) for the handshake to succeed.
 *
 * tools/ss2022-interop/harness.py rewrites only the inbound and the server address (never the
 * password) and proves a real connection round-trips.
 */
class SS2022InteropConfigDumpTest {

    @Test
    fun `dump ss2022 interop client config with over-long key`() {
        val node = ProxyNode(
            id = 60,
            name = "ss2022-overlong",
            type = "ss",
            originalServer = "127.0.0.1",
            originalPort = 18388,
            configJson = """{"method":"2022-blake3-aes-256-gcm","password":"VGhpcyBpcyBhIG11Y2ggbG9uZ2VyIGtleSB0aGF0IGV4Y2VlZHMgMzIgYnl0ZXM:dXNlci11UFNLLTAxMjM0NTY3ODlhYmNkZWYwMTIzNDU"}""",
            enabled = true,
        )

        val compiled = SingboxConfigBuilder(settings = baseSettings()).build(
            proxyNodes = listOf(node),
            runtimeContext = null,
            connectionMode = ProxyConnectionMode.DIRECT,
        )
        val config = JsonParser.parseString(compiled.configJson).asJsonObject
        val outbound = config.getAsJsonArray("outbounds")
            .map { it.asJsonObject }
            .single { it.get("tag").asString == "proxy-60" }
        assertEquals("shadowsocks", outbound.get("type").asString)
        assertEquals("2022-blake3-aes-256-gcm", outbound.get("method").asString)
        // The stored iPSK (47 decoded bytes) must come out as base64(SHA256(decoded)[:32]),
        // padded; the exact-length uPSK passes through unchanged.
        assertEquals(
            "ahzdXvlNZcEOt6YAg8YPZa5ouTl+KJRvtXPaH1ayyAA=:dXNlci11UFNLLTAxMjM0NTY3ODlhYmNkZWYwMTIzNDU=",
            outbound.get("password").asString,
        )
        val password = outbound.get("password").asString
        assertTrue("normalized iPSK must be 44 chars", password.split(":")[0].length == 44)

        val outDir = System.getenv("SS2022_INTEROP_OUT")?.takeIf { it.isNotBlank() }?.let(::File)
            ?: Files.createTempDirectory("ss2022-interop").toFile()
        outDir.mkdirs()
        val target = File(outDir, "android-ss2022-overlong.json")
        target.writeText(compiled.configJson, Charsets.UTF_8)
        println("ss2022-interop: wrote ${target.absolutePath}")
    }

    private fun baseSettings() = SingboxSettings(
        panelUrl = "https://panel.example.com",
        dnsServer = "1.1.1.1",
        dnsFallback = "8.8.8.8",
        dnsStrategy = "prefer_ipv4",
        bypassLan = true,
        bypassChina = false,
        customBypassRules = "",
    )
}
