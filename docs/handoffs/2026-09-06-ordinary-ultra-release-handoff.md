# Ordinary Ultra lifecycle fixes and client release - 2026-09-06

## Scope and release status

User approved commit, artifacts and server publication. The user subsequently authorized skipping macOS if a local formal build is unavailable. **macOS is NOT published in this release**; all official Mac manifest entries and DMG/ZIP/runtime payloads remain 1.0.7 / previously published runtimes. The abandoned Mac version bump was removed from the source project; shared Core fixes are retained.

Initial publication status: validated locally and staged; see the final deployment addendum below for the actual production result. This document does not imply customer end-to-end connectivity acceptance.

## Customer evidence and diagnosis

- Input: customer `observation-20260905.zip`, SHA-256 `b7d7793ed8aae32ccd545a17c45c59f8d406565e8428846b8168134fd694c477` (raw logs remain local-only). Client reported Mac 1.0.7, arm64, macOS 27 beta build 26A5378j.
- The extracted timeline contains 172 unique `policy_reason=ok, lease_count=0` incidents and startup/health interactions that stop newly starting runtimes. The last recorded configuration sync is `2026-09-05 16:50:04.336` in the customer report clock; listener disappearance begins around `16:54:16`, with 1,981 absent-listener samples continuing through `19:39:32`. Do not silently reinterpret the customer clock as UTC.
- An HTTP timeout raises `OperationCanceledException` without caller cancellation. The old shared polling loop treated every such exception as shutdown, so a transient control-plane timeout could permanently stop renewal.
- Health and manual UI restart paths can receive credential-free catalog snapshots. Restarting Ultra directly from these snapshots produces no usable leases; restarting during slow listener setup also destroys a healthy in-progress start.
- Separate upstream four-byte EOF/remote-close events remain unproven and are not claimed fixed. These findings are lifecycle defects, not proof that every UDP problem or macOS beta issue shares one cause.

## Implemented changes

### Shared Core (Windows now; macOS on a later release)

- `ConfigPollingService`: stop polling only on actual caller cancellation; log and persist timeout errors, then continue. Coalesce fresh full-pull recovery requests rather than attempting restart with a catalog snapshot. Observe immediate-sync failures.
- `ForwardRuntimeManager`: track starts; ignore stale health callbacks after readiness; acquire fresh leases for automatic recovery and manual Start without credentials. A deliberate manual start with a fresh lease still starts normally. Explicit user-stop remains respected.
- `HealthCheckService`: skip currently starting forwards. Recovery reconciliation restarts only when the runtime remains unready and leases have been freshly acquired.
- Six tests in `OrdinaryUltraRecoveryTests.cs` cover timeout continuation, slow-start health races, explicit stop, manual catalog start, manual fresh-lease start/wipe, and late health callbacks.

### Android ordinary mode

- Renewal does not restart an unchanged ordinary Ultra runtime solely because `policy_generation` rotates. Changed forward ID, target digest, transport or protected-connectivity requirements still trigger the existing restart paths. Native outbound code is unchanged.
- `OrdinaryUltraRenewalTest` checks renewal with same and rotated generations without stopping the runtime.
- Two pre-existing repository test fixtures assumed `Prefs.panelUrl` could redirect release builds. Release intentionally ignores that field; six tests instead reached the real Panel with dummy credentials and failed HTTP 401. Test-only OkHttp interceptors now route every request to their own MockWebServer. No release security bypass or production Prefs change was made. Both full variants now pass.
- APK runtime/JNI is unchanged from verified 0.2.19. Canonical acceptance metadata corrects the original package invocation label to actual runtime version `2026.09.04.3`. Native full TUN acceptance remains blocked by missing `io/nekohasekai/libbox/BridgeOptions.class` in the existing AAR.

### Linux client-agent

- Separate bounded renewal worker prevents the main config-pull loop from starving due Ultra lease renewals. Authoritative configuration reconciliation and health stay on the existing main loop.
- Fresh pulls and static-contract comparison precede hot lease delivery; a per-forward nonblocking lock and a current-state check prevent resurrection of removed/disabled/changed forwards.
- Socket delivery retries share one monotonic deadline (3 seconds single candidate; up to 70 seconds candidate batch, capped before lease expiry). A retry does not restart the entire budget.
- `client-agent/client_agent.py` and `artifacts/client-agent.py` are byte-identical. Publication of a Python agent artifact is not evidence that every online Linux client has already downloaded and restarted it.

## Artifact contract

| Entry | Version | Bytes | SHA-256 |
|---|---|---:|---|
| `windows_client_app` | `0.1.20.11` | 69433859 | `8e7449c684ad1867f8b13bc48ce3816d1d321ea1c2ffb770afc6f097a6af24d5` |
| `android_client_apk` | `0.2.20`/22 | 88858971 | `d7655f76dd04a7656c934efac1c705c357bcbbb5eaa0cd46539696fb2763f76d` |
| `client_agent` | `2026.09.06.1` | 298785 | `cd022fa1380ae5ff5b6b784a4b33e99feb16f6a2059143202ce6d6f3c1a78344` |

Manifest: `05ea6e569ca0cd4d31fa69f6897b2c60e3bd89793b0d8ad50c877333c42ebe6b`, 16958 bytes. Only `windows_client_app`, `android_client_apk`, `client_agent` change. Pre-existing global manifest drift/missing artifacts are out of scope; do not claim global consistency.

