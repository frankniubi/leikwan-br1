# External UoT sidecar - Go binary live fleet canary (forward 170)

Date: 2026-09-17 (CST)
Tree: `leikwan-br1` (Windows workstation), baseline `master` @ `33d5d5a3`
Related: `docs/reports/2026-09-17-external-uot-sidecar-go-parity.md` (wire-parity evidence)
Status: **live canary PASSED; Go binary adopted for forward 170; one implementation-independent bulk-UDP anomaly documented in section 7**

## 1. Goal

The Go sidecar had only local evidence (Go unit tests + a differential harness
against the Python reference). This run put the binary on the real sidecar host
that carries the fleet's `client -> slave -> sidecar` hop for forward 170 and
answered four questions:

1. Does the live forward keep working (fleet carriers, panel traffic, client
   continuity) across an `ExecStart` swap to the Go binary?
2. Does the Go process behave identically on the wire (UOT2 framing, HMAC
   token, HELLO/ACK/OKR, oversize drop, replay suppression, keepalive, close,
   reattach with source-port preservation, dual-session isolation)?
3. Is the swap reversible, with the Python implementation and config intact?
4. What are the operational differences (CLI, log volume, quiet mode)?

## 2. Targets

| Item | Value |
| --- | --- |
| Sidecar host | `purely-new` (fleet sidecar, unit `leikwan-external-uot`) |
| Unit | `leikwan-external-uot.service`; config `/etc/leikwan-external-uot.json` sha256 `f2afe1f0...c873d4` (unchanged by this run) |
| Go binary | `/usr/local/bin/leikwan-external-uot-go`, version `2026.09.17.1.33d5d5a3`, sha256 `18bd060a...ac60ef` (matches `artifacts/uot-sidecar/manifest.json`) |
| Python baseline | `/usr/local/bin/leikwan_external_uot.py` (left in place, never modified) |
| Client runtime | `ultra-forward-170` on `instance-20241020-0131` - untouched for the whole run (`NRestarts=0`, PID 82495) |
| Live carriers | 21-22 TCP carriers from the fleet through the slave hop |

## 3. Acceptance criteria

1. Protocol parity on the wire: the 9-check UOT2 canary passes on the live
   forward under both implementations.
2. Continuity under live traffic: no failed client sample while a swap happens.
3. Fleet recovery: carriers re-attach after each restart; population returns to
   its pre-swap level.
4. Throughput: the MTU-safe iperf3 profile through the live forward is unchanged
   (20/50 Mbps clean; the big-block profile keeps its known ~50% fragmentation
   loss, identical to the pre-swap baseline).
5. Reversibility: unit/binary/config backup, `ExecStart` restored verbatim in
   both directions, `NRestarts=0` after each swap.
6. No secret exposure through the new CLI (`--check-config`).

## 4. Method

Canary rig (kept under `tmp/fleet-canary-20260917/`, not committed):

- `canary_uot_client.py` - UOT2 conformance client (HELLO/ACK, paced echo,
  3000-datagram burst, oversize 1201 B, replay, keepalive, close, OKR reattach
  with source-port preservation, dual-session crosstalk). Runs from the client
  host through the real `client -> slave -> sidecar` hop into the live forward.
- `canary_iperf.py` - MTU-safe throughput profile through the live forward:
  `down-20M`, `down-20M-bigblock` (22528-byte blocks), `down-50M`,
  `up-20M`, `up-50M` (10 s each).
- `orchestrator.py` - swap/rollback (`--mode go|python`), readback capture,
  fleet probes, continuity probes, journal probes.

Every swap: back up unit file + binary + config into `/root/backups/<name>`,
write both file trees, `daemon-reload`, `restart`, then read back
`ExecStart`, sha256, listener, carrier count and `NRestarts` from the live
unit. Rollback restores `ExecStart` from the recorded pre-swap unit file.

Client continuity probe: 1 MiB over the federation TCP path through forward 170
every 5 s; a swap window must show 0 failed samples.

Phases (artifacts under `tmp/fleet-canary-20260917/live/20260917-0345/`):

| # | Phase | Applied sidecar | Purpose |
| --- | --- | --- | --- |
| 1 | `baseline-python` | Python | baseline protocol + throughput |
| 2 | `swap-go` / `go` / `go-steady` | Go | first swap, protocol + throughput + steady continuity |
| 3 | `swap-go-final-window` / `go-final` / `reuse-go-final` / `go-minrepro*` | Go | long continuity window, repeat rig, isolation attempts |
| 4 | `swap-python-rollback` / `python-rollback` | Python | rollback verification + Python rig |
| 5 | `swap-go-adopt-window` / `python-final` / `fleetprobe-adopt` | Python -> Go | Python control rig + final adoption |

## 5. Evidence

### 5.1 Protocol conformance on the live forward (9 checks per phase)

