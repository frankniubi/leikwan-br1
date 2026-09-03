# Android CN -> International and Standard Outbound Handoff

Date: 2026-09-04 (work performed 2026-09-03/04, Asia/Taipei)
Scope: Android only; current `master` checkout; Mainland China -> international direction.
Status: PARTIAL. Ordinary Ultra local runtime is fixed, passes an AVD provider round-trip,
and the controlled-signature QA artifact is published;
native outbound remains blocked by the Java/native AAR gate, and full connected acceptance
remains unaccepted.

## Purpose

Continue the Android CN -> international work from the Windows native outbound and
CN-only DNS handoffs. Verify the emulator QA exception, standard outbound build
surface, native outbound UI/config coverage, and the Java/native `libbox.aar`
compatibility boundary without using an old worktree or overwriting unrelated work.

## Workspace guard

- Workspace: `C:\Users\frankniubi\Downloads\leikwan-br1`
- Branch: `master`
- This follow-up started from current HEAD `cf1e043bcce2948303e25eccb1bdd81d5f12a05c`
  (`fix(android): publish cache-busting signed QA URL`), not an older worktree.
- Baseline used for Android verification: `565a25ed2fa494454a3facc5a4171e9b9b1d3516`
  (`fix(panel): accept native forward form payload`). The scoped Android test
  change was committed as `d65a1b43b0307326a34dd0bed0ea1aa9a9350403`.
- The checkout was already dirty. Unrelated changes and artifacts were preserved.
- No worktree switch, reset, clean, production `DeviceSecurity` weakening, production
  manifest update, or replacement of `app/libs/libbox.aar` was performed. A separate
  static QA artifact was published after local verification; it is not a production
  Android release.

## Actual source change

The earlier enrollment test change remains unchanged. This follow-up also changes the
ordinary Android Ultra selection path and adds bounded Android runtime evidence:

- `leikwan-android/app/src/test/java/com/leikwan/bridge/api/AndroidEnrollmentRepositoryTest.kt`
  - injects a stable `identityPublicKeyProvider` fixture because Robolectric does
    not provide the AndroidKeyStore provider;
  - uses `BuildConfig.VERSION_NAME` instead of a stale hard-coded version;
  - asserts the current `/api/client/devices/identity/enroll` endpoint and
    `identity_public_key` request field.

- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/ProxySelectionPolicy.kt`
  restores the pre-31-August `singleNodeForServices` contract. Ordinary Ultra with a
  selected protocol node now keeps exactly that node; Ultra with no selected node keeps
  the raw fixed-target bridge path.
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
  passes `proxyOptional = true` only when ordinary Ultra has no selected node and passes
  `singleNodeForServices = true` for ordinary Ultra.
- `leikwan-android/app/src/test/java/com/leikwan/bridge/service/ProxySelectionPolicyTest.kt`
  covers the selected-node regression.
- `leikwan-android/app/src/androidTest/java/com/leikwan/bridge/singbox/NativeOutboundConfigRuntimeTest.kt`
  verifies both ordinary Ultra route shapes with the packaged libbox runtime.
- `leikwan-android/app/src/androidTest/java/com/leikwan/bridge/runtime/UltraRuntimeProcessSmokeTest.kt`
  starts the packaged x86_64 ordinary Ultra process and performs a local provider
  handshake/control-lease/TCP payload round-trip; cleanup is bounded and does not use a
  production lease.

Root cause confirmed against pre-31-August commit `1460bfdf`: `a163c2b5` changed the
ordinary Ultra call to `proxyOptional = selectedEffective?.mode == ULTRA` and removed
`singleNodeForServices`. A selected protocol node was therefore treated as optional and
was omitted from the sing-box config. The fix restores the old behavior without changing
the native outbound path.

## JVM verification

Command:

```text
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --no-daemon --max-workers=1 --rerun-tasks --console=plain --info
```

Result: **PASS**

- 293 tests
- 0 failures
- 0 errors
- 0 skipped
- 64 JUnit XML suites

Evidence:

- `artifacts/android/android-ultra-fix-20260904/android-jvm-final-20260904.log`
- `artifacts/android/android-ultra-fix-20260904/android-jvm-count-final-20260904.txt`
- `artifacts/android/android-jvm-20260904-ultra-fix.log` (earlier run)
- `leikwan-android/app/build/test-results/testDebugUnitTest/` (64 XML suites)

Final XML count: `files=64 tests=293 failures=0 errors=0 skipped=0`.

## Ordinary Ultra verification

The final focused checks are separate from the previously aborted 17-test UTP run:

- Go runtime: `go test -count=1 ./...` **PASS** (`2.151s`), evidence
  `artifacts/android/android-ultra-fix-20260904/go-test-20260904-nocache.log`.
- AVD config/runtime check: `NativeOutboundConfigRuntimeTest` **2/2 PASS**. It calls
  `Libbox.checkConfig` for the no-node bridge and selected-node adapter, and asserts
  `127.0.0.1:30522`, final tag `proxy-7`, and exactly one protocol adapter. Evidence:
  `artifacts/android/android-ultra-fix-20260904/instrument-native-config-final-20260904.txt`.
- AVD packaged process/data check: `UltraRuntimeProcessSmokeTest` **2/2 PASS**. The
  second test injects an ordinary `leikwan-ultra-policy-v2` lease over the real Unix
  control socket, accepts the current handshake in a local provider fixture, and echoes
  the payload after the runtime's XOR transform. Evidence:
  `artifacts/android/android-ultra-fix-20260904/instrument-ultra-runtime-final2-20260904.txt`.
- AVD: `LeiKwan_QA_API34` / `emulator-5556`, ABI `x86_64,arm64-v8a`, package
  `com.leikwan.bridge` version `0.2.18` code `20`. No `FATAL EXCEPTION`, `SIGABRT`,
  `BridgeOptions`, or `ClassNotFoundException` appeared in the focused logcat. Full
  evidence: `artifacts/android/android-ultra-fix-20260904/logcat-ultra-runtime-final2-20260904.txt`
  and `signed-launch-logcat-final-20260904.txt`.

These checks prove the packaged ordinary runtime and Android config/selection wiring;
they do not prove a live panel lease or production provider line. The older real-device
evidence in `docs/handoffs/2026-08-28-android-ultra-data-path-handoff.md` still records
the provider path stalling after 1,733 bytes, so account-backed traffic remains
**UNPROVEN** until rerun with a current active lease.

## QA APK and emulator

QA build command:

```text
cd leikwan-android
.\gradlew.bat :app:assembleDebug -Pqatest=true -PandroidX86=true --no-daemon --max-workers=1 --rerun-tasks --console=plain
```

Result: **PASS** (`BUILD SUCCESSFUL`)

QA APK:

- Path: `artifacts/android/android-qa-20260903-final/app-debug-qa-20260904.apk`
- Size: 102,647,220 bytes
- SHA-256: `512D0FEFC40842E7E935F98F273F0DDFB2246127BD0FD9CAE34D25BF800A128A`
- Version: `0.2.18`, version code `20`

QA `BuildConfig` evidence (`leikwan-android/app/build/generated/source/buildConfig/debug/com/leikwan/bridge/BuildConfig.java`):

```text
BUILD_TYPE = "debug"
VERSION_CODE = 20
VERSION_NAME = "0.2.18"
ALLOW_QA_TEST_DEVICE_EXCEPTIONS = true
PANEL_URL = "https://v3.leikwanhost.com"
```

AVD smoke:

- AVD: `LeiKwan_QA_API34`, serial `emulator-5556`
- ABI: `x86_64,arm64-v8a`; `ro.boot.qemu=1`
- APK install returned `Success`.
- UI contains `LeiKwan Bridge`, `Enroll this trusted device`, `Agent Token`,
  and `Client ID`; it does not contain `Unsupported device`.
- Smoke logcat contains no `FATAL EXCEPTION`, `SIGABRT`, `BridgeOptions`, or
  `ClassNotFoundException` marker for this launch.
- The release/default build flag remains false when `-Pqatest=true` is absent;
  this pass did not relax production emulator blocking.

Evidence:

- `artifacts/android/android-qa-build-20260904-final.log`
- `artifacts/android/android-qa-20260903-final/install-20260904.txt`
- `artifacts/android/android-qa-20260903-final/ui-20260904.xml`
- `artifacts/android/android-qa-20260903-final/qa-smoke-evidence-20260904.txt`
- `artifacts/android/android-qa-20260903-final/logcat-qa-smoke-20260904.txt`
- `artifacts/android/android-qa-20260903-final/tombstones-final-20260904.txt`

### Ordinary Ultra fix artifact

The reproducible QA package was rebuilt after the ordinary Ultra fix:

- Unsigned APK: `artifacts/android/android-ultra-fix-20260904/app-debug-qa-20260904-ultra-fix-unsigned.apk`
  (102,647,348 bytes, SHA-256 `C46FA1CA67433B5A31EC8E4FE63F767FC5D766EA85ACB94E757863685E5CC193`).
- Installable controlled-signature APK:
  `artifacts/android/android-ultra-fix-20260904/app-debug-qa-20260904-ultra-fix-unstable-signed.apk`
  (102,696,514 bytes, SHA-256 `7BA5465D59BB7D886855753F50412E8669638B773360C7EB6AE9E551FBABC1A6`).
- Instrumentation APK: `artifacts/android/android-ultra-fix-20260904/app-debug-androidTest-20260904-ultra-fix.apk`
  (1,084,539 bytes, SHA-256 `F55EAE90EF583B740F6ABF83316B8EC61389DF22FCB467A26A98EE6426A1DF14`).
- BuildConfig: debug QA, version `0.2.18` / code `20`,
  `ALLOW_QA_TEST_DEVICE_EXCEPTIONS=true`.
- Signature: `CN=LeiKwan Android Unstable`; certificate SHA-256
  `2fde6e1ec7142a2e800ee21880d7b0a163857de6985c2def5ad095aad1a68388`.
- `apksigner verify` v2/v3 and both Android artifact verifiers: **PASS**. The
  signed APK was installed on `emulator-5556` after removing the old debug-signed
  package; `adb install` returned `Success`.

Evidence:

- `artifacts/android/android-ultra-fix-20260904/artifact-manifest-final-20260904.txt`
- `artifacts/android/android-ultra-fix-20260904/signed-artifact-evidence-final-20260904.txt`
- `artifacts/android/android-ultra-fix-20260904/apksigner-verify-final-20260904.txt`
- `artifacts/android/android-ultra-fix-20260904/signed-install-final-20260904.txt`
- `artifacts/android/android-ultra-fix-20260904/verify-native-signed-final-20260904.txt`
- `artifacts/android/android-ultra-fix-20260904/verify-ultra-signed-final-20260904.txt`

The first public QA APK was a debug-signed package (`Android Debug`, SHA-256
`512D0FEFC40842E7E935F98F273F0DDFB2246127BD0FD9CAE34D25BF800A128A`). It shared
the production `applicationId` but not the production signing certificate, so
Android correctly rejected an in-place install with `package conflicts with an
existing package`. A QA derivative was re-aligned and signed with the existing
controlled `LeiKwan Android Unstable` certificate (v2/v3 verified):

- Local signed package:
  `artifacts/android/android-qa-20260903-final/app-qa-unstable-signed-20260904.apk`
- Signed package SHA-256: `500878B8738EFA888C6EC1A76BD7D685637CFE0D3694BCC62D8788DB0B5E5EAA`
- Signed package size: `102696514` bytes
- AVD proof: formal release package installed, then signed QA package installed
  with `adb install -r -d`; both returned `Success`. The public re-download of
  the signed package also installed with `Success`.

The emulator shell cannot read `/data/tombstones` (`Permission denied`). Therefore
this is a tombstone-access limitation, not a claim that no tombstone exists.

## AAR Java/native hard gate

The original tracked AAR was preserved byte-for-byte:

- Path: `leikwan-android/app/libs/libbox.aar`
- Size: 28,757,787 bytes
- SHA-256: `706C139FC1C5F0366494135FCF1D857F899D62965D19734EBC56AF4FF532CCB0`

`classes.jar` inspection:

- `io/nekohasekai/libbox/Libbox.class`: present
- `io/nekohasekai/libbox/SetupOptions.class`: present
- `io/nekohasekai/libbox/TunOptions.class`: present
- `io/nekohasekai/libbox/BridgeOptions.class`: **absent (count 0)**
- Total `io/nekohasekai/libbox` classes: 121

Native slices and APK merge comparison:

| ABI | AAR `libbox.so` | APK merged `libbox.so` | SHA-256 |
| --- | ---: | ---: | --- |
| `arm64-v8a` | 42,140,160 bytes | 42,140,160 bytes | `FA958BAD539BA5C1CB32465447D4DCDFDD7CA40D4FA77D8DB72305EA0ECD7A3D` |
| `x86_64` | 44,846,912 bytes | 44,846,912 bytes | `22E87EE85B20F22D64C2896984C939552432A411E96E7784EC5D062E95ECB175` |

The `.so` hashes matching does not make the Java binding complete. No complete
matching gomobile AAR containing the required `BridgeOptions` binding was rebuilt.
The available temp probe and single-ABI AAR variants also lack that class and were
not copied into the project.

Historical AVD evidence retained in
`artifacts/android/android-qa-20260903-final/aar-bridgeoptions-gate-20260904.txt`
records:

```text
java.lang.ClassNotFoundException: Didn't find class "io.nekohasekai.libbox.BridgeOptions"
JNI NewGlobalRef called with pending exception
signal 6 (Aborted)
```

Decision: **AAR hard gate FAIL**. Native/runtime acceptance remains blocked even
though the current QA smoke launch itself did not trigger that class path.

Static checks that did pass (these do not override the hard gate):

- `scripts/verify-android-native-artifacts.ps1`: PASS
- `scripts/verify-android-ultra-artifact.ps1`: PASS
- The QA APK has both required ABIs and paired Ultra JNI/runtime hashes.

Evidence:

- `artifacts/android/android-qa-20260903-final/aar-java-classes-20260904.txt`
- `artifacts/android/android-qa-20260903-final/aar-inspect/`
- `artifacts/android/android-qa-20260903-final/apk-inspect-20260904/`
- `artifacts/android/android-qa-20260903-final/verify-native-artifacts-20260904-final.txt`
- `artifacts/android/android-qa-20260903-final/verify-ultra-artifact-20260904-final.txt`

## Connected Android tests

Command attempted:

```text
cd leikwan-android
.\gradlew.bat :app:connectedDebugAndroidTest -Pqatest=true -PandroidX86=true --no-daemon --max-workers=1 --rerun-tasks --console=plain
```

Status: **ABORTED / FAIL at the Gradle-UTP harness level; not accepted as PASS**.

Observed facts:

- Gradle output stopped at `Tests 8/17 completed` and has no final `Finished` or
  `BUILD SUCCESSFUL` line for this invocation.
- UTP reported
  `recordTestResultEvent failed with an error: io.grpc.StatusRuntimeException: UNAVAILABLE: io exception`.
- No connected Gradle/UTP/instrumentation process remained at the final check;
  the emulator and adb were intentionally left running.
- The result directory has no trustworthy final XML for this invocation.
- The raw runner logcat for runner pid `19293` shows `run started: 17 tests` and
  later `run finished: 17 tests, 0 failed, 0 ignored`, followed by
  `Failure reporting to instrumentation watcher`. This is useful diagnostic
  evidence only; it is not a Gradle/UTP acceptance result.
- The raw log lists the CN -> international UI test
  `mainlandToInternationalCarrierShowsInternationalOnlyScopeSelector` as
  completed, but this is UI-only and does not prove a lease, TUN, or traffic path.

Evidence:

- `artifacts/android/android-qa-20260903-final/connected-tests.txt`
- `artifacts/android/android-qa-20260903-final/connected-evidence.txt`
- `artifacts/android/android-qa-20260903-final/connected-test-names.txt`
- `artifacts/android/android-qa-20260903-final/connected-process-status-20260904.txt`
- `leikwan-android/app/build/outputs/androidTest-results/connected/debug/LeiKwan_QA_API34(AVD) - 14/utp.0.log`
- `artifacts/android/android-qa-20260903-final/logcat-final-20260904.txt`

Native connected/runtime is therefore **BLOCKED by the AAR hard gate and the
missing connected harness result**. No further connected/runtime run should start
until a matching AAR is rebuilt and verified first.

## Standard outbound status

The regular outbound code path compiled as part of the same Android build and is
covered by the JVM contract/configuration suite. There was no account-backed
standard outbound traffic run in this pass, and no physical-device TCP/UDP,
VPN/TUN, renewal, or leak-capture evidence was produced. Standard outbound is
therefore **build/config PASS, end-to-end traffic UNPROVEN**.

## Native CN -> international status

The existing model/UI contract supports the international-only carrier shape and
the connected raw runner reached the corresponding UI test. The actual native
SOCKS5 lease, OpenGFW exception behavior, TUN route table, DNS path, TCP/UDP
traffic, renewal, and revoke lifecycle were not accepted because the Java/native
AAR gate failed. Native CN -> international is **BLOCKED, not released**.

## Remaining actions

1. Build one complete gomobile AAR from the same sing-box/libbox source and commit
   its generated Java stubs and both native ABI slices together. The class table
   must include `io/nekohasekai/libbox/BridgeOptions` (and the full callback/API
   surface used by the native library).
2. Verify the candidate AAR before installing it: class table, ABI ELF headers,
   AAR/APK SHA-256 pairing, and a focused `Libbox.checkConfig` probe.
3. Install only that verified candidate on the disposable AVD and rerun
   `connectedDebugAndroidTest`; require a final Gradle XML result, logcat without
   JNI/class-loading aborts, and readable tombstone/bugreport evidence.
4. With valid panel credentials and a verified carrier, test standard outbound and
   native CN -> international separately: lease acquisition, TUN, DNS/OpenGFW
   exception policy, TCP and UDP, renewal, revoke, and post-stop secret cleanup.
5. Re-run the artifact verification scripts and update this handoff with explicit
   PASS/FAIL evidence before any formal Android release publication or deployment.

## Deploy and rollback

- Latest ordinary Ultra fix artifact (published 2026-09-04 after the local AVD
  verification above):
  - Cache-busting URL:
    `https://v3.leikwanhost.com/artifacts/files/native-outbound-test-20260904-ultra-fix-signed/android-ultra-cn-to-intl-qa-20260904.apk`
  - Remote path:
    `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/public/artifacts/native-outbound-test-20260904-ultra-fix-signed/android-ultra-cn-to-intl-qa-20260904.apk`
  - Local source:
    `artifacts/android/android-ultra-fix-20260904/app-debug-qa-20260904-ultra-fix-unstable-signed.apk`
  - Source, remote, and HTTP re-download SHA-256:
    `7BA5465D59BB7D886855753F50412E8669638B773360C7EB6AE9E551FBABC1A6`
  - Size: `102696514` bytes; HTTP: `200 OK`; `Content-Length: 102696514`.
  - Remote install mode is `0644`; publication used a temporary upload and an
    atomic move. Any pre-existing same-name target was copied to the remote
    `/root/codex-backups/android-ultra-fix-20260904/` directory first.
  - Publication evidence and byte-for-byte HTTP re-download:
    `artifacts/android/android-ultra-fix-20260904/remote-publish-ultra-fix-evidence-20260904.txt`
    and `remote-download-ultra-fix-20260904.apk`.
