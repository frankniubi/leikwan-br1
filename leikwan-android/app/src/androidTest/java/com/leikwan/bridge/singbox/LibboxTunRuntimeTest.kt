package com.leikwan.bridge.singbox

import android.content.Intent
import android.net.VpnService
import android.os.ParcelFileDescriptor
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.leikwan.bridge.service.LeikwanVpnService
import com.leikwan.bridge.service.VpnStartupState
import java.util.concurrent.TimeUnit
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

/** Real kernel TUN + libbox routing, with shell UID traffic so app exclusion cannot bypass it. */
@RunWith(AndroidJUnit4::class)
class LibboxTunRuntimeTest {
    @Test
    fun kernelTunRelaysTcpAndUdpAndSurvivesReload() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val context = instrumentation.targetContext
        val args = InstrumentationRegistry.getArguments()
        org.junit.Assume.assumeTrue("requires the host fixture and shell-UID NDK probe", args.containsKey("socks_port"))
        val tcpPort = requireNotNull(args.getString("echo_tcp_port")) { "host echo TCP port required" }.toInt()
        val udpPort = requireNotNull(args.getString("echo_udp_port")) { "host echo UDP port required" }.toInt()
        val proxyPort = requireNotNull(args.getString("socks_port")) { "host native SOCKS port required" }.toInt()
        assertNull("QA VPN consent was not granted", VpnService.prepare(context))
        val config = """{
          "log":{"level":"debug"},
          "dns":{"servers":[{"type":"udp","tag":"test-dns","server":"10.0.2.2","server_port":$udpPort,"detour":"native-proxy"}],"final":"test-dns","strategy":"ipv4_only"},
          "inbounds":[{"type":"tun","tag":"tun-in","address":["172.19.0.1/30"],"mtu":1400,"auto_route":true,"strict_route":true,"stack":"gvisor","route_address":["0.0.0.0/0"]}],
          "outbounds":[{"type":"socks","tag":"native-proxy","server":"10.0.2.2","server_port":$proxyPort,"version":"5","username":"qa","password":"ephemeral-test"},{"type":"direct","tag":"direct"}],
          "route":{"auto_detect_interface":true,"rules":[{"protocol":"dns","action":"hijack-dns"}],"final":"native-proxy"}
        }""".trimIndent()
        LeikwanVpnService.configJson = config
        context.startForegroundService(Intent(context, LeikwanVpnService::class.java))
        try {
            val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(15)
            while (System.nanoTime() < deadline && LeikwanVpnService.startupState == VpnStartupState.IDLE) Thread.sleep(50)
            while (System.nanoTime() < deadline && LeikwanVpnService.startupState == VpnStartupState.STARTING) Thread.sleep(50)
            assertEquals("VPN error: ${LeikwanVpnService.lastStartError}", VpnStartupState.RUNNING, LeikwanVpnService.startupState)
            // Android 14 restricts shell netlink reads. Verify the actual VPN fd instead.
            val tunField = LeikwanVpnService::class.java.getDeclaredField("tunFd").apply { isAccessible = true }
            val tun = tunField.get(LeikwanVpnService.instance) as? ParcelFileDescriptor
            assertNotNull("kernel TUN descriptor missing", tun)
            assertTrue("kernel TUN descriptor closed", tun!!.fileDescriptor.valid())
            repeat(3) { round ->
                val tcp = probe("tcp", tcpPort, round)
                assertTrue("TCP TUN echo missing: $tcp", tcp.contains("TUN_TCP_$round"))
                val udp = probe("udp", udpPort, round)
                assertTrue("UDP TUN echo missing: $udp", udp.contains("TUN_UDP_$round"))
                assertTrue(LeikwanVpnService.isRunning)
                if (round == 0) {
                    LeikwanVpnService.instance!!.serviceReload()
                    assertFalse("reload leaked the old Android TUN descriptor", tun.fileDescriptor.valid())
                    Thread.sleep(500)
                } else if (round == 1) Thread.sleep(1100)
            }
        } finally {
            LeikwanVpnService.instance?.stopVpn()
        }
        assertFalse(LeikwanVpnService.isRunning)
        assertNull(LeikwanVpnService.configJson)
    }

    private fun probe(protocol: String, port: Int, round: Int): String {
        val descriptor = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(
            "/data/local/tmp/leikwan-tun-probe $protocol $port $round",
        )
        return ParcelFileDescriptor.AutoCloseInputStream(descriptor).bufferedReader().use { it.readText() }
    }
}
