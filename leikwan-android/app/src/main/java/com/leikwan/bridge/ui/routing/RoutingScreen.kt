package com.leikwan.bridge.ui.routing

import android.app.Application
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModelProvider.AndroidViewModelFactory.Companion.APPLICATION_KEY
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.UserTrafficOutcome

@Composable
fun RoutingScreen(
    viewModel: RoutingViewModel = viewModel(
        factory = viewModelFactory {
            initializer { RoutingViewModel(this[APPLICATION_KEY] as Application) }
        },
    ),
) {
    val state by viewModel.state.collectAsState()
    RoutingScreenContent(
        state = state,
        onSetGlobalDefault = viewModel::setGlobalDefault,
        onSetGlobalDefaultNode = viewModel::setGlobalDefaultNode,
        onAddCountry = viewModel::addCountryRule,
        onAddCategory = viewModel::addCategoryRule,
        onToggle = viewModel::toggleRule,
        onSetTargetNode = viewModel::setRuleTargetNode,
        onDelete = viewModel::deleteRule,
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun RoutingScreenContent(
    state: RoutingUiState,
    onSetGlobalDefault: (UserTrafficOutcome) -> Unit,
    onSetGlobalDefaultNode: (Long?) -> Unit,
    onAddCountry: (String, UserTrafficOutcome) -> Unit,
    onAddCategory: (String, UserTrafficOutcome) -> Unit,
    onToggle: (String) -> Unit,
    onSetTargetNode: (String, Long?) -> Unit,
    onDelete: (String) -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        Text("Routing", style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(16.dp))

        // Global default
        Text("Global default", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            UserTrafficOutcome.entries.forEach { outcome ->
                FilterChip(
                    selected = state.globalDefault == outcome,
                    onClick = { onSetGlobalDefault(outcome) },
                    label = { Text(outcome.name) },
                )
            }
        }
        Spacer(Modifier.height(8.dp))
        NodePicker(
            label = "Default node",
            selectedNodeId = state.globalDefaultNodeId,
            nodes = state.availableNodes,
            enabled = state.globalDefault == UserTrafficOutcome.TUNNEL,
            autoLabel = "Auto (selector)",
            onSelect = onSetGlobalDefaultNode,
        )
        Spacer(Modifier.height(8.dp))
        Text(
            "TUNNEL = everything through LeiKwan except rules; " +
                "DIRECT = everything direct except rules.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // Add country rule
        Text("Add country rule", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        var countryCode by remember { mutableStateOf("") }
        var countryOutcome by remember { mutableStateOf(UserTrafficOutcome.DIRECT) }
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            OutlinedTextField(
                value = countryCode,
                onValueChange = { countryCode = it.uppercase().take(2) },
                label = { Text("ISO code") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(
                    capitalization = KeyboardCapitalization.Characters,
                    keyboardType = KeyboardType.Text,
                ),
                modifier = Modifier.weight(1f),
            )
            Spacer(Modifier.width(8.dp))
            OutcomeToggle(outcome = countryOutcome, onChange = { countryOutcome = it })
        }
        Spacer(Modifier.height(8.dp))
        Button(
            onClick = {
                onAddCountry(countryCode, countryOutcome)
                countryCode = ""
            },
            enabled = countryCode.isNotBlank(),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Add country rule")
        }

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // Add category rule
        Text("Add category rule", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        var categoryExpanded by remember { mutableStateOf(false) }
        var selectedCategory by remember(state.presetCategories) {
            mutableStateOf(state.presetCategories.firstOrNull() ?: "")
        }
        var categoryOutcome by remember { mutableStateOf(UserTrafficOutcome.TUNNEL) }
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ExposedDropdownMenuBox(
                expanded = categoryExpanded,
                onExpandedChange = { categoryExpanded = it },
                modifier = Modifier.weight(1f),
            ) {
                OutlinedTextField(
                    value = selectedCategory,
                    onValueChange = {},
                    readOnly = true,
                    label = { Text("Category") },
                    trailingIcon = {
                        ExposedDropdownMenuDefaults.TrailingIcon(expanded = categoryExpanded)
                    },
                    modifier = Modifier.fillMaxWidth().menuAnchor(),
                )
                ExposedDropdownMenu(
                    expanded = categoryExpanded,
                    onDismissRequest = { categoryExpanded = false },
                ) {
                    state.presetCategories.forEach { category ->
                        DropdownMenuItem(
                            text = { Text(category) },
                            onClick = {
                                selectedCategory = category
                                categoryExpanded = false
                            },
                        )
                    }
                }
            }
            Spacer(Modifier.width(8.dp))
            OutcomeToggle(outcome = categoryOutcome, onChange = { categoryOutcome = it })
        }
        Spacer(Modifier.height(8.dp))
        Button(
            onClick = { onAddCategory(selectedCategory, categoryOutcome) },
            enabled = selectedCategory.isNotBlank(),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Add category rule")
        }

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // Existing rules
        Text("Rules", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        val rules = state.countryRules + state.categoryRules
        if (rules.isEmpty()) {
            Text(
                "No custom rules yet.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        } else {
            rules.forEach { rule ->
                RuleRow(
                    rule = rule,
                    nodes = state.availableNodes,
                    onToggle = onToggle,
                    onSetTargetNode = onSetTargetNode,
                    onDelete = onDelete,
                )
                Spacer(Modifier.height(8.dp))
            }
        }
    }
}

@Composable
private fun OutcomeToggle(outcome: UserTrafficOutcome, onChange: (UserTrafficOutcome) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(outcome.name, style = MaterialTheme.typography.labelMedium)
        Spacer(Modifier.width(4.dp))
        Switch(
            checked = outcome == UserTrafficOutcome.TUNNEL,
            onCheckedChange = {
                onChange(if (it) UserTrafficOutcome.TUNNEL else UserTrafficOutcome.DIRECT)
            },
        )
    }
}

@Composable
private fun RuleRow(
    rule: RoutingRule,
    nodes: List<RoutingNodeOption>,
    onToggle: (String) -> Unit,
    onSetTargetNode: (String, Long?) -> Unit,
    onDelete: (String) -> Unit,
) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.fillMaxWidth().padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(rule.matchValue, style = MaterialTheme.typography.titleSmall)
                    Text(
                        rule.outcome.name,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Switch(
                    checked = rule.enabled,
                    onCheckedChange = { onToggle(rule.id) },
                )
                IconButton(onClick = { onDelete(rule.id) }) {
                    Icon(Icons.Default.Delete, contentDescription = "Delete ${rule.matchValue}")
                }
            }
            if (rule.outcome == UserTrafficOutcome.TUNNEL) {
                Spacer(Modifier.height(8.dp))
                NodePicker(
                    label = "Target node",
                    selectedNodeId = rule.targetNodeId,
                    nodes = nodes,
                    enabled = rule.enabled,
                    autoLabel = "Auto (default)",
                    onSelect = { onSetTargetNode(rule.id, it) },
                )
                if (rule.targetNodeId != null && nodes.none { it.id == rule.targetNodeId }) {
                    Spacer(Modifier.height(4.dp))
                    Text(
                        "Pinned node is unavailable; startup falls back to the default node.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.error,
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NodePicker(
    label: String,
    selectedNodeId: Long?,
    nodes: List<RoutingNodeOption>,
    enabled: Boolean,
    autoLabel: String,
    onSelect: (Long?) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    val selectedLabel = when {
        selectedNodeId == null -> autoLabel
        else -> nodes.firstOrNull { it.id == selectedNodeId }?.name ?: "Unavailable node #$selectedNodeId"
    }
    ExposedDropdownMenuBox(
        expanded = expanded,
        onExpandedChange = { if (enabled) expanded = it },
        modifier = Modifier.fillMaxWidth(),
    ) {
        OutlinedTextField(
            value = selectedLabel,
            onValueChange = {},
            readOnly = true,
            enabled = enabled,
            label = { Text(label) },
            trailingIcon = {
                ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded)
            },
            modifier = Modifier.fillMaxWidth().menuAnchor(),
        )
        ExposedDropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false },
        ) {
            DropdownMenuItem(
                text = { Text(autoLabel) },
                onClick = {
                    onSelect(null)
                    expanded = false
                },
            )
            nodes.forEach { node ->
                DropdownMenuItem(
                    text = { Text("${node.name} (${node.type.uppercase()})") },
                    onClick = {
                        onSelect(node.id)
                        expanded = false
                    },
                )
            }
        }
    }
}
