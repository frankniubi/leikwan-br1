# PUBLISH-PLAN: macOS HA runtime rebuild (task C) — NOT YET PUBLISHED

Branch/worktree: `feat/macos-ha-runtime` / `.worktrees/task-macos-ha-20260911` (from master d27cc5fc).
Prepared by the macOS HA agent. **Do not upload to production before the parent agent merges
this plan with the parallel Android agent's versions.json changes.**

## 1. What changed

* Rebuilt darwin ultra-client runtimes from master HEAD `tools/ultra-client` with the exact
  2026.09.11.4 flag set: garble v0.17.0 `-literals -tiny`, `CGO_ENABLED=0`, `-buildmode=pie`,
  `-trimpath -buildvcs=false`, `-ldflags='-s -w -buildid= -X main.runtimeBuildVersion=2026.09.11.4.d27cc5fc'`,
  same-origin seed derivation as `scripts/build-ultra-linux.ps1` (seed `RFf01rFRPJE`).
  Reproducibility double-build verified. New script: `scripts/build-ultra-macos.ps1`.
* Repackaged `macos_client_update_arm64/x64` zips: extracted the 1.0.26 bundles, replaced
  `Contents/Resources/bin/ultra-client`, ad-hoc re-signed the whole bundle with rcodesign 0.29.0
  (two passes, manifest hashes refreshed between passes, determinism asserted), re-zipped
  preserving every original entry's Unix metadata. Info.plist stays 1.0.26.
* **The darwin runtime artifacts are the bundle-signed binaries.** Code-signing rewrites Mach-O
  bytes, and two hard contracts conflict otherwise:
  - panel test `master/tests/ultra_client_artifact_distribution_test.php` requires
    `ultra_client_runtime_macos_*.sha256 == sha256(artifact file)`;
  - `MacAppUpdateService` drift self-repair compares the INSTALLED bundle runtime
    (`Contents/Resources/bin/ultra-client`) against the same entry.
  Both hold only if the artifact file IS the signed binary that ships in the zip.
  Unsigned garble output hashes (for provenance): arm64 `65e7128e41f74a88fe65a049369926cf0301c833cc90bc7b636959bee7688095`,
  amd64 `673721896708cf320ac6fe44902f36324d40fef19512dc30808ab2c9800a6fdc`.

## 2. New artifact hashes (post-sign / as published)

| file | sha256 | size |
|---|---|---|
| artifacts/ultra/ultra-client_macos_arm64 | `04a22d295a0b2674e5cb7fb74e074d8e7d61b5c8a86e912a24d5bcfb61922884` | 13300640 |
| artifacts/ultra/ultra-client_macos_amd64 | `1373d771ab88277a10845f0bf54e31eb271c0fc5e20f8a2a744d1243ae92d02e` | 14999376 |
| artifacts/macos/leikwan-bridge-update-arm64.zip | `2d9f9808fab1431e855206651a52957ecb30167e5355a07a24fcdb528527e134` | 68821491 |
| artifacts/macos/leikwan-bridge-update-x64.zip | `72853740ed4b2099e1bf2beedf1543a828acc77b719c4ef6b87a3a13f342ef04` | 72828224 |

Runtime `--version`: `2026.09.11.4.d27cc5fc` (verified via an identical-flag windows probe build).

## 3. versions.json diff (worktree artifacts/versions.json vs master)

