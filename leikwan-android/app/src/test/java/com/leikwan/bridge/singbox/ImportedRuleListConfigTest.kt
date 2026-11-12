package com.leikwan.bridge.singbox

import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.routing.ImportedRuleListStore
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.SurgeListConverter
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.service.ProxyConnectionMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.nio.file.Files

/** An imported Surge list becomes a local source rule-set referenced by one route rule. */
class ImportedRuleListConfigTest {

    @Test
    fun `imported list is a local source rule-set routed to its outcome`() {
        val dir = Files.createTempDirectory("rule-lists").toFile()
        val store = ImportedRuleListStore(dir)
        val conversion = SurgeListConverter.parse("DOMAIN-SUFFIX,baidu.com\nIP-CIDR,1.0.1.0/24")
        val list = store.save("ChinaMax", "https://example.invalid/ChinaMax.list", conversion, nowMs = 1L)
        val path = requireNotNull(store.pathFor(list.id))

        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = emptyList(),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 0L,
            clashRules = listOf(
                RoutingRule(id = "list-${list.id}", kind = RoutingRuleKind.IMPORTED_LIST, matchValue = path, outcome = UserTrafficOutcome.DIRECT, enabled = true),
            ),
        )
        assertTrue(snapshot.warnings.toString(), snapshot.warnings.isEmpty())

        val config = JsonParser.parseString(
            SingboxConfigBuilder(settings = baseSettings()).build(
                proxyNodes = listOf(socksNode()),
                runtimeContext = null,
                routingPolicySnapshot = snapshot,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson,
        ).asJsonObject
        val route = config.getAsJsonObject("route")
        val tag = "user-list-${list.id}"
        val ruleSet = route.getAsJsonArray("rule_set").map { it.asJsonObject }.single { it.get("tag").asString == tag }
        assertEquals("local", ruleSet.get("type").asString)
        assertEquals("source", ruleSet.get("format").asString)
        assertEquals(path, ruleSet.get("path").asString)
        assertNull(ruleSet.get("url"))
        val rule = route.getAsJsonArray("rules").map { it.asJsonObject }
            .single { it.getAsJsonArray("rule_set")?.any { t -> t.asString == tag } == true }
        assertEquals("direct", rule.get("outbound").asString)

        // The file is exactly what the converter produced.
        val body = JsonParser.parseString(store.fileFor(list.id).readText()).asJsonObject
        assertEquals(2, body.get("version").asInt)
        assertEquals("baidu.com", body.getAsJsonArray("rules")[0].asJsonObject.getAsJsonArray("domain_suffix")[0].asString)
    }

    @Test
    fun `a list whose file is gone is dropped with a warning`() {
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = emptyList(),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 0L,
            clashRules = listOf(
                RoutingRule(id = "list-gone", kind = RoutingRuleKind.IMPORTED_LIST, matchValue = "", outcome = UserTrafficOutcome.DIRECT, enabled = true),
            ),
        )
        assertTrue(snapshot.userClashRules.isEmpty())
        assertFalse(snapshot.warnings.isEmpty())
    }

    @Test
    fun `store round trips, refreshes in place and deletes the file`() {
        val dir = Files.createTempDirectory("rule-lists").toFile()
        val store = ImportedRuleListStore(dir)
        val first = store.save("", "https://example.invalid/Foo.list", SurgeListConverter.parse("DOMAIN,a.com"), nowMs = 1L)
        assertEquals("Foo.list", first.name)
        assertTrue(ImportedRuleListStore.isValidId(first.id))

        val refreshed = store.save(first.name, first.sourceUrl, SurgeListConverter.parse("DOMAIN,a.com\nDOMAIN,b.com"), nowMs = 2L, existingId = first.id)
        assertEquals(first.id, refreshed.id)
        assertEquals(2, store.get(first.id)?.ruleCount)
        assertEquals(1, store.all().size)

        store.delete(first.id)
        assertTrue(store.all().isEmpty())
        assertFalse(store.fileFor(first.id).exists())
        assertNull(store.pathFor("../escape"))
    }

    private fun engine() = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )

    private fun socksNode() = ProxyNode(
        id = 50,
        name = "socks",
        type = "socks5",
        originalServer = "127.0.0.1",
        originalPort = 1081,
        configJson = """{"username":"user","password":"pass"}""",
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
}
