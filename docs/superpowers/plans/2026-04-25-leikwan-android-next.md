# Leikwan Android Next Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create `C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next`, a new Android app that keeps the working runtime and panel-pull behavior from `android-client`, matches the panel-published contract exactly, upgrades bond to the latest Linux-aligned path, and replaces the current ugly UI with a Compose shell at least as good as `leikwan-android`.

**Architecture:** Start from a trimmed copy of `android-client` so the proven runtime, import, subscription, `VpnService`, and `sing-box` paths remain the operational base. Add a typed panel-published contract layer sourced from `ProvisioningService::buildClientConfig()`, wrap the legacy stores/runtime in repositories, rebuild the app shell and screens in Compose using `leikwan-android` as the UX model, then replace the stale Android bond branch with a dedicated planner that follows the current Linux `leikwan-bond` CLI, `client-agent/install_forward_runtime.py`, and `docs/BOND_OPERATIONS.md`.

**Tech Stack:** Kotlin, Jetpack Compose, Android `VpnService`, libbox (`sing-box`), SharedPreferences-backed runtime stores carried forward from `android-client`, OkHttp/HTTP URLConnection for panel pull, ZXing QR scan, ABI-packaged native runtimes (`wstunnel`, `leikwan-ingress`, `leikwan-bond`).

---

## Scope Decisions Locked Before Implementation

- New project root: `C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next`
- New namespace / app id: `com.leikwan.androidnext`
- New deep-link/import scheme: `leikwan-android-next`
- Primary onboarding flow: install-command / Android import-string setup from `android-client`, not the panel account login flow from `leikwan-android`
- UI system: Jetpack Compose
- Service-mode truth: panel-published config from `master/src/Support/ProvisioningService.php::buildClientConfig()`
- Bond runtime truth: latest Linux bond source and current publish contract, not the old `android-client` bond branch
- Side-by-side installability with existing Android apps is mandatory

## What Already Exists And Should Stay True

### Runtime truth from `android-client`

Keep these behaviors working while the UI changes around them:
- install command parsing
- stored `master_url`, `client_id`, `agent_token`
- `/api/client/configs/pull` polling
- service relay runtime orchestration
- direct profile runtime through `sing-box`
- subscription refresh and share-link parsing
- `VpnService` ownership
- boot/autostart behavior if already configured

Reference files:
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\AppPrefs.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\ApiClient.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\TunnelEngine.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\TunnelService.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\LeikwanVpnService.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\SubscriptionImportSupport.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\ProfileStore.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\RelayStore.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\SingBoxSupport.kt`

### UX truth from `leikwan-android`

Copy the patterns, not the broken runtime assumptions:
- Compose navigation shell
- bottom-tab layout
- cleaner tunnels / proxies / settings screen split
- snackbar-driven feedback
- lightweight cards instead of giant debug walls
- proxy editor that can be split into Basic and Advanced sections

Reference files:
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/MainActivity.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesScreen.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/settings/SettingsScreen.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/theme/*`

### Panel publish truth

The new app must not guess what the service runtime should be.

Authoritative source:
- `master/src/Support/ProvisioningService.php`
- especially `buildClientConfig()`

The new parser and runtime must remain aligned with the published values for:
- `mode`
- `transport`
- `listen_port`
- `target`
- `service_status`
- `speed_limit_mbps`
- `bond_remote_host`
- `bond_remote_port`
- `bonding`
- `mptcp`
- `endpoints[]`, including `host`, `port`, `priority`, `weight`, `health_status`, `supports_quic_leg`, `ingress_protocol`, and `ingress_auth_token`

Reference files:
- `master/src/Support/ProvisioningService.php`
- `docs/BOND_OPERATIONS.md`
- `client-agent/install_forward_runtime.py`
- `docs/2026-04-19-forward-26-panel-publish-vs-live-client-investigation.md`
- `docs/2026-04-19-forward-26-udp-uot-open-ack-fix.md`

## Not In Scope

- panel account email/password login as the primary onboarding path
- backend-breaking API changes for non-bond protocols
- dropping advanced protocol fields to make the UI look simple
- changing topology away from `client -> slave -> sidecar`
- full shared-core rewrite before a shippable Android app exists

## File Structure

### New project root
- `leikwan-android-next/`

### Key files that will exist after scaffolding