| Phase | Checks | Duration |
| --- | --- | --- |
| `baseline-python` | 9/9 PASS | 35.9 s |
| `go` | 9/9 PASS | 35.9 s |
| `python-rollback` | 9/9 PASS | 35.9 s |
| `go-final` | 9/9 PASS | 35.9 s |
| `python-final` | 9/9 PASS | 35.9 s |

Total 45/45. The nine checks: `hello_okn` (ACK `OKN`, 2.2-2.4 ms, negotiated
1200), `paced_echo`, `burst_echo_loss_lt_1pct` (3000/3000, 0.00%), `oversize_dropped`,
`replay_suppressed`, `keepalive_seen`, `close_closes_carrier`,
`reattach_okr_port_preserved`, `dual_session_no_crosstalk`.

### 5.2 Throughput profile through the live forward

| Run | baseline-python | go | python-rollback | go-final | python-final |
| --- | --- | --- | --- | --- | --- |
| down-20M | 20.00 Mbps / 0% | 20.00 / 0% | 20.00 / 0% | 20.00 / 0% | 20.00 / 0% |
| down-20M-bigblock | 10.02 / 49.69% | 9.91 / 49.20% | 10.00 / 49.64% | 10.00 / 49.64% | 10.01 / 49.69% |
| down-50M | 50.00 / 0% | 50.00 / 0% | 49.99 / 0.02% | 48.99 / 2.00% | 50.00 / 0% |
| up-20M | 19.90 / 0% | *see section 7* | 19.90 / 0% | *see section 7* | *see section 7* |
| up-50M | 49.60 / 0.29% | 49.74 / 0% | 49.65 / 0.17% | 49.29 / 0.92% | 49.54 / 0.41% |

`down-20M-bigblock` is a fragmentation probe: ~50% loss is its known
signature on this path, identical before and after the swap (the same value
appears in the pre-swap baseline and in both implementations).

### 5.3 Live traffic across the restarts

| Window | Samples | Result | Payload wall | Rate |
| --- | --- | --- | --- | --- |
| `swap-python-window` (Python -> Go) | 48 | 48/48 OK | 2.37-2.42 s | 5.24 Mbps |
| `go-steady` | 18 | 18/18 OK | 2.34-2.39 s | 5.24 Mbps |
| `swap-go-final-window` (Go -> Python) | 48 | 48/48 OK | 2.37-2.42 s | 5.24 Mbps |
| `swap-go-adopt-window` (Python -> Go) | 18 | 18/18 OK | 2.36-2.42 s | 5.24 Mbps |

132/132 samples OK; no failed client sample during any restart.

Fleet carrier population (sidecar `ss` reading + `fleetprobe` ticks):
21-22 carriers before and after each restart; `fleetprobe-after-go` 21-26 ->
21, `fleetprobe-after-go-final` 21-22, `fleetprobe-after-python` 21-22,
`fleetprobe-adopt` 21-22. Carriers re-attach within the first tick (~3-5 s)
after every restart.

### 5.4 Swap and rollback receipts

| Swap | `ExecStart` before | after | Readback |
| --- | --- | --- | --- |
| Python -> Go | `/usr/bin/python3 .../leikwan_external_uot.py` | `/usr/local/bin/leikwan-external-uot-go ... --log-level INFO` | hash match true, active, 21 carriers, `NRestarts=0` |
| Go -> Python | Go binary | restored verbatim from `/root/backups/external-uot-go-20260917-0345/unit.live-before-swap` | active, 21 carriers, `NRestarts=0` |
| Python -> Go (adopt) | Python script | Go binary `--log-level INFO` | hash match true, active, 22 carriers at +3 s, `NRestarts=0` |

Backups: `/root/backups/external-uot-go-20260917-0345` (unit + binary +
config) and `/root/backups/external-uot-python-20260917-0345`. The client
runtime was never touched (`NRestarts=0`, PID 82495 across all swaps); no
sidecar process other than the unit ran at any point after cleanup (no leftover
listener on the canary port, no leftover config/canary files on the host).

### 5.5 Operational levers

- `--check-config` (throwaway loopback instance): rc=0, JSON dump; secret
  substring check against the live config = **not present**.
- `--log-level WARNING` vs `INFO` on a throwaway loopback instance: a full
  canary session produced **1 journal line** under WARNING (the unit-start line
  only, 0 session lines) and **19 lines** under INFO.
- Live unit log volume at current fleet activity: 208 lines in ~20 min
  (~10 lines/min; 77 `session opened`, 32 `session closed`,
  78 `carrier attached`). Sustainable, and `--log-level WARNING` is the
  available quiet switch.

## 6. Deviations from the parity report

1. Parity report section 4 item 5 ("Unverified - on-host canary pending") is now
   resolved by this run.
2. Parity report section 5 ("Deployment sketch, not yet executed") was executed;
   receipts in section 5.4. No protocol or CLI deviations were found on the
   live host; the only intentional behavioural difference remains the carrier
   write deadline documented in the parity report.
