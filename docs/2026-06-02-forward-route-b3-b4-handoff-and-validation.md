# Forward Route — B3/B4 (racing runtime) handoff + B7 live validation

> Status after this session: Forward Route is **functional as "primary combination"** — routes are
> selectable (admin + tenant), `saveForward` seeds the route's lowest-`sort_order` enabled combo into
> `forward_combination_id`/`active_combination_id`, and the forward realizes through the existing
> single-combo path. The **auto-racing / failover (B3 publish candidates + B4 slave selector)** is the
> remaining enhancement. It is deliberately left for a focused session **with a live multi-sidecar
> environment**, because it modifies the live-failover selector on the critical forwarding path and
> cannot be validated locally (Windows dev box has no systemd/slaves/sidecars).
>
> Spec: `docs/superpowers/specs/2026-06-02-forward-route-design.md` §4.4–§4.5, §9. Decisions: B0 (commit
> a3d7065) — v1 raced set = combos sharing `backend_mode`, differing only in `self_leg_outer_transport`
> (i.e. **cnix ↔ leigod**); cnix↔cf-bond is deferred (§10).

## B3 — Publish `route_race_candidates` (panel)
**Seam:** `ProvisioningService::publishTaskPayloadForForward` (L6280) +
`staticSidecarUplinkSelectorPayload` (L6203) + `buildStaticSidecarUplinkPairForNode` (L5634, already
emits per-combo uplinks incl. the leigod keys).
**Do:**
1. When `forward.forward_route_id > 0`, load the route's **enabled** combos (lowest `sort_order` first)
   via `forward_route_combinations` (cap at e.g. 3 — `route_race.candidate_cap`).
2. For each enabled combo, build its slave↔sidecar uplink candidate with
   `buildStaticSidecarUplinkPairForNode($combo, $node)` (this already yields cnix `udp` / leigod
   `leigod` uplinks). Emit them as the selector candidate set.
3. Emit a `route_race_candidates` payload field (NOT the reserved `forward_routes` key —
   `SidecarAgentController.php:292`): a list of `{forward_combination_id, uplink, sort_order, enabled}`
   plus a `route_race` policy block `{race_enabled, switch_improvement_ms, restart_cooldown,
   candidate_cap, kill_switch}`. When `race_enabled = 0`, emit only the lowest-`sort_order` enabled
   combo (today's single-combo behavior).
4. **Route/active-stable selector port:** make `collisionFreeSidecarSelectorPort` (search it) seed on
   `forward_route_id` (fallback `active_combination_id`) instead of `forward_combination_id`, so a
   winning-basis flip does NOT churn the loopback selector port (the EADDRINUSE selector-storm class).
**Test:** a forward bound to a race-ON route with 2 enabled combos publishes 2 candidates (each tagged
`forward_combination_id`, with cnix vs leigod uplink keys); race-OFF publishes only the lowest-sort one;
the payload key is `route_race_candidates`; the selector port is stable across a simulated winner flip.

## B4 — Slave selector: candidate = basis (slave-agent, CRITICAL PATH)
**Seam:** `slave_agent.py` `evaluate_sidecar_uplink_selector` (~L2608 in the design notes; re-grep) +
`SidecarUplinkSelectorProxy` (~L936) + `probe_sidecar_selector_candidate` (~L2523). Today these race
primary/backup WG **lanes** to one sidecar; generalize "candidate" to a **basis** (a full per-combo
uplink). Both slave files must change identically (`slave-agent/slave_agent.py` ==
`artifacts/slave-agent.py`) + bump `AGENT_VERSION` + `versions.json`.
**Do:**
1. Consume `route_race_candidates`: bring up the uplink for each enabled candidate (warm standby) via
   the existing `apply_sidecar_uplink` (cnix and leigod arms already exist from Part A) — bounded by
   `candidate_cap`.
2. Probe each candidate end-to-end (reuse `probe_sidecar_selector_candidate`'s score =
   `latency + 2*mdev`), pick the best healthy basis, and have the proxy rewrite the active backend
   target to it. Reuse `switch_improvement_ms` hysteresis + `restart_cooldown` + the kill-switch +
   monitor-only mode. A candidate whose probe fails/degrades is demoted (this is the CF/CNIX-degrades →
   LeiGod failover).
3. Single candidate (race OFF) → monitor-only/no-switch (behaves like today).
4. **Reverse feedback:** report the active candidate's `forward_combination_id` in the heartbeat →
   panel persists `forwards.active_combination_id` (the column exists from B1) for admin observability.
5. Extend `cleanup_orphan_sidecar_uplinks` / `remove_sidecar_uplink` so de-selected candidates' uplinks
   (incl. `leikwan-gost-lws*` for leigod) are reaped — the per-iface reap from Part A already covers
   leigod; verify warm-standby teardown doesn't strand a basis.
**Caution:** `classify_forward_chain_remediation` (slave_agent.py) LOOKS like a race engine but is dead
code (zero callers, `candidate_latencies_ms` never populated) — do not assume it; the selector path is
the real engine.

## B7 — Live validation (needs a Linux slave + ≥1 sidecar + a LeiGod line)
1. Create combos `cnix` (current_mode, self_leg=cnix) and `leigod` (current_mode, self_leg=leigod,
   leigod_line_id set) sharing one node_group + client_mode (so the B2 ingress guard accepts them).
2. Make a Forward Route bundling both (race ON), attach a forward, set `LEIGOD_BASIS_ENABLED=1`.
3. Confirm the slave brings up BOTH uplinks; the selector picks the lower-latency basis; `wg show` +
   `ping` over the active basis is clean.
4. **Degrade the active basis** (e.g. block the CNIX path or the LeiGod line) → confirm sub-second
   failover to the other basis with hysteresis (no flapping); `forwards.active_combination_id` updates.
5. Race OFF → pins the lowest-`sort_order` enabled combo (single-combo behavior).
6. Disable a combo in the route → it drops from the candidate set; its uplink is reaped.

## Why deferred (not rushed)
B3 is inert without B4; B4 rewrites the live-failover selector (the path that already produced the
selector-port-storm + provision-task-deadlock incidents in this repo's history). It must be implemented
against the real selector internals and validated on live infra — not blind on a dev box. The data
model + control-plane + UI are all in place and tested, so this is a well-bounded follow-up.
