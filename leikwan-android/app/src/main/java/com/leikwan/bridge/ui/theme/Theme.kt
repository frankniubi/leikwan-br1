package com.leikwan.bridge.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val DarkColorScheme = darkColorScheme(
    primary = Color(0xFF4A6CF7),
    onPrimary = Color.White,
    primaryContainer = Color(0xFF3451C7),
    surface = Color(0xFF0F1117),
    surfaceVariant = Color(0xFF1A1D27),
    background = Color(0xFF0F1117),
    onBackground = Color(0xFFE0E0E0),
    onSurface = Color(0xFFE0E0E0),
    onSurfaceVariant = Color(0xFF9CA3AF),
    error = Color(0xFFEF4444),
    outline = Color(0xFF2A2D37),
    surfaceContainerHighest = Color(0xFF1E2130),
)

@Composable
fun LeikwanTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = DarkColorScheme,
        content = content,
    )
}
