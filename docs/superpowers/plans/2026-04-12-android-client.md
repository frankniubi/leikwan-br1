# LeiKwan Bridge Android Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a native Android app that connects to the LeiKwan Panel, establishes WG+bond tunnels, integrates sing-box for proxy protocol support (ss/vless/vmess/subscriptions/QR), and runs as a VPN service.

**Architecture:** Kotlin + Jetpack Compose UI, libbox.aar (sing-box Go mobile library) for VPN/tun/proxy, leikwan-bond Rust CLI as subprocess for tunnel aggregation. Panel API via Retrofit. Room DB for local storage.

**Tech Stack:** Kotlin, Jetpack Compose, Material Design 3, libbox.aar, Retrofit+OkHttp, Room, ML Kit barcode, Android NDK (Rust cross-compile)

**Scope note:** WireGuard userspace tunnels (for MPTCP underlay) are deferred to a follow-up iteration. The MVP uses bond legs that connect directly via WSS/relay addresses (the common case for most forward configs). When WG is needed, the TunnelOrchestrator will be extended with wireguard-go integration.

---

## File Structure

```
leikwan-android/
  app/
    src/main/
      java/com/leikwan/bridge/
        LeikwanApp.kt                          — Application class, DI init
        ui/
          MainActivity.kt                      — Single activity, nav host
          login/LoginScreen.kt                 — Login UI
          login/LoginViewModel.kt              — Login logic
          tunnels/TunnelsScreen.kt             — Tunnels tab UI
          tunnels/TunnelsViewModel.kt          — Tunnel/VPN state
          proxies/ProxiesScreen.kt             — Proxies tab UI
          proxies/ProxiesViewModel.kt          — Proxy node CRUD
          proxies/AddProxyDialog.kt            — URI/subscription input dialog
          proxies/QrScanScreen.kt              — Camera QR scanner
          settings/SettingsScreen.kt           — Settings tab UI
          settings/SettingsViewModel.kt        — Settings logic
          theme/Theme.kt                       — Dark theme + colors
          theme/Type.kt                        — Typography
          nav/AppNavigation.kt                 — Bottom nav + NavHost
        service/
          LeikwanVpnService.kt                 — VpnService + PlatformInterface impl
          BoxServiceManager.kt                 — CommandServer lifecycle wrapper
          TunnelOrchestrator.kt                — Start/stop WG+bond+singbox sequence
        api/
          PanelApi.kt                          — Retrofit interface
          PanelModels.kt                       — Data classes (LoginResponse, ForwardInfo, etc.)
          PanelRepository.kt                   — API + token management
        bond/
          BondManager.kt                       — Process spawn/kill for leikwan-bond
        singbox/
          SingboxConfigBuilder.kt              — Generate sing-box JSON config
        proxy/
          ProxyUriParser.kt                    — Parse ss://, vless://, vmess:// URIs
          SubscriptionFetcher.kt               — Fetch + decode subscription URLs
        data/
          AppDatabase.kt                       — Room database
          ProxyNodeDao.kt                      — DAO for proxy_nodes
          SubscriptionDao.kt                   — DAO for subscriptions
          Entities.kt                          — Room entities
          Prefs.kt                             — EncryptedSharedPreferences wrapper
      jniLibs/
        arm64-v8a/libleikwan_bond.so           — leikwan-bond arm64 binary
      libs/
        libbox.aar                             — sing-box Go mobile library
      res/
        mipmap-mdpi/ic_launcher.png
        mipmap-hdpi/ic_launcher.png
        mipmap-xhdpi/ic_launcher.png
        mipmap-xxhdpi/ic_launcher.png
        mipmap-xxxhdpi/ic_launcher.png
        mipmap-anydpi-v26/ic_launcher.xml      — Adaptive icon
        drawable/ic_launcher_foreground.png
        values/strings.xml
        values/themes.xml
        xml/network_security_config.xml
      AndroidManifest.xml
    build.gradle.kts                           — App-level build config
  build.gradle.kts                             — Project-level build config
  settings.gradle.kts                          — Project settings
  gradle.properties
  local.properties
```

---

## Task 1: Environment Setup — WSL Cross-Compilation Toolchain

**Files:**
- Create: `wstunnel-main/.cargo/config.toml` (modify existing)
- Output: `wstunnel-main/leikwan-bond/target/aarch64-linux-android/release/leikwan-bond`

This task sets up the WSL environment to cross-compile leikwan-bond for Android arm64, and builds libbox.aar from sing-box source.

- [ ] **Step 1: Install Android NDK and Rust Android targets in WSL**

Run in WSL:
```bash
# Install rustup (replacing apt-installed rustc)
sudo apt remove -y rustc cargo 2>/dev/null
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
source ~/.cargo/env

# Add Android target
rustup target add aarch64-linux-android

# Install Android NDK via command-line tools
sudo apt-get update && sudo apt-get install -y unzip wget openjdk-17-jdk
mkdir -p ~/android-sdk/cmdline-tools
cd /tmp
wget -q https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip -O cmdline-tools.zip
unzip -q cmdline-tools.zip
mv cmdline-tools ~/android-sdk/cmdline-tools/latest
export ANDROID_HOME=~/android-sdk
export PATH=$PATH:$ANDROID_HOME/cmdline-tools/latest/bin
yes | sdkmanager --licenses 2>/dev/null
sdkmanager "ndk;27.0.12077973" "platforms;android-34" "build-tools;34.0.0"
```

Expected: NDK installed at `~/android-sdk/ndk/27.0.12077973/`, Rust target `aarch64-linux-android` available.

- [ ] **Step 2: Configure Rust NDK linker**

Add to `wstunnel-main/.cargo/config.toml`:
```toml
[target.aarch64-linux-android]
linker = "/home/<user>/android-sdk/ndk/27.0.12077973/toolchains/llvm/prebuilt/linux-x86_64/bin/aarch64-linux-android21-clang"
```

Replace `<user>` with the actual WSL username.

- [ ] **Step 3: Cross-compile leikwan-bond for Android arm64**

Run in WSL:
```bash
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/wstunnel-main
cargo build --release -p leikwan-bond --target aarch64-linux-android
```

Expected: Binary at `target/aarch64-linux-android/release/leikwan-bond`. Verify:
```bash
file target/aarch64-linux-android/release/leikwan-bond
# Expected: ELF 64-bit LSB pie executable, ARM aarch64
```

- [ ] **Step 4: Install Go and gomobile, build libbox.aar**

Run in WSL:
```bash
# Install Go
wget -q https://go.dev/dl/go1.22.5.linux-amd64.tar.gz
sudo tar -C /usr/local -xzf go1.22.5.linux-amd64.tar.gz
export PATH=$PATH:/usr/local/go/bin:~/go/bin
export GOPATH=~/go

# Install gomobile
go install golang.org/x/mobile/cmd/gomobile@latest
go install golang.org/x/mobile/cmd/gobind@latest
gomobile init

# Clone and build sing-box libbox
cd /tmp
git clone --depth 1 https://github.com/sagernet/sing-box.git
cd sing-box

# Set Android SDK/NDK for gomobile
export ANDROID_HOME=~/android-sdk
export ANDROID_NDK_HOME=~/android-sdk/ndk/27.0.12077973

gomobile bind -v -androidapi 21 -javapkg=io.nekohasekai -target=android/arm64 ./experimental/libbox
```

Expected: `libbox.aar` and `libbox-sources.jar` in current directory.

- [ ] **Step 5: Copy build artifacts to project**

```bash
# From WSL
mkdir -p /mnt/c/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/libs
mkdir -p /mnt/c/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/jniLibs/arm64-v8a

cp /tmp/sing-box/libbox.aar /mnt/c/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/libs/
cp /mnt/c/Users/frankniubi/Downloads/leikwan-br1/wstunnel-main/target/aarch64-linux-android/release/leikwan-bond \
   /mnt/c/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/jniLibs/arm64-v8a/libleikwan_bond.so
```

- [ ] **Step 6: Commit**

```bash
git add wstunnel-main/.cargo/config.toml
git commit -m "build: add Android arm64 cross-compilation config for leikwan-bond"
```

---

## Task 2: Android Project Scaffold

**Files:**
- Create: `leikwan-android/build.gradle.kts`
- Create: `leikwan-android/settings.gradle.kts`
- Create: `leikwan-android/gradle.properties`
- Create: `leikwan-android/app/build.gradle.kts`
- Create: `leikwan-android/app/src/main/AndroidManifest.xml`
- Create: `leikwan-android/app/src/main/res/values/strings.xml`
- Create: `leikwan-android/app/src/main/res/values/themes.xml`
- Create: `leikwan-android/app/src/main/res/xml/network_security_config.xml`

- [ ] **Step 1: Create project-level build.gradle.kts**

```kotlin
// leikwan-android/build.gradle.kts
plugins {
    id("com.android.application") version "8.5.1" apply false
    id("org.jetbrains.kotlin.android") version "1.9.24" apply false
    id("org.jetbrains.kotlin.plugin.compose") version "2.0.0" apply false
    id("com.google.devtools.ksp") version "1.9.24-1.0.20" apply false
}
```

- [ ] **Step 2: Create settings.gradle.kts**

```kotlin
// leikwan-android/settings.gradle.kts
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolution {
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = "LeiKwanBridge"
include(":app")
```

- [ ] **Step 3: Create gradle.properties**

```properties
# leikwan-android/gradle.properties
org.gradle.jvmargs=-Xmx2048m
android.useAndroidX=true
kotlin.code.style=official
android.nonTransitiveRClass=true
```

- [ ] **Step 4: Create app/build.gradle.kts**

```kotlin
// leikwan-android/app/build.gradle.kts
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("com.google.devtools.ksp")
}

android {
    namespace = "com.leikwan.bridge"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.leikwan.bridge"
        minSdk = 26  // VpnService + libbox needs API 26+
        targetSdk = 34
        versionCode = 1
        versionName = "0.1.0"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
    }

    packaging {
        jniLibs {
            useLegacyPackaging = true  // required to extract libleikwan_bond.so
        }
    }
}

dependencies {
    // Compose
    val composeBom = platform("androidx.compose:compose-bom:2024.06.00")
    implementation(composeBom)
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.activity:activity-compose:1.9.1")
    implementation("androidx.navigation:navigation-compose:2.7.7")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.4")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.4")
    debugImplementation("androidx.compose.ui:ui-tooling")

    // Networking
    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-gson:2.11.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")

    // Room
    implementation("androidx.room:room-runtime:2.6.1")
    implementation("androidx.room:room-ktx:2.6.1")
    ksp("androidx.room:room-compiler:2.6.1")

    // Encrypted SharedPreferences
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    // QR scanning
    implementation("com.google.mlkit:barcode-scanning:17.3.0")
    implementation("androidx.camera:camera-camera2:1.3.4")
    implementation("androidx.camera:camera-lifecycle:1.3.4")
    implementation("androidx.camera:camera-view:1.3.4")

    // sing-box libbox
    implementation(files("libs/libbox.aar"))

    // Coroutines
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")
}
```

