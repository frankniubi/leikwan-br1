package com.leikwan.bridge.runtime

import android.net.LocalSocket
import android.net.LocalSocketAddress
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import java.io.EOFException
import java.io.IOException
import java.nio.ByteBuffer
import java.nio.ByteOrder

private const val MAX_ULTRA_CONTROL_PAYLOAD = 64 * 1024
private const val ULTRA_CONTROL_TIMEOUT_MS = 2_000
private const val ULTRA_NATIVE_BATCH_TIMEOUT_MS = 30_000
private const val ULTRA_CONTROL_CONNECT_ATTEMPTS = 20
private const val ULTRA_NATIVE_CONNECT_ATTEMPTS = 3
private const val MAX_NATIVE_LEASE_CANDIDATES = 4

fun interface UltraControlSocket {
    /** Exchanges one fully framed request and returns only the reply payload. */
    @Throws(IOException::class)
    fun exchange(socketPath: String, frame: ByteArray): ByteArray
}

/** Optional timeout-aware transport used for native candidate probing. */
interface TimedUltraControlSocket : UltraControlSocket {
    fun exchange(socketPath: String, frame: ByteArray, timeoutMs: Int): ByteArray

    override fun exchange(socketPath: String, frame: ByteArray): ByteArray =
        exchange(socketPath, frame, ULTRA_CONTROL_TIMEOUT_MS)
}

object AndroidUltraControlSocket : TimedUltraControlSocket {
    override fun exchange(socketPath: String, frame: ByteArray): ByteArray =
        exchange(socketPath, frame, ULTRA_CONTROL_TIMEOUT_MS)

    override fun exchange(socketPath: String, frame: ByteArray, timeoutMs: Int): ByteArray {
        val socket = LocalSocket()
        try {
            socket.connect(LocalSocketAddress(socketPath, LocalSocketAddress.Namespace.FILESYSTEM))
            socket.setSoTimeout(timeoutMs.coerceIn(1_000, ULTRA_NATIVE_BATCH_TIMEOUT_MS))
            socket.outputStream.write(frame)
            socket.outputStream.flush()
            val input = socket.inputStream
            val header = ByteArray(4)
            readExactly(input, header)
            val length = ByteBuffer.wrap(header).order(ByteOrder.BIG_ENDIAN).int
            if (length !in 1..MAX_ULTRA_CONTROL_PAYLOAD) throw IOException("ultra_control_reply_invalid")
            return ByteArray(length).also { readExactly(input, it) }
        } finally {
            socket.close()
        }
    }

    private fun readExactly(input: java.io.InputStream, target: ByteArray) {
        var offset = 0
        while (offset < target.size) {
            val count = input.read(target, offset, target.size - offset)
            if (count <= 0) throw EOFException("ultra_control_reply_incomplete")
            offset += count
        }
    }
}

