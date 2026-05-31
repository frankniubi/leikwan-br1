package com.leikwan.bridge.ui.routing

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.UserTrafficOutcome
import org.junit.Rule
import org.junit.Test

/**
 * Renders [RoutingScreenContent] with a seeded state and asserts the authoring controls + a rule row
 * are present. Runs on a device via :app:connectedDebugAndroidTest (compile-only in CI here).
 */
class RoutingScreenTest {

    @get:Rule
    val composeRule = createComposeRule()

    private val seededState = RoutingUiState(
        globalDefault = UserTrafficOutcome.TUNNEL,
        countryRules = listOf(
            RoutingRule(
                id = "country-cn",
                kind = RoutingRuleKind.COUNTRY,
                matchValue = "CN",
                outcome = UserTrafficOutcome.DIRECT,
            ),
        ),
        categoryRules = listOf(
            RoutingRule(
                id = "category-video",
                kind = RoutingRuleKind.WEBSITE_CATEGORY,
                matchValue = "video",
                outcome = UserTrafficOutcome.TUNNEL,
            ),
        ),
        presetCategories = listOf("video", "ai"),
    )

    @Test
    fun rendersAuthoringControlsAndRules() {
        composeRule.setContent {
            RoutingScreenContent(
                state = seededState,
                onSetGlobalDefault = {},
                onSetGlobalDefaultNode = {},
                onAddCountry = { _, _ -> },
                onAddCategory = { _, _ -> },
                onToggle = {},
                onSetTargetNode = { _, _ -> },
                onDelete = {},
            )
        }

        composeRule.onNodeWithText("Add country rule").assertIsDisplayed()
        composeRule.onNodeWithText("Add category rule").assertIsDisplayed()
        composeRule.onNodeWithText("CN").assertIsDisplayed()
        composeRule.onNodeWithText("video").assertIsDisplayed()
    }
}
