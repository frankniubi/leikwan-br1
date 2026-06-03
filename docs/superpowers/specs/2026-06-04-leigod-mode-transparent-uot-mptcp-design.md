# LeiGod Mode — MPTCP-over-LeiGod via the Static Sidecar Uplink (Compose Model) — Design Spec

**Date:** 2026-06-04
**Status:** Design approved (validated foundation + code-grounded after adversarial review); pending implementation-plan execution.
**Supersedes:** `2026-06-03-leigod-mode-uot-cpu-and-bond-aggregation-design.md` (its "lift per-leg CPU to 200" premise was disproven — §2), `2026-06-03-leigod-mode-client-direct-design.md`, and `2026-06-03-leigod-mode-demoted-cf-anchor-design.md` (all in `docs/superpowers/specs/`).
**Related:** `[[leigod-mode-automation-gap]]`, `[[leigod-uot-cpu-bound]]`, `[[leigod-account-facts]]`, `[[selector-port-collision-storm]]`, `[[cf-edge-selector-blindspot]]`, `docs/2026-06-03-leigod-mode-toplevel-feature.md`.

> **Review note (2026-06-04):** An earlier draft of this spec proposed "point the MPTCP underlay's WG endpoint at a slave-local lguot forward (`127.0.0.1:<local_uot_udp_port>`)." A 4-agent adversarial review + direct code reads proved that **false**: `local_uot_udp_port` drives an unrelated plain-TCP-UoT client, the leigod carrier lives only in `apply_sidecar_uplink`, and the MPTCP underlay already composes *over* the leigod static uplink. This spec is the corrected design.

---

## 1. Goal

Make `leigod_mode` **stable** by running the client→slave tier as **true MPTCP** (which never runs the `leikwan-bond` Rust binary, so it cannot re-admit-storm), while the slave→sidecar hop continues to be carried by the **existing leigod static sidecar uplink** (the lguot UoT over a LeiGod SOCKS-CONNECT line). The MPTCP underlay rides *over* that uplink — it does not replace or re-implement it.

Concretely: keep the leigod carrier exactly where it already works (the static sidecar uplink), and **fix the one structural bug that prevents true-MPTCP from coexisting with it** (a slave-agent early-return), then make `leigod_mode` combos actually select the MPTCP runtime.

Non-goals: making the *shared sidecar* cheap per-Mbps (impossible while using LeiGod — §2/§7); re-implementing the leigod carrier inside the MPTCP path (rejected alternative — §4).

---

## 2. Validated facts this design is built on (measured 2026-06-03/04)

