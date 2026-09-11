# Desktop / Mobile HA Release 2026-09-11

Cross-platform release bundling five workstreams: update UX + download-source
selection, macOS keychain permission downgrade, macOS HA runtime, Android HA
runtime, and tool-agnostic direct panel egress (bypass any TUN/HTTP proxy).

## Branches merged (integration/desktop-ha-release-20260911)

| Branch | Commit | Scope |
|---|---|---|
| feat/update-ui-download-sources | 0d73ab6f | Windows + macOS update progress UI and per-user download source (Cloudflare / Aliyun CDN) |
| feat/mac-keychain-downgrade | dc9c6f25 | macOS secret store defaults to private file; keychain becomes opt-in |
| feat/macos-ha-runtime | e788e3e0 | Rebuilt darwin ultra runtimes 2026.09.11.4.d27cc5fc + repackaged update zips |
| feat/android-ha-runtime | 87f09278 | Rebuilt android .so (arm64-v8a + x86_64) 2026.09.11.4 + APK 0.2.24 (version_code 26) |
| feat/panel-direct-egress | 001311c7 | Panel/lease/update traffic always egresses the physical NIC (IP_UNICAST_IF / IP_BOUND_IF socket binding + host-route fallback + DoH) |
| (integration) | 21dece57 | client-agent linux udp_queue_packets 64 -> 256 (aligned with desktop hosts) |

## Update UX + download source (Windows / macOS)

- New shared progress model (Check / Download / Verify / Stage / Apply) wired
  through WindowsAppUpdateService and MacAppUpdateService via IProgress.
- Windows overlay and macOS banner show phase + percent + failure detail with
  Retry / Dismiss.
- Fixed cross-endpoint bug: panel manifest absolute artifact URLs were
  hardcoded to the Cloudflare origin even when the user selected the Aliyun
  mirror. Known-endpoint origins are now rebound to the selected endpoint
  (path + query preserved); custom CDN origins are left untouched.
- Endpoint switch persists immediately and applies to app updates and
  runtime-drift downloads. Default remains Cloudflare.

## macOS keychain permission downgrade

- Root cause of the first-launch "keychain access" prompt: deprecated classic
  Keychain Services API with NULL keychain (login keychain) and no ACL; the
  app binary not being in the item trusted-apps ACL triggers the authorization
  dialog.
- New default: private file store (secrets.json, 0600 file / 0700 dir, atomic
  writes). Keychain is opt-in via use_mac_keychain=true.
- One-time migration imports existing keychain secrets into the file store and
  removes the keychain items; worst case is one final authorization prompt at
  migration time.
- Related finding (not changed here): Mac VPN/TUN connection start uses
  osascript "administrator privileges" and prompts for the admin password per
  connection. Separate follow-up (e.g. SMAppService helper).

## macOS HA runtime

- Rebuilt darwin runtimes with the canonical 11.4 flags (garble v0.17.0
  -literals -tiny, CGO_ENABLED=0, -buildmode=pie, runtimeBuildVersion
  2026.09.11.4.d27cc5fc). Reproducible double-build verified.
- Artifacts are the bundle-signed binaries (rcodesign ad-hoc) so both the
  panel contract test (entry sha == artifact file) and MacAppUpdateService
  drift self-repair (installed bundle bytes) hold.
- Update zips rebuilt (version stays 1.0.26 on purpose: managed assembly
  version unchanged, avoiding the infinite-update loop; DMG untouched, new
  installs converge via drift self-repair).

  | Artifact | sha256 (12) | size |
  |---|---|---|
  | ultra-client_macos_arm64 | 04a22d295a0b | 13300640 |
  | ultra-client_macos_amd64 | 1373d771ab88 | 14999376 |
  | leikwan-bridge-update-arm64.zip | 2d9f9808fab1 | 68821491 |
  | leikwan-bridge-update-x64.zip | 72853740ed4b | 72828224 |

## Android HA runtime

- Rebuilt libultra_client.so for arm64-v8a (CGO=0) and x86_64 (CGO=1, NDK
  27.1.12297006; Go toolchain refuses internal linking for android/amd64).
  Export surface identical to the previous 09-04 build; PIE/RELRO/NX.
- APK 0.2.24 (version_code 26), release build, zipaligned. AVD API34 x86_64
  smoke passed; no physical device attached, so physical arm64 TUN acceptance
  remains open.

### Android signing key rotation (2026-09-11)

