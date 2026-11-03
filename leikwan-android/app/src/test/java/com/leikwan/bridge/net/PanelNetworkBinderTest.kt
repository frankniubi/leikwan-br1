package com.leikwan.bridge.net

import com.leikwan.bridge.proxy.SubscriptionFetcher
import java.io.IOException
import java.net.ConnectException
import java.net.Proxy
import java.net.Socket
import java.net.SocketAddress
import java.net.SocketException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import javax.net.SocketFactory
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.SocketPolicy
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test

class PanelNetworkBinderTest {
    private class FakeConnectivity : PanelConnectivity<String> {
        var active: String? = null
        var candidates: List<PanelNetworkCandidate<String>> = emptyList()
        var bind: (String) -> Socket = { Socket() }
        val boundOn = mutableListOf<String>()
        var onChange: (() -> Unit)? = null

        override fun activeNetwork(): String? = active
        override fun describe(network: String): PanelNetworkCandidate<String>? = candidates.firstOrNull { it.value == network }
        override fun networks(): List<PanelNetworkCandidate<String>> = candidates
        override fun createBoundSocket(network: String): Socket {
            boundOn += network
            return bind(network)
        }
        override fun watch(onChange: () -> Unit): AutoCloseable {
            this.onChange = onChange
            return AutoCloseable { this.onChange = null }
        }
    }

    private val wifi = PanelNetworkCandidate("wifi", hasInternet = true, isVpn = false, validated = true, transport = PanelTransport.WIFI)
    private val cell = PanelNetworkCandidate("cell", hasInternet = true, isVpn = false, validated = true, transport = PanelTransport.CELLULAR)
    private val vpn = PanelNetworkCandidate("vpn", hasInternet = true, isVpn = true, validated = true, transport = PanelTransport.OTHER)

    private val connectivity = FakeConnectivity()
    private var ownVpnRunning = false
    private var now = 1_000L
    private val binder = PanelNetworkBinder(
        connectivity = connectivity,
        ownVpnRunning = { ownVpnRunning },
        nowMs = { now },
        bindFailureCooldownMs = 60_000L,
    )
    private lateinit var server: MockWebServer