- [ ] **Step 5: Create AndroidManifest.xml**

```xml
<!-- leikwan-android/app/src/main/AndroidManifest.xml -->
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_SPECIAL_USE" />
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
    <uses-permission android:name="android.permission.CAMERA" />
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
    <uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />

    <application
        android:name=".LeikwanApp"
        android:allowBackup="false"
        android:icon="@mipmap/ic_launcher"
        android:label="@string/app_name"
        android:networkSecurityConfig="@xml/network_security_config"
        android:theme="@style/Theme.LeikwanBridge"
        android:usesCleartextTraffic="false">

        <activity
            android:name=".ui.MainActivity"
            android:exported="true"
            android:theme="@style/Theme.LeikwanBridge">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>

        <service
            android:name=".service.LeikwanVpnService"
            android:exported="false"
            android:foregroundServiceType="specialUse"
            android:permission="android.permission.BIND_VPN_SERVICE">
            <intent-filter>
                <action android:name="android.net.VpnService" />
            </intent-filter>
        </service>

    </application>
</manifest>
```

- [ ] **Step 6: Create resource files**

`res/values/strings.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <string name="app_name">LeiKwan Bridge</string>
</resources>
```

`res/values/themes.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <style name="Theme.LeikwanBridge" parent="android:Theme.Material.NoActionBar" />
</resources>
```

`res/xml/network_security_config.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <domain-config cleartextTrafficPermitted="true">
        <domain includeSubdomains="false">127.0.0.1</domain>
    </domain-config>
</network-security-config>
```

- [ ] **Step 7: Generate app icons from logo.png**

Use Python in WSL or Windows to resize the logo for Android mipmap sizes:
```bash
python3 -c "
from PIL import Image
import os
logo = Image.open('C:/Users/frankniubi/Downloads/leikwan-br1/wstunnel-main/leikwan-client-win/src/logo.png')
# Crop to square (center crop from the wider dimension)
size = min(logo.size)
left = (logo.width - size) // 2
top = (logo.height - size) // 2
square = logo.crop((left, top, left + size, top + size))
sizes = {'mdpi': 48, 'hdpi': 72, 'xhdpi': 96, 'xxhdpi': 144, 'xxxhdpi': 192}
base = 'C:/Users/frankniubi/Downloads/leikwan-br1/leikwan-android/app/src/main/res'
for density, px in sizes.items():
    d = os.path.join(base, f'mipmap-{density}')
    os.makedirs(d, exist_ok=True)
    square.resize((px, px), Image.LANCZOS).save(os.path.join(d, 'ic_launcher.png'))
# Also generate foreground for adaptive icon (432px with padding)
fg = Image.new('RGBA', (432, 432), (0, 0, 0, 0))
icon = square.resize((300, 300), Image.LANCZOS)
fg.paste(icon, (66, 66), icon if icon.mode == 'RGBA' else None)
draw_dir = os.path.join(base, 'drawable')
os.makedirs(draw_dir, exist_ok=True)
fg.save(os.path.join(draw_dir, 'ic_launcher_foreground.png'))
print('Icons generated')
"
```

Create adaptive icon XML at `res/mipmap-anydpi-v26/ic_launcher.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@drawable/ic_launcher_foreground"/>
</adaptive-icon>
```

Add `res/values/colors.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">#0f1117</color>
</resources>
```

- [ ] **Step 8: Commit**

```bash
git add leikwan-android/
git commit -m "feat: scaffold Android project with Gradle, manifest, and app icons"
```

---

## Task 3: Theme, Navigation, and Application Shell

**Files:**
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/LeikwanApp.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/theme/Theme.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/theme/Type.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/MainActivity.kt`

- [ ] **Step 1: Create Application class**

```kotlin
// LeikwanApp.kt
package com.leikwan.bridge

import android.app.Application

class LeikwanApp : Application()
```

- [ ] **Step 2: Create Theme.kt**

```kotlin
// ui/theme/Theme.kt
package com.leikwan.bridge.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val DarkColorScheme = darkColorScheme(
    primary = Color(0xFF4A6CF7),
    onPrimary = Color.White,
    primaryContainer = Color(0xFF3451C7),
    surface = Color(0xFF0F1117),
    surfaceVariant = Color(0xFF1A1D27),
    background = Color(0xFF0F1117),
    onBackground = Color(0xFFE0E0E0),
    onSurface = Color(0xFFE0E0E0),
    onSurfaceVariant = Color(0xFF9CA3AF),
    error = Color(0xFFEF4444),
    outline = Color(0xFF2A2D37),
    surfaceContainerHighest = Color(0xFF1E2130),
)

@Composable
fun LeikwanTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = DarkColorScheme,
        content = content,
    )
}
```

- [ ] **Step 3: Create Type.kt**

```kotlin
// ui/theme/Type.kt
package com.leikwan.bridge.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

val AppTypography = Typography(
    headlineMedium = TextStyle(fontWeight = FontWeight.Bold, fontSize = 24.sp),
    titleLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 20.sp),
    titleMedium = TextStyle(fontWeight = FontWeight.Medium, fontSize = 16.sp),
    bodyLarge = TextStyle(fontSize = 16.sp),
    bodyMedium = TextStyle(fontSize = 14.sp),
    labelMedium = TextStyle(fontWeight = FontWeight.Medium, fontSize = 12.sp),
)
```

- [ ] **Step 4: Create AppNavigation.kt**

```kotlin
// ui/nav/AppNavigation.kt
package com.leikwan.bridge.ui.nav

import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material.icons.filled.Lan
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.leikwan.bridge.ui.tunnels.TunnelsScreen
import com.leikwan.bridge.ui.proxies.ProxiesScreen
import com.leikwan.bridge.ui.settings.SettingsScreen

enum class Screen(val route: String, val label: String, val icon: ImageVector) {
    Tunnels("tunnels", "Tunnels", Icons.Default.Lan),
    Proxies("proxies", "Proxies", Icons.Default.Shield),
    Settings("settings", "Settings", Icons.Default.Settings),
}

@Composable
fun MainScreen() {
    val navController = rememberNavController()
    Scaffold(
        bottomBar = { BottomBar(navController) }
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = Screen.Tunnels.route,
            modifier = Modifier.padding(padding),
        ) {
            composable(Screen.Tunnels.route) { TunnelsScreen() }
            composable(Screen.Proxies.route) { ProxiesScreen() }
            composable(Screen.Settings.route) { SettingsScreen() }
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

- [ ] **Step 5: Create MainActivity.kt**

```kotlin
// ui/MainActivity.kt
package com.leikwan.bridge.ui

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.leikwan.bridge.ui.theme.LeikwanTheme
import com.leikwan.bridge.ui.nav.MainScreen

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            LeikwanTheme {
                MainScreen()
            }
        }
    }
}
```

- [ ] **Step 6: Create placeholder screens** (so the app compiles and runs)

`ui/tunnels/TunnelsScreen.kt`:
```kotlin
package com.leikwan.bridge.ui.tunnels

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier

@Composable
fun TunnelsScreen() {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Text("Tunnels", style = MaterialTheme.typography.headlineMedium)
    }
}
```

`ui/proxies/ProxiesScreen.kt`:
```kotlin
package com.leikwan.bridge.ui.proxies

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier

@Composable
fun ProxiesScreen() {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Text("Proxies", style = MaterialTheme.typography.headlineMedium)
    }
}
```

`ui/settings/SettingsScreen.kt`:
```kotlin
package com.leikwan.bridge.ui.settings

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier

@Composable
fun SettingsScreen() {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Text("Settings", style = MaterialTheme.typography.headlineMedium)
    }
}
```

- [ ] **Step 7: Build and verify**

```bash
cd leikwan-android
./gradlew assembleDebug
```

Expected: BUILD SUCCESSFUL, APK at `app/build/outputs/apk/debug/app-debug.apk`.

- [ ] **Step 8: Commit**

```bash
git add leikwan-android/
git commit -m "feat: add app shell with theme, navigation, and 3-tab scaffold"
```

---

## Task 4: Room Database and Entities

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/data/Entities.kt`
- Create: `app/src/main/java/com/leikwan/bridge/data/ProxyNodeDao.kt`
- Create: `app/src/main/java/com/leikwan/bridge/data/SubscriptionDao.kt`
- Create: `app/src/main/java/com/leikwan/bridge/data/AppDatabase.kt`
- Create: `app/src/main/java/com/leikwan/bridge/data/Prefs.kt`

- [ ] **Step 1: Create Entities.kt**

```kotlin
// data/Entities.kt
package com.leikwan.bridge.data

import androidx.room.*

@Entity(tableName = "proxy_nodes")
data class ProxyNode(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val name: String,
    val type: String, // "ss", "vless", "vmess"
    val originalServer: String,
    val originalPort: Int,
    val configJson: String, // full protocol-specific config as JSON
    val subscriptionId: Long? = null,
    val forwardId: Long? = null,
    val enabled: Boolean = true,
    val createdAt: Long = System.currentTimeMillis(),
)

@Entity(tableName = "subscriptions")
data class Subscription(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val name: String,
    val url: String,
    val lastUpdated: Long = 0,
    val autoRefresh: Boolean = true,
    val refreshIntervalMin: Int = 1440, // 24h
)
```

- [ ] **Step 2: Create ProxyNodeDao.kt**

```kotlin
// data/ProxyNodeDao.kt
package com.leikwan.bridge.data

import androidx.room.*
import kotlinx.coroutines.flow.Flow

@Dao
interface ProxyNodeDao {
    @Query("SELECT * FROM proxy_nodes ORDER BY createdAt DESC")
    fun getAll(): Flow<List<ProxyNode>>

    @Query("SELECT * FROM proxy_nodes WHERE enabled = 1 ORDER BY createdAt DESC")
    suspend fun getEnabled(): List<ProxyNode>

    @Query("SELECT * FROM proxy_nodes WHERE subscriptionId = :subId")
    suspend fun getBySubscription(subId: Long): List<ProxyNode>

    @Insert
    suspend fun insert(node: ProxyNode): Long

    @Insert
    suspend fun insertAll(nodes: List<ProxyNode>)

    @Update
    suspend fun update(node: ProxyNode)

    @Delete
    suspend fun delete(node: ProxyNode)

    @Query("DELETE FROM proxy_nodes WHERE subscriptionId = :subId")
    suspend fun deleteBySubscription(subId: Long)
}
```

- [ ] **Step 3: Create SubscriptionDao.kt**

```kotlin
// data/SubscriptionDao.kt
package com.leikwan.bridge.data

import androidx.room.*
import kotlinx.coroutines.flow.Flow

@Dao
interface SubscriptionDao {
    @Query("SELECT * FROM subscriptions ORDER BY name")
    fun getAll(): Flow<List<Subscription>>

    @Query("SELECT * FROM subscriptions WHERE id = :id")
    suspend fun getById(id: Long): Subscription?

    @Insert
    suspend fun insert(sub: Subscription): Long

    @Update
    suspend fun update(sub: Subscription)

    @Delete
    suspend fun delete(sub: Subscription)
}
```

- [ ] **Step 4: Create AppDatabase.kt**

```kotlin
// data/AppDatabase.kt
package com.leikwan.bridge.data

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

@Database(entities = [ProxyNode::class, Subscription::class], version = 1)
abstract class AppDatabase : RoomDatabase() {
    abstract fun proxyNodeDao(): ProxyNodeDao
    abstract fun subscriptionDao(): SubscriptionDao

    companion object {
        @Volatile private var INSTANCE: AppDatabase? = null

        fun get(context: Context): AppDatabase =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "leikwan_bridge.db"
                ).build().also { INSTANCE = it }
            }
    }
}
```

- [ ] **Step 5: Create Prefs.kt**

```kotlin
// data/Prefs.kt
package com.leikwan.bridge.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKeys

