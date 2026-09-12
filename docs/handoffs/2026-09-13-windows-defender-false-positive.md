# Windows client 0.1.20.33 / runtime 2026.09.12.2 - Defender false positive, stale runtime, duplicate installs

Date: 2026-09-13 (UTC+8 night shift)
Commits: `0dc69598` (fix), release published from it.

## Symptoms reported

1. "Access denied" when starting the client, then the app would not start at all
   even after relaunching, with processes left behind.
2. Two LeiKwan Bridge entries in Apps & features / two install directories.
3. The client kept offering the same update it had already installed.

## Root cause 1 - Windows Defender quarantines every garble build

`ultra-client.exe` was built with `garble -literals -tiny`. Windows Defender
classifies **every** garble output as `Trojan:Win32/Gracing.I` / `Gracing.B`
and quarantines it. While the file is quarantined, reads fail with
`ERROR_VIRUS_INFECTED` (0x800704EC) which .NET surfaces as an access denied
style failure - that is the "access denied" the user saw. It was never an
admin/elevation problem.

Measured with the real engine (`MpCmdRun.exe -Scan -ScanType 3 -File`):

| variant | flags | Defender |
| --- | --- | --- |
| v1 | `go build -trimpath -ldflags "-s -w -buildid="` | clean |
| v2 | `garble -literals` | `Trojan:Win32/Gracing.I` |
| v3 | `garble -tiny` | `Trojan:Win32/Gracing.I` |
| v4 | `garble -literals -tiny` (shipped config) | `Trojan:Win32/Gracing.I` |
| v5 | `garble -seed=...` (identifiers only) | `Trojan:Win32/Gracing.I` |
| v6 | plain build + `-X main.runtimeBuildVersion` | clean |

Oracle command:

```
"C:\ProgramData\Microsoft\Windows Defender\Platform\<ver>\MpCmdRun.exe" \
  -Scan -ScanType 3 -File <artifact> -DisableRemediation    # exit 0 clean, 2 detected
```

## Root cause 2 - the GUI ran a stale managed runtime copy

`config.json` had `ultra_bin = C:\ProgramData\Leikwan\ClientAgent\runtime\ultra-client.exe`.
That is a runtime copy the product owns and no installer ever refreshed, so it
still held the 2026-09-05 build (`62174c05...`). Consequences:

* the client silently ran a month-old helper (no attestation, no path-MTU
  ceiling, none of the 2026.09.12.1 hardening);
* `HasRuntimeDrift()` compares the *effective* runtime against
  `runtime_sha256` from the release manifest, so it reported drift on every
  check: the GUI offered the update it had just installed, forever.

`IsLegacyUltraBinPath` only knew the `%LOCALAPPDATA%` legacy paths. It now also
treats `%ProgramData%\Leikwan\ClientAgent\runtime\` as managed, so
`MigrateLegacyUltraBin` rewrites the config to the shipped
`<install>\bin\ultra-client.exe` at startup.

## Root cause 3 - duplicate installs

* `C:\Users\<user>\AppData\Local\Programs\LeiKwan Bridge` - a full 0.1.20.2
  per-user install with no uninstaller left behind by an older installer.
* `C:\Users\<user>\AppData\Local\LeiKwan Bridge` - leftover program files plus
  an orphaned HKCU uninstall entry (`LeiKwan Bridge` 0.1.0) pointing at a
  deleted `uninstall.exe`. That ghost entry is the second product the user saw.

## Fixes shipped

1. `scripts/build-ultra-windows.ps1`: garble is now opt-in (`-Obfuscate`, off by
   default) and the build **fails** if `MpCmdRun` detects the artifact. Reported
   fields: `obfuscator`, `defender_scan`.
2. `AgentConfig.IsLegacyUltraBinPath`: managed per-machine runtime path added;
   `MigrateLegacyUltraBin` now migrates it (tests:
   `AgentConfig_MigrateLegacyUltraBin_MigratesManagedPerMachineRuntimePath`,
   `..._KeepsExplicitCustomRuntimePath`).
3. `scripts/windows-client-install-helper.ps1`: `Invoke-DuplicateInstallSweep`
   runs after every interactive install and every silent update. It
   - runs a duplicate install's own Inno uninstaller silently, else removes its
     files (keeping the `updates\` installer cache, which the running client uses),
   - deletes stale uninstall registry entries whose directory holds no real
     install (only entries matching LeiKwan/利群 are considered),
   - refreshes stale `%ProgramData%\Leikwan\ClientAgent\runtime\` copies from the
     freshly installed `bin\ultra-client.exe` and drops `ultra-client.exe.tmp-*`.
4. Release `windows_client_app` 0.1.20.33 (`9f04ba08...`, 69,537,556 bytes) with
   runtime `2026.09.12.2` (`34d4f261...`, fingerprint `2026.09.12.2.0dc69598`).

## Panel side

* `artifacts/versions.json`: `ultra_client_runtime_windows` -> 2026.09.12.2 and
  `windows_client_app` -> 0.1.20.33, drift pointer re-synced by the publisher.
* `ULTRA_RUNTIME_FINGERPRINTS` now leads with `2026.09.12.2.0dc69598` (older
  fingerprints kept so clients mid-upgrade keep working).
* `retired_runtime_<sha12>` history entry added for the previous Windows runtime
  (`2026.09.12.1.4b106b02` / `030b4815...`). Without it, a Windows client still
  on the old build would report a fingerprint the panel still knows (macOS,
  Linux and Android share it) with a hash that is no longer published - the
  exact signature the lease attestation check refuses. `releasedRuntimeHashes()`
  walks every entry, so a history entry keeps those clients leasing.
* History entries are never exposed by `/artifacts/client-manifest.json`: that
  route builds its payload from a fixed key allowlist.

## Verification

```
curl https://v3.leikwanhost.com/artifacts/client-manifest.json        # 0.1.20.33
curl https://api-leikwanbridge.nbwly.com/artifacts/client-manifest.json  # identical
MpCmdRun.exe -Scan -ScanType 3 -File <setup.exe>                     # exit 0
```

On the client: version 0.1.20.33, `ultra_bin` rewritten to the install root, one
Apps & features entry, no update prompt.

## Follow-ups

* File a Defender false-positive report for the garble build so obfuscation can
  be re-enabled: https://www.microsoft.com/en-us/wdsi/filesubmission
* Code-signing certificate would remove this entire class of failure.
* Linux/macOS/Android runtimes still use garble; only Windows is affected by
  Defender.