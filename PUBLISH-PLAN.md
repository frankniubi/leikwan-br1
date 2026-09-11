# Android HA Runtime Publish Plan (Task D)

Prepared by: android-ha agent (worktree task-android-ha-20260911, branch feat/android-ha-runtime)
Status: NOT PUBLISHED. This file is the hand-off for the parent agent to merge and publish together with the macOS agent's parallel versions.json changes. Do NOT upload to production from this worktree.

## 1. What was built (all in this worktree)

| Artifact | ABI | sha256 | size_bytes | version | notes |
|---|---|---|---|---|---|
| artifacts/ultra/ultra-client_android_arm64 | arm64-v8a | a5b10542c65c1fa62094bda87f187df111428d140256680c173eebba8f8c6360 | 14549252 | 2026.09.11.4 | CGO=0, garble v0.17.0 -literals -tiny, reproducible (double-build hash match) |
| artifacts/ultra/ultra-client_android_x86_64 | x86_64 | abfb38956c08d8c3beb221ee10555255388dc0e39e1fce105161490b58af247f | 16239072 | 2026.09.11.4 | CGO=1 (Go toolchain refuses internal linking for android/amd64), NDK 27.1.12297006 clang, garble v0.17.0, reproducible |
| artifacts/android/leikwan-android-client.apk | arm64-v8a + x86_64 | 97ba83259b64b810f01d60b775a4c845041e052a0e47f8b6f7b751f1d60a10a3 | 89424219 | 0.2.24 (version_code 26) | release build, zipaligned, apksigner v1+v2+v3 (keystore %USERPROFILE%\.android\leikwan-android-unstable-20260827.jks, alias leikwan-android-unstable) |

Both .so files replaced leikwan-android/app/src/main/jniLibs/<abi>/libultra_client.so and leikwan-android/app/src/main/assets/runtime/<abi>/ultra-client and are hash-paired inside the APK (verified).

Export-symbol comparison (old 09-04 vs new x86_64 .so): identical 60-symbol export surface (same libc/liblog/libdl imports, _cgo_topofstack/_cgo_panic/crosscall2, main.main). ELF hardening: PIE + BIND_NOW full RELRO + NX stack on x86_64; PIE + RELRO + NX on arm64.

## 2. versions.json diff (this worktree copy of artifacts/versions.json)

(a) android_client_apk changed:
  "version_code": 25 -> 26
  "sha256": "dc5caed055c3278c1bd54a14030201b7d37c17cb957d453273aa45a099e12bfc" -> "97ba83259b64b810f01d60b775a4c845041e052a0e47f8b6f7b751f1d60a10a3"
  "size_bytes": 88879451 -> 89424219
  "version": "0.2.23" -> "0.2.24"
  (path / artifact_url unchanged)

(b) New entries appended at the end of the file (after "wg_watchdog"):
  "ultra_client_runtime_android_arm64": architecture arm64, artifact_url /artifacts/ultra/ultra-client_android_arm64, path artifacts/ultra/ultra-client_android_arm64, hardening [cgo-disabled,pie,relro,nx-stack,trimpath,stripped,garble-identifiers,garble-literals,garble-tiny], obfuscator garble-v0.17.0, sha256 a5b10542c65c1fa62094bda87f187df111428d140256680c173eebba8f8c6360, size_bytes 14549252, version 2026.09.11.4
  "ultra_client_runtime_android_x86_64": architecture x86_64, artifact_url /artifacts/ultra/ultra-client_android_x86_64, path artifacts/ultra/ultra-client_android_x86_64, hardening [pie,relro,bind-now,nx-stack,trimpath,stripped,garble-identifiers,garble-literals,garble-tiny], note "android/amd64 requires cgo external linking (Go toolchain refuses internal linking); linked with NDK 27.1.12297006 clang", obfuscator garble-v0.17.0, sha256 abfb38956c08d8c3beb221ee10555255388dc0e39e1fce105161490b58af247f, size_bytes 16239072, version 2026.09.11.4

Exact diff available via: git diff -- artifacts/versions.json in worktree C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\task-android-ha-20260911

## 3. Upload plan (parent to execute; creds from leikwan-v3.txt, never printed)

Panel: root@173.208.183.93, webroot /www/wwwroot/v3.leikwanhost.com/leikwan-v3 (repo root == webroot repo tree; panel serves files by repo-relative path).

Upload order (binaries first, manifest LAST so the switch is atomic):
  1. scp artifacts/ultra/ultra-client_android_arm64     -> root@173.208.183.93:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/ultra/ultra-client_android_arm64
  2. scp artifacts/ultra/ultra-client_android_x86_64    -> root@173.208.183.93:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/ultra/ultra-client_android_x86_64
  3. scp artifacts/android/leikwan-android-client.apk   -> root@173.208.183.93:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/android/leikwan-android-client.apk
  4. scp artifacts/versions.json (MERGED with macOS agent's changes) -> root@173.208.183.93:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json
  Backup before replace, e.g. /root/codex-backups/android-ha-20260911/ (follow the linux-ha 2026-09-11 procedure).

Post-upload canary (hashes must match the table above):
  - GET https://v3.leikwanhost.com/artifacts/leikwan-android-client.apk (route exists: AndroidClientApk serves artifacts/android/leikwan-android-client.apk)
  - GET https://v3.leikwanhost.com/artifacts/versions.json must show android_client_apk v0.2.24/26 and the two ultra_client_runtime_android_* entries; panel recomputes sha/size from files, so this also validates the uploaded binaries.
  - /api/health HTTP 200.

## 4. Known gaps / notes for the parent

- No physical Android device attached to this machine (adb devices empty); smoke was run on the LeiKwan_QA_API34 x86_64 AVD instead (see section 5). Physical arm64 device TUN acceptance remains open.
- /artifacts/ultra/ultra-client_android_arm64 and _x86_64 have NO panel PHP routes yet (Bootstrap.php/ArtifactController only route linux/windows/macos ultra binaries). The Android app ships the runtime inside the APK and does not fetch these URLs, so nothing breaks today; if the parent wants the URLs to resolve, the macOS/panel agent should add routes (serveRepoFile('artifacts/ultra/ultra-client_android_arm64')) and optionally the LeigodOnlyArtifactSourceBuildMap entries.
- x86_64 .so must stay CGO=1: Go toolchain error "android/amd64 requires external (cgo) linking" with CGO_ENABLED=0. NDK 27.1.12297006 at %USERPROFILE%\Android\Sdk\ndk is required on the build machine. arm64 stays CGO=0.
- Rollback target: previous APK 0.2.23 sha256 dc5caed055c3278c1bd54a14030201b7d37c17cb957d453273aa45a099e12bfc (still available in git history / server backup).

## 5. QA evidence (this worktree)

- go test ./... -short in tools/ultra-client: PASS (ok leikwan.local/ultra-client 23.6s)
- build-ultra-android.ps1 reproducibility checks: PASS for both ABIs (double garble build hash match)
- verify-android-ultra-artifact.ps1: status ok, both ABIs hash-paired lib+asset
- verify-android-build-version.ps1: 0.2.24/26, release, qa exceptions off, required native classes present, 0 retired update classes
- verify-android-native-artifacts.ps1 (via packaging flow): PASS (libbox + ultra ELF machine checks)
- master/tests/android_client_artifact_manifest_test.php: ok
- apksigner verify --min-sdk-version 26: v2 true, v3 true
- Emulator smoke (qa-android-native-outbound.ps1 -SkipConnectedTests on LeiKwan_QA_API34): see artifacts/android/native-qa-*/evidence for install/launch/logcat/screenshot.
