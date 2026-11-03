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
import com.leikwan.bridge.session.DeviceLimitGate
import com.leikwan.bridge.session.PanelControlException
import java.io.IOException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@OptIn(ExperimentalCoroutinesApi::class)
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ConnectionsThirdPartyVpnHintTest {
    private val dispatcher = StandardTestDispatcher()
    private lateinit var app: Application
    private lateinit var prefs: Prefs

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        app = ApplicationProvider.getApplicationContext()
        val shared = app.getSharedPreferences("connections-vpn-hint-test", Context.MODE_PRIVATE)
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

    private val forward = ForwardListItem(
        forwardId = 10,
        serviceName = "Service 10",
        direction = "CAN->HKG",
        target = "141.11.91.62:30522",
        listenPort = 31410,
        mode = ForwardMode.SINGLE,
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "relay",
        speedLimitMbps = null,
    )

    private val node = ProxyNode(
        id = 5,
        name = "Node 5",
        type = "ss",
        originalServer = "127.0.0.1",
        originalPort = 8080,
        configJson = "{}",
        enabled = true,
    )

    private fun connect(thirdPartyVpn: Boolean, startConnect: suspend (Long) -> Unit): String? {
        val viewModel = ConnectionsViewModel(
            app,
            prefs,
            catalogFetcher = { ForwardCatalog(visibleForwards = listOf(forward), selectedForwardId = 10) },
            nodeFlows = { flowOf(listOf(node)) },
            startConnect = startConnect,
            stopConnect = {},
            thirdPartyVpnBlocking = { thirdPartyVpn },
        )
        dispatcher.scheduler.advanceUntilIdle()
        viewModel.selectForward(10)
        viewModel.toggleConnect()
        dispatcher.scheduler.advanceUntilIdle()
        return viewModel.state.value.error
    }

    private val hint get() = app.getString(R.string.network_third_party_vpn_hint)

    @Test
    fun `connect failure with a blocking third party VPN appends the hint`() {
        val error = connect(thirdPartyVpn = true) { throw IOException("Pull configs failed") }

        assertEquals("Pull configs failed\n\n$hint", error)
    }

    @Test
    fun `connect failure without a third party VPN shows the plain error`() {
        assertEquals("Pull configs failed", connect(thirdPartyVpn = false) { throw IOException("Pull configs failed") })
    }

    @Test
    fun `successful connect never nags even with a third party VPN`() {
        assertNull(connect(thirdPartyVpn = true) {})
    }

    @Test
    fun `terminal panel refusal is not blamed on another VPN`() {
        assertEquals("device_banned", connect(thirdPartyVpn = true) { throw PanelControlException.banned(1) })
    }
}
