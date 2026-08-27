package com.leikwan.bridge.ui

import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.core.view.WindowCompat
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class MainActivitySystemBarsTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun lightThemeUsesDarkSystemBarIcons() {
        val activity = composeRule.activity
        val controller = WindowCompat.getInsetsController(activity.window, activity.window.decorView)

        assertTrue(
            "Light app theme requires dark status-bar icons",
            controller.isAppearanceLightStatusBars,
        )
        assertTrue(
            "Light app theme requires dark navigation-bar icons",
            controller.isAppearanceLightNavigationBars,
        )
    }
}
