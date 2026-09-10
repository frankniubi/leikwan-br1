# Android Client Implementation Maintenance Log

Date: 2026-04-20

Scope:

- Android single/bond Linux-parity planning and execution
- Android typed forward contract parsing and unsupported-mode filtering
- Android runtime planning/orchestration rewrite
- Android `sing-box` runtime-context rewrite
- Android packaged runtime binaries and build verification

## 1. What Was Implemented

### 1.1 Linux-Parity Runtime Direction

The Android app was moved toward the same practical runtime contract that the Linux client already uses for the modes Android should actually support today:

- supported in this pass:
  - `single`
  - `bond`
- explicitly filtered out for Android use in this pass:
  - `mptcp`
- runtime entry is no longer one hardcoded bond path for everything

Reference truth sources used during the work:

- `client-agent/install_forward_runtime.py`
- `master/src/Support/ProvisioningService.php`
- `docs/BOND_OPERATIONS.md`

### 1.2 Typed Panel Contract Parsing

Android now parses and retains a typed forward contract instead of relying on a flattened summary model.

Implemented files:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardMode.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`

Behavior changes:

- forward mode normalization is explicit
- bond payload fields survive parsing instead of being dropped
- cached contract retrieval is available for runtime startup paths

### 1.3 Android Rule Filtering

Android now hides unsupported `mptcp` forwards instead of pretending they are runnable.

Implemented behavior:

- pull catalog is filtered to Android-supported modes
- stale unsupported selections are cleared
- UI shows a note when unsupported forwards were hidden

Main files:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`

### 1.4 Runtime Planning Layer

Android runtime bring-up is now planned through mode-aware runtime objects instead of one old bond-only execution path.

Implemented files:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/SingleForwardRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`

Behavior changes:

- `single` has its own runtime planning path
- `bond` planning now uses the Linux-parity contract fields
- bond tuning values are carried through instead of being flattened back to defaults

Fields explicitly honored in the Android runtime plan:

- `chunk_size`
- `max_window_bytes`
- `retransmit_ms`
- `connect_timeout_ms`
- `interactive_chunk_bytes`
- `latency_guard_bytes`
- `interactive_rtt_slack_ms`

### 1.5 Orchestration Rewrite

The old Android tunnel bring-up shape was replaced with a typed orchestration flow.

Implemented files:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeBinaryInstaller.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ProcessRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`

Behavior changes:

- `TunnelOrchestrator` now loads typed forward contracts
- runtime startup branches by forward mode instead of always entering bond startup
- runtime plans are launched through reusable runner utilities
- `sing-box` config generation is now driven from the selected runtime context

### 1.6 `sing-box` Runtime-Context Rewrite

`SingboxConfigBuilder` was refactored so it builds from a runtime contract instead of an underspecified listen-port list.

Implemented files:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- runtime-context helpers used by the new orchestration path

Behavior changes:

- proxy-node selection is forward-aware
- route bypass generation is runtime-aware
- generated `sing-box` config now understands the selected local runtime topology

### 1.7 Android Runtime Assets

Android now packages the needed runtime binaries for the `arm64-v8a` target inside app assets.

Packaged assets:

- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/wstunnel`
- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-ingress`
- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-bond`

These assets are installed into an app-owned runtime directory by `RuntimeBinaryInstaller` on first use.

## 2. Test Coverage Added

Android unit test areas added or materially extended:

- typed contract parsing
- forward filtering
- `single` runtime planning
- `bond` runtime planning
- `sing-box` config generation from runtime context

Main test groups referenced in verification:

- `com.leikwan.bridge.runtime.ForwardFilterTest`
- `com.leikwan.bridge.api.PanelModelsContractParsingTest`
- `com.leikwan.bridge.runtime.SingleForwardRunnerTest`
- `com.leikwan.bridge.runtime.BondForwardRunnerTest`
- `com.leikwan.bridge.singbox.SingboxConfigBuilderTest`

## 3. Build / Tooling Changes

### 3.1 Gradle test scaffolding

Added Android unit-test scaffolding in:

- `leikwan-android/app/build.gradle.kts`

Key additions:

- `junit:junit:4.13.2`
- `org.jetbrains.kotlinx:kotlinx-coroutines-test:1.8.1`

### 3.2 Android runtime build prerequisites

Resolved the missing Android runtime build chain locally by installing Android NDK:

- `27.1.12297006`

Command used:

- `sdkmanager.bat 'ndk;27.1.12297006'`

### 3.3 Rust Android runtime builds

Built current-source Android runtimes for `aarch64-linux-android`:

- `cargo build --release -p leikwan-ingress --target aarch64-linux-android`
- `cargo build --release -p leikwan-bond --target aarch64-linux-android`

## 4. Build Artifacts State

Current Android APK outputs present in the workspace:

- `leikwan-android/app/build/outputs/apk/debug/app-debug.apk`
- `leikwan-android/app/build/outputs/apk/release/app-release.apk`

Current packaged runtime asset state present in the app tree:

- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/wstunnel`
- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-ingress`
- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-bond`

Current Android app version metadata in Gradle:

- `versionCode = 1`
- `versionName = "0.1.0"`

## 5. Verified Commands Run

The following commands were explicitly run and used as verification during the Android execution work:

- `./gradlew.bat :app:compileDebugKotlin`
- `./gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.runtime.ForwardFilterTest" --tests "com.leikwan.bridge.api.PanelModelsContractParsingTest"`
- `./gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.runtime.SingleForwardRunnerTest" --tests "com.leikwan.bridge.runtime.BondForwardRunnerTest"`
- `./gradlew.bat :app:testDebugUnitTest --tests "com.leikwan.bridge.singbox.SingboxConfigBuilderTest"`
- `./gradlew.bat :app:testDebugUnitTest :app:assembleDebug`
- `cargo build --release -p leikwan-ingress --target aarch64-linux-android`
- `cargo build --release -p leikwan-bond --target aarch64-linux-android`

End-of-session verification recorded in `progress.md`:

- `:app:testDebugUnitTest :app:assembleDebug`
  - passed

## 6. Product / Operational Notes

### 6.1 What Android should do now

Android is now positioned to behave like a Linux-aligned `single` / `bond` client instead of a bond-only prototype.

The important product changes are:

- Android no longer advertises unsupported `mptcp` forwards as runnable
- Android now has a real `single` runtime planning path
- Android `bond` runtime planning uses Linux-style panel contract data
- Android `sing-box` config generation is derived from runtime context, not guessed from partial data
- Android now carries the required local executables in app assets for `arm64-v8a`

### 6.2 Known remaining gap

The major unresolved validation gap is unchanged:

- no real device/emulator tunnel smoke test was run in this session

So the shipped state is strong at the parsing/planning/build layer, but still needs live runtime validation against an actual Android device or emulator before claiming full end-to-end tunnel proof.

### 6.3 Practical next checks

Recommended next Android checks:

1. install the APK on a real `arm64-v8a` device or emulator
2. verify asset extraction for:
   - `wstunnel`
   - `leikwan-ingress`
   - `leikwan-bond`
3. verify login and catalog pull against the current panel
4. verify:
   - one `single` forward end-to-end
   - one `bond` forward end-to-end
5. validate generated `sing-box` config and traffic flow with a real selected forward

## 7. Main Files To Revisit Next Time

If Android work resumes, the highest-value files to revisit first are:

- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/SingleForwardRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeBinaryInstaller.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ProcessRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- `leikwan-android/app/build.gradle.kts`
