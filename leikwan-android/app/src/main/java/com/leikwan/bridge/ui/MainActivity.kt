package com.leikwan.bridge.ui

import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.net.VpnService
import android.os.Bundle
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.core.view.WindowCompat
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.collectAsState
import com.leikwan.bridge.R
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.proxy.ImportRouter
import com.leikwan.bridge.security.DeviceSecurity
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.session.SessionGate
import com.leikwan.bridge.ui.login.EnrollmentScreen
import com.leikwan.bridge.ui.nav.MainScreen
import com.leikwan.bridge.ui.security.BlockedDeviceScreen
import com.leikwan.bridge.ui.theme.LeikwanTheme
import com.leikwan.bridge.ui.update.AppUpdatePrompt
import com.leikwan.bridge.update.AppUpdateStatus
import com.leikwan.bridge.update.AppUpdateViewModel
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import java.security.MessageDigest
import java.net.URI

private data class EnrollmentLink(
    val clientId: String,
    val code: String,
)

class MainActivity : ComponentActivity() {

    private var vpnPermissionCallback: (() -> Unit)? = null
    private val appUpdateViewModel: AppUpdateViewModel by viewModels()
    private var updateCheckStarted = false
    private var autoInstallPromptedVersionCode: Long? = null
    private var unknownSourcesPromptedVersionCode: Long? = null

