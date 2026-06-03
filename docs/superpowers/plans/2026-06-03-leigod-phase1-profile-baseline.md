# LeiGod Mode — Phase 1: Profile + Baseline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> **NOTE:** This is a **measurement/profiling runbook**, not a code-TDD plan. Each task is "run command → observe → record into the findings doc." The deliverable is the findings doc that decides Phase 2's optimization lever. No production config is changed; profiling runs against combo 5's already-live leigod legs.

**Goal:** Determine where the per-packet CPU goes on a leigod slave and establish the per-leg / aggregate / latency baseline, so Phase 2 optimizes the dominant cost (kernel-WG-first) instead of guessing.

**Architecture:** Profile one combo-5 leigod leg (slave → sidecar over WG-carried-by-lguot-over-LeiGod-SOCKS) under iperf3 load; break down slave CPU by process (WG / lguot / LeiGod client / gost); then measure end-to-end single-leg vs the ~200 Mbit/s line cap, and 2–3-slave bond aggregate + latency.

**Tech Stack:** Linux perf tooling (`pidstat`, `mpstat`, `perf top`, `wg`), `iperf3`, `ping`, `journalctl`; access via PuTTY `plink`/`pscp` from Windows (see `[[ssh-tooling-plink-hosts]]`).

**Hosts (combo 5):**
- Client `sh36`: `root@sh36.ctyun.mcbar.cc` (114.80.33.50) / `Frank20021212.` — reachable (flaky; retry).
- Panel: `root@10.0.10.196` / `Frank1212.`.
- Slaves (combo 5, group `sha-tencent`): display IPs `150.158.103.182`, `111.231.3.220`, `111.229.217.7` (panel node ids 2/19/20). **SSH creds: fetch from `leikwan-v3.txt` / panel node inventory** (Task 0); if direct SSH is blocked, jump via `sh36` (`sshpass` is installed there).
- Sidecar `nrt1`: `185.220.238.143` — direct SSH from local is historically refused; sidecar-side profiling is **best-effort** (Task 5), the SLAVE is the primary target.

**Findings doc to produce/commit:** `docs/2026-06-03-leigod-phase1-profile-findings.md`

---

### Task 0: Access + identify the live leigod components on one slave

**Files:**
- Create: `docs/2026-06-03-leigod-phase1-profile-findings.md` (skeleton)

- [ ] **Step 1: Confirm combo 5 leigod legs are live and pick a profiling slave**

Run (panel):
```
plink -batch -ssh -pw 'Frank1212.' root@10.0.10.196 "mysql -h 127.0.0.1 -P 3306 -u v3_leikwanhost_c -p6ZHZTXYTxABn8rsX v3_leikwanhost_c -e \"SELECT id,backend_mode,self_leg_outer_transport,leigod_line_id,sidecar_point_id FROM forward_combinations WHERE id=5;\""
```
Expected: `self_leg_outer_transport=leigod`, `leigod_line_id=453`, `sidecar_point_id=5` (combo 5 keeps leigod legs even in the cf_mode hybrid). Pick slave `150.158.103.182` as the profiling target.

- [ ] **Step 2: Obtain slave SSH creds and confirm reachability**

Fetch creds from `leikwan-v3.txt` (project host inventory) or panel node config. Then:
```
plink -batch -ssh -pw '<SLAVE_PW>' root@150.158.103.182 "hostname; nproc; uptime"
```
If refused, jump via sh36:
```
plink -batch -ssh -pw 'Frank20021212.' root@sh36.ctyun.mcbar.cc "sshpass -p '<SLAVE_PW>' ssh -o StrictHostKeyChecking=no root@150.158.103.182 'hostname; nproc; uptime'"
```
Expected: hostname + `nproc` (confirm it is 2 vCPU) + load.

- [ ] **Step 3: Identify the leigod runtime processes + WG interface on the slave**

Run (on the slave):
```
systemctl list-units --type=service --state=running | grep -iE 'leikwan|leigod|gost|lguot|wg'
ps -ww -eo pid,comm,cmd | grep -iE 'lguot|gost|leigod|wireguard' | grep -v grep
wg show 2>/dev/null | head -40; ip -br link | grep -iE 'lws|wg'
```
Record into the findings doc: the WG interface name (e.g. `lws...`), the lguot client unit + cmdline, the LeiGod client binary/process, any gost process, and the leigod SOCKS local port.

