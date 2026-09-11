# macOS client 1.0.27 release (managed rebuild + DMG/update-zip refresh)

Release date: 2026-09-12 (panel publish 2026-09-11T20:00Z)
Branch: `release/macos-1.0.27` (worktree `.worktrees/task-macos-1027-release`, base master `990cf477`)

## Why

The 2026-09-11 macOS release (`e788e3e0`) only rotated the embedded
`ultra-client` runtime inside the 1.0.26 update zips. The managed assemblies were
still the 2026-09-10 build and therefore shipped without:

* `0d73ab6f` update progress UI + download-endpoint selection
* `dc9c6f25` macOS secret store default moved from login keychain to a private file
* `001311c7` panel/update traffic pinned to the physical NIC (TUN/proxy bypass)

1.0.27 also carries `add5a37d` (runtime-drift self-repair) and the pairing
workspace split from the 1.0.26 line.

## Artifacts

| artifact | version | sha256 | size |
|---|---|---|---|
| `artifacts/macos/leikwan-bridge-arm64.dmg` | 1.0.27 | `71514a9c11fbc6b701332336ff5fe98d6d12e7454dac9d5295baa52974ef142c` | 77329057 |
| `artifacts/macos/leikwan-bridge-x64.dmg` | 1.0.27 | `4453e86d3903e3f18f28e8c78f27b9a64c835e5e96d7511988b5e0bcd52ffa47` | 81660801 |
| `artifacts/macos/leikwan-bridge-update-arm64.zip` | 1.0.27 | `cc312714295fbbfe680efea155a5e28546827be14c5ec509c16a1806bc501bb4` | 68859262 |
| `artifacts/macos/leikwan-bridge-update-x64.zip` | 1.0.27 | `4f98de5a231772da6ee75bc221309ea87dffd84693923af6d2265b3ae4d8189f` | 72866018 |

Embedded `Contents/Resources/bin/ultra-client` (both bundles) stays byte-identical
to the published runtime artifacts:

* arm64 `04a22d295a0b2674e5cb7fb74e074d8e7d61b5c8a86e912a24d5bcfb61922884`
* amd64 `1373d771ab88277a10845f0bf54e31eb271c0fc5e20f8a2a744d1243ae92d02e`

## Build

* Windows host, `dotnet publish -c Release -r osx-arm64|osx-x64 --self-contained true -p:Version=1.0.27`
  (SDK 8.0.419, runtime packs 8.0.25), apphost from the project's
  `CreateMacBundleAppHost` target (`../Resources/dotnet/LeikwanBridge.Mac.dll`).
* `scripts/build-macos-client-bundle.py` (new) extracts the committed 1.0.26
  bundle, swaps `Contents/Resources/dotnet/`, the apphost and
  `bin/ultra-client`, bumps `Info.plist` to 1.0.27, ad-hoc signs twice with
  `rcodesign 0.29.0` (`--timestamp-url none`), refreshing
  `helper-manifest.json` between passes and asserting signature determinism,
  then rebuilds the zip preserving the original entry order and Unix metadata.
* DMGs were produced on a real macOS 26.6.2 arm64 VM with
  `hdiutil create -volname 'LeiKwan Bridge' -srcfolder <dmg-root> -ov -format UDZO`
  from the signed `.app` + `Applications -> /Applications` symlink (same shape as
  the previous Mac-built DMGs), then downloaded back into `artifacts/macos/`.

## Verification

* `scripts/verify-macos-update-zip.py`: install surface ok, seal `hash2` matches the
  embedded runtime, 6 helpers self-consistent, zip CRC clean, no `__MACOSX`, both archs.
* Real-Mac (macOS 26.6.2 arm64 VM): `hdiutil verify` valid, DMG mounts,
  `verify-macos-dmg-layout.py` ok for arm64/x86_64,
  `codesign --verify --deep --strict` "valid on disk / satisfies its Designated
  Requirement" for both bundles, `Signature=adhoc`, `TeamIdentifier=not set`,
  `spctl` rejected (expected, ad-hoc and not notarized).
