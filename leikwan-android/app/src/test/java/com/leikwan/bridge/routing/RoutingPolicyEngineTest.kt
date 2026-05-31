package com.leikwan.bridge.routing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Pure-JVM precedence + compiler tests for the v2 routing core.
 *
 * Enforcement lives in sing-box route.rules; decide() is the canonical, unit-testable statement of
 * precedence (and diagnostics), never the data path. compileRouteRules/requiredRuleSets produce the
 * lists that SingboxConfigBuilder.buildRoute emits.
 */
class RoutingPolicyEngineTest {

    private fun engine(): RoutingPolicyEngine {
        val registry = SafePathRegistry.androidBaseline(
            panelUrl = "https://panel.example.com",
            mirrorHost = "v3-mirror.leikwanhost.com",
        )
        return RoutingPolicyEngine(registry, RoutingCategoryCatalog.default())
    }

    @Test
    fun `protected panel beats a user tunnel country rule`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(
                RoutingRule(
                    id = "country-cn",
                    kind = RoutingRuleKind.COUNTRY,
                    matchValue = "CN",
                    outcome = UserTrafficOutcome.TUNNEL,
                )
            ),
            categoryRules = emptyList(),
            rulePackVersions = listOf("country-category-2026.05.31"),
            compiledAtMs = 1717046400000,
        )

        val decision = engine.decide(
            snapshot = snapshot,
            request = RoutingRequest(
                destinationHost = "panel.example.com",
                destinationIp = null,
                countryCode = "CN",
                websiteCategory = null,
                isBootstrapTraffic = true,
            ),
        )

        assertEquals(UserTrafficOutcome.DIRECT, decision.outcome)
        assertEquals(RoutingDecisionReason.SYSTEM_PROTECTED, decision.reason)
        assertEquals("panel-api", decision.protectedPathId)
    }

    @Test
    fun `user country rule applies when not protected`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(
                RoutingRule("country-cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT)
            ),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )

        val decision = engine.decide(
            snapshot,
            RoutingRequest(
                destinationHost = "example.cn",
                destinationIp = null,
                countryCode = "CN",
                websiteCategory = null,
                isBootstrapTraffic = false,
            ),
        )

        assertEquals(UserTrafficOutcome.DIRECT, decision.outcome)
        assertEquals(RoutingDecisionReason.USER_RULE, decision.reason)
        assertEquals("country-cn", decision.matchedRuleId)
    }

    @Test
    fun `default outcome applies when nothing matches`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.DIRECT,
            globalDefaultNodeId = null,
            countryRules = emptyList(),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )

        val decision = engine.decide(
            snapshot,
            RoutingRequest("nowhere.example", null, "US", "music", false),
        )

        assertEquals(UserTrafficOutcome.DIRECT, decision.outcome)
        assertEquals(RoutingDecisionReason.DEFAULT, decision.reason)
        assertNull(decision.matchedRuleId)
        assertNull(decision.protectedPathId)
    }

    @Test
    fun `compile drops an unsupported rule with a warning`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(
                RoutingRule("bad-country", RoutingRuleKind.COUNTRY, "  ", UserTrafficOutcome.DIRECT),
                RoutingRule("ok-country", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT),
            ),
            categoryRules = listOf(
                RoutingRule("bad-cat", RoutingRuleKind.WEBSITE_CATEGORY, "weather", UserTrafficOutcome.TUNNEL),
                RoutingRule("ok-cat", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.TUNNEL),
            ),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )

        // unsupported rules are dropped, supported ones kept
        assertEquals(listOf("ok-country"), snapshot.userCountryRules.map { it.id })
        assertEquals(listOf("ok-cat"), snapshot.userCategoryRules.map { it.id })
        assertTrue(snapshot.warnings.any { it.contains("bad-country") })
        assertTrue(snapshot.warnings.any { it.contains("bad-cat") })
        assertEquals(1, snapshot.precedenceVersion)
        assertEquals(UserTrafficOutcome.TUNNEL, snapshot.globalDefaultOutcome)
        assertEquals(listOf<String>(), snapshot.activeRulePackVersions)
        assertEquals(1L, snapshot.compileTimestampMs)
    }

    @Test
    fun `disabled rules are dropped from the snapshot`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(
                RoutingRule("off", RoutingRuleKind.COUNTRY, "JP", UserTrafficOutcome.DIRECT, enabled = false),
                RoutingRule("on", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT, enabled = true),
            ),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )
        assertEquals(listOf("on"), snapshot.userCountryRules.map { it.id })
    }

    @Test
    fun `compileRouteRules maps CN-DIRECT to a geoip-cn direct rule`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(
                RoutingRule("country-cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT)
            ),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )

        val compiled = engine.compileRouteRules(snapshot)
        assertEquals(1, compiled.size)
        assertEquals(listOf("geoip-cn"), compiled[0].ruleSetTags)
        assertEquals("direct", compiled[0].outbound)
        assertEquals("country-cn", compiled[0].sourceRuleId)
    }

    @Test
    fun `compileRouteRules maps video-TUNNEL to catalog tags with proxy sentinel`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.DIRECT,
            globalDefaultNodeId = null,
            countryRules = emptyList(),
            categoryRules = listOf(
                RoutingRule("cat-video", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.TUNNEL)
            ),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )

        val compiled = engine.compileRouteRules(snapshot)
        assertEquals(1, compiled.size)
        val expectedTags = RoutingCategoryCatalog.default().ruleSetsFor("video").map { it.tag }
        assertTrue(expectedTags.isNotEmpty())
        assertEquals(expectedTags, compiled[0].ruleSetTags)
        assertEquals("<PROXY>", compiled[0].outbound)
        assertEquals("cat-video", compiled[0].sourceRuleId)
    }

    @Test
    fun `requiredRuleSets dedups overlapping tags`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(
                RoutingRule("cn1", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT),
                RoutingRule("cn2", RoutingRuleKind.COUNTRY, "cn", UserTrafficOutcome.DIRECT),
            ),
            categoryRules = listOf(
                RoutingRule("v1", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.TUNNEL),
                RoutingRule("v2", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.DIRECT),
            ),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )

        val refs = engine.requiredRuleSets(snapshot)
        val tags = refs.map { it.tag }
        // de-duplicated by tag
        assertEquals(tags.toSet().size, tags.size)
        assertTrue(tags.contains("geoip-cn"))
        // a geoip-cn ref uses the sing-geoip URL template
        val geoipRef = refs.first { it.tag == "geoip-cn" }
        assertEquals(
            "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs",
            geoipRef.url,
        )
        // and the video geosite tags are present
        val videoTags = RoutingCategoryCatalog.default().ruleSetsFor("video").map { it.tag }
        assertTrue(tags.containsAll(videoTags))
    }

    @Test
    fun `tunnel category rule with target node compiles to node sentinel`() {
        val engine = engine()
        val snapshot = engine.compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = 7L,
            countryRules = emptyList(),
            categoryRules = listOf(
                RoutingRule(
                    "video",
                    RoutingRuleKind.WEBSITE_CATEGORY,
                    "video",
                    UserTrafficOutcome.TUNNEL,
                    targetNodeId = 3L,
                ),
            ),
            rulePackVersions = emptyList(),
            compiledAtMs = 0L,
        )
        val compiled = engine.compileRouteRules(snapshot)
        assertEquals("<NODE:3>", compiled.single().outbound)
    }

    @Test
    fun `tunnel rule without target node uses default proxy sentinel`() {
        val engine = engine()
        val snapshot = engine.compile(
            UserTrafficOutcome.TUNNEL,
            null,
            emptyList(),
            listOf(
                RoutingRule(
                    "ai",
                    RoutingRuleKind.WEBSITE_CATEGORY,
                    "ai",
                    UserTrafficOutcome.TUNNEL,
                    targetNodeId = null,
                ),
            ),
            emptyList(),
            0L,
        )
        assertEquals("<PROXY>", engine.compileRouteRules(snapshot).single().outbound)
    }

    @Test
    fun `direct rule ignores target node`() {
        val engine = engine()
        val snapshot = engine.compile(
            UserTrafficOutcome.TUNNEL,
            7L,
            emptyList(),
            listOf(
                RoutingRule(
                    "ai",
                    RoutingRuleKind.WEBSITE_CATEGORY,
                    "ai",
                    UserTrafficOutcome.DIRECT,
                    targetNodeId = 9L,
                ),
            ),
            emptyList(),
            0L,
        )
        assertEquals("direct", engine.compileRouteRules(snapshot).single().outbound)
    }
}
