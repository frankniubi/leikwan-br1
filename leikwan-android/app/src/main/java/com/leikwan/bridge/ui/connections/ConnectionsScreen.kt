package com.leikwan.bridge.ui.connections

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.ui.MainActivity

@Composable
fun ConnectionsScreen(
    onImportNode: () -> Unit = {},
    viewModel: ConnectionsViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()
    val activity = LocalContext.current as? MainActivity
    var nodePickerForward by remember { mutableStateOf<Long?>(null) }

    ConnectionsScreenContent(
        state = state,
        onToggle = onToggle(viewModel, state, activity),
        onSelectForward = viewModel::selectForward,
        onOpenNodePicker = { forwardId -> nodePickerForward = forwardId },
        onRefresh = viewModel::refreshForwards,
        onImportNode = onImportNode,
        onNativeSelected = viewModel::setNativeSelected,
        onNativeForward = viewModel::setNativeForward,
        onNativeRouteScope = viewModel::setNativeRouteScope,
        onNativeBypassChina = viewModel::setNativeBypassChina,
    )

    nodePickerForward?.let { forwardId ->
        NodePickerDialog(
            forwardId = forwardId,
            nodes = state.nodes,
            nodeByForward = state.nodeByForward,
            onPick = { nodeId -> viewModel.selectNodeForForward(forwardId, nodeId); nodePickerForward = null },
            onImport = { onImportNode(); nodePickerForward = null },
            onDismiss = { nodePickerForward = null },
        )
    }
}

/** Ask Android for VPN consent before the first tunnel start. */
private fun onToggle(
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
    onToggle: () -> Unit,
    onSelectForward: (Long) -> Unit,
    onOpenNodePicker: (Long) -> Unit,
    onRefresh: () -> Unit,
    onImportNode: () -> Unit,
    onNativeSelected: (Boolean) -> Unit = {},
    onNativeForward: (Long) -> Unit = {},
    onNativeRouteScope: (String) -> Unit = {},
    onNativeBypassChina: (Boolean) -> Unit = {},
) {
    Column(modifier = Modifier.fillMaxSize().padding(16.dp)) {
        MasterSwitchCard(state = state, onToggle = onToggle)
        Spacer(Modifier.height(16.dp))

        if (state.nativeVisible) {
            NativeOutboundCard(
                state = state,
                onSelected = onNativeSelected,
                onForward = onNativeForward,
                onRouteScope = onNativeRouteScope,
                onBypassChina = onNativeBypassChina,
            )
            Spacer(Modifier.height(16.dp))
        }

        state.error?.let { err ->
            Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(8.dp))
        }

        if (!state.hasUsablePath) {
            EmptyNodeState(state = state, onImportNode = onImportNode, onRefresh = onRefresh)
        } else {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    stringResource(R.string.connections_pairing_heading),
                    style = MaterialTheme.typography.titleLarge,
                )
                IconButton(onClick = onRefresh) {
                    Icon(Icons.Default.Refresh, stringResource(R.string.connections_refresh))
                }
            }
            Spacer(Modifier.height(8.dp))

            if (state.isLoading) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(8.dp))
            }

            if (state.forwards.isEmpty()) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                    Text(
                        stringResource(R.string.connections_empty_forwards),
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(state.forwards, key = { it.forwardId }) { forward ->
                        PairingCard(
                            forward = forward,
                            node = state.nodeFor(forward.forwardId),
                            selected = forward.forwardId == state.selectedForwardId,
                            isConnected = state.vpnStatus != VpnStatus.DISCONNECTED,
                            nativeMode = state.nativeSelected && forward.forwardId == state.nativeForwardId,
                            onSelect = { onSelectForward(forward.forwardId) },
                            onOpenNodePicker = { onOpenNodePicker(forward.forwardId) },
                        )
                    }
                    item(key = "import-more") {
                        Row(
                            modifier = Modifier.fillMaxWidth().padding(top = 4.dp),
                            horizontalArrangement = Arrangement.Center,
                        ) {
                            TextButton(onClick = onImportNode) {
                                Text(stringResource(R.string.connections_import_more))
                            }
                        }
                    }
                }
            }
        }

        Spacer(Modifier.height(16.dp))
        SecurityNoteCard()
    }
}

