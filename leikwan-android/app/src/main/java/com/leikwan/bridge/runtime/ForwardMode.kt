package com.leikwan.bridge.runtime

enum class ForwardMode(val wireValue: String) {
    SINGLE("single"),
    RACE("race"),
    BOND("bond"),
    MPTCP("mptcp");

    companion object {
        fun normalize(raw: String?): ForwardMode {
            return when (raw?.trim()?.lowercase()) {
                null, "", "single" -> SINGLE
                "race", "failover", "multi-path", "lb" -> RACE
                "bond", "bonding", "aggregate", "aggregated" -> BOND
                "mptcp", "mptcp-wireguard", "mptcp-ipsec", "multipath-tcp" -> MPTCP
                else -> SINGLE
            }
        }
    }
}
