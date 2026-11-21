package com.leikwan.bridge.ui.connections

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.R
import com.leikwan.bridge.net.PortNetworkChoice
import com.leikwan.bridge.service.AppConnectionMode

@Composable
internal fun ConnectionModeCard(state: ConnectionsUiState, onMode: (AppConnectionMode) -> Unit,
    onNetwork: (PortNetworkChoice) -> Unit, onForward: (Long) -> Unit) {
    val idle = state.vpnStatus == VpnStatus.DISCONNECTED
    Surface(shape = MaterialTheme.shapes.large, tonalElevation = 1.dp) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.ports_mode), style = MaterialTheme.typography.titleMedium)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = state.connectionMode == AppConnectionMode.VPN, enabled = idle,
                    onClick = { onMode(AppConnectionMode.VPN) }, label = { Text(stringResource(R.string.ports_vpn)) })
                FilterChip(selected = state.connectionMode == AppConnectionMode.PORTS, enabled = idle,
                    onClick = { onMode(AppConnectionMode.PORTS) }, label = { Text(stringResource(R.string.ports_title)) })
            }
            if (state.connectionMode == AppConnectionMode.PORTS) {
                Text(stringResource(R.string.ports_explanation), style = MaterialTheme.typography.bodySmall)
                Text(stringResource(R.string.ports_network), style = MaterialTheme.typography.labelLarge)
                // Two rows fit narrow phones without hiding a transport choice.
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
                Text(stringResource(R.string.ports_select_forward), style = MaterialTheme.typography.labelLarge)
                state.serviceForwards.forEach { forward ->
                    Row(Modifier.fillMaxWidth()) {
                        Checkbox(checked = forward.forwardId in state.portForwardIds, enabled = idle,
                            onCheckedChange = { onForward(forward.forwardId) })
                        Column(Modifier.weight(1f).padding(top = 8.dp)) {
                            Text(stringResource(R.string.ports_forward_name, forward.forwardId, forward.serviceName))
                            Text(stringResource(R.string.ports_forward_endpoint, forward.listenPort, forward.target, forward.transport), style = MaterialTheme.typography.bodySmall)
                            if (forward.mode != com.leikwan.bridge.runtime.ForwardMode.ULTRA) {
                                Text(stringResource(R.string.ports_unsupported), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }
                state.portListeners.forEach { Text(it, style = MaterialTheme.typography.bodySmall) }
            }
        }
    }
}
