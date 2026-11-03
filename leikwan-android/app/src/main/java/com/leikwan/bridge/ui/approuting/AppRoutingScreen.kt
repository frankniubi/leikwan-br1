package com.leikwan.bridge.ui.approuting

import androidx.compose.foundation.Image
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.outlined.Android
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.routing.AppGroup
import com.leikwan.bridge.routing.AppPolicy
import com.leikwan.bridge.routing.AppPresetCatalog
import com.leikwan.bridge.routing.AppRoutingConfig
import com.leikwan.bridge.routing.AppVpnScope

@Composable
fun AppRoutingScreen(
    lines: List<AppGroupLine> = emptyList(),
    viewModel: AppRoutingViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()
    AppRoutingScreenContent(
        state = state,
        lines = lines,
        onScope = viewModel::setScope,
        onPolicy = viewModel::setPolicy,
        onGroupPolicy = viewModel::setGroupPolicy,
        onQuery = viewModel::setQuery,
        onAppGroup = viewModel::setAppGroup,
        onPresetAppGroup = viewModel::setPresetAppGroup,
        onSaveAppGroup = viewModel::saveAppGroup,
        onDeleteAppGroup = viewModel::deleteAppGroup,
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
    lines: List<AppGroupLine> = emptyList(),
    onAppGroup: (String, String) -> Unit = { _, _ -> },
    onPresetAppGroup: (String, String) -> Unit = { _, _ -> },
    onSaveAppGroup: (AppGroup) -> Unit = {},
    onDeleteAppGroup: (String) -> Unit = {},
) {
    val zh = LocalConfiguration.current.locales[0]?.language.equals("zh", ignoreCase = true)
    // The group being edited; an empty id means a new group.
    var editing by remember { mutableStateOf<AppGroup?>(null) }
    val allApps = remember(state.groups, state.otherApps) {
        (state.groups.flatMap { it.apps } + state.otherApps).distinctBy { it.packageName }
    }
    editing?.let { group ->
        AppGroupEditor(
            initial = group,
            lines = lines,
            nodes = state.nodes,
            apps = allApps,
            onDismiss = { editing = null },
            onSave = { onSaveAppGroup(it); editing = null },
            onDelete = { onDeleteAppGroup(group.id); editing = null },
        )
    }
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
        item(key = "app-groups") {
            AppGroupsSection(
                groups = state.config.groups,
                lines = lines,
                nodes = state.nodes,
                apps = allApps,
                onEdit = { editing = it },
                onAdd = {
                    val line = lines.firstOrNull()
                    editing = AppGroup(
                        id = "",
                        name = "",
                        forwardId = line?.forwardId ?: 0L,
                        nodeId = line?.pairedNodeId ?: state.nodes.firstOrNull()?.id ?: 0L,
                    )
                },
            )
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
                    onAppGroup = onAppGroup,
                    onGroupAppGroup = { groupId -> onPresetAppGroup(group.key, groupId) },
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
                    AppRow(app = app, config = state.config, nodes = state.nodes, onPolicy = onPolicy, onAppGroup = onAppGroup)
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
    onAppGroup: (String, String) -> Unit,
    onGroupAppGroup: (String) -> Unit,
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
                    config.groups.forEach { appGroup ->
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.app_policy_group_format, appGroup.name)) },
                            onClick = { open = false; onGroupAppGroup(appGroup.id) },
                        )
                    }
                }
            }
        }
        Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surfaceContainerLow) {
            Column {
                apps.forEach { app ->
                    AppRow(app = app, config = config, nodes = nodes, onPolicy = onPolicy, onAppGroup = onAppGroup)
                }
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
    onAppGroup: (String, String) -> Unit,
) {
    val policy = config.policyFor(app.packageName)
    val nodeId = config.entryFor(app.packageName)?.targetNodeId
    val appGroup = config.groupFor(app.packageName).takeIf { config.entryFor(app.packageName) == null }
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
                            appGroup?.name ?: policyLabel(policy, nodeId, nodes),
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
                    // A specific node is chosen through an app group (its own line and node).
                    config.groups.forEach { group ->
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.app_policy_group_format, group.name)) },
                            onClick = { open = false; onAppGroup(app.packageName, group.id) },
                        )
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

