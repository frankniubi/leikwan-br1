package com.leikwan.bridge.ui.tunnels

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.BorderStroke
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardStrategy
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.ui.shared.RoutingDiagnosticsCard

@Composable
fun TunnelsScreen(viewModel: TunnelsViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    val activity = LocalContext.current as? MainActivity

    TunnelsScreenContent(
        state = state,
        onToggleVpn = {
            if (state.vpnStatus == VpnStatus.DISCONNECTED) {
                activity?.requestVpnPermission { viewModel.toggleVpn() }
                    ?: viewModel.toggleVpn()
            } else {
                viewModel.toggleVpn()
            }
        },
        onRefresh = viewModel::refreshForwards,
        onSelectForward = viewModel::selectForward,
        onSelectStrategy = viewModel::selectStrategy,
    )
}

@Composable
internal fun TunnelsScreenContent(
    state: TunnelsUiState,
    onToggleVpn: () -> Unit,
    onRefresh: () -> Unit,
    onSelectForward: (Long) -> Unit,
    onSelectStrategy: (ForwardStrategy) -> Unit,
) {
    Column(modifier = Modifier.fillMaxSize().padding(16.dp)) {
        VpnToggleCard(
            status = state.vpnStatus,
            selectedForwardId = state.selectedForwardId,
            forwards = state.forwards,
            onToggle = onToggleVpn,
        )

        Spacer(Modifier.height(16.dp))

        state.routingDiagnostics?.let { diagnostics ->
            RoutingDiagnosticsCard(model = diagnostics)
            Spacer(Modifier.height(16.dp))
        }

        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(stringResource(R.string.tunnels_select_forward), style = MaterialTheme.typography.titleLarge)
            IconButton(onClick = onRefresh) {
                Icon(Icons.Default.Refresh, stringResource(R.string.tunnels_refresh))
            }
        }

        state.error?.let { err ->
            Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(8.dp))
        }

        if (state.selectedForwardId > 0) {
            ForwardStrategySelector(
                selected = state.selectedStrategy,
                onSelect = onSelectStrategy,
            )
            Spacer(Modifier.height(16.dp))
        }

        if (state.isLoading) {
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
        } else if (state.forwards.isEmpty()) {
            Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                Text(stringResource(R.string.tunnels_empty_forwards), color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        } else {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(state.forwards) { forward ->
                    ForwardCard(
                        forward = forward,
                        isSelected = forward.forwardId == state.selectedForwardId,
                        isVpnRunning = state.vpnStatus != VpnStatus.DISCONNECTED,
                        onClick = { onSelectForward(forward.forwardId) },
                    )
                }
            }
        }
    }
}

@Composable
private fun ForwardStrategySelector(
    selected: ForwardStrategy,
    onSelect: (ForwardStrategy) -> Unit,
) {
    Column(modifier = Modifier.fillMaxWidth()) {
        Text(
            stringResource(R.string.tunnels_strategy_heading),
            style = MaterialTheme.typography.titleMedium,
        )
        Spacer(Modifier.height(6.dp))
        StrategyRadioRow(
            selected = selected == ForwardStrategy.SPEED_MAX,
            title = stringResource(R.string.tunnels_strategy_speed_max),
            description = stringResource(R.string.tunnels_strategy_speed_max_description),
            onClick = { onSelect(ForwardStrategy.SPEED_MAX) },
        )
        StrategyRadioRow(
            selected = selected == ForwardStrategy.LATENCY_BEST,
            title = stringResource(R.string.tunnels_strategy_latency_best),
            description = stringResource(R.string.tunnels_strategy_latency_best_description),
            onClick = { onSelect(ForwardStrategy.LATENCY_BEST) },
        )
        StrategyRadioRow(
            selected = selected == ForwardStrategy.RANDOM,
            title = stringResource(R.string.tunnels_strategy_random),
            description = stringResource(R.string.tunnels_strategy_random_description),
            onClick = { onSelect(ForwardStrategy.RANDOM) },
        )
    }
}

