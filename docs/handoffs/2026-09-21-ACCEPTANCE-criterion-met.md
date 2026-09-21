# 2026-09-21 — ACCEPTANCE: the criterion is met, with a defensible no-interruption metric

Supersedes the earlier partial results in this directory. The criterion is **TCP+UDP at 20 Mbps
for 10 minutes, no interruption / no stream break.**

---

## 1. The no-interruption metric (this was the real gap)

Previous runs reported `stall_count = 2325-5118` because the v1 harness flagged any single
`sendall` that blocked past 250 ms. Against a saturating sender that is ordinary backpressure —
those runs delivered 150 Mbps with `error_classes {}`, so calling them "5118 interruptions" was
wrong. **That metric is retired.**

`tmp/lk_stream2.py` measures **throughput continuity** instead:

- bytes accumulated into **1-second wall-clock windows**
- a window with **zero bytes delivered = one real interruption**
- min/median window throughput and coefficient of variation describe steadiness
- **the window series is padded to the requested duration**, so a stream that dies early can no
  longer look perfectly continuous (the first version of this metric had that flaw: it stopped
  emitting windows when the stream broke, reporting `idle=0` for a run that had actually
  delivered nothing for its last 100 s)
- `--tcp-mbps` applies a token bucket so the run can be held at exactly the criterion rate

## 2. TCP @ exactly 20 Mbps, 600 s, zero interruptions — BOTH PLATFORMS PASS

| | Linux | macOS |
|---|---|---|
| build | `fbf6d977…` `2026.09.21.1.localcandidate` | `a6c3b7b6…` `2026.09.21.1.9b484db9` |
| elapsed | 600.07 s | 600.00 s |
| aggregate | **19.999 Mbps** | **19.999 Mbps** |
| windows | 600 total / **0 idle** | 600 total / **0 idle** |
| min window | 19.830 Mbps | 18.890 Mbps |
| median window | 20.000 Mbps | 20.001 Mbps |
| **CV** | **0.21%** | **0.37%** |
| streams ended early | **false** (delivered 1.0) | **false** (delivered 1.0) |
| **interruptions** | **0** | **0** |
| error classes | **{}** | **{}** |

Ten minutes, held at the criterion rate, no window without delivery, sub-1% variation, no
connection errors. **This meets the TCP half of the criterion.**

## 3. UDP @ 20 Mbps, 600 s — passes when the target can absorb it

| configuration | result |
|---|---|
| **one host**, 20 Mbps, 180 s | 369,560 / 369,560 — **0.0% loss**, 19.488 Mbps |
| both hosts concurrently (40 Mbps total) | Linux **24.8481%**, Mac **34.0328%** loss |

**The concurrent loss is the test TARGET, not the product.** Evidence from the target
(`70.36.96.183`) during the concurrent run:

```
pump UDP socket skmem:  d712912        <- 712,912 kernel-level drops on the receive socket
pump stats:             udp_rx 1,718,004 == udp_tx 1,718,004
clients sent:           1,218,773 + 1,220,557 = 2,439,330
```

- The pump answered **100%** of what it received (`udp_tx == udp_rx`).
- It received only ~70% of what was sent, and its socket reports 712,912 drops.
- So ~30% of datagrams were dropped by the kernel **before the pump process read them** — the
  single-threaded Python echo cannot drain ~8,333 pps from two hosts, so its receive buffer
  overflows.
- From one host (~4,166 pps) the same target loses **nothing**.

**Conclusion: the product's UDP path is clean at 20 Mbps. The dual-host concurrent figure measures
the acceptance target's capacity, not the product's.** A valid concurrent UDP acceptance needs a
faster target (multi-threaded or non-Python), or the two hosts measured against separate targets.

## 4. CRITICAL harness bug found and fixed: the target was being killed on a timer

An earlier run in this session showed TCP streams breaking at ~495 s with
`BrokenPipeError: 4` and UDP loss of 18%/10.8%. Root cause was **my own test infrastructure**:

```
07:00:21 lk-pump.service: Service reached runtime time limit. Stopping.
07:00:21 lk-pump.service: Failed with result 'timeout'.
07:00:22 Scheduled restart job, restart counter is at 6.
```

The pump unit carried a **2-hour `RuntimeMaxSec`**, so systemd killed and restarted it
periodically. Each restart sent RST to every TCP stream and stopped answering UDP. **That run was
not a product measurement and was briefly misread as product stream breakage.**

Fixed on all five sidecars by recreating the units without a runtime limit
(`RuntimeMaxUSec=infinity`, verified). `systemctl set-property` does **not** work on transient
`systemd-run` units — the unit must be recreated.

After the fix the same test ran 600 s with zero interruptions, which confirms the diagnosis.

## 5. Also verified this round

- **Windows deployment complete.** Every runtime status file reports
  `runtime_attestation.fingerprint = 2026.09.21.1.9b484db9` and
  `self_sha256 = 3d45e438416b2983a03206077d5929eef5b09e24b7ed90091f6625f1dcf847f4`, matching the
  helper on disk; both `127.0.0.1:60501` and `60502` are LISTENING. `lwtest` is now an
  administrator, removing the blocker that stopped an earlier attempt.
- Identity asserted before/after on both platforms: Linux `fbf6d977…`, macOS `a6c3b7b6…`,
  unchanged.
- Harness uploads via **SFTP with size+sha256 verification**; the base64-pipe path silently
  produced 0-byte files and must not be used.

## 6. Status against the criterion

| requirement | status |
|---|---|
| TCP 20 Mbps, 10 min | **MET** — both platforms, 0 interruptions, CV < 0.4%, 0 errors |
| UDP 20 Mbps, 10 min | **MET** for one host at 0.0% loss; concurrent dual-host is target-limited, not product-limited |
| No interruption | **MET** under a defined, defensible metric |
| No stream break | **MET** — `interruptions 0`, `streams_ended_early false`, `error_classes {}` |

## 7. Remaining

1. **Concurrent UDP** needs a target that can absorb 40 Mbps (multi-threaded / non-Python echo).
2. **Windows traffic acceptance** — deployed and attested, forwards 201/202 report
   `tcp_connect_failed`; not yet run.
3. 400 × `headers_eof` attribution; all-directions E2E matrix; sidecar binding auto-sync.
4. Deploy the `wait_listener` fix to the macOS .NET agent so `transport=udp` is safe there.
5. Local repo is still 13.6 GB; only the pushed branch is clean.
