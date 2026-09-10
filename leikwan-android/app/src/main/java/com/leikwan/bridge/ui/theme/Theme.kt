package com.leikwan.bridge.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val LightColorScheme = lightColorScheme(
    primary = Color(0xFF1D4ED8),
    onPrimary = Color.White,
    primaryContainer = Color(0xFFDCE6FF),
    onPrimaryContainer = Color(0xFF001A41),
    secondary = Color(0xFF006A6A),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFF9CF1EF),
    onSecondaryContainer = Color(0xFF002020),
    surface = Color(0xFFFAFAFC),
    surfaceVariant = Color(0xFFE8ECF2),
    background = Color(0xFFFAFAFC),
    onBackground = Color(0xFF111827),
    onSurface = Color(0xFF111827),
    onSurfaceVariant = Color(0xFF4B5563),
    error = Color(0xFFB3261E),
    onError = Color.White,
    errorContainer = Color(0xFFF9DEDC),
    onErrorContainer = Color(0xFF410E0B),
    outline = Color(0xFF6B7280),
    outlineVariant = Color(0xFFD1D5DB),
    surfaceContainerHighest = Color(0xFFE5E7EB),
)

@Composable
fun LeikwanTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = LightColorScheme,
        content = content,
    )
}