    @Before
    fun setUp() {
        server = MockWebServer().apply { start() }
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    // --- selection -------------------------------------------------------------------------

    @Test
    fun `selection skips VPN networks and never returns one`() {
        assertEquals("wifi", PanelNetworkSelection.select("vpn", listOf(vpn, wifi)))
        assertNull(PanelNetworkSelection.select("vpn", listOf(vpn)))
        assertNull(PanelNetworkSelection.select(null, emptyList<PanelNetworkCandidate<String>>()))
    }

    @Test
    fun `selection prefers validated then active then ethernet wifi cellular`() {
        val unvalidatedWifi = wifi.copy(validated = false)
        assertEquals("cell", PanelNetworkSelection.select("wifi", listOf(unvalidatedWifi, cell)))
        assertEquals("cell", PanelNetworkSelection.select("cell", listOf(wifi, cell)))
        assertEquals("wifi", PanelNetworkSelection.select("vpn", listOf(cell, wifi, vpn)))
        val eth = PanelNetworkCandidate("eth", hasInternet = true, isVpn = false, validated = true, transport = PanelTransport.ETHERNET)
        assertEquals("eth", PanelNetworkSelection.select(null, listOf(cell, wifi, eth)))
        // An unvalidated physical network still beats default routing.
        assertEquals("wifi", PanelNetworkSelection.select(null, listOf(unvalidatedWifi, vpn)))
    }

    @Test
    fun `selection ignores networks without internet`() {
        val noInternet = wifi.copy(hasInternet = false)
        assertEquals("cell", PanelNetworkSelection.select("wifi", listOf(noInternet, cell)))
    }

    // --- binder ----------------------------------------------------------------------------

    @Test
    fun `binder tracks network changes through the watch callback`() {
        connectivity.candidates = listOf(wifi)
        connectivity.active = "wifi"
        binder.start()
        assertEquals("wifi", binder.selectedNetwork())

        connectivity.candidates = listOf(cell)
        connectivity.active = "cell"
        connectivity.onChange!!.invoke()
        assertEquals("cell", binder.selectedNetwork())

        binder.stop()
        assertNull(connectivity.onChange)
    }

    @Test
    fun `sockets are bound to the selected physical network`() {
        connectivity.candidates = listOf(vpn, wifi)
        connectivity.active = "vpn"

        binder.socketFactory.createSocket().close()

        assertEquals(listOf("wifi"), connectivity.boundOn)
        assertTrue(binder.vpnBypassConfirmed())
        assertEquals(PanelEgress.PHYSICAL_BOUND, binder.egress())
    }

    @Test
    fun `no physical network means default routing without binding`() {
        connectivity.candidates = listOf(vpn)
        connectivity.active = "vpn"

        binder.socketFactory.createSocket().close()

        assertTrue(connectivity.boundOn.isEmpty())
        assertFalse(binder.isBindingActive())
    }

    @Test
    fun `refused binding falls back to a default socket and cools down until expiry or network change`() {
        connectivity.candidates = listOf(vpn, wifi)
        connectivity.active = "vpn"
        connectivity.bind = { throw SocketException("Binding socket to network 100 failed: EPERM") }

        val socket = binder.socketFactory.createSocket()
        socket.close()
        assertFalse(binder.isBindingActive())

        binder.socketFactory.createSocket().close()
        assertEquals("cooling down: no second bind attempt", 1, connectivity.boundOn.size)

        now += 60_000L
        assertTrue(binder.isBindingActive())

        binder.reportBindFailure(null)
        assertFalse(binder.isBindingActive())
        connectivity.candidates = listOf(vpn, cell)
        binder.refresh()
        assertTrue("a new network deserves a fresh attempt", binder.isBindingActive())
    }

    @Test
    fun `linkage errors from the platform binding also fall back`() {
        connectivity.candidates = listOf(wifi)
        connectivity.bind = { throw UnsatisfiedLinkError("no native binding") }

        binder.socketFactory.createSocket().close()

        assertFalse(binder.isBindingActive())
    }

    // --- third-party VPN detection and hint ------------------------------------------------

    @Test
    fun `third party VPN is the active network while our own VPN is not running`() {
        connectivity.candidates = listOf(vpn, wifi)
        connectivity.active = "vpn"
        assertTrue(binder.isThirdPartyVpnActive())

        ownVpnRunning = true
        assertFalse(binder.isThirdPartyVpnActive())

        ownVpnRunning = false
        connectivity.active = "wifi"
        assertFalse(binder.isThirdPartyVpnActive())
    }

    @Test
    fun `hint only when a third party VPN is up and binding did not bypass it`() {
        connectivity.candidates = listOf(vpn, wifi)
        connectivity.active = "vpn"
        assertTrue("never bound yet: cannot claim a bypass", binder.shouldHintThirdPartyVpn())

        binder.socketFactory.createSocket().close()
        assertFalse("bound past the VPN", binder.shouldHintThirdPartyVpn())

        binder.reportBindFailure(ConnectException("blackholed"))
        assertTrue(binder.shouldHintThirdPartyVpn())
        assertEquals(PanelEgress.THIRD_PARTY_VPN, binder.egress())

        connectivity.active = "wifi"
        assertFalse("no third-party VPN, no hint", binder.shouldHintThirdPartyVpn())
        assertEquals(PanelEgress.DEFAULT_ROUTE, binder.egress())
    }

    @Test
    fun `hint needs a third party VPN even when binding failed`() {
        connectivity.candidates = listOf(wifi)
        connectivity.active = "wifi"
        connectivity.bind = { throw SocketException("EPERM") }
        binder.socketFactory.createSocket().close()

        assertFalse(binder.shouldHintThirdPartyVpn())
    }

    // --- OkHttp wiring ---------------------------------------------------------------------

    @Test
    fun `panel clients ignore proxies and use the binder socket factory`() {
        val client = PanelNetwork.applyTo(OkHttpClient.Builder(), binder).build()
        assertEquals(Proxy.NO_PROXY, client.proxy)
        assertSame(binder.socketFactory, client.socketFactory)

        val unbound = PanelNetwork.applyTo(OkHttpClient.Builder(), null).build()
        assertEquals(Proxy.NO_PROXY, unbound.proxy)
        assertSame(SocketFactory.getDefault(), unbound.socketFactory)
    }

    @Test
    fun `DoH bootstrap and subscription clients ignore proxies`() {
        val panelBootstrap = AppDns.newBootstrapClient(bindToPanelNetwork = true)
        assertEquals(Proxy.NO_PROXY, panelBootstrap.proxy)
        assertSame(PanelNetwork.socketFactory, panelBootstrap.socketFactory)

        val sharedBootstrap = AppDns.newBootstrapClient(bindToPanelNetwork = false)
        assertEquals(Proxy.NO_PROXY, sharedBootstrap.proxy)
        assertSame(SocketFactory.getDefault(), sharedBootstrap.socketFactory)

        assertEquals(Proxy.NO_PROXY, SubscriptionFetcher.client.proxy)
    }

    @Test
    fun `refused binding still completes the call on default routing`() {
        connectivity.candidates = listOf(vpn, wifi)
        connectivity.active = "vpn"
        connectivity.bind = { throw SocketException("Binding socket to network 100 failed: EPERM") }
        server.enqueue(MockResponse().setBody("ok"))

        val body = panelClient().newCall(Request.Builder().url(server.url("/api/ping")).build()).execute()
            .use { it.body!!.string() }

        assertEquals("ok", body)
        assertEquals(1, server.requestCount)
        assertFalse(binder.isBindingActive())
    }

    @Test
    fun `blackholed bound connect is retried once on default routing`() {
        connectivity.candidates = listOf(vpn, wifi)
        connectivity.active = "vpn"
        connectivity.bind = {
            object : Socket() {
                override fun connect(endpoint: SocketAddress?, timeout: Int) {
                    throw ConnectException("bound path blackholed")
                }
            }
        }
        server.enqueue(MockResponse().setBody("ok"))

        val body = panelClient().newCall(Request.Builder().url(server.url("/api/ping")).build()).execute()
            .use { it.body!!.string() }

        assertEquals("ok", body)
        assertEquals("the bound attempt never reached the panel", 1, server.requestCount)
        assertTrue(connectivity.boundOn.isNotEmpty())
        assertFalse(binder.isBindingActive())
    }

    @Test
    fun `failures after the request may have reached the panel are not replayed or blamed on binding`() {
        connectivity.candidates = listOf(wifi)
        connectivity.active = "wifi"
        server.enqueue(MockResponse().setSocketPolicy(SocketPolicy.DISCONNECT_AFTER_REQUEST))
        server.enqueue(MockResponse().setSocketPolicy(SocketPolicy.DISCONNECT_AFTER_REQUEST))

        try {
            panelClient().newCall(
                Request.Builder().url(server.url("/api/enroll")).post("{}".toRequestBody()).build(),
            ).execute().close()
            fail("expected the disconnect to surface")
        } catch (_: IOException) {
            // expected
        }

        assertTrue(binder.isBindingActive())
    }

    @Test
    fun `only connect-phase failures qualify for the fallback retry`() {
        assertTrue(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(ConnectException("refused")))
        assertTrue(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(UnknownHostException("doh down")))
        assertTrue(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(SocketTimeoutException("connect timed out")))
        assertTrue(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(IOException("wrapped", ConnectException("x"))))
        assertFalse(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(SocketTimeoutException("Read timed out")))
        assertFalse(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(IOException("unexpected end of stream")))
        // A later route failed to connect, but an earlier route already carried the request.
        val laterRoute = ConnectException("Failed to connect to [::1]").apply {
            addSuppressed(IOException("unexpected end of stream"))
        }
        assertFalse(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(laterRoute))
        val allRoutesRefused = ConnectException("Failed to connect to [::1]").apply {
            addSuppressed(ConnectException("Failed to connect to 127.0.0.1"))
        }
        assertTrue(PanelNetworkFallbackInterceptor.isConnectPhaseFailure(allRoutesRefused))
    }

    private fun panelClient(): OkHttpClient =
        PanelNetwork.applyTo(OkHttpClient.Builder(), binder)
            .addInterceptor(PanelNetworkFallbackInterceptor { binder })
            .build()
}
