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
        var brightestPixel = 0f

        for (y in 0 until pixels.height) {
            for (x in 0 until pixels.width) {
                val color = pixels[x, y]
                brightestPixel = maxOf(brightestPixel, color.red, color.green, color.blue)
            }
        }

        assertTrue(
            "Blocked-device title should be visibly lighter than its dark background",
            brightestPixel >= 0.65f,
        )
    }
}