class Prefs private constructor(private val prefs: SharedPreferences) {

    var panelUrl: String
        get() = prefs.getString("panel_url", "https://v3-mirror.leikwanhost.com") ?: "https://v3-mirror.leikwanhost.com"
        set(v) = prefs.edit().putString("panel_url", v).apply()

    var token: String?
        get() = prefs.getString("token", null)
        set(v) = prefs.edit().putString("token", v).apply()

    var username: String?
        get() = prefs.getString("username", null)
        set(v) = prefs.edit().putString("username", v).apply()

    var email: String?
        get() = prefs.getString("email", null)
        set(v) = prefs.edit().putString("email", v).apply()

    var dnsServer: String
        get() = prefs.getString("dns_server", "1.1.1.1") ?: "1.1.1.1"
        set(v) = prefs.edit().putString("dns_server", v).apply()

    var dnsFallback: String
        get() = prefs.getString("dns_fallback", "8.8.8.8") ?: "8.8.8.8"
        set(v) = prefs.edit().putString("dns_fallback", v).apply()

    var dnsStrategy: String
        get() = prefs.getString("dns_strategy", "prefer_ipv4") ?: "prefer_ipv4"
        set(v) = prefs.edit().putString("dns_strategy", v).apply()

    var bypassLan: Boolean
        get() = prefs.getBoolean("bypass_lan", true)
        set(v) = prefs.edit().putBoolean("bypass_lan", v).apply()

    var bypassChina: Boolean
        get() = prefs.getBoolean("bypass_china", false)
        set(v) = prefs.edit().putBoolean("bypass_china", v).apply()

    var customBypassRules: String
        get() = prefs.getString("custom_bypass", "") ?: ""
        set(v) = prefs.edit().putString("custom_bypass", v).apply()

    fun isLoggedIn(): Boolean = token != null

    fun logout() {
        prefs.edit().remove("token").remove("username").remove("email").apply()
    }

    companion object {
        @Volatile private var INSTANCE: Prefs? = null

        fun get(context: Context): Prefs =
            INSTANCE ?: synchronized(this) {
                INSTANCE ?: Prefs(
                    EncryptedSharedPreferences.create(
                        "leikwan_secure_prefs",
                        MasterKeys.getOrCreate(MasterKeys.AES256_GCM_SPEC),
                        context.applicationContext,
                        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
                    )
                ).also { INSTANCE = it }
            }
    }
}
```

- [ ] **Step 6: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

Expected: BUILD SUCCESSFUL.

- [ ] **Step 7: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/data/
git commit -m "feat: add Room database, entities, DAOs, and encrypted prefs"
```

---

## Task 5: Panel API Client

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/api/PanelModels.kt`
- Create: `app/src/main/java/com/leikwan/bridge/api/PanelApi.kt`
- Create: `app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`

- [ ] **Step 1: Create PanelModels.kt**

```kotlin
// api/PanelModels.kt
package com.leikwan.bridge.api

import com.google.gson.JsonElement
import com.google.gson.annotations.SerializedName

data class LoginRequest(val email: String, val password: String)

data class LoginResponse(
    val ok: Boolean,
    val token: String,
    @SerializedName("user_id") val userId: Long,
    val username: String,
    @SerializedName("client_id") val clientId: Long,
    @SerializedName("client_name") val clientName: String,
    val clients: List<ClientInfo>,
)

data class ClientInfo(val id: Long, val name: String)

data class PullConfigsResponse(
    val ok: Boolean,
    val client: JsonElement,
    val forwards: List<JsonElement>,
)

data class HeartbeatResponse(val ok: Boolean)

data class ForwardInfo(
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("service_name") val serviceName: String = "",
    val target: String = "",
    @SerializedName("listen_port") val listenPort: Int = 0,
    val mode: String = "",
    val transport: String = "",
    @SerializedName("service_status") val serviceStatus: String = "",
    @SerializedName("endpoint_mode") val endpointMode: String = "",
    val endpoints: List<JsonElement> = emptyList(),
    @SerializedName("speed_limit_mbps") val speedLimitMbps: Int? = null,
)
```

- [ ] **Step 2: Create PanelApi.kt**

```kotlin
// api/PanelApi.kt
package com.leikwan.bridge.api

import com.google.gson.JsonElement
import retrofit2.http.*

interface PanelApi {
    @POST("api/client/login")
    suspend fun login(@Body request: LoginRequest): LoginResponse

    @POST("api/client/heartbeat")
    suspend fun heartbeat(@Header("Authorization") auth: String): HeartbeatResponse

    @GET("api/client/configs/pull")
    suspend fun pullConfigs(@Header("Authorization") auth: String): PullConfigsResponse

    @GET("api/client/config/{forwardId}")
    suspend fun getForwardConfig(
        @Header("Authorization") auth: String,
        @Path("forwardId") forwardId: Long,
    ): JsonElement
}
```

- [ ] **Step 3: Create PanelRepository.kt**

```kotlin
// api/PanelRepository.kt
package com.leikwan.bridge.api

import android.content.Context
import com.google.gson.Gson
import com.google.gson.JsonElement
import com.leikwan.bridge.data.Prefs
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

class PanelRepository(context: Context) {
    private val prefs = Prefs.get(context)
    private val gson = Gson()

    private var api: PanelApi = buildApi(prefs.panelUrl)
    private var cachedForwardConfigs = mutableMapOf<Long, JsonElement>()

    private fun buildApi(baseUrl: String): PanelApi {
        val url = baseUrl.trimEnd('/') + "/"
        val client = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .build()
        return Retrofit.Builder()
            .baseUrl(url)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(PanelApi::class.java)
    }

    private fun authHeader(): String = "Bearer ${prefs.token}"

    suspend fun login(panelUrl: String, email: String, password: String): LoginResponse {
        prefs.panelUrl = panelUrl
        api = buildApi(panelUrl)
        val resp = api.login(LoginRequest(email, password))
        if (!resp.ok) throw Exception("Login failed")
        prefs.token = resp.token
        prefs.username = resp.username
        prefs.email = email
        return resp
    }

    suspend fun pullConfigs(): List<ForwardInfo> {
        val resp = api.pullConfigs(authHeader())
        if (!resp.ok) throw Exception("Pull configs failed")
        cachedForwardConfigs.clear()
        val forwards = mutableListOf<ForwardInfo>()
        for (raw in resp.forwards) {
            try {
                val info = gson.fromJson(raw, ForwardInfo::class.java)
                forwards.add(info)
                val id = raw.asJsonObject?.get("forward_id")?.asLong
                if (id != null) cachedForwardConfigs[id] = raw
            } catch (e: Exception) {
                // skip unparseable forwards
            }
        }
        return forwards
    }

    fun getCachedConfig(forwardId: Long): JsonElement? = cachedForwardConfigs[forwardId]

    suspend fun heartbeat(): Boolean {
        return try {
            api.heartbeat(authHeader()).ok
        } catch (e: Exception) {
            false
        }
    }

    fun isLoggedIn(): Boolean = prefs.isLoggedIn()

    fun logout() {
        prefs.logout()
        cachedForwardConfigs.clear()
    }
}
```

- [ ] **Step 4: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 5: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/api/
git commit -m "feat: add Panel API client with Retrofit (login, pull configs, heartbeat)"
```

---

## Task 6: Bond Process Manager

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/bond/BondManager.kt`

- [ ] **Step 1: Create BondManager.kt**

```kotlin
// bond/BondManager.kt
package com.leikwan.bridge.bond

import android.content.Context
import android.util.Log
import com.google.gson.JsonElement

private const val TAG = "BondManager"

data class BondParams(
    val chunkSize: Long = 131072,
    val maxWindowBytes: Long = 16777216,
    val retransmitMs: Long = 300,
    val connectTimeoutMs: Long = 3000,
    val activeLegLimit: Long = 0,
    val interactiveChunkBytes: Long = 16384,
    val latencyGuardBytes: Long = 262144,
    val interactiveRttSlackMs: Long = 30,
)

class BondManager(private val context: Context) {
    private val processes = mutableMapOf<Long, Process>()

    private fun bondBinaryPath(): String {
        return "${context.applicationInfo.nativeLibraryDir}/libleikwan_bond.so"
    }

    fun startBond(
        forwardId: Long,
        protocol: String,
        listen: String,
        legs: List<String>,
        sessionToken: String,
        params: BondParams = BondParams(),
    ) {
        if (processes.containsKey(forwardId)) {
            Log.w(TAG, "Bond already running for forward $forwardId")
            return
        }

        val cmd = mutableListOf(
            bondBinaryPath(), "client",
            "--protocol", protocol,
            "--listen", listen,
            "--legs", legs.joinToString(","),
            "--session-token", sessionToken,
            "--chunk-size", params.chunkSize.toString(),
            "--max-window-bytes", params.maxWindowBytes.toString(),
            "--retransmit-ms", params.retransmitMs.toString(),
            "--connect-timeout-ms", params.connectTimeoutMs.toString(),
            "--interactive-chunk-bytes", params.interactiveChunkBytes.toString(),
            "--latency-guard-bytes", params.latencyGuardBytes.toString(),
            "--interactive-rtt-slack-ms", params.interactiveRttSlackMs.toString(),
        )
        if (params.activeLegLimit > 0) {
            cmd += listOf("--active-leg-limit", params.activeLegLimit.toString())
        }

        val pb = ProcessBuilder(cmd)
            .redirectErrorStream(true)
        val process = pb.start()
        processes[forwardId] = process
        Log.i(TAG, "Bond started for forward $forwardId, listen=$listen, legs=${legs.size}")

        // Log output in background thread
        Thread {
            process.inputStream.bufferedReader().use { reader ->
                reader.forEachLine { line -> Log.d(TAG, "bond[$forwardId]: $line") }
            }
        }.start()
    }

    fun stopBond(forwardId: Long) {
        processes.remove(forwardId)?.let { proc ->
            proc.destroy()
            proc.waitFor()
            Log.i(TAG, "Bond stopped for forward $forwardId")
        }
    }

    fun stopAll() {
        processes.forEach { (id, proc) ->
            proc.destroy()
            proc.waitFor()
            Log.i(TAG, "Bond stopped for forward $id")
        }
        processes.clear()
    }

