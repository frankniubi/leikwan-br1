# Native Outbound Acceptance Runbook

## Purpose

This runbook is the acceptance gate for the native outbound feature described
in `docs/superpowers/specs/2026-09-01-native-outbound-ultra-carrier-design.md`.
It produces reproducible evidence for the Panel contract, Android x86_64 VM,
and the existing Windows/macOS test machines.

## 0. Worktree Freshness Gate

Run this before changing implementation code. The current repository `master`
is the baseline; documentation-only commits made for this feature are included
in the baseline.

```powershell
$root = 'C:\Users\frankniubi\Downloads\leikwan-br1'
$master = (git -C $root rev-parse refs/heads/master).Trim()
git -C $root worktree list --porcelain

# Compare the known client worktrees without changing any of them.
$worktrees = @(
  "$root\.worktrees\android-final-compatible",
  "$root\.worktrees\android-host-vnext",
  "$root\.worktrees\macos-client",
  "$root\.worktrees\ultra-policy-v2",
  "$root\.claude\worktrees\windows-workflow-parity",
  "$root\.claude\worktrees\android-routing-workflow",
  'C:\tmp\lkb-android-renewal'
)
foreach ($wt in $worktrees) {
  if (!(Test-Path "$wt\.git")) { continue }
  $head = (git -C $wt rev-parse HEAD).Trim()
  $ahead = [int](git -C $root rev-list --count "$master..$head")
  $behind = [int](git -C $root rev-list --count "$head..$master")
  $latest = git -C $wt log -1 --format='%ci|%H|%s'
  $dirty = @(git -C $wt status --porcelain)
  "$wt|ahead=$ahead|behind=$behind|dirty=$($dirty.Count)|$latest"
}
```

Decision rule:

1. A worktree is **newer** only when its latest relevant commit timestamp is
   later than `master` and its scoped diff contains implementation code.
2. A branch that is merely `ahead` by commit count but has an older timestamp,
   or is also behind `master`, is divergent historical WIP and is not used as
   the implementation baseline.
3. Dirty worktrees are read-only comparison inputs. Do not cherry-pick or copy
   their files without an explicit scoped diff review.
4. Record the selected baseline, all relevant worktree paths, counts, dates,
   and the decision in the final handoff. No credentials are included.

For the 2026-09-01 run, the selected baseline is `master` at the feature plan
commit. The Android worktrees end on 2026-08-29 or earlier, the macOS worktree
on 2026-08-28, and the Windows parity worktree on 2026-06-01; none is newer
than `master`. The Windows parity worktree has uncommitted WIP and remains
untouched.

## 1. Required Build Artifacts

The Android acceptance APK must contain both ABIs:

| Artifact | arm64-v8a | x86_64 | Verification |
|---|---:|---:|---|
| `libbox.aar` | `jni/arm64-v8a/libbox.so` | `jni/x86_64/libbox.so` | `tar -tf` |
| `ultra-client` executable asset | `assets/runtime/arm64-v8a/ultra-client` | `assets/runtime/x86_64/ultra-client` | ELF `e_machine` 183/62 |
| `libultra_client.so` | `jniLibs/arm64-v8a/libultra_client.so` | `jniLibs/x86_64/libultra_client.so` | ELF `e_machine` 183/62 |

Build from the selected baseline:

```powershell
cd C:\Users\frankniubi\Downloads\leikwan-br1
.\scripts\build-ultra-android.ps1 -Version 2026.09.01.1 -Architectures arm64,amd64
.\scripts\build-nekobox-libcore.ps1 -Targets 'android/arm64,android/amd64'
cd leikwan-android
.\gradlew.bat :app:assembleDebug -Pqatest=true -PandroidX86=true
cd ..
```

The build must fail if either ABI is missing. Record APK SHA-256, size, Gradle
task output, and the following checksums without recording any lease data:

```powershell
$apk = 'leikwan-android/app/build/outputs/apk/debug/app-debug.apk'
Get-FileHash $apk -Algorithm SHA256
tar -tf leikwan-android/app/libs/libbox.aar | Select-String 'jni/(arm64-v8a|x86_64)/libbox.so'
Get-ChildItem leikwan-android/app/src/main/assets/runtime -Recurse -File |
  Where-Object { $_.Name -eq 'ultra-client' } |
  Get-FileHash -Algorithm SHA256
```

## 2. Temporary Android VM

The local machine already has the API 34 x86_64 AVD `LeiKwan_QA_API34` and
Android SDK under `%LOCALAPPDATA%\Android\Sdk`. Launch a non-persistent,
read-only emulator instance so the existing AVD is not modified:

```powershell
$sdk = "$env:LOCALAPPDATA\Android\Sdk"
$serial = 'emulator-5556'
$emu = Start-Process -FilePath "$sdk\emulator\emulator.exe" -ArgumentList @(
  '-avd', 'LeiKwan_QA_API34', '-port', '5556', '-read-only',
  '-no-snapshot-load', '-no-snapshot-save', '-no-boot-anim', '-no-window'
) -PassThru
$adb = "$sdk\platform-tools\adb.exe"
& $adb -s $serial wait-for-device
do { Start-Sleep -Seconds 2; $boot = (& $adb -s $serial shell getprop sys.boot_completed).Trim() } while ($boot -ne '1')
& $adb -s $serial shell getprop ro.product.cpu.abilist
```

