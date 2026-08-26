package com.leikwan.bridge.session

import java.util.concurrent.atomic.AtomicBoolean

/** Executes a terminal Panel command once, in the order that removes data-plane access first. */
class SessionRevocationCoordinator(
    private val stopVpn: () -> Unit,
    private val stopRuntimes: () -> Unit,
    private val clearLeaseState: () -> Unit,
    private val clearEnrollment: () -> Unit,
    private val onRevoked: (PanelControlException) -> Unit = {},
) {
    private val terminal = AtomicBoolean(false)

    fun revoke(
        exception: PanelControlException,
        acknowledge: (Long) -> Unit = {},
    ): Boolean {
        if (!terminal.compareAndSet(false, true)) return false
        val commandVersion = exception.command?.version?.coerceAtLeast(0L) ?: 0L

        // Never let one best-effort shutdown action prevent removal of the encrypted enrollment.
        runCatching(stopVpn)
        runCatching(stopRuntimes)
        runCatching(clearLeaseState)
        runCatching(clearEnrollment)
        runCatching { onRevoked(exception) }
        runCatching { acknowledge(commandVersion) }
        return true
    }
}