    fun isRunning(forwardId: Long): Boolean = processes.containsKey(forwardId)

    companion object {
        fun parseBondParams(bondingConfig: JsonElement?): BondParams {
            val obj = bondingConfig?.takeIf { it.isJsonObject }?.asJsonObject ?: return BondParams()
            return BondParams(
                chunkSize = obj.get("chunk_size")?.asLong ?: 131072,
                maxWindowBytes = obj.get("max_window_bytes")?.asLong ?: 16777216,
                retransmitMs = obj.get("retransmit_ms")?.asLong ?: 300,
                connectTimeoutMs = obj.get("connect_timeout_ms")?.asLong ?: 3000,
                activeLegLimit = obj.get("active_leg_limit")?.asLong ?: 0,
                interactiveChunkBytes = obj.get("interactive_chunk_bytes")?.asLong ?: 16384,
                latencyGuardBytes = obj.get("latency_guard_bytes")?.asLong ?: 262144,
                interactiveRttSlackMs = obj.get("interactive_rtt_slack_ms")?.asLong ?: 30,
            )
        }
    }
}
```

- [ ] **Step 2: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 3: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/bond/
git commit -m "feat: add BondManager to spawn leikwan-bond subprocess per forward"
```

---

## Task 7: Proxy URI Parser

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/proxy/ProxyUriParser.kt`

- [ ] **Step 1: Create ProxyUriParser.kt**

```kotlin
// proxy/ProxyUriParser.kt
package com.leikwan.bridge.proxy

import android.util.Base64
import com.google.gson.Gson
import com.google.gson.JsonObject
import com.leikwan.bridge.data.ProxyNode
import java.net.URI

object ProxyUriParser {
    private val gson = Gson()

    fun parse(uri: String): ProxyNode? {
        val trimmed = uri.trim()
        return when {
            trimmed.startsWith("ss://") -> parseShadowsocks(trimmed)
            trimmed.startsWith("vmess://") -> parseVmess(trimmed)
            trimmed.startsWith("vless://") -> parseVless(trimmed)
            else -> null
        }
    }

    fun parseMultiple(text: String): List<ProxyNode> {
        // Handle base64-encoded subscription content
        val decoded = try {
            String(Base64.decode(text.trim(), Base64.DEFAULT))
        } catch (e: Exception) {
            text
        }
        return decoded.lines()
            .map { it.trim() }
            .filter { it.isNotEmpty() }
            .mapNotNull { parse(it) }
    }

    private fun parseShadowsocks(uri: String): ProxyNode? {
        // ss://base64(method:password)@host:port#name
        // or ss://base64(method:password@host:port)#name
        return try {
            val withoutScheme = uri.removePrefix("ss://")
            val name = withoutScheme.substringAfter("#", "").let {
                java.net.URLDecoder.decode(it, "UTF-8").ifEmpty { null }
            }
            val mainPart = withoutScheme.substringBefore("#")

            var server: String
            var port: Int
            var method: String
            var password: String

            if (mainPart.contains("@")) {
                // SIP002 format: base64(method:password)@host:port
                val userInfo = mainPart.substringBefore("@")
                val hostPort = mainPart.substringAfter("@")
                val decoded = String(Base64.decode(userInfo, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP))
                method = decoded.substringBefore(":")
                password = decoded.substringAfter(":")
                server = hostPort.substringBeforeLast(":")
                port = hostPort.substringAfterLast(":").toInt()
            } else {
                // Legacy format: base64(method:password@host:port)
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
        } catch (e: Exception) {
            null
        }
    }

    private fun parseVmess(uri: String): ProxyNode? {
        // vmess://base64(json)
        return try {
            val encoded = uri.removePrefix("vmess://")
            val json = String(Base64.decode(encoded, Base64.DEFAULT))
            val obj = gson.fromJson(json, JsonObject::class.java)

            val server = obj.get("add")?.asString ?: return null
            val port = obj.get("port")?.asInt
                ?: obj.get("port")?.asString?.toIntOrNull()
                ?: return null
            val name = obj.get("ps")?.asString ?: "$server:$port"

            ProxyNode(
                name = name,
                type = "vmess",
                originalServer = server,
                originalPort = port,
                configJson = json,
            )
        } catch (e: Exception) {
            null
        }
    }

    private fun parseVless(uri: String): ProxyNode? {
        // vless://uuid@host:port?params#name
        return try {
            val parsed = URI(uri)
            val uuid = parsed.userInfo ?: return null
            val server = parsed.host ?: return null
            val port = parsed.port.takeIf { it > 0 } ?: return null
            val name = parsed.fragment?.let {
                java.net.URLDecoder.decode(it, "UTF-8")
            } ?: "$server:$port"

            val params = mutableMapOf<String, String>()
            parsed.query?.split("&")?.forEach { pair ->
                val (k, v) = pair.split("=", limit = 2)
                params[k] = java.net.URLDecoder.decode(v, "UTF-8")
            }

            val config = JsonObject().apply {
                addProperty("uuid", uuid)
                params.forEach { (k, v) -> addProperty(k, v) }
            }

            ProxyNode(
                name = name,
                type = "vless",
                originalServer = server,
                originalPort = port,
                configJson = gson.toJson(config),
            )
        } catch (e: Exception) {
            null
        }
    }
}
```

- [ ] **Step 2: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 3: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/
git commit -m "feat: add proxy URI parser for ss://, vmess://, vless:// protocols"
```

---

## Task 8: Subscription Fetcher

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/proxy/SubscriptionFetcher.kt`

- [ ] **Step 1: Create SubscriptionFetcher.kt**

```kotlin
// proxy/SubscriptionFetcher.kt
package com.leikwan.bridge.proxy

import com.leikwan.bridge.data.ProxyNode
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

object SubscriptionFetcher {
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .followRedirects(true)
        .build()

    suspend fun fetch(url: String): List<ProxyNode> = withContext(Dispatchers.IO) {
        val request = Request.Builder()
            .url(url)
            .header("User-Agent", "LeiKwanBridge/0.1.0")
            .build()
        val response = client.newCall(request).execute()
        val body = response.body?.string() ?: throw Exception("Empty response from subscription")
        if (!response.isSuccessful) throw Exception("Subscription fetch failed: ${response.code}")
        ProxyUriParser.parseMultiple(body)
    }
}
```

- [ ] **Step 2: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 3: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/proxy/SubscriptionFetcher.kt
git commit -m "feat: add subscription URL fetcher with base64 decoding support"
```

---

## Task 9: sing-box Config Builder

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`

- [ ] **Step 1: Create SingboxConfigBuilder.kt**

```kotlin
// singbox/SingboxConfigBuilder.kt
package com.leikwan.bridge.singbox

import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode

class SingboxConfigBuilder(private val prefs: Prefs) {
    private val gson = Gson()

    fun build(
        proxyNodes: List<ProxyNode>,
        forwardListenPorts: Map<Long, Int>, // forwardId -> listenPort
        slaveIps: Set<String>,
    ): String {
        val root = JsonObject()

        // DNS
        root.add("dns", buildDns(slaveIps))

        // Inbounds
        root.add("inbounds", buildInbounds())

        // Outbounds
        root.add("outbounds", buildOutbounds(proxyNodes, forwardListenPorts))

        // Route
        root.add("route", buildRoute(slaveIps))

        return gson.toJson(root)
    }

    private fun buildDns(slaveIps: Set<String>): JsonObject {
        val dns = JsonObject()

        val servers = JsonArray()

        val remote = JsonObject().apply {
            addProperty("tag", "remote")
            addProperty("address", prefs.dnsServer)
            addProperty("detour", "proxy")
        }
        servers.add(remote)

        val direct = JsonObject().apply {
            addProperty("tag", "direct-dns")
            addProperty("address", prefs.dnsFallback)
            addProperty("detour", "direct")
        }
        servers.add(direct)

        dns.add("servers", servers)

        val rules = JsonArray()
        // Bypass domains use direct DNS
        val bypassDnsRule = JsonObject().apply {
            val domains = JsonArray()
            domains.add("v3-mirror.leikwanhost.com")
            add("domain", domains)
            addProperty("server", "direct-dns")
        }
        rules.add(bypassDnsRule)

        if (prefs.bypassChina) {
            val chinaDnsRule = JsonObject().apply {
                val ruleSet = JsonArray()
                ruleSet.add("geosite-cn")
                add("rule_set", ruleSet)
                addProperty("server", "direct-dns")
            }
            rules.add(chinaDnsRule)
        }

        dns.add("rules", rules)
        dns.addProperty("final", "remote")
        dns.addProperty("strategy", prefs.dnsStrategy)

        return dns
    }

    private fun buildInbounds(): JsonArray {
        val inbounds = JsonArray()
        val tun = JsonObject().apply {
            addProperty("type", "tun")
            addProperty("tag", "tun-in")
            addProperty("inet4_address", "172.19.0.1/30")
            addProperty("auto_route", true)
            addProperty("strict_route", true)
            addProperty("stack", "system")
        }
        inbounds.add(tun)
        return inbounds
    }

    private fun buildOutbounds(
        proxyNodes: List<ProxyNode>,
        forwardListenPorts: Map<Long, Int>,
    ): JsonArray {
        val outbounds = JsonArray()

        // Add proxy outbounds for each enabled node
        for (node in proxyNodes) {
            val outbound = buildProxyOutbound(node, forwardListenPorts) ?: continue
            outbounds.add(outbound)
        }

        // If we have proxy outbounds, the first one is the default "proxy"
        // Add a selector if multiple nodes
        if (proxyNodes.isNotEmpty()) {
            val selector = JsonObject().apply {
                addProperty("type", "selector")
                addProperty("tag", "proxy")
                val outboundTags = JsonArray()
                for (node in proxyNodes) {
                    outboundTags.add("proxy-${node.id}")
                }
                add("outbounds", outboundTags)
                if (proxyNodes.isNotEmpty()) {
                    addProperty("default", "proxy-${proxyNodes.first().id}")
                }
            }
            outbounds.add(selector)
        }

        // Direct and block
        outbounds.add(JsonObject().apply {
            addProperty("type", "direct")
            addProperty("tag", "direct")
        })
        outbounds.add(JsonObject().apply {
            addProperty("type", "block")
            addProperty("tag", "block")
        })
        outbounds.add(JsonObject().apply {
            addProperty("type", "dns")
            addProperty("tag", "dns-out")
        })

        return outbounds
    }

