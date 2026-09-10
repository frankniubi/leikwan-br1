package com.leikwan.bridge.runtime

enum class ForwardStrategy(val wireValue: String) {
    SPEED_MAX("speed_max"),
    LATENCY_BEST("latency_best"),
    RANDOM("random");

    companion object {
        fun fromWireValue(value: String?): ForwardStrategy =
            entries.firstOrNull { it.wireValue == value?.trim()?.lowercase() } ?: LATENCY_BEST
    }
}