#### Core runtime and storage carried forward from `android-client`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/AppPrefs.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ApiClient.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/TunnelEngine.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/TunnelService.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/LeikwanVpnService.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/RuntimeBinaries.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/SingBoxSupport.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ProfileStore.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/NodeStore.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/RelayStore.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/SubscriptionImportSupport.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/TunnelModels.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ProfileModels.kt`

#### New typed contract and repository layer
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/panel/PublishedForwardContract.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/panel/PublishedForwardParser.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/panel/PanelSnapshotRepository.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/BondPlanBuilder.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/IngressPlanBuilder.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/RuntimeBinaryInstaller.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/RuntimeStatusRepository.kt`

#### New Compose UI layer
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/MainActivity.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/nav/AppNavigation.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/theme/LeikwanNextTheme.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/setup/SetupScreen.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/setup/SetupViewModel.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/tunnels/TunnelsScreen.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/tunnels/TunnelsViewModel.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProxiesScreen.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProxiesViewModel.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProfileEditorScreen.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProfileDraft.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/QrScanScreen.kt`
- `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/settings/SettingsScreen.kt`

#### Tests to add
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/panel/PublishedForwardParserTest.kt`
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/panel/PanelSnapshotRepositoryTest.kt`
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/runtime/BondPlanBuilderTest.kt`
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/runtime/IngressPlanBuilderTest.kt`
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/runtime/RuntimeBinaryInstallerTest.kt`
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/setup/SetupViewModelTest.kt`
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/proxies/ProfileDraftMapperTest.kt`
- `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/tunnels/TunnelsViewModelTest.kt`

---

## Task 1: Scaffold `leikwan-android-next` from `android-client` without dragging local SDK junk into the new project

**Files:**
- Create: `leikwan-android-next/`
- Modify: `leikwan-android-next/settings.gradle.kts`
- Modify: `leikwan-android-next/app/build.gradle.kts`
- Modify: `leikwan-android-next/app/src/main/AndroidManifest.xml`
- Modify: `leikwan-android-next/app/src/main/res/values/strings.xml`
- Rename package tree under: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/`

- [ ] **Step 1: Copy the old working app into the new project root, excluding local SDK/build directories**

Run:

```powershell
$src = 'C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client'
$dst = 'C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next'
robocopy $src $dst /E /XD .gradle android-sdk commandlinetools-extract platform-tools build app\build
```

Expected:
- `leikwan-android-next\app\src\main\java\com\leikwan\androidclient\` exists
- no copied `android-sdk`, `commandlinetools-extract`, or `platform-tools` directories in the new project

- [ ] **Step 2: Rename the Android identity so the new app can coexist with the old apps**

Update `leikwan-android-next/app/build.gradle.kts` to use:

```kotlin
android {
    namespace = "com.leikwan.androidnext"

    defaultConfig {
        applicationId = "com.leikwan.androidnext"
        minSdk = 26
        targetSdk = 34
        versionCode = 1
        versionName = "2026.04.25.1"
    }
}
```

Update `leikwan-android-next/app/src/main/res/values/strings.xml` so `app_name` becomes `Leikwan Android Next`.

Update `leikwan-android-next/app/src/main/AndroidManifest.xml` so the import scheme becomes:

```xml
<data android:scheme="leikwan-android-next" />
```

- [ ] **Step 3: Rename Kotlin packages from `com.leikwan.androidclient` to `com.leikwan.androidnext`**

Run a controlled package rename in the new project only:

```powershell
Get-ChildItem 'C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next\app\src' -Recurse -File |
  Where-Object { $_.Extension -in '.kt', '.xml' } |
  ForEach-Object {
    (Get-Content $_.FullName) -replace 'com\.leikwan\.androidclient', 'com.leikwan.androidnext' |
      Set-Content $_.FullName
  }
```

Then move the source directory from:
- `...\java\com\leikwan\androidclient`

to:
- `...\java\com\leikwan\androidnext`

- [ ] **Step 4: Verify the copied legacy app still builds before any Compose work starts**

Run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:assembleDebug
```

Expected:
- `BUILD SUCCESSFUL`
- `app\build\outputs\apk\debug\app-debug.apk` exists

- [ ] **Step 5: Commit the clean scaffold**

```bash
git add leikwan-android-next
git commit -m "feat: scaffold leikwan android next project"
```