- This package is signed `CN=LeiKwan Android Unstable`, version `0.2.18` /
    code `20`, and carries `ALLOW_QA_TEST_DEVICE_EXCEPTIONS=true`. It is for a
    disposable QA AVD/device; it does not update `artifacts/versions.json`.
  - The HTTP re-download was installed on `emulator-5556` with `adb install -r -d`
    (`Success`), and the package reported certificate SHA-256
    `2fde6e1ec7142a2e800ee21880d7b0a163857de6985c2def5ad095aad1a68388`.
    Evidence: `remote-download-install-20260904.txt`,
    `remote-download-package-20260904.txt`, and
    `remote-download-launch-logcat-20260904.txt`.

- Earlier native QA package (historical, retained for rollback/reference):
  - A QA-only Android package was published as a new static artifact (no formal
    updater/production manifest change):
  - Canonical cache-busting URL:
    `https://v3.leikwanhost.com/artifacts/files/native-outbound-test-20260904-signed/android-native-cn-to-intl-qa-20260904.apk`
  - The original URL was also replaced with the signed bytes, but its immutable
    cache key may still serve the earlier debug package to existing clients:
    `https://v3.leikwanhost.com/artifacts/files/native-outbound-test-20260904/android-native-cn-to-intl-qa-20260904.apk`
  - Remote path:
    `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/public/artifacts/native-outbound-test-20260904-signed/android-native-cn-to-intl-qa-20260904.apk`
  - Local source: `artifacts/android/android-qa-20260903-final/app-qa-unstable-signed-20260904.apk`
  - Source, remote, and HTTP re-download SHA-256:
    `500878B8738EFA888C6EC1A76BD7D685637CFE0D3694BCC62D8788DB0B5E5EAA`
  - Source, remote, and HTTP re-download size: `102696514` bytes
  - HTTP verification: `200 OK`, `Content-Length: 102696514`
  - Remote staging file was installed with mode `0644`, then removed; the new
    signed target replaced the debug QA file atomically.
  - The canonical target is a hard link to the signed target under
    `native-outbound-test-20260904`; it does not duplicate APK storage.
  - The previous debug QA bytes remain at the remote rollback path:
    `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/public/artifacts/native-outbound-test-20260904/android-native-cn-to-intl-qa-20260904.debug-original-20260904.apk`
