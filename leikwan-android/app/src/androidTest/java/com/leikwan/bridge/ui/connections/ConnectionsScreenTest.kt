package com.leikwan.bridge.ui.connections

import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NativeOutboundCarrier
import com.leikwan.bridge.runtime.NativeOutboundLease
import com.leikwan.bridge.ui.theme.LeikwanTheme
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

class ConnectionsScreenTest {
    @get:Rule
    val composeRule = createComposeRule()

    private val forward = ForwardListItem(
        forwardId = 159L,
        serviceName = "Hong Kong to China",
        direction = "Hong Kong -> China Mainland",
        target = "203.0.113.10:443",
        listenPort = 30159,
        mode = ForwardMode.SINGLE,
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "relay",
        speedLimitMbps = null,
    )

    private val capability = NativeOutboundCapability(
        enabled = true,
        protocol = "socks5",
        leaseProtocol = "leikwan-ultra-native-socks5-v1",
        transport = "tcp+udp",
        forwardIds = listOf(forward.forwardId),
        defaultForwardId = forward.forwardId,
        routeScopes = listOf("cn_only", "global"),
        defaultRouteScope = "global",
        forwards = listOf(
            NativeOutboundCarrier(
                forwardId = forward.forwardId,
                direction = forward.direction,
                scopeDigest = NativeOutboundLease.scopeDigest(
                    forward.forwardId,
                    forward.direction,
                    "tcp+udp",
                    "global",
                ),
                transport = "tcp+udp",
                routeScopes = listOf("cn_only", "global"),
                defaultRouteScope = "global",
            ),
        ),
    )

    @Test
    fun nativeOptionStaysInvisibleWithoutEntitlement() {
        composeRule.setContent {
            LeikwanTheme {
                ConnectionsScreenContent(
                    state = ConnectionsUiState(forwards = listOf(forward)),
                    onToggle = {},
                    onSelectForward = {},
                    onOpenNodePicker = {},
                    onRefresh = {},
                    onImportNode = {},
                )
            }
        }

        composeRule.onNodeWithText("Native outbound").assertDoesNotExist()
    }

    @Test
    fun entitledNativeOptionExposesRouteScopesAndNoNodeCopy() {
        composeRule.setContent {
            LeikwanTheme {
                ConnectionsScreenContent(
                    state = ConnectionsUiState(
                        forwards = listOf(forward),
                        nativeOutbound = capability,
                        nativeForwardId = forward.forwardId,
                        nativeRouteScope = "global",
                    ),
                    onToggle = {},
                    onSelectForward = {},
                    onOpenNodePicker = {},
                    onRefresh = {},
                    onImportNode = {},
                )
            }
        }

        assertTrue(capability.isUsable())
        composeRule.onNodeWithText("Native outbound").assertExists()
        composeRule.onNodeWithText("Mainland China only").assertExists()
        composeRule.onNodeWithText("Global").assertExists()
        composeRule.onNodeWithText("Bypass mainland China").assertExists()
        composeRule.onNodeWithText("No personal node is required").assertExists()
    }

    @Test
    fun nonInternationalCarrierHidesMainlandOnlyScopeSelector() {
        val nonInternationalForward = forward.copy(
            forwardId = 160L,
            serviceName = "China Mainland to Hong Kong",
            direction = "China Mainland -> Hong Kong",
        )
        val globalOnlyCapability = capability.copy(
            forwardIds = listOf(nonInternationalForward.forwardId),
            defaultForwardId = nonInternationalForward.forwardId,
            routeScopes = listOf("global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nonInternationalForward.forwardId,
                    direction = nonInternationalForward.direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(
                        nonInternationalForward.forwardId,
                        nonInternationalForward.direction,
                        "tcp+udp",
                        "global",
                    ),
                    transport = "tcp+udp",
                    routeScopes = listOf("global"),
                    defaultRouteScope = "global",
                ),
            ),
        )

        composeRule.setContent {
            LeikwanTheme {
                ConnectionsScreenContent(
                    state = ConnectionsUiState(
                        forwards = listOf(nonInternationalForward),
                        nativeOutbound = globalOnlyCapability,
                        nativeForwardId = nonInternationalForward.forwardId,
                        nativeRouteScope = "global",
                    ),
                    onToggle = {},
                    onSelectForward = {},
                    onOpenNodePicker = {},
                    onRefresh = {},
                    onImportNode = {},
                )
            }
        }

        composeRule.onNodeWithText("Native outbound").assertExists()
        composeRule.onNodeWithText("Traffic scope").assertDoesNotExist()
        composeRule.onNodeWithText("Global").assertDoesNotExist()
    }

    @Test
    fun mainlandToInternationalCarrierShowsInternationalOnlyScopeSelector() {
        val intlForward = forward.copy(
            forwardId = 161L,
            serviceName = "China Mainland to International",
            direction = "Mainland China -> International",
        )
        val intlCapability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(intlForward.forwardId),
            defaultForwardId = intlForward.forwardId,
            routeScopes = listOf("intl_only", "global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = intlForward.forwardId,
                    direction = intlForward.direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(
                        intlForward.forwardId,
                        intlForward.direction,
                        "tcp+udp",
                        "global",
                    ),
                    transport = "tcp+udp",
                    routeScopes = listOf("intl_only", "global"),
                    defaultRouteScope = "global",
                ),
            ),
        )

        composeRule.setContent {
            LeikwanTheme {
                ConnectionsScreenContent(
                    state = ConnectionsUiState(
                        forwards = listOf(intlForward),
                        nativeOutbound = intlCapability,
                        nativeForwardId = intlForward.forwardId,
                        nativeRouteScope = "intl_only",
                    ),
                    onToggle = {},
                    onSelectForward = {},
                    onOpenNodePicker = {},
                    onRefresh = {},
                    onImportNode = {},
                )
            }
        }

        composeRule.onNodeWithText("Traffic scope").assertExists()
        composeRule.onNodeWithText("International only").assertExists()
        composeRule.onNodeWithText("Global").assertExists()
        composeRule.onNodeWithText("Mainland China only").assertDoesNotExist()
    }

    @Test
    fun unrestrictedControlIsVisibleOnlyForAnEntitledCarrier() {
        val entitled = capability.copy(
            forwards = capability.forwards.map { it.copy(unrestrictedAllowed = true) },
        )
        composeRule.setContent {
            LeikwanTheme {
                ConnectionsScreenContent(
                    state = ConnectionsUiState(
                        forwards = listOf(forward),
                        nativeOutbound = entitled,
                        nativeForwardId = forward.forwardId,
                        nativeRouteScope = "global",
                    ),
                    onToggle = {},
                    onSelectForward = {},
                    onOpenNodePicker = {},
                    onRefresh = {},
                    onImportNode = {},
                )
            }
        }
        composeRule.onNodeWithText("Unrestricted native route").assertExists()
    }
}
