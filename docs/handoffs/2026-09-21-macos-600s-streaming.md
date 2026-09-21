# 2026-09-21 — macOS 600 s streaming acceptance: TCP clean, UDP shows 0.1% loss

## Setup

macOS test host, forwards 201 (UDP) and 202 (TCP), both `transport=tcp+udp`. Streaming harness
`lk_stream.py` (4 streams, sha `49b3ac26...`, uploaded via SFTP and verified byte-for-byte after
the base64-pipe path silently produced a 0-byte file) plus `lk_uot.py` for UDP at 20 Mbps.

Helper identity asserted before, at every poll, and after:

```
BEFORE  2026.09.21.1.9b484db9   a6c3b7b66c72aac57c67c3fa
11 polls a6c3b7b66c72aac57c67c3fa
AFTER   2026.09.21.1.9b484db9   a6c3b7b66c72aac57c67c3fa
```

Both listeners were up (`ultra-cli` TCP 60501 and 60502) and stayed up; no `wait_listener`
failures, no restarts.

## Results

**TCP streaming, 600 s:**

```
stream_frames_sent   1,880,954
stream_bytes_total   2,633,335,600   (2.63 GB)
stream_payload_mbps  35.07
error_classes        {}              <- zero errors, resets, refusals
```

**UDP, 600 s:**

```
udp_sent_packets   1,209,819
udp_acked_packets  1,208,527
udp_loss_pct       0.1068            <- 1,292 packets
udp_achieved_mbps  19.289
```

## Reading this correctly

**TCP is clean at the connection level.** `error_classes` is empty across 1.88M frames over ten
minutes: no resets, no refusals, no empty replies. 35 Mbps is well above the 20 Mbps the
criterion asks for.

**But the stall counter is large and the max stall is 7.7 s**, so this run does NOT demonstrate
"no interruption" under any strict reading:

```
stall_count  4346
stall_max_ms 7682.2
stall_total_ms 1,950,084   (3250 s of aggregate stall across 4 streams x 600 s = 2400 stream-s)
```

Note `stall_total_ms` (1,950,084 ms) **exceeds** the 2,400,000 ms total stream-time budget only
when summed per stream — it is 81% of aggregate stream-time, and a 7.68 s single blocking send is
not ordinary buffer oscillation the way Linux's 751 ms maximum was. So the same caveat that
applied to Linux applies here more strongly: **the metric is a per-send backpressure timer, not
an interruption detector**, and it is now clear the two platforms behave quite differently
(Linux max 751 ms at 150 Mbps; macOS max 7.68 s at 35 Mbps).

The most likely explanation is the same shared sidecar: macOS reaches it over a much longer path
(rtt ~44 ms in the runtime status versus Linux's much shorter hop), so a saturating sender
experiences deeper and longer queueing. **This is a hypothesis, not a finding.**

**UDP lost 0.1068%.** That is small but it is the first UDP loss observed on any 600 s run this
session — every previous run was exactly 0.0%. It must not be rounded away. It is either:

- genuine loss on the macOS path at 19.3 Mbps for 10 minutes, or
- the ack-counting boundary in the harness (the 2 s drain window at the end of a run can miss
  stragglers, which would undercount acks and appear as loss)

Both are plausible and this run does not distinguish them.

## Status against the criterion

| requirement | Linux | macOS |
|---|---|---|
| 10 minutes | met (600 s) | met (600 s) |
| UDP ~20 Mbps | met, 0.0% loss | met, **0.1068% loss** |
| TCP throughput | 150.6 Mbps | 35.07 Mbps |
| No connection failures | met (`{}`) | met (`{}`) |
| No interruption | needs a defined metric | needs a defined metric; max stall 7.68 s is concerning |

## Outstanding

1. **Define the no-interruption metric properly** (windowed throughput stability, or a gap
   threshold justified against observed buffer behaviour) instead of the 250 ms per-send timer.
   Re-run both platforms under it.
2. **Decide whether macOS's 0.1068% UDP loss is real** — extend the drain window and re-run, or
   compare against the target's own received-packet counter.
3. **Rate-limit the TCP test to 20 Mbps.** Both streaming runs were unthrottled, so neither
   demonstrates behaviour *at* 20 Mbps, only capability above it.
4. Windows still needs elevation; the whole matrix is still unrun in the other direction
   (target -> client).
