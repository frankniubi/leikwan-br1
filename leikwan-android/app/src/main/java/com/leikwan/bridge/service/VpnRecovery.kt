package com.leikwan.bridge.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.util.Log
import com.leikwan.bridge.data.Prefs

/** When a config-less start of [LeikwanVpnService] rebuilds the user's last connection. */
internal object VpnRecovery {
    /** The boot receiver's start; the system uses [VpnService.SERVICE_INTERFACE] for always-on VPN. */
    const val ACTION_RECONNECT = "com.leikwan.bridge.action.RECONNECT"
    const val ATTEMPTS = 6
    /**
     * Waits before attempts 2..[ATTEMPTS]: right after a boot or kill the network may still be
     * coming up, and a background kill can land in Doze where connectivity returns only after
     * minutes. The old 4-attempt (~50 s) window gave up in exactly those cases and left the VPN
     * off until the user opened the app.
     */
    val RETRY_DELAYS_MS = longArrayOf(5_000L, 15_000L, 30_000L, 60_000L, 120_000L)

    /**
     * A sticky restart (null intent), an always-on start or the boot receiver's start rebuilds the
     * tunnel the user left connected. Any other start without a config is a failed handoff.
     */
    fun shouldRecover(intent: Intent?, connectIntent: Boolean): Boolean =
        connectIntent && (
            intent == null ||
                intent.action == VpnService.SERVICE_INTERFACE ||
                intent.action == ACTION_RECONNECT
            )
}

/** Brings the VPN back after a reboot or an app update when the user had left it connected. */
class VpnRestartReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED && intent.action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        val prefs = runCatching { Prefs.get(context) }.getOrNull() ?: return
        if (!prefs.vpnConnectIntent || !prefs.isLoggedIn()) return
        // Without VPN consent the TUN cannot open; the user has to connect from the app.
        if (VpnService.prepare(context) != null) return
        runCatching {
            context.startForegroundService(
                Intent(context, LeikwanVpnService::class.java).setAction(VpnRecovery.ACTION_RECONNECT),
            )
        }.onFailure { Log.w("VpnRestartReceiver", "Cannot restart the VPN after ${intent.action}", it) }
    }
}
