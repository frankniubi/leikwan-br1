package com.leikwan.bridge.runtime

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.io.File
import java.io.InputStream
import java.net.InetAddress
import java.net.ServerSocket
import java.net.Socket
import java.time.Instant
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference
import kotlin.concurrent.thread
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/** Starts the packaged ordinary Ultra process on the QA AVD without a production lease. */
@RunWith(AndroidJUnit4::class)
class UltraRuntimeProcessSmokeTest {
    @Test
    fun packagedOrdinaryUltraStartsAndOpensControlSocket() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val binaries = RuntimeBinaryInstaller(context).prepareBinaryPaths()
        val binary = requireNotNull(binaries.ultraBinary) { "packaged ultra binary is missing" }
        val runtimeDir = File(context.filesDir, "ultra-process-smoke-${System.nanoTime()}").apply {
            require(mkdirs()) { "unable to create smoke runtime directory" }
        }
        val configFile = File(runtimeDir, "config.json")
        val controlSocket = File(runtimeDir, "control.sock")
        val target = "example.invalid:443"
        configFile.writeText(
            """
            {
              "forward_id": 991,
              "listen_host": "127.0.0.1",
              "listen_port": 31991,
              "target": "$target",
              "control_socket": "${controlSocket.absolutePath}",
              "policy_generation": "${"a".repeat(64)}",
              "target_digest": "${UltraPolicy.targetDigest(target)}",
              "transport": "tcp",
              "max_tcp_connections": 4,
              "connect_timeout_seconds": 2,
              "probe_timeout_ms": 800,
              "probe_workers": 2
            }
            """.trimIndent(),
        )

