package com.leikwan.bridge.ui.connections

import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ConnectionsRouteSelectionUiTest {
    @get:Rule
    val composeRule = createComposeRule()

    private fun render(state: ConnectionsUiState) {
        composeRule.setContent {
            LeikwanTheme {
                ConnectionsScreenContent(
                    state = state,
                    onToggle = {},
                    onSelectForward = {},
                    onOpenNodePicker = {},
                    onRefresh = {},
                    onImportNode = {},
                )
            }
        }
    }

    @Test
    fun connectingDuringRouteSelectionShowsTheCandidateCountAndHint() {
        render(ConnectionsUiState(vpnStatus = VpnStatus.CONNECTING, selectingRouteCandidates = 4))

        composeRule.onNodeWithText("Selecting the best of 4 routes...").assertExists()
        composeRule.onNodeWithText("This can take up to 30 seconds", substring = true).assertExists()
        composeRule.onNodeWithText("Connecting...").assertDoesNotExist()
    }

    @Test
    fun connectingWithoutRouteSelectionKeepsThePlainStatus() {
        render(ConnectionsUiState(vpnStatus = VpnStatus.CONNECTING))

        composeRule.onNodeWithText("Connecting...").assertExists()
        composeRule.onNodeWithText("This can take up to 30 seconds", substring = true).assertDoesNotExist()
    }

    @Test
    fun aStaleSelectionCountIsIgnoredOnceConnected() {
        render(ConnectionsUiState(vpnStatus = VpnStatus.CONNECTED, selectingRouteCandidates = 4))

        composeRule.onNodeWithText("Selecting the best of 4 routes...").assertDoesNotExist()
    }
}
