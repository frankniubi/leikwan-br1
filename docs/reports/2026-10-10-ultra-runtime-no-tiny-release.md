# Ultra runtime 2026.10.10.1: build without garble tiny mode (2026-10-10)

## Problem

UDP over external UoT lost 15-29% on CAN→TPE and FOC→TPE (and any route using that carrier) with
the Linux runtime 2026.10.02.1. The UoT carriers were fine: sidecar sessions showed `reattaches=0`,
`queue_drops=0`, and the pump received exactly the packets the harness counted as acked.

The runtime process itself died every 15-90 s under 10 Mbps UDP: exit 2 with no output, and once a
SIGSEGV whose instruction pointer was the faulting address in a data page (kernel: `error 15`). Each
crash cost about 25 s of UDP (5 s `RestartSec` plus line reselection and carrier re-attach).

## Cause

2026.10.02.1 was the first runtime built with garble v0.17.0 `-literals -tiny` (Go 1.26.5). Tiny mode
suppresses every panic message, so the crash could only be isolated by A/B on the Linux test host
(172.21.255.189, forward 283, combo 59, same source d1bac327):

| Build | Runs | Crashed |
|---|---|---|
| release binary (`-literals -tiny -s -w`) | 6 | 5 |
| fresh build, same flags, other seed | 2 | 2 |
| plain `go build` | 1 | 0 |
| garble `-literals` (no `-s -w`) | 3 | 0 |
| garble `-literals -s -w` | 2 | 0 |

The root cause inside garble's tiny-mode runtime patching was not pinned down; the A/B was decisive.

## Fix

Commit b089a4e2: tiny mode removed from every runtime build script (Linux, macOS, Windows, Android,
macOS app packaging) and `tests/test_ultra_build_flags.py` fails if it returns. Identifier renaming and
`-literals` stay; the obfuscation guard passes. `go test` passes (203 s).

## Release `ultra-runtime-20261010-1`

- Published 12:54:05 UTC via `deploy-client-artifacts.py`, status `committed`, backup
  `/root/codex-backups/ultra-runtime-20261010-1`.
- Linux amd64 `c9f7494f…` (19,529,994 B), arm64 `d2925b4d…` (17,694,986 B), fingerprint
  `2026.10.10.1.b089a4e2`, reproducible builds. Public URL and Aliyun mirror hashes verified.
- Attestation rollover first: `ULTRA_ATTESTATION_EXTRA_HASHES_JSON` now also lists the 2026.10.02.1
  Linux hashes. `.env` backup: `/root/release-ultra-20261010-1/env.before`.
- 0 lease refusals after the publish. The test host self-updated about 2 minutes later.

## Verification (test host, 10 Mbps UDP + 10 Mbps TCP, 20 s warm-up)

| Runtime | UDP loss | Exits |
|---|---|---|
| 2026.10.02.1 (old) | 21.9% | 4 in 150 s |
| 2026.10.10.1 candidate, 3 runs | 0%, 0%, 0.08% | 0 |
| 2026.10.10.1 auto-installed by the agent, 2 runs | 0%, 0% | 0 |

## Not done

- **macOS**: the runtime is bundled in the app, so the fix needs macOS 1.0.54 from the build Mac;
  both build Macs were offline on Tailscale. The build scripts are already fixed.
- Windows / Android run 2026.09.25.1 (not garbled) and are unaffected.
- Startup gap: after a runtime start, UDP carriers attach 5-20 s after the status reports `ready`.
  Pre-existing; it only shows on restarts.
- Remove the 2026.10.02.1 entry from `ULTRA_ATTESTATION_EXTRA_HASHES_JSON` once no device reports it
  (macOS will keep reporting it until 1.0.54 ships; its hashes are still the published macOS entries).
