# LeiGod UoT CPU Efficiency — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Tasks 1, 2, 6, 7 are **live ops/measurement** (run on hosts, record); Tasks 3–5 are **Go code (TDD)** + build/deploy.

**Goal:** Lift each leigod leg from CPU-bound ~70–100 Mbit/s toward its ~200 Mbit/s unmetered-BGP line cap by (1) ensuring kernel WireGuard and (2) cutting lguot's per-datagram CPU, then confirm the bond aggregates past 200 at single-leg latency.

**Architecture:** leigod path is `WG(kernel) → lguot(UoT) → LeiGod SOCKS → sidecar`. Kernel WG slashes crypto CPU (first lever, always). lguot already batches `recvmmsg`/`sendmmsg`; its remaining cost is a per-datagram memcpy into a bufio buffer — replaced with a `writev` (scatter-gather) batch. Each change is gated by a before/after measurement so we never stack blind (Approach A).

**Tech Stack:** Go 1.x (`tools/lguot`, `golang.org/x/net/ipv4`, `net.Buffers`/writev); Linux `wireguard` kernel module + `wg-quick`; `pidstat`/`mpstat`/`iperf3`/`ping`; PuTTY `plink`/`pscp`.

**Hosts (combo 5):** client `sh36` 114.80.33.50 / `Frank20021212.`; panel `10.0.10.196` / `Frank1212.`; slaves (group `sha-tencent`) `150.158.103.182`,`111.231.3.220`,`111.229.217.7` (creds: `leikwan-v3.txt`/panel; jump via sh36 `sshpass` if direct SSH blocked); sidecar `nrt1` 185.220.238.143 (SSH best-effort).

**Findings doc:** `docs/2026-06-03-leigod-phase1-profile-findings.md` (records every before/after number).

---

### Task 1: Baseline measurement (single leg) — the gate for everything after

**Files:** Create `docs/2026-06-03-leigod-phase1-profile-findings.md`

- [ ] **Step 1: Pick slave + confirm leigod leg is live**

Run (panel): the combo-5 query from the Phase-1 runbook; confirm `self_leg_outer_transport=leigod`. Pick `150.158.103.182`. On the slave: `ps -ww -eo pid,comm,cmd | grep -iE 'lguot|gost|wireguard|leigod' | grep -v grep`; `wg show`. Record the WG iface, lguot unit, the LeiGod client process, the sidecar WG peer IP, and `nproc`.

- [ ] **Step 2: Baseline single-leg throughput + CPU + RTT**

On the slave, run a 60s saturating load and capture in a second shell:
```
iperf3 -c <SIDECAR_WG_IP> -p 5201 -t 60 -P 4 --connect-timeout 4000      # shell A (load)
pidstat -u -h -p ALL 1 30 | awk 'NR<=3 || $8>2'                          # shell B (per-proc %CPU)
mpstat -P ALL 1 5                                                         # shell B (%usr vs %sys)
ping -c 20 <SIDECAR_WG_IP>                                                # shell B (loaded RTT)
```
Record: SUM receiver Mbit/s, top CPU processes (lguot / wireguard-go? / LeiGod client / gost), %sys, loaded RTT. **This is the baseline every later task compares against.**

- [ ] **Step 3: Commit baseline**
```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): single-leg baseline (throughput/CPU/RTT)"
```

---

### Task 2: Kernel WireGuard (first lever, always)

**Files:** Modify (only if it forces userspace): `slave-agent/slave_agent.py` (the `wg-quick up` bring-up), `sidecar-agent/sidecar_agent.py`.

- [ ] **Step 1: Determine WG mode on the slave**
```
lsmod | grep -i wireguard && echo KERNEL_MODULE_PRESENT || echo NO_MODULE
ps -ww -eo cmd | grep -i 'wireguard-go' | grep -v grep && echo USERSPACE || echo no-userspace
```
Record. If KERNEL_MODULE_PRESENT and no `wireguard-go` → already kernel, skip to Step 4 (record "already kernel").

- [ ] **Step 2: Enable the kernel module (if userspace / missing)**
```
modprobe wireguard 2>&1 || (apt-get update && apt-get install -y wireguard-tools && modprobe wireguard)
lsmod | grep -i wireguard          # confirm loaded
```
Expected: module loads (recent kernels ship it built-in; older need `wireguard` pkg). If the kernel genuinely lacks it, record as a blocker (provider kernel) and stop Task 2.

