package com.leikwan.bridge

import androidx.compose.material3.Text
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import org.junit.Rule
import org.junit.Test

/**
 * Proves the androidTest source set + compose ui-test infrastructure compiles and runs on a device.
 * Subsequent UI tasks add real screen tests here. Run via :app:connectedDebugAndroidTest on a device.
 */
class AndroidInfraSmokeTest {

    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun composeTestHarnessRenders() {
        composeRule.setContent {
            Text("leikwan-infra-smoke")
        }
        composeRule.onNodeWithText("leikwan-infra-smoke").assertIsDisplayed()
    }
}
