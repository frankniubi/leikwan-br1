# macOS 1.0.8 and Android 0.2.21 network release

## Scope

The user authorized publishing macOS despite unavailable real-Mac QA, and fixing Android. This release changes only the Android APK and both architectures of Mac DMG/update ZIP. Windows 0.1.20.11, Linux 2026.09.06.1, standalone Ultra runtimes and production PHP must remain byte-identical. Preserve the seven unrelated dirty files recorded in `.tmp/mac-android-release-20260906/baseline.json`.

Publication status is recorded in the deployment addendum below. A local build alone is not a publication claim.

## Android fixes

- `AndroidNetworkMonitor` supplies the physical interface inventory and initial/default-route changes to libbox. The old service returned null interfaces and never invoked the default-interface listener. VPN networks are excluded; loss, capabilities, link properties and replacement physical networks trigger updates. Listener delivery/close is serialized, and Android underlying-network selection follows the physical route.
- `protect(fd)` failure now raises an error instead of silently risking recapture.
- TUN reload closes the previous Android `ParcelFileDescriptor` after establishing its replacement. libbox retains its own duplicated handle; the previous implementation leaked the Android handle at every reload. The regression test failed on the old implementation and passed after the fix.
- Service destruction and stop both close native services, command diagnostics, network callbacks and TUN ownership. Config credentials are still wiped at stop.
- The dual-ABI build helper no longer splices an arbitrary fallback `.so` into an existing AAR. Static verification rejects missing Java JNI classes, mismatched ABI binding inventories and APK native overrides relative to the AAR.

### Correcting the earlier BridgeOptions diagnosis

The previous handoff's missing-`BridgeOptions.class` gate was too broad for the **current** AAR. This AAR uses the `CommandServer` API; neither ABI refers to `BridgeOptions`. Both native slices reference 120 libbox Java classes, all present in its 134-class `classes.jar`. No empty compatibility class was added and no native binary was replaced.

- AAR SHA-256: `706c139fc1c5f0366494135fcf1d857f899d62965d19734ebc56af4ff532ccb0`.
- `classes.jar`: `055fa3404d7ac780bac44b99d3aa3936f720ac911795100de7db7c7a55c7b47c`.
- arm64 `.so`: `fa958bad539ba5c1cb32465447d4dcdfdd7ca40d4fa77d8db72305ea0ecd7a3d`.
- x64 `.so`: `22e87ee85b20f22d64c2896984c939552432a411e96e7784ec5d062e95ecb175`.
- Local source `sing-box-build` commit `2e64545db4838c06dd16d9cb75621bc7136600cd` has the same API surface. This is observed provenance, not a claim of a new reproducible AAR rebuild.

Real `CommandServer` startup, TCP and SOCKS UDP traffic, actual Android kernel TUN creation, TCP/UDP through the outbound SOCKS path, and service reload passed on the API34 x86_64 AVD. Therefore the historical class-name gate is superseded by API-specific packaging checks plus runtime evidence. A future native reference to a missing `BridgeOptions` still fails the new verifier.

### Verification

- Formal non-QA Debug JVM: 66 files, 299 tests, zero failures/errors/skips.
- Formal non-QA Release JVM: 66 files, 299 tests, zero failures/errors/skips.
- JNI service/platform, native config and ordinary Ultra focused instrumentation: 6/6.
- Kernel TUN test: 1/1; proxy-side evidence records 3 TCP and 3 UDP round trips, including reload and the old-fd-close assertion. Probe runs under shell UID, not the VPN-excluded application UID.
- JVM network regression covers initial physical interface, VPN exclusion, loss, recovery and callback removal. Static binding guard: 5 tests; guarded deployment: 13 tests.
- No `FATAL EXCEPTION`, fatal signal, `ClassNotFoundException` or `UnsatisfiedLinkError` in final focused logcat. `/data/tombstones` returned permission denied; absence was not established.
- Early fixture failures remain in local evidence: null mock connection owner, IPv6 loopback mismatch and `nc` pipe behavior were test-harness errors. The final NDK socket probe avoids those paths. QA-enabled DeviceSecurity tests intentionally failed until rerun with formal QA=false; no security bypass was removed.