- [ ] **Step 3: Force kernel for wg-quick + re-bring-up the interface**

Ensure no userspace override is set, then bounce the interface (the agent will reconcile it; a single restart is fine, no storm):
```
env | grep -i WG_QUICK_USERSPACE_IMPLEMENTATION    # must be empty
wg-quick down <IFACE>; wg-quick up <IFACE>
wg show <IFACE> | head -3
ps -ww -eo cmd | grep -i wireguard-go | grep -v grep || echo "no userspace wg now"
```
If `slave_agent.py`/`sidecar_agent.py` sets `WG_QUICK_USERSPACE_IMPLEMENTATION` or invokes `wireguard-go` directly, remove that (kernel is default when the module is present) and bump the agent version (Task 5 deploy mechanics). Record whether an agent edit was needed.

- [ ] **Step 4: Re-measure single leg (kernel WG)**

Repeat Task 1 Step 2's load+capture. Record new throughput / CPU / RTT vs baseline. Expected if it was userspace: large `%sys`-side CPU drop, throughput rises toward 200. If already kernel: no change (expected).

- [ ] **Step 5: Commit**
```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md slave-agent/slave_agent.py sidecar-agent/sidecar_agent.py 2>/dev/null
git commit -m "perf(leigod): ensure kernel WireGuard on slave/sidecar + remeasure"
```

> **Gate:** if Task 4 single-leg now ~200 and CPU not saturated, the per-leg goal is met by kernel WG alone — Tasks 3–4 (lguot opts) become optional; jump to Task 6 (deploy any agent edit) + Task 7 (aggregation). Otherwise continue.

---

### Task 3: lguot hot-loop — replace per-datagram copy with writev (Go, TDD)

**Files:**
- Modify: `tools/lguot/main.go`
- Test: `tools/lguot/main_test.go` (create)

- [ ] **Step 1: Write the failing test for a pure framing helper**

Create `tools/lguot/main_test.go`:
```go
package main

import (
	"bytes"
	"encoding/binary"
	"net"
	"testing"
)

func TestFrameBatchProducesLengthPrefixedWire(t *testing.T) {
	payloads := [][]byte{[]byte("ab"), []byte("hello"), {}}
	hdrs := make([][]byte, len(payloads))
	for i := range hdrs {
		hdrs[i] = make([]byte, 2)
	}
	var iov net.Buffers
	frameBatch(&iov, hdrs, payloads)

	var got bytes.Buffer
	if _, err := iov.WriteTo(&got); err != nil {
		t.Fatalf("WriteTo: %v", err)
	}
	var want bytes.Buffer
	for _, p := range payloads {
		h := make([]byte, 2)
		binary.BigEndian.PutUint16(h, uint16(len(p)))
		want.Write(h)
		want.Write(p)
	}
	if !bytes.Equal(got.Bytes(), want.Bytes()) {
		t.Fatalf("frame mismatch:\n got=%x\nwant=%x", got.Bytes(), want.Bytes())
	}
}
```

- [ ] **Step 2: Run it; verify it fails (frameBatch undefined)**

Run: `cd tools/lguot && go test ./... -run TestFrameBatch -v`
Expected: FAIL — `undefined: frameBatch`.

- [ ] **Step 3: Implement `frameBatch` (minimal)**

Add to `tools/lguot/main.go`:
```go
// frameBatch appends [uint16 len][payload] iovecs for each payload into iov, reusing hdrs[i]
// for the per-datagram length prefix (no payload copy). The caller flushes iov with a single
// writev via net.Buffers.WriteTo.
func frameBatch(iov *net.Buffers, hdrs [][]byte, payloads [][]byte) {
	for i, p := range payloads {
		binary.BigEndian.PutUint16(hdrs[i], uint16(len(p)))
		*iov = append(*iov, hdrs[i], p)
	}
}
```

- [ ] **Step 4: Run the test; verify it passes**

Run: `cd tools/lguot && go test ./... -run TestFrameBatch -v`
Expected: PASS.

- [ ] **Step 5: Rewire `udpToTCP` to use writev (no bufio copy)**

