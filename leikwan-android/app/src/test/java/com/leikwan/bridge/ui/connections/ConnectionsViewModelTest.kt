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
import com.leikwan.bridge.service.ProxyConnectionMode
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
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
}