@Composable
private fun MasterSwitchCard(state: ConnectionsUiState, onToggle: () -> Unit) {
    val statusColor = when (state.vpnStatus) {
        VpnStatus.DISCONNECTED -> MaterialTheme.colorScheme.onSurfaceVariant
        VpnStatus.CONNECTING -> Color(0xFFF59E0B)
        VpnStatus.CONNECTED -> Color(0xFF22C55E)
    }
    val statusText = when (state.vpnStatus) {
        VpnStatus.CONNECTING -> stringResource(R.string.connections_status_connecting)
        VpnStatus.DISCONNECTED -> if (state.hasUsablePath) {
            stringResource(R.string.connections_status_disconnected)
        } else {
            stringResource(R.string.connections_status_no_node)
        }
        VpnStatus.CONNECTED -> {
            val name = state.selectedForwardForwardName()
            stringResource(R.string.connections_status_connected_format, name, state.bridgedListenPort)
        }
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(20.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(stringResource(R.string.connections_master_title), style = MaterialTheme.typography.titleLarge)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Surface(modifier = Modifier.size(10.dp), shape = MaterialTheme.shapes.small, color = statusColor) {}
                    Spacer(Modifier.width(8.dp))
                    Text(statusText, style = MaterialTheme.typography.bodyMedium)
                }
            }
            Switch(
                checked = state.vpnStatus != VpnStatus.DISCONNECTED,
                onCheckedChange = { onToggle() },
                enabled = state.vpnStatus != VpnStatus.CONNECTING,
            )
        }
    }
}

private fun ConnectionsUiState.selectedForwardForwardName(): String {
    val forward = forwards.firstOrNull { it.forwardId == selectedForwardId } ?: return ""
    return forward.direction.takeIf { it.isNotBlank() } ?: forward.serviceName
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NativeOutboundCard(
    state: ConnectionsUiState,
    onSelected: (Boolean) -> Unit,
    onForward: (Long) -> Unit,
    onRouteScope: (String) -> Unit,
    onBypassChina: (Boolean) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    val carrier = state.nativeCarrier
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.connections_native_title), style = MaterialTheme.typography.titleLarge)
                    Text(
                        carrier?.direction?.takeIf { it.isNotBlank() }
                            ?: stringResource(R.string.connections_native_unknown),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Switch(checked = state.nativeSelected, onCheckedChange = onSelected)
            }
            Spacer(Modifier.height(8.dp))
            if (state.nativeOutbound?.forwards.orEmpty().size > 1) {
                ExposedDropdownMenuBox(
                    expanded = expanded,
                    onExpandedChange = { expanded = it },
                ) {
                    OutlinedTextField(
                        value = carrier?.direction.orEmpty(),
                        onValueChange = {},
                        readOnly = true,
                        label = { Text(stringResource(R.string.connections_native_forward)) },
                        modifier = Modifier.fillMaxWidth().menuAnchor(),
                        trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
                    )
                    ExposedDropdownMenu(
                        expanded = expanded,
                        onDismissRequest = { expanded = false },
                    ) {
                        state.nativeOutbound?.forwards.orEmpty().forEach { option ->
                            DropdownMenuItem(
                                text = { Text(option.direction) },
                                onClick = {
                                    expanded = false
                                    onForward(option.forwardId)
                                },
                            )
                        }
                    }
                }
                Spacer(Modifier.height(8.dp))
            }
            val hasRestrictedScope = state.nativeScopes.any {
                it.equals("cn_only", ignoreCase = true) || it.equals("intl_only", ignoreCase = true)
            }
            if (hasRestrictedScope) {
                Text(stringResource(R.string.connections_native_scope), style = MaterialTheme.typography.labelLarge)
                state.nativeScopes.forEach { scope ->
                    Row(
                        modifier = Modifier.fillMaxWidth().clickable { onRouteScope(scope) },
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        RadioButton(
                            selected = state.nativeRouteScope == scope,
                            onClick = { onRouteScope(scope) },
                        )
                        Text(when {
                            scope.equals("cn_only", ignoreCase = true) ->
                                stringResource(R.string.connections_native_scope_cn_only)
                            scope.equals("intl_only", ignoreCase = true) ->
                                stringResource(R.string.connections_native_scope_intl_only)
                            else -> stringResource(R.string.connections_native_scope_global)
                        })
                    }
                }
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.connections_native_bypass_china))
                Switch(
                    checked = state.nativeBypassChina,
                    onCheckedChange = onBypassChina,
                    enabled = state.nativeRouteScope == "global",
                )
            }
            Text(
                stringResource(R.string.connections_native_no_node),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun EmptyNodeState(
    state: ConnectionsUiState,
    onImportNode: () -> Unit,
    onRefresh: () -> Unit,
) {
    if (state.isLoading) {
        LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(8.dp))
    } else {
        Card(modifier = Modifier.fillMaxWidth(), colors = CardDefaults.cardColors()) {
            Column(
                modifier = Modifier.padding(20.dp).fillMaxWidth(),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(stringResource(R.string.connections_empty_title), style = MaterialTheme.typography.titleLarge)
                Spacer(Modifier.height(6.dp))
                Text(
                    stringResource(R.string.connections_empty_body),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(horizontal = 8.dp),
                )
                Spacer(Modifier.height(14.dp))
                TextButton(onClick = onImportNode) {
                    Text(stringResource(R.string.connections_empty_import))
                }
            }
        }

        Spacer(Modifier.height(16.dp))
        Text(
            stringResource(R.string.connections_wait_match),
            style = MaterialTheme.typography.titleMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(8.dp))

        if (state.forwards.isEmpty() && !state.isLoading) {
            Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                Text(stringResource(R.string.connections_empty_forwards), color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        } else {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(state.forwards, key = { it.forwardId }) { forward ->
                    WaitingForwardCard(forward, onRefresh)
                }
            }
        }
    }
}

