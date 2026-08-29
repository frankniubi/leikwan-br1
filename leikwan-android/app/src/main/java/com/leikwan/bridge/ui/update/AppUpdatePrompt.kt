package com.leikwan.bridge.ui.update

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.R
import com.leikwan.bridge.update.AppUpdateStatus
import com.leikwan.bridge.update.AppUpdateUiState

@Composable
fun AppUpdatePrompt(
    state: AppUpdateUiState,
    onCheckForUpdates: () -> Unit,
    onDownloadUpdate: () -> Unit,
    onInstallUpdate: () -> Unit,
    onAllowUnknownSources: () -> Unit,
    onDismiss: () -> Unit,
) {
    when (val status = state.status) {
        is AppUpdateStatus.Available -> AlertDialog(
            onDismissRequest = onDismiss,
            title = { Text(stringResource(R.string.update_available_title)) },
            text = {
                Text(stringResource(R.string.update_available_message, status.manifest.versionName))
            },
            confirmButton = {
                TextButton(onClick = onDownloadUpdate) {
                    Text(stringResource(R.string.update_download))
                }
            },
            dismissButton = {
                TextButton(onClick = onDismiss) {
                    Text(stringResource(R.string.common_cancel))
                }
            },
        )

        is AppUpdateStatus.Downloading -> AlertDialog(
            onDismissRequest = {},
            title = { Text(stringResource(R.string.update_downloading_title)) },
            text = {
                val fraction = if (status.totalBytes > 0L) {
                    (status.downloadedBytes.toFloat() / status.totalBytes.toFloat()).coerceIn(0f, 1f)
                } else {
                    0f
                }
                Column {
                    Text(
                        stringResource(
                            R.string.update_downloading_message,
                            status.manifest.versionName,
                            (fraction * 100).toInt(),
                        ),
                    )
                    LinearProgressIndicator(
                        progress = fraction,
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(top = 12.dp),
                    )
                }
            },
            confirmButton = {},
        )

        is AppUpdateStatus.ReadyToInstall -> AlertDialog(
            onDismissRequest = onDismiss,
            title = { Text(stringResource(R.string.update_ready_title)) },
            text = { Text(stringResource(R.string.update_ready_message, status.manifest.versionName)) },
            confirmButton = {
                TextButton(onClick = onInstallUpdate) {
                    Text(stringResource(R.string.update_install))
                }
            },
            dismissButton = {
                TextButton(onClick = onDismiss) {
                    Text(stringResource(R.string.common_cancel))
                }
            },
        )

        is AppUpdateStatus.Error -> AlertDialog(
            onDismissRequest = onDismiss,
            title = { Text(stringResource(R.string.update_error_title)) },
            text = { Text(status.message) },
            confirmButton = {
                TextButton(onClick = onCheckForUpdates) {
                    Text(stringResource(R.string.update_retry))
                }
            },
            dismissButton = {
                TextButton(onClick = onDismiss) {
                    Text(stringResource(R.string.common_cancel))
                }
            },
        )

        else -> Unit
    }
}
