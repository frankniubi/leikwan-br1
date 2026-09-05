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

Pending final commit, artifact upload, guarded publication and public verification. Do not treat this paragraph as a successful deployment receipt.