These tests do not claim physical-phone, live native lease data-plane, Twilio browser UDP, CS2 or long-duration Wi-Fi/cellular handover acceptance. The deployment live lease probe separately checks the production control-plane contract.

## macOS delivery

Mac 1.0.8 contains the complete dual-RID managed app build and the previously fixed shared polling/recovery lifecycle, not a Core-only patch or renamed 1.0.7 archive. Shared fixes and customer observation analysis are in `2026-09-06-ordinary-ultra-release-handoff.md`.

- Native helpers were retained from the previous architecture-matched release and resealed ad-hoc. `helper-manifest.json` reflects their final bytes. Standalone runtime artifacts are unchanged.
- Local Windows/WSL packaging: xorriso 1.5.6 builds HFS+; libdmg-hfsplus creates compressed UDIF from the HFS volume. An intermediate hybrid DMG produced a header warning and was rejected. The final pure-HFS DMGs pass full `7z t` without that warning.
- `apple-codesign` 0.29.0 ad-hoc signs Mach-O code. Managed DLL/resource hashes are included in the final resource seal. 24 Mach-O files and 29 CodeDirectory slices per architecture pass embedded page/special-slot digest validation.
- Every one of 231 files per architecture matches after final DMG extraction and ZIP extraction. Apphost architecture, Info.plist 1.0.8, helper hashes and executable ZIP permissions are verified. Mac unit tests: 71/71.
- **Not Developer ID signed or notarized. No Apple `codesign`/Gatekeeper or real macOS 27 beta launch/TUN/sleep acceptance was performed.** The cross-platform verifier's CMS warning is not represented as Apple verification. The user explicitly accepted publishing Mac without a local real-Mac build environment.

## Reproduction and evidence

Local evidence: `.tmp/mac-android-release-20260906/`. It contains the Android red/green instrumentation logs, final JVM counts, binding checks, packaging log, Mac build/seal/digest/roundtrip logs, preflight hashes, deployment plan/receipt and public canary downloads.

Android instrumentation uses disposable `emulator-5556`, `LeiKwan_QA_API34`, `-read-only -no-snapshot`. The release uses the existing signing certificate, `DEBUG=false`, QA exceptions=false and both `arm64-v8a`/`x86_64` ABIs. Do not install the QA APK over a customer's physical phone.

`scripts/tests/android_tun_probe.c` is compiled using NDK 27.1 `x86_64-linux-android26-clang`, pushed as `/data/local/tmp/leikwan-tun-probe`, then executed by `LibboxTunRuntimeTest`. Its host fixture accepts only ephemeral test credentials and only the two local echo destinations; it is not an open proxy. Invoke the TUN instrumentation with `echo_tcp_port`, `echo_udp_port`, `socks_port`. Without these explicit fixtures, the test is skipped, not treated as real TUN acceptance.

For other distributions, implement equivalent physical-interface/default-route reporting and explicit TUN descriptor ownership only where applicable; do not copy Android-specific APIs or add obsolete Java classes. Keep ordinary/native protocol compatibility and explicit user-stop semantics from the earlier handoff.

## Deployment Addendum

