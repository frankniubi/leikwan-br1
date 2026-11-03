package com.leikwan.bridge.ui.connections

import android.app.Application
import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardCatalog
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardMode
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Forward -> node pairings hosted on the panel apply on every device of the client. */
@OptIn(ExperimentalCoroutinesApi::class)
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ConnectionsViewModelHostedPairingTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var app: Application
    private lateinit var prefs: Prefs

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        app = ApplicationProvider.getApplicationContext()
        val shared = app.getSharedPreferences("connections-vm-hosted-test", Context.MODE_PRIVATE)
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

    private fun forward(id: Long) = ForwardListItem(
        forwardId = id,
        serviceName = "Service $id",
        direction = "CAN->HKG",
        target = "198.51.100.7:1080",
        listenPort = (31400 + id).toInt(),
        mode = ForwardMode.SINGLE,
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "relay",
        speedLimitMbps = null,
    )

    private fun node(id: Long, hostedId: Long? = null) = ProxyNode(
        id = id,
        name = "Node $id",
        type = "socks5",
        originalServer = "198.51.100.7",
        originalPort = 1080,
        configJson = "{}",
        hostedId = hostedId,
    )

    private fun vm(
        hosted: MutableStateFlow<Map<Long, Long>>,
        pushed: MutableList<Pair<Long, Long>>,
    ) = ConnectionsViewModel(
        app,
        prefs,
        catalogFetcher = { ForwardCatalog(visibleForwards = listOf(forward(10), forward(11)), selectedForwardId = 10) },
        nodeFlows = { flowOf(listOf(node(5), node(6, hostedId = 61), node(7, hostedId = 71))) },
        hostedPairings = hosted,
        pushHostedPairing = { forwardId, nodeId -> pushed += forwardId to nodeId },
    )

    @Test
    fun `a new device starts with the panel pairing instead of the first node`() {
        val hosted = MutableStateFlow(mapOf(10L to 7L))
        val viewModel = vm(hosted, mutableListOf())
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(7L, viewModel.state.value.nodeFor(10L)?.id)
        assertEquals("forwards without a panel pairing keep the first node", 5L, viewModel.state.value.nodeFor(11L)?.id)
        assertEquals("the connect path uses the paired node", 7L, prefs.selectedProxyId)
    }

    @Test
    fun `a pairing changed on the panel re-asserts and a local pick is pushed`() {
        val hosted = MutableStateFlow(emptyMap<Long, Long>())
        val pushed = mutableListOf<Pair<Long, Long>>()
        val viewModel = vm(hosted, pushed)
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(5L, viewModel.state.value.nodeFor(10L)?.id)

        hosted.value = mapOf(10L to 6L)
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(6L, viewModel.state.value.nodeFor(10L)?.id)
        assertEquals(6L, prefs.selectedProxyId)

        viewModel.selectNodeForForward(10L, 7L)
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(7L, viewModel.state.value.nodeFor(10L)?.id)
        assertEquals(listOf(10L to 7L), pushed)
    }

    @Test
    fun `a panel pairing to a node this device does not have is ignored`() {
        val hosted = MutableStateFlow(mapOf(10L to 999L))
        val viewModel = vm(hosted, mutableListOf())
        dispatcher.scheduler.advanceUntilIdle()
        assertEquals(5L, viewModel.state.value.nodeFor(10L)?.id)
    }
}