@Composable
private fun AppGroupsSection(
    groups: List<AppGroup>,
    lines: List<AppGroupLine>,
    nodes: List<ProxyNode>,
    apps: List<InstalledApp>,
    onEdit: (AppGroup) -> Unit,
    onAdd: () -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            stringResource(R.string.app_groups_title),
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.primary,
            modifier = Modifier.padding(start = 4.dp, top = 4.dp),
        )
        Text(
            stringResource(if (lines.isEmpty()) R.string.app_groups_no_lines else R.string.app_groups_hint),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(start = 4.dp),
        )
        if (groups.isNotEmpty()) {
            Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surfaceContainerLow) {
                Column {
                    groups.forEachIndexed { index, group ->
                        if (index > 0) HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                        val line = lines.firstOrNull { it.forwardId == group.forwardId }
                        val node = nodes.firstOrNull { it.id == group.nodeId }
                        val appNames = group.packages.mapNotNull { pkg -> apps.firstOrNull { it.packageName == pkg }?.label }
                        ListItem(
                            headlineContent = { Text(group.name, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            supportingContent = {
                                Column {
                                    Text(
                                        listOf(
                                            line?.label ?: stringResource(R.string.app_group_line_unavailable),
                                            node?.name ?: stringResource(R.string.app_group_node_unavailable),
                                        ).joinToString(" · "),
                                        maxLines = 1,
                                        overflow = TextOverflow.Ellipsis,
                                    )
                                    Text(
                                        if (appNames.isEmpty()) {
                                            stringResource(R.string.app_group_apps_none)
                                        } else {
                                            appNames.joinToString("、")
                                        },
                                        maxLines = 2,
                                        overflow = TextOverflow.Ellipsis,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            },
                            trailingContent = {
                                Text(
                                    stringResource(R.string.app_group_apps_count_format, group.packages.size),
                                    style = MaterialTheme.typography.labelMedium,
                                )
                            },
                            colors = ListItemDefaults.colors(containerColor = MaterialTheme.colorScheme.surfaceContainerLow),
                            modifier = Modifier.clickable { onEdit(group) },
                        )
                    }
                }
            }
        }
        FilledTonalButton(onClick = onAdd, enabled = lines.isNotEmpty() && nodes.isNotEmpty()) {
            Icon(Icons.Filled.Add, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.app_group_add))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AppGroupEditor(
    initial: AppGroup,
    lines: List<AppGroupLine>,
    nodes: List<ProxyNode>,
    apps: List<InstalledApp>,
    onDismiss: () -> Unit,
    onSave: (AppGroup) -> Unit,
    onDelete: () -> Unit,
) {
    val isNew = initial.id.isBlank()
    val defaultName = stringResource(R.string.app_group_default_name_format, 1)
    var name by remember { mutableStateOf(initial.name) }
    var forwardId by remember { mutableStateOf(initial.forwardId) }
    var nodeId by remember { mutableStateOf(initial.nodeId) }
    var selected by remember { mutableStateOf(initial.packages.toSet()) }
    var query by remember { mutableStateOf("") }
    // Members first, so an edit opens on what the group already holds.
    val ordered = remember(apps) { apps.sortedByDescending { it.packageName in initial.packages } }
    val trimmed = query.trim()
    val visible = if (trimmed.isEmpty()) ordered else ordered.filter {
        it.label.contains(trimmed, ignoreCase = true) || it.packageName.contains(trimmed, ignoreCase = true)
    }
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.surface) {
            Column(Modifier.fillMaxSize()) {
                Row(
                    Modifier.fillMaxWidth().padding(start = 4.dp, end = 12.dp, top = 8.dp, bottom = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    IconButton(onClick = onDismiss) { Icon(Icons.Filled.Close, contentDescription = null) }
                    Text(
                        stringResource(if (isNew) R.string.app_group_new_title else R.string.app_group_edit_title),
                        style = MaterialTheme.typography.titleLarge,
                        modifier = Modifier.weight(1f),
                    )
                    Button(
                        onClick = {
                            onSave(
                                initial.copy(
                                    name = name.trim().ifEmpty { defaultName },
                                    forwardId = forwardId,
                                    nodeId = nodeId,
                                    packages = ordered.map { it.packageName }.filter { it in selected } +
                                        selected.filter { pkg -> ordered.none { it.packageName == pkg } },
                                ),
                            )
                        },
                        enabled = forwardId > 0L && nodeId > 0L,
                    ) { Text(stringResource(R.string.app_group_save)) }
                }
                LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 32.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    item(key = "name") {
                        OutlinedTextField(
                            value = name,
                            onValueChange = { name = it },
                            label = { Text(stringResource(R.string.app_group_name)) },
                            placeholder = { Text(defaultName) },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                    item(key = "line") {
                        PickerField(
                            label = stringResource(R.string.app_group_line),
                            value = lines.firstOrNull { it.forwardId == forwardId }?.label
                                ?: stringResource(R.string.app_group_line_unavailable),
                            options = lines.map { it.forwardId to it.label },
                            onPick = { picked ->
                                forwardId = picked
                                // Default to the node already paired with that line on the Services page.
                                lines.firstOrNull { it.forwardId == picked }?.pairedNodeId?.let { nodeId = it }
                            },
                        )
                    }
                    item(key = "node") {
                        PickerField(
                            label = stringResource(R.string.app_group_node),
                            value = nodes.firstOrNull { it.id == nodeId }?.name
                                ?: stringResource(R.string.app_group_node_unavailable),
                            options = nodes.map { it.id to it.name },
                            onPick = { nodeId = it },
                        )
                    }
                    item(key = "apps-header") {
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text(
                                stringResource(R.string.app_group_apps) + " · " +
                                    stringResource(R.string.app_group_apps_count_format, selected.size),
                                style = MaterialTheme.typography.titleSmall,
                                color = MaterialTheme.colorScheme.primary,
                                modifier = Modifier.padding(start = 4.dp, top = 8.dp),
                            )
                            OutlinedTextField(
                                value = query,
                                onValueChange = { query = it },
                                modifier = Modifier.fillMaxWidth(),
                                singleLine = true,
                                leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                                placeholder = { Text(stringResource(R.string.app_routing_search)) },
                                shape = CircleShape,
                            )
                        }
                    }
                    items(visible, key = { "pick-${it.packageName}" }) { app ->
                        val checked = app.packageName in selected
                        ListItem(
                            headlineContent = { Text(app.label, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            leadingContent = {
                                val icon = app.icon
                                if (icon != null) {
                                    Image(icon, contentDescription = null, modifier = Modifier.size(36.dp).clip(MaterialTheme.shapes.small))
                                } else {
                                    Icon(Icons.Outlined.Android, contentDescription = null, modifier = Modifier.size(36.dp))
                                }
                            },
                            trailingContent = {
                                Checkbox(
                                    checked = checked,
                                    onCheckedChange = { on ->
                                        selected = if (on) selected + app.packageName else selected - app.packageName
                                    },
                                )
                            },
                            modifier = Modifier.clickable {
                                selected = if (checked) selected - app.packageName else selected + app.packageName
                            },
                        )
                    }
                    if (!isNew) {
                        item(key = "delete") {
                            TextButton(onClick = onDelete, modifier = Modifier.padding(top = 8.dp)) {
                                Text(stringResource(R.string.app_group_delete), color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PickerField(
    label: String,
    value: String,
    options: List<Pair<Long, String>>,
    onPick: (Long) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = value,
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier.fillMaxWidth().menuAnchor(),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { (id, text) ->
                DropdownMenuItem(
                    text = { Text(text, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                    onClick = { expanded = false; onPick(id) },
                )
            }
        }
    }
}
