# LeiGod Mode — UoT CPU Efficiency + Bond Aggregation (kernel-WG-first)

**Date:** 2026-06-03
**Status:** ⚠️ **SUPERSEDED** by `2026-06-04-leigod-mode-transparent-uot-mptcp-design.md`. The "lift per-leg CPU to ~200 Mbit/s" premise was disproven by measurement (one lguot UoT/slave already reaches the ~175 line cap; the bottleneck is the shared sidecar, not per-leg CPU). The stability fix moved to running client→slave as **true MPTCP** (no bond binary) while reusing the existing leigod static sidecar uplink. Kept for the profile-first methodology + bond-aggregation analysis.
**Supersedes:** `2026-06-03-leigod-mode-demoted-cf-anchor-design.md` and `2026-06-03-leigod-mode-client-direct-design.md` (CF floor and client-direct both dropped — see §2).
**Related:** `docs/2026-06-03-leigod-mode-toplevel-feature.md`, `[[leigod-uot-cpu-bound]]`, `[[leigod-account-facts]]`, `[[leigod-throughput-measured]]`.

---

## 1. Goal & intentions

`leigod_mode` exists so the **client's aggregate throughput exceeds a single slave's cap, at single-leg latency.** Two hard facts drive the design:

- **Per-slave line cap ≈ 200 Mbit/s.** Each slave's egress is an **unmetered BGP IP**, which the provider caps at ~200 Mbit/s. This is a *network* cap — no amount of CPU work beats it per leg.
- **Today each leg is CPU-bound at ~70–100**, i.e. *below* its own 200 line cap, because of per-packet userspace work in the UoT/WG pipeline. Under load this saturates the 2-vCPU slave → legs stall/cool → flap storm/collapse.

**Targets:**
1. **Per-leg:** lift each leg from CPU-bound ~70–100 to its **~200 line cap** by removing the per-packet CPU bottleneck.
2. **Aggregate:** take the **client past 200** by bonding **N slaves** (≈ N×200 − overhead). Bonding is for *throughput aggregation*, not just redundancy.
3. **Latency:** aggregate latency stays **≈ single-leg** (latency-first bonding; no reorder/head-of-line penalty).

**Keystone — kernel WireGuard, first priority.** Only WireGuard stabilizes the connection path and makes latency diagnosis tractable, and **kernel** WG crypto is far cheaper than userspace, so it is the first and foundational optimization regardless of what else the profile shows. WG-UDP is carried over the LeiGod SOCKS line by the UoT carrier (lguot).

**The coherence:** the flap/collapse was CPU saturation. If kernel WG + an efficient UoT let each leg reach its 200 line cap *without* saturating CPU, legs stay healthy → no flap → the bond's existing **latency-first** policy delivers low-latency aggregation. Fixing per-packet CPU is the keystone for all three targets.

---

## 2. Architecture (locked) & what was dropped

