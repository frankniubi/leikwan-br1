package com.leikwan.bridge.ui.connections

import android.app.Application
import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.R
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardCatalog
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NativeOutboundCarrier
import com.leikwan.bridge.runtime.NativeOutboundLease
import com.leikwan.bridge.service.ProxyConnectionMode
import com.leikwan.bridge.session.DeviceLimitGate
import com.leikwan.bridge.session.DeviceLimitNotice
import com.leikwan.bridge.session.DeviceLimitReachedException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@OptIn(ExperimentalCoroutinesApi::class)
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ConnectionsViewModelTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var app: Application
    private lateinit var prefs: Prefs

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        app = ApplicationProvider.getApplicationContext()
        val shared = app.getSharedPreferences("connections-vm-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared).apply {
            panelUrl = "https://panel-a.example.test"
            clientId = 9
        }
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
        DeviceLimitGate.clear()
    }

    private fun forward(
        id: Long,
        name: String = "Service $id",
        direction: String = "CAN->HKG",
        target: String = "141.11.91.62:30522",
        port: Int = (31400 + id).toInt(),
    ): ForwardListItem = ForwardListItem(
        forwardId = id,
        serviceName = name,
        direction = direction,
        target = target,
        listenPort = port,
        mode = ForwardMode.SINGLE,
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "relay",
        speedLimitMbps = null,
    )

    private fun catalog(vararg forwards: ForwardListItem, selected: Long? = null): ForwardCatalog =
        ForwardCatalog(visibleForwards = forwards.toList(), selectedForwardId = selected)

    private fun node(id: Long, name: String = "Node $id", enabled: Boolean = true): ProxyNode =
        ProxyNode(
            id = id,
            name = name,
            type = "ss",
            originalServer = "127.0.0.1",
            originalPort = 8080,
            configJson = "{}",
            enabled = enabled,
        )

    private fun vm(
        nodes: List<ProxyNode> = emptyList(),
        catalog: ForwardCatalog = catalog(),
        startConnect: suspend (Long) -> Unit = {},
        stopConnect: () -> Unit = {},
    ): ConnectionsViewModel = ConnectionsViewModel(
        app,
        prefs,
        catalogFetcher = { catalog },
        nodeFlows = { flowOf(nodes) },
        startConnect = startConnect,
        stopConnect = stopConnect,
    )

    @Test
    fun `route selection progress follows the runtime while connecting`() {
        val selecting = MutableStateFlow<Int?>(null)
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = { catalog(forward(10), selected = 10) },
            nodeFlows = { flowOf(listOf(node(5))) },
            routeSelectionState = selecting,
        )
        dispatcher.scheduler.advanceUntilIdle()
        assertNull(viewModel.state.value.selectingRouteCandidates)

        selecting.value = 4
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(4, viewModel.state.value.selectingRouteCandidates)

        selecting.value = null
        dispatcher.scheduler.advanceUntilIdle()
        assertNull(viewModel.state.value.selectingRouteCandidates)
    }

    @Test
    fun `with no nodes the pairing surface is empty`() {
        val viewModel = vm(nodes = emptyList(), catalog = catalog(forward(10), selected = 10))
        dispatcher.scheduler.advanceUntilIdle()

        val state = viewModel.state.value
        assertTrue(state.forwards.isNotEmpty())
        assertFalse(state.hasNode)
        assertTrue(state.nodes.isEmpty())
    }

    @Test
    fun `forwards default to the first enabled node`() {
        val viewModel = vm(nodes = listOf(node(5, "A"), node(6, "B")), catalog = catalog(forward(10), forward(11)))
        dispatcher.scheduler.advanceUntilIdle()

        val state = viewModel.state.value
        assertTrue(state.hasNode)
        assertEquals(5L, state.nodeByForward[10L])
        assertEquals(5L, state.nodeByForward[11L])
        assertEquals(5L, state.nodeFor(10L)?.id)
    }

    @Test
    fun `disabled nodes are filtered out`() {
        val viewModel = vm(nodes = listOf(node(5, "A", enabled = false), node(6, "B")), catalog = catalog(forward(10)))
        dispatcher.scheduler.advanceUntilIdle()

        assertTrue(viewModel.state.value.nodes.none { it.id == 5L })
        assertEquals(6L, viewModel.state.value.nodeFor(10L)?.id)
    }

    @Test
    fun `selecting a forward pins it and its node`() {
        val viewModel = vm(nodes = listOf(node(5, "A"), node(6, "B")), catalog = catalog(forward(10), forward(11)))
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.selectForward(11)
        assertEquals(11L, viewModel.state.value.selectedForwardId)
        assertEquals(11L, prefs.selectedForwardId)
        assertEquals(5L, prefs.selectedProxyId)
    }

    @Test
    fun `swapping a node for one forward does not touch another forward`() {
        val viewModel = vm(nodes = listOf(node(5, "A"), node(6, "B")), catalog = catalog(forward(10), forward(11)))
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.selectNodeForForward(10L, 6L)
        assertEquals(6L, viewModel.state.value.nodeFor(10L)?.id)
        assertEquals(5L, viewModel.state.value.nodeFor(11L)?.id)
        assertEquals(6L, prefs.selectedProxyId)
    }

    @Test
    fun `a picked node that goes away is not replaced by another node`() {
        val nodes = MutableStateFlow(listOf(node(5, "HK"), node(6, "TW")))
        val started = mutableListOf<Long>()
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = { catalog(forward(10), selected = 10) },
            nodeFlows = { nodes },
            startConnect = { id -> started += id },
        )
        dispatcher.scheduler.advanceUntilIdle()
        viewModel.selectNodeForForward(10L, 6L)

        nodes.value = listOf(node(5, "HK"), node(6, "TW", enabled = false))
        dispatcher.scheduler.advanceUntilIdle()
        assertNull(viewModel.state.value.nodeFor(10L))

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertTrue(started.isEmpty())
        assertEquals(app.getString(R.string.connections_error_node_unavailable), viewModel.state.value.error)
    }

    @Test
    fun `a node pick while connected is refused instead of silently waiting for a reconnect`() {
        val viewModel = vm(nodes = listOf(node(5, "HK"), node(6, "TW")), catalog = catalog(forward(10), selected = 10))
        dispatcher.scheduler.advanceUntilIdle()
        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(VpnStatus.CONNECTED, viewModel.state.value.vpnStatus)

        viewModel.selectNodeForForward(10L, 6L)

        assertEquals(5L, viewModel.state.value.nodeFor(10L)?.id)
        assertEquals(5L, prefs.selectedProxyId)
    }

    @Test
    fun `the persisted node pick survives reopening the app`() {
        prefs.selectedForwardId = 10L
        prefs.selectedProxyId = 6L
        val viewModel = vm(nodes = listOf(node(5, "HK"), node(6, "TW")), catalog = catalog(forward(10), forward(11)))
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(6L, viewModel.state.value.nodeFor(10L)?.id)
        assertEquals(6L, prefs.selectedProxyId)
    }

    @Test
    fun `a picked service the panel no longer lists is not swapped for the first one`() {
        prefs.selectedForwardId = 12L
        val viewModel = vm(nodes = listOf(node(5)), catalog = catalog(forward(10), forward(11)))
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(0L, viewModel.state.value.selectedForwardId)
        assertEquals(app.getString(R.string.connections_error_service_unavailable), viewModel.state.value.error)
    }

    @Test
    fun `device limit refusal shows the persistent notice and retries at the steady cadence`() {
        var attempts = 0
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = { catalog(forward(10), selected = 10) },
            nodeFlows = { flowOf(listOf(node(5))) },
            startConnect = {
                attempts += 1
                if (attempts == 1) throw DeviceLimitReachedException(limit = 2, active = 2)
            },
            deviceLimitRetryMs = 60_000,
        )
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.toggleConnect()
        dispatcher.scheduler.runCurrent()

        var state = viewModel.state.value
        assertEquals(VpnStatus.DISCONNECTED, state.vpnStatus)
        assertNull(state.error)
        assertEquals(DeviceLimitNotice(limit = 2, active = 2), state.deviceLimit)
        assertEquals(1, attempts)

        dispatcher.scheduler.advanceTimeBy(59_000)
        dispatcher.scheduler.runCurrent()
        assertEquals(1, attempts)

        dispatcher.scheduler.advanceTimeBy(1_001)
        dispatcher.scheduler.runCurrent()
        state = viewModel.state.value
        assertEquals(2, attempts)
        assertEquals(VpnStatus.CONNECTED, state.vpnStatus)

        DeviceLimitGate.clear()
        dispatcher.scheduler.runCurrent()
        assertNull(viewModel.state.value.deviceLimit)
    }

    @Test
    fun `connect without a node errors`() {
        val viewModel = vm(nodes = emptyList(), catalog = catalog(forward(10), selected = 10))
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(VpnStatus.DISCONNECTED, viewModel.state.value.vpnStatus)
        assertEquals(app.getString(R.string.connections_error_select_node), viewModel.state.value.error)
    }

    @Test
    fun `connect without a selected service errors`() {
        val viewModel = vm(nodes = listOf(node(5)), catalog = catalog())
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(VpnStatus.DISCONNECTED, viewModel.state.value.vpnStatus)
        assertEquals(app.getString(R.string.connections_error_select_service), viewModel.state.value.error)
    }

    @Test
    fun `connect rides the forward and uses its paired node`() {
        val invoked = mutableListOf<Long>()
        var stopped = false
        val viewModel = vm(
            nodes = listOf(node(5, "Tokyo")),
            catalog = catalog(forward(10, direction = "CAN->HKG", target = "141.11.91.62:30522", port = 30010), selected = 10),
            startConnect = { id -> invoked += id },
            stopConnect = { stopped = true },
        )
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()

        val state = viewModel.state.value
        assertEquals(VpnStatus.CONNECTED, state.vpnStatus)
        assertNull(state.error)
        assertEquals(listOf(10L), invoked)
        assertEquals(5L, prefs.selectedProxyId)
        assertEquals(10L, prefs.selectedForwardId)
        assertEquals(ProxyConnectionMode.SERVICES, prefs.proxyConnectionMode)

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertTrue(stopped)
        assertEquals(VpnStatus.DISCONNECTED, viewModel.state.value.vpnStatus)
    }

    @Test
    fun `entitled native outbound connects without a personal node`() {
        val direction = "Hong Kong -> China Mainland"
        val nativeForwardId = 10L
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(nativeForwardId),
            defaultForwardId = nativeForwardId,
            routeScopes = listOf("cn_only", "global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nativeForwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(nativeForwardId, direction, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("cn_only", "global"),
                    defaultRouteScope = "global",
                ),
            ),
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = nativeForwardId
        prefs.nativeOutboundRouteScope = "cn_only"
        var invoked: Triple<Long, String, Boolean>? = null
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
                    selectedForwardId = nativeForwardId,
                    nativeOutbound = capability,
                )
            },
            nodeFlows = { flowOf(emptyList()) },
            startNativeConnect = { id, scope, bypass, _ -> invoked = Triple(id, scope, bypass) },
        )
        dispatcher.scheduler.advanceUntilIdle()

        val state = viewModel.state.value
        assertTrue(state.nativeVisible)
        assertTrue(state.nativeSelected)
        assertFalse(state.hasNode)
        assertTrue(state.hasUsablePath)
        assertEquals("cn_only", state.nativeRouteScope)

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(Triple(nativeForwardId, "cn_only", false), invoked)
        assertEquals(VpnStatus.CONNECTED, viewModel.state.value.vpnStatus)
    }

    @Test
    fun `live CAN to HKG native carrier selects forward 180 and global scope without direction fallback`() {
        val nativeForwardId = 180L
        val direction = "CAN -> HKG"
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(nativeForwardId),
            defaultForwardId = nativeForwardId,
            routeScopes = listOf("global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nativeForwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(
                        nativeForwardId,
                        direction,
                        "tcp+udp",
                        "global",
                    ),
                    transport = "tcp+udp",
                    routeScopes = listOf("global"),
                    defaultRouteScope = "global",
                ),
            ),
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = 174L
        prefs.nativeOutboundRouteScope = "cn_only"
        prefs.nativeOutboundBypassChina = false
        var invoked: Triple<Long, String, Boolean>? = null
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
                    selectedForwardId = nativeForwardId,
                    nativeOutbound = capability,
                )
            },
            nodeFlows = { flowOf(emptyList()) },
            startNativeConnect = { id, scope, bypass, _ -> invoked = Triple(id, scope, bypass) },
        )
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(nativeForwardId, viewModel.state.value.nativeForwardId)
        assertEquals(direction, viewModel.state.value.nativeCarrier?.direction)
        assertEquals("global", viewModel.state.value.nativeRouteScope)
        assertFalse(viewModel.state.value.nativeBypassChina)

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(Triple(nativeForwardId, "global", false), invoked)
    }

    @Test
    fun `mainland to international restores intl only preference and keeps bypass disabled`() {
        val direction = "Mainland China -> International"
        val nativeForwardId = 12L
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(nativeForwardId),
            defaultForwardId = nativeForwardId,
            routeScopes = listOf("intl_only", "global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nativeForwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(nativeForwardId, direction, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("intl_only", "global"),
                    defaultRouteScope = "global",
                ),
            ),
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = nativeForwardId
        prefs.nativeOutboundRouteScope = "international_only"
        prefs.nativeOutboundBypassChina = true
        var invoked: Triple<Long, String, Boolean>? = null
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
                    selectedForwardId = nativeForwardId,
                    nativeOutbound = capability,
                )
            },
            nodeFlows = { flowOf(emptyList()) },
            startNativeConnect = { id, scope, bypass, _ -> invoked = Triple(id, scope, bypass) },
        )
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals("intl_only", viewModel.state.value.nativeRouteScope)
        assertFalse(viewModel.state.value.nativeBypassChina)
        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(Triple(nativeForwardId, "intl_only", false), invoked)
    }

    @Test
    fun `unrestricted native policy is opt in and follows carrier entitlement`() {
        val direction = "Mainland China -> International"
        val nativeForwardId = 13L
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(nativeForwardId),
            defaultForwardId = nativeForwardId,
            routeScopes = listOf("intl_only", "global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nativeForwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(nativeForwardId, direction, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("intl_only", "global"),
                    defaultRouteScope = "global",
                    unrestrictedAllowed = true,
                ),
            ),
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = nativeForwardId
        var invoked: Triple<Long, String, Boolean>? = null
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
                    selectedForwardId = nativeForwardId,
                    nativeOutbound = capability,
                )
            },
            nodeFlows = { flowOf(emptyList()) },
            startNativeConnect = { id, scope, bypass, policy ->
                invoked = Triple(id, scope, bypass)
                assertEquals("unrestricted", policy)
            },
        )
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals("restricted", viewModel.state.value.nativeRoutePolicy)
        viewModel.setNativeRoutePolicy("unrestricted")
        assertEquals("unrestricted", viewModel.state.value.nativeRoutePolicy)
        assertEquals("unrestricted", prefs.nativeOutboundRoutePolicy)

        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(Triple(nativeForwardId, "global", false), invoked)
        assertEquals("unrestricted", prefs.nativeOutboundRoutePolicy)
    }

    @Test
    fun `unrestricted native policy cannot be selected without carrier entitlement`() {
        val direction = "Mainland China -> International"
        val nativeForwardId = 14L
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(nativeForwardId),
            defaultForwardId = nativeForwardId,
            routeScopes = listOf("global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nativeForwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(nativeForwardId, direction, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("global"),
                    defaultRouteScope = "global",
                ),
            ),
        )
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
                    selectedForwardId = nativeForwardId,
                    nativeOutbound = capability,
                )
            },
            nodeFlows = { flowOf(emptyList()) },
        )
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.setNativeRoutePolicy("unrestricted")
        assertEquals("restricted", viewModel.state.value.nativeRoutePolicy)
        assertEquals("restricted", prefs.nativeOutboundRoutePolicy)
    }

    @Test
    fun `native entitlement removal stops an active native tunnel`() {
        val direction = "Hong Kong -> China Mainland"
        val nativeForwardId = 10L
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(nativeForwardId),
            defaultForwardId = nativeForwardId,
            routeScopes = listOf("cn_only", "global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nativeForwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(nativeForwardId, direction, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("cn_only", "global"),
                    defaultRouteScope = "global",
                ),
            ),
        )
        var currentCatalog = ForwardCatalog(
            visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
            selectedForwardId = nativeForwardId,
            nativeOutbound = capability,
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = nativeForwardId
        var stopCalls = 0
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = { currentCatalog },
            nodeFlows = { flowOf(emptyList()) },
            stopConnect = { stopCalls++ },
            startNativeConnect = { _, _, _, _ -> },
        )
        dispatcher.scheduler.advanceUntilIdle()
        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(VpnStatus.CONNECTED, viewModel.state.value.vpnStatus)

        currentCatalog = ForwardCatalog(
            visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
            selectedForwardId = nativeForwardId,
            nativeOutbound = null,
        )
        viewModel.refreshForwards()
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(1, stopCalls)
        assertEquals(VpnStatus.DISCONNECTED, viewModel.state.value.vpnStatus)
        assertFalse(viewModel.state.value.nativeSelected)
    }

    @Test
    fun `native carrier replacement stops the old active tunnel`() {
        val direction = "Hong Kong -> China Mainland"
        val oldForwardId = 10L
        val newForwardId = 11L
        fun carrier(id: Long) = NativeOutboundCarrier(
            forwardId = id,
            direction = direction,
            scopeDigest = NativeOutboundLease.scopeDigest(id, direction, "tcp+udp", "global"),
            transport = "tcp+udp",
            routeScopes = listOf("cn_only", "global"),
            defaultRouteScope = "global",
        )
        fun capability(ids: List<Long>) = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = ids,
            defaultForwardId = ids.first(),
            routeScopes = listOf("cn_only", "global"),
            defaultRouteScope = "global",
            forwards = ids.map(::carrier),
        )

        var currentCatalog = ForwardCatalog(
            visibleForwards = listOf(forward(oldForwardId, direction = direction)),
            selectedForwardId = oldForwardId,
            nativeOutbound = capability(listOf(oldForwardId)),
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = oldForwardId
        var stopCalls = 0
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = { currentCatalog },
            nodeFlows = { flowOf(emptyList()) },
            stopConnect = { stopCalls++ },
            startNativeConnect = { _, _, _, _ -> },
        )
        dispatcher.scheduler.advanceUntilIdle()
        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(VpnStatus.CONNECTED, viewModel.state.value.vpnStatus)

        currentCatalog = ForwardCatalog(
            visibleForwards = listOf(
                forward(oldForwardId, direction = direction),
                forward(newForwardId, direction = direction),
            ),
            selectedForwardId = newForwardId,
            nativeOutbound = capability(listOf(newForwardId)),
        )
        viewModel.refreshForwards()
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(1, stopCalls)
        assertEquals(VpnStatus.DISCONNECTED, viewModel.state.value.vpnStatus)
        assertFalse(viewModel.state.value.nativeSelected)
        assertFalse(prefs.nativeOutboundSelected)
    }

    @Test
    fun `reopening the app over a running native tunnel keeps it up`() {
        val direction = "Hong Kong -> China Mainland"
        val nativeForwardId = 10L
        val carrier = NativeOutboundCarrier(
            forwardId = nativeForwardId,
            direction = direction,
            scopeDigest = NativeOutboundLease.scopeDigest(nativeForwardId, direction, "tcp+udp", "global"),
            transport = "tcp+udp",
            routeScopes = listOf("cn_only", "global"),
            defaultRouteScope = "global",
        )
        val catalog = ForwardCatalog(
            visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
            selectedForwardId = nativeForwardId,
            nativeOutbound = NativeOutboundCapability(
                enabled = true,
                protocol = "socks5",
                leaseProtocol = "leikwan-ultra-native-socks5-v1",
                transport = "tcp+udp",
                forwardIds = listOf(nativeForwardId),
                defaultForwardId = nativeForwardId,
                routeScopes = listOf("cn_only", "global"),
                defaultRouteScope = "global",
                forwards = listOf(carrier),
            ),
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = nativeForwardId
        prefs.nativeOutboundRouteScope = "global"
        var stopCalls = 0
        // A fresh view model (activity recreated, app reopened) while the VPN service still runs.
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = { catalog },
            nodeFlows = { flowOf(emptyList()) },
            stopConnect = { stopCalls++ },
            vpnRunning = true,
        )
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(0, stopCalls)
        assertEquals(VpnStatus.CONNECTED, viewModel.state.value.vpnStatus)
        assertTrue(viewModel.state.value.nativeSelected)
        assertTrue(prefs.nativeOutboundSelected)
    }

    @Test
    fun `turning native selection off stops the active native tunnel`() {
        val direction = "Hong Kong -> China Mainland"
        val nativeForwardId = 10L
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(nativeForwardId),
            defaultForwardId = nativeForwardId,
            routeScopes = listOf("cn_only", "global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = nativeForwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(nativeForwardId, direction, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("cn_only", "global"),
                    defaultRouteScope = "global",
                ),
            ),
        )
        prefs.nativeOutboundSelected = true
        prefs.nativeOutboundForwardId = nativeForwardId
        var stopCalls = 0
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(nativeForwardId, direction = direction)),
                    selectedForwardId = nativeForwardId,
                    nativeOutbound = capability,
                )
            },
            nodeFlows = { flowOf(emptyList()) },
            stopConnect = { stopCalls++ },
            startNativeConnect = { _, _, _, _ -> },
        )
        dispatcher.scheduler.advanceUntilIdle()
        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()

        viewModel.setNativeSelected(false)

        assertEquals(1, stopCalls)
        assertEquals(VpnStatus.DISCONNECTED, viewModel.state.value.vpnStatus)
        assertFalse(viewModel.state.value.nativeSelected)
    }

    private fun nativeCapability(forwardId: Long, direction: String): NativeOutboundCapability =
        NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(forwardId),
            defaultForwardId = forwardId,
            routeScopes = listOf("global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = forwardId,
                    direction = direction,
                    scopeDigest = NativeOutboundLease.scopeDigest(forwardId, direction, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("global"),
                    defaultRouteScope = "global",
                ),
            ),
        )

    @Test
    fun `native outbound rules are not node-carrying services`() {
        val flagged = forward(12, target = "127.0.0.9:1080").copy(nativeOutbound = true)
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(10), forward(11, direction = "CAN -> HKG"), flagged),
                    selectedForwardId = 10,
                    nativeOutbound = nativeCapability(11, "CAN -> HKG"),
                )
            },
            nodeFlows = { flowOf(listOf(node(5))) },
        )
        dispatcher.scheduler.advanceUntilIdle()

        val state = viewModel.state.value
        // 11 is a carrier of the native capability, 12 carries the panel's native flag.
        assertEquals(listOf(10L), state.serviceForwards.map { it.forwardId })
        assertTrue(state.appGroupLines().none { it.forwardId == 11L || it.forwardId == 12L })
    }

    @Test
    fun `services and native outbound share one selection`() {
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(10), forward(11, direction = "CAN -> HKG")),
                    selectedForwardId = 10,
                    nativeOutbound = nativeCapability(11, "CAN -> HKG"),
                )
            },
            nodeFlows = { flowOf(listOf(node(5))) },
        )
        dispatcher.scheduler.advanceUntilIdle()
        assertFalse(viewModel.state.value.nativeSelected)

        viewModel.selectNativeForward(11)
        assertTrue(viewModel.state.value.nativeSelected)
        assertEquals(11L, viewModel.state.value.nativeForwardId)
        assertTrue(prefs.nativeOutboundSelected)

        viewModel.selectForward(10)
        assertFalse(viewModel.state.value.nativeSelected)
        assertEquals(10L, viewModel.state.value.selectedForwardId)
        assertFalse(prefs.nativeOutboundSelected)

        // Picking a native rule through the services path still goes native, never node pairing.
        viewModel.selectForward(11)
        assertTrue(viewModel.state.value.nativeSelected)
    }

    @Test
    fun `an ordinary connect never selects a native rule`() {
        // A stale selection left on the native rule after native outbound was turned off.
        prefs.selectedForwardId = 11
        prefs.nativeOutboundSelected = false
        var started: Long? = null
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = {
                ForwardCatalog(
                    visibleForwards = listOf(forward(11, direction = "CAN -> HKG"), forward(10)),
                    selectedForwardId = 11,
                    nativeOutbound = nativeCapability(11, "CAN -> HKG"),
                )
            },
            nodeFlows = { flowOf(listOf(node(5))) },
            startConnect = { started = it },
        )
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(10L, viewModel.state.value.selectedForwardId)
        assertEquals(10L, prefs.selectedForwardId)
        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(10L, started)
    }
}