In `tools/lguot/main.go`, change `udpToTCP` to write framed batches directly to the conn via `net.Buffers.WriteTo` (writev), allocation-free across iterations:
```go
func udpToTCP(pc *ipv4.PacketConn, c net.Conn, learn *atomic.Value) error {
	ms := makeMsgs(batch)
	hdrs := make([][]byte, batch)
	for i := range hdrs {
		hdrs[i] = make([]byte, 2)
	}
	backing := make(net.Buffers, 0, batch*2)
	payloads := make([][]byte, 0, batch)
	var last *net.UDPAddr
	for {
		n, err := pc.ReadBatch(ms, 0)
		if err != nil {
			return err
		}
		payloads = payloads[:0]
		for i := 0; i < n; i++ {
			if learn != nil && ms[i].Addr != nil {
				if ua, ok := ms[i].Addr.(*net.UDPAddr); ok {
					if last == nil || last.Port != ua.Port || !last.IP.Equal(ua.IP) {
						learn.Store(ua)
						last = ua
					}
				}
			}
			payloads = append(payloads, ms[i].Buffers[0][:ms[i].N])
		}
		if len(payloads) == 0 {
			continue
		}
		iov := backing[:0]
		frameBatch(&iov, hdrs, payloads)
		if _, err := iov.WriteTo(c); err != nil { // single writev; blocks until written, so ms buffers are safe to reuse next ReadBatch
			return err
		}
	}
}
```

- [ ] **Step 6: Update `pump` to pass the raw conn (handshake still via bufio, then raw for the hot loop)**

