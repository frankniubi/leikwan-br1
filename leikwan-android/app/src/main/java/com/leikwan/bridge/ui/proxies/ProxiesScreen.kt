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
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CloudUpload
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
import androidx.compose.material3.TextButton
import com.leikwan.bridge.data.hosted.HostedNodeIdentity
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.ui.MainActivity

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
            Text(stringResource(R.string.proxies_title), style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(8.dp))

            state.error?.let { err ->
                Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                Spacer(Modifier.height(8.dp))
            }

            // Hosted nodes ("上云"): minimal hooks; the redesigned screen can restyle these.
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TextButton(onClick = { viewModel.syncAllToCloud() }, enabled = !state.isLoading) {
                    Text(stringResource(R.string.hosted_node_sync_all))
                }
                TextButton(onClick = { viewModel.refreshFromCloud() }, enabled = !state.isLoading) {
                    Text(stringResource(R.string.hosted_node_refresh))
                }
            }

            if (state.isLoading) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(8.dp))
            }

            if (state.nodes.isEmpty() && !state.isLoading) {
                Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                    Text(stringResource(R.string.proxies_empty), color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                val sections = remember(state.nodes) { ProxyListSections.build(state.nodes) }
                val proxySummaryText = ProxySummaryText(
                    unknown = stringResource(R.string.common_unknown),
                    methodFormat = stringResource(R.string.proxy_summary_method_format),
                    uuidFormat = stringResource(R.string.proxy_summary_uuid_format),
                    publicKeyFormat = stringResource(R.string.proxy_summary_public_key_format),
                    addressFormat = stringResource(R.string.proxy_summary_address_format),
                )
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    sections.forEach { section ->
                        item(key = "section-${section.title}") {
                            val sectionTitle = if (section.title == "Other") {
                                stringResource(R.string.proxies_section_other)
                            } else {
                                section.title
                            }
                            Text(
                                text = sectionTitle,
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
                                onClick = { viewModel.selectNode(node) },
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
                                            proxySummaryText,
                                        ) {
                                            ProxyProtocolDraftParser.summaryLines(node, proxySummaryText)
                                        }
                                        Text(node.name, style = MaterialTheme.typography.titleMedium)
                                        if (node.hostedId != null) {
                                            Text(
                                                stringResource(R.string.hosted_node_badge),
                                                style = MaterialTheme.typography.labelSmall,
                                                color = MaterialTheme.colorScheme.tertiary,
                                            )
                                        }
                                        if (state.selectedProxyId == node.id) {
                                            Text(
                                                stringResource(R.string.proxies_selected_proxy),
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
                                        if (node.hostedId == null && HostedNodeIdentity.isUploadable(node)) {
                                            IconButton(onClick = { viewModel.uploadToCloud(node) }) {
                                                Icon(Icons.Default.CloudUpload, stringResource(R.string.hosted_node_upload))
                                            }
                                        }
                                        IconButton(onClick = { viewModel.duplicateNode(node) }) {
                                            Icon(Icons.Default.ContentCopy, stringResource(R.string.proxies_duplicate_proxy))
                                        }
                                        IconButton(onClick = { editingNode = node }) {
                                            Icon(Icons.Default.Edit, stringResource(R.string.proxies_modify_proxy))
                                        }
                                        Switch(
                                            checked = node.enabled,
                                            onCheckedChange = {
                                                val willEnable = !node.enabled
                                                viewModel.toggleNode(node)
                                                if (willEnable) {
                                                    viewModel.selectNode(node)
                                                }
                                            },
                                        )
                                        IconButton(onClick = { viewModel.deleteNode(node) }) {
                                            Icon(
                                                Icons.Default.Delete,
                                                stringResource(R.string.common_delete),
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
                    Icon(Icons.Default.QrCodeScanner, stringResource(R.string.proxies_scan_qr))
                }
                Spacer(Modifier.height(8.dp))
                SmallFloatingActionButton(onClick = { showAddSub = true; showFabMenu = false }) {
                    Text(stringResource(R.string.proxies_fab_subscription), style = MaterialTheme.typography.labelMedium)
                }
                Spacer(Modifier.height(8.dp))
                SmallFloatingActionButton(onClick = { showAddUri = true; showFabMenu = false }) {
                    Text(stringResource(R.string.proxies_fab_uri), style = MaterialTheme.typography.labelMedium)
                }
                Spacer(Modifier.height(12.dp))
            }
            FloatingActionButton(onClick = { showFabMenu = !showFabMenu }) {
                Icon(Icons.Default.Add, stringResource(R.string.proxies_add_proxy))
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
