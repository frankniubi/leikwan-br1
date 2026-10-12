package com.leikwan.bridge.ui.connections

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.outlined.AddLink
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.R
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.ui.approuting.AppGroupLine

/** Services tab: service forwards with their node pairing, plus native outbound. */
@Composable
fun ServicesScreen(
    viewModel: ConnectionsViewModel,
    onImportNode: () -> Unit,
) {
    val state by viewModel.state.collectAsState()
    var nodePickerForward by remember { mutableStateOf<Long?>(null) }
    ServicesScreenContent(
        state = state,
        onSelectForward = viewModel::selectForward,
        onOpenNodePicker = { forwardId -> nodePickerForward = forwardId },
        onRefresh = viewModel::refreshForwards,
        onImportNode = onImportNode,
        onNativeForward = viewModel::selectNativeForward,
        onNativeRouteScope = viewModel::setNativeRouteScope,
        onNativeBypassChina = viewModel::setNativeBypassChina,
        onNativeRoutePolicy = viewModel::setNativeRoutePolicy,
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

@Composable
internal fun ServicesScreenContent(
    state: ConnectionsUiState,
    onSelectForward: (Long) -> Unit,
    onOpenNodePicker: (Long) -> Unit,
    onRefresh: () -> Unit,
    onImportNode: () -> Unit,
    onNativeForward: (Long) -> Unit = {},
    onNativeRouteScope: (String) -> Unit = {},
    onNativeBypassChina: (Boolean) -> Unit = {},
    onNativeRoutePolicy: (String) -> Unit = {},
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        Row(Modifier.fillMaxWidth().padding(start = 4.dp, top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(
                stringResource(R.string.nav_services),
                style = MaterialTheme.typography.headlineMedium,
                modifier = Modifier.weight(1f),
            )
            IconButton(onClick = onRefresh) {
                Icon(Icons.Default.Refresh, stringResource(R.string.connections_refresh))
            }
        }
        if (!state.hasUsablePath && !state.isLoading) {
            EmptyNodeCard(onImportNode = onImportNode)
        }
        ServicesSection(
            state = state,
            onSelectForward = onSelectForward,
            onOpenNodePicker = onOpenNodePicker,
            onRefresh = onRefresh,
            onImportNode = onImportNode,
        )
        if (state.nativeVisible) {
            NativeOutboundCard(
                state = state,
                onForward = onNativeForward,
                onRouteScope = onNativeRouteScope,
                onBypassChina = onNativeBypassChina,
                onRoutePolicy = onNativeRoutePolicy,
            )
        }
        // Room for the floating navigation dock.
        Spacer(Modifier.height(88.dp))
    }
}

/** "CAN -> HKG" reads better as "CAN → HKG". */
internal fun prettyRoute(value: String): String = value.replace("->", "→").replace(Regex("\\s+"), " ").trim()

internal fun ForwardListItem.routeLabel(): String =
    prettyRoute(direction.takeIf { it.isNotBlank() } ?: serviceName)

/** Local listen port and target, as the panel's forward table shows them. */
@Composable
internal fun ForwardListItem.endpointLabel(): String {
    val port = listenPort.takeIf { it > 0 }?.let { stringResource(R.string.connections_forward_listen_format, it) }
    val endpoint = target.trim().takeIf { it.isNotEmpty() }
    return listOfNotNull(port, endpoint).joinToString(" → ")
}

/**
 * Lines an app group can ride: Ultra services with a real target (a native-only carrier's
 * loopback target cannot reach a node), labelled like the Services list.
 */
internal fun ConnectionsUiState.appGroupLines(): List<AppGroupLine> = serviceForwards
    .filter { it.mode == ForwardMode.ULTRA && !it.target.trim().startsWith("127.") && !it.target.trim().startsWith("localhost") }
    .map { forward ->
        AppGroupLine(
            forwardId = forward.forwardId,
            label = "#${forward.forwardId} · ${forward.routeLabel()}",
            pairedNodeId = nodeByForward[forward.forwardId],
        )
    }

internal fun ConnectionsUiState.selectedRouteLabel(): String {
    if (nativeSelected) {
        nativeCarrier?.direction?.takeIf { it.isNotBlank() }?.let { return prettyRoute(it) }
    }
    val forward = forwards.firstOrNull { it.forwardId == selectedForwardId } ?: return ""
    val node = nodeFor(forward.forwardId)?.name
    return listOfNotNull(forward.routeLabel(), node).joinToString(" · ")
}

@Composable
internal fun EmptyNodeCard(onImportNode: () -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.colorScheme.secondaryContainer,
        contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
    ) {
        Row(modifier = Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Outlined.AddLink, contentDescription = null, modifier = Modifier.size(28.dp))
            Spacer(Modifier.width(14.dp))
            Text(
                stringResource(R.string.connections_empty_title),
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.weight(1f),
            )
            Button(onClick = onImportNode) { Text(stringResource(R.string.connections_empty_import)) }
        }
    }
}

