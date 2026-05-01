# Android URL Import & QR Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable the Android app to import proxy URIs and subscription URLs from share intents, clipboard detection, gallery QR codes, and fix SS 2022 cipher parsing.

**Architecture:** A new `ImportRouter` object classifies any input text as proxy URIs, subscription URLs, or unknown. All entry points (share intent, clipboard, gallery QR, camera QR, manual paste) feed through `ImportRouter`. Results are handled by a new `importAny()` method on `ProxiesViewModel` that adds nodes or subscriptions and reports results via a snackbar message flow.

**Tech Stack:** Kotlin, Jetpack Compose, ML Kit BarcodeScanning, CameraX, Room, Android Intents

**Spec:** `docs/superpowers/specs/2026-04-13-android-url-import-design.md`

---

### Task 1: Fix SS 2022 Parser

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/ProxyUriParser.kt`

- [ ] **Step 1: Fix `parseShadowsocks()` to handle SS 2022 ciphers**

The current SIP002 branch unconditionally base64-decodes the userinfo, which fails for SS 2022 URIs where the method name is plaintext (e.g. `2022-blake3-aes-256-gcm:KEY1:KEY2@host:port`).

Replace the entire `parseShadowsocks` method in `ProxyUriParser.kt`:

```kotlin
private fun parseShadowsocks(uri: String): ProxyNode? {
    return try {
        val withoutScheme = uri.removePrefix("ss://")
        val name = withoutScheme.substringAfter("#", "").let {
            URLDecoder.decode(it, "UTF-8").ifEmpty { null }
        }
        val mainPart = withoutScheme.substringBefore("#")

        val server: String
        val port: Int
        val method: String
        val password: String

        if (mainPart.contains("@")) {
            val userInfo = mainPart.substringBefore("@")
            val hostPort = mainPart.substringAfter("@")

            // Try base64 decode first (standard SIP002: base64(method:password)@host:port)
            val decoded = tryBase64Decode(userInfo)
            if (decoded != null && decoded.contains(":") && !decoded.startsWith("2022-")) {
                method = decoded.substringBefore(":")
                password = decoded.substringAfter(":")
            } else {
                // SS 2022 or plaintext format: method:key[:identity_key]@host:port
                val plaintext = URLDecoder.decode(userInfo, "UTF-8")
                val firstColon = plaintext.indexOf(":")
                if (firstColon < 0) return null
                method = plaintext.substring(0, firstColon)
                password = plaintext.substring(firstColon + 1)
            }

            server = hostPort.substringBeforeLast(":")
            port = hostPort.substringAfterLast(":").toInt()
        } else {
            val decoded = String(Base64.decode(mainPart, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP))
            method = decoded.substringBefore(":")
            val rest = decoded.substringAfter(":")
            password = rest.substringBefore("@")
            val hostPort = rest.substringAfter("@")
            server = hostPort.substringBeforeLast(":")
            port = hostPort.substringAfterLast(":").toInt()
        }

        val config = JsonObject().apply {
            addProperty("method", method)
            addProperty("password", password)
        }

        ProxyNode(
            name = name ?: "$server:$port",
            type = "ss",
            originalServer = server,
            originalPort = port,
            configJson = gson.toJson(config),
        )
    } catch (_: Exception) {
        null
    }
}

private fun tryBase64Decode(input: String): String? {
    return try {
        val bytes = Base64.decode(input, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP)
        val decoded = String(bytes, Charsets.UTF_8)
        // If it contains non-printable chars, it's not valid decoded text
        if (decoded.any { it.code < 32 && it != '\n' && it != '\r' && it != '\t' }) null
        else decoded
    } catch (_: Exception) {
        null
    }
}
```

- [ ] **Step 2: Verify the fix handles both formats**

Verify mentally or via log that:
- `ss://2022-blake3-aes-256-gcm:NDE3MzQ0OTdjZDc4ZDAzMDJiNTJiNzdkMjRiYTI0NTc=:MzBkNGQ1OWMtNWY5MS00OTc5LTg5OTMtN2VmMGE0ZTY=@47.100.39.54:35812#HKG-HKG2` → method=`2022-blake3-aes-256-gcm`, password=`NDE3...=:MzBk...=`, server=`47.100.39.54`, port=`35812`, name=`HKG-HKG2`
- `ss://YWVzLTI1Ni1nY206cGFzc3dvcmQ=@server:8388#name` → standard SIP002 still works (base64 of `aes-256-gcm:password`)