    private fun buildProxyOutbound(
        node: ProxyNode,
        forwardListenPorts: Map<Long, Int>,
    ): JsonObject? {
        // Determine the local listen port to use
        val listenPort = if (node.forwardId != null) {
            forwardListenPorts[node.forwardId]
        } else {
            // Use the first available forward
            forwardListenPorts.values.firstOrNull()
        } ?: return null

        val config = gson.fromJson(node.configJson, JsonObject::class.java)

        return when (node.type) {
            "ss" -> JsonObject().apply {
                addProperty("type", "shadowsocks")
                addProperty("tag", "proxy-${node.id}")
                addProperty("server", "127.0.0.1")
                addProperty("server_port", listenPort)
                addProperty("method", config.get("method")?.asString ?: "")
                addProperty("password", config.get("password")?.asString ?: "")
            }
            "vmess" -> JsonObject().apply {
                addProperty("type", "vmess")
                addProperty("tag", "proxy-${node.id}")
                addProperty("server", "127.0.0.1")
                addProperty("server_port", listenPort)
                addProperty("uuid", config.get("id")?.asString ?: "")
                addProperty("alter_id", config.get("aid")?.asInt ?: 0)
                addProperty("security", config.get("scy")?.asString ?: "auto")
                // Transport
                val net = config.get("net")?.asString ?: "tcp"
                if (net != "tcp") {
                    val transport = JsonObject().apply {
                        addProperty("type", net)
                        if (net == "ws") {
                            addProperty("path", config.get("path")?.asString ?: "/")
                            val headers = JsonObject()
                            config.get("host")?.asString?.let { headers.addProperty("Host", it) }
                            add("headers", headers)
                        }
                    }
                    add("transport", transport)
                }
            }
            "vless" -> JsonObject().apply {
                addProperty("type", "vless")
                addProperty("tag", "proxy-${node.id}")
                addProperty("server", "127.0.0.1")
                addProperty("server_port", listenPort)
                addProperty("uuid", config.get("uuid")?.asString ?: "")
                // Flow
                config.get("flow")?.asString?.let { addProperty("flow", it) }
                // Transport
                val tType = config.get("type")?.asString ?: "tcp"
                if (tType != "tcp" && tType != "none") {
                    val transport = JsonObject().apply {
                        addProperty("type", tType)
                        if (tType == "ws") {
                            addProperty("path", config.get("path")?.asString ?: "/")
                            val headers = JsonObject()
                            config.get("host")?.asString?.let { headers.addProperty("Host", it) }
                            add("headers", headers)
                        }
                        if (tType == "grpc") {
                            addProperty("service_name", config.get("serviceName")?.asString ?: "")
                        }
                    }
                    add("transport", transport)
                }
                // TLS
                val security = config.get("security")?.asString ?: ""
                if (security == "tls" || security == "reality") {
                    val tls = JsonObject().apply {
                        addProperty("enabled", true)
                        config.get("sni")?.asString?.let { addProperty("server_name", it) }
                        if (security == "reality") {
                            val reality = JsonObject().apply {
                                addProperty("enabled", true)
                                config.get("pbk")?.asString?.let { addProperty("public_key", it) }
                                config.get("sid")?.asString?.let { addProperty("short_id", it) }
                            }
                            add("reality", reality)
                        }
                    }
                    add("tls", tls)
                }
            }
            else -> null
        }
    }

    private fun buildRoute(slaveIps: Set<String>): JsonObject {
        val route = JsonObject()

        val rules = JsonArray()

        // DNS hijack
        rules.add(JsonObject().apply {
            addProperty("protocol", "dns")
            addProperty("outbound", "dns-out")
        })

        // Always bypass: slave IPs
        if (slaveIps.isNotEmpty()) {
            rules.add(JsonObject().apply {
                val cidrs = JsonArray()
                slaveIps.forEach { cidrs.add("$it/32") }
                add("ip_cidr", cidrs)
                addProperty("outbound", "direct")
            })
        }

        // Always bypass: panel mirror
        rules.add(JsonObject().apply {
            val domains = JsonArray()
            domains.add("v3-mirror.leikwanhost.com")
            add("domain", domains)
            addProperty("outbound", "direct")
        })

        // Conditional: bypass LAN
        if (prefs.bypassLan) {
            rules.add(JsonObject().apply {
                val cidrs = JsonArray()
                cidrs.add("10.0.0.0/8")
                cidrs.add("172.16.0.0/12")
                cidrs.add("192.168.0.0/16")
                cidrs.add("127.0.0.0/8")
                add("ip_cidr", cidrs)
                addProperty("outbound", "direct")
            })
        }

        // Conditional: bypass China
        if (prefs.bypassChina) {
            rules.add(JsonObject().apply {
                val ruleSet = JsonArray()
                ruleSet.add("geoip-cn")
                ruleSet.add("geosite-cn")
                add("rule_set", ruleSet)
                addProperty("outbound", "direct")
            })
        }

        // Custom bypass rules
        val custom = prefs.customBypassRules.trim()
        if (custom.isNotEmpty()) {
            val ips = JsonArray()
            val domains = JsonArray()
            custom.lines().map { it.trim() }.filter { it.isNotEmpty() }.forEach { line ->
                if (line.matches(Regex("^[0-9./:]+"))) {
                    ips.add(if ("/" in line) line else "$line/32")
                } else {
                    domains.add(line)
                }
            }
            if (ips.size() > 0) {
                rules.add(JsonObject().apply { add("ip_cidr", ips); addProperty("outbound", "direct") })
            }
            if (domains.size() > 0) {
                rules.add(JsonObject().apply { add("domain", domains); addProperty("outbound", "direct") })
            }
        }

        route.add("rules", rules)
        route.addProperty("final", "proxy")
        route.addProperty("auto_detect_interface", true)

        // Rule sets for China bypass
        if (prefs.bypassChina) {
            val ruleSets = JsonArray()
            ruleSets.add(JsonObject().apply {
                addProperty("tag", "geoip-cn")
                addProperty("type", "remote")
                addProperty("format", "binary")
                addProperty("url", "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs")
            })
            ruleSets.add(JsonObject().apply {
                addProperty("tag", "geosite-cn")
                addProperty("type", "remote")
                addProperty("format", "binary")
                addProperty("url", "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs")
            })
            route.add("rule_set", ruleSets)
        }

        return route
    }
}
```

- [ ] **Step 2: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 3: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/
git commit -m "feat: add sing-box config builder with DNS, bypass rules, and server replacement"
```

---

## Task 10: VPN Service and sing-box Integration

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt`
- Create: `app/src/main/java/com/leikwan/bridge/service/BoxServiceManager.kt`
- Create: `app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`

- [ ] **Step 1: Create LeikwanVpnService.kt**

This implements the Android VpnService and the libbox PlatformInterface. The exact interface methods depend on the libbox.aar API surface -- the implementation follows the SFA (sing-box for Android) pattern.

```kotlin
// service/LeikwanVpnService.kt
package com.leikwan.bridge.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.net.VpnService
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import com.leikwan.bridge.R
import com.leikwan.bridge.ui.MainActivity
import io.nekohasekai.libbox.CommandServer
import io.nekohasekai.libbox.CommandServerHandler
import io.nekohasekai.libbox.Libbox
import io.nekohasekai.libbox.PlatformInterface
import io.nekohasekai.libbox.TunOptions

private const val TAG = "LeikwanVpnService"
private const val CHANNEL_ID = "leikwan_vpn"
private const val NOTIFICATION_ID = 1

class LeikwanVpnService : VpnService(), PlatformInterface, CommandServerHandler {

    private var commandServer: CommandServer? = null

    companion object {
        var instance: LeikwanVpnService? = null
            private set
        var isRunning: Boolean = false
            private set
        var configJson: String? = null
    }

    override fun onCreate() {
        super.onCreate()
        instance = this
        createNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == "STOP") {
            stopVpn()
            return START_NOT_STICKY
        }

        val config = configJson
        if (config == null) {
            Log.e(TAG, "No config provided")
            stopSelf()
            return START_NOT_STICKY
        }

        startForeground(NOTIFICATION_ID, buildNotification("Connecting..."))

        try {
            val basePath = filesDir.absolutePath
            Libbox.setup(io.nekohasekai.libbox.SetupOptions().apply {
                this.basePath = basePath
                this.workingPath = "$basePath/working"
                this.tempPath = "$basePath/temp"
            })

            commandServer = CommandServer(this, this).also { server ->
                server.start()
                server.startOrReloadService(config, null)
            }

            isRunning = true
            updateNotification("Connected")
            Log.i(TAG, "VPN service started")
        } catch (e: Exception) {
            Log.e(TAG, "Failed to start VPN", e)
            stopVpn()
        }

        return START_STICKY
    }

    fun stopVpn() {
        try {
            commandServer?.apply {
                closeService()
                close()
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error stopping service", e)
        }
        commandServer = null
        isRunning = false
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        instance = null
        isRunning = false
        super.onDestroy()
    }

    override fun onRevoke() {
        stopVpn()
    }

    // --- PlatformInterface ---

    override fun openTun(options: TunOptions): Int {
        val builder = Builder()
            .setSession("LeiKwan Bridge")
            .setMtu(options.mtu)

        // Add addresses
        val inet4Iter = options.inet4Address
        while (inet4Iter.hasNext()) {
            val prefix = inet4Iter.next()
            builder.addAddress(prefix.address, prefix.prefix)
        }

        // Add routes
        val routeIter = options.inet4RouteAddress
        while (routeIter.hasNext()) {
            val prefix = routeIter.next()
            builder.addRoute(prefix.address, prefix.prefix)
        }

        // Add DNS
        options.dnsServerAddress?.let { dns ->
            val dnsIter = dns.iterator
            while (dnsIter.hasNext()) {
                builder.addDnsServer(dnsIter.next())
            }
        }

        // Exclude bypass apps if needed (none for now)

        val pfd = builder.establish() ?: throw Exception("VPN permission not granted")
        return pfd.fd
    }

    override fun autoDetectInterfaceControl(fd: Int) {
        protect(fd)
    }

    override fun usePlatformAutoDetectInterfaceControl(): Boolean = true
    override fun useProcFS(): Boolean = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q
    override fun underNetworkExtension(): Boolean = false
    override fun includeAllNetworks(): Boolean = false
    override fun clearDNSCache() {}

    // --- CommandServerHandler ---

    override fun serviceStop() { stopVpn() }
    override fun serviceReload() {}
    override fun getSystemProxyStatus() = null
    override fun setSystemProxyEnabled(enabled: Boolean) {}
    override fun writeDebugMessage(message: String?) { Log.d(TAG, message ?: "") }

    // --- Notification ---

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID, "VPN Service", NotificationManager.IMPORTANCE_LOW
            ).apply { description = "LeiKwan Bridge VPN status" }
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }

    private fun buildNotification(status: String): Notification {
        val intent = Intent(this, MainActivity::class.java)
        val pending = PendingIntent.getActivity(this, 0, intent, PendingIntent.FLAG_IMMUTABLE)

        val stopIntent = Intent(this, LeikwanVpnService::class.java).apply { action = "STOP" }
        val stopPending = PendingIntent.getService(this, 1, stopIntent, PendingIntent.FLAG_IMMUTABLE)

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("LeiKwan Bridge")
            .setContentText(status)
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setContentIntent(pending)
            .addAction(android.R.drawable.ic_media_pause, "Disconnect", stopPending)
            .setOngoing(true)
            .build()
    }

    private fun updateNotification(status: String) {
        getSystemService(NotificationManager::class.java)
            .notify(NOTIFICATION_ID, buildNotification(status))
    }
}
```

**Note:** The exact PlatformInterface methods depend on the libbox.aar version. Some methods (like `localDNSTransport`, `findConnectionOwner`, `startDefaultInterfaceMonitor`, `getInterfaces`, `readWIFIState`, `systemCertificates`, `sendNotification`, `startNeighborMonitor`, `closeNeighborMonitor`, `registerMyInterface`, `closeDefaultInterfaceMonitor`) will need stub implementations. Add them as needed when the compiler reports missing overrides — they can return null/default values or throw `UnsupportedOperationException` for features we don't use.

- [ ] **Step 2: Create TunnelOrchestrator.kt**

```kotlin
// service/TunnelOrchestrator.kt
package com.leikwan.bridge.service

