# Android Native Lease Phase 2 Handoff

Date: 2026-09-04
Scope: Android only, current `master` checkout, Mainland China -> international native outbound.
Deploy status: no Panel deployment and no public artifact upload.

## Purpose

Make the Android native lease path accept the verified live wire shape without
guessing a direction or silently binding a capability to another forward. The
live contract used by this phase is:

- `forward_id=180`
- `direction=CAN -> HKG`
- `route_scope=global`
- `transport=tcp+udp`
- `protocol=leikwan-ultra-native-socks5-v1`
- restricted route policy by default
- v1 scope digest for restricted leases; v2 remains covered for unrestricted leases

## Workspace guard

- Workspace: `C:\Users\frankniubi\Downloads\leikwan-br1`
- Branch: `master`
- Existing commits `542e4832` and `fb0942fb` were not reverted.
- The checkout was already dirty. Windows, macOS, Panel, and unrelated files
  were left untouched and are not part of the Android change set.
- `artifacts/versions.json` was not modified. Its SHA-256 remained
  `a216930cc3beb2c111f0a1425edbe5f92d19c96cffdfb057537e79bc25ac68b3`.

## Implemented changes

### Direction binding

- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/NativeOutboundModels.kt`
  adds `nativeDirectionsMatch`. It normalizes only surrounding whitespace and
  case; blank or unknown direction values do not get a default direction.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
  rejects a capability whose carrier direction does not match the selected
  forward with `native_forward_direction_mismatch`, before requesting a lease.

### Regression coverage

- The existing test `native pull capability and lease request stay redacted and
  scoped` remains the `159 / Hong Kong -> China Mainland / cn_only` scenario:
  two leases, `selection_mode=auto`, and `max_candidates=4` are still asserted.
  The only fixture addition is `forward_combination_direction`, required by the
  new fail-closed binding check.
- A separate test `live cn to international lease binds forward 180 and uses
  restricted v1 global contract` covers the live `180 / CAN -> HKG / global`
  response, request fields, v1 digest, forward/direction binding, one lease,
  and credential wipe.
- A negative test proves a mismatched carrier direction is rejected rather than
  falling back to the old Hong Kong -> China Mainland label.
- Existing unrestricted coverage remains and includes the v2 digest path.

## Version and release artifact

`leikwan-android/app/build.gradle.kts` was changed from `versionCode=20`,
`versionName="0.2.18"` to `versionCode=21`, `versionName="0.2.19"`.

Canonical release artifact:

- APK: `artifacts/android/android-native-lease-phase2-20260904/android-native-lease-phase2-release.apk`
- Acceptance JSON: same path with `.acceptance.json`
- Version: `0.2.19` / `21`
- Size: `88,858,971` bytes
- SHA-256: `77b6f6ef5524a366247a50a0a4e5209420eaadea094c8d7dbeccc55265aaa78e`
- Signature: v2=true, v3=true; signer DN `CN=LeiKwan Android Unstable, O=LeiKwan`
- Signer certificate SHA-256:
  `2fde6e1ec7142a2e800ee21880d7b0a163857de6985c2def5ad095aad1a68388`
- ABI: `arm64-v8a`, `x86_64`
- Release `BuildConfig`: `BUILD_TYPE=release`, `DEBUG=false`,
  `ALLOW_QA_TEST_DEVICE_EXCEPTIONS=false`, `VERSION_CODE=21`,
  `VERSION_NAME=0.2.19`

The old artifact was preserved and is not the formal entry:

- `artifacts/android/android-native-lease-phase2-20260904/android-native-lease-phase2-release-v0218.apk`
- Version `0.2.18` / `20`
- SHA-256: `671f1a6b3849fa0f5f407bb1e852f6d1d74cf152ce58142d3db838d73e12feb4`

Final verification evidence:

- `artifacts/android/android-native-lease-phase2-20260904/release-verification-final-v0219-20260904.txt`
- `artifacts/android/android-native-lease-phase2-20260904/android-native-lease-phase2-release.apk.acceptance.json`
- `artifacts/android/android-native-lease-phase2-20260904/release-package-canonical-v0219-20260904.log`

The three Android verifiers all returned exit code 0:

- `verify-android-native-artifacts.ps1`
- `verify-android-ultra-artifact.ps1`
- `verify-android-build-version.ps1`

The release APK contains paired Ultra JNI/runtime assets. The relevant hashes
are `f9f5bf5e01288e41202ae7c0f89c7b0334d77efac137b45a264fa7a9737129c2` for
arm64-v8a and `813b315cfeba9c57fb039e0f21b6f3111b9515ad4d2ee6343f495d2d3d074743`
for x86_64.

## JVM verification

Command:

```text
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --offline --no-daemon --max-workers=1 --rerun-tasks --console=plain
```

Result: **PASS**

```text
files=64 tests=297 failures=0 errors=0 skipped=0
```

The repository regression class was also run independently: **4 tests, 0
failures, 0 errors, 0 skipped**. Evidence:

- `artifacts/android/android-native-lease-phase2-20260904/android-jvm-after-coverage-restore-v0219-20260904.log`
- `artifacts/android/android-native-lease-phase2-20260904/android-jvm-after-coverage-restore-v0219-count-20260904.txt`
- `artifacts/android/android-native-lease-phase2-20260904/repository-regression-after-restore-20260904.log`

## Disposable AVD verification

AVD: `LeiKwan_QA_API34`, serial `emulator-5556`, API 34, ABI
`x86_64,arm64-v8a`. The QA app and test APK installed successfully and
reported `versionCode=21`, `versionName=0.2.19`.

Focused instrumentation was bounded to 90 seconds per class and returned real
process exit code 0 using `Process.WaitForExit`:

- `NativeOutboundConfigRuntimeTest`: **2/2 PASS**
- `UltraRuntimeProcessSmokeTest`: **2/2 PASS**

Evidence:

- `artifacts/android/android-native-lease-phase2-20260904/instrumentation-summary-v0219-accurate-20260904.txt`
- `artifacts/android/android-native-lease-phase2-20260904/instrument-native-config-v0219-accurate-20260904.txt`
- `artifacts/android/android-native-lease-phase2-20260904/instrument-ultra-runtime-v0219-accurate-20260904.txt`
- `artifacts/android/android-native-lease-phase2-20260904/logcat-focused-relevant-v0219-20260904.txt`
- `artifacts/android/android-native-lease-phase2-20260904/logcat-focused-markers-v0219-20260904.txt`

The submitted logcat summary (with the full raw capture retained locally at
`artifacts/android/android-native-lease-phase2-20260904/logcat-focused-v0219-20260904.txt`)
has no `FATAL EXCEPTION`, `SIGABRT`, `signal 6`,
`ClassNotFoundException`, or `UnsatisfiedLinkError`. The tombstone query was
attempted and returned permission denied; tombstone absence is therefore not
claimed:

- `artifacts/android/android-native-lease-phase2-20260904/tombstone-query-v0219-20260904.txt`

The disposable AVD was stopped after the run. No emulator/qemu/Gradle test
process remained.

## Java/native AAR hard gate

The unchanged local AAR was checked before and after the focused run:

- `leikwan-android/app/libs/libbox.aar`
- Size: `28,757,787` bytes
- SHA-256: `706c139fc1c5f0366494135fcf1d857f899d62965d19734ebc56af4ff532ccb0`
- `io/nekohasekai/libbox/BridgeOptions.class`: **0**
- `io/nekohasekai/libbox/Libbox.class`: 1
- `io/nekohasekai/libbox/SetupOptions.class`: 1
- JNI arm64-v8a SHA-256:
  `fa958bad539ba5c1cb32465447d4dcdfdd7ca40d4fa77d8db72305ea0ecd7a3d`
- JNI x86_64 SHA-256:
  `22e87ee85b20f22d64c2896984c939552432a411e96e7784ec5d062e95ecb175`

Evidence: `artifacts/android/android-native-lease-phase2-20260904/aar-bridgeoptions-gate-v0219-20260904.txt`.

Verdict: **FAIL for full native libbox acceptance**. The focused config/runtime
tests do not exercise the missing Java binding, so their PASS must not be
expanded into a native TUN/BridgeOptions acceptance claim. Historical AVD
evidence already records the corresponding `ClassNotFoundException`/signal 6
failure boundary.

## Status matrix

| Area | Status | Evidence boundary |
| --- | --- | --- |
| Direction-bound Android lease selection | PASS | JVM live `180/CAN -> HKG/global` test |
| Legacy `159/cn_only` multi-candidate coverage | PASS | Existing test remains and passes with 2 leases |
| Restricted v1 request/digest and credential wipe | PASS | Repository, lease client, and JVM assertions |
| Unrestricted v2 path | PASS | Existing unrestricted JVM test |
| Release version/signature/ABI | PASS | acceptance JSON and three verifiers |
| Focused ordinary/native config instrumentation | PASS | disposable AVD, 4/4 tests |
| Full native libbox/TUN runtime | FAIL/BLOCKED | `BridgeOptions.class` missing from AAR |
| Live provider traffic and end-to-end TUN | UNPROVEN | requires matching AAR and live device/lease run |

## Remaining actions

1. Produce a single matching gomobile/libbox AAR containing Java stubs and native
   slices, specifically including `BridgeOptions.class`; do not replace the
   current AAR without hash and class/slice verification.
2. Re-run the focused AVD tests and then the bounded connected native/TUN suite
   with the matching AAR, capturing logcat and accessible tombstone evidence.
3. Validate a real short lease (`180`, `CAN -> HKG`, `global`, `tcp+udp`) through
   native runtime traffic and renew/revoke behavior. This phase made no Panel
   deployment and no public upload.