Pass criteria: the serial is `emulator-5556`, `sys.boot_completed` is `1`, and
`ro.product.cpu.abilist` contains `x86_64`. The test script must install the
APK only on this serial and stop `$emu` in a `finally` block:

```powershell
try {
  & $adb -s $serial install -r $apk
  if ($LASTEXITCODE -ne 0) { throw 'apk_install_failed' }
} finally {
  & $adb -s $serial emu kill 2>$null
  if (!$emu.HasExited) { Stop-Process -Id $emu.Id -Force }
}
```

## 3. Panel Entitlement and GUI Gate

Use a disposable test plan and an assigned active `intl_to_cn` Ultra forward.
Supply client credentials through local environment variables or the existing
credential files; never put them in a command transcript or handoff.

| Case | Action | Evidence | Pass |
|---|---|---|---|
| Denied | Pull config with plan flag absent/false | redacted JSON hash + UI screenshot | `native_outbound` absent and no `原生出口` control |
| Allowed | Enable only `allow_native_outbound` on plan, pull again | redacted JSON + UI screenshot | capability present, `原生出口` visible |
| Carrier | Select the returned default forward | screenshot/UI dump | direction label matches the Ultra forward direction |
| Scope | Use an international-to-mainland-China carrier | screenshot/UI dump | `仅中国大陆` and `全局` are visible, default is `全局` |
| No node | Keep local proxy/node store empty | Room/profile count + screenshot | native start action remains enabled |
| Revoked | Disable plan or revoke generation, pull/lease again | HTTP status/code with secrets removed | no new lease; mode returns to normal service state |

The same visibility cases are run on Windows and macOS using the already
available test machines. Their host names and credentials are not recorded in
the repository.

## 4. Android VM Functional Checks

Install the QA APK on the temporary emulator and run the app with a test client.
Capture `uiautomator dump` after pull and after selecting native mode:

```powershell
& $adb -s $serial shell uiautomator dump /sdcard/native-ui.xml
& $adb -s $serial shell cat /sdcard/native-ui.xml | Select-String '原生出口'
& $adb -s $serial logcat -c
```

Perform these checks in order:

1. Select `原生出口` and the default carrier. Confirm the node list is empty
   and the carrier direction is the only provider-independent status shown.
2. For an international-to-mainland-China carrier, select `仅中国大陆` and
   verify mainland-China destinations use native SOCKS5 while a non-CN test
   destination is direct. Confirm the bypass-China switch is disabled/hidden.
3. Select `全局` and verify both destinations use native SOCKS5 when bypass-
   China is off. Turn bypass-China on and verify only mainland-China
   destinations become direct.
4. Request VPN permission, start the tunnel, and wait for the connected state.
5. Verify `dumpsys connectivity` contains the app VPN and `dumpsys vpn` shows a
   running VPN owner. Save both outputs after removing account/device IDs.
6. Generate one TCP request to the controlled test endpoint and one UDP DNS
   request. Record status code, byte counts, and timestamps from the test
   endpoint; do not record SOCKS credentials.
7. Wait through one renewal window. Confirm the tunnel stays connected and the
   lease endpoint is never printed in UI/log output.
8. Stop the tunnel and verify no native credential remains in `filesDir`,
   `shared_prefs`, Room databases, APK static assets, or diagnostics.

Required evidence files (all redacted):

- `android-apk.sha256`
- `android-x86_64-abi.txt`
- `android-ui-denied.xml` and `android-ui-allowed.xml`
- `android-vpn-before-stop.txt` and `android-vpn-after-stop.txt`
- `android-tcp-udp-result.json` (addresses and credentials removed)
- `android-logcat-redacted.txt`
- `android-storage-secret-scan.txt`

## 5. Windows/macOS Checks

Run on the existing test machines; no new machine provisioning is part of this
acceptance.

For each platform:

1. Pull an entitled capability and confirm the GUI shows `原生出口`.
2. Start native proxy mode with no imported profile; test HTTP/TUN according to
   the platform's existing connection modes.
3. For an international-to-mainland-China carrier, test both `仅中国大陆`
   and `全局`; in `全局`, toggle China-IP bypass and repeat the route check.
4. Revoke the plan, pull, and verify the option disappears and the running
   native process is stopped after the current lease expires.
5. Scan process arguments, temporary configs, profile stores, and logs for
   `leigod`, source IDs, line IDs, and raw credentials; all scans must be empty.

Evidence is a timestamped pass/fail table plus redacted screenshots and helper
hashes. Do not copy credentials or machine-specific secrets into the repo.

## 6. Final Gate

The feature is accepted only when all of the following are true:

- Panel focused tests and all existing Ultra regression tests pass.
- Android APK packages arm64-v8a and x86_64 `libbox` and runtime artifacts.
- The temporary x86_64 VM boots, installs, starts TUN, passes TCP+UDP, renews,
  and cleans up.
- Windows and macOS GUI visibility and no-node native startup pass on their
  existing test machines.
- Entitlement-off behavior is invisible and fail-closed.
- The final handoff records the worktree freshness decision, exact artifact
  hashes, test commands/results, unproven items, and rollback steps without
  credentials.