import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.util.Log
import com.google.gson.JsonElement
import com.leikwan.bridge.api.ForwardInfo
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.bond.BondManager
import com.leikwan.bridge.bond.BondParams
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.singbox.SingboxConfigBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext

private const val TAG = "TunnelOrchestrator"

class TunnelOrchestrator(private val context: Context) {
    private val panelRepo = PanelRepository(context)
    private val bondManager = BondManager(context)
    private val prefs = Prefs.get(context)
    private val db = AppDatabase.get(context)
    private val configBuilder = SingboxConfigBuilder(prefs)

    private var forwards = listOf<ForwardInfo>()
    private var slaveIps = mutableSetOf<String>()

    suspend fun start() = withContext(Dispatchers.IO) {
        // 1. Pull configs
        Log.i(TAG, "Pulling forward configs...")
        forwards = panelRepo.pullConfigs()
        Log.i(TAG, "Got ${forwards.size} forwards")

        // 2. Start bond processes per forward
        for (fwd in forwards) {
            startBondForForward(fwd)
        }

        // 3. Wait for bond listeners to be ready
        delay(1000)

        // 4. Generate sing-box config
        val proxyNodes = db.proxyNodeDao().getEnabled()
        val forwardPorts = forwards.associate { it.forwardId to it.listenPort }
        val configJson = configBuilder.build(proxyNodes, forwardPorts, slaveIps)
        Log.d(TAG, "Generated sing-box config: ${configJson.take(500)}...")

        // 5. Start VPN service
        LeikwanVpnService.configJson = configJson
        val intent = Intent(context, LeikwanVpnService::class.java)
        context.startForegroundService(intent)
    }

    private fun startBondForForward(fwd: ForwardInfo) {
        val config = panelRepo.getCachedConfig(fwd.forwardId) ?: return

        val obj = config.asJsonObject
        val endpoints = obj.getAsJsonArray("endpoints") ?: return
        val listenPort = obj.get("listen_port")?.asInt ?: return
        val protocol = obj.get("target_protocol")?.asString
            ?: obj.get("transport")?.asString ?: "tcp"

        val legAddresses = mutableListOf<String>()
        for (ep in endpoints) {
            val epObj = ep.asJsonObject

            // Collect slave IPs for bypass
            epObj.get("host")?.asString?.let { slaveIps.add(it) }

            // Collect bond leg addresses
            val bondLeg = epObj.get("bond_leg")?.asString
            if (bondLeg != null) {
                legAddresses.add(bondLeg)
            } else {
                val relayHost = epObj.get("relay_host")?.asString
                val relayPort = epObj.get("relay_port")?.asInt
                if (relayHost != null && relayPort != null) {
                    legAddresses.add("$relayHost:$relayPort")
                    slaveIps.add(relayHost)
                }
            }
        }

        if (legAddresses.isEmpty()) {
            Log.w(TAG, "No bond legs for forward ${fwd.forwardId}")
            return
        }

        val bondParams = BondManager.parseBondParams(obj.get("bonding"))
        val sessionToken = obj.get("session_token")?.asString
            ?: obj.get("bonding")?.asJsonObject?.get("session_token")?.asString
            ?: ""

        bondManager.startBond(
            forwardId = fwd.forwardId,
            protocol = protocol,
            listen = "127.0.0.1:$listenPort",
            legs = legAddresses,
            sessionToken = sessionToken,
            params = bondParams,
        )
    }

    fun stop() {
        // Reverse order: VPN -> bonds
        LeikwanVpnService.instance?.stopVpn()
        bondManager.stopAll()
        slaveIps.clear()
        forwards = emptyList()
    }

    fun isRunning(): Boolean = LeikwanVpnService.isRunning
}
```

- [ ] **Step 3: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

**Note:** This step will likely have compilation errors related to the exact libbox.aar API surface (missing interface methods). Fix by adding stub implementations for any unimplemented PlatformInterface or CommandServerHandler methods that the compiler reports.

- [ ] **Step 4: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/service/
git commit -m "feat: add VPN service, sing-box integration, and tunnel orchestrator"
```

---

## Task 11: Login Screen

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/ui/login/LoginViewModel.kt`
- Modify: `app/src/main/java/com/leikwan/bridge/ui/login/LoginScreen.kt` (replace placeholder)
- Modify: `app/src/main/java/com/leikwan/bridge/ui/MainActivity.kt`

- [ ] **Step 1: Create LoginViewModel.kt**

```kotlin
// ui/login/LoginViewModel.kt
package com.leikwan.bridge.ui.login

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class LoginUiState(
    val isLoading: Boolean = false,
    val error: String? = null,
    val isLoggedIn: Boolean = false,
)

class LoginViewModel(app: Application) : AndroidViewModel(app) {
    private val repo = PanelRepository(app)
    private val prefs = Prefs.get(app)

    private val _state = MutableStateFlow(LoginUiState(isLoggedIn = prefs.isLoggedIn()))
    val state = _state.asStateFlow()

    fun login(panelUrl: String, email: String, password: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                repo.login(panelUrl, email, password)
                _state.value = _state.value.copy(isLoading = false, isLoggedIn = true)
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message ?: "Login failed")
            }
        }
    }
}
```

- [ ] **Step 2: Replace LoginScreen.kt**

```kotlin
// ui/login/LoginScreen.kt
package com.leikwan.bridge.ui.login

import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.R

