package com.leikwan.bridge.ui.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.api.ClientInfo
import com.leikwan.bridge.ui.shared.RoutingDiagnosticsCard

@Composable
fun SettingsScreen(
    onLogout: () -> Unit = {},
    viewModel: SettingsViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()
    SettingsScreenContent(
        state = state,
        onSwitchClient = viewModel::switchClient,
        onUpdateDnsServer = viewModel::updateDnsServer,
        onUpdateDnsFallback = viewModel::updateDnsFallback,
        onUpdateDnsStrategy = viewModel::updateDnsStrategy,
        onUpdateBypassLan = viewModel::updateBypassLan,
        onUpdateBypassChina = viewModel::updateBypassChina,
        onUpdateCustomBypass = viewModel::updateCustomBypass,
        onUpdateRules = viewModel::updateRules,
        onRollbackRules = viewModel::rollbackRules,
        onLogout = { viewModel.logout(); onLogout() },
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun SettingsScreenContent(
    state: SettingsUiState,
    onSwitchClient: (Long) -> Unit,
    onUpdateDnsServer: (String) -> Unit,
    onUpdateDnsFallback: (String) -> Unit,
    onUpdateDnsStrategy: (String) -> Unit,
    onUpdateBypassLan: (Boolean) -> Unit,
    onUpdateBypassChina: (Boolean) -> Unit,
    onUpdateCustomBypass: (String) -> Unit,
    onUpdateRules: () -> Unit,
    onRollbackRules: () -> Unit,
    onLogout: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        Text(stringResource(R.string.settings_title), style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(16.dp))

        // Client Switcher
        if (state.clients.size > 1) {
            Text(stringResource(R.string.settings_client), style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))

            var clientExpanded by remember { mutableStateOf(false) }
            ExposedDropdownMenuBox(
                expanded = clientExpanded,
                onExpandedChange = { clientExpanded = it },
            ) {
                OutlinedTextField(
                    value = if (state.clientName.isNotEmpty()) {
                        state.clientName
                    } else {
                        stringResource(R.string.settings_client_id_format, state.clientId)
                    },
                    onValueChange = {},
                    readOnly = true,
                    label = { Text(stringResource(R.string.settings_active_client)) },
                    modifier = Modifier.fillMaxWidth().menuAnchor(),
                    trailingIcon = {
                        if (state.isSwitchingClient) {
                            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                        } else {
                            ExposedDropdownMenuDefaults.TrailingIcon(expanded = clientExpanded)
                        }
                    },
                    enabled = !state.isSwitchingClient,
                )
                ExposedDropdownMenu(
                    expanded = clientExpanded,
                    onDismissRequest = { clientExpanded = false },
                ) {
                    state.clients.forEach { client: ClientInfo ->
                        DropdownMenuItem(
                            text = {
                                Text(
                                    client.name,
                                    color = if (client.id == state.clientId) {
                                        MaterialTheme.colorScheme.primary
                                    } else {
                                        MaterialTheme.colorScheme.onSurface
                                    },
                                )
                            },
                            onClick = {
                                clientExpanded = false
                                onSwitchClient(client.id)
                            },
                        )
                    }
                }
            }

            state.switchError?.let { err ->
                Spacer(Modifier.height(4.dp))
                Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            }

            Spacer(Modifier.height(24.dp))
            HorizontalDivider()
            Spacer(Modifier.height(16.dp))
        }

        // DNS
        Text(stringResource(R.string.language), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(
                onClick = {
                    AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags("zh-CN"))
                },
            ) {
                Text(stringResource(R.string.language_zh))
            }
            OutlinedButton(
                onClick = {
                    AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags("en"))
                },
            ) {
                Text(stringResource(R.string.language_en))
            }
        }

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // DNS
        Text(stringResource(R.string.settings_dns), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))

        OutlinedTextField(
            value = state.dnsServer,
            onValueChange = onUpdateDnsServer,
            label = { Text(stringResource(R.string.settings_dns_server)) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = state.dnsFallback,
            onValueChange = onUpdateDnsFallback,
            label = { Text(stringResource(R.string.settings_dns_fallback)) },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Spacer(Modifier.height(8.dp))

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
                trailingIcon = {
                    ExposedDropdownMenuDefaults.TrailingIcon(expanded = strategyExpanded)
                },
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

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // Bypass Rules
        Text(stringResource(R.string.settings_bypass_rules), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))

        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(stringResource(R.string.settings_bypass_lan_ip))
            Switch(checked = state.bypassLan, onCheckedChange = onUpdateBypassLan)
        }
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(stringResource(R.string.settings_bypass_china))
            Switch(checked = state.bypassChina, onCheckedChange = onUpdateBypassChina)
        }

        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = state.customBypassRules,
            onValueChange = onUpdateCustomBypass,
            label = { Text(stringResource(R.string.settings_custom_bypass)) },
            modifier = Modifier.fillMaxWidth(),
            minLines = 3,
            maxLines = 6,
        )

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        Text(stringResource(R.string.settings_rule_packs), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
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
        Spacer(Modifier.height(8.dp))
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

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // Routing diagnostics (read-only)
        state.routingDiagnostics?.let { diagnostics ->
            RoutingDiagnosticsCard(model = diagnostics)
            Spacer(Modifier.height(24.dp))
            HorizontalDivider()
            Spacer(Modifier.height(16.dp))
        }

        // Account
        Text(stringResource(R.string.settings_account), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        Text(stringResource(R.string.settings_logged_in_as_format, state.username), style = MaterialTheme.typography.bodyMedium)
        if (state.clientName.isNotEmpty()) {
            Text(
                stringResource(R.string.settings_client_name_format, state.clientName),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        Spacer(Modifier.height(8.dp))
        OutlinedButton(
            onClick = onLogout,
            modifier = Modifier.fillMaxWidth(),
            colors = ButtonDefaults.outlinedButtonColors(
                contentColor = MaterialTheme.colorScheme.error,
            ),
        ) {
            Text(stringResource(R.string.settings_logout))
        }

        Spacer(Modifier.height(24.dp))

        // About
        Text(stringResource(R.string.settings_about), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(4.dp))
        Text(
            stringResource(R.string.settings_app_version),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
