package com.leikwan.bridge.ui.login

import androidx.activity.ComponentActivity
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Rule
import org.junit.Test

class EnrollmentScreenTest {
    @get:Rule
    val composeRule = createAndroidComposeRule<ComponentActivity>()

    @Test
    fun enrollmentUsesClientIdTokenOrOneTimeCodeOnly() {
        composeRule.setContent {
            LeikwanTheme {
                EnrollmentScreen(onEnrollmentSuccess = {})
            }
        }

        composeRule.onNodeWithText("Client ID").assertExists()
        composeRule.onNodeWithText("Agent Token").assertExists()
        composeRule.onNodeWithText("Email").assertDoesNotExist()
        composeRule.onNodeWithText("Panel URL").assertDoesNotExist()
    }
}
