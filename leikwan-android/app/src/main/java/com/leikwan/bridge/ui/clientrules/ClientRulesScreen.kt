package com.leikwan.bridge.ui.clientrules

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.Card
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import com.leikwan.bridge.clientrules.RouteDirection

@Composable
fun ClientRulesScreen(
    viewModel: ClientRulesViewModel = viewModel(factory = ClientRulesViewModel.factory(LocalContext.current)),
) {
    val state by viewModel.state.collectAsState()
    ClientRulesScreenContent(
        state = state,
        onSelectServiceScope = viewModel::selectServiceScope,
        onUpdateDestinationIp = viewModel::updateDraftDestinationIp,
        onUpdateDestinationPort = viewModel::updateDraftDestinationPort,
        onSelectDirection = viewModel::selectDraftDirection,
        onSetDraftEnabled = viewModel::setDraftEnabled,
        onSubmit = viewModel::submitDraft,
        onToggleRule = viewModel::toggleRuleEnabled,
        onDeleteRule = viewModel::deleteRule,
    )
}

@Composable
internal fun ClientRulesScreenContent(
    state: ClientRulesUiState,
    onSelectServiceScope: (String) -> Unit,
    onUpdateDestinationIp: (String) -> Unit,
    onUpdateDestinationPort: (String) -> Unit,
    onSelectDirection: (String) -> Unit,
    onSetDraftEnabled: (Boolean) -> Unit,
    onSubmit: () -> Unit,
    onToggleRule: (String) -> Unit,
    onDeleteRule: (String) -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        Text(stringResource(R.string.client_rules_title), style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(8.dp))
        Text(
            stringResource(R.string.client_rules_description),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(16.dp))

        state.unavailableReason?.let { reason ->
            Text(reason, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
            Spacer(Modifier.height(12.dp))
        }
        state.errorCode?.takeUnless { it == "client_rules_unavailable" }?.let { error ->
            Text(error.toClientRuleMessage(), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(12.dp))
        }

        ServiceScopeSection(
            state = state,
            onSelectServiceScope = onSelectServiceScope,
        )
        Spacer(Modifier.height(16.dp))

        DraftSection(
            state = state,
            onUpdateDestinationIp = onUpdateDestinationIp,
            onUpdateDestinationPort = onUpdateDestinationPort,
            onSelectDirection = onSelectDirection,
            onSetDraftEnabled = onSetDraftEnabled,
            onSubmit = onSubmit,
        )
        Spacer(Modifier.height(16.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        Text(stringResource(R.string.client_rules_existing_title), style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        if (state.rules.isEmpty()) {
            Text(
                stringResource(R.string.client_rules_empty),
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                style = MaterialTheme.typography.bodyMedium,
            )
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                state.rules.forEach { rule ->
                    RuleCard(
                        rule = rule,
                        directionLabel = state.directions.firstOrNull { it.id == rule.directionId }?.label
                            ?: stringResource(R.string.route_direction_unavailable),
                        saving = state.saving,
                        onToggleRule = onToggleRule,
                        onDeleteRule = onDeleteRule,
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ServiceScopeSection(
    state: ClientRulesUiState,
    onSelectServiceScope: (String) -> Unit,
) {
    Text(stringResource(R.string.client_rules_service_scope), style = MaterialTheme.typography.titleMedium)
    Spacer(Modifier.height(8.dp))
    if (state.serviceScopes.size > 1) {
        var expanded by remember { mutableStateOf(false) }
        ExposedDropdownMenuBox(
            expanded = expanded,
            onExpandedChange = { expanded = it },
        ) {
            OutlinedTextField(
                value = state.serviceScopes.firstOrNull { it.serviceScopeId == state.selectedServiceScopeId }?.label.orEmpty(),
                onValueChange = {},
                readOnly = true,
                label = { Text(stringResource(R.string.client_rules_service_scope)) },
                modifier = Modifier.fillMaxWidth().menuAnchor(),
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
                enabled = state.serviceScopeSelectionEnabled,
            )
            ExposedDropdownMenu(
                expanded = expanded,
                onDismissRequest = { expanded = false },
            ) {
                state.serviceScopes.forEach { option ->
                    DropdownMenuItem(
                        text = { Text(option.label) },
                        onClick = {
                            expanded = false
                            onSelectServiceScope(option.serviceScopeId)
                        },
                    )
                }
            }
        }
    } else {
        Text(
            state.serviceScopes.firstOrNull()?.label ?: stringResource(R.string.client_rules_no_service_scope),
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
    if (state.quotaLabel.isNotBlank()) {
        Spacer(Modifier.height(4.dp))
        Text(state.quotaLabel, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DraftSection(
    state: ClientRulesUiState,
    onUpdateDestinationIp: (String) -> Unit,
    onUpdateDestinationPort: (String) -> Unit,
    onSelectDirection: (String) -> Unit,
    onSetDraftEnabled: (Boolean) -> Unit,
    onSubmit: () -> Unit,
) {
    Text(stringResource(R.string.client_rules_new_title), style = MaterialTheme.typography.titleMedium)
    Spacer(Modifier.height(8.dp))
    var directionExpanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(
        expanded = directionExpanded,
        onExpandedChange = { directionExpanded = it },
    ) {
        OutlinedTextField(
            value = state.directions.firstOrNull { it.id == state.draftDirectionId }?.label.orEmpty(),
            onValueChange = {},
            readOnly = true,
            label = { Text(stringResource(R.string.route_direction_label)) },
            modifier = Modifier.fillMaxWidth().menuAnchor(),
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = directionExpanded) },
            enabled = state.enabled && state.directions.isNotEmpty(),
        )
        ExposedDropdownMenu(
            expanded = directionExpanded,
            onDismissRequest = { directionExpanded = false },
        ) {
            state.directions.forEach { direction: RouteDirection ->
                DropdownMenuItem(
                    text = { Text(direction.label) },
                    onClick = {
                        directionExpanded = false
                        onSelectDirection(direction.id)
                    },
                )
            }
        }
    }
    Spacer(Modifier.height(8.dp))
    OutlinedTextField(
        value = state.draftDestinationIp,
        onValueChange = onUpdateDestinationIp,
        label = { Text(stringResource(R.string.client_rule_destination_ip)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        enabled = state.enabled,
    )
    Spacer(Modifier.height(8.dp))
    OutlinedTextField(
        value = state.draftDestinationPort,
        onValueChange = onUpdateDestinationPort,
        label = { Text(stringResource(R.string.client_rule_destination_port)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        enabled = state.enabled,
    )
    Spacer(Modifier.height(8.dp))
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(stringResource(R.string.client_rule_enabled))
        Switch(
            checked = state.draftEnabled,
            onCheckedChange = onSetDraftEnabled,
            enabled = state.enabled,
        )
    }
    Spacer(Modifier.height(8.dp))
    Button(
        onClick = onSubmit,
        enabled = state.enabled && !state.saving,
        modifier = Modifier.fillMaxWidth(),
    ) {
        if (state.saving) {
            CircularProgressIndicator()
        } else {
            Text(stringResource(R.string.client_rules_create))
        }
    }
}

@Composable
private fun RuleCard(
    rule: ClientRouteRule,
    directionLabel: String,
    saving: Boolean,
    onToggleRule: (String) -> Unit,
    onDeleteRule: (String) -> Unit,
) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text("${rule.destinationIp}:${rule.destinationPort}", style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(4.dp))
                    Text(directionLabel, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Switch(
                    checked = rule.enabled,
                    onCheckedChange = { onToggleRule(rule.id) },
                    enabled = !saving && rule.pendingOperation != PendingClientRuleOperation.DELETE,
                )
            }
            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                AssistChip(onClick = {}, label = { Text(rule.effectiveState.displayLabel()) })
                if (rule.pendingOperation != PendingClientRuleOperation.NONE) {
                    AssistChip(onClick = {}, label = { Text(stringResource(R.string.client_rule_syncing)) })
                }
            }
            rule.pendingError?.let { error ->
                Spacer(Modifier.height(4.dp))
                Text(error.toClientRuleMessage(), color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            }
            Spacer(Modifier.height(8.dp))
            OutlinedButton(
                onClick = { onDeleteRule(rule.id) },
                enabled = !saving && rule.pendingOperation != PendingClientRuleOperation.DELETE,
            ) {
                Text(stringResource(R.string.client_rule_delete))
            }
        }
    }
}

private fun ClientRuleEffectiveState.displayLabel(): String = when (this) {
    ClientRuleEffectiveState.ACTIVE -> "Active"
    ClientRuleEffectiveState.DISABLED -> "Disabled"
    ClientRuleEffectiveState.UNAVAILABLE -> "Unavailable"
    ClientRuleEffectiveState.NOT_ALLOWED_BY_PLAN -> "Not allowed by plan"
    ClientRuleEffectiveState.OVER_QUOTA -> "Quota reached"
    ClientRuleEffectiveState.RUNTIME_MISSING -> "Not ready"
    ClientRuleEffectiveState.SYNCING -> "Saving"
    ClientRuleEffectiveState.ERROR -> "Error"
}

private fun String.toClientRuleMessage(): String = when (this) {
    "invalid_destination_ip" -> "Enter an exact IPv4 address."
    "invalid_destination_port" -> "Enter a destination port from 1 to 65535."
    "direction_not_allowed" -> "Choose an allowed Route Direction."
    "not_allowed_by_plan" -> "This Client Rule is not allowed by the current plan."
    "over_quota" -> "Client Rule quota reached."
    else -> this
}
