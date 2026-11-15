package com.leikwan.bridge.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.util.Log
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay

/** How one in-place rebuild run of [LeikwanVpnService] ended. */
internal sealed interface RebuildOutcome {
    /** The saved connection runs again. */
    data object Reconnected : RebuildOutcome

    /** Recovery was called off meanwhile (user stop, revoke); there is nothing left to do. */
    data object CalledOff : RebuildOutcome

    /**
     * An attempt failed before it stopped the tunnel that was still running (a post-switch
     * rebuild that could not reach the panel); that tunnel is kept.
     */
    data class KeptCurrent(val failure: Exception) : RebuildOutcome

    /** Every attempt failed, or the user disconnected in the app; [failure] is the last error. */
    data class GaveUp(val failure: Exception?) : RebuildOutcome
}

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
    /** Waits after a Wi-Fi <-> cellular switch before probing whether the tunnel still works. */
    const val SWITCH_PROBE_DELAY_MS = 4_000L
    /** Per-attempt timeout of the post-switch tunnel probe. */
    const val SWITCH_PROBE_TIMEOUT_MS = 2_000
    /** Rounds of the post-switch probe, [SWITCH_PROBE_RETRY_DELAY_MS] apart. */
    const val SWITCH_PROBE_ROUNDS = 3
    const val SWITCH_PROBE_RETRY_DELAY_MS = 1_500L
    /** Resolvers of the new network asked per round; the first one may be an unusable IPv6 one. */
    const val SWITCH_PROBE_MAX_RESOLVERS = 3

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

    /**
     * True when any of [resolvers] answers [query] within [SWITCH_PROBE_ROUNDS] rounds. A single
     * 2 s query to the first resolver, right after a switch while the new network still
     * validates, failed often enough to rebuild a tunnel that was fine. No resolver: nothing to
     * judge, so the path counts as usable.
     */
    suspend fun anyResolverAnswers(
        resolvers: List<String>,
        pause: suspend (Long) -> Unit = { delay(it) },
        query: (String) -> Boolean,
    ): Boolean {
        val candidates = resolvers.distinct().take(SWITCH_PROBE_MAX_RESOLVERS)
        if (candidates.isEmpty()) return true
        repeat(SWITCH_PROBE_ROUNDS) { round ->
            if (round > 0) pause(SWITCH_PROBE_RETRY_DELAY_MS)
            if (candidates.any(query)) return true
        }
        return false
    }

    /**
     * Up to [ATTEMPTS] rebuilds of the saved connection, [RETRY_DELAYS_MS] apart. An attempt that
     * fails while [tunnelStillUp] never reached the stop of the running tunnel (the panel or the
     * new network did not answer yet); that tunnel is kept instead of retrying into a teardown
     * that may end with no tunnel at all.
     */
    suspend fun runRebuildAttempts(
        reconnect: suspend () -> Unit,
        stillRecovering: () -> Boolean,
        connectIntent: () -> Boolean,
        tunnelStillUp: () -> Boolean,
        onAttemptFailed: (attempt: Int, error: Exception) -> Unit = { _, _ -> },
        pause: suspend (Long) -> Unit = { delay(it) },
    ): RebuildOutcome {
        var failure: Exception? = null
        for (attempt in 1..ATTEMPTS) {
            if (attempt > 1) pause(RETRY_DELAYS_MS[attempt - 2])
            if (!stillRecovering()) return RebuildOutcome.CalledOff
            // The user disconnected in the app meanwhile.
            if (!connectIntent()) break
            try {
                reconnect()
                return RebuildOutcome.Reconnected
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                failure = e
                onAttemptFailed(attempt, e)
                if (tunnelStillUp()) return RebuildOutcome.KeptCurrent(e)
            }
        }
        return RebuildOutcome.GaveUp(failure)
    }
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
