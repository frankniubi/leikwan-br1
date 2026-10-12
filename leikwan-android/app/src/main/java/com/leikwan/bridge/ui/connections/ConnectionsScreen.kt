package com.leikwan.bridge.ui.connections

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.CallSplit
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material.icons.outlined.Apps
import androidx.compose.material.icons.outlined.DataUsage
import androidx.compose.material.icons.outlined.Hub
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.Lan
import androidx.compose.material.icons.outlined.ListAlt
import androidx.compose.material.icons.outlined.Speed
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.R
import com.leikwan.bridge.routing.AppPolicy
import com.leikwan.bridge.routing.AppVpnScope
import com.leikwan.bridge.service.TrafficFormat
import com.leikwan.bridge.service.TrafficMonitor
import com.leikwan.bridge.service.TrafficSnapshot
import com.leikwan.bridge.session.DeviceLimitNotice
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.ui.shared.UpdateBanner
import com.leikwan.bridge.ui.shared.deviceLimitMessage
import com.leikwan.bridge.ui.theme.LocalStatusColors
import com.leikwan.bridge.update.AppUpdateController
import com.leikwan.bridge.update.AppUpdateState
import kotlinx.coroutines.delay

/** Dashboard tab, laid out after FlClash: a status header over a grid of small widget cards. */
@Composable
fun ConnectionsScreen(
    viewModel: ConnectionsViewModel,
    onOpenServices: () -> Unit = {},
    onOpenAppRouting: () -> Unit = {},
    onImportNode: () -> Unit = {},
) {
    val state by viewModel.state.collectAsState()
    val traffic by TrafficMonitor.state.collectAsState()
    val updateState by AppUpdateController.state.collectAsState()
    val context = LocalContext.current
    LaunchedEffect(Unit) { viewModel.refreshLocalSettings() }

    ConnectionsScreenContent(
        state = state,
        traffic = traffic,
        updateState = updateState,
        onUpdate = { AppUpdateController.downloadAndInstall(context) },
        onSetRuleMode = viewModel::setRuleMode,
        onOpenServices = onOpenServices,
        onOpenAppRouting = onOpenAppRouting,
        onImportNode = onImportNode,
    )
}

/** Starts or stops the tunnel, asking Android for VPN consent before the first start. */
internal fun connectToggle(
    viewModel: ConnectionsViewModel,
    state: ConnectionsUiState,
    activity: MainActivity?,
): () -> Unit = {
    val turningOn = state.vpnStatus == VpnStatus.DISCONNECTED
    if (turningOn && activity != null) {
        activity.requestVpnPermission { viewModel.toggleConnect() }
    } else {
        viewModel.toggleConnect()
    }
}

@Composable
internal fun ConnectionsScreenContent(
    state: ConnectionsUiState,
    traffic: TrafficSnapshot = TrafficSnapshot(),
    updateState: AppUpdateState = AppUpdateState.Idle,
    onUpdate: () -> Unit = {},
    onSetRuleMode: (Boolean) -> Unit = {},
    onOpenServices: () -> Unit = {},
    onOpenAppRouting: () -> Unit = {},
    onImportNode: () -> Unit = {},
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        DashboardHeader(state = state, traffic = traffic)

        UpdateBanner(state = updateState, onUpdate = onUpdate)
        state.deviceLimit?.let { notice -> DeviceLimitCard(notice) }
        state.error?.let { err ->
            Surface(
                modifier = Modifier.fillMaxWidth(),
                shape = MaterialTheme.shapes.medium,
                color = MaterialTheme.colorScheme.errorContainer,
                contentColor = MaterialTheme.colorScheme.onErrorContainer,
            ) {
                Text(err, modifier = Modifier.padding(14.dp), style = MaterialTheme.typography.bodyMedium)
            }
        }
        if (!state.hasUsablePath && !state.isLoading) {
            EmptyNodeCard(onImportNode = onImportNode)
        }

        NetworkSpeedCard(traffic = traffic)

        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutboundModeCard(ruleMode = state.ruleMode, onSetRuleMode = onSetRuleMode)
                AppRoutingCard(state = state, onClick = onOpenAppRouting)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                CurrentServiceCard(state = state, onClick = onOpenServices)
                TrafficUsageCard(traffic = traffic)
                ValueCard(
                    icon = Icons.Outlined.Lan,
                    title = stringResource(R.string.dash_connections),
                    value = traffic.connections.toString(),
                )
            }
        }

        // Room for the floating navigation dock.
        Spacer(Modifier.height(88.dp))
    }
}