## Verification

- Shared Core: **291/291** pass (`core-full.log`). Windows App: **52/52** pass (`windows-app-tests.log`). Mac unit tests on Windows: **71/71** pass (`mac-tests.log`); this is not execution on macOS.
- Android Debug: **298/298**; Release: **298/298**, 65 XML files per variant, zero failures/errors/skips (`android-all-isolated-final.log`, `android-all-final-counts.json`). Initial six release failures remain recorded in `android-release-failures.json`.
- Python client tests on Windows: **407 passed, 19 subtests passed** (`linux-pytest-windows.log`). WSL focused Ultra: **34 pass** (`linux-wsl-ultra.log`). A broader WSL unittest attempt recorded 8 errors: two missing-pytest imports and six unrelated bond/MPTCP tests attempting protected `/etc/systemd/system` writes. Do not rerun that suite as host root just to bypass its missing filesystem isolation (`linux-unittest-wsl.log`).
- Windows installer tests: **16/16** pass. Three scoped PHP artifact/route tests pass; their pre-existing dirty files were not edited or staged (`php-manifest-tests.json`).
- Deployment tool on WSL: **9/9** pass, including postflight rollback, stale-hash rejection, noncanonical route, Mac manifest drift, symlink rejection, corrupt temporary payload, and concurrent-writer preservation (`deploy-tool-tests.log`).
- Per-user and machine Windows installations updated to final `0.1.20.11`. All **259** packaged files in each install match the final setup stage; `local-install-after.json` and `local-install-all-files.json` contain hashes. Full copies of both previous installations were saved under `.tmp/ordinary-release-20260906/local-install-backup/` before replacement. An accidental intermediate installation in a truncated path was moved into that backup, not deleted. No new customer CS2/Twilio/long-lived TUN result is claimed for this build.
- Seven pre-existing dirty files have matching before/after SHA-256 and are excluded from this release commit (`unrelated-preservation-final.json`). Raw customer logs and credentials are excluded from Git.

## Publication procedure and evidence

All local evidence is under `.tmp/ordinary-release-20260906/` unless otherwise noted. `preflight.json` records live ownership/mode/hash/size of every overwritten payload, manifest and preserved Mac/PHP file; `native-lease-before.log` reports a successful native lease for forward 180 / CAN -> HKG / global / tcp+udp.

`scripts/deploy-client-artifacts.py` takes all three known release locks, rejects stale live hashes and unexpected manifest edits, checks disk space, verifies uploads, backs up before mutation, performs same-directory atomic payload replacements with temporary-file hash verification, and switches the manifest last. It checks installed hashes, untouched Mac/PHP files, php-fpm and `/api/health`. Failures roll back from verified backups without clobbering a concurrent writer. This is **per-file atomicity with manifest-last publication**, not a claim that several independent files switch in one filesystem transaction.

Old interrupted upload/deploy attempts from the earlier staging directory failed upload guards before live changes. Their incomplete/rejected Mac staging payloads must not be reused. The final release uses `/tmp/ordinary-ultra-release-20260906-final` and excludes Mac entirely. Do not execute `.tmp/ordinary-ultra-release-deploy-20260905.sh`.

After publication, require public cache-busted manifest and full artifact downloads, exact hashes/sizes/ETags, health and live native lease probes. Native lease issuance is a control-plane contract probe, not end-to-end UDP or full TUN traffic proof. Static downloads may have immutable cache headers; canonical URLs alone without the advertised version/hash query are insufficient to prove edge-cache convergence.

## macOS handoff - held by user request

- Local Windows cross-publish generated **full managed** 1.0.8 app bundles for both RIDs, with new apphost/Mac/Core assemblies and unchanged native helpers from verified 1.0.7. The initial Core-only experimental ZIPs were rejected and quarantined, not published.
- Pending QA files: `artifacts/macos-pending-1.0.8-20260906/`; acceptance JSON records Mach-O architecture, executable bits and helper preservation. arm64 ZIP SHA `7fc31c928c4c0635dec03cf8b99a792432a46e94cf0d55a58070f5034f1e3bbd` (68,546,570 bytes); x64 ZIP SHA `cb6963bc6ba57b9df4aded9dff53f12ccb70131c85883841a88aa41dece70c5a` (72,050,129 bytes). These are **PENDING-MAC-QA**, not DMGs or signed/notarized official builds.
- The test Mac was observed offline in Tailscale for over one day; SSH was unavailable. No macOS launch/installation/Gatekeeper/Network Extension/TUN/DNS/sleep-wake acceptance was possible. User authorized leaving Mac aside.
- Next distribution owner: build from this release commit or a newer master that contains it, temporarily set Mac version 1.0.8 (or the next unused version), rebuild all Mac managed assemblies, use `scripts/build-macos-client.sh` and `scripts/package-macos-client.sh` on a reachable Mac, then test slow-start, 30-second Panel timeout, fresh-lease/manual restart, and several lease rotations with actual traffic. Rebuild DMG/ZIP for both architectures, verify signatures/notarization according to release policy, and update matching manifest entries only after hardware acceptance. Never relabel the old 1.0.7 or the rejected Core-only ZIP as 1.0.8.

## Final deployment addendum

Pending guarded deployment and public canary at the time of this source commit. A subsequent evidence commit will replace this section with observed results.
