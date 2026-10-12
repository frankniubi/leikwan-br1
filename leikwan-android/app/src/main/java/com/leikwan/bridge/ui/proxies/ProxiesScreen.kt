package com.leikwan.bridge.ui.proxies

import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Surface
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.DropdownMenu
import androidx.compose.material.icons.outlined.Cloud
import androidx.compose.material.icons.filled.MoreVert
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
import androidx.compose.material.icons.filled.PhonelinkErase
import androidx.compose.material3.AlertDialog
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Card
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material.icons.outlined.CloudDownload
import androidx.compose.material.icons.outlined.CloudUpload
import androidx.compose.material.icons.outlined.VisibilityOff
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
    val hiddenCloudNodes by viewModel.hiddenCloudNodes.collectAsState()
    var showHiddenCloudNodes by remember { mutableStateOf(false) }
    // Deleting a cloud node removes it from every device, so it asks first.
    var confirmDeleteNode by remember { mutableStateOf<com.leikwan.bridge.data.ProxyNode?>(null) }

    if (showQrScan) {
        QrScanScreen(
            onResult = { text -> viewModel.importAny(text); showQrScan = false },
            onDismiss = { showQrScan = false },
        )
        return
    }

    Box(modifier = Modifier.fillMaxSize()) {
        Column(modifier = Modifier.fillMaxSize().padding(horizontal = 16.dp, vertical = 12.dp)) {
            // Title row; the cloud actions are icons so the page stays quiet.
            Row(
                modifier = Modifier.fillMaxWidth().padding(start = 4.dp, top = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    stringResource(R.string.proxies_title),
                    style = MaterialTheme.typography.headlineMedium,
                    modifier = Modifier.weight(1f),
                )
                if (hiddenCloudNodes.isNotEmpty()) {
                    IconButton(onClick = { showHiddenCloudNodes = true }, enabled = !state.isLoading) {
                        Icon(
                            Icons.Outlined.VisibilityOff,
                            contentDescription = stringResource(R.string.hosted_node_hidden_format, hiddenCloudNodes.size),
                        )
                    }
                }
                IconButton(onClick = { viewModel.refreshFromCloud() }, enabled = !state.isLoading) {
                    Icon(Icons.Outlined.CloudDownload, contentDescription = stringResource(R.string.hosted_node_refresh))
                }
                IconButton(onClick = { viewModel.syncAllToCloud() }, enabled = !state.isLoading) {
                    Icon(Icons.Outlined.CloudUpload, contentDescription = stringResource(R.string.hosted_node_sync_all))
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
                    Text(stringResource(R.string.proxies_empty), color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                // A node is shown as "your own node": no protocol, server or credential details.
                LazyColumn(
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                    contentPadding = androidx.compose.foundation.layout.PaddingValues(bottom = 160.dp),
                ) {
                    itemsIndexed(
                        items = state.nodes,
                        key = { _, node -> node.id },
                    ) { _, node ->
                        val selected = state.selectedProxyId == node.id
                        Surface(
                            onClick = { viewModel.selectNode(node) },
                            modifier = Modifier.fillMaxWidth(),
                            shape = MaterialTheme.shapes.large,
                            color = if (selected) MaterialTheme.colorScheme.secondaryContainer
                            else MaterialTheme.colorScheme.surfaceContainerLow,
                        ) {
                            Row(
                                modifier = Modifier.padding(start = 16.dp, end = 4.dp, top = 12.dp, bottom = 12.dp).fillMaxWidth(),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(
                                        node.name,
                                        style = MaterialTheme.typography.titleMedium,
                                        maxLines = 1,
                                        overflow = TextOverflow.Ellipsis,
                                    )
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        if (node.hostedId != null) {
                                            Icon(
                                                Icons.Outlined.Cloud,
                                                contentDescription = stringResource(R.string.hosted_node_badge),
                                                modifier = Modifier.size(14.dp),
                                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                            )
                                            Spacer(Modifier.width(4.dp))
                                        }
                                        Text(
                                            stringResource(R.string.own_node_label),
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }
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
                                var menuOpen by remember { mutableStateOf(false) }
                                Box {
                                    IconButton(onClick = { menuOpen = true }) {
                                        Icon(Icons.Default.MoreVert, contentDescription = stringResource(R.string.proxies_more_actions))
                                    }
                                    DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                                        DropdownMenuItem(
                                            text = { Text(stringResource(R.string.proxies_modify_proxy)) },
                                            onClick = { menuOpen = false; editingNode = node },
                                        )
                                        DropdownMenuItem(
                                            text = { Text(stringResource(R.string.proxies_duplicate_proxy)) },
                                            onClick = { menuOpen = false; viewModel.duplicateNode(node) },
                                        )
                                        if (node.hostedId == null && HostedNodeIdentity.isUploadable(node)) {
                                            DropdownMenuItem(
                                                text = { Text(stringResource(R.string.hosted_node_upload)) },
                                                onClick = { menuOpen = false; viewModel.uploadToCloud(node) },
                                            )
                                        }
                                        if (node.hostedId != null) {
                                            DropdownMenuItem(
                                                text = { Text(stringResource(R.string.hosted_node_remove_local)) },
                                                onClick = { menuOpen = false; viewModel.removeFromThisDevice(node) },
                                            )
                                        }
                                        DropdownMenuItem(
                                            text = {
                                                Text(stringResource(R.string.common_delete), color = MaterialTheme.colorScheme.error)
                                            },
                                            onClick = {
                                                menuOpen = false
                                                if (node.hostedId != null) confirmDeleteNode = node else viewModel.deleteNode(node)
                                            },
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
            modifier = Modifier.align(Alignment.BottomEnd).padding(end = 16.dp, bottom = 112.dp),
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

    confirmDeleteNode?.let { node ->
        AlertDialog(
            onDismissRequest = { confirmDeleteNode = null },
            title = { Text(node.name) },
            text = { Text(stringResource(R.string.hosted_node_delete_everywhere_confirm)) },
            confirmButton = {
                TextButton(onClick = { viewModel.deleteNode(node); confirmDeleteNode = null }) {
                    Text(stringResource(R.string.common_delete), color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = {
                TextButton(onClick = { confirmDeleteNode = null }) { Text(stringResource(R.string.common_cancel)) }
            },
        )
    }

    if (showHiddenCloudNodes) {
        AlertDialog(
            onDismissRequest = { showHiddenCloudNodes = false },
            title = { Text(stringResource(R.string.hosted_node_hidden_title)) },
            text = {
                Column {
                    hiddenCloudNodes.forEach { (hostedId, name) ->
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(name, modifier = Modifier.weight(1f))
                            TextButton(onClick = { viewModel.restoreHiddenCloudNodes(listOf(hostedId)) }) {
                                Text(stringResource(R.string.hosted_node_restore))
                            }
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { viewModel.restoreHiddenCloudNodes(); showHiddenCloudNodes = false }) {
                    Text(stringResource(R.string.hosted_node_restore_all))
                }
            },
            dismissButton = {
                TextButton(onClick = { showHiddenCloudNodes = false }) {
                    Text(stringResource(R.string.hosted_node_close))
                }
            },
        )
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