@Composable
private fun WaitingForwardCard(forward: ForwardListItem, onRefresh: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f)),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    forward.direction.takeIf { it.isNotBlank() } ?: forward.serviceName,
                    style = MaterialTheme.typography.titleMedium,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    stringResource(R.string.connections_forward_target_format, forward.target),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    fontFamily = FontFamily.Monospace,
                )
                Text(
                    stringResource(R.string.connections_listen_format, forward.listenPort),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    fontFamily = FontFamily.Monospace,
                )
            }
            AssistChip(
                onClick = onRefresh,
                label = { Text(stringResource(R.string.connections_wait_match)) },
            )
        }
    }
}

@Composable
private fun PairingCard(
    forward: ForwardListItem,
    node: ProxyNode?,
    selected: Boolean,
    isConnected: Boolean,
    nativeMode: Boolean = false,
    onSelect: () -> Unit,
    onOpenNodePicker: () -> Unit,
) {
    val border = if (selected) BorderStroke(2.dp, MaterialTheme.colorScheme.primary) else null
    Card(
        modifier = Modifier.fillMaxWidth(),
        onClick = onSelect,
        enabled = !isConnected,
        border = border,
        colors = if (selected) {
            CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.3f))
        } else {
            CardDefaults.cardColors()
        },
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                RadioButton(selected = selected, onClick = onSelect)
                Spacer(Modifier.width(8.dp))
                Column(modifier = Modifier.weight(1f)) {
                    // Forward identity: direction + dest ip:port.
                    Text(
                        forward.direction.takeIf { it.isNotBlank() } ?: forward.serviceName,
                        style = MaterialTheme.typography.titleMedium,
                    )
                    Text(
                        stringResource(R.string.connections_forward_target_format, forward.target),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        fontFamily = FontFamily.Monospace,
                    )
                    Spacer(Modifier.height(6.dp))
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            stringResource(R.string.connections_listen_format, forward.listenPort),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            fontFamily = FontFamily.Monospace,
                        )
                        if (nativeMode) {
                            Text(
                                stringResource(R.string.connections_native_title),
                                color = MaterialTheme.colorScheme.primary,
                                style = MaterialTheme.typography.labelLarge,
                            )
                        } else {
                            TextButton(onClick = onOpenNodePicker) {
                                val nodeLabel = node?.name ?: stringResource(R.string.connections_node_placeholder)
                                Text(stringResource(R.string.connections_node_format, nodeLabel))
                            }
                        }
                    }
                }
            }
            Row(
                modifier = Modifier.fillMaxWidth().padding(top = 2.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                AssistChip(onClick = {}, label = { Text(forward.methodDisplay) })
                AssistChip(onClick = {}, label = { Text(forward.transport.uppercase()) })
                forward.speedLimitMbps?.let {
                    AssistChip(onClick = {}, label = { Text(stringResource(R.string.connections_speed_mbps_format, it)) })
                }
            }
        }
    }
}

@Composable
private fun SecurityNoteCard() {
    Card(modifier = Modifier.fillMaxWidth(), colors = CardDefaults.cardColors()) {
        Column(modifier = Modifier.padding(14.dp)) {
            Text(
                stringResource(R.string.connections_gate),
                style = MaterialTheme.typography.titleSmall,
            )
            Spacer(Modifier.height(4.dp))
            Text(
                stringResource(R.string.connections_gate_body),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun NodePickerDialog(
    forwardId: Long,
    nodes: List<ProxyNode>,
    nodeByForward: Map<Long, Long>,
    onPick: (Long) -> Unit,
    onImport: () -> Unit,
    onDismiss: () -> Unit,
) {
    val selectedNodeId = nodeByForward[forwardId]
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.connections_node_picker_title)) },
        text = {
            if (nodes.isEmpty()) {
                Text(stringResource(R.string.connections_node_picker_empty))
            } else {
                Column {
                    nodes.forEach { node ->
                        Row(
                            modifier = Modifier.fillMaxWidth().clickable { onPick(node.id) },
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            RadioButton(selected = node.id == selectedNodeId, onClick = { onPick(node.id) })
                            Column(modifier = Modifier.weight(1f)) {
                                Text(node.name, style = MaterialTheme.typography.bodyLarge)
                                Text(
                                    // The node's real server host:port is intentionally NOT shown: the
                                    // local outbound dials only 127.0.0.1:<forward listen port> (the
                                    // bridge), and the real server address is used by the forward
                                    // process on the far side. Expose just the protocol, not the server.
                                    node.type,
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onImport) { Text(stringResource(R.string.connections_import_more)) }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.common_cancel)) }
        },
    )
}
