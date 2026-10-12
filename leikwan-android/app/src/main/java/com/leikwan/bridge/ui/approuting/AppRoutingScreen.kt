package com.leikwan.bridge.ui.approuting

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.outlined.Android
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.ListItem
import androidx.compose.material3.ListItemDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.routing.AppPolicy
import com.leikwan.bridge.routing.AppPresetCatalog
import com.leikwan.bridge.routing.AppRoutingConfig
import com.leikwan.bridge.routing.AppVpnScope

@Composable
fun AppRoutingScreen(viewModel: AppRoutingViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    AppRoutingScreenContent(
        state = state,
        onScope = viewModel::setScope,
        onPolicy = viewModel::setPolicy,
        onGroupPolicy = viewModel::setGroupPolicy,
        onQuery = viewModel::setQuery,
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun AppRoutingScreenContent(
    state: AppRoutingUiState,
    onScope: (AppVpnScope) -> Unit,
    onPolicy: (String, AppPolicy, Long?) -> Unit,
    onGroupPolicy: (String, AppPolicy) -> Unit,
    onQuery: (String) -> Unit,
) {
    val zh = LocalConfiguration.current.locales[0]?.language.equals("zh", ignoreCase = true)
    val query = state.query.trim()
    val others = if (query.isEmpty()) state.otherApps else state.otherApps.filter {
        it.label.contains(query, ignoreCase = true) || it.packageName.contains(query, ignoreCase = true)
    }
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 32.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item(key = "scope") {
            Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surfaceContainerLow) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                        listOf(
                            AppVpnScope.ALL_APPS to R.string.app_routing_scope_all,
                            AppVpnScope.SELECTED_APPS to R.string.app_routing_scope_selected,
                        ).forEachIndexed { index, (scope, label) ->
                            SegmentedButton(
                                selected = state.config.scope == scope,
                                onClick = { onScope(scope) },
                                shape = SegmentedButtonDefaults.itemShape(index = index, count = 2),
                            ) { Text(stringResource(label), maxLines = 1) }
                        }
                    }
                }
            }
        }
        if (state.isLoading) {
            item(key = "loading") { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        }
        state.groups.forEach { group ->
            item(key = "group-${group.key}") {
                AppGroupCard(
                    title = if (zh) group.labelZh else group.labelEn,
                    apps = group.apps,
                    config = state.config,
                    nodes = state.nodes,
                    onPolicy = onPolicy,
                    onGroupPolicy = { policy -> onGroupPolicy(group.key, policy) },
                )
            }
        }
        if (!state.isLoading) {
            item(key = "others-header") {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        stringResource(R.string.app_routing_other_apps),
                        style = MaterialTheme.typography.titleSmall,
                        color = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.padding(start = 4.dp, top = 8.dp),
                    )
                    OutlinedTextField(
                        value = state.query,
                        onValueChange = onQuery,
                        modifier = Modifier.fillMaxWidth(),
                        singleLine = true,
                        leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                        placeholder = { Text(stringResource(R.string.app_routing_search)) },
                        shape = CircleShape,
                    )
                }
            }
            items(others, key = { "app-${it.packageName}" }) { app ->
                Surface(shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.surfaceContainerLow) {
                    AppRow(app = app, config = state.config, nodes = state.nodes, onPolicy = onPolicy)
                }
            }
        }
    }
}

@Composable
private fun AppGroupCard(
    title: String,
    apps: List<InstalledApp>,
    config: AppRoutingConfig,
    nodes: List<ProxyNode>,
    onPolicy: (String, AppPolicy, Long?) -> Unit,
    onGroupPolicy: (AppPolicy) -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(Modifier.fillMaxWidth().padding(start = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(
                title,
                style = MaterialTheme.typography.titleSmall,
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.weight(1f),
            )
            var open by remember { mutableStateOf(false) }
            Box {
                TextButton(onClick = { open = true }) {
                    Text(stringResource(R.string.app_routing_set_all))
                    Icon(Icons.Filled.ArrowDropDown, contentDescription = null)
                }
                DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
                    AppPolicy.entries.forEach { policy ->
                        DropdownMenuItem(
                            text = { Text(policyLabel(policy, null, nodes)) },
                            onClick = { open = false; onGroupPolicy(policy) },
                        )
                    }
                }
            }
        }
        Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surfaceContainerLow) {
            Column {
                apps.forEach { app -> AppRow(app = app, config = config, nodes = nodes, onPolicy = onPolicy) }
            }
        }
    }
}