- [ ] **Step 3: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/ProxyUriParser.kt
git commit -m "fix(android): support SS 2022 cipher URIs in ProxyUriParser"
```

---

### Task 2: Create ImportRouter

**Files:**
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/ImportRouter.kt`

- [ ] **Step 1: Create ImportRouter.kt**

```kotlin
package com.leikwan.bridge.proxy

import com.leikwan.bridge.data.ProxyNode

object ImportRouter {

    sealed class ImportResult {
        data class ProxyNodes(val nodes: List<ProxyNode>) : ImportResult()
        data class SubscriptionUrl(val url: String) : ImportResult()
        data class Mixed(val nodes: List<ProxyNode>, val subscriptionUrls: List<String>) : ImportResult()
        data class Empty(val rawInput: String) : ImportResult()
    }

    private val proxySchemes = listOf("ss://", "vmess://", "vless://")

    fun process(text: String): ImportResult {
        val trimmed = text.trim()
        if (trimmed.isEmpty()) return ImportResult.Empty(text)

        val nodes = mutableListOf<ProxyNode>()
        val subUrls = mutableListOf<String>()

        val lines = trimmed.lines().map { it.trim() }.filter { it.isNotEmpty() }

        for (line in lines) {
            when {
                proxySchemes.any { line.startsWith(it, ignoreCase = true) } -> {
                    ProxyUriParser.parse(line)?.let { nodes.add(it) }
                }
                line.startsWith("http://", ignoreCase = true) ||
                    line.startsWith("https://", ignoreCase = true) -> {
                    subUrls.add(line)
                }
            }
        }

        // If nothing matched line-by-line, try base64 decode of the whole input
        if (nodes.isEmpty() && subUrls.isEmpty()) {
            val decoded = ProxyUriParser.parseMultiple(trimmed)
            if (decoded.isNotEmpty()) {
                return ImportResult.ProxyNodes(decoded)
            }
        }

        return when {
            nodes.isNotEmpty() && subUrls.isNotEmpty() -> ImportResult.Mixed(nodes, subUrls)
            nodes.isNotEmpty() -> ImportResult.ProxyNodes(nodes)
            subUrls.isNotEmpty() -> ImportResult.SubscriptionUrl(subUrls.first())
            else -> ImportResult.Empty(text)
        }
    }

    fun looksImportable(text: String): Boolean {
        val trimmed = text.trim()
        return proxySchemes.any { trimmed.startsWith(it, ignoreCase = true) } ||
            trimmed.startsWith("http://", ignoreCase = true) ||
            trimmed.startsWith("https://", ignoreCase = true)
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/ImportRouter.kt
git commit -m "feat(android): add ImportRouter for unified import classification"
```

---

### Task 3: Add `importAny()` to ProxiesViewModel

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesViewModel.kt`

- [ ] **Step 1: Add snackbar message flow and importAny method**

Add a new `snackbarMessage` flow and the `importAny` method to `ProxiesViewModel`. Add these imports at the top:

```kotlin
import com.leikwan.bridge.proxy.ImportRouter
```

Add this field after `val state = _state.asStateFlow()`:

```kotlin
private val _snackbar = MutableStateFlow<String?>(null)
val snackbar = _snackbar.asStateFlow()

