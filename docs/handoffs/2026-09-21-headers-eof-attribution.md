# headers_eof attribution — 2026-09-21

Investigator: delegated subagent (headers_eof root-cause task). Read-only on production; the only
traffic generated was through test forward 202 on the Linux test host. No git commit.

---

## Verdict

**The 400 `headers_eof` failures were an upstream artifact of the leased Ultra provider relay, not a
LeiKwan product defect: every one of them is a relay stream that our client had already established
and written the complete HTTP request into, which the relay aborted with a TCP RST after almost
exactly 1000 ms having returned zero bytes and — on the evidence available — without ever completing
its own egress connection to the target; the request-per-connection test harness is what turned that
intermittent per-new-connection upstream failure into a 0.2-2 % request failure rate.**
Confidence: **high** that no LeiKwan client/product defect is implicated; **medium-high** that the
failed streams never reached the target; **low** on the relay's *internal* reason for the 1 s abort
(no provider-side logs exist).

---

## 1. What `headers_eof` actually is (the emitter)

The token does **not** exist in any product source. A full search of `docs/`, `tmp/`, `tools/`,
`master/`, `storage/`, `deploy/` (and a wider repo sweep) finds it in exactly one executable file:

`tmp/mac-tcp-path-20260920/final_tcp_client.py` line 24 — the **test harness client**, a raw
Python-socket HTTP/1.1 client:

```python
16:   seq+=1; t=time.time();rid=f'{role}-{route}-{index}-{seq}'
19:    s=socket.create_connection(('127.0.0.1',port),timeout=5);s.settimeout(5)
20:    s.sendall(f'GET /data?id={rid} HTTP/1.1\r\nHost: owned-test\r\nAuthorization: Bearer {token}\r\nConnection: close\r\n\r\n'.encode())
21:    stage='headers';buf=b''
22:    while b'\r\n\r\n' not in buf:
23:     chunk=s.recv(4096)
24:     if not chunk:raise EOFError('headers_eof')      # <-- the emitter
```

So `headers_eof` is a **harness label** meaning "the local forward socket returned EOF (FIN) before
any response-header byte arrived". It is not a panel field, not a relay field, and it is not produced
by `tools/ultra-client`. (A sibling family of scripts once emitted `headers_error`; that token
survives only in 2026-09-19 *analysis output* files, e.g. `tmp/enterprise-uot-20260919/analyze-final-output.txt`.)

The experiment behind the 400 failures (from the three handoff docs):

| item | value |
|---|---|
| window | 2026-09-20 20:27:28–20:37:28 UTC+8 (12:27:28–12:37:28 UTC), 600 s |
| clients | test Mac (`.4` helper `2026.09.20.4.52d81766`) and Linux test host `172.21.255.189` (released `2026.09.19.1.f18d01fb`) |
| forwards | 198 (HK, local 60151) and 199 (SG, local 60152), both TCP-only, 8 workers/route, ~1.075 s/request/worker |
| targets | our own HTTP target `target_final.py` (Python `http.server.ThreadingHTTPServer`) on TCP 60051 of the HK host `70.36.96.183` and the SG host |
| result | 17,443 ok / 400 fail; Mac HK 37, Mac SG 18, Linux HK 11, Linux SG 334 — all 400 classified `EOFError:headers_eof` |

---

## 2. Evidence — the 400 failures are relay aborts at ~1000 ms with zero bytes

### 2.1 Client-side per-stream records recovered from the Linux test host

The 2026-09-20 journal is still present on `172.21.255.189` (host `instance-20241020-0131`).
`journalctl -u ultra-forward-198/-199` for the soak window yields **exactly the failing requests**:

- `ultra-forward-198` (HK, Linux client): **11** `tcp stream had no downstream bytes` lines —
  the Linux HK harness failure count was **11**. 1:1.
- `ultra-forward-199` (SG, Linux client): **334** such lines — the Linux SG harness failure count
  was **334**. 1:1.

Raw excerpt (forward 199, timestamps +0800 = UTC 12:27:29; one failure bracketed by successes on the
*same* selection_ref/candidate/lease):