- **Topology:** today's slave-relayed `leigod_mode`: `client → slave (runs LeiGod) → sidecar → endpoint`. Unchanged.
- **Per-customer slaves** (not a shared tier). LeiGod runs on the slaves' **bounded set of stable IPs** (fraud-safe per `[[leigod-account-facts]]`); operational rule: **don't churn slave IPs** (reprovisioning with new IPs feeds LeiGod's anti-fraud).
- **No CF floor** (dropped): CPU-efficiency removes the *saturation* flap; a LeiGod *outage* (line/account/maintenance) is accepted as a hard forward outage (no non-CF floor is reliable from China).
- **No client-direct, no shared relay tier** (both rejected: client-direct churns the LeiGod account IP set → fraud ban; the shared tier was tied to the CF/scaling path we dropped).
- **WG = kernel**, data-plane foundation. **lguot** carries WG-UDP over the LeiGod SOCKS5 TCP (already batched: `recvmmsg`/`sendmmsg` via x/net/ipv4, 1 MB bufio coalescing, `TCP_NODELAY`).

---

## 3. Why profile-first (Approach A)

lguot is **already CPU-optimized** (batched syscalls, allocation-free hot loop). So the ~70–100 cap is **probably not lguot's framing** but elsewhere on the slave: **userspace WG crypto** and/or the **third-party LeiGod client process** relaying the SOCKS TCP (its CPU we cannot change). Optimizing lguot blind could miss the real cost. **Measure the per-packet CPU breakdown before optimizing.**

---

## 4. Phases

### Phase 1 — Profile & baseline (on combo 5)
- **WG kernel vs userspace** on slaves + sidecar (`lsmod | grep wireguard`, `wg show`, `ps` for `wireguard-go`).
- **Per-packet CPU breakdown** under sustained single-leg load (`pidstat`/`perf top`): WG vs lguot vs the LeiGod client process vs any residual gost.
- **Measurements:** single-leg max throughput vs the 200 line cap; single-leg RTT; bond 2–3 slaves → aggregate throughput + RTT; cooldown rate under load.
- **Output:** where the CPU goes, the gap to 200/leg, the aggregate-and-latency baseline. This decides Phase 2's dominant-cost lever.

### Phase 2 — Kernel WG first, then UoT efficiency (apply dominant-cost lever, re-measure between changes)
1. **Kernel WireGuard (first, always).** Ensure slaves + sidecar use the kernel `wireguard` module via `wg-quick`, not `wireguard-go`. Stabilizes the path, eases latency diagnosis, cuts crypto CPU. If already kernel, confirm and record.
2. **Reduce packet count** to ease WG *and* the LeiGod client: UDP **GSO** (`sendmmsg` with segment size) and/or a larger WG MTU where the LeiGod line's path MTU allows — fewer, bigger datagrams = less per-packet CPU everywhere in the pipeline.
3. **lguot micro-opts** (secondary): `writev`/`net.Buffers` to drop the per-datagram memcpy into the bufio buffer; tune `batch`. Only if the profile shows lguot is a meaningful share.
- Each change re-measured against the Phase 1 baseline; do **not** stack blind.

### Phase 3 — Aggregation + latency validation
- Confirm each leg reaches **~200** (line cap), CPU not saturated.
- Bond N slaves → confirm **aggregate > 200** (≈ N×200 − overhead) **and latency ≈ single-leg**. Validate the bond's latency-first config holds (`policy_mode=latency_first`, `interactive_chunk_bytes`, `latency_guard_bytes`, `interactive_rtt_slack_ms`, `udp_primary_only`): interactive/small traffic rides the single lowest-RTT leg while bulk stripes — so latency stays single-leg while throughput sums.
- Confirm **no flap** (legs at line cap, not CPU-saturated) over a sustained run.

---

## 5. Testing / validation
- **Live (combo 5):** Phase-1 profile; per-leg iperf3 to ~200; multi-leg bond iperf3 aggregate>200 with `ping`/RTT ≈ single-leg; sustained-run cooldown rate ≈ 0.
- **Unit:** any lguot change (writev/batch/GSO) gets a Go test on the framing round-trip + a throughput micro-bench; agent change for kernel-WG provisioning gets a reconcile test (idempotent, picks kernel module).
- Discipline: one spaced measurement per change; prepared revert; watch slave + sidecar load (no storm).

---

## 6. Risks & open questions
- **LeiGod client process is a third-party CPU floor.** If Phase 1 shows it dominates, per-leg 200 may be unreachable on 2-vCPU even with kernel WG — then the levers are *fewer packets* (GSO/MTU), bigger slaves, or **more legs** (bond more slaves), the last bounded by the LeiGod fraud footprint.
- **200 is the per-leg ceiling regardless of CPU** — exceeding 200 for the client is *only* via aggregation (more legs).
- **Aggregation latency:** striping across legs with differing RTTs can inflate latency; the latency-first policy mitigates but Phase 3 must prove it. If a slow leg hurts latency, demote/weight it (existing health/penalty machinery).
- **GSO/MTU interact with the LeiGod line MTU** — validate no fragmentation/path-MTU blackholes.
- **Kernel WG availability** on the slave/sidecar OS (module present + loadable in the provider's kernel).
- **No CF floor** → LeiGod outage = forward down (accepted).
- **Open:** does the LeiGod SOCKS line preserve throughput at 200/leg, or is the *line itself* a sub-200 cap? (Phase 1 single-leg measurement answers this.)

## 7. Out of scope
CF floor; shared relay tier; client-direct; full Rust `leikwan-uot` rewrite (only revisit if Phase 1 proves lguot — not WG/LeiGod-client — is the dominant cost).