@Composable
private fun AppRow(
    app: InstalledApp,
    config: AppRoutingConfig,
    nodes: List<ProxyNode>,
    onPolicy: (String, AppPolicy, Long?) -> Unit,
) {
    val policy = config.policyFor(app.packageName)
    val nodeId = config.entryFor(app.packageName)?.targetNodeId
    var open by remember { mutableStateOf(false) }
    ListItem(
        headlineContent = { Text(app.label, maxLines = 1, overflow = TextOverflow.Ellipsis) },
        supportingContent = if (app.packageName == AppPresetCatalog.GOOGLE_PLAY_SERVICES) {
            { Text(stringResource(R.string.app_routing_gms_hint)) }
        } else {
            null
        },
        leadingContent = {
            val icon = app.icon
            if (icon != null) {
                Image(icon, contentDescription = null, modifier = Modifier.size(40.dp).clip(MaterialTheme.shapes.small))
            } else {
                Icon(Icons.Outlined.Android, contentDescription = null, modifier = Modifier.size(40.dp))
            }
        },
        trailingContent = {
            Box {
                Surface(
                    onClick = { open = true },
                    shape = CircleShape,
                    color = if (policy == AppPolicy.FOLLOW) MaterialTheme.colorScheme.surfaceContainerHighest
                    else MaterialTheme.colorScheme.secondaryContainer,
                    contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
                ) {
                    Row(Modifier.padding(start = 12.dp, end = 6.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            policyLabel(policy, nodeId, nodes),
                            style = MaterialTheme.typography.labelLarge,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.width(IntrinsicLabelWidth),
                        )
                        Icon(Icons.Filled.ArrowDropDown, contentDescription = null)
                    }
                }
                DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
                    DropdownMenuItem(
                        text = { Text(policyLabel(AppPolicy.FOLLOW, null, nodes)) },
                        onClick = { open = false; onPolicy(app.packageName, AppPolicy.FOLLOW, null) },
                    )
                    DropdownMenuItem(
                        text = { Text(policyLabel(AppPolicy.TUNNEL, null, nodes)) },
                        onClick = { open = false; onPolicy(app.packageName, AppPolicy.TUNNEL, null) },
                    )
                    // Pinning to one node only makes sense with more than one node.
                    if (nodes.size > 1) {
                        nodes.forEach { node ->
                            DropdownMenuItem(
                                text = { Text(policyLabel(AppPolicy.TUNNEL, node.id, nodes)) },
                                onClick = { open = false; onPolicy(app.packageName, AppPolicy.TUNNEL, node.id) },
                            )
                        }
                    }
                    DropdownMenuItem(
                        text = { Text(policyLabel(AppPolicy.DIRECT, null, nodes)) },
                        onClick = { open = false; onPolicy(app.packageName, AppPolicy.DIRECT, null) },
                    )
                    DropdownMenuItem(
                        text = { Text(policyLabel(AppPolicy.BYPASS, null, nodes)) },
                        onClick = { open = false; onPolicy(app.packageName, AppPolicy.BYPASS, null) },
                    )
                }
            }
        },
        colors = ListItemDefaults.colors(containerColor = MaterialTheme.colorScheme.surfaceContainerLow),
    )
}

/** Keeps the policy chip from squeezing the app name. */
private val IntrinsicLabelWidth = 88.dp

@Composable
private fun policyLabel(policy: AppPolicy, nodeId: Long?, nodes: List<ProxyNode>): String = when (policy) {
    AppPolicy.FOLLOW -> stringResource(R.string.app_policy_follow)
    AppPolicy.TUNNEL -> nodes.firstOrNull { it.id == nodeId }?.let { stringResource(R.string.app_policy_tunnel_node_format, it.name) }
        ?: stringResource(R.string.app_policy_tunnel)
    AppPolicy.DIRECT -> stringResource(R.string.app_policy_direct)
    AppPolicy.BYPASS -> stringResource(R.string.app_policy_bypass)
}