- [ ] **Step 4: Commit the findings-doc skeleton**

```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): phase-1 profile findings skeleton + slave runtime inventory"
```

---

### Task 1: WireGuard kernel vs userspace (the keystone question)

- [ ] **Step 1: Check WG mode on the profiling slave**

Run (on the slave):
```
lsmod | grep -i wireguard && echo "KERNEL MODULE PRESENT" || echo "NO KERNEL MODULE"
ps -ww -eo comm,cmd | grep -i 'wireguard-go' | grep -v grep && echo "USERSPACE wireguard-go RUNNING" || echo "no wireguard-go"
wg show <IFACE> 2>/dev/null | head -5    # kernel wg responds; userspace also responds via wg(8) — combine with the two checks above
```
Expected: one of "kernel module present + no wireguard-go" (kernel WG) or "wireguard-go running" (userspace WG). **Record which.** This is the single most decision-relevant result for Phase 2.

- [ ] **Step 2: Repeat for the sidecar (best-effort)**

If the sidecar is reachable (via panel jump or agent), run the same three commands; else record "sidecar WG mode: unknown (SSH refused), revisit." Record.

- [ ] **Step 3: Commit the findings update**

```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): phase-1 WG kernel-vs-userspace finding"
```

---

### Task 2: Single-leg throughput baseline vs the 200 Mbit/s line cap

- [ ] **Step 1: Find the sidecar iperf3 target reachable over this slave's WG tunnel**

Run (on the slave): `wg show <IFACE> allowed-ips` and note the sidecar WG peer IP. Confirm the managed iperf3 target (panel runs `leikwan-bond-sidecar-5-iperf3-5201` on the sidecar). The test target is the sidecar's WG-side IP `:5201` (reachable through the tunnel).

- [ ] **Step 2: Drive one leg at full tilt and record throughput**

Run (on the slave), 30s, single + parallel streams, with a connect timeout so it can't hang:
```
iperf3 -c <SIDECAR_WG_IP> -p 5201 -t 30 --connect-timeout 4000            # single stream
iperf3 -c <SIDECAR_WG_IP> -p 5201 -t 30 -P 4 --connect-timeout 4000       # 4 streams (saturate)
```
Expected: a SUM receiver Mbit/s. **Record both.** Interpretation: if it plateaus well under 200 (e.g. ~70–120), the leg is CPU/transport-bound below its line cap (the thing Phase 2 fixes); if it reaches ~190–200, the line cap — not CPU — is the per-leg ceiling.

- [ ] **Step 3: Commit**

```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): phase-1 single-leg throughput baseline"
```

---

### Task 3: Per-process CPU breakdown on the slave under load (the core finding)

- [ ] **Step 1: Start the load, capture per-process CPU + per-CPU utilization**

In one slave shell start the saturating iperf3 (`-t 60 -P 4`); in a second slave shell capture during the run:
```
pidstat -u -h -p ALL 1 30 | awk 'NR<=3 || $8>2'     # per-process %CPU (lguot, wireguard-go?, the LeiGod client, gost, iperf3)
mpstat -P ALL 1 5                                    # per-core: %usr vs %sys (WG kernel crypto shows as %sys)
```
Record the top CPU consumers with their %CPU. Attribute: WG (kernel = high `%sys` on mpstat; userspace = `wireguard-go` in pidstat), lguot, the LeiGod client process, gost.

- [ ] **Step 2: Kernel-stack hotspots (if `perf` is available)**

Run (on the slave) during load:
```
perf top -d 5 2>/dev/null | head -25   # OR: perf record -g -a -- sleep 10 && perf report --stdio | head -40
```
Record the top symbols (e.g. WG crypto `chacha20poly1305`, copy_user, socket syscalls). If `perf` is absent, note it and rely on pidstat/mpstat.

- [ ] **Step 3: Record the verdict — which component dominates**