- Source/release commit: `30cfc72723527238eaf2877c11734402b808d9e2`.
- Production publication committed at `2026-09-05T19:54:35.568005+00:00` (`2026-09-06 03:54:35` Asia/Taipei).
- Backup: `/root/codex-backups/mac-android-network-release-20260906`. Five old payloads plus the old manifest were saved and hash-verified before writes. Each payload uses same-directory atomic replacement, followed by rehash; the manifest is last. Locks, disk preflight, old-hash guards and conflict-aware rollback are enforced.
- Static manifest before: `05ea6e569ca0cd4d31fa69f6897b2c60e3bd89793b0d8ad50c877333c42ebe6b`; after: `8374f63ca32ad7b50d9d600c7903f6cda9e9c53f632b99277f443ed8c08da70f`; both 16,958 bytes. Public manifests are dynamically generated; compare selected contracts rather than assuming their whole-response hashes equal the static file.
- Windows, Linux source/mirror, three PHP compatibility files and four standalone Ultra runtimes were guarded and remain unchanged. No production reload/restart was performed.
- Slow full uploads were stopped in the temporary area only. Android rsync transferred 2,897,339 literal bytes. Mac exact-byte reconstruction used a 4,835,010-byte bundle (SHA `4825f1ed6a038b9aac445fa548320074bd018c1ff63390e41aef88a2819aabd8`), pinned `bsdiff4` 1.2.6 and static tools. Rebuilding was limited by systemd to `CPUQuota=50%`, `MemoryMax=1536M`, `TasksMax=64`; observed memory was approximately 570 MB. Compressed DMG data was verified before restoring the original audited UDIF metadata. Both regenerated ZIPs and both DMGs matched the original local hashes exactly before deployment. No application source compilation ran on production.
- Android canonical release (not the QA build) was installed only on disposable `emulator-5556`; the 6 focused tests and actual kernel-TUN test passed again. The AVD was shut down afterward. No physical Android device was modified.
- Three post-deploy rounds passed health, manifest consistency and native lease issuance: forward 180, `CAN -> HKG`, `global`, one lease, `leikwan-ultra-native-socks5-v1`, `tcp+udp`. The same lease probe passed before deployment.
- GitHub was not force-pushed or merged across the divergent sanitized history. The commit is local; source/evidence synchronization to the server is recorded separately. Mac ignored DMG/ZIP files are external release payloads, not Git objects.

| Artifact | Version | Bytes | SHA-256 |
|---|---|---:|---|
| Android APK | 0.2.21 / 23 | 88863067 | `2afcb4c7b59b5cbaa307a4ce4fc85451b194e1f0d7a8c67262c56517cc293e42` |
| Mac arm64 DMG | 1.0.8 | 68581998 | `b29826dbaec2ecb6a6b6ddcb45282793803d2c14ff06b05a2249abbd39cc9170` |
| Mac x64 DMG | 1.0.8 | 72559524 | `b552dd623680812228e3a49400c36ce35dc6c97389dfbbac6e272d6cd16fdd72` |
| Mac arm64 ZIP | 1.0.8 | 68539931 | `9702370f4f138cf97112b763deadb1a65fddff9645898708c537ee9e99a1f7fc` |
| Mac x64 ZIP | 1.0.8 | 72554338 | `eeb315339b068b6ced48b0702f2be895c44dc479b87342b78f65ee4447e470d1` |

- Public canary completed: two manifests, five full artifact downloads, and three additional full downloads using the exact non-canary updater URLs all passed. HTTP 200, sizes, SHA-256 and payload ETags agree. The raw public Android URL also returned the new APK; Mac updater URLs carry `v` and `sha256`. Evidence: `.tmp/mac-android-release-20260906/public-canary/result.json`.
- Read-only postflight rechecked installed payloads, static manifest and preserved Windows/Linux/PHP/standalone runtime hashes. Independent backup verification passed for all six old files. PHP-FPM remains active; free disk space was 97,085,212 KiB. Temporary reconstruction completed before publication.
- Browser automation was unavailable because the installed gstack browse binary lacks `server.ts`. No browser screenshot/GUI canary is claimed; real HTTP requests/full payload downloads are the public acceptance evidence.
- Committed evidence summary: `docs/handoffs/evidence/2026-09-06-macos-android-network-release.json`. Server evidence/source destination is the backup directory above; local upload receipt: `.tmp/mac-android-release-20260906/evidence-upload-receipt.json`.
- Source delta archive: `source-30cfc727.tar.gz`, SHA `222863580bae7c290e34b622db76f6ac133a342c65aac34807b76a180fb099d2`, 32,006 bytes, based on `115a9a4e`. It contains committed release changes, not unrelated dirty files or credentials. The final handoff/evidence are uploaded separately.

Unrelated global manifest drift remains out of scope; this release does not claim all manifest entries are consistent. The seven pre-existing dirty files remain unchanged.