## Task 2: Add a typed panel-published contract layer that matches `ProvisioningService::buildClientConfig()` instead of relying on the old minimal parser

**Files:**
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ApiClient.kt`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/TunnelModels.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/panel/PublishedForwardContract.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/panel/PublishedForwardParser.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/panel/PanelSnapshotRepository.kt`
- Test: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/panel/PublishedForwardParserTest.kt`
- Test: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/panel/PanelSnapshotRepositoryTest.kt`

- [ ] **Step 1: Write the failing parser tests using the panel-published shape, not the old reduced Android shape**

Create `PublishedForwardParserTest.kt` with cases that assert parsing of:
- `mode`
- `transport`
- `listen_port`
- `target`
- `service_status`
- `speed_limit_mbps`
- `bond_remote_host`
- `bond_remote_port`
- `bonding.route_key`
- `bonding.session_token`
- `bonding.udp_session_token`
- `bonding.quic_*`
- endpoint `ingress_protocol`
- endpoint `ingress_auth_token`
- endpoint `supports_quic_leg`

Use one JSON payload copied from the real key names returned by `buildClientConfig()`.

- [ ] **Step 2: Keep the old `PullResponseParser` for runtime compatibility, but add a richer contract parser beside it**

Create a new data model shaped like:

```kotlin
data class PublishedForwardContract(
    val forwardId: Int,
    val serviceName: String,
    val listenPort: Int,
    val target: String,
    val mode: String,
    val transport: String,
    val serviceStatus: String,
    val speedLimitMbps: Int?,
    val bondRemoteHost: String,
    val bondRemotePort: Int?,
    val bonding: PublishedBondingContract?,
    val endpoints: List<PublishedEndpoint>,
)
```

Do not delete the old `ForwardConfig` / `PullResponse` structures yet. The old runtime will still use them until the new repositories are wired through.

- [ ] **Step 3: Cache one raw panel snapshot and expose both views of it**

`PanelSnapshotRepository` should:
- store the raw JSON from `/api/client/configs/pull`
- parse legacy `PullResponse` for the working runtime path
- parse `PublishedForwardContract` for the new UI and bond planner
- expose a method that compares the two lists by `forward_id` and fails loudly if a forward disappeared from one view but not the other

- [ ] **Step 4: Verify the repository sees the same publish set the panel emitted**

