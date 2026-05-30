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
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
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
    onLogout: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        Text("Settings", style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(16.dp))

        // Client Switcher
        if (state.clients.size > 1) {
            Text("Client", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))

            var clientExpanded by remember { mutableStateOf(false) }
            ExposedDropdownMenuBox(
                expanded = clientExpanded,
                onExpandedChange = { clientExpanded = it },
            ) {
                OutlinedTextField(
                    value = state.clientName.ifEmpty { "Client #${state.clientId}" },
                    onValueChange = {},
                    readOnly = true,
                    label = { Text("Active Client") },
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
        Text("DNS", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))

        OutlinedTextField(
            value = state.dnsServer,
            onValueChange = onUpdateDnsServer,
            label = { Text("DNS Server") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = state.dnsFallback,
            onValueChange = onUpdateDnsFallback,
            label = { Text("Fallback DNS") },
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
                label = { Text("DNS Strategy") },
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
        Text("Bypass Rules", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))

        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Bypass LAN IP")
            Switch(checked = state.bypassLan, onCheckedChange = onUpdateBypassLan)
        }
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Bypass China IP/Domain")
            Switch(checked = state.bypassChina, onCheckedChange = onUpdateBypassChina)
        }

        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = state.customBypassRules,
            onValueChange = onUpdateCustomBypass,
            label = { Text("Custom bypass (one IP/domain per line)") },
            modifier = Modifier.fillMaxWidth(),
            minLines = 3,
            maxLines = 6,
        )

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
        Text("Account", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        Text("Logged in as: ${state.username}", style = MaterialTheme.typography.bodyMedium)
        if (state.clientName.isNotEmpty()) {
            Text(
                "Client: ${state.clientName}",
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
            Text("Logout")
        }

        Spacer(Modifier.height(24.dp))

        // About
        Text("About", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(4.dp))
        Text(
            "LeiKwan Bridge v0.1.0",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
