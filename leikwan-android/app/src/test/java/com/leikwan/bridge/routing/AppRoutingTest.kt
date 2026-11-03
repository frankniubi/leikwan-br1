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
    fun `an app belongs to one group and the group pins its node`() {
        val tokyo = AppGroup(id = "a", name = "A", forwardId = 256L, nodeId = 2L, packages = listOf(netflix, chatgpt))
        val hongKong = AppGroup(id = "b", name = "B", forwardId = 261L, nodeId = 3L, packages = listOf(bank))
        val config = AppRoutingConfig(scope = AppVpnScope.SELECTED_APPS)
            .with(chatgpt, AppPolicy.DIRECT)
            .withGroup(tokyo)
            .withGroup(hongKong)
            // Moving an app takes it out of its previous group.
            .withGroupMember(chatgpt, "b")

        assertEquals(listOf(netflix), config.groups.first { it.id == "a" }.packages)
        assertEquals(listOf(bank, chatgpt), config.groups.first { it.id == "b" }.packages)
        assertTrue("joining a group drops the app's own row", config.entries.isEmpty())
        assertEquals(AppPolicy.TUNNEL, config.policyFor(bank))
        assertEquals(listOf(netflix, bank, chatgpt), config.includePackages(owner))
        assertEquals(
            listOf(
                AppRoutingRule(netflix, UserTrafficOutcome.TUNNEL, 2L),
                AppRoutingRule(bank, UserTrafficOutcome.TUNNEL, 3L),
                AppRoutingRule(chatgpt, UserTrafficOutcome.TUNNEL, 3L),
            ),
            config.routingRules(),
        )

        // Giving an app its own policy takes it out of the group; deleting a group frees its apps.
        val direct = config.with(bank, AppPolicy.DIRECT)
        assertEquals(listOf(chatgpt), direct.groups.first { it.id == "b" }.packages)
        assertEquals(AppPolicy.BYPASS, config.withoutGroup("a").policyFor(netflix))
    }

    @Test
    fun `groups survive a round trip and junk groups are dropped`() {
        val config = AppRoutingConfig()
            .with(bank, AppPolicy.DIRECT)
            .withGroup(AppGroup(id = "a", name = "A", forwardId = 256L, nodeId = 2L, packages = listOf(netflix)))
        assertEquals(config, AppRoutingConfig.fromJson(config.toJson()))

        val junk = """{"scope":"ALL_APPS","entries":[{"package":"$bank","policy":"DIRECT"}],"groups":[
            |{"id":"a","name":"A","forward_id":256,"node_id":2,"packages":["$netflix","$bank","bad pkg"]},
            |{"id":"b","name":"B","forward_id":0,"node_id":2,"packages":["$chatgpt"]},
            |{"id":"c","name":"C","forward_id":261,"node_id":3,"packages":["$netflix","$chatgpt"]}]}""".trimMargin()
        val parsed = AppRoutingConfig.fromJson(junk)
        assertEquals(listOf("a", "c"), parsed.groups.map { it.id })
        // The bank keeps its own row; Netflix stays in the first group that lists it.
        assertEquals(listOf(netflix), parsed.groups[0].packages)
        assertEquals(listOf(chatgpt), parsed.groups[1].packages)
        // Configs written before groups existed still load.
        assertEquals(emptyList<AppGroup>(), AppRoutingConfig.fromJson("""{"scope":"ALL_APPS","entries":[]}""").groups)
    }

    @Test
    fun `an Ultra config sends each group to its own line and keeps the selected node as default`() {
        val group = AppGroup(id = "a", name = "A", forwardId = 256L, nodeId = 2L, packages = listOf(netflix))
        val config = AppRoutingConfig().withGroup(group)
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
        fun node(id: Long, server: String) = ProxyNode(
            id = id,
            name = "node-$id",
            type = "ss",
            originalServer = server,
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
            forwardId = null,
        )
        fun leg(nodeId: Long, forwardId: Long, port: Int) = com.leikwan.bridge.runtime.NodeLeg(
            nodeId = nodeId,
            forwardId = forwardId,
            processKey = forwardId,
            mode = ForwardMode.ULTRA,
            localListenPort = port,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
        )
        val runtime = RuntimeContext(
            forwardId = 261L,
            mode = ForwardMode.ULTRA,
            localListenPort = 10016,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
            nodeLegs = linkedMapOf(1L to leg(1L, 261L, 10016), 2L to leg(2L, 256L, 10001)),
        )
        val root = JsonParser.parseString(
            builder.build(
                listOf(node(1L, "hk.example.com"), node(2L, "tokyo.example.com")),
                runtime,
                snapshot,
                runtimeOnlyProxy = true,
            ).configJson,
        ).asJsonObject

        val outbounds = root.getAsJsonArray("outbounds").map { it.asJsonObject }.associateBy { it.get("tag").asString }
        assertEquals(10016, outbounds.getValue("proxy-1").get("server_port").asInt)
        assertEquals(10001, outbounds.getValue("proxy-2").get("server_port").asInt)
        assertEquals("proxy-1", outbounds.getValue("proxy").get("default").asString)
        val packageRule = root.getAsJsonObject("route").getAsJsonArray("rules")
            .map(com.google.gson.JsonElement::getAsJsonObject)
            .first { it.has("package_name") }
        assertEquals(listOf(netflix), packageRule.getAsJsonArray("package_name").map { it.asString })
        assertEquals("proxy-2", packageRule.get("outbound").asString)
        assertEquals("proxy", root.getAsJsonObject("route").get("final").asString)
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