Run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.androidnext.panel.PublishedForwardParserTest"
```

Expected:
- parser tests pass
- tests cover at least one `bond` forward and one non-bond forward

- [ ] **Step 5: Commit the typed panel-publish layer**

```bash
git add leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ApiClient.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/TunnelModels.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/panel leikwan-android-next/app/src/test/java/com/leikwan/androidnext/panel
git commit -m "feat: add typed panel publish contract layer"
```

## Task 3: Add the Compose shell and replace the old XML launcher activity with a setup-driven onboarding flow

**Files:**
- Modify: `leikwan-android-next/app/build.gradle.kts`
- Modify: `leikwan-android-next/app/src/main/AndroidManifest.xml`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/MainActivity.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/nav/AppNavigation.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/theme/Color.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/theme/Theme.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/theme/Type.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/setup/SetupScreen.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/setup/SetupViewModel.kt`
- Test: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/setup/SetupViewModelTest.kt`

- [ ] **Step 1: Add Compose support using the `leikwan-android` dependency pattern**

Merge the Compose-specific pieces from `leikwan-android/app/build.gradle.kts` into the new project:

```kotlin
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    buildFeatures {
        compose = true
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.06.00")
    implementation(composeBom)
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.activity:activity-compose:1.9.1")
    implementation("androidx.navigation:navigation-compose:2.7.7")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.4")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
```

- [ ] **Step 2: Replace the launcher with a Compose `MainActivity` that routes into Setup, Tunnels, Proxies, and Settings**

Use `leikwan-android` as the visual reference, but do not port its email/password login flow. The setup screen must use:
- `InstallCommandParser`
- saved `InstallConfig` from `AppPrefs`
- deep-link import via `leikwan-android-next://`
- shared text import where useful

- [ ] **Step 3: Add a setup screen that wraps the old import behavior cleanly**

`SetupViewModel` should support:
- paste install command
- paste Android import string
- show the parsed `master_url` and `client_id`
- save through `AppPrefs.saveInstallConfig()`
- trigger first refresh through `PanelSnapshotRepository`

- [ ] **Step 4: Write the viewmodel tests first**

Test cases:
- valid install command saves config and emits success state
- invalid import string leaves config untouched and emits readable error
- deep-link scheme payload is routed into the same parse path as manual paste

- [ ] **Step 5: Verify the new launcher compiles while the old runtime classes remain untouched**

Run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:compileDebugKotlin
```

Expected:
- `BUILD SUCCESSFUL`
- launcher activity is `com.leikwan.androidnext.ui.MainActivity`
- manifest still contains `TunnelService`, `LeikwanVpnService`, and `BootReceiver`

- [ ] **Step 6: Commit the Compose shell and setup flow**

```bash
git add leikwan-android-next/app/build.gradle.kts leikwan-android-next/app/src/main/AndroidManifest.xml leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/setup
git commit -m "feat: add compose shell and setup onboarding"
```

## Task 4: Wrap the legacy profile, node, subscription, and relay stores in repositories that the Compose screens can use without rewriting the runtime first

**Files:**
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ProfileStore.kt`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/NodeStore.kt`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/RelayStore.kt`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/SubscriptionImportSupport.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/repo/ProfileRepository.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/repo/RelayRepository.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/repo/ImportRepository.kt`
- Test: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/repo/ImportRepositoryTest.kt`
- Test: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/repo/ProfileRepositoryTest.kt`

- [ ] **Step 1: Preserve the old stores exactly before adding repository wrappers**

Do not rewrite storage format in this task. Keep these files as the persistence truth for v1:
- `ProfileStore.kt`
- `NodeStore.kt`
- `RelayStore.kt`
- `AppPrefs.kt`

The repositories should adapt them to flows/state holders for Compose without changing the JSON keys yet.

- [ ] **Step 2: Keep the old import grammar, including WireGuard and subscription parsing**

`ImportRepository` must wrap `SubscriptionImportSupport` so the new UI keeps:
- `ss://`
- `vmess://`
- `vless://`
- `wireguard://`
- full `[Interface]` / `[Peer]` WireGuard config import
- subscription URLs
- sing-box remote profile links
- existing Hysteria/Hysteria2 parsing from the old app

Even if the v1 design emphasis is on `ss`, `vless`, `vmess`, and `wireguard`, the import layer must not regress the existing old-app grammar.

- [ ] **Step 3: Add repository tests that prove imported profiles survive round-trip store writes**

Cover:
- imported `ss` profile saved and loaded back with the same method/password
- imported `vmess` profile keeps UUID and security
- imported `vless` profile keeps flow/SNI/TLS flags
- imported `wireguard` profile keeps local address, allowed IPs, reserved bytes, and MTU
- subscription refresh replaces prior imported nodes for the same subscription id without deleting manual profiles

- [ ] **Step 4: Verify repository code compiles without touching the VPN/runtime path yet**

Run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.androidnext.repo.ImportRepositoryTest" --tests "com.leikwan.androidnext.repo.ProfileRepositoryTest"
```

Expected:
- repository tests pass
- no changes to `TunnelEngine.kt` required yet

- [ ] **Step 5: Commit the repository layer**

```bash
git add leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ProfileStore.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/NodeStore.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/RelayStore.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/SubscriptionImportSupport.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/repo leikwan-android-next/app/src/test/java/com/leikwan/androidnext/repo
git commit -m "feat: wrap legacy stores for compose state"
```

## Task 5: Build the Compose Proxies screen and Basic/Advanced editor on top of the old profile model instead of forcing a risky storage rewrite

**Files:**
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProxiesScreen.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProxiesViewModel.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProfileEditorScreen.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProfileDraft.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/ProfileDraftMapper.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies/QrScanScreen.kt`
- Test: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/proxies/ProfileDraftMapperTest.kt`

- [ ] **Step 1: Port the visual structure from `leikwan-android`, not its Room-backed entity model**

The screen should have:
- selected profile banner
- relay-backed vs direct mode switch for the active profile
- add/import action cluster
- subscription section
- clean list cards with protocol, endpoint, and binding summary

But the screen state should be built from `ProxyProfile`, `RemoteSubscription`, and `RelayOption`, not `ProxyNode`.

- [ ] **Step 2: Split the profile editor into Basic and Advanced sections**

Basic section must include:
- name
- protocol
- transport mode (`relay_backed` or `direct`)
- server/port or relay binding
- required credential fields
- TLS enablement where relevant

Advanced section must include the old operational knobs rather than deleting them:
- SNI
- allow insecure
- VMess security
- WireGuard local address, allowed IPs, reserved, MTU
- Hysteria / Hysteria2 bandwidth and TLS knobs
- any non-default transport details the old runtime still consumes

- [ ] **Step 3: Keep QR/import behavior and save directly into the old stores through the repositories**

`ProxiesViewModel` should:
- add parsed profiles through `ProfileRepository`
- refresh subscriptions through `ImportRepository`
- save selected active profile id through `ProfileStore`
- route direct-start actions into the existing `LeikwanVpnService` / `SingBoxManager` path instead of inventing a second direct runtime

- [ ] **Step 4: Add the mapper tests before wiring the screen**

Test cases:
- `ProxyProfile` with `ProfileType.WIREGUARD` maps to editor draft with all WG fields visible in Advanced
- `ProxyProfile` with `TransportMode.RELAY_BACKED` maps to relay selection state instead of server/port editing state
- save from draft recreates the original `ProxyProfile` for `ss`, `vmess`, `vless`, and `wireguard`

- [ ] **Step 5: Verify direct-profile smoke paths from the new UI**

Run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.androidnext.ui.proxies.ProfileDraftMapperTest"
.\gradlew.bat :app:assembleDebug
```

Then manual smoke on device/emulator:
- import one `ss://` link
- import one `vmess://` link
- import one `vless://` link
- import one WireGuard profile
- confirm each renders in the new UI without losing fields

- [ ] **Step 6: Commit the Compose proxy management flow**

```bash
git add leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/proxies leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/proxies
git commit -m "feat: add compose proxies screen and editor"
```

## Task 6: Build the Compose Tunnels and Settings screens around the panel-published contract and the existing service runtime, then surface published-vs-live mismatches instead of hiding them

**Files:**
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/tunnels/TunnelsScreen.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/tunnels/TunnelsViewModel.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/settings/SettingsScreen.kt`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/AppPrefs.kt`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/RelayStore.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/RuntimeStatusRepository.kt`
- Test: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/tunnels/TunnelsViewModelTest.kt`

- [ ] **Step 1: Build the tunnel list from `PublishedForwardContract`, not the old reduced relay summary alone**

Each tunnel card must surface the panel-published values for:
- service name
- mode
- transport
- target
- listen port
- service status
- endpoint count
- bond remote target where relevant

Do not locally recompute or rename these fields into something that hides what the panel actually published.

- [ ] **Step 2: Keep runtime control on the existing service machinery during the first cut**

`TunnelsViewModel` should:
- start/stop the existing `TunnelService`
- use `PanelSnapshotRepository` for the current published contract view
- use `RuntimeStatusRepository` for running/failed status
- keep selected service forward id in `AppPrefs`

This preserves the known working service runtime while the shell changes around it.

- [ ] **Step 3: Add a published-vs-live mismatch surface**

Create a small comparison that checks:
- panel-published endpoint set for the selected forward
- current relay/runtime snapshot in `RelayStore`

If the sets differ, surface a visible mismatch state with wording like:
- `Published configuration and live runtime differ`
- `Republish or restart the service runtime before trusting performance tests`

Do not bury this only in debug text.

- [ ] **Step 4: Put setup and runtime controls into Settings instead of the old all-in-one page**

Settings should include:
- current imported `master_url`
- client id
- raw import command preview
- autostart toggle
- custom DNS endpoint
- runtime summary/details/error block
- a safe action to clear the imported setup and stop services

- [ ] **Step 5: Add viewmodel tests before device smoke**

Cover:
- selected forward state follows the published contract list
- inactive or missing published forward clears selection cleanly
- mismatch detection becomes visible when published endpoints do not match relay snapshot endpoints
- runtime error text is surfaced into UI state without truncating away the important part

- [ ] **Step 6: Verify the service UX path in the new shell**

Run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.androidnext.ui.tunnels.TunnelsViewModelTest"
.\gradlew.bat :app:assembleDebug
```

Manual smoke:
- import working install command
- pull config
- open Tunnels
- verify the screen shows panel-published mode/transport/target
- start the service runtime
- verify runtime status changes in the UI

- [ ] **Step 7: Commit the tunnels/settings Compose flow**

```bash
git add leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/tunnels leikwan-android-next/app/src/main/java/com/leikwan/androidnext/ui/settings leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/RuntimeStatusRepository.kt leikwan-android-next/app/src/test/java/com/leikwan/androidnext/ui/tunnels
git commit -m "feat: add compose tunnels and settings screens"
```

## Task 7: Replace the stale Android bond branch with a Linux-aligned bond planner that follows the current panel publish contract and packages the missing ingress runtime

**Files:**
- Modify: `leikwan-android-next/app/build.gradle.kts`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/RuntimeBinaries.kt`
- Modify: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/TunnelEngine.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/RuntimeBinaryInstaller.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/IngressPlanBuilder.kt`
- Create: `leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime/BondPlanBuilder.kt`
- Create: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/runtime/IngressPlanBuilderTest.kt`
- Create: `leikwan-android-next/app/src/test/java/com/leikwan/androidnext/runtime/BondPlanBuilderTest.kt`
- Package: `leikwan-android-next/app/src/main/assets/runtime/arm64-v8a/wstunnel`
- Package: `leikwan-android-next/app/src/main/assets/runtime/arm64-v8a/leikwan-ingress`
- Package: `leikwan-android-next/app/src/main/assets/runtime/arm64-v8a/leikwan-bond`

- [ ] **Step 1: Write the planner tests before touching `TunnelEngine.kt`**

`BondPlanBuilderTest.kt` must assert:
- `plain_tcp_mux` bond requires `route_key` and `ingress_auth_token`
- the generated `leikwan-bond client` command includes:
  - `--protocol`
  - `--listen`
  - `--legs`
  - `--session-token`
  - `--chunk-size`
  - `--max-window-bytes`
  - `--retransmit-ms`
  - `--connect-timeout-ms`
  - `--join-timeout-ms`
  - `--interactive-chunk-bytes`
  - `--latency-guard-bytes`
  - `--interactive-rtt-slack-ms`
  - `--warmup-trigger-bytes`
  - `--warmup-slice-bytes`
  - `--warmup-leg-delay-ms`
  - `--bulk-promote-bytes`
  - `--leg-cooldown-ms`
- QUIC leg rendering uses the published endpoint metadata and bond `quic_*` fields
- TCP fallback still works when `quic` legs are unavailable

`IngressPlanBuilderTest.kt` must assert:
- `plain_tcp_mux` ingress command uses `leikwan-ingress client --listen --remote --route-key --auth-token`
- non-`plain_tcp_mux` fallback still renders `wstunnel client` against the published remote target

- [ ] **Step 2: Package and install the missing runtime binaries instead of pretending `libleikwanbond.so` is the whole story**

Port the asset-based install pattern from `leikwan-android`:
- copy ABI-specific binaries from `app/src/main/assets/runtime/arm64-v8a/`
- install them into an app-owned runtime directory on first use
- mark them executable

`RuntimeBinaryInstaller` must expose paths for:
- `wstunnel`
- `leikwan-ingress`
- `leikwan-bond`

- [ ] **Step 3: Move bond command generation out of the old inline `TunnelEngine` branch into planners that consume `PublishedForwardContract`**

Do not keep the old branch that just opens raw `wstunnel` legs to `bond.remoteTarget`.

Instead:
- read the published bond config
- render one ingress helper per published endpoint when `ingress_mode == plain_tcp_mux`
- preserve the current topology `client -> slave -> sidecar`
- feed the generated local legs into `leikwan-bond client`
- keep Linux-style adaptive join timeout and QUIC leg selection behavior where the published fields allow it

- [ ] **Step 4: Keep the rest of `TunnelEngine` stable while swapping bond only**

Do not rewrite `single` / race / direct service behavior in this task. The safe change is:
- extract only the bond branch into planner-backed command generation
- leave the rest of the old runtime path behaving as before

- [ ] **Step 5: Verify bond behavior against panel publish truth, not wishful local assumptions**

Run:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.androidnext.runtime.BondPlanBuilderTest" --tests "com.leikwan.androidnext.runtime.IngressPlanBuilderTest"
.\gradlew.bat :app:assembleDebug
```

Manual smoke for one known bond forward:
- confirm the pulled contract shows the expected published slave endpoints
- start the service runtime
- confirm one ingress helper is launched per published endpoint
- confirm `leikwan-bond` starts with the current CLI field set from latest Linux source
- confirm the app does not connect directly to the sidecar except through the published slave hop topology

- [ ] **Step 6: Commit the bond modernization**

```bash
git add leikwan-android-next/app/build.gradle.kts leikwan-android-next/app/src/main/java/com/leikwan/androidnext/RuntimeBinaries.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/TunnelEngine.kt leikwan-android-next/app/src/main/java/com/leikwan/androidnext/runtime leikwan-android-next/app/src/main/assets/runtime leikwan-android-next/app/src/test/java/com/leikwan/androidnext/runtime
git commit -m "feat: modernize android bond runtime to linux parity"
```

## Task 8: Run the full verification matrix before claiming the new app is the replacement candidate

**Files:**
- Modify: `docs/2026-04-20-android-client-implementation-maintenance-log.md`
- Create: `artifacts/android-next/` screenshots, logs, and contract snapshots

- [ ] **Step 1: Run the full unit-test and build suite**

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next
.\gradlew.bat :app:testDebugUnitTest
.\gradlew.bat :app:assembleDebug
```

Expected:
- `BUILD SUCCESSFUL`
- all new parser, repository, setup, proxy, tunnel, and bond planner tests pass

- [ ] **Step 2: Install the debug APK beside the existing apps**

```powershell
adb install -r .\app\build\outputs\apk\debug\app-debug.apk
```

Expected:
- the old `android-client`, the current `leikwan-android`, and `leikwan-android-next` can all coexist on the device

- [ ] **Step 3: Execute the direct-profile smoke matrix**

Manual verification:
- import one `ss://` profile and connect
- import one `vmess://` profile and connect
- import one `vless://` profile and connect
- import one WireGuard config and connect
- confirm each path reaches the VPN runtime and routes traffic

Record:
- screenshot of profile list
- screenshot of connected state
- any logcat lines needed to prove `LeikwanVpnService` started cleanly

- [ ] **Step 4: Execute the service-mode smoke matrix against the panel-published contract**

Manual verification:
- import a valid install command
- pull config
- compare the tunnel card to the current panel-published `mode`, `transport`, `target`, and endpoint count
- start the runtime
- confirm the visible runtime path matches the pulled contract

Record:
- pulled contract snapshot JSON
- screenshot of the Tunnels screen before start
- screenshot after connect

- [ ] **Step 5: Execute the bond-specific smoke matrix**

Manual verification for one bond forward:
- capture the published endpoint set
- start the bond runtime
- verify ingress-helper launch count equals published endpoint count
- verify `leikwan-bond` command line includes the latest-source flags
- verify the runtime path still follows `client -> slave -> sidecar`

Record:
- pulled contract snapshot
- process list / command-line evidence
- relevant runtime logs

- [ ] **Step 6: Update the maintenance log with what actually shipped and what still blocks replacement**

Append to `docs/2026-04-20-android-client-implementation-maintenance-log.md`:
- exact APK path
- exact tests run
- direct-profile results
- service-mode results
- bond results
- whether `leikwan-android-next` is good enough to replace the old app yet

- [ ] **Step 7: Commit the verification evidence and documentation updates**

```bash
git add docs/2026-04-20-android-client-implementation-maintenance-log.md artifacts/android-next
git commit -m "docs: record leikwan android next verification evidence"
```

## Self-Review Checklist For The Implementer

Before calling this done, verify:
- the new app uses install-command setup, not the panel account login path
- the tunnel list reflects what the panel published, not what the Android app guessed
- the direct profile editor still exposes all required advanced fields
- WireGuard import and direct connect both survive the UI rewrite
- bond no longer depends on the stale old inline Android bond branch
- at least one mismatch state exists for published-vs-live runtime divergence
- the app still installs beside the two existing Android apps

## Final Execution Order

1. Scaffold and rename the project
2. Add the typed panel-publish layer
3. Add the Compose shell and setup onboarding
4. Wrap the legacy stores and import stack in repositories
5. Build the Compose proxy management flow
6. Build the Compose tunnels/settings flow
7. Modernize bond only after the rest of the shell is stable
8. Run the full verification matrix
