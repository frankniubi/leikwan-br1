package com.leikwan.bridge.service

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Process-local startup phase for the connect screen. The Ultra runtime only answers a
 * multi-candidate lease batch after it has probed every candidate (the shared ordinary-Ultra probe
 * contract is about 30 s), so the UI shows that wait instead of a bare "connecting".
 */
object ConnectProgress {
    private val candidates = MutableStateFlow<Int?>(null)

    /** Number of candidate routes being probed, or null when no route selection is running. */
    val selectingRouteCandidates = candidates.asStateFlow()

    /** Runs [block] while the UI reports a route selection across [candidateCount] candidates. */
    inline fun <T> selectingRoutes(candidateCount: Int, block: () -> T): T {
        if (candidateCount <= 1) return block()
        begin(candidateCount)
        try {
            return block()
        } finally {
            end()
        }
    }

    @PublishedApi
    internal fun begin(candidateCount: Int) {
        candidates.value = candidateCount
    }

    @PublishedApi
    internal fun end() {
        candidates.value = null
    }
}