```diff
  "macos_client_update_arm64": {
-    "sha256": "e6211e27c4e383e41a5cae380d1a8989b0aceea4e816c8ef747c8bdb1b66ab52",
-    "size_bytes": 68417230,
+    "sha256": "2d9f9808fab1431e855206651a52957ecb30167e5355a07a24fcdb528527e134",
+    "size_bytes": 68821491,
+    "runtime_sha256": {
+      "bin/sing-box": "d2957fe7fa43ff12e4e9f0ca31ec66958039cab167c1444e8003cd22ce52f401",
+      "bin/wstunnel": "c4418dc79a16f96866cc11a0217a2924ee8f15f034302240c561fd2457b6c847",
+      "bin/leikwan-bond": "48b0177f646640b1a1f9cf7a83c017606d317adb566e514a541b1f4505c71f43",
+      "bin/leikwan-ingress": "e17e13dba582666f471d22da09564e60a1de7c6511b08532406e060d3c1b4088",
+      "bin/leikwan-uot-bond": "3da21e499c96f42b32378e4e29a609dd4b8d1d0fe5cd77fd88cdb93cd664eb42",
+      "bin/ultra-client": "04a22d295a0b2674e5cb7fb74e074d8e7d61b5c8a86e912a24d5bcfb61922884"
+    },
     "version": "1.0.26"
   },
  "macos_client_update_x64": {
-    "sha256": "f1af62c3498af999643a41eb6e282301b21f5138d6e156a81985512b8bfa5d9d",
-    "size_bytes": 72693812,
+    "sha256": "72853740ed4b2099e1bf2beedf1543a828acc77b719c4ef6b87a3a13f342ef04",
+    "size_bytes": 72828224,
+    "runtime_sha256": {
+      "bin/sing-box": "2f7a306f8271ff22d9fa51e6a826b4afb07fa2008e1935c37c78a5eccf90fa7e",
+      "bin/wstunnel": "d11f073c9e6529ee1173bc40909d9bc04dfe7b9ff5b13bd74a93dcc513af3683",
+      "bin/leikwan-bond": "2615531442dc930f86488e5e8e1512c941f2231d0cbb9233543e88a1f672b5c5",
+      "bin/leikwan-ingress": "a77b21b826444d5e23bb34c78881efb997cfb50f4f486898f5a7d22a24fc3453",
+      "bin/leikwan-uot-bond": "b8161db20dcc52b2269d7cc946b94166a20e7baf7c30dad646d334202095a7cb",
+      "bin/ultra-client": "1373d771ab88277a10845f0bf54e31eb271c0fc5e20f8a2a744d1243ae92d02e"
+    },
     "version": "1.0.26"
   },
  "ultra_client_runtime_macos_x64": {
+    "build": "go1.26.5 darwin/amd64, cgo-disabled, pie, stripped, garble-v0.17.0, udp-buffers-2026.09.11.4.d27cc5fc",
-    "sha256": "da71638061c9c2a0cd038a4883e0dc398a8499af1a1edc3202871d711c3d30cf",
-    "size_bytes": 14658176,
+    "sha256": "1373d771ab88277a10845f0bf54e31eb271c0fc5e20f8a2a744d1243ae92d02e",
+    "size_bytes": 14999376,
     "version": "2026.09.11.4"
   },
  "ultra_client_runtime_macos_arm64": {
+    "build": "go1.26.5 darwin/arm64, cgo-disabled, pie, stripped, garble-v0.17.0, udp-buffers-2026.09.11.4.d27cc5fc",
-    "sha256": "a808cbd335d5a5fbe96a6c38fcee9c49f0ec9e7695ebab8e4df160338c5d60e0",
-    "size_bytes": 13161938,
+    "sha256": "04a22d295a0b2674e5cb7fb74e074d8e7d61b5c8a86e912a24d5bcfb61922884",
+    "size_bytes": 13300640,
     "version": "2026.09.11.4"
   },
```

macos_client_app_arm64/x64 (DMGs) are intentionally unchanged — the DMGs were not rebuilt.
New DMG installs keep the old runtime but the drift self-repair pulls the new zip on first check.

## 4. Why the update version stays 1.0.26 (no bump)

* `App.axaml.cs` reports `currentVersion` from the MANAGED assembly version (1.0.26).
* `MacBundleValidator` requires zip `Info.plist` version == manifest artifact version.
* Bumping the manifest to 1.0.27 without a managed rebuild would make every client download
  and apply the update forever (assembly stays 1.0.26 < 1.0.27 after apply).
* Runtime-only content rotation precedent: commit 18c4ecf3 kept `windows_client_app` at
  0.1.20.28 and only rotated runtime fingerprints; drift self-repair delivers the payload.
  Version bumps happen when managed code changes (e.g. dac779fa 1.0.25 -> 1.0.26).

## 5. Upload plan (for the parent agent; NOT executed)

