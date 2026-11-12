package com.leikwan.bridge.singbox

import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.routing.ClashRuleCatalog
import com.leikwan.bridge.routing.ClashRuleParser
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.service.ProxyConnectionMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.nio.file.Files

/**
 * Builds the sing-box configs the routing E2E workflow (.github/workflows/routing-e2e.yml) runs
 * through a real sing-box core: the Clash rules in tools/routing-e2e/rules.txt go through the
 * production parser, policy engine and SingboxConfigBuilder, exactly as the app does, and the
 * resulting JSON is written to $ROUTING_E2E_OUT (android-s1.json = global default TUNNEL,
 * android-s2.json = global default DIRECT). tools/routing-e2e/harness.py then proves at runtime
 * that real connections land on proxy / direct / reject as the rules say.
 */
class RoutingE2eConfigDumpTest {

    private data class ExpectedRule(val key: String, val value: Any, val outbound: String?)

    /** rules.txt, in file order, as sing-box route rules (null outbound = action reject). */
    private val expectedClashRules = listOf(
        ExpectedRule("domain_suffix", listOf("example-proxy.test"), PROXY_TAG),
        ExpectedRule("domain", listOf("direct.test"), "direct"),
        ExpectedRule("domain_keyword", listOf("blockme"), null),
        ExpectedRule("domain_regex", listOf("^rx[0-9]+\\.test$"), "direct"),
        ExpectedRule("ip_cidr", listOf("198.51.100.0/24"), PROXY_TAG),
        ExpectedRule("ip_cidr", listOf("203.0.113.0/24"), null),
        ExpectedRule("ip_cidr", listOf("192.0.2.128/25"), "direct"),
        ExpectedRule("ip_cidr", listOf("2001:db8:1::/48"), PROXY_TAG),
        ExpectedRule("port", listOf(8443), "direct"),
        ExpectedRule("rule_set", listOf("geosite-openai"), "direct"),
        ExpectedRule("rule_set", listOf("geosite-telegram"), null),
    )

    @Test
    fun `dump routing e2e configs built from the clash fixture`() {
        val repoRoot = findRepoRoot()
        val rulesText = File(repoRoot, "tools/routing-e2e/rules.txt").readText(Charsets.UTF_8)
        val parsed = ClashRuleParser.parse(rulesText, ClashRuleCatalog.allowedGeositeTags())
        val errors = parsed.mapNotNull { it.error }
        assertTrue("rules.txt must parse without errors: $errors", errors.isEmpty())
        val clashRules = parsed.map { requireNotNull(it.rule) }
        assertEquals(expectedClashRules.size, clashRules.size)

        val outDir = System.getenv("ROUTING_E2E_OUT")?.takeIf { it.isNotBlank() }?.let(::File)
            ?: Files.createTempDirectory("routing-e2e").toFile()
        outDir.mkdirs()

        val scenarios = linkedMapOf(
            "s1" to UserTrafficOutcome.TUNNEL,
            "s2" to UserTrafficOutcome.DIRECT,
        )
        scenarios.forEach { (scenario, globalDefault) ->
            val snapshot = engine().compile(
                globalDefault = globalDefault,
                globalDefaultNodeId = null,
                countryRules = emptyList(),
                categoryRules = emptyList(),
                rulePackVersions = emptyList(),
                compiledAtMs = 0L,
                clashRules = clashRules,
            )
            assertTrue("no rule may be dropped: ${snapshot.warnings}", snapshot.warnings.isEmpty())
            val compiled = SingboxConfigBuilder(settings = baseSettings()).build(
                proxyNodes = listOf(markerSocksNode()),
                runtimeContext = null,
                routingPolicySnapshot = snapshot,
                connectionMode = ProxyConnectionMode.DIRECT,
            )
            assertEquals(PROXY_TAG, compiled.finalProxyTag)
            val config = JsonParser.parseString(compiled.configJson).asJsonObject
            assertConfigShape(config, if (globalDefault == UserTrafficOutcome.TUNNEL) PROXY_TAG else "direct")

            val target = File(outDir, "android-$scenario.json")
            target.writeText(compiled.configJson, Charsets.UTF_8)
            println("routing-e2e: wrote ${target.absolutePath}")
        }
    }