@Composable
fun LoginScreen(
    onLoginSuccess: () -> Unit,
    viewModel: LoginViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()

    LaunchedEffect(state.isLoggedIn) {
        if (state.isLoggedIn) onLoginSuccess()
    }

    var panelUrl by remember { mutableStateOf("https://v3-mirror.leikwanhost.com") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    Box(
        modifier = Modifier.fillMaxSize(),
        contentAlignment = Alignment.Center,
    ) {
        Card(
            modifier = Modifier
                .widthIn(max = 400.dp)
                .padding(24.dp),
        ) {
            Column(
                modifier = Modifier.padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                // Logo
                Image(
                    painter = painterResource(R.mipmap.ic_launcher),
                    contentDescription = "LeiKwan Bridge",
                    modifier = Modifier.size(80.dp),
                )
                Spacer(Modifier.height(8.dp))
                Text("LeiKwan Bridge", style = MaterialTheme.typography.headlineMedium)
                Text(
                    "Connect to your network",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(24.dp))

                // Panel URL
                OutlinedTextField(
                    value = panelUrl,
                    onValueChange = { panelUrl = it },
                    label = { Text("Panel URL") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                Spacer(Modifier.height(12.dp))

                // Email
                OutlinedTextField(
                    value = email,
                    onValueChange = { email = it },
                    label = { Text("Email") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                )
                Spacer(Modifier.height(12.dp))

                // Password
                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text("Password") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                )
                Spacer(Modifier.height(16.dp))

                // Error
                state.error?.let { err ->
                    Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                    Spacer(Modifier.height(8.dp))
                }

                // Sign In button
                Button(
                    onClick = { viewModel.login(panelUrl, email, password) },
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !state.isLoading && email.isNotBlank() && password.isNotBlank(),
                ) {
                    if (state.isLoading) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(20.dp),
                            strokeWidth = 2.dp,
                            color = MaterialTheme.colorScheme.onPrimary,
                        )
                        Spacer(Modifier.width(8.dp))
                    }
                    Text(if (state.isLoading) "Signing in..." else "Sign In")
                }
            }
        }
    }
}
```

- [ ] **Step 3: Update MainActivity.kt** to show login or main screen

```kotlin
// ui/MainActivity.kt
package com.leikwan.bridge.ui

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.*
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.ui.login.LoginScreen
import com.leikwan.bridge.ui.nav.MainScreen
import com.leikwan.bridge.ui.theme.LeikwanTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            LeikwanTheme {
                var isLoggedIn by remember { mutableStateOf(Prefs.get(this).isLoggedIn()) }
                if (isLoggedIn) {
                    MainScreen(onLogout = { isLoggedIn = false })
                } else {
                    LoginScreen(onLoginSuccess = { isLoggedIn = true })
                }
            }
        }
    }
}
```

Update `MainScreen` signature in `AppNavigation.kt` to accept `onLogout`:
```kotlin
// In AppNavigation.kt, update MainScreen to:
@Composable
fun MainScreen(onLogout: () -> Unit = {}) {
    // ... existing code, pass onLogout to Settings tab
```

- [ ] **Step 4: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 5: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/
git commit -m "feat: add login screen with Panel API authentication"
```

---

## Task 12: Tunnels Tab with VPN Toggle

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
- Modify: `app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`

- [ ] **Step 1: Create TunnelsViewModel.kt**

```kotlin
// ui/tunnels/TunnelsViewModel.kt
package com.leikwan.bridge.ui.tunnels

import android.app.Application
import android.net.VpnService
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.api.ForwardInfo
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.service.TunnelOrchestrator
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

enum class VpnStatus { DISCONNECTED, CONNECTING, CONNECTED }

data class TunnelsUiState(
    val vpnStatus: VpnStatus = VpnStatus.DISCONNECTED,
    val forwards: List<ForwardInfo> = emptyList(),
    val isLoading: Boolean = false,
    val error: String? = null,
)

class TunnelsViewModel(app: Application) : AndroidViewModel(app) {
    private val repo = PanelRepository(app)
    private val orchestrator = TunnelOrchestrator(app)

    private val _state = MutableStateFlow(TunnelsUiState())
    val state = _state.asStateFlow()

    init {
        refreshForwards()
    }

    fun refreshForwards() {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                val forwards = repo.pullConfigs()
                _state.value = _state.value.copy(forwards = forwards, isLoading = false)
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message, isLoading = false)
            }
        }
    }

    fun toggleVpn() {
        viewModelScope.launch {
            if (orchestrator.isRunning()) {
                _state.value = _state.value.copy(vpnStatus = VpnStatus.DISCONNECTED)
                orchestrator.stop()
            } else {
                _state.value = _state.value.copy(vpnStatus = VpnStatus.CONNECTING, error = null)
                try {
                    orchestrator.start()
                    _state.value = _state.value.copy(vpnStatus = VpnStatus.CONNECTED)
                } catch (e: Exception) {
                    _state.value = _state.value.copy(vpnStatus = VpnStatus.DISCONNECTED, error = e.message)
                }
            }
        }
    }
}
```

- [ ] **Step 2: Replace TunnelsScreen.kt**

```kotlin
// ui/tunnels/TunnelsScreen.kt
package com.leikwan.bridge.ui.tunnels

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.leikwan.bridge.api.ForwardInfo

@Composable
fun TunnelsScreen(viewModel: TunnelsViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()

    Column(modifier = Modifier.fillMaxSize().padding(16.dp)) {
        // VPN Toggle
        VpnToggleCard(
            status = state.vpnStatus,
            onToggle = { viewModel.toggleVpn() },
        )

        Spacer(Modifier.height(16.dp))

        // Header with refresh
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("Forwards", style = MaterialTheme.typography.titleLarge)
            IconButton(onClick = { viewModel.refreshForwards() }) {
                Icon(Icons.Default.Refresh, "Refresh")
            }
        }

        // Error
        state.error?.let { err ->
            Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            Spacer(Modifier.height(8.dp))
        }

        // Loading or list
        if (state.isLoading) {
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
        } else if (state.forwards.isEmpty()) {
            Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                Text("No forwards assigned", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        } else {
            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(state.forwards) { forward ->
                    ForwardCard(forward)
                }
            }
        }
    }
}

@Composable
private fun VpnToggleCard(status: VpnStatus, onToggle: () -> Unit) {
    val statusColor by animateColorAsState(
        when (status) {
            VpnStatus.DISCONNECTED -> MaterialTheme.colorScheme.onSurfaceVariant
            VpnStatus.CONNECTING -> Color(0xFFF59E0B) // amber
            VpnStatus.CONNECTED -> Color(0xFF22C55E)  // green
        }, label = "status"
    )
    val statusText = when (status) {
        VpnStatus.DISCONNECTED -> "Disconnected"
        VpnStatus.CONNECTING -> "Connecting..."
        VpnStatus.CONNECTED -> "Connected"
    }

    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.padding(20.dp).fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column {
                Text("VPN", style = MaterialTheme.typography.titleLarge)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Surface(
                        modifier = Modifier.size(10.dp),
                        shape = MaterialTheme.shapes.small,
                        color = statusColor,
                    ) {}
                    Spacer(Modifier.width(8.dp))
                    Text(statusText, style = MaterialTheme.typography.bodyMedium)
                }
            }
            Switch(
                checked = status != VpnStatus.DISCONNECTED,
                onCheckedChange = { onToggle() },
                enabled = status != VpnStatus.CONNECTING,
            )
        }
    }
}

@Composable
private fun ForwardCard(forward: ForwardInfo) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(forward.serviceName.ifEmpty { "Forward #${forward.forwardId}" },
                style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(4.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                AssistChip(onClick = {}, label = { Text(forward.mode.uppercase()) })
                AssistChip(onClick = {}, label = { Text(forward.transport.uppercase()) })
                forward.speedLimitMbps?.let {
                    AssistChip(onClick = {}, label = { Text("${it} Mbps") })
                }
            }

            Spacer(Modifier.height(8.dp))
            Text("Target: ${forward.target}", style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text("Listen: 127.0.0.1:${forward.listenPort}", style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
```

- [ ] **Step 3: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 4: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/
git commit -m "feat: add Tunnels tab with VPN toggle and forward cards"
```

---

## Task 13: Proxies Tab with Add/Delete/QR

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesViewModel.kt`
- Create: `app/src/main/java/com/leikwan/bridge/ui/proxies/AddProxyDialog.kt`
- Create: `app/src/main/java/com/leikwan/bridge/ui/proxies/QrScanScreen.kt`
- Modify: `app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesScreen.kt`

- [ ] **Step 1: Create ProxiesViewModel.kt**

```kotlin
// ui/proxies/ProxiesViewModel.kt
package com.leikwan.bridge.ui.proxies

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.Subscription
import com.leikwan.bridge.proxy.ProxyUriParser
import com.leikwan.bridge.proxy.SubscriptionFetcher
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch

data class ProxiesUiState(
    val nodes: List<ProxyNode> = emptyList(),
    val subscriptions: List<Subscription> = emptyList(),
    val isLoading: Boolean = false,
    val error: String? = null,
)

class ProxiesViewModel(app: Application) : AndroidViewModel(app) {
    private val db = AppDatabase.get(app)
    private val nodeDao = db.proxyNodeDao()
    private val subDao = db.subscriptionDao()

    private val _state = MutableStateFlow(ProxiesUiState())
    val state = _state.asStateFlow()

    init {
        viewModelScope.launch {
            nodeDao.getAll().collectLatest { nodes ->
                _state.value = _state.value.copy(nodes = nodes)
            }
        }
        viewModelScope.launch {
            subDao.getAll().collectLatest { subs ->
                _state.value = _state.value.copy(subscriptions = subs)
            }
        }
    }

    fun addFromUri(uriText: String) {
        viewModelScope.launch {
            val nodes = uriText.lines().mapNotNull { ProxyUriParser.parse(it.trim()) }
            if (nodes.isEmpty()) {
                _state.value = _state.value.copy(error = "No valid proxy URIs found")
                return@launch
            }
            nodeDao.insertAll(nodes)
            _state.value = _state.value.copy(error = null)
        }
    }

    fun addSubscription(name: String, url: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                val subId = subDao.insert(Subscription(name = name, url = url))
                val nodes = SubscriptionFetcher.fetch(url).map { it.copy(subscriptionId = subId) }
                nodeDao.insertAll(nodes)
                subDao.update(subDao.getById(subId)!!.copy(lastUpdated = System.currentTimeMillis()))
                _state.value = _state.value.copy(isLoading = false)
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message)
            }
        }
    }

    fun refreshSubscription(sub: Subscription) {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                nodeDao.deleteBySubscription(sub.id)
                val nodes = SubscriptionFetcher.fetch(sub.url).map { it.copy(subscriptionId = sub.id) }
                nodeDao.insertAll(nodes)
                subDao.update(sub.copy(lastUpdated = System.currentTimeMillis()))
                _state.value = _state.value.copy(isLoading = false)
            } catch (e: Exception) {
                _state.value = _state.value.copy(isLoading = false, error = e.message)
            }
        }
    }

    fun toggleNode(node: ProxyNode) {
        viewModelScope.launch { nodeDao.update(node.copy(enabled = !node.enabled)) }
    }

    fun deleteNode(node: ProxyNode) {
        viewModelScope.launch { nodeDao.delete(node) }
    }

    fun deleteSubscription(sub: Subscription) {
        viewModelScope.launch {
            nodeDao.deleteBySubscription(sub.id)
            subDao.delete(sub)
        }
    }

    fun clearError() { _state.value = _state.value.copy(error = null) }
}
```

- [ ] **Step 2: Create AddProxyDialog.kt**

```kotlin
// ui/proxies/AddProxyDialog.kt
package com.leikwan.bridge.ui.proxies

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun AddUriDialog(onDismiss: () -> Unit, onAdd: (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Add Proxy") },
        text = {
            OutlinedTextField(
                value = text,
                onValueChange = { text = it },
                label = { Text("ss://, vmess://, or vless:// URI") },
                modifier = Modifier.fillMaxWidth(),
                minLines = 3,
                maxLines = 6,
            )
        },
        confirmButton = {
            TextButton(onClick = { onAdd(text); onDismiss() }, enabled = text.isNotBlank()) {
                Text("Add")
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
fun AddSubscriptionDialog(onDismiss: () -> Unit, onAdd: (String, String) -> Unit) {
    var name by remember { mutableStateOf("") }
    var url by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Add Subscription") },
        text = {
            Column {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("Name") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = url,
                    onValueChange = { url = it },
                    label = { Text("Subscription URL") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onAdd(name, url); onDismiss() },
                enabled = name.isNotBlank() && url.isNotBlank(),
            ) { Text("Add") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}
```

- [ ] **Step 3: Create QrScanScreen.kt**

```kotlin
// ui/proxies/QrScanScreen.kt
package com.leikwan.bridge.ui.proxies

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.Executors

@Composable
fun QrScanScreen(onResult: (String) -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    var hasPermission by remember {
        mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED)
    }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        hasPermission = it
    }

    LaunchedEffect(Unit) {
        if (!hasPermission) launcher.launch(Manifest.permission.CAMERA)
    }

    Box(modifier = Modifier.fillMaxSize()) {
        if (hasPermission) {
            var scanned by remember { mutableStateOf(false) }
            AndroidView(
                factory = { ctx ->
                    val previewView = PreviewView(ctx)
                    val cameraProvider = ProcessCameraProvider.getInstance(ctx).get()
                    val preview = Preview.Builder().build().also {
                        it.surfaceProvider = previewView.surfaceProvider
                    }
                    val analyzer = ImageAnalysis.Builder().build().also {
                        it.setAnalyzer(Executors.newSingleThreadExecutor()) { imageProxy ->
                            @androidx.camera.core.ExperimentalGetImage
                            val mediaImage = imageProxy.image
                            if (mediaImage != null && !scanned) {
                                val image = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
                                BarcodeScanning.getClient().process(image)
                                    .addOnSuccessListener { barcodes ->
                                        for (barcode in barcodes) {
                                            barcode.rawValue?.let { value ->
                                                if (!scanned && (value.startsWith("ss://") || value.startsWith("vmess://") || value.startsWith("vless://"))) {
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
                    cameraProvider.bindToLifecycle(lifecycleOwner, CameraSelector.DEFAULT_BACK_CAMERA, preview, analyzer)
                    previewView
                },
                modifier = Modifier.fillMaxSize(),
            )
        } else {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text("Camera permission required")
            }
        }

        // Close button
        IconButton(
            onClick = onDismiss,
            modifier = Modifier.align(Alignment.TopEnd).padding(16.dp),
        ) {
            Icon(Icons.Default.Close, "Close", tint = MaterialTheme.colorScheme.onSurface)
        }
    }
}
```

- [ ] **Step 4: Replace ProxiesScreen.kt**

```kotlin
// ui/proxies/ProxiesScreen.kt
package com.leikwan.bridge.ui.proxies

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel

@Composable
fun ProxiesScreen(viewModel: ProxiesViewModel = viewModel()) {
    val state by viewModel.state.collectAsState()
    var showAddUri by remember { mutableStateOf(false) }
    var showAddSub by remember { mutableStateOf(false) }
    var showQrScan by remember { mutableStateOf(false) }
    var showFabMenu by remember { mutableStateOf(false) }

    if (showQrScan) {
        QrScanScreen(
            onResult = { uri -> viewModel.addFromUri(uri); showQrScan = false },
            onDismiss = { showQrScan = false },
        )
        return
    }

    Box(modifier = Modifier.fillMaxSize()) {
        Column(modifier = Modifier.fillMaxSize().padding(16.dp)) {
            Text("Proxies", style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(8.dp))

            state.error?.let { err ->
                Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                Spacer(Modifier.height(8.dp))
            }

            if (state.isLoading) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(8.dp))
            }

            if (state.nodes.isEmpty() && !state.isLoading) {
                Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                    Text("No proxy nodes. Tap + to add.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(state.nodes) { node ->
                        Card(modifier = Modifier.fillMaxWidth()) {
                            Row(
                                modifier = Modifier.padding(12.dp).fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(node.name, style = MaterialTheme.typography.titleMedium)
                                    Text(
                                        "${node.type.uppercase()} | ${node.originalServer}:${node.originalPort}",
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                                Row {
                                    Switch(
                                        checked = node.enabled,
                                        onCheckedChange = { viewModel.toggleNode(node) },
                                    )
                                    IconButton(onClick = { viewModel.deleteNode(node) }) {
                                        Icon(Icons.Default.Delete, "Delete",
                                            tint = MaterialTheme.colorScheme.error)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // FAB
        Column(
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
            horizontalAlignment = Alignment.End,
        ) {
            if (showFabMenu) {
                SmallFloatingActionButton(onClick = { showQrScan = true; showFabMenu = false }) {
                    Icon(Icons.Default.QrCodeScanner, "Scan QR")
                }
                Spacer(Modifier.height(8.dp))
                SmallFloatingActionButton(onClick = { showAddSub = true; showFabMenu = false }) {
                    Text("SUB", style = MaterialTheme.typography.labelMedium)
                }
                Spacer(Modifier.height(8.dp))
                SmallFloatingActionButton(onClick = { showAddUri = true; showFabMenu = false }) {
                    Text("URI", style = MaterialTheme.typography.labelMedium)
                }
                Spacer(Modifier.height(12.dp))
            }
            FloatingActionButton(onClick = { showFabMenu = !showFabMenu }) {
                Icon(Icons.Default.Add, "Add proxy")
            }
        }
    }

    if (showAddUri) {
        AddUriDialog(onDismiss = { showAddUri = false }, onAdd = { viewModel.addFromUri(it) })
    }
    if (showAddSub) {
        AddSubscriptionDialog(onDismiss = { showAddSub = false }, onAdd = { name, url -> viewModel.addSubscription(name, url) })
    }
}
```

- [ ] **Step 5: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 6: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/
git commit -m "feat: add Proxies tab with URI import, subscription support, and QR scanner"
```

---

## Task 14: Settings Tab with DNS, Bypass Rules, and Logout

**Files:**
- Create: `app/src/main/java/com/leikwan/bridge/ui/settings/SettingsViewModel.kt`
- Modify: `app/src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt`

- [ ] **Step 1: Create SettingsViewModel.kt**

```kotlin
// ui/settings/SettingsViewModel.kt
package com.leikwan.bridge.ui.settings

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import com.leikwan.bridge.data.Prefs
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

data class SettingsUiState(
    val dnsServer: String = "1.1.1.1",
    val dnsFallback: String = "8.8.8.8",
    val dnsStrategy: String = "prefer_ipv4",
    val bypassLan: Boolean = true,
    val bypassChina: Boolean = false,
    val customBypassRules: String = "",
    val username: String = "",
)

class SettingsViewModel(app: Application) : AndroidViewModel(app) {
    private val prefs = Prefs.get(app)

    private val _state = MutableStateFlow(SettingsUiState(
        dnsServer = prefs.dnsServer,
        dnsFallback = prefs.dnsFallback,
        dnsStrategy = prefs.dnsStrategy,
        bypassLan = prefs.bypassLan,
        bypassChina = prefs.bypassChina,
        customBypassRules = prefs.customBypassRules,
        username = prefs.username ?: "",
    ))
    val state = _state.asStateFlow()

    fun updateDnsServer(value: String) { prefs.dnsServer = value; _state.value = _state.value.copy(dnsServer = value) }
    fun updateDnsFallback(value: String) { prefs.dnsFallback = value; _state.value = _state.value.copy(dnsFallback = value) }
    fun updateDnsStrategy(value: String) { prefs.dnsStrategy = value; _state.value = _state.value.copy(dnsStrategy = value) }
    fun updateBypassLan(value: Boolean) { prefs.bypassLan = value; _state.value = _state.value.copy(bypassLan = value) }
    fun updateBypassChina(value: Boolean) { prefs.bypassChina = value; _state.value = _state.value.copy(bypassChina = value) }
    fun updateCustomBypass(value: String) { prefs.customBypassRules = value; _state.value = _state.value.copy(customBypassRules = value) }

    fun logout() { prefs.logout() }
}
```

- [ ] **Step 2: Replace SettingsScreen.kt**

```kotlin
// ui/settings/SettingsScreen.kt
package com.leikwan.bridge.ui.settings

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(
    onLogout: () -> Unit = {},
    viewModel: SettingsViewModel = viewModel(),
) {
    val state by viewModel.state.collectAsState()

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        Text("Settings", style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(16.dp))

        // --- DNS ---
        Text("DNS", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))

        OutlinedTextField(
            value = state.dnsServer,
            onValueChange = { viewModel.updateDnsServer(it) },
            label = { Text("DNS Server") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = state.dnsFallback,
            onValueChange = { viewModel.updateDnsFallback(it) },
            label = { Text("Fallback DNS") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Spacer(Modifier.height(8.dp))

        // DNS Strategy dropdown
        var strategyExpanded by remember { mutableStateOf(false) }
        val strategies = listOf("prefer_ipv4", "prefer_ipv6", "ipv4_only", "ipv6_only")
        ExposedDropdownMenuBox(expanded = strategyExpanded, onExpandedChange = { strategyExpanded = it }) {
            OutlinedTextField(
                value = state.dnsStrategy,
                onValueChange = {},
                readOnly = true,
                label = { Text("DNS Strategy") },
                modifier = Modifier.fillMaxWidth().menuAnchor(),
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = strategyExpanded) },
            )
            ExposedDropdownMenu(expanded = strategyExpanded, onDismissRequest = { strategyExpanded = false }) {
                strategies.forEach { strategy ->
                    DropdownMenuItem(
                        text = { Text(strategy) },
                        onClick = { viewModel.updateDnsStrategy(strategy); strategyExpanded = false },
                    )
                }
            }
        }

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // --- Bypass Rules ---
        Text("Bypass Rules", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))

        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Bypass LAN IP")
            Switch(checked = state.bypassLan, onCheckedChange = { viewModel.updateBypassLan(it) })
        }
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Bypass China IP/Domain")
            Switch(checked = state.bypassChina, onCheckedChange = { viewModel.updateBypassChina(it) })
        }

        Spacer(Modifier.height(8.dp))
        OutlinedTextField(
            value = state.customBypassRules,
            onValueChange = { viewModel.updateCustomBypass(it) },
            label = { Text("Custom bypass (one IP/domain per line)") },
            modifier = Modifier.fillMaxWidth(),
            minLines = 3,
            maxLines = 6,
        )

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        // --- Account ---
        Text("Account", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(8.dp))
        Text("Logged in as: ${state.username}", style = MaterialTheme.typography.bodyMedium)
        Spacer(Modifier.height(8.dp))
        OutlinedButton(
            onClick = { viewModel.logout(); onLogout() },
            modifier = Modifier.fillMaxWidth(),
            colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.error),
        ) {
            Text("Logout")
        }

        Spacer(Modifier.height(24.dp))

        // --- About ---
        Text("About", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(4.dp))
        Text("LeiKwan Bridge v0.1.0", style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
```

- [ ] **Step 3: Update AppNavigation.kt** to pass onLogout to Settings

Replace the Settings composable call in NavHost:
```kotlin
composable(Screen.Settings.route) { SettingsScreen(onLogout = onLogout) }
```

- [ ] **Step 4: Build and verify**

```bash
cd leikwan-android && ./gradlew assembleDebug
```

- [ ] **Step 5: Commit**

```bash
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/
git add leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt
git commit -m "feat: add Settings tab with DNS config, bypass rules, and logout"
```

---

## Task 15: Gradle Wrapper and First Build

**Files:**
- Create: Gradle wrapper files in `leikwan-android/`

- [ ] **Step 1: Initialize Gradle wrapper**

Run from Windows (since Android Studio is installed):
```bash
cd leikwan-android
# Create wrapper using gradle from Android Studio or download
"C:/Program Files/Android/Android Studio/jbr/bin/java" -version  # verify Java works
```

Then set up the wrapper manually:
```bash
mkdir -p leikwan-android/gradle/wrapper
```

Create `leikwan-android/gradle/wrapper/gradle-wrapper.properties`:
```properties
distributionBase=GRADLE_USER_HOME
distributionPath=wrapper/dists
distributionUrl=https\://services.gradle.org/distributions/gradle-8.7-bin.zip
zipStoreBase=GRADLE_USER_HOME
zipStorePath=wrapper/dists
```

Download `gradlew` and `gradlew.bat` wrapper scripts or copy from any existing Android project.

- [ ] **Step 2: Set local.properties with SDK path**

Install Android SDK if not present:
```bash
# In Android Studio: Tools > SDK Manager > install SDK 34, Build Tools 34.0.0, NDK
```

Create `leikwan-android/local.properties`:
```properties
sdk.dir=C:\\Users\\frankniubi\\AppData\\Local\\Android\\Sdk
```

If SDK is at a different location (check Android Studio settings), update accordingly.

- [ ] **Step 3: Build debug APK**

```bash
cd leikwan-android
./gradlew assembleDebug
```

Expected: `app/build/outputs/apk/debug/app-debug.apk`

- [ ] **Step 4: Install via ADB**

Once user connects phone:
```bash
C:/Users/frankniubi/Downloads/leikwan-br1/platform-tools/adb.exe install app/build/outputs/apk/debug/app-debug.apk
```

- [ ] **Step 5: Commit**

```bash
git add leikwan-android/
git commit -m "build: add Gradle wrapper and complete first debug build"
```

---

## Task 16: VPN Permission Flow and End-to-End Integration

**Files:**
- Modify: `app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
- Modify: `app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`

- [ ] **Step 1: Add VPN permission handling in TunnelsScreen**

Add VPN permission request launcher:
```kotlin
// In TunnelsScreen.kt, add at the top of the composable:
val context = LocalContext.current
val vpnPermissionLauncher = rememberLauncherForActivityResult(
    ActivityResultContracts.StartActivityForResult()
) { result ->
    if (result.resultCode == Activity.RESULT_OK) {
        viewModel.toggleVpn()
    }
}

// Update the VpnToggleCard onToggle:
onToggle = {
    if (state.vpnStatus == VpnStatus.DISCONNECTED) {
        val intent = VpnService.prepare(context)
        if (intent != null) {
            vpnPermissionLauncher.launch(intent)
        } else {
            viewModel.toggleVpn()
        }
    } else {
        viewModel.toggleVpn()
    }
}
```

- [ ] **Step 2: Test the full flow**

1. Install APK via ADB
2. Open app -> Login with Panel credentials
3. View forward list on Tunnels tab
4. Add a proxy node via URI on Proxies tab
5. Toggle VPN on -> accept VPN permission -> verify connection

- [ ] **Step 3: Commit**

```bash
git add leikwan-android/
git commit -m "feat: add VPN permission flow and end-to-end tunnel integration"
```
