package com.leikwan.bridge.ui

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Resource contract for the app's locales: every shipped strings.xml carries the same key set and
 * the per-app locale config advertises exactly the locales the pickers offer (zh-CN, zh-HK, en).
 */
class LocaleResourceParityTest {
    private val root: File = File(requireNotNull(System.getProperty("user.dir")), "src/main/res")

    private fun keys(localeDir: String): Set<String> {
        val text = File(root, "$localeDir/strings.xml").readText()
        return Regex("<string name=\"([^\"]+)\"").findAll(text).map { it.groupValues[1] }.toSet()
    }

    @Test
    fun `every shipped locale carries the same string keys`() {
        val defaultKeys = keys("values")
        assertTrue("default strings.xml must not be empty", defaultKeys.isNotEmpty())
        assertEquals("zh-CN key set", defaultKeys, keys("values-zh-rCN"))
        assertEquals("zh-HK key set", defaultKeys, keys("values-zh-rHK"))
    }

    @Test
    fun `zh-HK strings are Traditional and not a copy of zh-CN`() {
        val simplified = File(root, "values-zh-rCN/strings.xml").readText()
        val hongKong = File(root, "values-zh-rHK/strings.xml").readText()
        assertNotEquals(simplified, hongKong)
        // The picker label is written by hand in both locales; the body must be Traditional.
        assertTrue(hongKong.contains("language\">語言"))
        assertTrue(hongKong.contains("app_name\">雷寬橋"))
    }

    @Test
    fun `locale config advertises exactly the locales the pickers offer`() {
        val config = File(root, "xml/locales_config.xml").readText()
        assertEquals(
            listOf("zh-CN", "zh-HK", "en"),
            Regex("android:name=\"([^\"]+)\"").findAll(config).map { it.groupValues[1] }.toList(),
        )
    }

    @Test
    fun `both language pickers offer the three locales with distinct labels`() {
        val defaultText = File(root, "values/strings.xml").readText()
        for (key in listOf("language", "language_zh", "language_zh_hk", "language_en", "settings_language_system")) {
            assertTrue("missing $key in default locale", defaultText.contains("name=\"$key\""))
        }
        for (picker in listOf(
            "src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt",
            "src/main/java/com/leikwan/bridge/ui/shared/AppearanceControls.kt",
        )) {
            val source = File(picker).readText()
            assertTrue("$picker must offer zh-CN", source.contains("\"zh-CN\""))
            assertTrue("$picker must offer zh-HK", source.contains("\"zh-HK\""))
            assertTrue("$picker must offer en", source.contains("\"en\""))
            assertTrue(
                "$picker must not fold every zh* locale into zh-CN",
                !source.contains("startsWith(\"zh\""),
            )
        }
    }
}
