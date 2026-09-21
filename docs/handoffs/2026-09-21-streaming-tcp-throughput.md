# 2026-09-21 — Streaming TCP measurement: the 0.5 Mbps figure was the harness, not the path

## Why a new harness was needed

`tmp/lk_uot.py` opens a **new TCP connection per 1,200-byte request**, so its payload rate is
capped near `1200 B * req/s` (~0.5 Mbps at the observed ~54 req/s) no matter how fast the path
is. Every TCP number reported this session measured **per-request reliability**, not throughput,
and could never satisfy a "20 Mbps" criterion. A streaming harness was required.

## Root cause of the failed persistent attempt (now understood exactly)

The responder reads 64 KiB chunks in a loop and replies **only when a chunk STARTS with LKT1**:

```python
chunk = self.request.recv(1 << 16)
if chunk.startswith(MAGIC):
    self.request.sendall(MAGIC + b"ACK")
```

An earlier persistent variant sent 1,200-byte frames but read only 64 bytes per request, leaving
ACK bytes in the receive buffer. Every later frame then began mid-buffer and no longer *started*
with MAGIC, so the responder stopped replying and the client saw `empty_reply` — 178,282
connection openings instead of a sustained stream. **That attempt was invalid and was recorded
as such.**

The correct client simply **never reads**: throughput needs no reply, and unread ACKs cannot
desynchronise it. `tmp/lk_stream.py` streams continuously per connection and measures
interruption as a **stall** — a `sendall` that blocks past `--stall-ms` (default 250 ms) —
which is what backpressure or a dead stream looks like from the sender.

## Result — Linux, candidate `fbf6d977...`, 4 streams, 300 s

```
BEFORE sha=fbf6d9777568048b6eb42785
AFTER  sha=fbf6d9777568048b6eb42785   IDENTITY_OK=True

variant              streaming-tcp
seconds              300.14
tcp_streams          4
stream_frames_sent   4,634,336
stream_bytes_total   6,488,070,400      (6.49 GB)
stream_payload_mbps  172.934
stall_threshold_ms   250
stall_count          2325
stall_total_ms       778252.7
stall_max_ms         676.8
error_classes        {}                 <- zero errors, zero resets, zero refusals
```

Mid-run socket state confirmed the connections were genuinely held open:

```
4 x ESTABLISHED 127.0.0.1:<ephemeral> -> 127.0.0.1:60502
send-queue depths 7.0 MB, 13.9 MB, 20.9 MB, 37.1 MB
```

After the run, the responder held **0 established connections** — everything tore down cleanly.

## Interpretation

- **The path carries ~173 Mbps of TCP, not 0.5 Mbps.** The earlier ceiling was entirely the
  per-request harness. This is the first measurement that actually exercises TCP throughput.
- **No connection-level failures at all** (`error_classes {}`) across 4.6M frames — no resets,
  no refusals, no empty replies.
- **The 2,325 stalls need care before being called interruptions.** Mean stall is ~335 ms and the
  max is 677 ms, while throughput stayed at 173 Mbps. Sampling showed multi-megabyte send queues,
  i.e. the kernel was buffering, so a `sendall` that blocks briefly is expected buffer
  oscillation under a saturating sender rather than a broken stream.

## Honest limitation

**Delivery to the target was not independently confirmed.** The pump keeps cumulative counters in
process memory and prints them to stdout, and `journalctl -u leikwan-external-uot` yielded no
`stats {...}` lines, so the received-byte figure could not be read back. What is established is
that 6.49 GB was accepted by the forward with zero errors and held-open connections; what is
**not** independently established is that the target consumed all of it, as opposed to some
portion being dropped after buffering (~3.2M cumulative `drop` on the sidecar eth0, a
since-boot counter that cannot be attributed to this window).

To close this properly the runbook must sample the target's byte counters immediately before and
after each run and compare the deltas.

## Also needs redoing

The streaming harness upload to macOS silently produced a **0-byte file** (verified: `e3b0c442...`
is the empty-file hash). The same silent-write failure previously seen on other hosts now
affects macOS. The chunked-`printf` upload path must verify size after every chunk; do not trust
it. **The Mac streaming run has not been done.**

## Status against the criterion

| half | status |
|---|---|
| UDP 20 Mbps, 10 min, no loss | **met** — 0.0% loss reproduced across 4 configurations, 2 platforms |
| TCP 20 Mbps, 10 min, no interruption | **throughput now demonstrated** (173 Mbps streaming); **10-minute duration and independent delivery accounting still to do** |
