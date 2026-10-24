package com.leikwan.bridge.ui.nav

import androidx.annotation.StringRes
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Dashboard
import androidx.compose.material.icons.filled.Hub
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.Public
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.outlined.Dashboard
import androidx.compose.material.icons.outlined.Hub
import androidx.compose.material.icons.outlined.Public
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.leikwan.bridge.R
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.ui.approuting.AppRoutingScreen
import com.leikwan.bridge.ui.clientrules.ClientRulesScreen
import com.leikwan.bridge.ui.connections.ConnectionsScreen
import com.leikwan.bridge.ui.connections.ConnectionsViewModel
import com.leikwan.bridge.ui.connections.ServicesScreen
import com.leikwan.bridge.ui.connections.VpnStatus
import com.leikwan.bridge.ui.connections.appGroupLines
import com.leikwan.bridge.ui.connections.connectToggle
import com.leikwan.bridge.ui.proxies.ProxiesScreen
import com.leikwan.bridge.ui.proxies.ProxiesViewModel
import com.leikwan.bridge.ui.routing.RoutingScreen
import com.leikwan.bridge.ui.settings.SettingsScreen
import com.leikwan.bridge.ui.shared.RequiredUpdateDialog
import com.leikwan.bridge.update.AppUpdateController
import com.leikwan.bridge.update.ClientUpdatePolicy

/** Top-level destinations in the floating navigation dock. */
enum class Screen(
    val route: String,
    @StringRes val labelRes: Int,
    val icon: ImageVector,
    val selectedIcon: ImageVector,
) {
    Connections("connections", R.string.nav_dashboard, Icons.Outlined.Dashboard, Icons.Filled.Dashboard),
    Services("services", R.string.nav_services, Icons.Outlined.Public, Icons.Filled.Public),
    Proxies("proxies", R.string.nav_proxies, Icons.Outlined.Hub, Icons.Filled.Hub),
    Settings("settings", R.string.nav_settings, Icons.Outlined.Settings, Icons.Filled.Settings),
}

/** Secondary pages; they get a top app bar and no dock. */
internal enum class SubPage(val route: String, @StringRes val titleRes: Int) {
    Routing("routing", R.string.nav_routing),
    ClientRules("client-rules", R.string.nav_client_rules),
    AppRouting("app-routing", R.string.dash_app_routing),
}

@Composable
fun MainScreen(onLogout: () -> Unit = {}) {
    val navController = rememberNavController()
    val snackbarHostState = remember { SnackbarHostState() }
    val proxiesViewModel: ProxiesViewModel = viewModel()
    // One connection state for the dashboard, the services tab and the power button.
    val connectionsViewModel: ConnectionsViewModel = viewModel()
    val context = LocalContext.current
    val activity = context as? MainActivity

    LaunchedEffect(Unit) { AppUpdateController.checkIfDue(context) }

    // Panel-mandated minimum version (set by every configs/pull). The gate covers the whole
    // app, connected or not, and leaves the VPN running.
    val requiredMinimumVersion by ClientUpdatePolicy.requiredMinimumVersion.collectAsState()
    val appUpdateState by AppUpdateController.state.collectAsState()
    requiredMinimumVersion?.let { minimum ->
        LaunchedEffect(minimum) { AppUpdateController.checkForRequiredUpdate(context) }
        RequiredUpdateDialog(
            minimumVersion = minimum,
            state = appUpdateState,
            onUpdate = { AppUpdateController.downloadAndInstall(context) },
            onCheck = { AppUpdateController.check(context) },
        )
    }

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

    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val onTopLevel = navBackStackEntry == null ||
        Screen.entries.any { it.route == navBackStackEntry?.destination?.route }
    val openTab: (Screen) -> Unit = { screen ->
        navController.navigate(screen.route) {
            popUpTo(navController.graph.startDestinationId) { saveState = true }
            launchSingleTop = true
            restoreState = true
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbarHostState, modifier = Modifier.padding(bottom = if (onTopLevel) 84.dp else 0.dp)) },
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(top = padding.calculateTopPadding())) {
            NavHost(
                navController = navController,
                startDestination = Screen.Connections.route,
                modifier = Modifier.fillMaxSize(),
            ) {
                composable(Screen.Connections.route) {
                    ConnectionsScreen(
                        viewModel = connectionsViewModel,
                        onOpenServices = { openTab(Screen.Services) },
                        onOpenAppRouting = { navController.navigate(SubPage.AppRouting.route) },
                        onImportNode = { openTab(Screen.Proxies) },
                    )
                }
                composable(Screen.Services.route) {
                    ServicesScreen(viewModel = connectionsViewModel, onImportNode = { openTab(Screen.Proxies) })
                }
                composable(Screen.Proxies.route) { ProxiesScreen(viewModel = proxiesViewModel) }
                composable(Screen.Settings.route) {
                    SettingsScreen(
                        onLogout = onLogout,
                        onOpenRouting = { navController.navigate(SubPage.Routing.route) },
                        onOpenClientRules = { navController.navigate(SubPage.ClientRules.route) },
                        onOpenAppRouting = { navController.navigate(SubPage.AppRouting.route) },
                    )
                }
                composable(SubPage.Routing.route) {
                    SubPageScaffold(SubPage.Routing, onBack = { navController.popBackStack() }) {
                        RoutingScreen(showTitle = false)
                    }
                }
                composable(SubPage.ClientRules.route) {
                    SubPageScaffold(SubPage.ClientRules, onBack = { navController.popBackStack() }) {
                        ClientRulesScreen(showTitle = false)
                    }
                }
                composable(SubPage.AppRouting.route) {
                    val connections by connectionsViewModel.state.collectAsState()
                    val lines = remember(connections.forwards, connections.nodeByForward) { connections.appGroupLines() }
                    SubPageScaffold(SubPage.AppRouting, onBack = { navController.popBackStack() }) {
                        AppRoutingScreen(lines = lines)
                    }
                }
            }
            if (onTopLevel) {
                val connectionState by connectionsViewModel.state.collectAsState()
                NavigationDock(
                    navController = navController,
                    onTab = openTab,
                    vpnStatus = connectionState.vpnStatus,
                    onPower = connectToggle(connectionsViewModel, connectionState, activity),
                    modifier = Modifier.align(Alignment.BottomCenter),
                )
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SubPageScaffold(page: SubPage, onBack: () -> Unit, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize()) {
        TopAppBar(
            title = { Text(stringResource(page.titleRes)) },
            // The surrounding Scaffold already pads for the status bar.
            windowInsets = WindowInsets(0),
            navigationIcon = {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.common_back))
                }
            },
        )
        content()
    }
}