1. **LeiGod relays TCP only.** Its SOCKS5 accepts UDP ASSOCIATE but does **not** relay UDP (DNS to 8.8.8.8 *and* 1.1.1.1 via SOCKS-UDP both timed out); **no TUN**. ⇒ The slave→sidecar hop **must** be TCP-wrapped (lguot UoT over SOCKS-CONNECT) and the sidecar **must** keep an lguot server (de-framer). Native-UDP / kernel-WG-sidecar designs are impossible.
2. **One UoT connection per slave is enough and stable.** A single lguot TCP-UoT connection (slave → sidecar over LeiGod line 453) sustained **~165–190 Mbit/s, ~0 loss, no stalls** over 30 s. ≈ the slave's ~200 Mbit/s unmetered-BGP line cap. **Per slave: one UoT.** (The old "70–100, CPU-bound per leg" premise is obsolete.) **Canonical per-slave figure used in this spec: ~175 Mbit/s (range 165–190).**
3. **Multiple slaves dial concurrently on one LeiGod account.** All 3 combo-5 slaves ran `leigod@453` simultaneously, no conflict — but this was a **30 s** test. Sustained-soak IP-stability/fraud behavior is **unproven** (§8, §9).
4. **MPTCP does not run the `leikwan-bond` binary.** The re-admit-churn (`session.rs:4380`) is a property of the bond selector. True MPTCP uses `leikwan_mptcp_client.py` instead, so it cannot re-admit-storm. **Caveat:** this only addresses the *bond cooldown/re-admit* failure class — see §2.6 for the failure class it does **not** address.
5. **The MPTCP underlay is already designed to ride the static uplink (compose model).** `buildMptcpSlaveUnderlayPayload` calls `staticSidecarPrimaryUplinkForForwardNode($forward,$node,'mptcp')` (`ProvisioningService.php:9069`). When a static uplink exists it sets `uses_static_sidecar_uplink=true`, blanks `sidecar_endpoint_host`/`sidecar_endpoint_port` (`:9078-9083`), and `apply_mptcp_underlay` then renders **only the client peer** — the slave→sidecar bytes route through the separate static-uplink interface (`slave_agent.py:4917-4959`). The leigod carrier (lguot client/line + endpoint re-pin + MTU 1280) lives in `apply_sidecar_uplink`'s `outer_transport=='leigod'` arm (`slave_agent.py:4383-4436`), and the sidecar's lguot server in `apply_slave_sidecar_uplink` (`sidecar_agent.py`). **None of this is in the MPTCP path or the MPTCP client/sidecar agents — and it does not need to be.**
6. **(The actual failure class to confirm.)** Per `[[selector-port-collision-storm]]` / `[[cf-edge-selector-blindspot]]`, the historical 65/66 outage signature was an **agent-side selector port-collision + systemd restart storm (EADDRINUSE) + CF-edge churn** — *not* the bond cooldown. Switching the client tier to MPTCP does **nothing** about an agent restart storm. The implementation plan's Phase 0 **must capture the actual last-outage signature** before assuming this redesign fixes 65/67 (§9, plan Phase 0).

---

## 3. Architecture (compose model)

```
        client  (true MPTCP — leikwan_mptcp_client.py, NOT the bond binary)
          │  MPTCP subflows = the N per-slave WG underlay interfaces (one signal endpoint each)
   ┌──────┼───────────┐                       (aggregation is ACROSS slaves)
   ▼      ▼           ▼
slaveA  slaveB     slaveC
   │ MPTCP underlay iface lwf<fid>n<nid>  (client peer only; uses_static_sidecar_uplink=true)
   │     routes slave→sidecar via ↓ the SAME slave's static uplink interface
   ▼
[static sidecar uplink iface]  outer_transport='leigod'  (apply_sidecar_uplink)
   │  ensure_leigod_line_service(line 453) + ensure_sidecar_lguot_client_service
   │  WG Endpoint re-pinned to 127.0.0.1:<gost/lguot listener>, MTU=1280
   │  ── ONE lguot TCP-UoT over a LeiGod SOCKS-CONNECT line ──┐
   ▼                                                          ▼
sidecar lguot server (de-framer, apply_slave_sidecar_uplink) ──► sidecar WG (kernel) ──► target
```

- **Client → slave:** **true MPTCP** over the N per-slave WG underlay interfaces. Each slave is one MPTCP signal endpoint; aggregation is across slaves. The bond binary is not run.
- **Slave → sidecar:** the **existing leigod static sidecar uplink** — one lguot TCP-UoT over a LeiGod line, terminated by the sidecar lguot server. The MPTCP underlay interface carries **only the client peer** and routes slave→sidecar traffic through this static-uplink interface (because `uses_static_sidecar_uplink=true`).
- **Sidecar:** unchanged — kernel WG + the lguot server stood up by the static uplink. (Inherent cost — §7.)
- **Transparency:** the MPTCP client and MPTCP sidecar agents never see "leigod" — they ride over a WG hop that *happens* to be the leigod-carried static uplink. This is what makes "no agent-protocol change" true.

---

## 4. What changes vs. today

