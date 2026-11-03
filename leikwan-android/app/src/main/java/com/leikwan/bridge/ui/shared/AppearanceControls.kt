package com.leikwan.bridge.ui.shared

import androidx.appcompat.app.AppCompatDelegate
import androidx.compose.foundation.layout.Box
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.Palette
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.core.os.LocaleListCompat
import com.leikwan.bridge.R
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.ui.theme.ThemeController
import com.leikwan.bridge.ui.theme.ThemeMode

/** Compact theme picker (system / day / night) for screens without a settings page. */
@Composable
fun ThemeMenuButton() {
    val context = LocalContext.current
    val mode by ThemeController.mode.collectAsState()
    var open by remember { mutableStateOf(false) }
    Box {
        IconButton(onClick = { open = true }) {
            Icon(Icons.Outlined.Palette, contentDescription = stringResource(R.string.settings_theme))
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            listOf(
                ThemeMode.SYSTEM to R.string.theme_system,
                ThemeMode.LIGHT to R.string.theme_day,
                ThemeMode.DARK to R.string.theme_night,
            ).forEach { (option, label) ->
                DropdownMenuItem(
                    text = { Text(stringResource(label)) },
                    trailingIcon = { if (mode == option) Icon(Icons.Filled.Check, contentDescription = null) },
                    onClick = {
                        open = false
                        ThemeController.set(Prefs.get(context), option)
                    },
                )
            }
        }
    }
}

/** Compact language picker (system / 简体中文 / English). */
@Composable
fun LanguageMenuButton() {
    var open by remember { mutableStateOf(false) }
    val current = AppCompatDelegate.getApplicationLocales().toLanguageTags().substringBefore(',')
    Box {
        IconButton(onClick = { open = true }) {
            Icon(Icons.Outlined.Language, contentDescription = stringResource(R.string.language))
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            listOf(
                "" to R.string.settings_language_system,
                "zh-CN" to R.string.language_zh,
                "en" to R.string.language_en,
            ).forEach { (tag, label) ->
                val selected = current.equals(tag, ignoreCase = true) ||
                    (tag == "zh-CN" && current.startsWith("zh", ignoreCase = true))
                DropdownMenuItem(
                    text = { Text(stringResource(label)) },
                    trailingIcon = { if (selected) Icon(Icons.Filled.Check, contentDescription = null) },
                    onClick = {
                        open = false
                        AppCompatDelegate.setApplicationLocales(
                            if (tag.isEmpty()) LocaleListCompat.getEmptyLocaleList()
                            else LocaleListCompat.forLanguageTags(tag),
                        )
                    },
                )
            }
        }
    }
}
