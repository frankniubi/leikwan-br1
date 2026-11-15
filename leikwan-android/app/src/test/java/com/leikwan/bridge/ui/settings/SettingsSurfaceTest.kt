package com.leikwan.bridge.ui.settings

import java.io.File
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The Settings > Advanced surface must not duplicate the Routing product surface: the legacy
 * bypass-LAN / bypass-China switches were removed from Settings, while the persisted preferences
 * and the sing-box compiler keep honoring values saved by older builds.
 */
class SettingsSurfaceTest {
    private val root: File = File(requireNotNull(System.getProperty("user.dir")))

    private fun source(path: String): String = File(root, path).readText()

    @Test
    fun `settings no longer renders the legacy bypass switches`() {
        val screen = source("src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt")
        val viewModel = source("src/main/java/com/leikwan/bridge/ui/settings/SettingsViewModel.kt")
        for (banned in listOf(
            "settings_bypass_lan_ip",
            "settings_bypass_china",
            "onUpdateBypassLan",
            "onUpdateBypassChina",
            "updateBypassLan",
            "updateBypassChina",
            "SwitchLine",
        )) {
            assertTrue("SettingsScreen must not reference $banned", !screen.contains(banned))
            assertTrue("SettingsViewModel must not reference $banned", !viewModel.contains(banned))
        }
    }

    @Test
    fun `saved bypass preferences stay honored by the config compiler`() {
        val prefs = source("src/main/java/com/leikwan/bridge/data/Prefs.kt")
        val builder = source("src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt")
        assertTrue("Prefs must keep the bypassLan preference", prefs.contains("var bypassLan"))
        assertTrue("Prefs must keep the bypassChina preference", prefs.contains("var bypassChina"))
        assertTrue(
            "SingboxConfigBuilder must keep compiling bypassChina",
            builder.contains("settings.bypassChina"),
        )
    }

    @Test
    fun `no locale still ships the removed switch strings`() {
        for (locale in listOf("values", "values-zh-rCN", "values-zh-rHK")) {
            val text = source("src/main/res/$locale/strings.xml")
            assertTrue("$locale must not ship settings_bypass_lan_ip", !text.contains("settings_bypass_lan_ip"))
            assertTrue("$locale must not ship settings_bypass_china", !text.contains("settings_bypass_china"))
            assertTrue("$locale must keep the custom bypass field", text.contains("settings_custom_bypass"))
        }
    }
}