class UltraLeaseClient(
    private val controlSocket: UltraControlSocket = AndroidUltraControlSocket,
    private val sleep: (Long) -> Unit = { delayMs -> Thread.sleep(delayMs) },
) {
    /** The lease is wiped whether the runtime accepts it or not. */
    fun send(controlSocketPath: String, policy: UltraPolicy, lease: UltraLease): Boolean {
        try {
            if (controlSocketPath.isBlank() || lease.forwardId != policy.forwardId
                || lease.policyGeneration != policy.policyGeneration) {
                return false
            }
            return sendPayload(
                controlSocketPath = controlSocketPath,
                payload = lease.toControlPayload().toString().toByteArray(Charsets.UTF_8),
                forwardId = policy.forwardId,
                policyGeneration = policy.policyGeneration,
                timeoutMs = ULTRA_CONTROL_TIMEOUT_MS,
                connectAttempts = ULTRA_CONTROL_CONNECT_ATTEMPTS,
            )
        } finally {
            lease.wipe()
        }
    }

    /** Delivers a native-outbound lease to the app-private Ultra adapter. */
    fun sendNative(controlSocketPath: String, lease: NativeOutboundLease): Boolean =
        sendNativeInternal(
            controlSocketPath,
            listOf(lease),
            timeoutMs = ULTRA_CONTROL_TIMEOUT_MS,
            connectAttempts = ULTRA_CONTROL_CONNECT_ATTEMPTS,
        )

    /** Delivers up to four candidates; the local adapter probes and selects one atomically. */
    fun sendNative(controlSocketPath: String, leases: List<NativeOutboundLease>): Boolean =
        sendNativeInternal(
            controlSocketPath,
            leases,
            timeoutMs = if (leases.size > 1) ULTRA_NATIVE_BATCH_TIMEOUT_MS else ULTRA_CONTROL_TIMEOUT_MS,
            connectAttempts = if (leases.size > 1) ULTRA_NATIVE_CONNECT_ATTEMPTS else ULTRA_CONTROL_CONNECT_ATTEMPTS,
        )

    private fun sendNativeInternal(
        controlSocketPath: String,
        leases: List<NativeOutboundLease>,
        timeoutMs: Int,
        connectAttempts: Int,
    ): Boolean {
        try {
            val first = leases.firstOrNull()
            if (controlSocketPath.isBlank()
                || leases.size !in 1..MAX_NATIVE_LEASE_CANDIDATES
                || first == null
                || leases.any { lease ->
                    !lease.isUsable()
                        || lease.forwardId != first.forwardId
                        || lease.policyGeneration != first.policyGeneration
                }) return false
            val payload = if (leases.size == 1) {
                first.toControlPayload().toString().toByteArray(Charsets.UTF_8)
            } else {
                JsonObject().also { root ->
                    root.add("leases", JsonArray().also { array ->
                        leases.forEach { array.add(it.toControlPayload()) }
                    })
                }.toString().toByteArray(Charsets.UTF_8)
            }
            return sendPayload(
                controlSocketPath = controlSocketPath,
                payload = payload,
                forwardId = first.forwardId,
                policyGeneration = first.policyGeneration,
                timeoutMs = timeoutMs,
                connectAttempts = connectAttempts,
            )
        } finally {
            leases.forEach(NativeOutboundLease::wipe)
        }
    }

    private fun sendPayload(
        controlSocketPath: String,
        payload: ByteArray,
        forwardId: Long,
        policyGeneration: String,
        timeoutMs: Int,
        connectAttempts: Int,
    ): Boolean {
        var body = payload
        var frame = ByteArray(0)
        try {
            if (body.size !in 1..MAX_ULTRA_CONTROL_PAYLOAD) return false
            frame = ByteArray(4 + body.size)
            ByteBuffer.wrap(frame, 0, 4).order(ByteOrder.BIG_ENDIAN).putInt(body.size)
            body.copyInto(frame, destinationOffset = 4)
            repeat(connectAttempts.coerceIn(1, ULTRA_CONTROL_CONNECT_ATTEMPTS)) { attempt ->
                val reply = runCatching {
                    if (controlSocket is TimedUltraControlSocket) {
                        controlSocket.exchange(controlSocketPath, frame, timeoutMs)
                    } else {
                        controlSocket.exchange(controlSocketPath, frame)
                    }
                }.getOrNull()
                if (reply != null) {
                    try {
                        return reply.size in 1..MAX_ULTRA_CONTROL_PAYLOAD
                            && replyAccepted(reply, forwardId, policyGeneration)
                    } finally {
                        reply.fill(0)
                    }
                }
                if (attempt + 1 < connectAttempts.coerceIn(1, ULTRA_CONTROL_CONNECT_ATTEMPTS)) sleep(50)
            }
            return false
        } finally {
            body.fill(0)
            frame.fill(0)
        }
    }

    private fun replyAccepted(reply: ByteArray, forwardId: Long, policyGeneration: String): Boolean {
        return runCatching {
            val root = JsonParser.parseString(reply.toString(Charsets.UTF_8)).asJsonObject
            root.get("ok")?.asBoolean == true
                && root.get("forward_id")?.asLong == forwardId
                && root.get("policy_generation")?.asString == policyGeneration
        }.getOrDefault(false)
    }
}
