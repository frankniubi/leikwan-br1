package com.leikwan.bridge.ui.connections

import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ServicesLockUiTest {
    @get:Rule
    val composeRule = createComposeRule()

    private val state = ConnectionsUiState(
        forwards = listOf(
            ForwardListItem(
                forwardId = 10,
                serviceName = "Service 10",
                direction = "SHA->TPE",
                target = "203.0.113.10:443",
                listenPort = 31410,
                mode = ForwardMode.SINGLE,
                transport = "tcp",
                serviceStatus = "active",
                endpointMode = "relay",
                speedLimitMbps = null,
            ),
        ),
        nodes = listOf(
            ProxyNode(
                id = 5,
                name = "Taiwan",
                type = "ss",
                originalServer = "127.0.0.1",
                originalPort = 8080,
                configJson = "{}",
            ),
        ),
        selectedForwardId = 10,
        nodeByForward = mapOf(10L to 5L),
    )

    private fun render(status: VpnStatus) {
        composeRule.setContent {
            LeikwanTheme {
                ServicesScreenContent(
                    state = state.copy(vpnStatus = status),
                    onSelectForward = {},
                    onOpenNodePicker = {},
                    onRefresh = {},
                    onImportNode = {},
                )
            }
        }
    }

    @Test
    fun theNodeChipLocksWithAReasonWhileConnected() {
        render(VpnStatus.CONNECTED)

        composeRule.onNodeWithText("Taiwan").assertIsNotEnabled()
        composeRule.onNodeWithText("Disconnect to change the service or its node.").assertExists()
    }

    @Test
    fun theNodeChipIsPickableWhileDisconnected() {
        render(VpnStatus.DISCONNECTED)

        composeRule.onNodeWithText("Taiwan").assertIsEnabled()
        composeRule.onNodeWithText("Disconnect to change the service or its node.").assertDoesNotExist()
    }
}
