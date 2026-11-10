package com.leikwan.bridge.routing

import org.junit.Test
import org.junit.Assert.*

class ClashRuleParserTest {
    private val catalogTags = setOf(
        "geosite-google",
        "geosite-youtube",
        "geosite-openai",
        "geosite-telegram",
        "geosite-cn",
        "geolocation-cn",
        "geoip-cn",
    )

    @Test
    fun testValidDomainRule() {
        val parsed = ClashRuleParser.parse("DOMAIN,example.com", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertNotNull(line.rule)
        assertEquals(RoutingRuleKind.DOMAIN, line.rule?.kind)
        assertEquals("example.com", line.rule?.matchValue)
        assertEquals(UserTrafficOutcome.TUNNEL, line.rule?.outcome)
    }

    @Test
    fun testValidDomainSuffixRule() {
        val parsed = ClashRuleParser.parse("DOMAIN-SUFFIX,google.com", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.DOMAIN_SUFFIX, line.rule?.kind)
        assertEquals("google.com", line.rule?.matchValue)
    }

    @Test
    fun testValidIpCidrRule() {
        val parsed = ClashRuleParser.parse("IP-CIDR,192.168.1.0/24", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.IP_CIDR, line.rule?.kind)
        assertEquals("192.168.1.0/24", line.rule?.matchValue)
    }

    @Test
    fun testValidIpCidr6Rule() {
        val parsed = ClashRuleParser.parse("IP-CIDR6,2001:db8::/32", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.IP_CIDR6, line.rule?.kind)
    }

    @Test
    fun testValidDstPortRule() {
        val parsed = ClashRuleParser.parse("DST-PORT,443", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.DST_PORT, line.rule?.kind)
        assertEquals("443", line.rule?.matchValue)
    }

    @Test
    fun testValidPortRangeRule() {
        val parsed = ClashRuleParser.parse("DST-PORT,8000:9000", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals("8000:9000", line.rule?.matchValue)
    }

    @Test
    fun testValidGeositRule() {
        val parsed = ClashRuleParser.parse("GEOSITE,geosite-google", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.GEOSITE, line.rule?.kind)
        assertEquals("geosite-google", line.rule?.matchValue)
    }

    @Test
    fun testThreeFieldRuleWithDirect() {
        val parsed = ClashRuleParser.parse("DOMAIN,example.com,DIRECT", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(UserTrafficOutcome.DIRECT, line.rule?.outcome)
    }

    @Test
    fun testThreeFieldRuleWithReject() {
        val parsed = ClashRuleParser.parse("DOMAIN,ad.com,REJECT", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(UserTrafficOutcome.REJECT, line.rule?.outcome)
    }

    @Test
    fun testThreeFieldRuleWithProxy() {
        val parsed = ClashRuleParser.parse("DOMAIN,example.com,PROXY", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(UserTrafficOutcome.TUNNEL, line.rule?.outcome) // PROXY -> TUNNEL
    }

    @Test
    fun testCaseInsensitiveType() {
        val parsed = ClashRuleParser.parse("domain,example.com", catalogTags)
        assertEquals(1, parsed.size)
        assertTrue(parsed[0].isOk)
    }

    @Test
    fun testBlankLineSkipped() {
        val parsed = ClashRuleParser.parse("  \n DOMAIN,example.com\n\n", catalogTags)
        assertEquals(1, parsed.size) // Only the DOMAIN line
        assertTrue(parsed[0].isOk)
    }

    @Test
    fun testCommentLineSkipped() {
        val parsed = ClashRuleParser.parse("# Comment\nDOMAIN,example.com\n// Another comment", catalogTags)
        assertEquals(1, parsed.size)
        assertTrue(parsed[0].isOk)
    }

    @Test
    fun testGeoipCountryAlias() {
        val parsed = ClashRuleParser.parse("GEOIP,cn", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.COUNTRY, line.rule?.kind)
        assertEquals("cn", line.rule?.matchValue)
    }

    @Test
    fun testRuleSetAlias() {
        val parsed = ClashRuleParser.parse("RULE-SET,geosite-google", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.RULE_SET, line.rule?.kind)
    }

    @Test
    fun testUnsupportedTypeError() {
        val parsed = ClashRuleParser.parse("IP-ASN,12345", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(!line.isOk)
        assertTrue(line.error?.contains("暂不支持") ?: false)
    }

    @Test
    fun testInvalidGeositTagError() {
        val parsed = ClashRuleParser.parse("GEOSITE,unknown-tag", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(!line.isOk)
        assertTrue(line.error?.contains("未收录") ?: false)
    }

    @Test
    fun testInvalidCidrError() {
        val parsed = ClashRuleParser.parse("IP-CIDR,not.a.cidr", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(!line.isOk)
    }

    @Test
    fun testInvalidPortError() {
        val parsed = ClashRuleParser.parse("DST-PORT,70000", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(!line.isOk)
    }

    @Test
    fun testInvalidRegexError() {
        val parsed = ClashRuleParser.parse("DOMAIN-REGEX,[invalid(regex", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(!line.isOk)
    }

    @Test
    fun testMatchRuleError() {
        val parsed = ClashRuleParser.parse("MATCH,PROXY", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(!line.isOk)
        assertTrue(line.error?.contains("MATCH") ?: false)
    }

    @Test
    fun testDuplicateDetection() {
        val parsed = ClashRuleParser.parse(
            "DOMAIN,example.com\nDOMAIN,example.com",
            catalogTags
        )
        assertEquals(2, parsed.size)
        assertTrue(parsed[0].isOk)
        assertTrue(!parsed[1].isOk)
        assertTrue(parsed[1].error?.contains("重复") ?: false)
    }

    @Test
    fun testMultipleRulesOkAndErrors() {
        val input = """
            DOMAIN,example.com
            DOMAIN,example.com
            GEOSITE,unknown-tag
            IP-CIDR,192.168.0.0/16
        """.trimIndent()
        val parsed = ClashRuleParser.parse(input, catalogTags)
        assertEquals(4, parsed.size)
        assertTrue(parsed[0].isOk)
        assertTrue(!parsed[1].isOk) // Duplicate
        assertTrue(!parsed[2].isOk) // Unknown tag
        assertTrue(parsed[3].isOk)
    }

    @Test
    fun testDomainRegex() {
        val parsed = ClashRuleParser.parse("DOMAIN-REGEX,^example\\..*", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.DOMAIN_REGEX, line.rule?.kind)
    }

    @Test
    fun testDomainKeyword() {
        val parsed = ClashRuleParser.parse("DOMAIN-KEYWORD,google", catalogTags)
        assertEquals(1, parsed.size)
        val line = parsed[0]
        assertTrue(line.isOk)
        assertEquals(RoutingRuleKind.DOMAIN_KEYWORD, line.rule?.kind)
    }
}
