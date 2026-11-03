package com.leikwan.bridge.ui.login

import androidx.activity.ComponentActivity
import androidx.compose.ui.graphics.toPixelMap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class EnrollmentScreenTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<ComponentActivity>()

    @Test
    fun enrollmentUsesClientIdAndTokenOnly() {
        composeRule.setContent {
            LeikwanTheme {
                EnrollmentScreen(onEnrollmentSuccess = {})
            }
        }

        composeRule.onNode(hasText("Client ID") and hasSetTextAction()).assertExists()
        composeRule.onNode(hasText("Agent Token") and hasSetTextAction()).assertExists()
        // There is no one-time login; sign-in is client ID + agent token only.
        composeRule.onNodeWithText("One-time Code").assertDoesNotExist()
        // Theme and language are reachable before signing in.
        composeRule.onNodeWithContentDescription("Theme").assertExists()
        composeRule.onNodeWithContentDescription("Language").assertExists()
        composeRule.onNodeWithText("Global (Cloudflare)").assertExists()
        composeRule.onNodeWithText("Remember sign-in details").assertExists()
        composeRule.onNodeWithText("https://v3.leikwanhost.com").assertDoesNotExist()
        composeRule.onNodeWithText("https://api-leikwanbridge.nbwly.com").assertDoesNotExist()
        composeRule.onNodeWithText("Email").assertDoesNotExist()
        composeRule.onNodeWithText("Panel URL").assertDoesNotExist()

        composeRule.onNodeWithText("Global (Cloudflare)").performClick()
        composeRule.onNodeWithText("Mainland China (Aliyun CDN)").assertExists()
    }

    @Test
    fun enrollmentTitleHasReadableContrast() {
        composeRule.setContent {
            LeikwanTheme {
                EnrollmentScreen(onEnrollmentSuccess = {})
            }
        }

        val pixels = composeRule
            .onNodeWithText("LeiKwan Bridge")
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
            "Enrollment title should be visibly darker than its light background",
            darkestPixel <= 0.35f,
        )
    }

    @Test
    fun disabledEnrollButtonKeepsReadableLabel() {
        composeRule.setContent {
            LeikwanTheme {
                EnrollmentScreen(onEnrollmentSuccess = {})
            }
        }

        val pixels = composeRule
            .onNodeWithText("Enroll")
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
            "Disabled enroll label should remain readable",
            darkestPixel <= 0.5f,
        )
    }
}
