package com.leikwan.bridge.ui.routing

import android.app.Application
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Rule
import androidx.compose.material.icons.outlined.Apps
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModelProvider.AndroidViewModelFactory.Companion.APPLICATION_KEY
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import com.leikwan.bridge.R

/** Routing overview: two entries, plus one master switch. Detailed authoring stays in RoutingScreen. */
@Composable
fun RoutingHubScreen(
    onOpenRules: () -> Unit,
    onOpenAppRouting: () -> Unit,
    viewModel: RoutingViewModel = viewModel(
        factory = viewModelFactory {
            initializer { RoutingViewModel(this[APPLICATION_KEY] as Application) }
        },
    ),
) {
    val state by viewModel.state.collectAsState()
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        RoutingFeatureCard(
            title = stringResource(R.string.routing_hub_rules_title),
            detail = stringResource(
                R.string.routing_hub_rules_detail,
                state.countryRules.size + state.categoryRules.size + state.clashRules.size,
            ),
            icon = Icons.AutoMirrored.Outlined.Rule,
            colors = RoutingBlue,
            onClick = onOpenRules,
            trailing = {
                Switch(
                    checked = state.selectorEnabled,
                    onCheckedChange = viewModel::setSelectorEnabled,
                )
            },
            action = { RoutingChip(stringResource(R.string.routing_hub_manage)) },
        )
        RoutingFeatureCard(
            title = stringResource(R.string.dash_app_routing),
            detail = stringResource(R.string.routing_hub_apps_detail),
            icon = Icons.Outlined.Apps,
            colors = RoutingPurple,
            onClick = onOpenAppRouting,
            action = { RoutingChip(stringResource(R.string.routing_hub_manage)) },
        )
        Text(
            stringResource(R.string.routing_hub_note),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(horizontal = 4.dp, vertical = 4.dp),
        )
    }
}
