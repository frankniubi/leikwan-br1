package com.leikwan.bridge.singbox

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import io.nekohasekai.libbox.*
import java.io.File
import java.lang.reflect.Proxy
import java.net.InetAddress
import java.net.ServerSocket
import java.net.Socket
import java.net.DatagramPacket
import java.net.DatagramSocket
import kotlin.concurrent.thread
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

/** Exercises real JNI initialization and service traffic, not just config parsing. */
@RunWith(AndroidJUnit4::class)
class LibboxServiceRuntimeTest {
    @Test
    fun bundledJavaAndNativeStartServiceAndCarryTcpAndUdp() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val dir = File(context.cacheDir, "libbox-service-${System.nanoTime()}").apply { mkdirs() }
        Libbox.setup(SetupOptions().apply {
            basePath = dir.absolutePath
            workingPath = dir.absolutePath
            tempPath = dir.absolutePath
        })
        val platform = Proxy.newProxyInstance(PlatformInterface::class.java.classLoader,
            arrayOf(PlatformInterface::class.java)) { _, method, _ ->
            when (method.name) {
                "getInterfaces" -> object : NetworkInterfaceIterator {
                    override fun hasNext() = false
                    override fun next(): NetworkInterface = error("empty")
                }
                "findConnectionOwner" -> ConnectionOwner()
                "openTun" -> error("unexpected TUN in mixed service test")
                else -> when (method.returnType) {
                    java.lang.Boolean.TYPE -> false
                    java.lang.Integer.TYPE -> 0
                    else -> null
                }
            }
        } as PlatformInterface
        val handler = object : CommandServerHandler {
            override fun serviceReload() {}
            override fun serviceStop() {}
            override fun getSystemProxyStatus(): SystemProxyStatus? = null
            override fun setSystemProxyEnabled(enabled: Boolean) {}
            override fun triggerNativeCrash() {}
            override fun writeDebugMessage(message: String?) {}
        }
        val port = ServerSocket(0).use { it.localPort }
        val server = CommandServer(handler, platform)
        try {
            server.start()
            val config = """{"log":{"level":"debug"},"inbounds":[{"type":"mixed","tag":"test-in","listen":"127.0.0.1","listen_port":$port}],"outbounds":[{"type":"direct","tag":"direct"}],"route":{"final":"direct","auto_detect_interface":false}}"""
            server.startOrReloadService(config, OverrideOptions())
            ServerSocket(0, 1, InetAddress.getByName("127.0.0.1")).use { echo ->
                val worker = thread(isDaemon = true) {
                    runCatching { echo.accept().use { socket ->
                        val b = ByteArray(4); socket.getInputStream().readNBytes(b, 0, 4)
                        socket.getOutputStream().write(b)
                    } }
                }
                socks(port, 1, echo.localPort).use { socket ->
                    socket.getOutputStream().write(byteArrayOf(1, 2, 3, 4))
                    assertArrayEquals(byteArrayOf(1, 2, 3, 4), socket.getInputStream().readNBytes(4))
                }
                worker.join(3000)
            }
            DatagramSocket(0, InetAddress.getByName("127.0.0.1")).use { echo ->
                echo.soTimeout = 5000
                val worker = thread(isDaemon = true) {
                    val packet = DatagramPacket(ByteArray(1024), 1024)
                    runCatching { echo.receive(packet); echo.send(packet) }
                }
                Socket("127.0.0.1", port).use { control ->
                    control.soTimeout = 5000
                    val out = control.getOutputStream(); val input = control.getInputStream()
                    out.write(byteArrayOf(5, 1, 0)); assertArrayEquals(byteArrayOf(5, 0), input.readNBytes(2))
                    out.write(byteArrayOf(5, 3, 0, 1, 0, 0, 0, 0, 0, 0))
                    val reply = input.readNBytes(10); assertEquals(10, reply.size); assertEquals(0, reply[1].toInt())
                    val relayPort = ((reply[8].toInt() and 255) shl 8) or (reply[9].toInt() and 255)
                    DatagramSocket().use { udp ->
                        udp.soTimeout = 5000
                        val data = byteArrayOf(0, 0, 0, 1, 127, 0, 0, 1, (echo.localPort shr 8).toByte(), echo.localPort.toByte(), 9, 8, 7, 6)
                        udp.send(DatagramPacket(data, data.size, InetAddress.getByName("127.0.0.1"), relayPort))
                        val packet = DatagramPacket(ByteArray(1024), 1024); udp.receive(packet)
                        assertArrayEquals(byteArrayOf(9, 8, 7, 6), packet.data.copyOfRange(packet.length - 4, packet.length))
                    }
                }
                worker.join(3000)
            }
        } finally {
            server.closeService()
            server.close()
        }
    }

    @Test
    fun vpnPlatformProvidesInterfacesAndAnInitialDefaultRoute() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val service = com.leikwan.bridge.service.LeikwanVpnService()
        android.content.ContextWrapper::class.java.getDeclaredMethod("attachBaseContext", android.content.Context::class.java)
            .apply { isAccessible = true }.invoke(service, context)
        val observed = java.util.concurrent.CountDownLatch(1)
        var defaultName = ""
        val listener = object : InterfaceUpdateListener {
            override fun updateDefaultInterface(name: String?, index: Int, expensive: Boolean, constrained: Boolean) {
                if (!name.isNullOrBlank() && index > 0) { defaultName = name; observed.countDown() }
            }
        }
        try {
            service.startDefaultInterfaceMonitor(listener)
            assertTrue("VPN platform never reports a default physical interface", observed.await(5, java.util.concurrent.TimeUnit.SECONDS))
            val interfaces = requireNotNull(service.getInterfaces()) { "VPN platform returned null interfaces" }
            var found = false
            while (interfaces.hasNext()) {
                val item = interfaces.next()
                if (item.name == defaultName && item.index > 0 && item.addresses.hasNext()) found = true
            }
            assertTrue("Default physical interface missing from libbox interface inventory", found)
        } finally { service.closeDefaultInterfaceMonitor(listener) }
    }

    private fun socks(port: Int, command: Int, targetPort: Int): Socket {
        val socket = Socket("127.0.0.1", port); socket.soTimeout = 5000
        val out = socket.getOutputStream(); val input = socket.getInputStream()
        out.write(byteArrayOf(5, 1, 0)); assertArrayEquals(byteArrayOf(5, 0), input.readNBytes(2))
        out.write(byteArrayOf(5, command.toByte(), 0, 1, 127, 0, 0, 1, (targetPort shr 8).toByte(), targetPort.toByte()))
        val reply = input.readNBytes(10); assertEquals(10, reply.size); assertEquals(0, reply[1].toInt())
        return socket
    }
}
