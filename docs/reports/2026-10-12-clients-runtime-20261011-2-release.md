# Client release clients-20261012-1: runtime 2026.10.11.2 on every platform (2026-10-11)

Follow-up to IKK-785135 (docs/reports/2026-10-11-ultra-zero-byte-close-failover.md).

## What ships

| Component | Before | After |
|---|---|---|
| Windows app + headless | 0.1.20.61 | **0.1.20.62** |
| Android | 0.2.39 (41) | **0.2.40 (42)** |
| macOS (arm64 + x64) | 1.0.54 | **1.0.55** |
| Ultra runtime (Linux, Windows, Android, macOS) | 2026.10.11.1 (Linux) / 2026.10.10.2 (others) | **2026.10.11.2.cef3fef2** |

The runtime carries two changes:
- **Zero-byte close failover (d4923c4e)**: a relay that ACKs, takes the first flight and closes empty three times in 10 s is avoided locally. It was already live on Linux as 2026.10.11.1; this brings it to Windows, Android and macOS.
- **TCP selection probe reply check (60f53ba9)**: after the stream handshake, the probe sends a TLS ClientHello to 1.1.1.1:443 and reads one byte. A clean empty close demotes the candidate in ranking, never excludes it. External-UoT forwards were not affected by this blind spot: their sidecar hello/ack probe already requires a reply.

## Verification before publish

- `go test` for all of tools/ultra-client passes (Windows).
- Linux test host canary of 2026.10.11.2:
  - five agent-managed forwards ran it with 0 exits;
  - temporary TCP-only forward 303 on combo 51 (CAN→HKG) made 4 selections over 4 live relays, so 16 probes: 0 empty-close flags, 0 probe failures;
  - HTTP 120/120 at p50 118 ms.
- Windows runtime: Defender scan clean.
- Windows installer and headless zip: the five helpers (bond, ingress, uot-bond, mptcp-relay, wstunnel) are reused byte-identical from 0.1.20.61 and match the manifest pins. The headless zip's file list is identical to 0.1.20.61.
- Android: the packaging contract passes (v2/v3 signature, arm64-v8a + x86_64, runtime 2026.10.11.2, versionCode 42).
- macOS, built on the Frankfurt build Mac (Intel; arm64 is cross-built and checked with the managed cross-load receipt):
  - five helpers are reused byte-identical from 1.0.54;
  - codesign is OK for the DMG app, the zip app and the extracted ultra-client on both architectures;
  - the signed ultra-client hash is equal in the zip, the DMG and helper-manifest;
  - the bundle version is 1.0.55;
  - the x64 runtime reports `2026.10.11.2.cef3fef2`.

## Publish

- `deploy-client-artifacts.py` committed at 15:30:33 UTC.
  - Backup: `/root/codex-backups/clients-20261012-1`.
  - Staging: `/root/release-clients-20261012-1`.
- 16 manifest keys changed, as planned.
- Attestation rollover was done first. `ULTRA_ATTESTATION_EXTRA_HASHES_JSON` now also lists 2026.10.11.1 (Linux ×2) and 2026.10.10.2 (Windows, Android ×2, macOS ×2). The `.env` backup is in the staging dir.
- After publish:
  - all 16 public URLs match;
  - Aliyun mirror spot checks match (Linux runtime, Windows runtime, macOS arm64 update zip);
  - /login returns 200;
  - 0 lease refusals, 305 leases in the first minutes.

| Artifact | SHA-256 (prefix) | Size |
|---|---|---|
| Windows setup 0.1.20.62 | aa11bfce376f | 69,791,828 |
| Windows headless zip | 827d385a18dc | 63,750,081 |
| Android APK 0.2.40 | dfcfa873f5dd | 83,509,688 |
| macOS arm64 DMG / update zip | f2054d63d2a3 / 672481b7ff31 | 78,574,952 / 70,003,275 |
| macOS x64 DMG / update zip | a34d0cc974bb / a59e62836c03 | 83,171,078 / 74,255,001 |
| Runtime Linux amd64 / arm64 | d28be71e1ac5 / 9440f1b5678a | |
| Runtime Windows | 75544b234385 | |
| Runtime Android arm64 / x86_64 | 0b5934fe6d83 / d3bec61e843f | |
| Runtime macOS arm64 / x64 (signed) | 2871b4a7beb0 / 9c35ec9a6c76 | |

## Notes

- The installer build checks the local artifacts/versions.json for the Windows runtime hash. That file now mirrors the live manifest.
- The uploads were slow from the mainland directly to the panel (about 23 KB/s), so the files went via the Taiwan jump host (103.246.219.39), at about 375 KB/s up and then about 3 MB/s on to the panel.
- The macOS source tarball must include `docs/third-party/sing-box-notice.txt`; packaging fails without it.
- The arm64 macOS build was never run on native Apple Silicon (no such Mac is left).
- Coordinated with a parallel session that deployed panel PHP for ZZN-514526: no shared files, sequential start/done messages around each production write.
