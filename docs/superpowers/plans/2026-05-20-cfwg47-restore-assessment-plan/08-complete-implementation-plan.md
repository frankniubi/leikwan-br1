# CFWG Forward 47 — Complete Implementation Plan

> Consolidated from docs `00-` through `07-` in this directory.
> This is the single-file reference for the next coder agent.
> Date: 2026-05-20 | Branch: `codex/cfwg47-assessment-handoff-20260520`

---

## Table of Contents

1. [Context & Problem Statement](#1-context--problem-statement)
2. [Architecture Overview (as-is)](#2-architecture-overview-as-is)
3. [Contract Semantics A–J](#3-contract-semantics-aj)
4. [Implementation Phases](#4-implementation-phases)
5. [Phase 1 — Regression Fixture Harness (FEAT-002)](#5-phase-1--regression-fixture-harness-feat-002)
6. [Phase 2 — Deterministic Backend Port (Semantic I)](#6-phase-2--deterministic-backend-port-semantic-i)
7. [Phase 3 — ICMP Latency Watchdog (Semantic J)](#7-phase-3--icmp-latency-watchdog-semantic-j)
8. [Phase 4 — Rollout / Unpin (FEAT-003)](#8-phase-4--rollout--unpin-feat-003)
9. [File-by-File Change Manifest](#9-file-by-file-change-manifest)
10. [Risk Register (R-01 – R-08)](#10-risk-register-r-01--r-08)
11. [Non-Goals & Hard Rules](#11-non-goals--hard-rules)
12. [Open Questions for the Operator](#12-open-questions-for-the-operator)
13. [Authoritative Line-Number Table](#13-authoritative-line-number-table)

---

## 1. Context & Problem Statement


Forward 47 (`lw-cfwg-47`) on client host `sh36` (114.80.33.50) is a WireGuard-over-wstunnel-over-Cloudflare path. It was broken by a panel artifact push that changed the rendered runtime without preserving the CFWG contract. The handoff operator manually restored the working runtime and pinned both sides (`disable_auto_update = true` on client id=3 and sidecar point id=5) to prevent re-regression.

**Goal of this plan:** Build and execute a safe path to unpin auto-update while adding two new capabilities:
1. Deterministic per-forward wstunnel backend port allocation (closes the last stickiness gap).
2. An ICMP-driven latency watchdog that auto-restarts or auto-corrects the path when WG latency degrades.

**Hard constraint:** Do not regress forward 47. Every change is gated by a render-diff harness that byte-compares rendered output against captured known-good fixtures.

---

## 2. Architecture Overview (as-is)

```
client (sh36)
  └─ wstunnel client -L udp://127.0.0.1:40047:127.0.0.1:14969
       └─ WSS over CF edge (wss://<cf_dial_host>:2053)
            └─ Sidecar public mux (leikwan_wss_mux.py, port 2053)
                 └─ Path match: HTTP upgrade path == "<path_prefix>.cfwg"
                      └─ TCP relay to 127.0.0.1:<backend_port>
                           └─ wstunnel server ws://127.0.0.1:<backend_port>
                                --restrict-to 127.0.0.1:14969
                                --restrict-http-upgrade-path-prefix <path_prefix>.cfwg
                                └─ UDP datagrams → sidecar WG listen port 14969
                                     └─ WG peer 10.204.53.42
```

### Key components

| Layer | What | Where | Sticky? |
|-------|------|-------|---------|
| Public port | CF-fronted TLS mux | `sidecar_agent.py:809 render_cf_mux_unit` → `leikwan_wss_mux.py` on `cf_runtime.listen_port` (2053) | YES (panel-configured) |
| Path routing | HTTP upgrade path demux | `leikwan_wss_mux.py:88 resolve_route` against `cf-routes-<listen>.json` | YES (panel-minted per forward) |
| Backend port | Per-forward wstunnel server loopback | `sidecar_agent.py:770 allocate_cf_backend_port` range [26000,26100) | NO — dynamic today, proposed deterministic |
| WG listen | Sidecar WireGuard | `sidecar_agent.py:32 DEFAULT_CONFIG["wireguard_listen_port"] = 14969` | YES (config-stable) |
| Client local UDP | wstunnel client target | `install_forward_runtime.py` → `40000 + (forward_id % 2000) = 40047` | YES (formula-derived) |

---

## 3. Contract Semantics A–J


| ID | Semantic | Producing code | Asserted by |
|----|----------|----------------|-------------|
| A | `cf_wg` branch taken when `self_leg_cf_transport='cf_wg'` + `backend_mode='cf_mode'` + sidecar pubkey present | `ProvisioningService.php:2995` | PHP render-diff test (d) |
| B | `cf_path_prefix` passthrough with `.cfwg` suffix, no leading slash | `ProvisioningService.php:3012` | Client render-diff (a), sidecar route render (c) |
| C | Sidecar peer public key plumbed into client `[Peer] PublicKey` | `ProvisioningService.php:3056 buildSelfLegWireGuardProfile` | WG conf render (b) |
| D | `wireguard_listen_port=14969` → client local UDP `40047`, sidecar `--restrict-to 127.0.0.1:14969` | `sidecar_agent.py:32`, `install_forward_runtime.py` | WG conf render (b), sidecar route render (c) |
| E | Client wstunnel `cf_target_port` capture-before-override | `install_forward_runtime.py self_leg_wg_underlay()` | Client render-diff (a) |
| F | CF dial IP + SNI in wstunnel client `wss://` URL | `ProvisioningService.php buildSelfLegWireGuardProfile` | Client render-diff (a), PHP render-diff (d) |
| G | `applied.json` per-file content hashing as no-op gate | `client_agent.py:1044 apply_forward` → `write_text_if_changed` | Runtime behavior (not byte-diffed) |
| H | `disable_auto_update` short-circuit | `client_agent.py:860`, `sidecar_agent.py:894,1453` | Runtime behavior (not byte-diffed) |
| **I** | **Per-forward backend_port = 26000 + (forward_id % 100)** | `sidecar_agent.py:770 allocate_cf_backend_port` (proposed) | Sidecar route render (c) pins `26047` |
| **J** | **ICMP watchdog observes + 3-tier escalation** | New `cfwg_latency_watchdog.py` (proposed) | New watchdog unit test |

---

## 4. Implementation Phases

```
Phase 1: FEAT-002 Regression Fixture Harness
    ↓ (harness GREEN on synthetic + live fixtures)
Phase 2: Deterministic Backend Port (Semantic I)
    ↓ (harness GREEN with 26047 pinned)
Phase 3: ICMP Latency Watchdog (Semantic J) — 5 stages
    ↓ (staged rollout per forward)
Phase 4: FEAT-003 Unpin Runbook execution
    ↓ (sign-off checklist complete)
Done
```

---

## 5. Phase 1 — Regression Fixture Harness (FEAT-002)

### 5.1 Fixture directories

```
client-agent/tests/fixtures/cfwg-forward-47/
  ├── panel-payload.json          (scrubbed panel forward dict)
  ├── expected-start-script.sh
  ├── expected-mptcp-forward-47.json
  ├── expected-systemd-unit.service
  ├── expected-wg-conf.conf
  └── README.md

sidecar-agent/tests/fixtures/cfwg-forward-47/
  ├── cf-runtime-routes.json      (scrubbed cf_runtime.routes)
  ├── expected-cf-routes-14969.json
  └── expected-cf-backend-cf-forward-47-cfwg.service
```

### 5.2 Scrubbing rules

| Live value | Replacement |
|------------|-------------|
| `162.159.39.5` | `192.0.2.5` |
| `nrt1.nbwly.com` | `edge.example.test` |
| `bBbi/7CsWMWrX/TrM/mRwTWceO3UoOIAL2S1BXLXCw4=` | `SIDECAR_PUBKEY_PLACEHOLDER=` |
| `07289d1f5ed25399` | `0123456789abcdef` |
| any `agent_token` | `AGENT_TOKEN_PLACEHOLDER` |
| any `agent_update_request_token` | `AGENT_UPDATE_REQUEST_TOKEN_PLACEHOLDER` |
| any WG `[Interface] PrivateKey` | never committed; use tempfile at test runtime |


### 5.3 Four new test files

#### (a) `client-agent/tests/test_cfwg_forward_47_render_diff.py`

- Drives `install_forward_runtime.render_forward_runtime(forward, {})` against `panel-payload.json`.
- Asserts byte-equal: `start_script`, `mptcp-forward-47.json`, systemd unit.
- Covers semantics B, E, F.

#### (b) `client-agent/tests/test_cfwg_forward_47_wg_conf_render.py`

- Renders WG conf via `leikwan_mptcp_client.build_wireguard_interface_config(underlay)`.
- Asserts byte-equal to `expected-wg-conf.conf`.
- Pins `Endpoint = 127.0.0.1:40047`.
- Covers semantics C, D.

#### (c) `sidecar-agent/tests/test_cfwg_forward_47_route_render.py`

- Loads `cf-runtime-routes.json`, drives `render_cf_backend_unit` + cf-routes writer.
- Asserts byte-equal: `cf-routes-14969.json`, `cf-backend-cf-forward-47-cfwg.service`.
- Pins `path_prefix` ends `.cfwg`, `target=127.0.0.1:14969`, `transport_mode=udp`.
- Covers semantics B, D, I (after Phase 2 pins `backend_port=26047`).

#### (d) `master/tests/cfwg_forward_47_panel_render_diff_test.php`

- Drives `ProvisioningService::assignedForwardConfigsForClient(3)` (public, line 2647).
- Seeds DB test double with canned rows for client/service/forward/combination/sidecar-point.
- Asserts the `candidate_pool` self-leg entry matches `expected-panel-payload.json`.
- Covers semantics A, B, C, F.
- Source-grep tests are KEPT alongside (not retired).

### 5.4 Manifest consistency check extension

Extend `client-agent/tests/test_artifact_versions_consistency.py` to cover all four keys:

| Manifest key | File path |
|--------------|-----------|
| `client_agent` | `client-agent/client_agent.py` |
| `install_forward_runtime` | `client-agent/install_forward_runtime.py` |
| `sidecar_agent` | `sidecar-agent/sidecar_agent.py` |
| `mptcp_client_runtime` | `client-agent/leikwan_mptcp_client.py` |

Decision required before extending: fix the manifest (regenerate hashes) or quarantine with `unittest.expectedFailure`. Do not leave the test silently broken.

### 5.5 Bootstrapping without live capture

1. Commit test files with `unittest.skipIf(not fixture_path.exists(), ...)` guards.
2. Seed synthetic fixtures from `test_self_leg_cf_wg_runtime.py::_candidate()` but use no-leading-slash `0123456789abcdef` (NOT `/tokyo`).
3. Document in fixture `README.md` that synthetic-only is necessary but not sufficient.

### 5.6 Render-diff workflow (gate for all subsequent phases)

1. Run all four tests on synthetic → green.
2. Run all four tests on live-captured → green.
3. Any diff blocks rollout.
4. Intentional divergences update expected fixture in SAME commit as producing-side change.

---

## 6. Phase 2 — Deterministic Backend Port (Semantic I)


### 6.1 Problem

`allocate_cf_backend_port` is first-come-first-served in `[26000, 26100)`. On cold install or `cf-managed.json` wipe, the port for a given forward is non-deterministic. This defeats byte-equal fixture assertions and makes incident logs hard to grep.

### 6.2 Proposed policy

```python
def allocate_cf_backend_port(self, service_name: str, reserved_ports: set[int] | None = None, *, forward_id: int = 0) -> int:
    reserved = set(reserved_ports or set())
    used: dict[int, str] = {}  # port -> owning service_name
    existing = 0
    for item in self.load_managed_cf_metadata():
        if not isinstance(item, dict):
            continue
        bp = int(item.get("backend_port", 0) or 0)
        if bp <= 0:
            continue
        owner = str(item.get("service_name", ""))
        if owner == service_name:
            existing = bp
            continue
        used[bp] = owner
    # Respect existing sticky assignment
    if existing > 0 and existing not in reserved and used.get(existing) is None:
        return existing
    # Preferred: deterministic by forward_id
    if forward_id > 0:
        preferred = 26000 + (forward_id % 100)
        if preferred not in reserved and used.get(preferred) is None:
            return preferred
    # Fallback: linear scan
    for port in range(26000, 26100):
        if port not in reserved and used.get(port) is None:
            return port
    raise RuntimeError("no free sidecar cf backend port available")
```

**Properties:**
- Existing installations keep their assignment (backward-compatible).
- Cold installs converge: forward 47 → `26047`, forward 26 → `26026`.
- Fixture stability: harness pins `26047` in expected unit text.
- Collisions (forward 47 vs 147) resolved by linear-scan fallback.

### 6.3 Changes required

| File | Change |
|------|--------|
| `sidecar-agent/sidecar_agent.py` | Modify `allocate_cf_backend_port` to accept `forward_id` kwarg, apply preferred-port logic. |
| `sidecar-agent/sidecar_agent.py` | Caller of `allocate_cf_backend_port` in the cf_runtime block must pass `forward_id` extracted from route's `service_name` (parse `cf-forward-<id>-cfwg`). |
| `sidecar-agent/tests/test_cfwg_forward_47_route_render.py` | Add `assertEqual(routes[0]["backend_port"], 26047)`. |
| `sidecar-agent/tests/test_sidecar_cf_mode_runtime.py` | Add cold-start and collision tests. |
| `artifacts/versions.json` | Regenerate `sidecar_agent` sha256 + size. |
| `sidecar-agent/tests/fixtures/cfwg-forward-47/expected-cf-backend-cf-forward-47-cfwg.service` | Pin `ws://127.0.0.1:26047`. |

### 6.4 Test coverage

1. Cold-start (no `cf-managed.json`): forward 47 → `26047`.
2. Pre-seeded `cf-managed.json` with forward 47 already on `26003`: returns `26003` (sticky).
3. Collision: forward 147 pre-occupies `26047`: forward 47 falls to `26000` (first free).

---

## 7. Phase 3 — ICMP Latency Watchdog (Semantic J)


### 7.1 Observation

For each `lw-cfwg-<id>` interface, run `ping -I lw-cfwg-<id> -c 1 -W 1 <peer_address>` every `probe_interval_seconds` (default 5). Maintain a sliding window of N samples (default 12 = 1 minute).

Metrics:
- `loss_pct` — fraction of timed-out samples in window.
- `rtt_avg_ms` — mean RTT of replied samples.
- `rtt_p95_ms` — 95th percentile RTT of replied samples.

Thresholds (panel-publishable per forward):
- `loss_pct_warn`: 5% (degradation suspected)
- `loss_pct_critical`: 20% (degradation confirmed)
- `rtt_p95_ms_critical`: `rtt_baseline_ms * 3` (floor 80ms)

Escalation fires after **2 consecutive windows** above critical.

### 7.2 Three-tier escalation

| Tier | Action | Cost | Mechanism |
|------|--------|------|-----------|
| 1 | Restart wstunnel client process | ~30s dropped | `systemctl restart leikwan-client-<forward_id>.service` |
| 2 | Rotate CF dial IP | ~1min dropped | Write pin file `cfwg-watchdog-pin/<forward_id>.json` with `{"cf_dial_host_pin": "<next_ip>"}`, then `apply_forward`. Pin has 10-minute TTL. |
| 3 | Rotate `cf_path_prefix` to alternate | ~1min dropped | Cycle through panel-published `cf_path_prefix_alternates`. Requires sidecar shadow routes for each alternate. |

Recovery (window drops below `loss_pct_warn` AND `rtt_p95_ms_critical`): ladder resets.

### 7.3 New module: `client-agent/cfwg_latency_watchdog.py`

```python
class CfwgLatencyWatchdog:
    def __init__(self, agent: ClientAgent) -> None: ...
    async def run_forever(self) -> None: ...
    def observe_forward(self, forward_id: int, interface_name: str,
                        peer_address: str, thresholds: dict) -> None: ...
    def stop_observing(self, forward_id: int) -> None: ...
```

Lives inside `leikwan-client-agent.service` as an asyncio task. ICMP via `asyncio.create_subprocess_exec("/bin/ping", ...)`.

### 7.4 Panel publish: `cf_latency_watchdog` block

Added to the `self_leg_wg` dict in `ProvisioningService.php buildSelfLegWireGuardProfile`:

```json
{
  "cf_latency_watchdog": {
    "enabled": false,
    "probe_interval_seconds": 5,
    "window_samples": 12,
    "loss_pct_warn": 0.05,
    "loss_pct_critical": 0.20,
    "rtt_p95_ms_critical_multiplier": 3.0,
    "rtt_p95_ms_floor": 80,
    "escalation_dwell_windows": 2,
    "cf_path_prefix_alternates": []
  }
}
```

### 7.5 Sidecar shadow routes for alternates

When `cf_path_prefix_alternates` is non-empty, `SidecarAgentController.php` line 213 emit logic iterates alternates and produces N+1 routes:
- Canonical: `service_name = cf-forward-47-cfwg`, `path_prefix = 0123456789abcdef.cfwg`
- Alternate 1: `service_name = cf-forward-47-cfwg-alt1`, `path_prefix = 0123456789abcdef.cfwg.alt1`
- All share the same `target = 127.0.0.1:14969` and `backend_port = 26047`.

### 7.6 Client-side: `install_forward_runtime.py` changes

`self_leg_wg_underlay()` extended to:
1. Read `cfwg-watchdog-pin/<forward_id>.json` if present and within TTL.
2. If pin exists: override `cf_dial_host` with pinned IP.
3. Pass through `cf_latency_watchdog` block to rendered forward dict.

### 7.7 Staged rollout

| Stage | What | Duration | Gate |
|-------|------|----------|------|
| 1 | Deterministic backend_port (Phase 2) | Ship immediately | Harness green with 26047 |
| 2 | Watchdog observe-only (`enabled=false`) | 1 week minimum | Logs show correct metric collection, no action |
| 3 | Tier 1 only (`enabled=true`, no alternates) | 1 week | No restart loops observed |
| 4 | Tier 2 (`self_leg_cf_candidates` ordered) | 1 week | Rotation events resolve degradation |
| 5 | Tier 3 (`cf_path_prefix_alternates` published) | Indefinite | Shadow routes stable, alternates resolve Tier-2-resistant failures |

Each stage has its own pre-flight harness run and rollback path.

### 7.8 Test coverage for watchdog

New file: `client-agent/tests/test_cfwg_latency_watchdog.py`

- Mock `asyncio.create_subprocess_exec` with scripted ICMP outputs.
- Test Tier 1: two consecutive critical windows → restart called once.
- Test Tier 2: Tier 1 fails to resolve → pin file written with next candidate IP.
- Test Tier 3: Tier 2 fails → `cf_path_prefix` cycles through alternates.
- Test recovery: window drops below warn → ladder resets.
- Test no-action when `enabled=false`: metrics collected, no escalation.

---

## 8. Phase 4 — Rollout / Unpin (FEAT-003)


### 8.1 Sequenced steps

| Section | Action | Scope |
|---------|--------|-------|
| 1 | Pre-flight host backup on sh36 | OUT OF SCOPE (live operator) |
| 2 | Pre-flight repo harness run (all 4 tests + manifest check) | IN SCOPE |
| 3 | Unpin sidecar point id=5 (`disable_auto_update` → null/false) | OUT OF SCOPE |
| 4 | Unpin client id=3 (same) | OUT OF SCOPE |
| 5 | Post-unpin verification: `wg show` + `ping` + hash check | OUT OF SCOPE |
| 6 | Rollback if any gate fails: restore from backup, re-pin | OUT OF SCOPE |

**Order:** Sidecar first (Section 3), wait one heartbeat, then client (Section 4).

### 8.2 Sign-off checklist (all must be true simultaneously)

- [ ] Panel artifacts render forward 47 CFWG correctly (Section 2 green).
- [ ] Auto-update enabled on both sides without regression.
- [ ] On-host file hashes match panel manifest.
- [ ] `wg show lw-cfwg-47` shows increasing `received` counter.
- [ ] `ping -I lw-cfwg-47 10.204.53.42` returns 0% loss.
- [ ] State stable across 2 client config pulls + 1 sidecar heartbeat cycle.

### 8.3 Rollback commands (sh36)

```bash
cp -a /root/codex-backups/cfwg47-pre-unpin-<ts>/leikwan-client-agent.py /usr/local/bin/
cp -a /root/codex-backups/cfwg47-pre-unpin-<ts>/install_forward_runtime.py /usr/local/bin/
cp -a /root/codex-backups/cfwg47-pre-unpin-<ts>/leikwan-client-start-47.sh /usr/local/bin/
cp -a /root/codex-backups/cfwg47-pre-unpin-<ts>/mptcp-forward-47.json /etc/leikwan/
cp -a /root/codex-backups/cfwg47-pre-unpin-<ts>/applied.json /var/lib/leikwan-client-agent/
systemctl restart leikwan-client-agent.service
```
Then re-pin `disable_auto_update=true` on both panel rows.

---

## 9. File-by-File Change Manifest


### New files

| Path | Phase | Purpose |
|------|-------|---------|
| `client-agent/tests/test_cfwg_forward_47_render_diff.py` | 1 | Client render-diff test |
| `client-agent/tests/test_cfwg_forward_47_wg_conf_render.py` | 1 | WG conf render test |
| `client-agent/tests/fixtures/cfwg-forward-47/` (6 files) | 1 | Client-side fixtures |
| `sidecar-agent/tests/test_cfwg_forward_47_route_render.py` | 1 | Sidecar route render test |
| `sidecar-agent/tests/fixtures/cfwg-forward-47/` (3 files) | 1 | Sidecar-side fixtures |
| `master/tests/cfwg_forward_47_panel_render_diff_test.php` | 1 | PHP execution-based test |
| `client-agent/cfwg_latency_watchdog.py` | 3 | ICMP watchdog module |
| `client-agent/tests/test_cfwg_latency_watchdog.py` | 3 | Watchdog unit tests |

### Modified files

| Path | Phase | Change summary |
|------|-------|----------------|
| `sidecar-agent/sidecar_agent.py` | 2 | `allocate_cf_backend_port` → deterministic by `forward_id` |
| `client-agent/install_forward_runtime.py` | 3 | Read `cfwg-watchdog-pin/<id>.json` in `self_leg_wg_underlay()`, pass through `cf_latency_watchdog` |
| `client-agent/client_agent.py` | 3 | Register/unregister watchdog in `apply_forward` loop; start watchdog asyncio task |
| `master/src/Support/ProvisioningService.php` | 3 | Add `cf_latency_watchdog` block to `buildSelfLegWireGuardProfile` output |
| `master/src/Controllers/SidecarAgentController.php` | 3 | Iterate `cf_path_prefix_alternates` to emit shadow routes |
| `client-agent/tests/test_artifact_versions_consistency.py` | 1 | Extend to 4 manifest keys |
| `artifacts/versions.json` | 1–3 | Regenerate hashes after each phase ships |
| `sidecar-agent/tests/test_sidecar_cf_mode_runtime.py` | 2 | Add cold-start + collision tests |

---

## 10. Risk Register (R-01 – R-08)

| ID | Risk | Likelihood | Impact | Mitigation |
|----|------|-----------|--------|------------|
| R-01 | Panel push regresses CFWG on unpin | high (without harness) | high | FEAT-002 harness is hard pre-flight gate |
| R-02 | Fixture-vs-live drift | medium | medium | Re-capture before each unpin |
| R-03 | Sidecar WG pubkey rotation breaks handshake silently | low | high | Harness pins `[Peer] PublicKey` |
| R-04 | `applied.json` signature staleness skips re-render | medium | high | Post-unpin window spans 2 config pulls |
| R-05 | Version pin race during rollout | medium | high | Re-pin if harness not re-run in same session |
| R-06 | Manifest hash desync (4 lineages drifted) | high (realized) | medium | Extend consistency test; Tier 2 — does not gate unpin |
| R-07 | Path-prefix fixture convention (no-slash vs /slash) | low | medium | Harness pins no-slash for forward 47 |
| R-08 | Sandbox cannot reach live hosts | high (always) | low | OUT OF SCOPE labeling; live operator executes |

---

## 11. Non-Goals & Hard Rules


**From handoff (binding):**
- Do NOT switch topology to direct client-to-sidecar.
- Do NOT delete backup at `/root/leikwan-mptcp-leg-priority-sh36-20260519-005901`.
- Do NOT re-enable auto-update until panel build proven to render same contract.
- Do NOT treat `systemctl is-active` alone as proof; `wg show received` + `ping 0% loss` is proof.

**From assessment (additive):**
- Do NOT modify production code in the planning pass.
- Do NOT commit raw live fixtures without scrubbing.
- Do NOT regenerate `artifacts/versions.json` blindly without deciding which lineage is truth.
- Do NOT ship path-prefix fix without confirming DB leading-slash state.
- Do NOT rip out `apply_latency_target_candidate_escalation` — the watchdog complements it.
- Do NOT change the public mux port or TLS stack.
- Do NOT move wstunnel backends off systemd.

---

## 12. Open Questions for the Operator

1. Which panel UI control or SQL UPDATE clears `disable_auto_update` on `bond_sidecar_point.agent_config_json` for sidecar point id=5?
2. Same question for client id=3's `agent_config_json`.
3. Does `forwards.path_prefix` for forward 47 carry a leading slash in the production DB?
4. Which script regenerates `artifacts/versions.json` hashes, and is it run on every artifact change?
5. Does the panel post the entire forward dict in heartbeat responses or rebuild from DB at heartbeat time?

---

## 13. Authoritative Line-Number Table

> Pinned at git revision `17545a4`. Use symbol + grep for long-lived references.

| Symbol | File | Line |
|--------|------|------|
| `assignedForwardConfigsForClient` | `master/src/Support/ProvisioningService.php` | 2647 |
| `buildForwardCandidatePool` | `master/src/Support/ProvisioningService.php` | 2733 |
| `buildSelfLegCandidate` | `master/src/Support/ProvisioningService.php` | 2971 |
| `buildSelfLegWireGuardProfile` | `master/src/Support/ProvisioningService.php` | 3056 |
| `wantCfWg` truthy gate | `master/src/Support/ProvisioningService.php` | 2995 |
| `cf_path_prefix .cfwg` concat | `master/src/Support/ProvisioningService.php` | 3012 |
| Four-condition cfwg shadow-route gate | `master/src/Controllers/SidecarAgentController.php` | 213 |
| `cf-forward-<id>-cfwg` emit | `master/src/Controllers/SidecarAgentController.php` | 216 |
| `127.0.0.1:<wg_listen_port>` target | `master/src/Controllers/SidecarAgentController.php` | 218 |
| `disable_auto_update` echo (sidecar) | `master/src/Controllers/SidecarAgentController.php` | 245 |
| `wireguard_listen_port` default | `sidecar-agent/sidecar_agent.py` | 32 |
| `wireguard_listen_port()` getter | `sidecar-agent/sidecar_agent.py` | 140 |
| `apply_config_patch` (sidecar) | `sidecar-agent/sidecar_agent.py` | 560 |
| `disable_auto_update` branch | `sidecar-agent/sidecar_agent.py` | 592–600 |
| `allocate_cf_backend_port` | `sidecar-agent/sidecar_agent.py` | 770 |
| `render_cf_backend_unit` | `sidecar-agent/sidecar_agent.py` | 791 |
| `render_cf_mux_unit` | `sidecar-agent/sidecar_agent.py` | 809 |
| `apply_management` | `sidecar-agent/sidecar_agent.py` | 882 |
| `apply_management_state` (client) | `client-agent/client_agent.py` | 800 |
| `auto_update_request_token` | `client-agent/client_agent.py` | 859 |
| `apply_config_patch` (client) | `client-agent/client_agent.py` | 904 |
| `apply_forward` | `client-agent/client_agent.py` | 1044 |
| `applied.json` write | `client-agent/client_agent.py` | 723, 812 |
| `select_forward_candidates` | `client-agent/client_agent.py` | 2269 |
| `apply_latency_target_candidate_escalation` | `client-agent/client_agent.py` | 2379 |
| `WssPathMux.resolve_route` | `sidecar-agent/leikwan_wss_mux.py` | ~88 |
| `normalize_path_prefix` | `sidecar-agent/leikwan_wss_mux.py` | 18 |

---

*End of consolidated implementation plan.*
