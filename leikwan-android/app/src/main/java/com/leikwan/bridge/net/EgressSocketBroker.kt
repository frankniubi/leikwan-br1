package com.leikwan.bridge.net

import android.net.LocalServerSocket
import android.net.LocalSocket
import android.net.LocalSocketAddress
import android.os.Process
import android.system.Os
import java.io.File
import java.io.FileDescriptor
import java.io.IOException
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread

/** One request per connection: byte 1 + SCM_RIGHTS socket; byte 0 ACK, byte 1 refusal. */
class EgressSocketBroker(private val path: File, private val bind: (FileDescriptor) -> Unit = { DirectPortNetwork.bindDescriptor(it) }) : AutoCloseable {
    private var listener: LocalSocket? = null
    private var server: LocalServerSocket? = null
    private val clients = ConcurrentHashMap.newKeySet<LocalSocket>()
    private val workers = ThreadPoolExecutor(4, 4, 0L, TimeUnit.MILLISECONDS, ArrayBlockingQueue<Runnable>(64))
    @Volatile private var closed = false

    fun start() {
        require(path.absolutePath.toByteArray(Charsets.UTF_8).size < 104) { "ports_broker_path_too_long" }
        path.delete()
        val socket = LocalSocket()
        try {
            socket.bind(LocalSocketAddress(path.absolutePath, LocalSocketAddress.Namespace.FILESYSTEM))
            Os.chmod(path.absolutePath, 384) // 0600, also inside the app-private files directory.
            listener = socket
            server = LocalServerSocket(socket.fileDescriptor)
            thread(name = "ports-fd-broker", isDaemon = true) {
                while (!closed) {
                    val client = try { server?.accept() ?: break } catch (_: IOException) { break }
                    clients += client
                    if (closed) { clients.remove(client); client.close(); break }
                    try { workers.execute { handle(client) } }
                    catch (_: java.util.concurrent.RejectedExecutionException) { clients.remove(client); client.close() }
                }
            }
        } catch (error: Exception) { socket.close(); path.delete(); throw error }
    }

    private fun handle(socket: LocalSocket) {
        var descriptors: Array<FileDescriptor>? = null
        try {
            socket.soTimeout = 3_000
            if (socket.peerCredentials.uid != Process.myUid()) throw IOException("ports_broker_peer_rejected")
            val version = socket.inputStream.read()
            descriptors = socket.ancillaryFileDescriptors
            if (version != 1 || descriptors?.size != 1) throw IOException("ports_broker_request_invalid")
            bind(descriptors.single())
            // These are received duplicates, not the Go process's original descriptor.
            descriptors.forEach { Os.close(it) }
            descriptors = null
            socket.outputStream.write(0)
            socket.outputStream.flush()
        } catch (_: Exception) {
            runCatching { socket.outputStream.write(1); socket.outputStream.flush() }
        } finally {
            descriptors?.forEach { runCatching { Os.close(it) } }
            clients.remove(socket)
            runCatching { socket.close() }
        }
    }

    override fun close() {
        closed = true
        runCatching { server?.close() }
        runCatching { listener?.close() }
        clients.forEach { runCatching { it.close() } }
        clients.clear()
        workers.shutdownNow()
        path.delete()
    }
}
