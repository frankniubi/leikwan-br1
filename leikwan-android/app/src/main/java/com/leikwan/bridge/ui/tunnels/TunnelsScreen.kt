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
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.service.ProxyConnectionMode
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
    )
}

@Composable
internal fun TunnelsScreenContent(
    state: TunnelsUiState,
    onToggleVpn: () -> Unit,
    onRefresh: () -> Unit,
    onSelectForward: (Long) -> Unit,
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
            Text("Select a Forward", style = MaterialTheme.typography.titleLarge)
            IconButton(onClick = onRefresh) {
                Icon(Icons.Default.Refresh, "Refresh")
            }
        }

        if (state.hiddenUnsupportedMptcpCount > 0) {
            Spacer(Modifier.height(8.dp))
            Text(
                "Hidden ${state.hiddenUnsupportedMptcpCount} unsupported MPTCP forward(s)",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        if (state.connectionMode == ProxyConnectionMode.DIRECT) {
            Spacer(Modifier.height(8.dp))
            Text(
                "Direct mode is controlled from the Proxies screen.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        state.error?.let { err ->
            Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(8.dp))
        }

        if (state.isLoading) {
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
        } else if (state.forwards.isEmpty()) {
            Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                Text("No forwards assigned", color = MaterialTheme.colorScheme.onSurfaceVariant)
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
        VpnStatus.DISCONNECTED -> if (selectedForwardId == 0L) "Select a forward below" else "Ready to connect"
        VpnStatus.CONNECTING -> "Connecting..."
        VpnStatus.CONNECTED -> {
            val name = forwards.find { it.forwardId == selectedForwardId }?.let {
                it.serviceName.ifEmpty { "Forward #${it.forwardId}" }
            } ?: "Connected"
            "Connected: $name"
        }
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(20.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text("VPN", style = MaterialTheme.typography.titleLarge)
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
                contentDescription = if (isSelected) "Selected" else "Not selected",
                tint = if (isSelected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    forward.serviceName.ifEmpty { "Forward #${forward.forwardId}" },
                    style = MaterialTheme.typography.titleMedium,
                )
                Spacer(Modifier.height(4.dp))

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    AssistChip(onClick = {}, label = { Text(forward.mode.wireValue.uppercase()) })
                    AssistChip(onClick = {}, label = { Text(forward.transport.uppercase()) })
                    forward.speedLimitMbps?.let {
                        AssistChip(onClick = {}, label = { Text("${it} Mbps") })
                    }
                }

                Spacer(Modifier.height(8.dp))
                Text(
                    "Target: ${forward.target}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    "Listen: 127.0.0.1:${forward.listenPort}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}