| Aspect | Today (broken for true-MPTCP) | New design |
|---|---|---|
| Client→slave runtime | `leigod_mode` combos are `client_mode='bond'`; `mptcp_preferred_bond_fallback` forces `publishesBondRuntime=true` | **true MPTCP** (`client_mode='mptcp'`, a runtime_preference that does **not** set `publishesBondRuntime`) |
| Slave→sidecar carrier | leigod **static sidecar uplink** (lguot UoT) | **unchanged — same static uplink** |
| Slave dispatch | true-MPTCP branch **early-returns before `apply_sidecar_uplink`** (`slave_agent.py:3201`) → leigod uplink never provisioned | apply the static uplink **alongside** the MPTCP underlay (fix the early-return) |
| Why leigod "works" today | only under bond-fallback (publishes bond runtime = runaway path) | under true MPTCP (no bond binary) |
| Bond cooldown machinery | live (`BOND_LEIGOD_LEG_COOLDOWN_MS`, `ProvisioningService.php:7721`) | **kept** for the bond-fallback path; **irrelevant** under true MPTCP (binary not run) — nothing to "retire" |
| MPTCP client / sidecar agents | no leigod awareness | **no change** (ride over the static uplink) |
| Panel underlay builder | already emits `uses_static_sidecar_uplink` | **no leigod-field surgery** needed |

**Core implementation levers (two, both small):**
1. **Slave agent:** in the dispatch (`slave_agent.py:3169-3209`), when the true-MPTCP branch fires, **also apply `sidecar_uplink` if enabled** before returning — so the leigod static uplink is provisioned together with the MPTCP underlay. (Today the payload already carries both `mptcp_underlay` and `sidecar_uplink`; only the apply step is skipped.)
2. **Panel:** make `leigod_mode` combos select **true MPTCP** — `client_mode='mptcp'` + a runtime_preference that does **not** set `publishesBondRuntime` (`ProvisioningService.php:3532`/`:6421`), and verify the published `forward['mptcp']` block is runtime-ready (`sidecar_mptcp_port>0` + `tcp_session_token` present, already emitted at `:9112-9113`) so the client's `choose_runtime_family` actually returns `mptcp` (`client_agent.py:2619-2672`).