Panel: `root@173.208.183.93`, webroot `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`,
credentials in `leikwan-v3.txt` (never echo). Use `sshpass.exe` from the repo root if needed.

Files to upload (relative to webroot):
1. `artifacts/ultra/ultra-client_macos_arm64`
2. `artifacts/ultra/ultra-client_macos_amd64`
3. `artifacts/macos/leikwan-bridge-update-arm64.zip`
4. `artifacts/macos/leikwan-bridge-update-x64.zip`
5. `artifacts/versions.json` (LAST, after all sha256 checks pass)

Suggested sequence (reference only; follow the existing panel release flow: backup -> stage ->
sha256 verify -> atomic replace -> versions.json last -> dual-entry canary):

```bash
# 0) backup (on server)
mkdir -p /root/codex-backups/macos-ha-runtime-20260911
cp -a <webroot>/artifacts/versions.json <webroot>/artifacts/macos \
     <webroot>/artifacts/ultra /root/codex-backups/macos-ha-runtime-20260911/

# 1) stage (from the worktree)
scp artifacts/ultra/ultra-client_macos_arm64 \
        artifacts/ultra/ultra-client_macos_amd64 \
        artifacts/macos/leikwan-bridge-update-arm64.zip \
        artifacts/macos/leikwan-bridge-update-x64.zip \
        root@173.208.183.93:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/.stage-macos-ha/

# 2) verify sha256 against the table in section 2 (server-side)
sha256sum .stage-macos-ha/*

# 3) atomic replace into webroot, then versions.json last
# 4) dual-entry (Cloudflare + Aliyun) canary: fetch each artifact, compare sha256,
#    GET /api/health, and confirm client-manifest.json exposes the new hashes.
```

Merge warning: the parallel Android agent is editing the same `artifacts/versions.json`.
Merge my four hunks (macos_client_update_* + ultra_client_runtime_macos_*) with its
android_client_apk hunks before publishing; never publish either half alone.

## 6. Blockers / risks

* **No real-Mac verification possible from this host.** The bundle is ad-hoc re-signed with
  rcodesign 0.29.0 (deterministic, idempotent, seal audited) exactly like the historical 1.0.8
  Windows-built release flow. `codesign --verify --deep --strict` on a real Mac and launch of
  the staged bundle are the only remaining acceptance items (blocked on a Mac).
  No Apple notarization was ever applied to this app (historical releases are ad-hoc too).
* DMGs not rebuilt: `macos_client_app_*` entries and DMG files are unchanged (old runtime).
  Fresh DMG installs self-heal via drift, but consider a Mac-built DMG refresh later.
* darwin artifacts are now SIGNED binaries (see section 1). `scripts/build-ultra-macos.ps1`
  alone regenerates the unsigned build; re-run the repackage flow to re-derive the signed
  artifact + zip (scripts are under worktree `.tmp/macos-ha-20260911/`).
* Sibling observation (not part of this change): linux `client-agent/install_forward_runtime.py`
  still passes `udp_queue_packets: 64`; the 11.4 doc claims three-platform host sync to 256 but
  the linux agent was missed. Mac and Windows hosts are at 256. Parent may want a follow-up.

## 7. Verification already performed

* `go test ./... -short` in tools/ultra-client: PASS (23.6s).
* `dotnet test` LeikwanBridge.Mac.Tests: 152/152 PASS (Windows host).
* garble reproducibility: double build byte-identical (both archs).
* Version injection probe: identical-flag windows build prints `2026.09.11.4.d27cc5fc`.
* Repackaging audit per zip: 241 entries, helper manifest self-consistent, seal hash2 == runtime,
  embedded runtime bytes == published artifact bytes, zip CRC clean, no __MACOSX.
* verify-macos-dmg-layout.py-equivalent checks on the extracted bundle: PASS (both archs).
* Panel PHP tests in the worktree: ultra_client_artifact_distribution_test.php,
  macos_client_artifact_manifest_test.php, macos_client_install_surface_test.php — all `ok`.
* Drift convergence: `macos_client_update_*[runtime_sha256][bin/ultra-client]` == the
  `ultra_client_runtime_macos_*` entry sha256 == the artifact file sha256.