@Composable
private fun StrategyRadioRow(
    selected: Boolean,
    title: String,
    description: String,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
        verticalAlignment = Alignment.Top,
    ) {
        RadioButton(selected = selected, onClick = onClick)
        Column(modifier = Modifier.weight(1f).padding(top = 8.dp)) {
            Text(title, style = MaterialTheme.typography.bodyLarge)
            Text(
                description,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun VpnToggleCard(
    status: VpnStatus,
    selectedForwardId: Long,
    forwards: List<ForwardListItem>,
    onToggle: () -> Unit,
) {
    val statusColor by animateColorAsState(
        when (status) {
            VpnStatus.DISCONNECTED -> MaterialTheme.colorScheme.onSurfaceVariant
            VpnStatus.CONNECTING -> Color(0xFFF59E0B)
            VpnStatus.CONNECTED -> Color(0xFF22C55E)
        }, label = "status"
    )
    val statusText = when (status) {
        VpnStatus.DISCONNECTED -> if (selectedForwardId == 0L) {
            stringResource(R.string.tunnels_status_select_forward)
        } else {
            stringResource(R.string.tunnels_status_ready)
        }
        VpnStatus.CONNECTING -> stringResource(R.string.tunnels_status_connecting)
        VpnStatus.CONNECTED -> {
            val name = forwards.find { it.forwardId == selectedForwardId }?.let {
                if (it.serviceName.isNotEmpty()) {
                    it.serviceName
                } else {
                    stringResource(R.string.tunnels_forward_fallback_format, it.forwardId)
                }
            } ?: stringResource(R.string.tunnels_status_connected)
            stringResource(R.string.tunnels_status_connected_format, name)
        }
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(20.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(stringResource(R.string.tunnels_vpn), style = MaterialTheme.typography.titleLarge)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Surface(
                        modifier = Modifier.size(10.dp),
                        shape = MaterialTheme.shapes.small,
                        color = statusColor,
                    ) {}
                    Spacer(Modifier.width(8.dp))
                    Text(statusText, style = MaterialTheme.typography.bodyMedium)
                }
            }
            Switch(
                checked = status != VpnStatus.DISCONNECTED,
                onCheckedChange = { onToggle() },
                enabled = status != VpnStatus.CONNECTING,
            )
        }
    }
}

@Composable
private fun ForwardCard(
    forward: ForwardListItem,
    isSelected: Boolean,
    isVpnRunning: Boolean,
    onClick: () -> Unit,
) {
    val borderColor = if (isSelected) MaterialTheme.colorScheme.primary else Color.Transparent

    Card(
        modifier = Modifier.fillMaxWidth(),
        onClick = { if (!isVpnRunning) onClick() },
        enabled = !isVpnRunning,
        border = if (isSelected) BorderStroke(2.dp, borderColor) else null,
        colors = if (isSelected) {
            CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.3f))
        } else {
            CardDefaults.cardColors()
        },
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                if (isSelected) Icons.Default.CheckCircle else Icons.Default.RadioButtonUnchecked,
                contentDescription = if (isSelected) {
                    stringResource(R.string.tunnels_selected)
                } else {
                    stringResource(R.string.tunnels_not_selected)
                },
                tint = if (isSelected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    if (forward.serviceName.isNotEmpty()) {
                        forward.serviceName
                    } else {
                        stringResource(R.string.tunnels_forward_fallback_format, forward.forwardId)
                    },
                    style = MaterialTheme.typography.titleMedium,
                )
                Spacer(Modifier.height(4.dp))

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    AssistChip(onClick = {}, label = { Text(forward.methodDisplay) })
                    AssistChip(onClick = {}, label = { Text(forward.transport.uppercase()) })
                    forward.speedLimitMbps?.let {
                        AssistChip(onClick = {}, label = { Text(stringResource(R.string.tunnels_speed_mbps_format, it)) })
                    }
                }

            }
        }
    }
}