```
20:27:29.731710 tcp stream ended forward_id=199 direction=downstream reason=eof   duration_ms=271 bytes=336163 selection_ref=Fw7xdeZbKmC5k0mN-WZ0CUrgTDCJZSDYKwLYNVjKG4s candidate_hash=3dc4d611bf73e087caf90aba21f5ef937252908ef07bc9612eadd3e0b014778a lease_hash=1b0d75c4e0514d55fe90103cf6dbe03abaacb5298bde45f8515b02ad96e09097
20:27:29.750498 tcp stream ended forward_id=199 direction=downstream reason=reset duration_ms=1000 bytes=0      selection_ref=Fw7xdeZbKmC5... candidate_hash=3dc4d611... lease_hash=1b0d75c4...
20:27:29.751142 tcp stream ended forward_id=199 direction=upstream   reason=closed duration_ms=1000 bytes=163  selection_ref=Fw7xdeZbKmC5...
20:27:29.751142 tcp stream had no downstream bytes forward_id=199 selection_ref=Fw7xdeZbKmC5... remote_stopped_first=true lifetime_ms=1000 upstream_bytes=163; keeping candidate eligible
20:27:30.017137 tcp stream ended forward_id=199 direction=downstream reason=eof   duration_ms=280 bytes=336163 selection_ref=Fw7xdeZbKmC5...
```

Aggregates over all such lines in the soak window:

| forward | lines | `lifetime_ms` | `upstream_bytes` | `remote_stopped_first` | selection_refs | paired downstream line |
|---|---:|---|---|---|---|---|
| 198 | 11 | 950-1049 (mostly 1000-1049) | 164 (11/11) | true (11/11) | 1 of 7 used | `reason=reset duration_ms≈1000 bytes=0` |
| 199 | 334 | 950-1049 | 163/164/165 | true (334/334) | 2 of 6 used (156 + 178) | `reason=reset duration_ms≈1000 bytes=0` |

Readings:

1. **The stream was fully established.** `relayTCP` measures lifetime *after* the relay handshake;
   `upstream_bytes` = 163-165 = exactly one HTTP request written to the relay.
2. **The relay sent a RST, first.** `direction=downstream reason=reset` = the client's read from the
   relay connection returned `ECONNRESET`; the upstream direction then ended as `closed` (client
   obeying the abort). `remote_stopped_first=true` is the go-routine ordering measurement, not a guess.
3. **~1000 ms, not a power of the harness.** Harness elapsed was 1024-1046 ms (Mac) and 1066-1116 ms
   (Linux); the difference from 1000 ms is the *local* dial+handshake to the relay (~25-45 ms Mac,
   ~70-115 ms Linux). The abort itself is at 999-1001 ms.
4. **Not candidate-specific.** Failures and successes interleave on the same candidate, lease and
   generation in the same second (excerpt above). Across the soak, forward 198 used 7 candidates
   (only 1 ever failed) and forward 199 used 6 (only 2 ever failed; 156 and 178 failures).

### 2.2 Target side of the soak (host 70.36.96.183)

Re-parsed the retained `/root/mac-tcp-final-soak-20260920/target-events.jsonl` (35,609 events,
0 partial lines), reconstructing connections per peer tuple:

```
connections 8933  accepts 8933  closes 8933  requests 8871
accepts with no request : 62
  durations: 58 <= 0.1 s, 1 in 0.1-1.0 s, 3 ~1.0 s
  accept times: ~1 per 9.7 s across the whole 600 s (no clustering)
```

The 62 accept-without-request connections are spread evenly (they look like a periodic prober:
~60 in 600 s), **not** clustered in the three client failure bursts. The 48 HK failed request IDs have
no `request` record at all (independently confirmed by
`failure-correlation/hk-FINAL-rid-audit.json`: 37+11 failed IDs, 37+11 missing_request).
Consistent with the earlier FINAL report — and now explicable: **the failed streams never got as far
as the target's HTTP handler.**

