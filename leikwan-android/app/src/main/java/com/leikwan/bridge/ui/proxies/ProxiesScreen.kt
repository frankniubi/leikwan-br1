package com.leikwan.bridge.ui.proxies

import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.QrCodeScanner
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel

@Composable
fun ProxiesScreen(viewModel: ProxiesViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    var showAddUri by remember { mutableStateOf(false) }
    var showAddSub by remember { mutableStateOf(false) }
    var showQrScan by remember { mutableStateOf(false) }
    var showFabMenu by remember { mutableStateOf(false) }

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
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(state.nodes) { node ->
                        Card(modifier = Modifier.fillMaxWidth()) {
                            Row(
                                modifier = Modifier.padding(12.dp).fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(node.name, style = MaterialTheme.typography.titleMedium)
                                    Text(
                                        "${node.type.uppercase()} | ${node.originalServer}:${node.originalPort}",
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                                Row {
                                    Switch(
                                        checked = node.enabled,
                                        onCheckedChange = { viewModel.toggleNode(node) },
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
}
