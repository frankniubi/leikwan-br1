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
import androidx.compose.ui.unit.dp

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
            Text("Routing diagnostics", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))
            DiagnosticsRow("Default action", model.defaultActionLabel)
            DiagnosticsRow("Decision reason", model.decisionReasonLabel)
            DiagnosticsRow("Protected traffic", model.protectedTrafficLabel)
            DiagnosticsRow("Active rule packs", model.activeRulePackLabel)
            DiagnosticsRow(
                "Warnings",
                if (model.warnings.isEmpty()) "none" else model.warnings.joinToString("; "),
            )
        }
    }
}

@Composable
private fun DiagnosticsRow(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(
            "$label: ",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(value, style = MaterialTheme.typography.bodySmall)
    }
}