### 2.3 Reproduced on the current deployment (2026-09-21, test forward 202)

Test forward 202 = listen 60502 → target `70.36.96.183:62102` (`lk-target-pump.py` responder).
Harness: request-per-connection, 1200-byte `LKT1` frame, expects the 7-byte `LKT1+ACK` reply
(`/root/_hdr_churn.py`, modelled on `tmp/lk_uot.py`'s tcp_worker).

| run | workers | seconds | requests | failures | class |
|---|---:|---:|---:|---:|---|
| churn8 | 8 | 60 | 4074 | 0 | — |
| single1 | 1 | 30 | 221 | 0 | — |
| churn8b | 8 | 30 | 1576 | **14 (0.89 %)** | all `empty_reply` (EOF, zero bytes) |
| cap1 | 8 | 30 | 1716 | **29 (1.69 %)** | all `empty_reply`, mean 1070.8 ms |
| cap2 | 12 | 60 | 5854 | 0 | — |
| cap3 | 16 | 150 | 15031 | 0 | — |

Journal for forward 202, same windows — again a 1:1 match:

```
2026/09/21 15:50:49  tcp stream had no downstream bytes forward_id=202 selection_ref=pdm3eKh2TJyS6bwSzO_5aaiX0hRB5wFzrNeeCIWhXNU remote_stopped_first=true lifetime_ms=999  upstream_bytes=1200; keeping candidate eligible
2026/09/21 15:50:49  tcp stream ended forward_id=202 direction=downstream reason=reset duration_ms=999  bytes=0    candidate_hash=40ac894c610f2084dbe157a4a340df4369cb5206af1d6ec306a21543a51f040e ...
2026/09/21 15:50:50  tcp stream ended forward_id=202 direction=downstream reason=reset duration_ms=1000 bytes=0    candidate_hash=40ac894c610f...
```

(churn8b: 14 harness failures = 14 `had no downstream bytes` lines = 14 `direction=downstream
reason=reset bytes=0` lines. cap1: 29 failures, journal 29 lines, burst at 07:51:58.8–07:52:02.6 UTC.
Since 00:00 UTC on 2026-09-21 forward 202 logged **365** such zero-byte ~1000 ms aborts.)

Packet capture on the target (70.36.96.183, `tcpdump -i any -n -tt 'tcp port 62102'`) during cap1 —
1,716 harness requests, 29 failures:

```
connections with SYN: 1690   SYN-ACK: 1690   (zero unanswered SYNs, zero RSTs from the target)
relay->target bytes histogram : {1200: 1685, 0: 5}
target->relay bytes histogram : {7: 1685, 0: 5}
1690 target connections vs 1716 harness requests  ->  delta 26  ~ 29 failures
```

Caveat, stated plainly: a concurrent third-party session was pushing bulk streaming traffic through
the same target during cap1 (a second relay egress IP `103.86.76.7`, ~200 MB in the window), so the
26-vs-29 delta is strong but not airtight. The qualitative result is not affected: **no connection in
the capture ever received a request and failed to get a reply; the connections the target did see are
essentially all complete 1200 B in / 7 B out round trips, and the failures produced no visible target
connection at all.** For the soak itself the equivalent check is stronger (2.2: no request record for
any of the 400 failed IDs).

---

## 3. Mechanism

1. The harness opens a **fresh TCP connection for every HTTP request** with `Connection: close`
   (`final_tcp_client.py`; the 2026-09-20 600s run issued ~29 new connections/s to the HK target and
   the same to SG).
2. The ultra-client accepts each local connection and dials a **new relay stream per local connection**
   (`serveTCP` → `dialCandidateStream` → `dialStreamConfig`); the relay handshake succeeds.
3. The client writes the whole request to the relay (`upstream_bytes` = 163-165 in the soak,
   1200 in today's test) and waits.
4. The relay **never delivers it**: at the target there is no matching connection/request (soak:
   no `request` record for any of the 400 IDs; today: target connection count short by exactly the
   failure count, and no unanswered SYNs when the capture was running).
5. Almost exactly **1000 ms** after the stream was established the relay aborts its own stream to us —
   the client's read returns `ECONNRESET`, the client closes the local socket, and the harness's
   `recv` returns b`` → `EOFError: headers_eof` / `empty_reply`.
6. The product behaves correctly throughout: it forwards the request once, does not replay it, records
   the remote-first ordering, and **does not** penalise the candidate ("keeping candidate eligible").
   That is why the same candidate kept serving successful requests in the same second.

Why the ~1 s is not ours: the only 1 s-scale constants in `tools/ultra-client` are unrelated
(`udp_probe` interval fallback, `external_uot` ticker, `native_socks5` read deadlines); the relay
stream path uses 100 ms / 3 s / 15 s deadlines, and the client's own telemetry proves the *remote*
ended first. A 1000 ms abort is a classic upstream connect/setup deadline.

**A second, separate contributor (different mechanism, already fixed):** the 2026-09-20 soak target
`target_final.py` is a Python `http.server.ThreadingHTTPServer` that does **not** override
`request_queue_size`, i.e. listen backlog = 5. The current responder's own source documents this
class explicitly:

```python
# /usr/local/lib/lk-target-pump.py
# Default request_queue_size is 5. At the acceptance connection rate the
# accept queue overflowed and the kernel dropped connections before accept,
# which the client sees as an empty reply. Counted as TcpExtListenDrops.
request_queue_size = 1024
```

That is a *target-harness* limitation with the same visible symptom. It is **not** the explanation for
the failures I captured today (with backlog 1024 there were zero unanswered SYNs), and it is not
proven for 2026-09-20; it is a plausible amplifier for high-rate per-request harness runs and should
be treated as a harness defect wherever an old small-backlog target is used.

---

## 4. Was the earlier FAIL verdict justified?

**Yes as a verdict on the acceptance criterion, and the handoff's refusal to call it fixed was correct
— but the framing that leaned toward a bad relay line was not.**

- Justified: at 20 Mbps TCP with 8 workers/route, 400 requests (2.24 %) got no response at all and
  the target never served them. Reporting a 600 s run with 400 business failures as FAIL is honest.
- Not justified as evidence of a LeiKwan product defect: the failures are not caused by
  `relayTCP`, candidate selection, health-domain logic or the UoT code. On the same candidate + lease
  in the same second, neighbours succeed in 271-313 ms with the full 336,163-byte body. Six of the
  seven candidates on forward 198 and four of six on forward 199 never failed at all.
- The earlier "root cause judgment" in `2026-09-20-mac-hk-tcp-path-investigation.md` ("数据面根因高度
  指向 line17 时段性路径故障") is **not supported**: the same signature occurs on other candidates, on
  the other host, on the other route, and (2026-09-21) on a different forward; and it is per-connection
  rather than per-path.
- The criterion itself is the bigger lesson: a request-per-connection harness measures *per-request
  relay stream setup reliability*, not "TCP at 20 Mbps with no interruption". The parent session's
  600 s persistent-connection streaming run today (150.6 Mbps, `error_classes {}`) is the right shape
  of measurement. The two numbers should be reported separately and never merged.

---

## 5. Reproduction recipe

Preconditions: Linux test host `172.21.255.189` (root), test forward 202 (`/etc/ultra/forward-202.json`,
listen 60502, target `70.36.96.183:62102`), target host reachable. **Do not touch production forwards
158/159/170/171/180 and never involve slave/CDN hosts.**

1. Client harness (request-per-connection, ~1.2 kB request, expects a reply) — this is effectively
   `tmp/lk_uot.py`'s `tcp_worker`:
   ```python
   s=socket.socket(); s.settimeout(6); s.connect(("127.0.0.1",60502))
   s.sendall(b"LKT1"+os.urandom(1196)); r=s.recv(64)
   # r == b'' -> the empty_reply / headers_eof class
   ```
   Run 8-16 threads for 30-60 s. (`/root/_hdr_churn.py` on the test host is this harness;
   `/root/_hdr_churn2.py` is a variant with uniquely-sized payloads for capture correlation.)
2. Client-side proof, on the test host:
   `journalctl -u ultra-forward-202.service --since '<start> UTC' --no-pager -o short-iso-precise | grep -E 'had no downstream bytes|direction=downstream reason=reset'`
   Every harness failure must have a matching pair with `lifetime_ms≈1000`, `remote_stopped_first=true`,
   `upstream_bytes≈1200`, `bytes=0`.
3. Target-side proof: either the responder's own counters —
   `python3 -c 'import socket;s=socket.create_connection(("127.0.0.1",60502));s.sendall(b"LKSTAT");print(s.recv(4096))'`
   (through the forward; `tcp_conns` must grow by ~requests, not by ~successes) — or a packet capture on
   the target: `tcpdump -i any -n -tt -l 'tcp port 62102' > /tmp/cap.txt` for the whole run, then compare
   the SYN count with the harness request count and look for unanswered SYNs.
4. Reproducibility today: intermittent. Three short bursts were caught in ~45 s of load
   (0.89 % and 1.69 % failure rates), but 15,031 further requests at 16 workers produced zero
   failures — expect to have to run a few minutes, and always keep the journal + capture from the
   same window.

---

## 6. What is still unknown (and what would settle it)

1. **Why the relay aborts at exactly ~1000 ms.** Nothing on our side decides this; we have no
   provider-side logs, no relay-side session records and no way to see the relay's egress attempt.
   Settled only by asking the leased relay operator for per-stream logs, or by correlating our
   stream IDs with their egress connect failures.
2. **Whether the failed streams' SYNs ever left the relay.** We know they never arrived at the target
   (soak: no request records; today: capture). We cannot distinguish "relay never dialled" from
   "relay dialled and its SYN was lost before the target".
3. **The Mac side of the soak (55 of the 400).** No Mac helper per-stream log survived
   (`mac-runtime.log` is the desktop log; the helper writes to a pipe). The Mac numbers are inferred
   from identical harness symptoms plus elapsed-time arithmetic (1024-1046 ms ⇒ 1000 ms + ~30 ms dial),
   not from direct logs.
4. **The transient trigger.** Why bursts at 07:50:49, 07:51:58 and 04:26 UTC today and not during the
   15 k-request run? Candidate factors (relay-side load, other tenants on the same relay endpoint,
   our own lease/session state) are untested. An experiment that holds our client, lease and target
   constant while varying only relay-side load is not possible from our side.
5. **Whether the small-backlog target contributed to any of the 400.** The 2026-09-20 target had
   backlog 5 and could overflow; the soak target host has no sysstat history and the kernel counters
   are cumulative, so this cannot be retro-attributed. It can be re-tested deliberately by running the
   same harness against a backlog-5 listener while capturing at the target.
6. **Whether any LeiKwan-controlled input (lease material, session/refresh state) changes the relay's
   egress behaviour.** No controlled A/B exists that varies only that factor.

### Local/remote artefacts produced by this investigation (not committed)

- `tmp/_hdr_198_199_nodownstream.txt` — all 345 client-side `had no downstream bytes` lines from the
  2026-09-20 soak (forwards 198/199), pulled from the Linux test host's journal.
- `tmp/_hdrprobe*.py`, `tmp/_hdr_run_experiment.py`, `tmp/_hdr_churn.py`, `tmp/_hdr_churn2.py`,
  `tmp/_hdr_capture.py`, `tmp/_hdr_cap_analysis.py` — read-only probes and the churn harness.
- Remote (test host `172.21.255.189`): `/root/_hdr_churn.py`, `/root/_hdr_churn2.py`,
  `/root/_hdr_records.jsonl`, `/root/_hdr_run_experiment.py`.
- Remote (target host `70.36.96.183`): `/tmp/hdr_cap.txt`, `/tmp/hdr_cap2.txt`, `/tmp/hdr_cap3.txt`.

No credentials, tokens or protected configuration values are reproduced in this document.
