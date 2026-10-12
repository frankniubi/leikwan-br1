package com.leikwan.bridge.routing

import com.google.gson.JsonParser
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.service.TunOptionSnapshot
import com.leikwan.bridge.service.TunSetupPlanner
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import com.leikwan.bridge.singbox.SingboxSettings
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

class AppRoutingTest {
    private val owner = BuildConfig.APPLICATION_ID
    private val netflix = "com.netflix.mediaclient"
    private val chatgpt = "com.openai.chatgpt"
    private val bank = "com.example.bank"

    private fun engine() = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "mirror.example.com"),
        RoutingCategoryCatalog.default(),
    )

    @Test
    fun `all-apps scope excludes bypassed apps and emits in-VPN rules`() {
        val config = AppRoutingConfig()
            .with(netflix, AppPolicy.TUNNEL)
            .with(chatgpt, AppPolicy.TUNNEL, targetNodeId = 7L)
            .with(bank, AppPolicy.BYPASS)
            .with(owner, AppPolicy.BYPASS)

        assertEquals(emptyList<String>(), config.includePackages(owner))
        assertEquals(listOf(bank), config.excludePackages(owner))
        assertEquals(
            listOf(
                AppRoutingRule(netflix, UserTrafficOutcome.TUNNEL),
                AppRoutingRule(chatgpt, UserTrafficOutcome.TUNNEL, 7L),
            ),
            config.routingRules(),
        )
        assertEquals(AppPolicy.FOLLOW, config.policyFor("com.example.unlisted"))
    }

    @Test
    fun `selected-apps scope lists only in-VPN apps and never this app`() {
        val config = AppRoutingConfig(scope = AppVpnScope.SELECTED_APPS)
            .with(netflix, AppPolicy.FOLLOW)
            .with(chatgpt, AppPolicy.DIRECT)
            .with(bank, AppPolicy.BYPASS)
            .with(owner, AppPolicy.FOLLOW)

        assertEquals(listOf(netflix, chatgpt), config.includePackages(owner))
        assertEquals(emptyList<String>(), config.excludePackages(owner))
        assertEquals(AppPolicy.BYPASS, config.policyFor("com.example.unlisted"))
        // Setting an app back to the scope default drops its row.
        assertFalse(config.with(chatgpt, AppPolicy.BYPASS).entries.any { it.packageName == chatgpt })
    }

    @Test
    fun `the stored config survives a round trip and rejects junk rows`() {
        val config = AppRoutingConfig(scope = AppVpnScope.SELECTED_APPS)
            .with(netflix, AppPolicy.TUNNEL, 3L)
            .with(bank, AppPolicy.DIRECT)
        assertEquals(config, AppRoutingConfig.fromJson(config.toJson()))

        val junk = """{"scope":"NOPE","entries":[{"package":"not a package","policy":"TUNNEL"},
            |{"package":"com.ok.app","policy":"DIRECT"},{"policy":"DIRECT"}]}""".trimMargin()
        val parsed = AppRoutingConfig.fromJson(junk)
        assertEquals(AppVpnScope.ALL_APPS, parsed.scope)
        assertEquals(listOf("com.ok.app"), parsed.entries.map { it.packageName })
        assertEquals(AppRoutingConfig(), AppRoutingConfig.fromJson("{not json"))
    }

    @Test
    fun `app rules come first and group by outbound`() {
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(RoutingRule("cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT)),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 0L,
            appRules = listOf(
                AppRoutingRule(netflix, UserTrafficOutcome.TUNNEL),
                AppRoutingRule("com.disney.disneyplus", UserTrafficOutcome.TUNNEL),
                AppRoutingRule(bank, UserTrafficOutcome.DIRECT),
                AppRoutingRule(chatgpt, UserTrafficOutcome.TUNNEL, 7L),
            ),
        )
        val rules = engine().compileRouteRules(snapshot)

        assertEquals(listOf(netflix, "com.disney.disneyplus"), rules[0].packageNames)
        assertEquals(RoutingPolicyEngine.PROXY_OUTBOUND_SENTINEL, rules[0].outbound)
        assertEquals(listOf(bank), rules[1].packageNames)
        assertEquals("direct", rules[1].outbound)
        assertEquals(listOf(chatgpt), rules[2].packageNames)
        assertEquals(RoutingPolicyEngine.nodeSentinel(7L), rules[2].outbound)
        assertTrue("country rules follow the app rules", rules[3].ruleSetTags.isNotEmpty())
    }

    @Test
    fun `the sing-box config carries package rules and the per-app scope`() {
        val config = AppRoutingConfig()
            .with(chatgpt, AppPolicy.TUNNEL)
            .with(bank, AppPolicy.BYPASS)
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
                appRouting = config,
            ),
        )
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = emptyList(),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 0L,
            appRules = config.routingRules(),
        )
        val node = ProxyNode(
            id = 1,
            name = "mine",
            type = "ss",
            originalServer = "remote.example.com",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
            forwardId = null,
        )
        val runtime = RuntimeContext(
            forwardId = 26L,
            mode = ForwardMode.SINGLE,
            localListenPort = 11024,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
        )
        val root = JsonParser.parseString(builder.build(listOf(node), runtime, snapshot).configJson).asJsonObject

        val tun = root.getAsJsonArray("inbounds").first().asJsonObject
        assertEquals(listOf(bank), tun.getAsJsonArray("exclude_package").map { it.asString })
        assertFalse(tun.has("include_package"))
        val packageRule = root.getAsJsonObject("route").getAsJsonArray("rules")
            .map(com.google.gson.JsonElement::getAsJsonObject)
            .first { it.has("package_name") }
        assertEquals(listOf(chatgpt), packageRule.getAsJsonArray("package_name").map { it.asString })
        assertEquals("proxy-1", packageRule.get("outbound").asString)
    }

    @Test
    fun `the TUN plan never mixes allowed and disallowed apps`() {
        val base = TunOptionSnapshot(
            autoRoute = true,
            strictRoute = true,
            inet4Addresses = emptyList(),
            inet6Addresses = emptyList(),
            inet4Routes = emptyList(),
            inet6Routes = emptyList(),
            dnsServer = null,
        )
        val selected = TunSetupPlanner.plan(base.copy(includePackages = listOf(netflix, owner)), ownerPackageName = owner)
        assertEquals(listOf(netflix), selected.allowedPackageNames)
        assertEquals(emptyList<String>(), selected.disallowedPackageNames)

        val excluded = TunSetupPlanner.plan(base.copy(excludePackages = listOf(bank)), ownerPackageName = owner)
        assertEquals(emptyList<String>(), excluded.allowedPackageNames)
        assertEquals(listOf(owner, bank), excluded.disallowedPackageNames)
    }

    @Test
    fun `every preset app is visible to the package manager`() {
        // Android 11+ hides packages the manifest does not query.
        val manifest = File(requireNotNull(System.getProperty("user.dir")), "src/main/AndroidManifest.xml").readText()
        AppPresetCatalog.allPackages.forEach { packageName ->
            assertTrue("$packageName missing from <queries>", manifest.contains("<package android:name=\"$packageName\" />"))
        }
    }

}
