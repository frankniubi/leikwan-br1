package com.leikwan.bridge.ui.shared

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.leikwan.bridge.R

/**
 * Read-only diagnostics card rendered on the Settings and Tunnels screens, summarising the most
 * recent routing-policy compile. Purely informational — it never mutates state.
 */
@Composable
fun RoutingDiagnosticsCard(
    model: RoutingDiagnosticsUiModel,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.fillMaxWidth().padding(16.dp)) {
            Text(stringResource(R.string.diagnostics_title), style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))
            DiagnosticsRow(stringResource(R.string.diagnostics_default_action), model.defaultActionLabel)
            DiagnosticsRow(stringResource(R.string.diagnostics_decision_reason), model.decisionReasonLabel)
            DiagnosticsRow(stringResource(R.string.diagnostics_protected_traffic), model.protectedTrafficLabel)
            DiagnosticsRow(stringResource(R.string.diagnostics_active_rule_packs), model.activeRulePackLabel)
            DiagnosticsRow(
                stringResource(R.string.diagnostics_warnings),
                if (model.warnings.isEmpty()) {
                    stringResource(R.string.diagnostics_none)
                } else {
                    model.warnings.joinToString("; ")
                },
            )
        }
    }
}

@Composable
private fun DiagnosticsRow(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(
            stringResource(R.string.diagnostics_row_format, label),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(value, style = MaterialTheme.typography.bodySmall)
    }
}