        val process = ProcessBuilder(binary, "--config", configFile.absolutePath)
            .redirectErrorStream(true)
            .start()
        val output = StringBuilder()
        val drain = thread(isDaemon = true, name = "ultra-process-smoke-log") {
            try {
                process.inputStream.bufferedReader().useLines { lines ->
                    lines.forEach { line ->
                        synchronized(output) {
                            if (output.length < 4096) output.appendLine(line)
                        }
                    }
                }
            } catch (_: java.io.IOException) {
                // Destroying the short-lived process interrupts its merged output stream.
            }
        }
        try {
            val deadline = System.currentTimeMillis() + 5_000L
            while (System.currentTimeMillis() < deadline && !controlSocket.exists() && process.isAlive) {
                Thread.sleep(50L)
            }
            val diagnostic = synchronized(output) { output.toString() }
            assertTrue(
                "ordinary Ultra exited before opening control socket at ${Instant.now()}: $diagnostic",
                process.isAlive && controlSocket.exists(),
            )
        } finally {
            process.destroy()
            if (!process.waitFor(3, java.util.concurrent.TimeUnit.SECONDS)) {
                process.destroyForcibly()
                process.waitFor(3, java.util.concurrent.TimeUnit.SECONDS)
            }
            drain.join(1_000L)
            runtimeDir.deleteRecursively()
        }
    }

    @Test
    fun packagedOrdinaryUltraForwardsTcpPayloadThroughProvider() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val provider = ServerSocket(0, 1, InetAddress.getByName("127.0.0.1"))
        val payload = "ordinary-ultra-android-roundtrip".toByteArray(Charsets.UTF_8)
        val providerError = AtomicReference<Throwable?>()
        val providerPayload = AtomicReference<ByteArray?>()
        val providerDone = CountDownLatch(1)
        val providerThread = thread(isDaemon = true, name = "ultra-provider-fixture") {
            try {
                provider.accept().use { socket ->
                    socket.soTimeout = 5_000
                    val input = socket.getInputStream()
                    readUltraHandshake(input)
                    socket.getOutputStream().apply {
                        write(byteArrayOf(5, 2, 1, 0))
                        flush()
                    }
                    val encoded = readExactly(input, payload.size)
                    val decoded = encoded.map { (it.toInt() xor 1).toByte() }.toByteArray()
                    providerPayload.set(decoded)
                    socket.getOutputStream().apply {
                        write(decoded)
                        flush()
                    }
                }
            } catch (error: Throwable) {
                providerError.set(error)
            } finally {
                providerDone.countDown()
            }
        }

        val runtimeDir = File(context.filesDir, "ultra-roundtrip-${System.nanoTime()}").apply {
            require(mkdirs()) { "unable to create round-trip runtime directory" }
        }
        val configFile = File(runtimeDir, "config.json")
        val controlSocket = File(runtimeDir, "control.sock")
        val target = "example.invalid:443"
        val generation = "b".repeat(64)
        val now = Instant.now()
        configFile.writeText(
            """
            {
              "forward_id": 992,
              "listen_host": "127.0.0.1",
              "listen_port": 31992,
              "target": "$target",
              "control_socket": "${controlSocket.absolutePath}",
              "policy_generation": "$generation",
              "target_digest": "${UltraPolicy.targetDigest(target)}",
              "transport": "tcp",
              "max_tcp_connections": 4,
              "connect_timeout_seconds": 3,
              "probe_timeout_ms": 800,
              "probe_workers": 2
            }
            """.trimIndent(),
        )
        val binaries = RuntimeBinaryInstaller(context).prepareBinaryPaths()
        val binary = requireNotNull(binaries.ultraBinary) { "packaged ultra binary is missing" }
        val process = ProcessBuilder(binary, "--config", configFile.absolutePath)
            .redirectErrorStream(true)
            .start()
        val output = StringBuilder()
        val drain = thread(isDaemon = true, name = "ultra-roundtrip-log") {
            try {
                process.inputStream.bufferedReader().useLines { lines ->
                    lines.forEach { line ->
                        synchronized(output) {
                            if (output.length < 4096) output.appendLine(line)
                        }
                    }
                }
            } catch (_: java.io.IOException) {
                // Process cleanup interrupts the merged output stream.
            }
        }
        try {
            awaitFile(controlSocket, process, 5_000L)
            val policy = UltraPolicy(
                forwardId = 992L,
                sessionTicket = "roundtrip-ticket",
                policyGeneration = generation,
                issuedAt = now.minusSeconds(1),
                renewAfter = now.plusSeconds(30),
                expiresAt = now.plusSeconds(120),
                transport = "tcp",
                rateLimitMbps = 100L,
                quotaRemainingBytes = 1_048_576L,
                targetDigest = UltraPolicy.targetDigest(target),
            )
            val lease = UltraLease(
                leaseId = "ul2_android_roundtrip_992",
                forwardId = 992L,
                sessionTicket = policy.sessionTicket,
                policyGeneration = generation,
                expiresAt = policy.expiresAt,
                transport = "tcp",
                rateLimitMbps = policy.rateLimitMbps,
                quotaRemainingBytes = policy.quotaRemainingBytes,
                selectionRef = "android-roundtrip-selection",
                targetDigest = policy.targetDigest,
                nonceHash = "c".repeat(64),
                endpointIp = "127.0.0.1",
                endpointPort = provider.localPort,
                user = "fixture-user".toByteArray(Charsets.UTF_8),
                password = "fixture-password".toByteArray(Charsets.UTF_8),
                directConnectivity = ProtectedConnectivitySet.empty(),
            )
            assertTrue(UltraLeaseClient().send(controlSocket.absolutePath, policy, lease))
            val local = connectLocalPort(31992, process, 8_000L, output)
            local.use { connection ->
                connection.soTimeout = 5_000
                connection.getOutputStream().apply {
                    write(payload)
                    flush()
                }
                assertArrayEquals(payload, readExactly(connection.getInputStream(), payload.size))
            }
            assertTrue(providerDone.await(5, TimeUnit.SECONDS))
            providerError.get()?.let { throw AssertionError("provider fixture failed", it) }
            assertArrayEquals(payload, providerPayload.get())
        } finally {
            process.destroy()
            if (!process.waitFor(3, TimeUnit.SECONDS)) {
                process.destroyForcibly()
                process.waitFor(3, TimeUnit.SECONDS)
            }
            drain.join(1_000L)
            provider.close()
            providerThread.join(1_000L)
            runtimeDir.deleteRecursively()
        }
        assertEquals(0, process.exitValue())
    }

    private fun awaitFile(file: File, process: Process, timeoutMs: Long) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline && !file.exists() && process.isAlive) {
            Thread.sleep(50L)
        }
        val diagnostic = synchronized(process) { process.isAlive }
        assertTrue(
            "ordinary Ultra process exited before opening ${file.name}: $diagnostic",
            process.isAlive && file.exists(),
        )
    }

    private fun connectLocalPort(
        port: Int,
        process: Process,
        timeoutMs: Long,
        output: StringBuilder,
    ): Socket {
        val deadline = System.currentTimeMillis() + timeoutMs
        var lastError: Throwable? = null
        while (System.currentTimeMillis() < deadline && process.isAlive) {
            try {
                return Socket().also {
                    it.connect(java.net.InetSocketAddress("127.0.0.1", port), 250)
                }
            } catch (error: Throwable) {
                lastError = error
                Thread.sleep(100L)
            }
        }
        val diagnostic = synchronized(output) { output.toString() }
        throw AssertionError(
            "ordinary Ultra listener $port did not become ready; processAlive=${process.isAlive}, " +
                "lastError=${lastError?.message}, output=$diagnostic",
            lastError,
        )
    }

    private fun readUltraHandshake(input: InputStream) {
        val greetingPrefix = readExactly(input, 2)
        val greetingTailLength = if (greetingPrefix[1].toInt() == 5) 6 else 4
        readExactly(input, greetingTailLength)
        val userLength = readExactly(input, 1)[0].toInt() and 0xff
        readExactly(input, userLength)
        val passwordLength = readExactly(input, 1)[0].toInt() and 0xff
        readExactly(input, passwordLength)
        readExactly(input, 3)
        when (readExactly(input, 1)[0].toInt()) {
            1 -> readExactly(input, 4)
            3 -> readExactly(input, (readExactly(input, 1)[0].toInt() and 0xff))
            4 -> readExactly(input, 16)
            else -> error("fixture received an invalid address type")
        }
        readExactly(input, 2)
    }

    private fun readExactly(input: InputStream, length: Int): ByteArray {
        val result = ByteArray(length)
        var offset = 0
        while (offset < length) {
            val count = input.read(result, offset, length - offset)
            if (count <= 0) error("fixture stream ended after $offset/$length bytes")
            offset += count
        }
        return result
    }
}
