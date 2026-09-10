package com.leikwan.bridge.data.routing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class RulePackManifestTest {

    @Test
    fun `parse validates geosite presets and geoip refs`() {
        val json = """
            {
              "version": "2026.05.31",
              "geosite": [
                {
                  "key": "google",
                  "labelEn": "Google",
                  "labelZh": "谷歌服务",
                  "defaultDirect": false,
                  "tags": ["geosite-google"]
                }
              ],
              "geoip": {
                "version": "2026.05.31",
                "cnTag": "geoip-cn",
                "refs": [
                  {"tag": "geoip-cn", "url": "https://example.invalid/geoip-cn.srs"}
                ]
              }
            }
        """.trimIndent()

        val manifest = RulePackManifest.parse(json)

        assertEquals("2026.05.31", manifest.version)
        assertEquals("google", manifest.geosite.single().key)
        assertEquals("geoip-cn", manifest.geoip.cnTag)
        assertEquals("https://example.invalid/geoip-cn.srs", manifest.geoip.refs.single().url)
    }

    @Test
    fun `parse rejects malformed oversized or missing cn geoip`() {
        assertTrue(RulePackManifest.parseOrNull("{not-json}") == null)
        assertTrue(RulePackManifest.parseOrNull(" ".repeat(1_050_000)) == null)
        assertTrue(
            RulePackManifest.parseOrNull(
                """
                    {
                      "version": "2026.05.31",
                      "geosite": [],
                      "geoip": {"version":"2026.05.31","cnTag":"geoip-cn","refs":[]}
                    }
                """.trimIndent()
            ) == null
        )
    }
}
