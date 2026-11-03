package com.leikwan.bridge.ui.settings

import androidx.appcompat.app.AppCompatDelegate
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.automirrored.outlined.Rule
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.Apps
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.Palette
import androidx.compose.material.icons.outlined.Route
import androidx.compose.material.icons.outlined.Tune
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.ListItemDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.core.os.LocaleListCompat
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.ui.theme.ThemeController
import com.leikwan.bridge.ui.theme.ThemeMode
import com.leikwan.bridge.ui.shared.RoutingDiagnosticsCard
import com.leikwan.bridge.ui.shared.UpdateSettingsBlock
import com.leikwan.bridge.update.AppUpdateController
import com.leikwan.bridge.update.AppUpdateState

@Composable
fun SettingsScreen(
    onLogout: () -> Unit = {},
    onOpenRouting: () -> Unit = {},
    onOpenClientRules: () -> Unit = {},
    onOpenAppRouting: () -> Unit = {},
    viewModel: SettingsViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()
    val updateState by AppUpdateController.state.collectAsState()
    val context = LocalContext.current
    SettingsScreenContent(
        state = state,
        updateState = updateState,
        onUpdateDnsServer = viewModel::updateDnsServer,
        onUpdateDnsFallback = viewModel::updateDnsFallback,
        onUpdateDnsStrategy = viewModel::updateDnsStrategy,
        onUpdateBypassLan = viewModel::updateBypassLan,
        onUpdateBypassChina = viewModel::updateBypassChina,
        onUpdateCustomBypass = viewModel::updateCustomBypass,
        onUpdateRules = viewModel::updateRules,
        onRollbackRules = viewModel::rollbackRules,
        onOpenRouting = onOpenRouting,
        onOpenClientRules = onOpenClientRules,
        onOpenAppRouting = onOpenAppRouting,
        onCheckUpdate = { AppUpdateController.check(context) },
        onUpdate = { AppUpdateController.downloadAndInstall(context) },
        onLogout = { viewModel.logout(); onLogout() },
    )
}

@Composable
internal fun SettingsScreenContent(
    state: SettingsUiState,
    updateState: AppUpdateState,
    onUpdateDnsServer: (String) -> Unit,
    onUpdateDnsFallback: (String) -> Unit,
    onUpdateDnsStrategy: (String) -> Unit,
    onUpdateBypassLan: (Boolean) -> Unit,
    onUpdateBypassChina: (Boolean) -> Unit,
    onUpdateCustomBypass: (String) -> Unit,
    onUpdateRules: () -> Unit,
    onRollbackRules: () -> Unit,
    onOpenRouting: () -> Unit,
    onOpenClientRules: () -> Unit,
    onCheckUpdate: () -> Unit,
    onOpenAppRouting: () -> Unit = {},
    onUpdate: () -> Unit,
    onLogout: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(20.dp),
    ) {
        Text(
            stringResource(R.string.settings_title),
            style = MaterialTheme.typography.headlineMedium,
            modifier = Modifier.padding(start = 4.dp, top = 4.dp),
        )

        SettingsGroup(stringResource(R.string.settings_account)) {
            if (state.clientId > 0L) {
                SettingsRow(
                    icon = Icons.Outlined.AccountCircle,
                    title = stringResource(R.string.settings_client_id_format, state.clientId),
                )
                GroupDivider()
            }
            ListItem(
                headlineContent = { Text(stringResource(R.string.settings_logout)) },
                leadingContent = { Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null) },
                colors = ListItemDefaults.colors(
                    containerColor = MaterialTheme.colorScheme.surfaceContainerLow,
                    headlineColor = MaterialTheme.colorScheme.error,
                    leadingIconColor = MaterialTheme.colorScheme.error,
                ),
                modifier = Modifier.clickable(onClick = onLogout),
            )
        }

        SettingsGroup(stringResource(R.string.settings_section_rules)) {
            SettingsRow(
                icon = Icons.Outlined.Route,
                title = stringResource(R.string.nav_routing),
                onClick = onOpenRouting,
            )
            GroupDivider()
            SettingsRow(
                icon = Icons.Outlined.Apps,
                title = stringResource(R.string.dash_app_routing),
                onClick = onOpenAppRouting,
            )
            GroupDivider()
            SettingsRow(
                icon = Icons.AutoMirrored.Outlined.Rule,
                title = stringResource(R.string.nav_client_rules),
                onClick = onOpenClientRules,
            )
        }

        SettingsGroup(stringResource(R.string.settings_section_general)) {
            ThemeRow()
            GroupDivider()
            LanguageRow()
        }

        AdvancedGroup(
            state = state,
            onUpdateDnsServer = onUpdateDnsServer,
            onUpdateDnsFallback = onUpdateDnsFallback,
            onUpdateDnsStrategy = onUpdateDnsStrategy,
            onUpdateBypassLan = onUpdateBypassLan,
            onUpdateBypassChina = onUpdateBypassChina,
            onUpdateCustomBypass = onUpdateCustomBypass,
            onUpdateRules = onUpdateRules,
            onRollbackRules = onRollbackRules,
        )

        SettingsGroup(stringResource(R.string.settings_about)) {
            Row(Modifier.padding(16.dp), verticalAlignment = Alignment.Top) {
                Icon(
                    Icons.Outlined.Info,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(top = 2.dp),
                )
                Spacer(Modifier.size(16.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.app_name), style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(4.dp))
                    UpdateSettingsBlock(state = updateState, onCheck = onCheckUpdate, onUpdate = onUpdate)
                }
            }
        }
        // Room for the floating navigation dock.
        Spacer(Modifier.height(72.dp))
    }
}

