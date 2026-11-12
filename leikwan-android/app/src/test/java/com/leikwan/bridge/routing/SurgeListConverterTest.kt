package com.leikwan.bridge.routing

import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class SurgeListConverterTest {

    @Test
    fun `blackmatrix7 style list merges into compact headless rules`() {
        val text = """
            # NAME: ChinaMax
            # TOTAL: 6
            DOMAIN,www.example.cn
            DOMAIN-SUFFIX,.Baidu.com
            DOMAIN-KEYWORD,taobao
            DOMAIN-REGEX,^ad\d+\.example\.com$
            IP-CIDR,1.0.1.0/24,no-resolve
            IP-CIDR6,2400:da00::/32,no-resolve
            DST-PORT,8000-9000
            DST-PORT,443
        """.trimIndent()

        val result = SurgeListConverter.parse(text)

        assertEquals(8, result.convertedCount)
        assertEquals(0, result.skippedCount)
        val domain = result.rules[0]
        assertEquals("www.example.cn", domain.getAsJsonArray("domain")[0].asString)
        assertEquals("baidu.com", domain.getAsJsonArray("domain_suffix")[0].asString)
        assertEquals("taobao", domain.getAsJsonArray("domain_keyword")[0].asString)
        assertEquals("""^ad\d+\.example\.com$""", domain.getAsJsonArray("domain_regex")[0].asString)
        val cidrs = result.rules[1].getAsJsonArray("ip_cidr").map { it.asString }
        assertEquals(listOf("1.0.1.0/24", "2400:da00::/32"), cidrs)
        assertEquals(443, result.rules[2].getAsJsonArray("port")[0].asInt)
        assertEquals("8000:9000", result.rules[3].getAsJsonArray("port_range")[0].asString)
    }

    @Test
    fun `logical rules become sing-box logical rules and NOT inverts`() {
        val text = """
            AND,((DOMAIN-SUFFIX,example.com),(DST-PORT,443)),DIRECT
            OR,((DOMAIN,a.com),(IP-CIDR,8.8.8.0/24)),PROXY
            NOT,((DOMAIN-KEYWORD,ads)),REJECT
            AND,((OR,((DOMAIN,x.com),(DOMAIN,y.com))),(NOT,((DST-PORT,80)))),DIRECT
        """.trimIndent()

        val result = SurgeListConverter.parse(text)

        assertEquals(4, result.convertedCount)
        val and = result.rules[0]
        assertEquals("logical", and.get("type").asString)
        assertEquals("and", and.get("mode").asString)
        assertEquals("example.com", and.getAsJsonArray("rules")[0].asJsonObject.getAsJsonArray("domain_suffix")[0].asString)
        assertEquals(443, and.getAsJsonArray("rules")[1].asJsonObject.getAsJsonArray("port")[0].asInt)
        assertEquals("or", result.rules[1].get("mode").asString)
        val not = result.rules[2]
        assertEquals("ads", not.getAsJsonArray("domain_keyword")[0].asString)
        assertTrue(not.get("invert").asBoolean)
        val nested = result.rules[3]
        val nestedOr = nested.getAsJsonArray("rules")[0].asJsonObject
        assertEquals("or", nestedOr.get("mode").asString)
        assertTrue(nested.getAsJsonArray("rules")[1].asJsonObject.get("invert").asBoolean)
        assertEquals(mapOf("DIRECT" to 2, "PROXY" to 1, "REJECT" to 1), result.policies)
    }

    @Test
    fun `unsupported types are skipped and counted, never fatal`() {
        val text = """
            PROCESS-NAME,WeChat,DIRECT
            URL-REGEX,^https?://ad\.,REJECT
            IP-ASN,13335
            GEOIP,CN
            USER-AGENT,MicroMessenger*
            AND,((PROCESS-NAME,curl),(DOMAIN,a.com)),DIRECT
            DOMAIN-REGEX,(?=lookahead)
            IP-CIDR,999.1.1.1/24
            DOMAIN,bad domain.com
            DOMAIN,ok.com
            FINAL,PROXY
        """.trimIndent()

        val result = SurgeListConverter.parse(text)

        assertEquals(1, result.convertedCount)
        assertEquals(1, result.skipped["PROCESS-NAME"])
        assertEquals(1, result.skipped["URL-REGEX"])
        assertEquals(1, result.skipped["IP-ASN"])
        assertEquals(1, result.skipped["GEOIP"])
        assertEquals(1, result.skipped["USER-AGENT"])
        assertEquals(1, result.skipped["LOGICAL-UNSUPPORTED"])
        assertEquals(3, result.skipped["INVALID"])
        assertEquals("PROXY", result.finalPolicy)
        assertFalse(result.samples.isEmpty())
    }

    @Test
    fun `clash rule-provider yaml payload and comments are accepted`() {
        val text = "﻿payload:\r\n  - DOMAIN-SUFFIX,google.com\r\n  - 'IP-CIDR,8.8.8.8/32,no-resolve'\r\n  // comment\r\n  ; another\r\n  - \"DOMAIN,mail.google.com\" # trailing\r\n"

        val result = SurgeListConverter.parse(text)

        assertEquals(3, result.convertedCount)
        assertEquals(0, result.skippedCount)
        assertEquals("mail.google.com", result.rules[0].getAsJsonArray("domain")[0].asString)
    }

    @Test
    fun `wildcard and source ip map to regex and source_ip_cidr`() {
        val result = SurgeListConverter.parse("DOMAIN-WILDCARD,*.example.?n\nSRC-IP-CIDR,192.168.1.0/24")

        assertEquals("""^.*\.example\..n$""", result.rules[0].getAsJsonArray("domain_regex")[0].asString)
        assertEquals("192.168.1.0/24", result.rules[1].getAsJsonArray("source_ip_cidr")[0].asString)
        assertTrue(Regex(result.rules[0].getAsJsonArray("domain_regex")[0].asString).matches("a.example.cn"))
    }

    @Test
    fun `bare ip cidr gets a full prefix and duplicates collapse`() {
        val result = SurgeListConverter.parse("IP-CIDR,1.1.1.1\nIP-CIDR,1.1.1.1/32\nIP-CIDR6,::1")

        assertEquals(listOf("1.1.1.1/32", "::1/128"), result.rules[0].getAsJsonArray("ip_cidr").map { it.asString })
    }

    @Test
    fun `rule cap stops conversion and counts the rest`() {
        val text = (1..SurgeListConverter.MAX_RULES + 5).joinToString("\n") { "DOMAIN,d$it.example.com" }

        val result = SurgeListConverter.parse(text)

        assertEquals(SurgeListConverter.MAX_RULES, result.convertedCount)
        assertEquals(5, result.skipped["LIMIT"])
    }

    @Test(expected = SurgeListConverter.TooLargeException::class)
    fun `oversized text is rejected`() {
        SurgeListConverter.parse("D".repeat(SurgeListConverter.MAX_BYTES + 1))
    }

    @Test
    fun `toJson is a version 2 source rule-set`() {
        val json = JsonParser.parseString(SurgeListConverter.toJson(SurgeListConverter.parse("DOMAIN,a.com"))).asJsonObject

        assertEquals(2, json.get("version").asInt)
        assertEquals("a.com", json.getAsJsonArray("rules")[0].asJsonObject.getAsJsonArray("domain")[0].asString)
    }

    @Test
    fun `empty list converts to nothing`() {
        val result = SurgeListConverter.parse("# only comments\n\n")

        assertTrue(result.isEmpty)
        assertNull(result.finalPolicy)
    }
}
