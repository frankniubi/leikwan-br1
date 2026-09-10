package com.leikwan.bridge.ui.nav

import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Rule
import androidx.compose.material.icons.filled.Lan
import androidx.compose.material.icons.filled.Route
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.annotation.StringRes
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.leikwan.bridge.R
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.ui.clientrules.ClientRulesScreen
import com.leikwan.bridge.ui.connections.ConnectionsScreen
import com.leikwan.bridge.ui.proxies.ProxiesScreen
import com.leikwan.bridge.ui.proxies.ProxiesViewModel
import com.leikwan.bridge.ui.routing.RoutingScreen
import com.leikwan.bridge.ui.settings.SettingsScreen

enum class Screen(val route: String, @StringRes val labelRes: Int, val icon: ImageVector) {
    Connections("connections", R.string.nav_connections, Icons.Default.Lan),
    Proxies("proxies", R.string.nav_proxies, Icons.Default.Shield),
    Routing("routing", R.string.nav_routing, Icons.Default.Route),
    ClientRules("client-rules", R.string.nav_client_rules, Icons.AutoMirrored.Filled.Rule),
    Settings("settings", R.string.nav_settings, Icons.Default.Settings),
}

@Composable
fun MainScreen(onLogout: () -> Unit = {}) {
    val navController = rememberNavController()
    val snackbarHostState = remember { SnackbarHostState() }
    val proxiesViewModel: ProxiesViewModel = viewModel()
    val context = LocalContext.current
    val activity = context as? MainActivity

    // Observe share intent imports
    LaunchedEffect(activity) {
        activity?.pendingImport?.collect { text ->
            proxiesViewModel.importAny(text)
        }
    }

    // Observe clipboard imports — show snackbar with action
    LaunchedEffect(activity) {
        activity?.clipboardImport?.collect { text ->
            val result = snackbarHostState.showSnackbar(
                message = context.getString(R.string.clipboard_proxy_detected),
                actionLabel = context.getString(R.string.clipboard_import),
                duration = SnackbarDuration.Long,
            )
            if (result == SnackbarResult.ActionPerformed) {
                proxiesViewModel.importAny(text)
            }
        }
    }

    // Observe snackbar messages from ViewModel
    val snackbarMsg by proxiesViewModel.snackbar.collectAsState()
    LaunchedEffect(snackbarMsg) {
        snackbarMsg?.let {
            snackbarHostState.showSnackbar(it)
            proxiesViewModel.clearSnackbar()
        }
    }

    Scaffold(
        bottomBar = { BottomBar(navController) },
        snackbarHost = { SnackbarHost(snackbarHostState) },
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = Screen.Connections.route,
            modifier = Modifier.padding(padding),
        ) {
            composable(Screen.Connections.route) {
                ConnectionsScreen(
                    onImportNode = {
                        navController.navigate(Screen.Proxies.route) {
                            popUpTo(navController.graph.startDestinationId) { saveState = true }
                            launchSingleTop = true
                            restoreState = true
                        }
                    },
                )
            }
            composable(Screen.Proxies.route) { ProxiesScreen(viewModel = proxiesViewModel) }
            composable(Screen.Routing.route) { RoutingScreen() }
            composable(Screen.ClientRules.route) { ClientRulesScreen() }
            composable(Screen.Settings.route) { SettingsScreen(onLogout = onLogout) }
        }
    }
}

@Composable
private fun BottomBar(navController: NavHostController) {
    NavigationBar {
        val navBackStackEntry by navController.currentBackStackEntryAsState()
        val currentDestination = navBackStackEntry?.destination
        Screen.entries.forEach { screen ->
            val label = stringResource(screen.labelRes)
            NavigationBarItem(
                icon = {
                    Icon(screen.icon, contentDescription = label)
                },
                label = { Text(label) },
                selected = currentDestination?.hierarchy?.any { it.route == screen.route } == true,
                onClick = {
                    navController.navigate(screen.route) {
                        popUpTo(navController.graph.startDestinationId) { saveState = true }
                        launchSingleTop = true
                        restoreState = true
                    }
                },
            )
        }
    }
}