@Composable
private fun SettingsGroup(title: String, content: @Composable ColumnScope.() -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            title,
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.primary,
            modifier = Modifier.padding(start = 16.dp),
        )
        Surface(
            modifier = Modifier.fillMaxWidth(),
            shape = MaterialTheme.shapes.large,
            color = MaterialTheme.colorScheme.surfaceContainerLow,
        ) {
            Column(content = content)
        }
    }
}

@Composable
private fun GroupDivider() {
    HorizontalDivider(modifier = Modifier.padding(start = 56.dp), color = MaterialTheme.colorScheme.outlineVariant)
}

@Composable
private fun SettingsRow(
    icon: ImageVector,
    title: String,
    summary: String? = null,
    onClick: (() -> Unit)? = null,
    trailing: @Composable (() -> Unit)? = null,
) {
    ListItem(
        headlineContent = { Text(title) },
        supportingContent = summary?.let { { Text(it) } },
        leadingContent = { Icon(icon, contentDescription = null) },
        trailingContent = trailing ?: onClick?.let {
            { Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null) }
        },
        colors = ListItemDefaults.colors(containerColor = MaterialTheme.colorScheme.surfaceContainerLow),
        modifier = if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier,
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ThemeRow() {
    val context = LocalContext.current
    val mode by ThemeController.mode.collectAsState()
    val options = listOf(
        ThemeMode.SYSTEM to stringResource(R.string.theme_system),
        ThemeMode.LIGHT to stringResource(R.string.theme_day),
        ThemeMode.DARK to stringResource(R.string.theme_night),
    )
    Column(Modifier.padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Outlined.Palette, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.size(16.dp))
            Text(stringResource(R.string.settings_theme), style = MaterialTheme.typography.bodyLarge)
        }
        Spacer(Modifier.height(12.dp))
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            options.forEachIndexed { index, (option, label) ->
                SegmentedButton(
                    selected = mode == option,
                    onClick = { ThemeController.set(Prefs.get(context), option) },
                    shape = SegmentedButtonDefaults.itemShape(index = index, count = options.size),
                ) {
                    Text(label, maxLines = 1)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun LanguageRow() {
    // An empty locale list means "follow the system".
    var selected by remember {
        mutableStateOf(AppCompatDelegate.getApplicationLocales().toLanguageTags().substringBefore(','))
    }
    val options = listOf(
        "" to stringResource(R.string.settings_language_system),
        "zh-CN" to stringResource(R.string.language_zh),
        "en" to stringResource(R.string.language_en),
    )
    Column(Modifier.padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Outlined.Language, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.size(16.dp))
            Text(stringResource(R.string.language), style = MaterialTheme.typography.bodyLarge)
        }
        Spacer(Modifier.height(12.dp))
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
            options.forEachIndexed { index, (tag, label) ->
                SegmentedButton(
                    selected = selected.equals(tag, ignoreCase = true) ||
                        (tag == "zh-CN" && selected.startsWith("zh", ignoreCase = true)),
                    onClick = {
                        selected = tag
                        AppCompatDelegate.setApplicationLocales(
                            if (tag.isEmpty()) LocaleListCompat.getEmptyLocaleList()
                            else LocaleListCompat.forLanguageTags(tag),
                        )
                    },
                    shape = SegmentedButtonDefaults.itemShape(index = index, count = options.size),
                ) {
                    Text(label, maxLines = 1)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AdvancedGroup(
    state: SettingsUiState,
    onUpdateDnsServer: (String) -> Unit,
    onUpdateDnsFallback: (String) -> Unit,
    onUpdateDnsStrategy: (String) -> Unit,
    onUpdateBypassLan: (Boolean) -> Unit,
    onUpdateBypassChina: (Boolean) -> Unit,
    onUpdateCustomBypass: (String) -> Unit,
    onUpdateRules: () -> Unit,
    onRollbackRules: () -> Unit,
) {
    var expanded by rememberSaveable { mutableStateOf(false) }
    SettingsGroup(stringResource(R.string.settings_section_advanced)) {
        SettingsRow(
            icon = Icons.Outlined.Tune,
            title = stringResource(R.string.settings_advanced_desc),
            onClick = { expanded = !expanded },
            trailing = {
                Icon(if (expanded) Icons.Default.ExpandLess else Icons.Default.ExpandMore, contentDescription = null)
            },
        )
        if (!expanded) return@SettingsGroup
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.settings_dns), style = MaterialTheme.typography.titleSmall)
            OutlinedTextField(
                value = state.dnsServer,
                onValueChange = onUpdateDnsServer,
                label = { Text(stringResource(R.string.settings_dns_server)) },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
            )
            OutlinedTextField(
                value = state.dnsFallback,
                onValueChange = onUpdateDnsFallback,
                label = { Text(stringResource(R.string.settings_dns_fallback)) },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
            )
            var strategyExpanded by remember { mutableStateOf(false) }
            val strategies = listOf("prefer_ipv4", "prefer_ipv6", "ipv4_only", "ipv6_only")
            ExposedDropdownMenuBox(
                expanded = strategyExpanded,
                onExpandedChange = { strategyExpanded = it },
            ) {
                OutlinedTextField(
                    value = state.dnsStrategy,
                    onValueChange = {},
                    readOnly = true,
                    label = { Text(stringResource(R.string.settings_dns_strategy)) },
                    modifier = Modifier.fillMaxWidth().menuAnchor(),
                    trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = strategyExpanded) },
                )
                ExposedDropdownMenu(
                    expanded = strategyExpanded,
                    onDismissRequest = { strategyExpanded = false },
                ) {
                    strategies.forEach { strategy ->
                        DropdownMenuItem(
                            text = { Text(strategy) },
                            onClick = {
                                onUpdateDnsStrategy(strategy)
                                strategyExpanded = false
                            },
                        )
                    }
                }
            }

            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
            Text(stringResource(R.string.settings_bypass_rules), style = MaterialTheme.typography.titleSmall)
            SwitchLine(stringResource(R.string.settings_bypass_lan_ip), state.bypassLan, onUpdateBypassLan)
            SwitchLine(stringResource(R.string.settings_bypass_china), state.bypassChina, onUpdateBypassChina)
            OutlinedTextField(
                value = state.customBypassRules,
                onValueChange = onUpdateCustomBypass,
                label = { Text(stringResource(R.string.settings_custom_bypass)) },
                modifier = Modifier.fillMaxWidth(),
                minLines = 3,
                maxLines = 6,
            )

            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
            Text(stringResource(R.string.settings_rule_packs), style = MaterialTheme.typography.titleSmall)
            Text(
                stringResource(R.string.settings_rule_pack_active_format, state.rulePackLabel),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (state.rulePackStale) {
                Text(
                    stringResource(R.string.settings_rule_pack_stale),
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            state.rulePackMessage?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = onUpdateRules, enabled = !state.isUpdatingRules) {
                    if (state.isUpdatingRules) {
                        CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
                    } else {
                        Text(stringResource(R.string.settings_rules_update))
                    }
                }
                OutlinedButton(onClick = onRollbackRules, enabled = !state.isUpdatingRules) {
                    Text(stringResource(R.string.settings_rules_rollback))
                }
            }

            state.routingDiagnostics?.let { diagnostics ->
                HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                RoutingDiagnosticsCard(model = diagnostics)
            }
        }
    }
}

@Composable
private fun SwitchLine(label: String, checked: Boolean, onCheckedChange: (Boolean) -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = onCheckedChange)
    }
}
