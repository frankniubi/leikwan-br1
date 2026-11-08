package com.leikwan.bridge.runtime

import java.time.Instant

object UltraNetworkGrace {
    const val SECONDS = 600L
    fun deadline(issuedExpiry: Instant, now: Instant): Instant? =
        if (now.isBefore(issuedExpiry.minusSeconds(60)) || !now.isBefore(issuedExpiry.plusSeconds(SECONDS))) null
        else issuedExpiry.plusSeconds(SECONDS)
}