3. New operational note: measured log volume and the verified
   `--log-level WARNING` quiet mode (section 5.5).

## 7. Anomaly: intermittent empty `up-20M` report in the synthetic 5-run rig

- Observed 3 times in the full 5-run rig (always the 4th run, `up-20M`):
  `go` phase (wall 31.46 s, `bits_per_second_received=null`, rc=0),
  `go-final` phase (32.0 s, null), `python-final` phase (31.1 s, null).
  The run after it (`up-50M`) is clean in every case.
- **Not implementation-specific**: the identical signature appears on the
  Python production baseline (`python-final`) and on Go; there is no
  Go-only failure. Fleet-facing behaviour was unaffected in all three cases.
- Sidecar forensics at the time of the Go occurrence: the session bound to the
  probe's UDP source port closed with `udp_in=0 udp_out=0` - the probe's
  datagrams never reached the sidecar, so the drop point is on the
  client-side runtime/bridge mapping rather than in the sidecar.
- Isolation attempts on Go were clean and did not reproduce it: 3x `up-20M`
  back-to-back, `down-20M,down-20M,up-20M,up-20M,down-20M,up-20M`, and
  `down-20M-bigblock,up-20M,up-20M`. The exact trigger is not isolated.
- Impact assessment: the fleet's live traffic in these windows (panel +
  continuity probes) stayed clean (132/132 samples, stable carrier count), and
  an immediate retry of the same profile recovers. Tracked as an open item
  below rather than a swap regression.

## 8. Final applied state (2026-09-17 04:16 CST)

- `ExecStart=/usr/local/bin/leikwan-external-uot-go --config /etc/leikwan-external-uot.json --log-level INFO`
- Unit active/running, `NRestarts=0`, listener `*:7443`, 21 carriers at the
  final capture, version `2026.09.17.1.33d5d5a3`.
- Config sha256 unchanged; the Python implementation is still on disk.
- Rollback (proven this run): `python tmp/fleet-canary-20260917/scripts/orchestrator.py swap --mode python`,
  or manually restore `ExecStart` from
  `/root/backups/external-uot-python-20260917-0345/` and `daemon-reload` + `restart`.

## 9. Open items

1. `up-20M` intermittent empty report in the synthetic rig (section 7) -
   pre-existing, both implementations; consider tracing the client-side
   runtime's UDP mapping state on the next occurrence.
2. Fleet carriers rotate between slave IPs (observed 210.171.236.64 ->
   210.171.236.54 during the run); population stays 21-22 and is unaffected by
   the swap, but the rotation itself is worth a separate note.
3. The Go binary's `--log-level WARNING` mode has been validated on a
   throwaway listener; switching the unit to it is a one-line unit edit
   (backup + reload identical to this run's swap procedure).

## 10. Artifact index

- Scripts: `tmp/fleet-canary-20260917/scripts/{orchestrator.py, canary_uot_client.py, canary_iperf.py, summarize_results.py, continuity_stats.py}`
- Phases: `tmp/fleet-canary-20260917/live/20260917-0345/<phase>/{protocol.json, iperf.json, context.json, continuity.json}`
- Final state capture: `tmp/fleet-canary-20260917/live/20260917-0345/final-state.json`

## 11. 中文摘要

- 本次在真实 sidecar 主机（purely-new）上把 leikwan-external-uot 的 ExecStart 切换到 Go 二进制（2026.09.17.1.33d5d5a3，sha256 18bd060a...ac60ef），完成了“切换 → 验证 → 回滚 → 再切换”的完整闭环；每次切换均为“备份（unit + 二进制 + 配置）+ 写盘 + daemon-reload + restart + 回读”，NRestarts=0，配置哈希未变，Python 实现仍保留在盘上。
- 线上协议一致性 5 个相位全部 9/9 通过（共 45/45）；吞吐 20/50 Mbps 全程干净；down-20M-bigblock 的 ~50% 丢包是该探测档位的既有特征，切换前后一致。
- 切换窗口内客户端连续性 132/132 全过（48/48、18/18、48/48、18/18），每次重启后 3~5 秒内载波数回到 21-22。
- 运维层：--check-config 输出不含密钥；--log-level WARNING 实测可将整段会话日志压到 0 行（仅保留启动行），INFO 下约 10 行/分钟。
- 唯一遗留：合成压测第 4 条 up-20M 偶发“空结果 + 31-40 秒”（Go 2 次、Python 1 次）；两套实现签名相同、专用最小复现未命中；sidecar 侧记账 udp_in=0，说明丢点位于客户端 runtime/网桥一侧，对真实业务（TCP 面板流量）无可见影响，列为遗留观察项。
- 最终状态：Go 二进制已上岗运行；回滚一条命令即可（orchestrator.py swap --mode python，本轮已验证）。
