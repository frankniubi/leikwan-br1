# Ultra active-candidate fallback release 2026.09.16.1

Release date: 2026-09-16 (panel publish 2026-09-15T20:41:35Z)
Commit: `e0e4a71f` (`fix(ultra): retry active candidate after probe exhaustion`)
Fingerprint: `2026.09.16.1.e0e4a71f`

## Change

When a fresh Ultra lease set produces `no_healthy_candidate`, the client now
retains the currently active physical endpoint and performs one real forwarding
check against the configured target. It does not send a TCP protocol header, so
encrypted or arbitrary application protocols remain valid. The fallback is
allowed only for an exact physical endpoint/port and an unambiguous logical
identity; ambiguous or changed endpoints fail closed. Runtime TCP and UDP HA
selection use the same one-shot active-path retry.

## Artifacts

| artifact | version | sha256 | size |
|---|---|---|---:|
| `artifacts/ultra/ultra-client_linux_amd64` | 2026.09.16.1 | `cb8d7e927624cb7c6180d16707995d862f9a92f2b75b28b84cd00fbe7530ebb6` | 16376074 |
| `artifacts/ultra/ultra-client_linux_arm64` | 2026.09.16.1 | `bd0641f95efe504f78c7db44707fd44234c40d011375a2a2a2d1e36c9f10bc7d` | 14811402 |
| `artifacts/ultra/ultra-client_windows_amd64.exe` | 2026.09.16.1 | `612c9f0f546101cc0723e83ccf76ab6a25ea34d27721e74026c5a762ad119f78` | 7142400 |
| `artifacts/ultra/ultra-client_macos_arm64` | 2026.09.16.1 | `ae8bf1aab19226cabeb173ee5666520b0936123ef25902123109654e44fcda2c` | 13494224 |
| `artifacts/ultra/ultra-client_macos_amd64` | 2026.09.16.1 | `9194f66b9cf683a91877feb86abe95f586a947f8fc9db890f3b58e3ed671b836` | 15187824 |
| `artifacts/windows/leikwan-bridge-headless-win-x64.zip` | 0.1.20.49 | `18b3b8a084f69195d6d348a21f98222beefd997b1875d42db7c901f09c987c26` | 36773994 |
| `artifacts/macos/leikwan-bridge-update-arm64.zip` | 1.0.40 | `46c3abad7d024477487b78ae533b2373e3b902d1c986060f715b89128b634279` | 68966411 |
| `artifacts/macos/leikwan-bridge-update-x64.zip` | 1.0.40 | `e27fb613987af68e371da2abcbba0b4f3af7f9ae61f280613c4402e40b2f89f8` | 73002670 |
| `artifacts/macos/leikwan-bridge-arm64.dmg` | 1.0.40 | `ac64cc1545f2e37b9944f4efbe529735ccc1355d1c893518c73a17c76a146441` | 77498523 |
| `artifacts/macos/leikwan-bridge-x64.dmg` | 1.0.40 | `267c8e56a74c0c817d0139cc752eb7ea512b87cdcc5f46ee2ebc2f967a0c176c` | 82052734 |

Mac bundles are ad-hoc signed with `rcodesign 0.29.0`; they are not Developer
ID signed or notarized.

## Canary evidence

### Linux amd64 (`172.21.255.189`, forward 170)

Window: `2026-09-16 02:43:44+08:00` to `02:53:54+08:00` (10 minutes).

* TCP HTTPS framing: 61/61; UDP DNS relay echo: 61/61.
* TCP p95/max: 129.3/132.2 ms; UDP p95/max: 5138.4/5863.0 ms.
* `no_healthy_candidate=0`; active-candidate fallback log count: 0.
* Runtime PID stayed unchanged; `NRestarts=0`; no business interruption or
  business-layer packet loss.
* 24 UDP candidate retry/failure messages recovered through retry.
* Two lease refresh failures retained the successful batches and last known-good
  runtime; this is refresh-failure recovery evidence, not a zero-refresh-failure
  claim. The fallback branch was not triggered during this window.
* The old binary and service state were restored after the canary; forwards
  158/159/171/180 were not restarted.

### macOS arm64 no-GUI (`100.103.253.21`, forward 170)

Window: `2026-09-15T19:59:50Z` to `20:09:53Z` (603.1 seconds). The canary used
the signed helper in a temporary app bundle and exercised persistent TCP
heartbeats, UDP DNS-like relays, bursts, and large UDP packets through the real
forward, with direct-path baselines.

* Tunnel UDP: 18,570/18,570 (0.00%); tunnel TCP heartbeat: 600/600, one
  connection, zero breaks.
* Direct baseline UDP: 18,569/18,570 (one permanent baseline loss); direct TCP:
  600/600.
* Tunnel UDP p50/p95/p99/max: 62.18/67.00/74.18/2211.74 ms.
* `no_healthy_candidate=0`, active-candidate fallback count 0,
  `Ultra lease refresh failed=0`, `headless_connect_failed=0`.
* The temporary app and test artifacts were removed afterwards. Root free space
  is about 1.4 GiB and the ClientAgent update cache is 0 B; the installed app,
  user data, and SSNoneProbe process were left intact.

### Windows no-GUI

The final `windows/amd64` runtime and `0.1.20.49` headless ZIP were built,
embedded-runtime hashes were checked, and local CLI/startup smoke passed. The
remote Windows host `172.21.255.4` accepted TCP reachability on 22/3389 but
rejected/reset the available SSH authentication, so its runtime was not
replaced and no remote Windows business canary is claimed.

## Panel publish and verification

All 15 targets were staged, hash-checked, atomically published, and rechecked
against the live panel. The rollback backup is
`/root/leikwan-br1-backups/ultra-active-candidate-20260916-20260915T204135Z`.
Concurrent public GET verification of all 14 manifest artifacts returned the
expected size and SHA-256; the live manifest reports the new runtime and no-GUI
versions.

## Residual risk

The active-candidate forwarding fallback is covered by unit/regression tests and
the release builds, but the 10-minute Linux/macOS windows did not encounter a
real `no_healthy_candidate` event, so direct production-trigger evidence for
that branch remains outstanding. Windows remote canary remains blocked by
authentication rather than by a client failure.
