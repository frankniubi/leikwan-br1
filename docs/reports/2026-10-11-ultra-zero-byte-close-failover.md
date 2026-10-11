# Ultra runtime 2026.10.11.1: fail over from relays that close with zero bytes (2026-10-11)

## Ticket

IKK-785135 (WHMCS client 563, service S947 = plan 12, forward 263, combo 51 ultra-can-hkg,
local :10018 -> boilhkt99u.imgamer.top:10016). Two TCP outages on 2026-10-11, about three minutes
each: 14:22-14:25:37 and 15:45:06-15:47:43 Beijing time (06:22 and 07:45 UTC).

## What happened

The selected CAN->HKG LeiGod relay (line 17 or 447, source-home-171) ACKed each stream, took the first
flight and closed it cleanly about 10 ms later with zero bytes. It sent no `04 01 00` target-refused
frame. About 1580 local connections per outage hit the same relay. Another candidate was healthy the
whole time.

- The runtime read every such stream as an ordinary short exchange (`noteRelayOutcome` escalated only
  on a remote-first TCP reset). No strike, no isolation, no failover.
- TCP has no reselect timer. Same-generation renewals stay on the selected relay
  (`stickyLeaseForPolicy`). Recovery came only with the next panel policy generation: 06:24:48 and
  07:47:02 UTC.
- During the second outage a renewal failed (`preserving last known-good runtime`; no leases were
  issued from 07:46:03 to 07:47:02 UTC), which kept the bad relay a little longer.
- Server-side canaries did not see it either: 5518 `canary_probe_ok` events for lines 17/447 that day
  and no failures. The canary checks the handshake only.

## Fix (commit d4923c4e)

- `noteRelayOutcome` classifies a **zero-byte close**: the remote stopped first, the read ended in a
  clean EOF, nothing was read, upstream bytes > 0 and lifetime <= 500 ms. A close inside 100 ms of the
  ACK was already caught in setup and failed over; this covers the close after the first flight.
- `PolicyStore.NoteZeroByteClose`: 3 such closes on one path to one target within 10 s isolate the
  path, but only when another usable path exists.
  - The isolation counts as business evidence, so the active-path rescue cannot revive the path on a
    bare handshake.
  - Windows escalate 15 s, 45 s, 2 min, and the isolation survives same-generation renewals.
  - Nothing is reported as global candidate reputation.
- Target-side guard: if the same target trips on the alternative path within 1 minute, the first path
  is restored, the second is not isolated, and zero-byte isolation for that target pauses for
  2 minutes.
- `NoteStreamDelivered`: a stream that started before a zero-byte isolation no longer clears it when it
  finishes with data (a long download opened while the relay was healthy).

## Verification

- `go test` for the whole ultra-client package on Windows: pass (203 s).
- On the Linux test host 172.21.255.189 (kernel 6.1), using a cross-compiled test binary:
  - the HA and zero-byte tests pass five times in a row;
  - `go test -race -count=3` over the same set is clean.
- The full Linux suite has 3 failures, and all of them also fail on unmodified master:
  - `TestLocalCloseDuringBlockedRemoteWriteEndsPromptly` and `TestUnansweredUploadIsNotAWriteStall`
    fail identically on master c6123506, so they are Linux socket-buffer sensitivities, not
    regressions;
  - `TestGameSim` could not write its report file.
- New tests:
  - `ha_zero_byte_close_test.go` covers isolation and the move to the sibling, the no-alternative
    rule, the target-side guard, the exact-signal guards (different targets, bytes read, slow close,
    local first, nothing sent), the pre-isolation stream rule, escalation, and a kernel-socket
    loopback where the provider sends FIN after the first flight.
  - `TestHATCPRelayAmbiguousSilentStreamsRemainEligible` now covers EOFs below the strike count, slow
    EOFs and EOFs after a read.
- Live canary: temporary forward 295 (service 30, combo 51 CAN->HKG, TCP, :60690 -> 1.1.1.1:80) on the
  test host, with HTTP request/reply:

| Runtime | Requests | OK | p50 | p95 | zero-byte isolations | exits |
|---|---|---|---|---|---|---|
| 2026.10.10.1 (current) | 150 | 150 | 120.5 ms | 129.7 ms | - | 0 |
| 2026.10.11.1 candidate | 150 | 150 | 119.7 ms | 129.1 ms | 0 | 0 |
| candidate, 4 parallel workers | 480 | 480 | 118 ms | 128-131 ms | 0 | 0 |

## Release `ultra-runtime-20261011-1`

- Published 10:49:23 UTC through `deploy-client-artifacts.py`.
  - Status `committed`; backup in `/root/codex-backups/ultra-runtime-20261011-1`.
  - Plan and stage are in `/root/release-ultra-20261011-1`.
- Artifacts, fingerprint `2026.10.11.1.d4923c4e`, garble v0.17.0 `-literals` (no tiny mode), builds
  reproducible:

| Arch | SHA-256 | Size |
|---|---|---|
| Linux amd64 | `7544f4be…` | 19,030,282 B |
| Linux arm64 | `0cbdd98e…` | 17,367,306 B |

- Hashes verified on the panel disk, the public URL and the Aliyun control-plane mirror
  (`api-leikwanbridge.nbwly.com`). `v3-mirror.leikwanhost.com` does not resolve.
- Attestation rollover was done first. `ULTRA_ATTESTATION_EXTRA_HASHES_JSON` now also lists the
  2026.10.10.1 Linux hashes. `.env` backups are `env.before-canary` and `env.before-rollover` in the
  release directory.
- 0 lease refusals after the publish. The test host self-updated within about a minute.
- The canary pieces are removed: forward 295 is deleted, the test device exemption is removed, and the
  drop-in and candidate binary are gone from the test host.

## Not done

- Windows, Android and macOS bundle their own runtime. They pick up this fix only with their next client
  release. The ticket customer runs the Linux agent.
- Server-side canary: a data-level probe (send a payload, require a reply) would let panel reputation
  catch such relays too. Not changed here.
