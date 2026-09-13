# macOS 1.0.32/1.0.33, Android 0.2.27, and the no-GUI clients

Date: 2026-09-14
Branch: master (local commits, not pushed)

## Why

1. The macOS and Android **apps** still embedded the pre-fix ultra runtime
   (2026.09.13.2-era). The runtime artifacts were already published at
   2026.09.13.3, but only the macOS *update zip* consumption path reads
   `ultra_client_runtime_macos_*` — and it refuses to run until the app bundle
   actually carries it. Shipping the runtime without shipping the app changed
   nothing for users.
2. Users asked for a **no-GUI** client on macOS and Windows (server-style
   installs, no desktop session, no tray).

## macOS

### The published runtime must be the *bundle-signed* binary

`scripts/build-macos-client-bundle.py` re-signs every nested Mach-O with
rcodesign, so the bytes of `Contents/Resources/bin/ultra-client` change during
bundle assembly (arm64 13576338 → 13581568, x64 15307024 → 15431952). The panel
enforces the attested self-hash, so the artifact registered in the manifest has
to be the post-signing binary, and the builder asserts exactly that
(`signed bundle runtime differs from published runtime artifact`).

The 2026.09.13.3 publish had signed **arm64 only**: x64 shipped unsigned
(`92508887…`), which would have been re-signed in the bundle and then failed
both the builder assertion and the panel's attestation check. Both
architectures are now bundle-signed and pinned:

| artifact | sha256 | size |
|---|---|---|
| `artifacts/ultra/ultra-client_macos_arm64` | `173a794c1b67ad779a8fa33ca66ce4f55da7672b782e65fe2c34bb665196f7ed` | 13581568 |
| `artifacts/ultra/ultra-client_macos_amd64` | `580cbb4892246ece97578e8913980452eb53a8b8acf2f8c5b50ca6584d944f8f` | 15431952 |

Both carry the `2026.09.13.3.452c8fe8` stamp, and
`attestation.self_sha256` in the manifest equals the embedded hash, so the
enforcement map accepts exactly what ships.

### 1.0.32

App payload rebuilt (222 managed files + apphost), runtime swapped to the signed
binaries, helper manifest and `_CodeSignature` seal refreshed:

| artifact | version | sha256 | size |
|---|---|---|---|
| `leikwan-bridge-update-arm64.zip` | 1.0.32 | `5d4a8ab09e4a948a…` | 69053486 |
| `leikwan-bridge-update-x64.zip` | 1.0.32 | `c49022bb8ebae3e4…` | 73075300 |
| `leikwan-bridge-arm64.dmg` | 1.0.32 | `57c6a025f513bbc7…` | 77592505 |
| `leikwan-bridge-x64.dmg` | 1.0.32 | `a4dd0d0171688f13…` | 81804145 |

Verified natively on the test Mac (macOS 26.6.2 arm64): `codesign --verify
--deep --strict` = 0 for the bundle and for both mounted DMGs,
`CFBundleShortVersionString` = 1.0.32, embedded runtime = the published
artifact, `Applications → /Applications` present.

### 1.0.33 — the no-GUI lifecycle

The windowless client is not a second client: `LeikwanBridge.Mac` gains
`--headless`, which boots the same Core services without ever initialising
Avalonia. Same lease handling, same heartbeat, same runtime attestation, same
update pinning, same bundle.

| artifact | version | sha256 | size |
|---|---|---|---|
| `leikwan-bridge-update-arm64.zip` | 1.0.33 | `58a66f923507f9a8…` | 69071717 |
| `leikwan-bridge-update-x64.zip` | 1.0.33 | `d35089249794f979…` | 73093533 |
| `leikwan-bridge-arm64.dmg` | 1.0.33 | `1d98d9943fd389cd…` | 78364643 |
| `leikwan-bridge-x64.dmg` | 1.0.33 | `120b6d7257f31a9b…` | 82067867 |

## Android 0.2.27

The APK was a version behind its own runtime tree: the 2026.09.13.3 Ultra
binaries were staged into `assets/runtime/*` and `jniLibs/*` but the published
APK was still 0.2.26/code 28.

| artifact | version | code | sha256 | size |
|---|---|---|---|---|
| `artifacts/android/leikwan-android-client.apk` | 0.2.27 | 29 | `29fa4495991b5168…` | 90284379 |

Verified: `apksigner verify` v2 + v3 + v3.1 true, `apkanalyzer` build contract
(release, non-debug, no QA device exceptions, no retired updater classes),
`verify-android-ultra-artifact` (both ABIs are ELF64 with matching JNI/asset
hashes: `1598f5ca…` arm64, `3b09eb7c…` x86_64).

