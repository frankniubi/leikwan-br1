package com.leikwan.bridge.runtime

import android.net.LocalSocket
import android.net.LocalSocketAddress
import com.google.gson.JsonParser
import java.io.EOFException
import java.io.IOException
import java.nio.ByteBuffer
import java.nio.ByteOrder

private const val MAX_ULTRA_CONTROL_PAYLOAD = 64 * 1024
private const val ULTRA_CONTROL_TIMEOUT_MS = 2_000
private const val ULTRA_CONTROL_CONNECT_ATTEMPTS = 20

fun interface UltraControlSocket {
    /** Exchanges one fully framed request and returns only the reply payload. */
    @Throws(IOException::class)
    fun exchange(socketPath: String, frame: ByteArray): ByteArray
}

object AndroidUltraControlSocket : UltraControlSocket {
    override fun exchange(socketPath: String, frame: ByteArray): ByteArray {
        val socket = LocalSocket()
        try {
            socket.connect(LocalSocketAddress(socketPath, LocalSocketAddress.Namespace.FILESYSTEM))
            socket.setSoTimeout(ULTRA_CONTROL_TIMEOUT_MS)
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
        var body = ByteArray(0)
        var frame = ByteArray(0)
        try {
            if (controlSocketPath.isBlank() || lease.forwardId != policy.forwardId
                || lease.policyGeneration != policy.policyGeneration) {
                return false
            }
            body = lease.toControlPayload().toString().toByteArray(Charsets.UTF_8)
            if (body.size !in 1..MAX_ULTRA_CONTROL_PAYLOAD) return false
            frame = ByteArray(4 + body.size)
            ByteBuffer.wrap(frame, 0, 4).order(ByteOrder.BIG_ENDIAN).putInt(body.size)
            body.copyInto(frame, destinationOffset = 4)

            repeat(ULTRA_CONTROL_CONNECT_ATTEMPTS) { attempt ->
                val reply = runCatching { controlSocket.exchange(controlSocketPath, frame) }.getOrNull()
                if (reply != null) {
                    try {
                        return reply.size in 1..MAX_ULTRA_CONTROL_PAYLOAD && replyAccepted(reply, policy)
                    } finally {
                        reply.fill(0)
                    }
                }
                if (attempt + 1 < ULTRA_CONTROL_CONNECT_ATTEMPTS) sleep(50)
            }
            return false
        } finally {
            body.fill(0)
            frame.fill(0)
            lease.wipe()
        }
    }

    private fun replyAccepted(reply: ByteArray, policy: UltraPolicy): Boolean {
        return runCatching {
            val root = JsonParser.parseString(reply.toString(Charsets.UTF_8)).asJsonObject
            root.get("ok")?.asBoolean == true
                && root.get("forward_id")?.asLong == policy.forwardId
                && root.get("policy_generation")?.asString == policy.policyGeneration
        }.getOrDefault(false)
    }
}
