package com.leikwan.bridge.ui.security

import androidx.activity.ComponentActivity
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Rule
import org.junit.Test

class BlockedDeviceScreenTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<ComponentActivity>()

    @Test
    fun blockedDeviceRendersBeforeAnyNavigationSurface() {
        composeRule.setContent {
            LeikwanTheme {
                BlockedDeviceScreen()
            }
        }

        composeRule.onNodeWithText("Unsupported device").assertExists()
    }
}
