# Ultra review probe — measurement from source host 10.10.10.171 (2026-09-14 ~17:30-17:50 UTC)

First per-endpoint, end-to-end measurement produced by the repaired probe, from the
source host, after the deploy recorded in
`2026-09-14-ultra-review-probe-operator-note-171-REDERIVED.md`.

## How it was produced (and what it is not)

No panel-issued review could be used: `UltraCandidateReviewService.php:58` sets
`REENQUEUE_COOLDOWN_SECONDS = 21600` (6 h) and line 581's last review failed at
15:55:40 UTC, so the next panel review is due ~21:55 UTC. Instead the DEPLOYED agent's
own `run_review()` was driven with the panel's real inputs, which is the same code path
`process_reviews()` uses:

* advertised relays: `113.31.106.203`, `113.31.111.9`, `113.31.108.63`
  (`ultra_lines.relay_ips_json` for line 581 / source-home-171);
* target: `38.207.136.247:32469`, resolved exactly as `clientTarget()` does
  (zone `tokyo` -> lowest forward id 159).

It is therefore a measurement of the deployed probe on a genuinely advertised endpoint,
but it is NOT a panel-issued review row.

## Result — advertised endpoint, 600 s window, 15 rounds

```text
relay                       113.31.106.203:5438   (advertised_ok=True)
target                      38.207.136.247:32469
rounds                      15
udp_ok / udp_failed         15 / 0        (relay-local keep echo)
big_ok / big_failed         15 / 0        (relay-local 1400 B echo)
dns_ok / dns_failed         15 / 0        (strict through-relay DNS answer)
min_rtt_ms                  4.68          (NOT 0.06-0.08 loopback)
passed                      False
proof.reason                no_end_to_end_transfer
target_tcp_ok               0
target_tcp_failed           15
target_tcp_zero_downstream  0
target_tcp_sample           04040101
last_error                  no_downstream_bytes
target_tcp_min_close_ms     39
target_tcp_max_close_ms     1072
```

## The finding: the relay is not silent — it refuses the target

The relay answered each stream with a 4-byte connect-reply prefix `04 01 04 01`, i.e. a
SOCKS-style reply frame with **REP = 0x04, host unreachable**, then closed. Reproduced
identically in three consecutive rounds on `113.31.111.9:5438` (close 1040 / 1042 /
2049 ms) and in the 15-round window above.

Control targets on the same relay with the same credentials:

| target | downstream bytes | reply frame | interpreted REP | close_ms |
| ------ | ---------------- | ----------- | --------------- | -------- |
| 38.207.136.247:32469 (reviewed) | 4 | `04 01 04 01` | 0x04 host unreachable | 39-1072 |
| 1.1.1.1:53 | 10 | `04 01 01 00 01 05 38 b7 9a de` | 0x01 general failure | 6005 |
| 8.8.8.8:53 | 10 | `04 01 01 00 01 05 37 3c 22 ef` | 0x01 general failure | 2078 |

So the relay DOES perform the outbound connect and DOES return a refusal code. The
reviewed destination is refused with `0x04` (host unreachable) while unrelated
destinations get `0x01` (general failure). This refines the working theory: the fault on
this path is an explicit target refusal, not a relay that accepts the greeting and
forwards nothing.

**Not established:** that `38.207.136.247:32469` is forward 184's destination. It is the
panel's own `clientTarget()` answer for zone `tokyo` (lowest forward id wins), which is a
reproducibility rule, not proof of identity. Verify the forward id before attributing
this refusal to forward 184.

## Probe defect this exposed (reported, not silently patched)

`source-agent/ultra_line_review.py`:

* `:504-512` `valid_connect_frame()` requires exactly 10 bytes and never inspects the
  reply code;
* `:528-532` a short prefix therefore never becomes a `connect_frame`, `frame[2]` (the
  reply byte) is never read, and `:530-531` cannot fire;
* `:478-479` `downstream_bytes == 4` so the round is labelled `no_downstream_bytes`
  rather than naming the relay's refusal;
* `:930-931` `target_tcp_zero_downstream` increments only when `downstream_bytes == 0`,
  so it **undercounts** and reports 0 for this fault.

The operator note's diagnostic advice (read `target_tcp_zero_downstream` as the fault
signature) is therefore wrong for exactly the fault it was written for. The fix is to
decode the reply code and treat a truncated reply frame as the relay's verdict.

## Blocker that remains (panel-side inventory, not the probe)

The advertised set frequently does not intersect the supplier's live pool, so the
repaired probe fails closed (`no advertised relay in the line material`, `rounds=0`):

| line | overlap between advertised set and acquired material |
| ---- | --------------------------------------------------- |
| 7 | 2 of 3 — selects a relay |
| 9 | 0 — refuses |
| 17 | 0 — refuses |
| 581 | 1 of 4 acquisitions, then 1 of 8, then 1 of 16 |

Most `ultra_lines` rows for source-home-171 carry `relay_ips_json = NULL`; a NULL set is
handled as "no mandate" (the agent proceeds and the panel's proof gate refuses), while a
non-empty set with no intersection makes the agent refuse locally.

Consequence: while the advertised set and the live pool disagree, reviews report
`blocked` even on a healthy relay, because the probe is not allowed to test an endpoint
nobody advertised. That is the intended fail-safe direction, and it is an inventory
problem to fix on the panel.
