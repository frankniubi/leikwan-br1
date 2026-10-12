package com.leikwan.bridge.ui.shared

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.SystemUpdate
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.R
import com.leikwan.bridge.update.AppUpdateState

/** Short "v0.2.42" label of the running build. */
@Composable
fun appVersionShort(): String = stringResource(R.string.app_version_short, BuildConfig.VERSION_NAME)

/** Home-screen banner; shown only while a newer build is available, downloading or ready. */
@Composable
fun UpdateBanner(
    state: AppUpdateState,
    onUpdate: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val info = when (state) {
        is AppUpdateState.Available -> state.info
        is AppUpdateState.Downloading -> state.info
        is AppUpdateState.ReadyToInstall -> state.info
        is AppUpdateState.Failed -> state.info ?: return
        else -> return
    }
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.colorScheme.primaryContainer,
        contentColor = MaterialTheme.colorScheme.onPrimaryContainer,
    ) {
        Column(Modifier.padding(horizontal = 16.dp, vertical = 14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.SystemUpdate, contentDescription = null, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.update_available_title), style = MaterialTheme.typography.titleSmall)
                    Text(
                        when (state) {
                            is AppUpdateState.Downloading ->
                                stringResource(R.string.update_downloading_format, (state.progress * 100).toInt())
                            is AppUpdateState.Failed -> stringResource(R.string.update_failed)
                            else -> stringResource(R.string.update_available_body, info.versionName)
                        },
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                if (state !is AppUpdateState.Downloading) {
                    Spacer(Modifier.width(8.dp))
                    Button(onClick = onUpdate) {
                        Text(
                            stringResource(
                                when (state) {
                                    is AppUpdateState.ReadyToInstall -> R.string.update_action_install
                                    is AppUpdateState.Failed -> R.string.update_retry
                                    else -> R.string.update_action_update
                                },
                            ),
                        )
                    }
                }
            }
            if (state is AppUpdateState.Downloading) {
                Spacer(Modifier.height(10.dp))
                LinearProgressIndicator(progress = { state.progress }, modifier = Modifier.fillMaxWidth())
            }
            if (state is AppUpdateState.ReadyToInstall) {
                Spacer(Modifier.height(6.dp))
                Text(stringResource(R.string.update_install_hint), style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

/** Settings "About" body: running version plus the check / download / install control. */
@Composable
fun UpdateSettingsBlock(
    state: AppUpdateState,
    onCheck: () -> Unit,
    onUpdate: () -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(
            stringResource(R.string.app_version_format, BuildConfig.VERSION_NAME, BuildConfig.VERSION_CODE),
            style = MaterialTheme.typography.bodyMedium,
        )
        when (state) {
            AppUpdateState.Checking -> Row(verticalAlignment = Alignment.CenterVertically) {
                CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(10.dp))
                Text(stringResource(R.string.update_checking), style = MaterialTheme.typography.bodySmall)
            }
            AppUpdateState.UpToDate -> StatusLine(stringResource(R.string.update_up_to_date))
            is AppUpdateState.Failed -> if (state.info == null) {
                StatusLine(stringResource(R.string.update_failed), error = true)
            }
            else -> Unit
        }
        when (state) {
            is AppUpdateState.Available, is AppUpdateState.Downloading, is AppUpdateState.ReadyToInstall ->
                UpdateBanner(state = state, onUpdate = onUpdate)
            is AppUpdateState.Failed -> if (state.info != null) {
                UpdateBanner(state = state, onUpdate = onUpdate)
            } else {
                TextButton(onClick = onCheck) { Text(stringResource(R.string.update_retry)) }
            }
            AppUpdateState.Checking -> Unit
            else -> FilledTonalButton(onClick = onCheck) { Text(stringResource(R.string.update_check_now)) }
        }
    }
}

@Composable
private fun StatusLine(text: String, error: Boolean = false) {
    Text(
        text,
        style = MaterialTheme.typography.bodySmall,
        color = if (error) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
    )
}
