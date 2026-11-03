package com.leikwan.bridge.ui.shared

import android.content.Context
import com.leikwan.bridge.R

/** Appends the "another VPN/proxy is running" hint to a failure message; no hint leaves it unchanged. */
internal fun withThirdPartyVpnHint(message: String?, hint: String?): String? = when {
    hint.isNullOrBlank() -> message
    message.isNullOrBlank() -> hint
    else -> "$message\n\n$hint"
}

/** The localized hint, only when a third-party VPN is up and panel traffic could not bypass it. */
internal fun Context.thirdPartyVpnHintOrNull(shouldHint: Boolean): String? =
    if (shouldHint) getString(R.string.network_third_party_vpn_hint) else null
