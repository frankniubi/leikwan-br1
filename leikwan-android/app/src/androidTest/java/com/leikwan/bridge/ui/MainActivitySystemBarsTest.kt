package com.leikwan.bridge.ui

import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.core.view.WindowCompat
import org.junit.Assert.assertFalse
import org.junit.Rule
import org.junit.Test

class MainActivitySystemBarsTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun darkThemeUsesLightSystemBarIcons() {
        val activity = composeRule.activity
        val controller = WindowCompat.getInsetsController(activity.window, activity.window.decorView)

        assertFalse(
            "Dark app theme requires light status-bar icons",
            controller.isAppearanceLightStatusBars,
        )
        assertFalse(
            "Dark app theme requires light navigation-bar icons",
            controller.isAppearanceLightNavigationBars,
        )
    }
}