    private val vpnPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        if (result.resultCode == RESULT_OK) {
            vpnPermissionCallback?.invoke()
        }
        vpnPermissionCallback = null
    }

    private val _pendingImport = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val pendingImport = _pendingImport.asSharedFlow()

    private val _clipboardImport = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val clipboardImport = _clipboardImport.asSharedFlow()

    private var lastClipHash: String? = null
    private var pendingEnrollmentLink by mutableStateOf<EnrollmentLink?>(null)

    fun requestVpnPermission(onGranted: () -> Unit) {
        val intent = VpnService.prepare(this)
        if (intent != null) {
            vpnPermissionCallback = onGranted
            vpnPermissionLauncher.launch(intent)
        } else {
            onGranted()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.light(Color.WHITE, Color.WHITE),
            navigationBarStyle = SystemBarStyle.light(Color.WHITE, Color.WHITE),
        )
        WindowCompat.getInsetsController(window, window.decorView).apply {
            isAppearanceLightStatusBars = true
            isAppearanceLightNavigationBars = true
        }
        handleIncomingIntent(intent)
        setContent {
            LeikwanTheme {
                val security = androidx.compose.runtime.remember {
                        DeviceSecurity.evaluate(
                            applicationContext,
                            allowQaTestDeviceExceptions = BuildConfig.ALLOW_QA_TEST_DEVICE_EXCEPTIONS,
                        ).also { result ->
                        android.util.Log.e(
                            "LeiKwanAttest",
                            "startup security result=$result qaFlag=${BuildConfig.ALLOW_QA_TEST_DEVICE_EXCEPTIONS}",
                        )
                    }
                }
                val terminalControl by SessionGate.state.collectAsState()
                val updateState by appUpdateViewModel.state.collectAsState()
                if (security != DeviceSecurityResult.Allowed
                    || terminalControl?.code in setOf("device_banned", "device_untrusted")) {
                    BlockedDeviceScreen()
                } else {
                    Box(modifier = androidx.compose.ui.Modifier.fillMaxSize()) {
                        var isLoggedIn by remember { mutableStateOf(Prefs.get(this@MainActivity).isLoggedIn()) }
                        val enrollmentLink = pendingEnrollmentLink
                        if (isLoggedIn && terminalControl == null) {
                            MainScreen(
                                onLogout = {
                                    SessionGate.clear()
                                    isLoggedIn = false
                                },
                                updateState = updateState,
                                onCheckForUpdates = { appUpdateViewModel.checkForUpdates(autoDownload = false) },
                                onDownloadUpdate = { appUpdateViewModel.downloadAndPrepare() },
                                onInstallUpdate = { launchUpdateInstall(force = true) },
                                onAllowUnknownSources = { openUnknownSourcesSettings() },
                            )
                        } else {
                            EnrollmentScreen(
                                onEnrollmentSuccess = {
                                    SessionGate.clear()
                                    isLoggedIn = true
                                },
                                prefilledClientId = enrollmentLink?.clientId.orEmpty(),
                                prefilledEnrollmentCode = enrollmentLink?.code.orEmpty(),
                                onPrefillConsumed = { pendingEnrollmentLink = null },
                            )
                        }
                        AppUpdatePrompt(
                            state = updateState,
                            onCheckForUpdates = { appUpdateViewModel.checkForUpdates(autoDownload = false) },
                            onDownloadUpdate = { appUpdateViewModel.downloadAndPrepare() },
                            onInstallUpdate = { launchUpdateInstall(force = true) },
                            onAllowUnknownSources = { openUnknownSourcesSettings() },
                            onDismiss = { appUpdateViewModel.reset() },
                        )
                        val ready = updateState.status as? AppUpdateStatus.ReadyToInstall
                        LaunchedEffect(ready?.manifest?.versionCode) {
                            if (ready != null) launchUpdateInstall(force = false)
                        }
                    }
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIncomingIntent(intent)
    }

    override fun onResume() {
        super.onResume()
        checkClipboard()
        if (!updateCheckStarted) {
            updateCheckStarted = true
            appUpdateViewModel.checkForUpdates(autoDownload = true)
        }
        if (appUpdateViewModel.state.value.status is AppUpdateStatus.ReadyToInstall) {
            launchUpdateInstall(force = false)
        }
    }

    private fun launchUpdateInstall(force: Boolean) {
        val ready = appUpdateViewModel.state.value.status as? AppUpdateStatus.ReadyToInstall ?: return
        if (!force && autoInstallPromptedVersionCode == ready.manifest.versionCode) return

        if (!appUpdateViewModel.canRequestPackageInstalls()) {
            if (force || unknownSourcesPromptedVersionCode != ready.manifest.versionCode) {
                unknownSourcesPromptedVersionCode = ready.manifest.versionCode
                openUnknownSourcesSettings()
            }
            return
        }
        unknownSourcesPromptedVersionCode = null

        val intent = appUpdateViewModel.installIntent().getOrNull()
        if (intent == null) {
            Toast.makeText(this, R.string.update_install_prepare_failed, Toast.LENGTH_LONG).show()
            return
        }
        runCatching { startActivity(intent) }
            .onSuccess { autoInstallPromptedVersionCode = ready.manifest.versionCode }
            .onFailure {
                Toast.makeText(this, R.string.update_install_failed, Toast.LENGTH_LONG).show()
            }
    }

    private fun openUnknownSourcesSettings() {
        runCatching { startActivity(appUpdateViewModel.unknownSourcesSettingsIntent()) }
            .onFailure {
                Toast.makeText(this, R.string.update_unknown_sources_settings_failed, Toast.LENGTH_LONG).show()
            }
    }

    private fun handleIncomingIntent(intent: Intent?) {
        parseEnrollmentLink(intent)?.let { link ->
            pendingEnrollmentLink = link
            return
        }
        if (intent?.action == Intent.ACTION_SEND && intent.type == "text/plain") {
            intent.getStringExtra(Intent.EXTRA_TEXT)?.let { text ->
                _pendingImport.tryEmit(text)
            }
        }
    }

    private fun parseEnrollmentLink(intent: Intent?): EnrollmentLink? {
        if (intent?.action != Intent.ACTION_VIEW) return null
        val uri = intent.data ?: return null
        val panelHost = runCatching { URI(BuildConfig.PANEL_URL).host }.getOrNull() ?: return null
        if (!uri.scheme.equals("https", ignoreCase = true)
            || !uri.host.equals(panelHost, ignoreCase = true)
            || uri.path != "/android/enroll") {
            return null
        }
        val clientId = uri.getQueryParameter("client_id")?.trim()?.takeIf { it.matches(Regex("^[1-9][0-9]{0,17}$")) }
            ?: return null
        val code = uri.getQueryParameter("code")?.trim()?.takeIf { it.matches(Regex("^[A-Za-z0-9_-]{16,256}$")) }
            ?: return null
        return EnrollmentLink(clientId, code)
    }

    private fun checkClipboard() {
        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return
        val clip = clipboard.primaryClip ?: return
        if (clip.itemCount == 0) return
        val text = clip.getItemAt(0).text?.toString() ?: return
        if (!ImportRouter.looksImportable(text)) return

        val hash = sha256(text)
        if (hash == lastClipHash) return
        lastClipHash = hash

        _clipboardImport.tryEmit(text)
    }

    private fun sha256(input: String): String {
        val bytes = MessageDigest.getInstance("SHA-256").digest(input.toByteArray())
        return bytes.joinToString("") { "%02x".format(it.toInt() and 0xff) }
    }
}
