package com.leikwan.bridge.ui.shared

import android.content.Context
import androidx.annotation.StringRes
import com.leikwan.bridge.R
import com.leikwan.bridge.session.DeviceLimitNotice

/**
 * Picks the counted variant only when the panel sent both counts; the placeholders are
 * (active, limit) in every locale.
 */
internal fun deviceLimitMessage(
    notice: DeviceLimitNotice,
    withCounts: (active: Int, limit: Int) -> String,
    withoutCounts: () -> String,
): String {
    val active = notice.active
    val limit = notice.limit
    return if (active != null && limit != null) withCounts(active, limit) else withoutCounts()
}

/** Running-device wording: the control loop keeps retrying, so it promises automatic recovery. */
fun Context.deviceLimitMessage(notice: DeviceLimitNotice): String =
    formatDeviceLimit(notice, R.string.device_limit_message_counts, R.string.device_limit_message)

/** Sign-in wording: nothing retries a failed enrollment, so it asks the user to sign in again. */
fun Context.deviceLimitEnrollMessage(notice: DeviceLimitNotice): String =
    formatDeviceLimit(notice, R.string.device_limit_enroll_message_counts, R.string.device_limit_enroll_message)

private fun Context.formatDeviceLimit(
    notice: DeviceLimitNotice,
    @StringRes counted: Int,
    @StringRes uncounted: Int,
): String = deviceLimitMessage(
    notice,
    withCounts = { active, limit -> getString(counted, active, limit) },
    withoutCounts = { getString(uncounted) },
)
