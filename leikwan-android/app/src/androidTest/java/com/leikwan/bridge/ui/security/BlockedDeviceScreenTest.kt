package com.leikwan.bridge.ui.security

import androidx.activity.ComponentActivity
import androidx.compose.ui.graphics.toPixelMap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Assert.assertTrue
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

    @Test
    fun blockedDeviceTitleHasReadableContrast() {
        composeRule.setContent {
            LeikwanTheme {
                BlockedDeviceScreen()
            }
        }

        val pixels = composeRule
            .onNodeWithText("Unsupported device")
            .captureToImage()
            .toPixelMap()
        var darkestPixel = 1f

        for (y in 0 until pixels.height) {
            for (x in 0 until pixels.width) {
                val color = pixels[x, y]
                darkestPixel = minOf(darkestPixel, color.red, color.green, color.blue)
            }
        }

        assertTrue(
            "Blocked-device title should be visibly darker than its light background",
            darkestPixel <= 0.35f,
        )
    }
}