* `LeikwanBridge.Mac.Tests`: 170/170 passed. Panel PHP tests
  (`macos_client_install_surface_test.php`, `macos_client_artifact_manifest_test.php`,
  `macos_client_download_page_regression_test.php`, `ultra_client_artifact_distribution_test.php`,
  `macos_client_ultra_policy_v2_contract_test.php`) all `ok`.

## Panel publish

Backup `/root/codex-backups/macos-1.0.27/` (old `versions.json` + 4 macos artifacts),
stage ``.stage-macos-1.0.27/` verified by `sha256sum -c`, atomic `mv` into
`artifacts/macos/` with `artifacts/versions.json` last. Dual-entry canary
(`v3.leikwanhost.com` + `api-leikwanbridge.nbwly.com`): full GET sha256 of all four artifacts equals the
published hashes and both `/artifacts/versions.json` and
`/artifacts/client-manifest.json` advertise 1.0.27.

Known pre-existing quirk (not release specific): through the Cloudflare entry
(`v3.leikwanhost.com`) `HEAD` alternates 404/200 for every artifact route
(untouched artifacts such as the Windows installer and the Android APK behave the
same, and `/artifacts/versions.json` answers 404 to HEAD); `GET`/`Range` are
healthy on both entry points.

## Real-Mac acceptance log (macOS 26.6.2 arm64 VM, user leikwan)

| item | result |
|---|---|
| `hdiutil verify` (both DMGs) | valid |
| mount + `verify-macos-dmg-layout.py` | `{"install_surface_ok": true, "version": "1.0.27", ...}` arm64 + x86_64, `Applications -> /Applications` |
| `codesign --verify --deep --strict --verbose=2` (mounted + installed) | "valid on disk" / "satisfies its Designated Requirement", exit 0 |
| `codesign -dv --verbose=4` | `Identifier=com.leikwan.bridge`, `Signature=adhoc`, `flags=0x2(adhoc)`, `TeamIdentifier=not set`, `Sealed Resources version=2 rules=13 files=231` |
| `spctl -a -vvv -t exec` | **rejected** (expected: ad-hoc, not Developer ID, not notarized) |
| `defaults read ... CFBundleShortVersionString` | 1.0.27 (CFBundleVersion 1.0.27) |
| installed `bin/ultra-client` sha256 | 04a22d29… (equals the published runtime artifact) |
| all six helper hashes | equal `macos_client_update_arm64.runtime_sha256` |
| `ultra-client --version` | 2026.09.11.4.d27cc5fc |
| `--ultra-leak-fixture` (headless managed probe) | exit 2 = pass |
| launch (`open -a`) / GUI acceptance | **blocked: the VM exposes no display device** (`system_profiler SPDisplaysDataType` empty, `Invalid display 0x00000000`, `CVDisplayLinkCreateWithCGDisplays error -6661` → Avalonia `RenderTimer` failure, app exits). No screenshot, no in-app update UI / download-source / keychain-prompt observation possible. |
| crash reports | the only new `.ips` (2026-09-12 03:58) belongs to the *pre-existing* hybrid 1.0.26 bundle that was installed on the VM (its `LeikwanBridge.Mac.dll` had been replaced with a 1.0.27 assembly while `LeikwanBridge.Core.dll` stayed 1.0.26, so it aborts with `FileNotFoundException`); no crash report was produced by the 1.0.27 build. |
| panel reachability from the VM | `versions.json` + `client-manifest.json` on both entry points return 1.0.27 with the published hashes; `artifact_url` is cache-busted with `v=1.0.27&sha256=…` |

Raw logs: `.tmp/macos-1027-release/evidence/{accept,dmg-build,diagnose,control,vm-panel-check}.log` and
`.tmp/macos-1027-release/evidence/LeikwanBridge.Mac-2026-09-12-035823.ips`.

## Residual risk

GUI acceptance on a real Mac could not be completed: the test VM has no display
device (`system_profiler SPDisplaysDataType` empty, `Invalid display 0x00000000`,
`CVDisplayLinkCreateWithCGDisplays error -6661`), so the Avalonia app exits right
after `RenderTimer` initialisation. The managed payload itself loads on real
macOS (`--ultra-leak-fixture` exits 2). No crash report was produced.