@Composable
private fun ServicesSection(
    state: ConnectionsUiState,
    onSelectForward: (Long) -> Unit,
    onOpenNodePicker: (Long) -> Unit,
    onRefresh: () -> Unit,
    onImportNode: () -> Unit,
) {
    // Native outbound rules have their own card while it is shown; without it they stay listed,
    // marked, but cannot be picked (they never carry a node).
    val rows = if (state.nativeVisible) state.serviceForwards else state.forwards
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (state.isLoading) {
            LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
        }
        if (rows.isEmpty() && state.nativeVisible) return@Column
        Surface(
            modifier = Modifier.fillMaxWidth(),
            shape = MaterialTheme.shapes.large,
            color = MaterialTheme.colorScheme.surfaceContainerLow,
        ) {
            if (rows.isEmpty()) {
                Text(
                    stringResource(R.string.connections_empty_forwards),
                    modifier = Modifier.fillMaxWidth().padding(24.dp),
                    textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            } else {
                Column {
                    rows.forEachIndexed { index, forward ->
                        if (index > 0) {
                            HorizontalDivider(
                                modifier = Modifier.padding(start = 56.dp),
                                color = MaterialTheme.colorScheme.outlineVariant,
                            )
                        }
                        ServiceRow(
                            forward = forward,
                            node = state.nodeFor(forward.forwardId),
                            hasNodes = state.hasNode,
                            selected = !state.nativeSelected && forward.forwardId == state.selectedForwardId,
                            locked = state.vpnStatus != VpnStatus.DISCONNECTED,
                            nativeRule = state.isNativeForward(forward),
                            onSelect = { onSelectForward(forward.forwardId) },
                            onOpenNodePicker = { onOpenNodePicker(forward.forwardId) },
                            onImportNode = onImportNode,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun ServiceRow(
    forward: ForwardListItem,
    node: ProxyNode?,
    hasNodes: Boolean,
    selected: Boolean,
    locked: Boolean,
    nativeRule: Boolean,
    onSelect: () -> Unit,
    onOpenNodePicker: () -> Unit,
    onImportNode: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(enabled = !locked && !nativeRule, role = Role.RadioButton, onClick = onSelect)
            .padding(start = 4.dp, end = 12.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = onSelect, enabled = !locked && !nativeRule)
        Column(Modifier.weight(1f).padding(vertical = 6.dp)) {
            Text(
                stringResource(R.string.connections_forward_title_format, forward.forwardId, forward.routeLabel()),
                style = MaterialTheme.typography.titleMedium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            val meta = forward.endpointLabel()
            if (meta.isNotBlank()) {
                Text(
                    meta,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
        Spacer(Modifier.width(8.dp))
        when {
            nativeRule -> Text(
                stringResource(R.string.connections_native_title),
                color = MaterialTheme.colorScheme.primary,
                style = MaterialTheme.typography.labelLarge,
            )
            !hasNodes -> TextButton(onClick = onImportNode) {
                Text(stringResource(R.string.connections_wait_match))
            }
            else -> Surface(
                onClick = onOpenNodePicker,
                shape = CircleShape,
                color = MaterialTheme.colorScheme.secondaryContainer,
                contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
            ) {
                Text(
                    stringResource(
                        R.string.connections_node_format,
                        node?.name ?: stringResource(R.string.connections_node_placeholder),
                    ),
                    modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp).width(IntrinsicNodeWidth),
                    style = MaterialTheme.typography.labelLarge,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    textAlign = TextAlign.Center,
                )
            }
        }
    }
}

/** Keeps long node names from squeezing the route label. */
private val IntrinsicNodeWidth = 96.dp

/**
 * Native outbound rules, listed directly: each one is picked like a service but never pairs with a
 * node, because traffic leaves through the panel's own exit. The settings drawer holds the scope
 * and policy for the picked rule.
 */
@Composable
private fun NativeOutboundCard(
    state: ConnectionsUiState,
    onForward: (Long) -> Unit,
    onRouteScope: (String) -> Unit,
    onBypassChina: (Boolean) -> Unit,
    onRoutePolicy: (String) -> Unit,
) {
    var drawerExpanded by rememberSaveable { mutableStateOf(false) }
    val carrier = state.nativeCarrier
    val locked = state.vpnStatus != VpnStatus.DISCONNECTED
    val carriers = state.nativeOutbound?.forwards.orEmpty()
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.colorScheme.surfaceContainerLow,
    ) {
        Column {
            Row(
                modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 4.dp, top = 8.dp, bottom = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(stringResource(R.string.connections_native_title), style = MaterialTheme.typography.titleMedium)
                    Text(
                        stringResource(R.string.connections_native_no_node),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                IconButton(onClick = { drawerExpanded = !drawerExpanded }) {
                    Icon(
                        imageVector = if (drawerExpanded) Icons.Default.ExpandLess else Icons.Default.ExpandMore,
                        contentDescription = stringResource(
                            if (drawerExpanded) R.string.connections_section_collapse
                            else R.string.connections_section_expand,
                        ),
                    )
                }
            }
            carriers.forEach { option ->
                val selected = state.nativeSelected && option.forwardId == state.nativeForwardId
                val forward = state.forwards.firstOrNull { it.forwardId == option.forwardId }
                HorizontalDivider(
                    modifier = Modifier.padding(start = 56.dp),
                    color = MaterialTheme.colorScheme.outlineVariant,
                )
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable(enabled = !locked, role = Role.RadioButton) { onForward(option.forwardId) }
                        .padding(start = 4.dp, end = 12.dp, top = 6.dp, bottom = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    RadioButton(selected = selected, onClick = { onForward(option.forwardId) }, enabled = !locked)
                    Column(Modifier.weight(1f).padding(vertical = 6.dp)) {
                        Text(
                            stringResource(
                                R.string.connections_forward_title_format,
                                option.forwardId,
                                prettyRoute(option.direction.ifBlank { forward?.routeLabel().orEmpty() }),
                            ),
                            style = MaterialTheme.typography.titleMedium,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        forward?.listenPort?.takeIf { it > 0 }?.let { port ->
                            Text(
                                stringResource(R.string.connections_forward_listen_format, port),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }
            if (drawerExpanded) {
                HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    carrier?.let {
                        Text(
                            stringResource(R.string.connections_forward_title_format, it.forwardId, prettyRoute(it.direction)),
                            style = MaterialTheme.typography.labelLarge,
                        )
                    }
                    if (carrier?.unrestrictedAllowed == true) {
                        SwitchRow(
                            label = stringResource(R.string.connections_native_unrestricted),
                            checked = state.nativeRoutePolicy == "unrestricted",
                            onCheckedChange = { enabled ->
                                onRoutePolicy(if (enabled) "unrestricted" else "restricted")
                            },
                            enabled = !locked,
                        )
                    }
                    val hasRestrictedScope = state.nativeScopes.any {
                        it.equals("cn_only", ignoreCase = true) || it.equals("intl_only", ignoreCase = true)
                    }
                    if (hasRestrictedScope) {
                        Text(
                            stringResource(R.string.connections_native_scope),
                            style = MaterialTheme.typography.labelLarge,
                        )
                        Column {
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
                    }
                    SwitchRow(
                        label = stringResource(R.string.connections_native_bypass_china),
                        checked = state.nativeBypassChina,
                        onCheckedChange = onBypassChina,
                        enabled = state.nativeRouteScope == "global",
                    )
                }
            }
        }
    }
}

@Composable
private fun SwitchRow(
    label: String,
    checked: Boolean,
    onCheckedChange: (Boolean) -> Unit,
    enabled: Boolean = true,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = onCheckedChange, enabled = enabled)
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
                                    // Nodes are shown as the user's own: no server and no protocol.
                                    stringResource(R.string.own_node_label),
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
