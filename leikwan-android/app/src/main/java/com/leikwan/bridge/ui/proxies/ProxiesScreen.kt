package com.leikwan.bridge.ui.proxies

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SmallFloatingActionButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.service.ProxyConnectionMode

@Composable
fun ProxiesScreen(viewModel: ProxiesViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    val activity = LocalContext.current as? MainActivity
    LaunchedEffect(Unit) {
        viewModel.refreshRoutingSelectorState()
    }
    var showAddUri by remember { mutableStateOf(false) }
    var showAddSub by remember { mutableStateOf(false) }
    var showQrScan by remember { mutableStateOf(false) }
    var showFabMenu by remember { mutableStateOf(false) }
    var editingNode by remember { mutableStateOf<com.leikwan.bridge.data.ProxyNode?>(null) }

    if (showQrScan) {
        QrScanScreen(
            onResult = { text -> viewModel.importAny(text); showQrScan = false },
            onDismiss = { showQrScan = false },
        )
        return
    }

    Box(modifier = Modifier.fillMaxSize()) {
        Column(modifier = Modifier.fillMaxSize().padding(16.dp)) {
            Text("Proxies", style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(8.dp))
            Text("Connection mode", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ProxyModeChip(
                    label = ProxyConnectionMode.SERVICES.label,
                    selected = state.connectionMode == ProxyConnectionMode.SERVICES,
                    onClick = { viewModel.setConnectionMode(ProxyConnectionMode.SERVICES) },
                )
                ProxyModeChip(
                    label = ProxyConnectionMode.DIRECT.label,
                    selected = state.connectionMode == ProxyConnectionMode.DIRECT,
                    onClick = { viewModel.setConnectionMode(ProxyConnectionMode.DIRECT) },
                )
            }
            Spacer(Modifier.height(8.dp))
            Card(
                modifier = Modifier.fillMaxWidth(),
                onClick = { activity?.navigateToRouting() },
            ) {
                Column(modifier = Modifier.padding(12.dp)) {
                    Text(
                        if (state.routingSelectorEnabled) {
                            "Routing rules: ON"
                        } else {
                            "Routing rules: OFF"
                        },
                        style = MaterialTheme.typography.titleSmall,
                    )
                    Text(
                        if (state.routingSelectorEnabled) {
                            "Selected proxy may be overridden by Routing."
                        } else {
                            "Selected proxy is authoritative."
                        },
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
            Spacer(Modifier.height(8.dp))

            state.error?.let { err ->
                Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                Spacer(Modifier.height(8.dp))
            }

            if (state.isLoading) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(8.dp))
            }

            if (state.nodes.isEmpty() && !state.isLoading) {
                Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                    Text("No proxy nodes. Tap + to add.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                val sections = remember(state.nodes) { ProxyListSections.build(state.nodes) }
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    sections.forEach { section ->
                        item(key = "section-${section.title}") {
                            Text(
                                text = section.title,
                                style = MaterialTheme.typography.titleMedium,
                                color = MaterialTheme.colorScheme.primary,
                                modifier = Modifier.padding(top = 4.dp, bottom = 4.dp),
                            )
                        }
                        itemsIndexed(
                            items = section.nodes,
                            key = { _, node -> node.id },
                        ) { _, node ->
                            Card(
                                modifier = Modifier.fillMaxWidth(),
                                onClick = {
                                    viewModel.selectNode(node)
                                    if (state.connectionMode == ProxyConnectionMode.DIRECT && node.enabled) {
                                        activity?.requestVpnPermission { viewModel.startDirectSelected() }
                                            ?: viewModel.startDirectSelected()
                                    }
                                },
                                border = if (state.selectedProxyId == node.id) {
                                    BorderStroke(2.dp, MaterialTheme.colorScheme.primary)
                                } else {
                                    null
                                },
                            ) {
                                Row(
                                    modifier = Modifier.padding(12.dp).fillMaxWidth(),
                                    horizontalArrangement = Arrangement.SpaceBetween,
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Column(modifier = Modifier.weight(1f)) {
                                        val summaryLines = remember(
                                            node.id,
                                            node.type,
                                            node.originalServer,
                                            node.originalPort,
                                            node.configJson,
                                        ) {
                                            ProxyProtocolDraftParser.summaryLines(node)
                                        }
                                        Text(node.name, style = MaterialTheme.typography.titleMedium)
                                        if (state.selectedProxyId == node.id) {
                                            Text(
                                                "Selected proxy",
                                                style = MaterialTheme.typography.bodySmall,
                                                color = MaterialTheme.colorScheme.primary,
                                            )
                                        }
                                        summaryLines.forEach { line ->
                                            Text(
                                                line,
                                                style = MaterialTheme.typography.bodySmall,
                                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                            )
                                        }
                                    }
                                    Row {
                                        IconButton(onClick = { viewModel.duplicateNode(node) }) {
                                            Icon(Icons.Default.ContentCopy, "Duplicate proxy")
                                        }
                                        IconButton(onClick = { editingNode = node }) {
                                            Icon(Icons.Default.Edit, "Modify proxy")
                                        }
                                        Switch(
                                            checked = node.enabled,
                                            onCheckedChange = {
                                                val willEnable = !node.enabled
                                                viewModel.toggleNode(node)
                                                if (willEnable) {
                                                    viewModel.selectNode(node)
                                                    if (state.connectionMode == ProxyConnectionMode.DIRECT) {
                                                        activity?.requestVpnPermission { viewModel.startDirectSelected() }
                                                            ?: viewModel.startDirectSelected()
                                                    }
                                                }
                                            },
                                        )
                                        IconButton(onClick = { viewModel.deleteNode(node) }) {
                                            Icon(
                                                Icons.Default.Delete,
                                                "Delete",
                                                tint = MaterialTheme.colorScheme.error,
                                            )
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        Column(
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
            horizontalAlignment = Alignment.End,
        ) {
            if (showFabMenu) {
                SmallFloatingActionButton(onClick = { showQrScan = true; showFabMenu = false }) {
                    Icon(Icons.Default.QrCodeScanner, "Scan QR")
                }
                Spacer(Modifier.height(8.dp))
                SmallFloatingActionButton(onClick = { showAddSub = true; showFabMenu = false }) {
                    Text("SUB", style = MaterialTheme.typography.labelMedium)
                }
                Spacer(Modifier.height(8.dp))
                SmallFloatingActionButton(onClick = { showAddUri = true; showFabMenu = false }) {
                    Text("URI", style = MaterialTheme.typography.labelMedium)
                }
                Spacer(Modifier.height(12.dp))
            }
            FloatingActionButton(onClick = { showFabMenu = !showFabMenu }) {
                Icon(Icons.Default.Add, "Add proxy")
            }
        }
    }

    if (showAddUri) {
        AddUriDialog(onDismiss = { showAddUri = false }, onAdd = { viewModel.addFromUri(it) })
    }
    if (showAddSub) {
        AddSubscriptionDialog(
            onDismiss = { showAddSub = false },
            onAdd = { name, url -> viewModel.addSubscription(name, url) },
        )
    }
    editingNode?.let { node ->
        EditProxyDialog(
            node = node,
            onDismiss = { editingNode = null },
            onSave = { draft ->
                viewModel.updateNode(node, draft)
                editingNode = null
            },
        )
    }
}

@Composable
private fun ProxyModeChip(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
) {
    AssistChip(
        onClick = onClick,
        label = { Text(label) },
        border = if (selected) BorderStroke(2.dp, MaterialTheme.colorScheme.primary) else null,
    )
}