**Explicitly NOT changed (corrections to the earlier draft):** `local_uot_udp_port` is **not** the leigod port (it's the unrelated plain-TCP-UoT path); `allow_private_self_leg` is **already 0** for leigod_mode and gates the **client→slave CF self-leg**, not the leigod uplink (which is gated by `self_leg_outer_transport='leigod'` → `staticSidecarCombinationUsesLeigod`, `:8215`); no leigod fields are added to `buildMptcpSlaveUnderlayPayload`; the MPTCP client/sidecar agents are untouched.

---

## 5. Rejected alternative — "leigod arm inside the MPTCP underlay"

Porting the entire `outer_transport=='leigod'` block (line service + lguot client + endpoint re-pin + MTU) into `apply_mptcp_underlay`, plus teaching `leikwan_mptcp_client.py` and `leikwan_mptcp_sidecar.py` a leigod carrier, and emitting all leigod fields from `buildMptcpSlaveUnderlayPayload`. **Rejected:** it re-implements a working carrier on a second code path, needs new agent code on three components + a three-artifact deploy, and risks divergence (two MTU sources, two lguot-server provisioners). The compose model reuses the proven carrier and touches one slave-agent branch + the panel runtime selection.

---

## 6. MTU (decisive — per user)

The conservative WG MTU that matters is on the **leigod-carried hop**, which is the **static sidecar uplink** — and that interface **already ships `mtu=1280`** (`ProvisioningService.php:5841`), live in production. In the compose model the MPTCP underlay interface (`lwf<fid>n<nid>`) carries only the client peer and does **not** traverse LeiGod, so it does not need the conservative MTU on its own account. **Work item:** (a) confirm 1280 is correct under sustained load (empirically; raise only while loss stays 0), and (b) make 1280 a single configurable source (`BOND_LEIGOD_WG_MTU`, default 1280) rather than a literal, so it can't silently diverge — **not** introduce a second MTU on the MPTCP underlay.

---

## 7. Economics (honest)

The sidecar's user-space lguot termination cost is **inherent** to LeiGod being TCP-only. The **~223 Mbit/s figure is an aggregate throughput ceiling** measured on the 2-vCPU sidecar (it is a Mbit/s ceiling, **not** a per-Mbps cost). With N=3 slaves the per-slave ~175 would imply ~525, but the sidecar caps at **~223** — so **aggregate ≈ min(N×175, ~223)** on current hardware. Levers: (a) **bigger-vCPU sidecar** (the realistic one); (b) accept + amortize across paying users. Note: the `tools/lguot` hot loop **already ships** batched `recvmmsg`/`sendmmsg` + `net.Buffers` (writev) — the easy syscall-batching win is **already realized**, so do **not** bank on a further 20–40% software gain to lift the cap. This redesign fixes **stability**, not per-Mbps cost.

---

## 8. Testing / validation

- **Already validated (foundation):** single-UoT-per-slave stability (~165–190, lossless, **30 s**); 3-slave concurrent dialing (**30 s**).
- **Plan's gate — end-to-end true-MPTCP-over-leigod:** re-enable combo 5 / forward 65 (currently parked) as **true MPTCP** in the compose shape; confirm:
  (a) the client's `choose_runtime_family` returns **`mptcp`** (verify `forward_has_mptcp_runtime_config`: `sidecar_mptcp_port>0` + token), not bond;
  (b) the slave provisions **both** the MPTCP underlay interface **and** the leigod static-uplink interface (lguot client up);
  (c) the sidecar lguot server is up and de-framing;
  (d) **no `leikwan-bond` process / no cooldown storm / no agent restart storm (EADDRINUSE)** under sustained load;
  (e) throughput **approaches the ~223 sidecar cap** (NOT N×190 — see §7), latency ≈ single-leg, per-slave still one UoT.
- **Sustained soak (new):** ≥30 min, all 3 slaves concurrent, watching for LeiGod disconnect / IP-rotation / throttle (the 30 s tests do not prove sustained fraud-safety — §9).
- **Chaos (new):** kill `leigod@453` mid-iperf; observe whether the path recovers or wedges (no-fallback risk — §9).
- Panel unit tests for the runtime-selection change (TDD).

---

## 9. Risks & open questions

- **Actual outage attribution (highest).** The last 65/66 outage signature was an **agent restart storm + CF-edge churn**, not bond cooldown (§2.6). MPTCP does not fix that. **Plan Phase 0 must capture the real signature** (journal restart counts, EADDRINUSE, CF 520s, selector port collisions) before assuming this redesign solves 65/67. The leigod gost/lguot units + WG ListenPort sharing (`slave_agent.py:4436`) still apply under MPTCP — confirm the MPTCP path does not reintroduce the port collision.
- **Bond still runs away if a user picks bond.** `leigod_mode` combos are `client_mode='bond'` today; merely setting runtime_preference won't force MPTCP. **Decision (in plan):** force `client_mode='mptcp'` for leigod_mode (or refuse to persist leigod_mode+bond), with a guard test. If bond must stay selectable, the bond re-admit path must be fixed — not just documented.
- **No fallback if the LeiGod line dies.** The whole slave→sidecar hop depends on line 453 + a stable exit IP. If it dies, the static uplink wedges with no automatic direct fallback. Define watchdog/health-demotion behavior; add the chaos test (§8).
- **Sidecar cap (~223 on 2 vCPU)** bounds aggregate regardless of this fix (§7); 67 re-enable must be gated on measured headroom + collision-free per-lane ports.
- **MTU** stays 1280 (already live); confirm under sustained load (§6).
- **No CF, no UDP, no TUN** — locked by the findings.

## 10. Out of scope

CF anchor; client-direct; UDP/SOCKS-UDP; kernel/eBPF UoT; the rejected leigod-in-underlay path (§5); bigger-sidecar provisioning; further `lguot` micro-optimization (already at batched-syscall floor — §7).