- This package is a debug QA build with
  `ALLOW_QA_TEST_DEVICE_EXCEPTIONS=true`. It is suitable for disposable AVD
  testing only; native outbound remains blocked by the `BridgeOptions` AAR gate.
- `artifacts/versions.json` and the formal `android_client_apk` release entry
  were intentionally left unchanged. No production Android update was claimed.
- Post-deploy read-only inspection shows the server's pre-existing
  `versions.json` is already older than this checkout: remote SHA-256
  `88377FD217D9413AE1744F5C51F1817F7A06BB0D4B2927F91D5208778A0D1FA7`, with
  `android_client_apk` version `0.2.14` / code `16`; the local file is version
  `0.2.18` / code `20`. This deploy did not reconcile that drift because the
  only newly published package is a debug QA build and the native AAR gate is
  still failing.
- Publication evidence and the byte-for-byte re-download are recorded in
  `artifacts/android/android-qa-20260903-final/remote-publish-evidence-20260904.txt`
  and `remote-download-signed-20260904.apk`.
- The tracked `libbox.aar` remains at SHA-256
  `706C139FC1C5F0366494135FCF1D857F899D62965D19734EBC56AF4FF532CCB0`.
- The ordinary Ultra source/test change is committed as
  `3f21e6a3` (`fix(android): restore ordinary ultra protocol adapter selection`).
  Roll back only that commit if required; do not clean the pre-existing dirty
  checkout or delete the evidence directories. The earlier enrollment test change
  remains in its separate commit `d65a1b43`.
