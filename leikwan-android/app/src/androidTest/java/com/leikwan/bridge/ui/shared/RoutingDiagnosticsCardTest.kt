package com.leikwan.bridge.ui.shared

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Rule
import org.junit.Test

/**
 * Renders the read-only [RoutingDiagnosticsCard] and asserts its labelled rows. Runs on a device via
 * :app:connectedDebugAndroidTest (compile-only in CI here).
 */
class RoutingDiagnosticsCardTest {

    @get:Rule
    val composeRule = createComposeRule()

    @Test
    fun rendersDiagnosticsLabels() {
        composeRule.setContent {
            LeikwanTheme {
                RoutingDiagnosticsCard(
                    model = RoutingDiagnosticsUiModel(
                        defaultActionLabel = "TUNNEL",
                        decisionReasonLabel = "DEFAULT",
                        protectedTrafficLabel = "Panel, bootstrap, and rule-pack endpoints",
                        activeRulePackLabel = "none",
                        warnings = listOf("system-protected traffic is enforced"),
                    ),
                )
            }
        }

        composeRule.onNodeWithText("Routing diagnostics").assertIsDisplayed()
        composeRule.onNodeWithText("Default action:").assertExists()
        composeRule.onNodeWithText("Decision reason:").assertExists()
        composeRule.onNodeWithText("Protected traffic:").assertExists()
        composeRule.onNodeWithText("Active rule packs:").assertExists()
        composeRule.onNodeWithText("Warnings:").assertExists()
    }
}
