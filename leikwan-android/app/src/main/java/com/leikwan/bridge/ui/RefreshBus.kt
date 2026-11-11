package com.leikwan.bridge.ui

import kotlinx.coroutines.flow.MutableSharedFlow

/** One-shot signals asking ViewModels to re-pull the panel catalog now. */
object RefreshBus {
    val events = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    fun request() {
        events.tryEmit(Unit)
    }
}
