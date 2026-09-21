# 2026-09-21 — Verified 600 s run on the candidate (identity asserted before/after)

After discovering the auto-update had replaced the candidate (see
`2026-09-21-linux-host-no-longer-runs-candidate.md`), the hold was re-armed, the candidate
redeployed atomically, and the run repeated with the binary identity asserted **immediately
before, during, and after** the measurement.

## Identity

```
hold:    LEIKWAN_UPDATE_HOLD_UNTIL=1789995786  (~8h from 1789966986)
BEFORE   sha=fbf6d9777568048b6eb42785  version=2026.09.21.1.localcandidate
13 polls sha=fbf6d9777568048b6eb42785   (every minute of the run)
AFTER    sha=fbf6d9777568048b6eb42785  version=2026.09.21.1.localcandidate
IDENTITY_STABLE=True
```

Deploy note: `cp` over `/usr/local/bin/ultra-client` fails with `Text file busy` while a
runtime executes it. Replace atomically instead — `cp` to a temp name, then `mv` over the
target — which swaps the directory entry while running processes keep the old inode.

## Result — Linux, transport=tcp+udp, both protocols concurrently, 600 s

| protocol | result |
|---|---|
| UDP | 1,217,058 sent / **1,217,058 acked** — **0.0% loss**, 19.407 Mbps, 602.04 s |
| TCP | 32,297 ok / 74 fail — **0.2286%**, 0.518 Mbps payload, 600.12 s |

TCP error classes: `empty_reply` 64, `ConnectionResetError` 10.
Counters on both forwards: `state=ready`, `queue_drop=0`, `queue_timeout=0`,
`queue_blocked=0`, `socket_receive_drop=0`; `leikwan-client-agent NRestarts=0`.

## What this does and does not establish

**UDP is now reproducibly clean.** 0.0% loss has been observed across every configuration tried
on both platforms:

| run | platform | build | UDP loss |
|---|---|---|---|
| concurrent both-platform | Linux | candidate | 0.0% |
| concurrent both-platform | macOS | 9b484db9 | 0.0% |
| macOS re-measure (tcp+udp) | macOS | 9b484db9 | 0.0% |
| **verified, pinned** | **Linux** | **candidate** | **0.0%** |

The earlier 95.9928% macOS figure was the `wait_listener` defect, now root-caused and fixed
(`88644489`, deployed to the macOS agent as the runtime fix; `efd3f967` for the agent guard).

**TCP does not meet a 20 Mbps criterion, and the harness is the binding constraint.**
`tcp_payload_mbps` is 0.518 against a requested 20 because `lk_uot.py` opens a **new TCP
connection for every request** (1,200-byte payload each). At ~54 requests/s the payload rate is
~0.5 Mbps regardless of link capacity; the 20 Mbps request is only a rate cap the harness never
approaches. So this run measures **per-request reliability**, not TCP throughput.

Per-request reliability by build (all with concurrent UDP where noted):

| build | TCP fail | UDP concurrent |
|---|---|---|
| candidate, verified | 0.2286% | yes (both protocols, one host) |
| candidate, earlier | 1.1839% | yes (two hosts, shared sidecar) |
| macOS 9b484db9 | 0.1406% | yes (two hosts, shared sidecar) |
| macOS 9b484db9 | 0.2527% | no |
| candidate, earlier | 0.0% | yes (TCP on the other host) |

Failure classes are always `empty_reply` (connection accepted, request written, peer closes
without replying), and the rate rises with concurrent load on a shared sidecar. An isolated
Linux TCP-only run with no competing UDP gave **0/7,526**.

**Conclusion:** no build-specific TCP regression is demonstrated — the spread is consistent with
load-dependent sidecar contention. But **the 20 Mbps TCP half of the criterion is unproven, and
cannot be proven with this harness.** A streaming/persistent-connection TCP measurement is
required; the one attempt at a persistent variant this session was invalid
(`tcp_conns_opened=178,282` — the connection was re-established per request, so it measured a
connection storm the harness created, not persistence).

## Outstanding

1. Build a valid streaming TCP measurement and re-run the criterion.
2. Decide whether `empty_reply` under sidecar contention is acceptable, or whether the
   responder needs its own backpressure handling.
3. Add identity assertions to the standing runbook so no future result can be reported without a
   verified binary.