In `pump`, after the optional token handshake on `w` (keep `writeFrame(w, ...)` + its flush), pass the raw conn to `udpToTCP` instead of `w`:
```go
	go func() { defer wg.Done(); _ = udpToTCP(pc, c, learn); c.Close() }()
	go func() { defer wg.Done(); _ = tcpToUDP(r, pc, getDst); c.Close() }()
```
(The read side `tcpToUDP(r, ...)` is unchanged. `w` is now used only for the handshake; that's fine — it is flushed before `udpToTCP` writes to `c`.)

- [ ] **Step 7: Verify build + tests + the round-trip still works**

Run: `cd tools/lguot && go vet ./... && go test ./... -v && go build -o /tmp/lguot .`
Expected: vet clean, tests PASS, build OK.

- [ ] **Step 8: Commit**
```bash
git add tools/lguot/main.go tools/lguot/main_test.go
git commit -m "perf(lguot): writev batch (drop per-datagram bufio copy) + frameBatch test"
```

---

### Task 4: Tune the batch size (cheap throughput knob)

**Files:** Modify `tools/lguot/main.go`

- [ ] **Step 1: Make `batch` a flag (default raised to 128)**

Replace the `const batch = 64` usage: add `-batch` flags to both modes (default 128), thread it into `makeMsgs`/`udpToTCP`/`tcpToUDP`. Keep `bufSize`/`tcpBuf`/`udpBuf` constants. (If threading is invasive, raise the const to 128 and note it.)

- [ ] **Step 2: Build + test**

Run: `cd tools/lguot && go test ./... -v && go build -o /tmp/lguot .`
Expected: PASS, build OK.

- [ ] **Step 3: Commit**
```bash
git add tools/lguot/main.go
git commit -m "perf(lguot): batch size flag (default 128)"
```

---

### Task 5: Build + deploy lguot (three-artifact, version bump)

**Files:** Modify `artifacts/lguot/lguot_linux_amd64` (rebuilt), `artifacts/versions.json`, and the served copies.

- [ ] **Step 1: Cross-build the linux binary**
```bash
cd tools/lguot && GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -o ../../artifacts/lguot/lguot_linux_amd64 .
sha256sum ../../artifacts/lguot/lguot_linux_amd64
```

- [ ] **Step 2: Bump the lguot artifact version**

Edit `artifacts/versions.json`: bump the lguot component version (e.g. `2026.06.03.1`) and its sha256 to the Step-1 value. (Grep `versions.json` for the lguot entry.)

- [ ] **Step 3: Deploy all three artifact locations (avoid the static-shadow churn — see `[[panel-artifact-serving]]`)**

`pscp` the new binary + `versions.json` to the panel so `slave-agent/`-served, `artifacts/`, and `master/public/artifacts/` copies all match:
```
pscp -batch -pw 'Frank1212.' artifacts/lguot/lguot_linux_amd64 root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/lguot/lguot_linux_amd64
pscp -batch -pw 'Frank1212.' artifacts/lguot/lguot_linux_amd64 root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/public/artifacts/lguot/lguot_linux_amd64
pscp -batch -pw 'Frank1212.' artifacts/versions.json root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json
```
Then verify all served copies' sha256 match Step 1 (plink `sha256sum` on each path). **All three must match** or the fleet downloads the stale shadow.

- [ ] **Step 4: Confirm fleet pickup on one slave**

On `150.158.103.182`: wait one heartbeat, then `sha256sum /usr/local/bin/lguot` == Step-1 sha; `systemctl status <lguot-unit>` active; `wg show <IFACE>` still healthy.

- [ ] **Step 5: Commit the artifact + version bump**
```bash
git add artifacts/lguot/lguot_linux_amd64 artifacts/versions.json
git commit -m "build(lguot): ship writev+batch build (version bump + three-artifact)"
```

---

### Task 6: Re-measure single leg after lguot deploy

- [ ] **Step 1: Repeat the single-leg load+capture (Task 1 Step 2) on the updated slave**

Record throughput / CPU / RTT vs the kernel-WG number (Task 2 Step 4). Expected: if lguot was a meaningful CPU share, throughput rises + lguot %CPU drops; if not, ~no change (confirms WG/LeiGod-client was dominant — documented, not a failure).

- [ ] **Step 2: Commit the post-deploy single-leg numbers**
```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): single-leg after kernel-WG + lguot writev/batch"
```

---

### Task 7: Aggregation + latency validation (combo 5, end-to-end)

- [ ] **Step 1: Confirm per-leg ~200 and bond aggregate > 200 at single-leg latency**

From `sh36`, ONE spaced measurement (no restart storms), then cooldowns + RTT:
```
iperf3 -c 127.0.0.1 -p 11035 -t 20 -P 8 --connect-timeout 4000 2>&1 | grep -iE 'SUM|receiver'
journalctl --since '-30s' --no-pager | grep -c 'entering cooldown'
ping -c 20 <target>
```
Record: aggregate Mbit/s (expect > single-leg, trending to N×~200 − overhead), cooldown count (expect ~0 — legs no longer CPU-saturated), aggregate RTT (expect ≈ single-leg idle RTT from Task 1). If aggregate latency is inflated by a slow leg, demote/weight it via the existing bond health machinery (note for a follow-up).

- [ ] **Step 2: Sustained-stability check**

Re-run cooldown count over a 5-min idle + a 60s load window; expect single-digit cooldowns. Record.

- [ ] **Step 3: Commit the final results + verdict**
```bash
git add docs/2026-06-03-leigod-phase1-profile-findings.md
git commit -m "docs(leigod): aggregation + latency validation; per-leg/aggregate/flap results"
```

- [ ] **Step 4: Decide next lever (if per-leg still < 200)**

If a single leg still can't reach ~200 after kernel WG + lguot, the residual cost is the LeiGod client process or the line itself (per the findings). Next levers (separate plan): UDP GSO / larger WG MTU (fewer packets), bigger slaves, or more legs. Record the recommendation.

---

## Self-Review (against the spec)

- **Spec §1 targets:** per-leg → 200 (Tasks 2/3/4/6) ✓; aggregate > 200 (Task 7) ✓; latency ≈ single-leg (Task 7 + RTT in 1/2/6) ✓.
- **Spec keystone — kernel WG first:** Task 2 precedes the lguot work and has a gate to skip lguot if kernel WG alone hits 200 ✓.
- **Spec §3 profile-first:** Task 1 baseline gates everything; each change re-measured (Tasks 2/6/7); lguot opts explicitly optional if not the dominant cost ✓.
- **Spec §2 architecture:** slaves stay; no CF/tier/client-direct touched; only WG-mode + lguot + (later) GSO ✓.
- **Type/name consistency:** `frameBatch(*net.Buffers, [][]byte, [][]byte)` defined in Task 3 Step 3, used in Task 3 Step 1 (test) and Step 5 (`udpToTCP`) — consistent; `udpToTCP(pc, c, learn)` signature change propagated to `pump` (Task 3 Step 6).
- **Placeholders:** `<SIDECAR_WG_IP>`, `<IFACE>`, slave creds are runtime-discovered with the discovery command inline; all code/build/deploy steps are concrete.
- **Deploy hazard covered:** three-artifact sha match (Task 5 Step 3) per the static-shadow lesson.