- The previous signing identity ("LeiKwan Android Unstable", created
  2026-08-27) was replaced by a proper release identity via apksigner v3 key
  rotation:
  - New key: `leikwan-android-release`, RSA 4096, 100-year validity,
    DN `CN=LeiKwan Bridge, O=LeiKwan, OU=Client Apps`,
    cert SHA-256 `700adc0ab7147cb00e1fec8c0b54db64533480bac4b9cd8fef19ef532963ecb4`.
  - Lineage: `leikwan-android/signing/leikwan-android-release-lineage.pem`
    (committed; required on every future build).
  - The legacy key co-signs the v1/v2 blocks via `--next-signer`, so
    Android 8-12 devices keep accepting in-place upgrades (they see the old
    cert); Android 13+ devices accept the new cert through the v3 lineage.
  - `scripts/package-android-native-test.ps1` now signs with release key +
    lineage + next-signer by default.
  - Keystores, password files and lineage backed up to
    `/root/codex-backups/android-signing-20260911/` on the panel host.
  - Rule: never sign with the legacy key alone again.

  | Artifact | sha256 (12) | size |
  |---|---|---|
  | ultra-client_android_arm64 | a5b10542c65c | 14549252 |
  | ultra-client_android_x86_64 | abfb38956c08 | 16239072 |
  | leikwan-android-client.apk (rotated) | aa7444b6b0ad | 89428315 |

## Direct panel egress (any TUN / proxy tool)

- Primary mechanism: kernel-level socket interface forcing on every panel
  connection — Windows IP_UNICAST_IF=31 / IPV6_UNICAST_IF=31, macOS
  IP_BOUND_IF=25 / IPV6_BOUND_IF=125 — implemented via
  SocketsHttpHandler.ConnectCallback. Tool-agnostic, no root required.
- Fallback: /32 (+/128) host routes through the physical gateway only while a
  TUN-like interface holds the default route; cleaned up on exit and network
  changes. route.exe needs elevation; degrades to socket-only otherwise.
- All panel host resolution goes through DoH (StrictDohResolver); DoH TCP
  itself is interface-bound. HTTP proxies are unreachable (UseProxy=false on
  panel kinds).
- Data-plane relay health probe moved to a new "Plain" client kind
  (UseProxy=false, normal OS routing) so the data plane is never forced
  off-TUN.
- Known limit: WFP kernel-driver per-process capture tools cannot be bypassed
  from user mode; mainstream route-table TUN tools are fully covered.

## Windows installer 0.1.20.29 (published 2026-09-11)

- Rebuilt the Windows client installer so it ships the desktop changes above
  (update progress UI, per-user download source, direct panel egress):
  - `artifacts/windows/leikwan-bridge-setup.exe`
    sha256 `60ba7defddaec4b26f2a34f9289f1d1bd46b2bc4d9a462f8a105c87ddf92c60d`,
    71,618,978 bytes, packaged app version 0.1.20.29, embedded
    ultra-client runtime 2026.09.11.4 (`17fc936a…`).
  - Built via `dotnet publish … -c Release -r win-x64 -p:SelfContained=true -o
    artifacts/windows/publish` followed by
    `scripts/build-windows-client-setup.ps1`. Note: do NOT use
    `-p:PublishSingleFile=true` for this app — it doubles the payload
    (155 MB single-file exe plus the component DLLs the packaging step also
    copies) and inflates the installer to ~119 MB.
  - `artifacts/versions.json` windows_client_app bumped to 0.1.20.29.
  - Published to the panel with backup at
    `/root/codex-backups/windows-installer-20260911-29/`; served sha256 and
    size verified on both the Cloudflare and Aliyun entrances.

## Verification

- Integration solution tests: Core 435/435, App 97/97, Mac 170/170.
- go test ./... -short (tools/ultra-client): pass.
- macOS zip integrity: 241 entries each, helper-manifest self-consistent,
  CodeResources seal hash2 == runtime, embedded runtime bytes == artifact,
  CRC clean; layout checks pass for both arches.
- Android: verify-android-ultra-artifact.ps1 / verify-android-build-version.ps1
  / verify-android-native-artifacts.ps1 pass; apksigner v2+v3 verified;
  reproducibility double-build hash match for both ABIs.
- Panel PHP contract tests (ultra_client_artifact_distribution,
  macos_client_artifact_manifest, macos_client_install_surface,
  android_client_artifact_manifest): pass.

## Production publish

- Backup: /root/codex-backups/android-macos-ha-20260911 (panel host).
- Uploaded 7 artifacts + merged versions.json (manifest switched last).
- versions.json: android_client_apk 0.2.24/26; new
  ultra_client_runtime_android_{arm64,x86_64}; macos_client_update_* sha +
  runtime_sha256 refreshed; ultra_client_runtime_macos_* refreshed with build
  field.
- Canary: see publish log (Cloudflare + Aliyun entrances).

## Remaining follow-ups

- Physical Android arm64 device TUN acceptance test.
- macOS codesign --verify --deep --strict + real-device launch test (no Mac
  available on the build host; app is ad-hoc signed, un-notarized, as before).
- macOS DMG rebuild so fresh installs ship the new runtime directly.
- Panel PHP routes for /artifacts/ultra/ultra-client_android_{arm64,x86_64}
  (app ships the runtime inside the APK, so nothing fetches these URLs today).
- Linux client-agent release to pick up udp_queue_packets 256 (source fixed in
  21dece57; publish with the next client-agent release).
- Mac VPN-mode osascript admin-password prompt (independent of keychain).
