package com.leikwan.bridge.session

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

/** Process-local UI gate for terminal Panel controls. Enrollment is cleared separately by the coordinator. */
object SessionGate {
    private val terminalControl = MutableStateFlow<PanelControlException?>(null)
    val state = terminalControl.asStateFlow()

    fun markTerminal(exception: PanelControlException) {
        terminalControl.value = exception
    }

    fun clear() {
        terminalControl.value = null
    }
}