Note: the packaging script's own guard refused the final acceptance write
("package flow modified artifacts/versions.json") because the macOS manifest
edit landed while the ~50 min Gradle build was running. The APK itself was
already copied and is verified above; the acceptance JSON was re-derived from
the published artifact.

## A real bug the 1.0.32 test caught

`MacAppUpdateServiceTests.PrepareAsync_RejectsSizeAndHashMismatches` failed:

```
Expected: typeof(InvalidDataException)
Actual:   typeof(IOException): artifact download did not complete after 64 attempts (24 bytes received)
```

`ResumableArtifactDownloader` treats "body ended early" as a resumable
transport failure. When the *server* serves an artifact shorter than the
manifest declares (exactly the truncated-publish class of failure this project
has hit before), the client hammered the panel 64 times and then reported a
generic IOException instead of `update_size_mismatch`.

Fix: an attempt that completes a full response *and* delivers no new bytes
counts as unproductive; two of those in a row return the short result so the
caller fails fast with `update_size_mismatch`. A genuinely interrupted
transfer still resumes, and the retry budget is unchanged for it.

## No-GUI clients

Design: one payload, two lifecycles.

| | macOS | Windows |
|---|---|---|
| entry | `LeikwanBridge.Mac --headless` (LaunchDaemon) | `leikwan-bridge-headless.exe` (service `LeikwanBridgeHeadless`) |
| payload | the shipped app bundle | 33.8 MB self-contained console build of the same Core stack |
| identity | agent token file (0600, root) | agent token file (SYSTEM + Administrators ACL) |
| status | `--status-file` JSON + log | same |
| stop | SIGTERM | SCM stop control |

Both build the same Core service graph the GUI builds (ProcessSupervisor,
ForwardRuntimeManager with the port preflight, ConfigPollingService,
ClientDiagnosticsService, HealthCheckService, WireGuardManager, HeartbeatService
with the Ultra policy-revocation handler). The Windows stack is additionally
guarded by a source-parity test, so a service that the GUI starts can never
silently disappear from the windowless client.

New published artifacts and installers:

| key | url | sha256 |
|---|---|---|
| `windows_headless_client` | `/artifacts/windows/leikwan-bridge-headless-win-x64.zip` | `a722911a77b0a1a5…` (33821040 B) |
| `macos_headless_installer` | `/artifacts/install-macos-headless.sh` | `5e254c9a3a73531d…` |
| `macos_headless_uninstaller` | `/artifacts/uninstall-macos-headless.sh` | `cc33d63fb46e9109…` |
| `windows_headless_installer` | `/artifacts/install-windows-headless.ps1` | `da1c0dae3410d590…` |
| `windows_headless_uninstaller` | `/artifacts/uninstall-windows-headless.ps1` | `13100350f85bc191…` |

The panel serves them, the tenant clients page links them (with copy-ready
commands), and `master/tests/headless_client_artifact_test.php` pins the surface
(manifest ↔ files ↔ routes ↔ no credential in an installer).

Shared values (panel endpoints, install root, shared state directory) moved to
`LeikwanBridge.Core.Services.ClientDefaults`; `AppDefaults` and the windowless
client both read them, so the two surfaces cannot disagree about where the
runtime lives or which panel they talk to.

## Verification

* macOS: strict codesign on both DMGs and the installed bundle, version and
  embedded runtime checked on the machine, first datagram/lease behaviour
  unchanged (same runtime).
* Android: signature schemes, build contract, ABI/hash pairing, manifest test.
* Panel: every changed artifact fetched back over HTTPS and hashed against both
  the manifest and the file on disk (`PUBLISH-OK`); `php -l` on every patched
  controller; Bootstrap patched by anchor (never whole-file) because the panel
  copy is authoritative for routes this checkout does not own.
* Suites: Core 509/509, App 111/111 (incl. 7 new no-GUI tests), Mac 222/222,
  panel contract tests green (headless, macos artifact/install surface, android,
  windows manifest, distribution, attestation).

## Follow-ups

* macOS/Windows no-GUI clients do not apply updates themselves yet: re-run the
  installer / replace the payload and restart. The client reports its version
  like any other client.
* The Windows no-GUI build does not install the system proxy or sing-box TUN;
  it keeps panel forwards (including Ultra and native-outbound routes) up.
* `client_install_simplification_regression_test.php`,
  `client_online_limit_wiring_regression_test.php`,
  `client_config_active_boundary_regression_test.php` and `oidc_client_test.php`
  still fail on pre-existing grounds unrelated to this work.