    private fun assertConfigShape(config: JsonObject, expectedFinal: String) {
        val route = config.getAsJsonObject("route")
        assertEquals(expectedFinal, route.get("final").asString)
        // sing-box 1.14 makes a missing outbound domain resolver fatal at startup.
        assertEquals("direct-dns", route.get("default_domain_resolver")?.asString)

        val proxy = config.getAsJsonArray("outbounds").map { it.asJsonObject }
            .single { it.get("tag").asString == PROXY_TAG }
        assertEquals("socks", proxy.get("type").asString)
        assertEquals(MARKER_HOST, proxy.get("server").asString)
        assertEquals(MARKER_PORT, proxy.get("server_port").asInt)

        // The clash rules appear contiguously and in fixture order.
        val rules = route.getAsJsonArray("rules").map { it.asJsonObject }
        val first = rules.indexOfFirst { matches(it, expectedClashRules.first()) }
        assertTrue("first clash rule missing from route.rules: $rules", first >= 0)
        expectedClashRules.forEachIndexed { offset, expected ->
            val actual = rules.getOrNull(first + offset)
            assertTrue("route.rules[${first + offset}] should be $expected, was $actual", actual != null && matches(actual, expected))
        }

        // Clash geosite rule-sets are emitted as remote sets whose URL basename is <tag>.srs; the
        // harness maps them onto the bundled ios/RuleSets copies.
        val ruleSets = route.getAsJsonArray("rule_set").map { it.asJsonObject }.associateBy { it.get("tag").asString }
        listOf("geosite-openai", "geosite-telegram").forEach { tag ->
            val entry = requireNotNull(ruleSets[tag]) { "route.rule_set lacks $tag: $ruleSets" }
            assertEquals("remote", entry.get("type").asString)
            assertEquals("binary", entry.get("format").asString)
            assertTrue(entry.get("url").asString.endsWith("/$tag.srs"))
        }
    }

    private fun matches(rule: JsonObject, expected: ExpectedRule): Boolean {
        val expectedKeys = setOf(expected.key, if (expected.outbound == null) "action" else "outbound")
        if (rule.keySet() != expectedKeys) return false
        val values = rule.get(expected.key) as? JsonArray ?: return false
        val expectedValues = expected.value as List<*>
        if (values.size() != expectedValues.size) return false
        val valuesMatch = expectedValues.indices.all { i ->
            when (val v = expectedValues[i]) {
                is Int -> values[i].asInt == v
                else -> values[i].asString == v
            }
        }
        if (!valuesMatch) return false
        return if (expected.outbound == null) {
            rule.get("action").asString == "reject"
        } else {
            rule.get("outbound").asString == expected.outbound
        }
    }

    private fun findRepoRoot(): File {
        val start = System.getProperty("user.dir") ?: "."
        var dir: File? = File(start).absoluteFile
        while (dir != null) {
            if (File(dir, "tools/routing-e2e/rules.txt").isFile) return dir
            dir = dir.parentFile
        }
        error("tools/routing-e2e/rules.txt not found above $start")
    }

    /** Mirrors the SOCKS5 fixture in SingboxConfigBuilderTest, pointed at the harness marker. */
    private fun markerSocksNode() = ProxyNode(
        id = 50,
        name = "socks",
        type = "socks5",
        originalServer = MARKER_HOST,
        originalPort = MARKER_PORT,
        configJson = """{"username":"user","password":"pass"}""",
    )

    private fun engine() = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )

    private fun baseSettings() = SingboxSettings(
        panelUrl = "https://panel.example.com",
        dnsServer = "1.1.1.1",
        dnsFallback = "8.8.8.8",
        dnsStrategy = "prefer_ipv4",
        bypassLan = true,
        bypassChina = false,
        customBypassRules = "",
    )

    private companion object {
        const val PROXY_TAG = "proxy-50"
        const val MARKER_HOST = "127.0.0.1"
        const val MARKER_PORT = 1081
    }
}
