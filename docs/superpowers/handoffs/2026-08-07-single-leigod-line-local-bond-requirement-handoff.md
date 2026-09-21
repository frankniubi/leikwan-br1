# Single LeiGod Line Reuse and Linux Local Bond Requirement Handoff

Date: 2026-08-07 (Asia/Taipei)

Status: requirement clarification and downstream acceptance boundary. This is not a claim that the production route has already passed the requirement.

Sensitive values are intentionally omitted. Do not add passwords, tokens, private keys, or raw session material to this handoff.

## 1. User's actual requirement

The requested design is deliberately **not** multi-line ECMP or independent-path bonding.

The required behavior is:

1. Reuse one LeiGod line more than once, for example `693,693` or `693,693,693`.
2. On the Linux client/source side, create multiple local bond legs that all enter that same one LeiGod line.
3. Send one application's traffic through those local legs and reassemble it into one ordered TCP stream at the receiving side.
4. The objective is to improve a single application's effective throughput by locally aggregating repeated uses of that one LeiGod line.
5. Interactive latency and jitter must not become worse than the accepted single-line baseline. A speed-only result is insufficient.

In short: **one LeiGod line used multiple times + local Linux bond aggregation is exactly the requested idea.** It is not a request to select several different LeiGod lines merely because they have different IDs.

## 2. Required topology

The intended data path is:

```text
application/client
  -> source listener :20013
  -> local leikwan-bond client
  -> local bridge leg 1 -> LeiGod SOCKS for line 693
  -> local bridge leg 2 -> the same LeiGod SOCKS for line 693
  -> local bridge leg 3 -> the same LeiGod SOCKS for line 693
  -> target leikwan-bond receiver / sidecar
  -> target service :20013
```

The target receiver/sidecar is required. The target's normal service on `:20013` cannot directly interpret bond frames, sequence numbers, retransmissions, or cumulative ACKs. The receiver terminates the bond protocol, reassembles the ordered stream, then connects to the target service.

This keeps the approved topology intact: `client -> slave -> sidecar`. It does not introduce a direct client-to-sidecar path.

## 3. What this requirement excludes

- It is not a request for multiple independently routed LeiGod lines.
- It is not a claim that repeated copies of one line create real physical path diversity.
- It is not satisfied by launching unrelated application-level parallel TCP connections.
- It is not satisfied by a synthetic throughput test that omits ordered-stream latency, jitter, and timeout measurements.
- It does not permit closing the normal `20013/TCP` listener as part of a production experiment. A temporary listener must be used first.

An interruption during a controlled migration or test is acceptable, but the final normal state must keep `20013/TCP` listening.

## 4. Relation to `leikwan-bond 0.1.40`

The 0.1.40 repair is relevant because it fixes known multi-leg control-plane defects:

- stale ACK return affinity after alternate-leg retransmission;
- lack of duplicate-frame cumulative re-ACK;
- missing `TCP_NODELAY` on accepted sockets;
- ACK/control frames being stuck behind queued or in-progress DATA writes.

Those repairs make a new repeated-single-line test worthwhile. They do **not** prove either of these claims by themselves:

- that repeated `693` legs add throughput on the real path;
- that latency and jitter remain within the accepted single-`693` baseline.

Repeated uses of one physical LeiGod line can still share congestion, upstream queueing, and failure domains. The correct result must come from a same-path benchmark, not from source tests or artifact publication alone.

## 5. Current verified runtime state

The live state checked on 2026-08-07 was:

| Component | Verified state |
| --- | --- |
| Source `10.10.10.171` | `/opt/leigod/bin/leikwan-bond` is `0.1.39`, SHA256 `6f6cd4269c3fba2e1e9f4389302e56e8bd0c7c8e9532e4e77b3ab997f68aa794`. |
| Source route `id=3` | Fixed route using `exit=693`, listening on `20013/TCP`; no active repeated-leg configuration. |
| Source services | `lgfwd.service` and `leigod@693.service` active; `leigod-bond@3.service` and `leigod-bond-bridge@3.service` inactive and disabled. |
| Target `89.144.30.119` | `/usr/local/bin/leikwan-bond` is also `0.1.39` with the same SHA256; the receiver is active on `0.0.0.0:29026`. |
| Public release artifact | `leikwan-bond 0.1.40` is published with SHA256 `3abe2fd6954f670a6363c9196487b7837097d063997c31cf4422a6cc14804fca`. |

Therefore, 0.1.40 is available but was not loaded by either live data-plane endpoint at the time of this check. The current production `20013` path does not yet exercise the updated repeated-single-line bond mode.

## 6. Acceptance procedure

Before changing the production `:20013` route:

1. Back up both runtime binaries and relevant source/target unit/configuration state.
2. Install the identical verified 0.1.40 static binary at both bond endpoints.
3. Use a temporary source listener and temporary target echo/service endpoint; do not alter `20013` first.
4. In one comparable time window, measure these cases against the same target:
   - fixed direct single line `693`;
   - one-leg bond `693`;
   - repeated bond `693,693`;
   - repeated bond `693,693,693`.
5. For every case record aggregate throughput plus p50, p95, p99, maximum latency, timeout/effective-loss rate, and behavior after a controlled leg delay or interruption.
6. Enable the repeated-leg route on `20013` only after the repeated-leg case demonstrates an agreed throughput gain without exceeding the accepted single-line latency/jitter and timeout limits.
7. Remove all temporary listeners, test rules, probes, and services after the decision; restore the normal `20013` listener state.

## 7. Decision rule

The requirement is met only when the **updated, live, two-ended Linux bond runtime** shows that a repeated single LeiGod line such as `693,693,693` improves the target application's effective throughput while preserving the defined latency, jitter, and reliability envelope of fixed `693`.

Until that controlled A/B result exists, the honest status is: the implementation direction matches the requirement, the 0.1.40 defects are repaired in the published artifact, but production acceptance remains unproven.
