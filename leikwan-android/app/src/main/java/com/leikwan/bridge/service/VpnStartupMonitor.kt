package com.leikwan.bridge.service

import kotlinx.coroutines.delay

enum class VpnStartupState {
    IDLE,
    STARTING,
    RUNNING,
    FAILED,
}

data class VpnStartupSnapshot(
    val state: VpnStartupState,
    val errorMessage: String?,
)

sealed interface VpnStartupAwaitResult {
    data object Running : VpnStartupAwaitResult
    data class Failed(val errorMessage: String) : VpnStartupAwaitResult
    data object Timeout : VpnStartupAwaitResult
}

object VpnStartupMonitor {
    suspend fun await(
        timeoutMs: Long,
        pollMs: Long = 100,
        readState: () -> VpnStartupSnapshot,
        sleeper: suspend (Long) -> Unit = { delay(it) },
    ): VpnStartupAwaitResult {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            val snapshot = readState()
            when (snapshot.state) {
                VpnStartupState.RUNNING -> return VpnStartupAwaitResult.Running
                VpnStartupState.FAILED -> {
                    return VpnStartupAwaitResult.Failed(
                        snapshot.errorMessage ?: "VPN service failed to start"
                    )
                }
                else -> sleeper(pollMs)
            }
        }
        return VpnStartupAwaitResult.Timeout
    }
}