@Composable
private fun DashboardHeader(state: ConnectionsUiState, traffic: TrafficSnapshot) {
    val status = LocalStatusColors.current
    val connecting = state.vpnStatus == VpnStatus.CONNECTING
    val selectingRoutes = connecting && (state.selectingRouteCandidates ?: 0) > 1
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    if (state.vpnStatus == VpnStatus.CONNECTED && traffic.runningSinceMs > 0) {
        LaunchedEffect(traffic.runningSinceMs) {
            while (true) {
                now = System.currentTimeMillis()
                delay(1_000)
            }
        }
    }
    val statusText = when (state.vpnStatus) {
        VpnStatus.CONNECTING -> if (selectingRoutes) {
            stringResource(R.string.connections_status_selecting_routes, state.selectingRouteCandidates ?: 0)
        } else {
            stringResource(R.string.connections_status_connecting)
        }
        VpnStatus.DISCONNECTED -> if (state.hasUsablePath) {
            stringResource(R.string.connections_status_disconnected)
        } else {
            stringResource(R.string.connections_status_no_node)
        }
        VpnStatus.CONNECTED -> if (traffic.runningSinceMs > 0) {
            stringResource(R.string.dash_status_running_format, TrafficFormat.duration(now - traffic.runningSinceMs))
        } else {
            stringResource(R.string.connections_status_connected_format, state.selectedRouteLabel())
        }
    }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(start = 4.dp, top = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.nav_dashboard), style = MaterialTheme.typography.headlineMedium)
                Text(
                    statusText,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            when (state.vpnStatus) {
                VpnStatus.CONNECTED -> Surface(shape = CircleShape, color = status.connected, modifier = Modifier.size(40.dp)) {
                    Box(contentAlignment = Alignment.Center) {
                        Icon(Icons.Filled.Check, contentDescription = null, tint = Color.White)
                    }
                }
                VpnStatus.CONNECTING -> CircularProgressIndicator(
                    modifier = Modifier.size(32.dp),
                    color = status.connecting,
                    strokeWidth = 3.dp,
                )
                VpnStatus.DISCONNECTED -> Surface(
                    shape = CircleShape,
                    color = MaterialTheme.colorScheme.surfaceContainerHigh,
                    modifier = Modifier.size(40.dp),
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        Icon(
                            Icons.Filled.PowerSettingsNew,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }
        if (connecting) {
            LinearProgressIndicator(modifier = Modifier.fillMaxWidth(), color = status.connecting)
        }
        if (selectingRoutes) {
            Text(
                stringResource(R.string.connections_status_selecting_routes_hint),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(start = 4.dp),
            )
        }
    }
}

/** Persistent while the panel refuses this device for a full online device limit. */
@Composable
private fun DeviceLimitCard(notice: DeviceLimitNotice) {
    val context = LocalContext.current
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.medium,
        color = MaterialTheme.colorScheme.errorContainer,
        contentColor = MaterialTheme.colorScheme.onErrorContainer,
    ) {
        Column(modifier = Modifier.padding(16.dp).fillMaxWidth()) {
            Text(stringResource(R.string.device_limit_title), style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(6.dp))
            Text(context.deviceLimitMessage(notice), style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun NetworkSpeedCard(traffic: TrafficSnapshot) {
    WidgetCard(
        icon = Icons.Outlined.Speed,
        title = stringResource(R.string.dash_network_speed),
        modifier = Modifier.fillMaxWidth(),
        contentPadding = false,
        trailing = {
            Text(
                "↑ ${TrafficFormat.rate(traffic.uplink)}  ↓ ${TrafficFormat.rate(traffic.downlink)}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        },
    ) {
        SpeedChart(
            values = traffic.history,
            color = MaterialTheme.colorScheme.primary,
            modifier = Modifier.fillMaxWidth().height(128.dp),
        )
    }
}

@Composable
private fun OutboundModeCard(ruleMode: Boolean, onSetRuleMode: (Boolean) -> Unit) {
    WidgetCard(icon = Icons.AutoMirrored.Outlined.CallSplit, title = stringResource(R.string.dash_outbound_mode)) {
        ModeOption(
            icon = Icons.Outlined.ListAlt,
            label = stringResource(R.string.dash_mode_rule),
            selected = ruleMode,
            onClick = { onSetRuleMode(true) },
        )
        ModeOption(
            icon = Icons.Outlined.Language,
            label = stringResource(R.string.dash_mode_global),
            selected = !ruleMode,
            onClick = { onSetRuleMode(false) },
        )
    }
}

@Composable
private fun ModeOption(icon: ImageVector, label: String, selected: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        shape = MaterialTheme.shapes.small,
        color = if (selected) MaterialTheme.colorScheme.secondaryContainer else Color.Transparent,
        contentColor = if (selected) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onSurface,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.padding(horizontal = 10.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(10.dp))
            Text(label, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun CurrentServiceCard(state: ConnectionsUiState, onClick: () -> Unit) {
    val label = state.selectedRouteLabel()
    WidgetCard(
        icon = Icons.Outlined.Hub,
        title = stringResource(R.string.dash_current_service),
        onClick = onClick,
    ) {
        Text(
            label.ifBlank { stringResource(R.string.dash_no_service) },
            style = MaterialTheme.typography.titleMedium,
            color = if (label.isBlank()) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.primary,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun TrafficUsageCard(traffic: TrafficSnapshot) {
    val upColor = MaterialTheme.colorScheme.tertiary
    val downColor = MaterialTheme.colorScheme.primary
    WidgetCard(icon = Icons.Outlined.DataUsage, title = stringResource(R.string.dash_traffic_usage)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TrafficDonut(
                upload = traffic.uplinkTotal,
                download = traffic.downlinkTotal,
                uploadColor = upColor,
                downloadColor = downColor,
                trackColor = MaterialTheme.colorScheme.surfaceContainerHighest,
                modifier = Modifier.size(52.dp),
            )
            Spacer(Modifier.width(12.dp))
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                LegendLine(color = upColor, text = "↑ " + TrafficFormat.bytes(traffic.uplinkTotal))
                LegendLine(color = downColor, text = "↓ " + TrafficFormat.bytes(traffic.downlinkTotal))
            }
        }
    }
}

@Composable
private fun LegendLine(color: Color, text: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Surface(shape = CircleShape, color = color, modifier = Modifier.size(8.dp)) {}
        Spacer(Modifier.width(6.dp))
        Text(text, style = MaterialTheme.typography.bodySmall, maxLines = 1)
    }
}

@Composable
private fun AppRoutingCard(state: ConnectionsUiState, onClick: () -> Unit) {
    val config = state.appRouting
    val scopeText = if (config.scope == AppVpnScope.ALL_APPS) {
        stringResource(R.string.dash_app_all)
    } else {
        stringResource(R.string.dash_app_selected_format, config.entries.count { it.policy != AppPolicy.BYPASS })
    }
    val ruleCount = config.routingRules().size
    WidgetCard(
        icon = Icons.Outlined.Apps,
        title = stringResource(R.string.dash_app_routing),
        onClick = onClick,
    ) {
        Text(scopeText, style = MaterialTheme.typography.titleMedium)
        Text(
            stringResource(R.string.dash_app_rules_format, ruleCount),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun ValueCard(icon: ImageVector, title: String, value: String) {
    WidgetCard(icon = icon, title = title) {
        Text(value, style = MaterialTheme.typography.titleLarge)
    }
}
