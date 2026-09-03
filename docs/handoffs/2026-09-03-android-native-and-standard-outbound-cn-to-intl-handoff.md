# Android CN -> International and Standard Outbound Handoff

Date: 2026-09-04 (work performed 2026-09-03/04, Asia/Taipei)
Scope: Android only; current `master` checkout; Mainland China -> international direction.
Status: PARTIAL / BLOCKED. JVM and QA smoke are green. Native outbound and connected acceptance are not accepted.

## Purpose

Continue the Android CN -> international work from the Windows native outbound and
CN-only DNS handoffs. Verify the emulator QA exception, standard outbound build
surface, native outbound UI/config coverage, and the Java/native `libbox.aar`
compatibility boundary without using an old worktree or overwriting unrelated work.

## Workspace guard

- Workspace: `C:\Users\frankniubi\Downloads\leikwan-br1`
- Branch: `master`
- Baseline used for Android verification: `565a25ed2fa494454a3facc5a4171e9b9b1d3516`
  (`fix(panel): accept native forward form payload`). The scoped Android test
  change was committed as `d65a1b43b0307326a34dd0bed0ea1aa9a9350403`.
- The checkout was already dirty. Unrelated changes and artifacts were preserved.
- No worktree switch, reset, clean, production `DeviceSecurity` weakening, server
  deployment, or replacement of `app/libs/libbox.aar` was performed.

## Actual source change

Only this tracked Android test file was changed:

- `leikwan-android/app/src/test/java/com/leikwan/bridge/api/AndroidEnrollmentRepositoryTest.kt`
  - injects a stable `identityPublicKeyProvider` fixture because Robolectric does
    not provide the AndroidKeyStore provider;
  - uses `BuildConfig.VERSION_NAME` instead of a stale hard-coded version;
  - asserts the current `/api/client/devices/identity/enroll` endpoint and
    `identity_public_key` request field.

No Android production source was changed in this pass. The existing QA-only device
exception and CN -> international scope/UI implementation were compiled as-is.

## JVM verification

Command:

```text
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest --no-daemon --max-workers=1 --rerun-tasks --console=plain --info
```

Result: **PASS**

- 292 tests
- 0 failures
- 0 errors
- 0 skipped
- 64 JUnit XML suites

Evidence:

- `artifacts/android/android-jvm-20260903-final.log`
- `artifacts/android/android-jvm-20260904-count.log`
- `artifacts/android/android-qa-20260903-final/jvm-count-20260904.txt`
- `leikwan-android/app/build/test-results/testDebugUnitTest/` (64 XML suites)

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

- A QA-only Android package was published as a new static artifact (no formal
  updater/production manifest change):
  - URL: `https://v3.leikwanhost.com/artifacts/files/native-outbound-test-20260904/android-native-cn-to-intl-qa-20260904.apk`
  - Remote path:
    `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/public/artifacts/native-outbound-test-20260904/android-native-cn-to-intl-qa-20260904.apk`
  - Local source: `artifacts/android/android-qa-20260903-final/app-debug-qa-20260904.apk`
  - Source, remote, and HTTP re-download SHA-256:
    `512D0FEFC40842E7E935F98F273F0DDFB2246127BD0FD9CAE34D25BF800A128A`
  - Source, remote, and HTTP re-download size: `102647220` bytes
  - HTTP verification: `200 OK`, `Content-Length: 102647220`
  - Remote staging file was installed with mode `0644`, then removed; the new
    target did not replace an existing artifact.
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
  and `remote-download-20260904.apk`.
- The tracked `libbox.aar` remains at SHA-256
  `706C139FC1C5F0366494135FCF1D857F899D62965D19734EBC56AF4FF532CCB0`.
- To roll back the test-only source change, revert only the diff in
  `AndroidEnrollmentRepositoryTest.kt`; do not clean the pre-existing dirty
  checkout or delete the evidence directories.
