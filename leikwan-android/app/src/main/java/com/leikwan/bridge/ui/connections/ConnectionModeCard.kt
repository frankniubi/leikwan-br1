package com.leikwan.bridge.ui.connections

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.R
import com.leikwan.bridge.net.PortNetworkChoice
import com.leikwan.bridge.service.AppConnectionMode

@Composable
internal fun ConnectionModeCard(state: ConnectionsUiState, onMode: (AppConnectionMode) -> Unit,
    onNetwork: (PortNetworkChoice) -> Unit) {
    var advanced by rememberSaveable { mutableStateOf(false) }
    val idle = state.vpnStatus == VpnStatus.DISCONNECTED && !state.modeSwitching
    Surface(shape = MaterialTheme.shapes.large, tonalElevation = 1.dp) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = state.connectionMode == AppConnectionMode.VPN, enabled = !state.modeSwitching,
                    onClick = { onMode(AppConnectionMode.VPN) }, label = { Text(stringResource(R.string.ports_vpn)) })
                FilterChip(selected = state.connectionMode == AppConnectionMode.PORTS, enabled = !state.modeSwitching,
                    onClick = { onMode(AppConnectionMode.PORTS) }, label = { Text(stringResource(R.string.ports_only)) })
                if (state.modeSwitching) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
            }
            if (state.connectionMode == AppConnectionMode.PORTS) {
                Text(stringResource(R.string.ports_all_summary, state.portForwards.size), style = MaterialTheme.typography.labelLarge)
                state.portForwards.forEach { forward ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.ports_local_port, forward.listenPort), style = MaterialTheme.typography.bodyMedium)
                            Text(forward.routeLabel(), style = MaterialTheme.typography.bodySmall)
                        }
                        Text(stringResource(if (forward.forwardId in state.activePortIds) R.string.ports_active else R.string.ports_ready),
                            style = MaterialTheme.typography.labelMedium)
                    }
                }
                if (state.unsupportedPortCount > 0) Text(stringResource(R.string.ports_skipped_summary, state.unsupportedPortCount),
                    style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                TextButton(onClick = { advanced = !advanced }, contentPadding = PaddingValues(0.dp)) {
                    Text(stringResource(R.string.ports_advanced))
                }
                if (advanced) {
                    Text(stringResource(R.string.ports_network), style = MaterialTheme.typography.labelLarge)
                    PortNetworkChoice.entries.chunked(2).forEach { choices ->
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            choices.forEach { choice ->
                                val label = when (choice) {
                                    PortNetworkChoice.AUTO -> R.string.ports_auto
                                    PortNetworkChoice.WIFI -> R.string.ports_wifi
                                    PortNetworkChoice.CELLULAR -> R.string.ports_cellular
                                    PortNetworkChoice.ETHERNET -> R.string.ports_ethernet
                                }
                                FilterChip(selected = state.portNetwork == choice, enabled = idle,
                                    onClick = { onNetwork(choice) }, label = { Text(stringResource(label)) })
                            }
                        }
                    }
                    Text(stringResource(R.string.ports_advanced_note), style = MaterialTheme.typography.bodySmall)
                    state.forwards.filter { forward -> state.portForwards.none { it.forwardId == forward.forwardId } }.forEach { forward ->
                        Text(stringResource(R.string.ports_skipped_reason, forward.serviceName), style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}