/**
 * FlClash-style dock: a floating pill holding the tabs, with the round start/stop button
 * beside it.
 */
@Composable
private fun NavigationDock(
    navController: NavHostController,
    onTab: (Screen) -> Unit,
    vpnStatus: VpnStatus,
    onPower: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = navBackStackEntry?.destination
    Row(
        modifier = modifier
            .fillMaxWidth()
            .navigationBarsPadding()
            .padding(start = 16.dp, end = 16.dp, bottom = 12.dp)
            .height(64.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Surface(
            modifier = Modifier.weight(1f).fillMaxHeight().shadow(8.dp, CircleShape),
            shape = CircleShape,
            color = MaterialTheme.colorScheme.surfaceContainerHigh,
        ) {
            Row(
                modifier = Modifier.padding(4.dp),
                horizontalArrangement = Arrangement.SpaceEvenly,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Screen.entries.forEach { screen ->
                    val selected = currentDestination?.hierarchy?.any { it.route == screen.route } == true ||
                        (currentDestination == null && screen == Screen.Connections)
                    DockItem(screen = screen, selected = selected, onClick = { onTab(screen) }, modifier = Modifier.weight(1f))
                }
            }
        }
        Spacer(Modifier.width(10.dp))
        PowerButton(vpnStatus = vpnStatus, onClick = onPower)
    }
}

@Composable
private fun DockItem(screen: Screen, selected: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val label = stringResource(screen.labelRes)
    val background by animateColorAsState(
        if (selected) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceContainerHigh,
        label = "dock-item",
    )
    Surface(
        onClick = onClick,
        modifier = modifier.fillMaxHeight(),
        shape = CircleShape,
        color = background,
        contentColor = if (selected) MaterialTheme.colorScheme.onSecondaryContainer else MaterialTheme.colorScheme.onSurfaceVariant,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Icon(if (selected) screen.selectedIcon else screen.icon, contentDescription = label, modifier = Modifier.size(22.dp))
            Text(label, fontSize = 10.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun PowerButton(vpnStatus: VpnStatus, onClick: () -> Unit) {
    val connected = vpnStatus == VpnStatus.CONNECTED
    val container by animateColorAsState(
        if (connected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.primaryContainer,
        label = "power-container",
    )
    Surface(
        onClick = onClick,
        enabled = vpnStatus != VpnStatus.CONNECTING,
        modifier = Modifier.size(64.dp).shadow(8.dp, CircleShape),
        shape = CircleShape,
        color = container,
        contentColor = if (connected) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onPrimaryContainer,
    ) {
        Box(contentAlignment = Alignment.Center) {
            when (vpnStatus) {
                VpnStatus.CONNECTING -> CircularProgressIndicator(Modifier.size(26.dp), strokeWidth = 3.dp)
                VpnStatus.CONNECTED -> Icon(
                    Icons.Filled.Pause,
                    contentDescription = stringResource(R.string.connections_power_disconnect),
                    modifier = Modifier.size(30.dp),
                )
                VpnStatus.DISCONNECTED -> Icon(
                    Icons.Filled.PlayArrow,
                    contentDescription = stringResource(R.string.connections_power_connect),
                    modifier = Modifier.size(32.dp),
                )
            }
        }
    }
}
