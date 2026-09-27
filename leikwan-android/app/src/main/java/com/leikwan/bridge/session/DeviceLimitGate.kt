package com.leikwan.bridge.session

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.io.IOException

/**
 * The panel refused this device an online slot because the client's online device limit is full
 * (HTTP 429 `client_online_limit_reached`). Unlike [PanelControlException] this is not terminal:
 * enrollment stays intact and the same request succeeds once another device has been silent for
 * about 180 s, is kicked by the tenant, or the limit is raised. Counts are null when the panel
 * omitted them.
 */
class DeviceLimitReachedException(
    val limit: Int?,
    val active: Int?,
) : IOException(CODE) {
    val notice: DeviceLimitNotice get() = DeviceLimitNotice(limit, active)

    companion object {
        const val CODE = "client_online_limit_reached"
    }
}

data class DeviceLimitNotice(
    val limit: Int?,
    val active: Int?,
)

/**
 * Process-local UI state for a refused online slot. Set whenever a panel call is refused for the
 * device limit, cleared by the next call the panel accepts; enrollment is never touched here.
 */
object DeviceLimitGate {
    private val notice = MutableStateFlow<DeviceLimitNotice?>(null)
    val state = notice.asStateFlow()

    fun report(exception: DeviceLimitReachedException) {
        notice.value = exception.notice
    }

    fun clear() {
        notice.value = null
    }
}
