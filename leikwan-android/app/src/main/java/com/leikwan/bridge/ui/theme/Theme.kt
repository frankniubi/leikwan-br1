package com.leikwan.bridge.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/*
 * Day and night are one palette: both schemes are Material 3 "fidelity" schemes generated (HCT
 * tonal palettes) from the single brand seed #2563EB, so every role keeps its hue and only the
 * light/dark tone pairing differs. Regenerate both together if the seed ever changes.
 */
private val LightColorScheme = lightColorScheme(
    primary = Color(0xFF004AC6),
    onPrimary = Color(0xFFFFFFFF),
    primaryContainer = Color(0xFF2563EB),
    onPrimaryContainer = Color(0xFFEEEFFF),
    secondary = Color(0xFF495C95),
    onSecondary = Color(0xFFFFFFFF),
    secondaryContainer = Color(0xFFDBE1FF),
    onSecondaryContainer = Color(0xFF394C84),
    tertiary = Color(0xFF943700),
    onTertiary = Color(0xFFFFFFFF),
    tertiaryContainer = Color(0xFFBC4800),
    onTertiaryContainer = Color(0xFFFFEDE6),
    background = Color(0xFFFAF8FF),
    onBackground = Color(0xFF191B23),
    surface = Color(0xFFFAF8FF),
    onSurface = Color(0xFF191B23),
    surfaceVariant = Color(0xFFDFE1F4),
    onSurfaceVariant = Color(0xFF434655),
    surfaceContainerLowest = Color(0xFFFFFFFF),
    surfaceContainerLow = Color(0xFFF3F3FE),
    surfaceContainer = Color(0xFFEDEDF9),
    surfaceContainerHigh = Color(0xFFE7E7F3),
    surfaceContainerHighest = Color(0xFFE1E2ED),
    error = Color(0xFFBA1A1A),
    onError = Color(0xFFFFFFFF),
    errorContainer = Color(0xFFFFDAD6),
    onErrorContainer = Color(0xFF93000A),
    outline = Color(0xFF737686),
    outlineVariant = Color(0xFFC3C6D7),
)

private val DarkColorScheme = darkColorScheme(
    primary = Color(0xFFB4C5FF),
    onPrimary = Color(0xFF002A78),
    primaryContainer = Color(0xFF2563EB),
    onPrimaryContainer = Color(0xFFEEEFFF),
    secondary = Color(0xFFB4C5FF),
    onSecondary = Color(0xFF182D63),
    secondaryContainer = Color(0xFF33467E),
    onSecondaryContainer = Color(0xFFDBE1FF),
    tertiary = Color(0xFFFFB596),
    onTertiary = Color(0xFF581E00),
    tertiaryContainer = Color(0xFFBC4800),
    onTertiaryContainer = Color(0xFFFFEDE6),
    background = Color(0xFF11131B),
    onBackground = Color(0xFFE1E2ED),
    surface = Color(0xFF11131B),
    onSurface = Color(0xFFE1E2ED),
    surfaceVariant = Color(0xFF434655),
    onSurfaceVariant = Color(0xFFC3C6D7),
    surfaceContainerLowest = Color(0xFF0C0E16),
    surfaceContainerLow = Color(0xFF191B23),
    surfaceContainer = Color(0xFF1D1F27),
    surfaceContainerHigh = Color(0xFF282A32),
    surfaceContainerHighest = Color(0xFF32343D),
    error = Color(0xFFFFB4AB),
    onError = Color(0xFF690005),
    errorContainer = Color(0xFF93000A),
    onErrorContainer = Color(0xFFFFDAD6),
    outline = Color(0xFF8D90A0),
    outlineVariant = Color(0xFF434655),
)

private val BaseTypography = Typography()

/** Apple-style hierarchy: large semibold headlines with slightly tight tracking. */
private val AppTypography = BaseTypography.copy(
    displaySmall = BaseTypography.displaySmall.copy(fontWeight = FontWeight.SemiBold, letterSpacing = (-0.5).sp),
    headlineLarge = BaseTypography.headlineLarge.copy(fontWeight = FontWeight.SemiBold, letterSpacing = (-0.5).sp),
    headlineMedium = BaseTypography.headlineMedium.copy(fontWeight = FontWeight.SemiBold, letterSpacing = (-0.4).sp),
    headlineSmall = BaseTypography.headlineSmall.copy(fontWeight = FontWeight.SemiBold, letterSpacing = (-0.3).sp),
    titleLarge = BaseTypography.titleLarge.copy(fontWeight = FontWeight.SemiBold, letterSpacing = (-0.2).sp),
    titleMedium = BaseTypography.titleMedium.copy(fontWeight = FontWeight.SemiBold),
    titleSmall = BaseTypography.titleSmall.copy(fontWeight = FontWeight.SemiBold),
)

private val AppShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp),
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

/** Connection status colors, tuned per theme so they keep contrast on both backgrounds. */
@Immutable
data class StatusColors(
    val connected: Color,
    val connecting: Color,
    val idle: Color,
)

private val LightStatusColors = StatusColors(
    connected = Color(0xFF16A34A),
    connecting = Color(0xFFD97706),
    idle = Color(0xFF64748B),
)

private val DarkStatusColors = StatusColors(
    connected = Color(0xFF4ADE80),
    connecting = Color(0xFFFBBF24),
    idle = Color(0xFF7C8799),
)

val LocalStatusColors = staticCompositionLocalOf { LightStatusColors }

/** The user's day/night choice; [SYSTEM] follows the device setting. */
enum class ThemeMode { SYSTEM, LIGHT, DARK }

/** Process-wide theme choice, so a change in Settings recolors every screen at once. */
object ThemeController {
    private val mutable = MutableStateFlow(ThemeMode.SYSTEM)
    val mode: StateFlow<ThemeMode> = mutable.asStateFlow()

    fun load(prefs: Prefs) {
        mutable.value = prefs.themeMode
    }

    fun set(prefs: Prefs, mode: ThemeMode) {
        prefs.themeMode = mode
        mutable.value = mode
    }
}

/**
 * Material 3 theme with exactly two fixed brand palettes, day and night. The user picks
 * day, night or the system setting; the app never derives colors from the wallpaper.
 */
@Composable
fun LeikwanTheme(
    darkTheme: Boolean? = null,
    content: @Composable () -> Unit,
) {
    val mode by ThemeController.mode.collectAsState()
    val dark = darkTheme ?: when (mode) {
        ThemeMode.SYSTEM -> isSystemInDarkTheme()
        ThemeMode.LIGHT -> false
        ThemeMode.DARK -> true
    }
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            // Status and navigation bar icons follow the app theme, not only the system one.
            (view.context as? Activity)?.window?.let { window ->
                WindowCompat.getInsetsController(window, view).apply {
                    isAppearanceLightStatusBars = !dark
                    isAppearanceLightNavigationBars = !dark
                }
            }
        }
    }
    CompositionLocalProvider(LocalStatusColors provides if (dark) DarkStatusColors else LightStatusColors) {
        MaterialTheme(
            colorScheme = if (dark) DarkColorScheme else LightColorScheme,
            shapes = AppShapes,
            typography = AppTypography,
            content = content,
        )
    }
}
