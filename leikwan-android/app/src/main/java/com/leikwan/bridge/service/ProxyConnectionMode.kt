package com.leikwan.bridge.service

enum class ProxyConnectionMode(
    val storedValue: String,
    val label: String,
) {
    SERVICES("services", "Services"),
    DIRECT("direct", "Direct");

    companion object {
        fun fromStored(value: String?): ProxyConnectionMode {
            return entries.firstOrNull { it.storedValue == value } ?: SERVICES
        }
    }
}
