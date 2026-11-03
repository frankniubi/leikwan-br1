package com.leikwan.bridge.service

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** Live tunnel traffic for the dashboard, fed by the libbox status stream (about once a second). */
data class TrafficSnapshot(
    /** Upload rate, bytes per second. */
    val uplink: Long = 0L,
    /** Download rate, bytes per second. */
    val downlink: Long = 0L,
    val uplinkTotal: Long = 0L,
    val downlinkTotal: Long = 0L,
    val connections: Int = 0,
    /** Recent combined rates, oldest first, at most [TrafficMonitor.HISTORY] samples. */
    val history: List<Long> = emptyList(),
    /** Wall-clock start of the running tunnel, or 0 when stopped. */
    val runningSinceMs: Long = 0L,
)

object TrafficMonitor {
    const val HISTORY = 40

    private val mutable = MutableStateFlow(TrafficSnapshot())
    val state: StateFlow<TrafficSnapshot> = mutable.asStateFlow()

    fun onStarted(nowMs: Long = System.currentTimeMillis()) {
        mutable.value = TrafficSnapshot(runningSinceMs = nowMs)
    }

    fun report(uplink: Long, downlink: Long, uplinkTotal: Long, downlinkTotal: Long, connections: Int) {
        val current = mutable.value
        val history = (current.history + (uplink.coerceAtLeast(0) + downlink.coerceAtLeast(0))).takeLast(HISTORY)
        mutable.value = current.copy(
            uplink = uplink.coerceAtLeast(0),
            downlink = downlink.coerceAtLeast(0),
            uplinkTotal = uplinkTotal.coerceAtLeast(0),
            downlinkTotal = downlinkTotal.coerceAtLeast(0),
            connections = connections.coerceAtLeast(0),
            history = history,
        )
    }

    fun onStopped() {
        mutable.value = TrafficSnapshot()
    }
}

/** "1.2 MB/s"-style formatting shared by the dashboard widgets. */
object TrafficFormat {
    private val units = arrayOf("B", "KB", "MB", "GB", "TB")

    fun bytes(value: Long): String {
        var amount = value.coerceAtLeast(0).toDouble()
        var unit = 0
        while (amount >= 1024 && unit < units.lastIndex) {
            amount /= 1024
            unit += 1
        }
        return if (unit == 0 || amount >= 100) "%.0f %s".format(amount, units[unit])
        else "%.1f %s".format(amount, units[unit])
    }

    fun rate(value: Long): String = bytes(value) + "/s"

    fun duration(ms: Long): String {
        val seconds = (ms / 1000).coerceAtLeast(0)
        return "%02d:%02d:%02d".format(seconds / 3600, (seconds / 60) % 60, seconds % 60)
    }
}