Into the findings doc, write the dominant cost: **(a)** userspace WG crypto, **(b)** the LeiGod client process, **(c)** lguot, or **(d)** kernel WG `%sys` (already efficient → line/LeiGod-bound). This selects Phase 2's first lever.

- [ ] **Step 4: Commit**

```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): phase-1 slave per-process CPU breakdown + dominant-cost verdict"
```

---

### Task 4: Single-leg latency baseline

- [ ] **Step 1: Idle and loaded RTT over the leg**

Run (on the slave): `ping -c 20 <SIDECAR_WG_IP>` idle, then again *during* the Task-2 iperf3 load. Record idle RTT, loaded RTT, and the inflation (bufferbloat indicator). This is the latency floor the bonded path must preserve.

- [ ] **Step 2: Commit**

```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): phase-1 single-leg RTT baseline (idle + loaded)"
```

---

### Task 5: Bond aggregate + latency baseline (end-to-end via the client)

- [ ] **Step 1: Measure the current bond aggregate from the client**

On `sh36`, run ONE spaced measurement through the live forward (port 11035) and capture cooldowns, per the established discipline (no restart storms):
```
iperf3 -c 127.0.0.1 -p 11035 -t 20 -P 8 --connect-timeout 4000 2>&1 | grep -iE 'SUM|receiver|error'
journalctl --since '-30s' --no-pager | grep -c 'entering cooldown'
ping -c 20 <forward-egress-or-target>     # aggregate latency
```
Record: aggregate Mbit/s (receiver), cooldown count, aggregate RTT. Compare aggregate vs single-leg×N and aggregate RTT vs single-leg RTT (Task 4). **Note:** combo 5 is the cf_mode hybrid now, so this aggregate includes the CF leg — record that caveat; the slave-side single-leg numbers (Tasks 2–4) are the clean per-leg signal.

- [ ] **Step 2: Commit**

```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): phase-1 bond aggregate + latency baseline"
```

---

### Task 6: Synthesize → Phase 2 lever decision

- [ ] **Step 1: Write the Phase 1 conclusion**

Into the findings doc, fill a summary table: WG mode (kernel/userspace); single-leg throughput vs 200; dominant CPU component; single-leg idle/loaded RTT; bond aggregate + RTT + cooldowns. Then state the **Phase 2 first lever**, consistent with the spec's kernel-WG-first rule:
- WG userspace → **Phase 2 starts with kernel WG**.
- WG already kernel + LeiGod-client dominates → **Phase 2 starts with packet-count reduction (GSO/MTU)**; note 200/leg may need bigger slaves/more legs.
- WG kernel + lguot dominates → **Phase 2 starts with lguot writev/batch**.
- Single leg already ~200 → per-leg is line-capped; **Phase 2 focuses on aggregation + latency only**.

- [ ] **Step 2: Commit the completed findings doc**

```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): phase-1 profile complete — Phase 2 lever decided"
```

- [ ] **Step 3: Hand back for the Phase 2 plan**

Phase 2/3 plans are written from these findings (the optimization is profile-driven). Surface the dominant-cost verdict to the user and request the go-ahead to write the Phase 2 plan.

---

## Self-Review (against the spec)

- **Spec §4 Phase 1 coverage:** WG kernel/userspace (Task 1) ✓; per-packet CPU breakdown (Task 3) ✓; single-leg throughput vs 200 (Task 2) ✓; single-leg RTT (Task 4) ✓; bond aggregate + RTT + cooldown (Task 5) ✓; output decides Phase 2 lever (Task 6) ✓.
- **Kernel-WG-first keystone:** Task 1 is the decision-relevant first measurement; Task 6 routes to kernel-WG-first. ✓
- **No production change:** all tasks are read-only profiling against live legs; no config flips, no restarts beyond a single spaced iperf3 (Task 5). ✓
- **Placeholders:** `<SLAVE_PW>`, `<IFACE>`, `<SIDECAR_WG_IP>` are runtime-discovered values with the discovery command given inline (not unspecified work) — acceptable for a runbook; every command is concrete otherwise.
- **Access risk noted:** slave creds source + sh36 jump; sidecar best-effort. ✓
