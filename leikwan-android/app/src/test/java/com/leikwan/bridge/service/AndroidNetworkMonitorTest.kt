package com.leikwan.bridge.service

import android.content.Context
import android.net.ConnectivityManager
import android.net.LinkAddress
import android.net.LinkProperties
import android.net.NetworkCapabilities
import androidx.test.core.app.ApplicationProvider
import io.nekohasekai.libbox.InterfaceUpdateListener
import java.net.NetworkInterface
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AndroidNetworkMonitorTest {
    @Test
    fun `reports physical network and excludes VPN then unregisters`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val cm = context.getSystemService(ConnectivityManager::class.java)
        val shadow = shadowOf(cm)
        val physical = NetworkInterface.getNetworkInterfaces().toList().first { it.index > 0 }
        val network = org.robolectric.shadows.ShadowNetwork.newInstance(301)
        val properties = LinkProperties().apply {
            interfaceName = physical.name
            val address = LinkAddress::class.java.getDeclaredConstructor(String::class.java)
                .apply { isAccessible = true }.newInstance("192.0.2.2/24")
            LinkProperties::class.java.getDeclaredMethod("addLinkAddress", LinkAddress::class.java)
                .apply { isAccessible = true }.invoke(this, address)
        }
        shadow.clearAllNetworks()
        val caps = org.robolectric.shadows.ShadowNetworkCapabilities.newInstance()
        shadowOf(caps).addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        shadowOf(caps).addCapability(NetworkCapabilities.NET_CAPABILITY_NOT_VPN)
        shadowOf(caps).addTransportType(NetworkCapabilities.TRANSPORT_WIFI)
        shadow.addNetwork(network, null)
        shadow.setNetworkCapabilities(network, caps)
        shadow.setLinkProperties(network, properties)
        val updates = mutableListOf<Int>()
        val listener = object : InterfaceUpdateListener {
            override fun updateDefaultInterface(name: String?, index: Int, expensive: Boolean, constrained: Boolean) {
                updates += index
            }
        }
        val monitor = AndroidNetworkMonitor(context) {}
        monitor.start(listener)
        assertTrue(updates.contains(physical.index))
        assertTrue(monitor.interfaces().any { it.index == physical.index })
        shadowOf(caps).addTransportType(NetworkCapabilities.TRANSPORT_VPN)
        shadow.networkCallbacks.toList().forEach { it.onCapabilitiesChanged(network, caps) }
        assertTrue(monitor.interfaces().none { it.network == network })
        assertEquals(-1, updates.last())
        shadowOf(caps).removeTransportType(NetworkCapabilities.TRANSPORT_VPN)
        shadow.networkCallbacks.toList().forEach { it.onAvailable(network) }
        assertEquals(physical.index, updates.last())
        shadow.removeNetwork(network)
        shadow.networkCallbacks.toList().forEach { it.onLost(network) }
        assertEquals(-1, updates.last())
        monitor.remove(listener)
        val count = updates.size
        assertTrue(shadow.networkCallbacks.isEmpty())
        monitor.close()
        assertEquals(count, updates.size)
    }
}