fun clearSnackbar() {
    _snackbar.value = null
}
```

Add this method after `clearError()`:

```kotlin
fun importAny(text: String) {
    viewModelScope.launch {
        when (val result = ImportRouter.process(text)) {
            is ImportRouter.ImportResult.ProxyNodes -> {
                nodeDao.insertAll(result.nodes)
                _snackbar.value = "Added ${result.nodes.size} proxy node(s)"
            }
            is ImportRouter.ImportResult.SubscriptionUrl -> {
                _state.value = _state.value.copy(isLoading = true)
                try {
                    val url = result.url
                    val name = url.substringAfterLast("/").take(16).ifEmpty { "Subscription" }
                    val subId = subDao.insert(
                        com.leikwan.bridge.data.Subscription(name = name, url = url)
                    )
                    val nodes = SubscriptionFetcher.fetch(url)
                        .map { it.copy(subscriptionId = subId) }
                    nodeDao.insertAll(nodes)
                    subDao.update(subDao.getById(subId)!!.copy(
                        lastUpdated = System.currentTimeMillis()
                    ))
                    _snackbar.value = "Subscription added (${nodes.size} nodes)"
                    _state.value = _state.value.copy(isLoading = false)
                } catch (e: Exception) {
                    _state.value = _state.value.copy(isLoading = false)
                    _snackbar.value = "Failed to fetch subscription: ${e.message}"
                }
            }
            is ImportRouter.ImportResult.Mixed -> {
                nodeDao.insertAll(result.nodes)
                for (url in result.subscriptionUrls) {
                    try {
                        val name = url.substringAfterLast("/").take(16).ifEmpty { "Subscription" }
                        val subId = subDao.insert(
                            com.leikwan.bridge.data.Subscription(name = name, url = url)
                        )
                        val nodes = SubscriptionFetcher.fetch(url)
                            .map { it.copy(subscriptionId = subId) }
                        nodeDao.insertAll(nodes)
                        subDao.update(subDao.getById(subId)!!.copy(
                            lastUpdated = System.currentTimeMillis()
                        ))
                    } catch (_: Exception) {}
                }
                _snackbar.value = "Imported ${result.nodes.size} node(s) and ${result.subscriptionUrls.size} subscription(s)"
            }
            is ImportRouter.ImportResult.Empty -> {
                _snackbar.value = "No proxy links found"
            }
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesViewModel.kt
git commit -m "feat(android): add importAny() with snackbar feedback to ProxiesViewModel"
```

---

### Task 4: Add Share Intent to AndroidManifest and MainActivity

**Files:**
- Modify: `leikwan-android/app/src/main/AndroidManifest.xml`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/MainActivity.kt`

- [ ] **Step 1: Add ACTION_SEND intent filter to AndroidManifest.xml**

Add a second `<intent-filter>` block inside the `<activity>` tag for `MainActivity`, after the existing launcher intent filter:

```xml
            <intent-filter>
                <action android:name="android.intent.action.SEND" />
                <category android:name="android.intent.category.DEFAULT" />
                <data android:mimeType="text/plain" />
            </intent-filter>
```

Also add `android:launchMode="singleTop"` to the `<activity>` tag so re-launches don't create duplicate activities.

The full `<activity>` block should become:

```xml
        <activity
            android:name=".ui.MainActivity"
            android:exported="true"
            android:launchMode="singleTop"
            android:theme="@style/Theme.LeikwanBridge">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
            <intent-filter>
                <action android:name="android.intent.action.SEND" />
                <category android:name="android.intent.category.DEFAULT" />
                <data android:mimeType="text/plain" />
            </intent-filter>
        </activity>
```

- [ ] **Step 2: Handle share intent and clipboard in MainActivity.kt**

Replace the entire `MainActivity.kt` with:

```kotlin
package com.leikwan.bridge.ui

import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.proxy.ImportRouter
import com.leikwan.bridge.ui.login.LoginScreen
import com.leikwan.bridge.ui.nav.MainScreen
import com.leikwan.bridge.ui.theme.LeikwanTheme
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow
import java.security.MessageDigest

class MainActivity : ComponentActivity() {

    private var vpnPermissionCallback: (() -> Unit)? = null

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
        enableEdgeToEdge()
        handleIncomingIntent(intent)
        setContent {
            LeikwanTheme {
                var isLoggedIn by mutableStateOf(Prefs.get(this).isLoggedIn())
                if (isLoggedIn) {
                    MainScreen(onLogout = { isLoggedIn = false })
                } else {
                    LoginScreen(onLoginSuccess = { isLoggedIn = true })
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleIncomingIntent(intent)
    }

    override fun onResume() {
        super.onResume()
        checkClipboard()
    }

    private fun handleIncomingIntent(intent: Intent?) {
        if (intent?.action == Intent.ACTION_SEND && intent.type == "text/plain") {
            intent.getStringExtra(Intent.EXTRA_TEXT)?.let { text ->
                _pendingImport.tryEmit(text)
            }
        }
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
        return bytes.joinToString("") { "%02x".format(it) }
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add leikwan-android/app/src/main/AndroidManifest.xml leikwan-android/app/src/main/java/com/leikwan/bridge/ui/MainActivity.kt
git commit -m "feat(android): handle share intent and clipboard detection in MainActivity"
```

---

### Task 5: Wire Import Flows Into MainScreen with Snackbar

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt`

- [ ] **Step 1: Update MainScreen to observe import flows and show snackbars**

Replace the entire `AppNavigation.kt` with:

```kotlin
package com.leikwan.bridge.ui.nav

import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lan
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
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.leikwan.bridge.ui.MainActivity
import com.leikwan.bridge.ui.proxies.ProxiesScreen
import com.leikwan.bridge.ui.proxies.ProxiesViewModel
import com.leikwan.bridge.ui.settings.SettingsScreen
import com.leikwan.bridge.ui.tunnels.TunnelsScreen

enum class Screen(val route: String, val label: String, val icon: ImageVector) {
    Tunnels("tunnels", "Tunnels", Icons.Default.Lan),
    Proxies("proxies", "Proxies", Icons.Default.Shield),
    Settings("settings", "Settings", Icons.Default.Settings),
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
                message = "Proxy link detected in clipboard",
                actionLabel = "Import",
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
            startDestination = Screen.Tunnels.route,
            modifier = Modifier.padding(padding),
        ) {
            composable(Screen.Tunnels.route) { TunnelsScreen() }
            composable(Screen.Proxies.route) { ProxiesScreen(viewModel = proxiesViewModel) }
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
            NavigationBarItem(
                icon = { Icon(screen.icon, contentDescription = screen.label) },
                label = { Text(screen.label) },
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
```

- [ ] **Step 2: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt
git commit -m "feat(android): wire import flows and snackbar into MainScreen"
```

---

### Task 6: Gallery QR Import and QR Scanner Enhancement

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/QrScanScreen.kt`

- [ ] **Step 1: Replace QrScanScreen.kt with gallery support and ImportRouter classification**

Replace the entire `QrScanScreen.kt` with:

```kotlin
package com.leikwan.bridge.ui.proxies

import android.Manifest
import android.content.pm.PackageManager
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ExperimentalGetImage
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.PhotoLibrary
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import com.leikwan.bridge.proxy.ImportRouter
import java.util.concurrent.Executors

@Composable
fun QrScanScreen(onResult: (String) -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    var hasPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED,
        )
    }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        hasPermission = it
    }

    var scanned by remember { mutableStateOf(false) }

    // Gallery picker
    val galleryLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.PickVisualMedia()
    ) { uri: Uri? ->
        if (uri != null && !scanned) {
            val image = InputImage.fromFilePath(context, uri)
            BarcodeScanning.getClient().process(image)
                .addOnSuccessListener { barcodes ->
                    for (barcode in barcodes) {
                        barcode.rawValue?.let { value ->
                            val result = ImportRouter.process(value)
                            if (!scanned && result !is ImportRouter.ImportResult.Empty) {
                                scanned = true
                                onResult(value)
                            }
                        }
                    }
                }
        }
    }

    LaunchedEffect(Unit) {
        if (!hasPermission) permissionLauncher.launch(Manifest.permission.CAMERA)
    }

    Box(modifier = Modifier.fillMaxSize()) {
        if (hasPermission) {
            AndroidView(
                factory = { ctx ->
                    val previewView = PreviewView(ctx)
                    val cameraProvider = ProcessCameraProvider.getInstance(ctx).get()
                    val preview = Preview.Builder().build().also {
                        it.setSurfaceProvider(previewView.surfaceProvider)
                    }
                    val analyzer = ImageAnalysis.Builder().build().also {
                        it.setAnalyzer(Executors.newSingleThreadExecutor()) { imageProxy ->
                            @ExperimentalGetImage
                            val mediaImage = imageProxy.image
                            if (mediaImage != null && !scanned) {
                                val image = InputImage.fromMediaImage(
                                    mediaImage,
                                    imageProxy.imageInfo.rotationDegrees,
                                )
                                BarcodeScanning.getClient().process(image)
                                    .addOnSuccessListener { barcodes ->
                                        for (barcode in barcodes) {
                                            barcode.rawValue?.let { value ->
                                                val result = ImportRouter.process(value)
                                                if (!scanned && result !is ImportRouter.ImportResult.Empty) {
                                                    scanned = true
                                                    onResult(value)
                                                }
                                            }
                                        }
                                    }
                                    .addOnCompleteListener { imageProxy.close() }
                            } else {
                                imageProxy.close()
                            }
                        }
                    }
                    cameraProvider.unbindAll()
                    cameraProvider.bindToLifecycle(
                        lifecycleOwner,
                        CameraSelector.DEFAULT_BACK_CAMERA,
                        preview,
                        analyzer,
                    )
                    previewView
                },
                modifier = Modifier.fillMaxSize(),
            )
        } else {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text("Camera permission required")
            }
        }

        // Top-right action buttons
        Row(
            modifier = Modifier.align(Alignment.TopEnd).padding(16.dp),
        ) {
            IconButton(onClick = {
                galleryLauncher.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
            }) {
                Icon(Icons.Default.PhotoLibrary, "Import from gallery", tint = MaterialTheme.colorScheme.onSurface)
            }
            IconButton(onClick = onDismiss) {
                Icon(Icons.Default.Close, "Close", tint = MaterialTheme.colorScheme.onSurface)
            }
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/QrScanScreen.kt
git commit -m "feat(android): add gallery QR import and accept subscription URLs in scanner"
```

---

### Task 7: Update ProxiesScreen to Use importAny for QR Results

**Files:**
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesScreen.kt`

- [ ] **Step 1: Change QR scan result handler to use importAny**

In `ProxiesScreen.kt`, find:

```kotlin
    if (showQrScan) {
        QrScanScreen(
            onResult = { uri -> viewModel.addFromUri(uri); showQrScan = false },
            onDismiss = { showQrScan = false },
        )
        return
    }
```

Replace with:

```kotlin
    if (showQrScan) {
        QrScanScreen(
            onResult = { text -> viewModel.importAny(text); showQrScan = false },
            onDismiss = { showQrScan = false },
        )
        return
    }
```

- [ ] **Step 2: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesScreen.kt
git commit -m "feat(android): route QR scan results through importAny"
```

---

### Task 8: Build APK

- [ ] **Step 1: Build debug APK**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

Expected: `BUILD SUCCESSFUL`, APK at `app/build/outputs/apk/debug/app-debug.apk`

- [ ] **Step 2: Verify APK exists**

```bash
ls -lh app/build/outputs/apk/debug/app-debug.apk
```
