# Native Usage Accounting Release Handoff (2026-09-07)

## Status

The native lease usage accounting fix and the selected Windows/macOS artifacts
are published in production. Direct SSH was intermittent, so the guarded
deployment used the existing `client -> sh36 -> panel` jump path. The direct
panel wrapper was not bypassed; all writes were made by the backup-first
helpers described below.

Source commit: `806e934789c7f9d92a99954d23bad6361b7c276d`.
Deployment plan commit: `cfcb6aaba4e057949c25087cdfa88aa922119c89`.

## Root cause and fix

- Native relay status files were written below a sibling runtime tree and were
  not included by the heartbeat collector, so native traffic could be absent
  from usage reports.
- A single per-forward cursor could also suppress a second native lease for the
  same forward.
- The client now scans the dedicated `native-usage` trees, keeps cursors per
  runtime instance, groups rows by `(forward_id, policy_generation)`, and
  leaves rows beyond the panel's 32-row limit for retry.
- The exact pending batch is persisted before a heartbeat. It is cleared only
  after `ultra_usage_acknowledged=true` (or the legacy `ok=true` response when
  the acknowledgement field is absent).
- The panel heartbeat response now reports
  `ultra_usage_acknowledged`; failed usage records leave the batch pending.
- Download links derive their canonical path and add version/hash cache-busting
  parameters. The release labels include the manifest version.

## Local verification

The following existing result files are green:

| Suite | Result |
| --- | --- |
| Core | 307/307 passed (`native-usage-core-release-final2.trx`) |
| App | 52/52 passed (`native-usage-app-release-final.trx`) |
| Mac | 102/102 passed (`mac-native-usage-final-no-false-fallback.trx`) |
| PHP focused regression tests | all `ok` (syntax, download cache-bust, lease contract, manifest and macOS/windows page tests) |
| Python focused tests | 8 passed, 13 skipped |

## Selected artifacts and manifest

`artifacts/versions.json` was checked against the bytes on disk:

| Key | Version | Size | SHA-256 |
| --- | --- | ---: | --- |
| `windows_client_app` | `0.1.20.14` | 22,451,749 | `d0649fbcbe2693beeb8d4bc3ec7599a833993cdf6fc00d242a7395d29bb5b756` |
| `macos_client_app_arm64` | `1.0.18` | 76,930,102 | `b3d68f39d8622057b669f49f3506a5f4d46bb832eac1211d2a0abfd7126b18ed` |
| `macos_client_app_x64` | `1.0.18` | 81,390,798 | `6ed82016451dbf6f550a48434327afe8ee0a91f045b483308edcca45177d0c7f` |
| `macos_client_update_arm64` | `1.0.18` | 68,385,872 | `021f6e6550e32720582c38420890ac64dac5c8e09d9c17050f87379e4170d4c9` |
| `macos_client_update_x64` | `1.0.18` | 72,662,463 | `7d25525219ca49ce140a61931600418a2b72c3f04f6c9ebee6b695bc3e0bfdc0` |

The Android manifest entry already present in the workspace is
`0.2.21` / version code `23` / SHA-256
`2afcb4c7b59b5cbaa307a4ce4fc85451b194e1f0d7a8c67262c56517cc293e42`; it was
left untouched by this release.

## Local installation evidence

- The current-user Windows installation under `%LOCALAPPDATA%\\LeiKwan Bridge`
  converged to `0.1.20.14`; the installed executable and Core DLL hashes match
  the build staging hashes.
- The all-users installation under `C:\\Program Files\\LeiKwan Bridge` remains
  `0.1.20.13`. Direct replacement was denied on the protected `bin\\CN.conf`
  path, and the non-interactive UAC attempt did not produce an elevated child.
  Do not claim the all-users installation is updated until an administrator
  runs the installer.
- The macOS test host has the `1.0.18` app installed and its package passed
  `codesign --verify --deep --strict` and `hdiutil verify`.
- The macOS host reports no display and Avalonia exits with native render timer
  error `-6661`; this is a headless test limitation. It is not evidence of a
  successful GUI launch on a normal desktop.

## Production deployment and evidence

Fresh remote preflight and validate-only checks passed before mutation. The
deployment completed on 2026-09-06 20:28 UTC (local date 2026-09-07):

- PHP controller backup: `/root/codex-backups/native-usage-20260907-cfcb6aab-php`.
- Artifact/manifest backup: `/root/codex-backups/native-usage-20260907-cfcb6aab`.
- PHP receipt and artifact receipt both report `status=committed` and contain
  the complete before/after hashes.
- The artifact tool wrote the five payloads first and switched
  `artifacts/versions.json` last. Android remained protected by its before hash.
- Postflight found php-fpm `active`, health `status=ok`, all selected hashes
  equal to the target manifest, and about 93 GB free on the root filesystem.
- The public canary downloaded the dynamic manifest, client manifest, Windows
  setup, Android APK, and all four Mac DMG/ZIP files. Every response was HTTP
  200 and every downloaded size/SHA-256/ETag matched the target. Desktop
  downloads use `v`/`sha256` cache-busting; Android retains its existing bare
  canonical URL.
- Three consecutive live native lease probes passed for `forward_id=180`,
  `CAN -> HKG`, `global`, one lease, protocol
  `leikwan-ultra-native-socks5-v1`, transport `tcp+udp`.

Evidence is retained in:

- `.tmp/native-usage-audit-20260906/public-health-recheck-20260907.log`
- `.tmp/native-usage-audit-20260906/panel-preflight-native-usage-20260907.log`
- `.tmp/native-usage-audit-20260906/origin-direct-health-20260907.log`
- `.tmp/native-usage-audit-20260906/panel-port-recheck-20260907.log`
- `.tmp/native-usage-audit-20260906/panel-tcp-recheck-20260907.log`
- `.tmp/native-usage-audit-20260906/panel-preflight-jump-20260907.log`
- `.tmp/native-usage-audit-20260906/remote-release-preflight-20260907.json`
- `.tmp/native-usage-audit-20260906/remote-validate-release-20260907.log`
- `.tmp/native-usage-audit-20260906/remote-deploy-php-20260907.log`
- `.tmp/native-usage-audit-20260906/remote-deploy-artifacts-20260907.log`
- `.tmp/native-usage-audit-20260906/remote-postflight-native-usage-20260907.log`
- `.tmp/native-usage-audit-20260906/remote-artifact-receipt-20260907.json`
- `.tmp/native-usage-audit-20260906/remote-php-receipt-20260907.json`
- `.tmp/native-usage-audit-20260906/public-canary-native-usage-20260907/result.json`
- `.tmp/native-usage-audit-20260906/public-ha-canary-native-usage-20260907.log`
- `.tmp/native-usage-audit-20260906/live-native-lease-probe-20260907/`

The remote staging directory is mode `0700` and retained temporarily for
rollback/audit. Remove it only after the release retention window; keep both
backup directories immutable for rollback.

## Scope cautions

- Existing unrelated dirty files and pre-existing manifest drift were not
  staged or repaired.
- Android native TUN/BridgeOptions acceptance remains separately unproven; the
  current Android artifact was not replaced here.
- Mac DMG/ZIP files are external payloads and are ignored by Git; their hashes
  above are the source of truth for a future guarded upload.
