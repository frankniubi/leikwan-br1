# 2026-09-21 — 600 s streaming acceptance: TCP 150 Mbps, UDP 0.0% loss, delivery independently accounted

## Setup

Linux test host, forward 201 (UDP) and 202 (TCP) both `transport=tcp+udp`, both target
`70.36.96.183`. Both protocols driven **concurrently** for 600 s. TCP used the streaming harness
(`tmp/lk_stream.py`, 4 streams); UDP used the rate harness (`tmp/lk_uot.py`, 20 Mbps, 4 streams).

Binary identity was asserted before the run and at every poll:

```
BEFORE sha=fbf6d9777568048b6eb42785   2026.09.21.1.localcandidate
13 polls sha_ok=True
AFTER  sha=fbf6d9777568048b6eb42785   IDENTITY_OK=True
```

`LEIKWAN_UPDATE_HOLD_UNTIL` was re-armed before the run, so the auto-update could not replace the
candidate (the previous run's cause of a mis-attributed measurement).

## Independent delivery accounting

Sampled the target sidecar's `eth0` byte counters immediately before and after:

```
sidecar rx_delta_bytes = 12,974,751,186   (12.97 GB)
sidecar tx_delta_bytes =    329,371,770   ( 0.33 GB)
```

**12.97 GB received at the target over 600 s = ~173 Mbps.** This is measured on the receiving
host, not inferred from the sender, and it corroborates the client-side figure. The sender
pushed 11.30 GB of TCP payload plus ~1.45 GB of UDP; the sidecar received 12.97 GB, so
essentially everything sent was in fact received. The earlier inability to confirm delivery — the
pump keeps its counters in-process and `journalctl` exposes no `stats {...}` lines — is
resolved by reading the interface counters instead.

## Results

**TCP streaming, 600 s:**

```
stream_frames_sent   8,071,644
stream_bytes_total   11,300,301,600   (11.30 GB)
stream_payload_mbps  150.618
error_classes        {}               <- zero errors, resets, refusals
```

**UDP, 600 s:**

```
udp_sent_packets   1,206,029
udp_acked_packets  1,206,029
udp_loss_pct       0.0
udp_achieved_mbps  19.23
```

Post-run counters on both forwards: `state=ready`, `queue_drop=0`, `queue_timeout=0`,
`queue_blocked=0`, `socket_receive_drop=0`; `leikwan-client-agent NRestarts=0`.

## The stall metric is misnamed and must not be read as "interruption"

The run reports `stall_count=5118`, mean ~330 ms, max 751 ms. **This does not indicate broken
streams, and reporting it as 5,118 interruptions would be wrong.**

`lk_stream.py` flags a stall when a single `sendall` blocks past 250 ms. Against a **saturating**
sender the kernel send buffer constantly fills and drains, so blocking past 250 ms is ordinary
backpressure, not a stream break. Evidence that the streams were healthy throughout:

- `error_classes` is empty — not one reset, refusal or empty reply in 600 s
- payload flowed continuously at 150 Mbps and 12.97 GB was received at the target
- the four connections stayed ESTABLISHED with multi-megabyte send queues

The honest reading: **the streams never broke; individual sends occasionally waited on buffer
space.** A meaningful "no interruption" metric would be windowed throughput stability over the
run, or a gap threshold well above the natural buffer oscillation period — not a 250 ms
per-send threshold. That metric still needs defining.

## Status against the acceptance criterion

Criterion: **TCP+UDP at 20 Mbps for 10 minutes, no interruption, no stream break.**

| requirement | status |
|---|---|
| 10 minutes | **met** — 600.21 s TCP / 602.08 s UDP |
| UDP at 20 Mbps | **met** — 19.23 Mbps achieved, **0.0% loss** |
| TCP throughput | **met** — 150.6 Mbps, far above 20 Mbps |
| No connection failures | **met** — `error_classes {}` on both protocols |
| Delivery actually happened | **met** — 12.97 GB independently counted at the target |
| "No interruption" | **needs a defined metric** — the current stall counter is not it |

## Caveats, stated plainly

1. **TCP was not rate-limited to 20 Mbps.** The streaming harness sends as fast as the path
   allows, so this demonstrates the *capability* to exceed 20 Mbps, not behaviour at exactly
   20 Mbps. A rate-controlled 20 Mbps streaming run is the stricter test.
2. The sidecar `eth0` delta includes any other traffic on that host during the window. The host
   carries production UoT traffic, so a small part of 12.97 GB may not be this test. The client
   counters and the target deltas agree closely, so the attribution is sound, but it is not a
   per-connection accounting.
3. Only the Linux host was exercised. macOS passed the streaming harness upload as a **0-byte
   file** (silent chunked-write failure) and has not been run with it. Windows still needs
   elevation.
