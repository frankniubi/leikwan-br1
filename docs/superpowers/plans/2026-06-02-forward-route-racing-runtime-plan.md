# Forward Route racing runtime (B3+B4) — Implementation Plan (grounded)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or executing-plans. Steps use checkbox (`- [ ]`).

**Goal:** Make a Forward Route's ingress slave **auto-race** across its enabled combinations (v1: cnix ↔ leigod) and fail over when a basis degrades — by reusing the EXISTING slave selector.

**Key grounding (verified this session):**
- The slave selector already races a candidate list and rewrites the active backend target on switch:
  `slave_agent.py::evaluate_sidecar_uplink_selector` L2712-2713 sets `active_target_host = selected.sidecar_address`, `active_target_port = selected.sidecar_port|selector.sidecar_service_port`. The `SidecarUplinkSelectorProxy` (L938) serves that active target to the bond runtime. **So no core selector rewrite is needed.**
- The selector candidate list is built by `staticSidecarUplinkSelectorPayload` (ProvisioningService L6203) from the uplinks returned by `staticSidecarUplinksForForwardNode($forward,$node)` (L6103) — which today returns the **single** combination's primary+backup lanes (L6126 reads `forward.forward_combination_id`; L6153 builds via `buildStaticSidecarUplinkPairForNode`).
- Warm standby is already automatic: the per-point management feed (`staticSlaveSidecarCombinationsForPoint`, L5544 → L5481) brings up **every** combo's uplink on a sidecar point, so the candidate interfaces are already live.
- B0: the v1 raced set must share `backend_mode` (slave-internal backend behind the stable mux). For the selector candidates to share one `sidecar_service_port`, they must ALSO share `sidecar_point_id`.

**Therefore the change is small and panel-weighted:** feed the selector one candidate per enabled route-combo; the existing selector does the rest. Slave change is observability-only.

**Tech:** PHP panel (`master`), Python slave agent (dual-write `slave_agent.py` + `artifacts/slave-agent.py` + `AGENT_VERSION`). No deploy. TDD.

---

## Task R1 — Panel: emit one selector candidate per enabled route-combo (CORE)

**Files:** `master/src/Support/ProvisioningService.php` (`staticSidecarUplinksForForwardNode` L6103; selector candidate dict L6236; new helper `enabledRouteCombinationsForForward`). Test: `master/tests/publish_route_race_candidates_test.php`.

- [ ] **R1.1 Failing test.** Seed (fake DB) a forward with `forward_route_id=9` (race on) whose route has 2 enabled combos (ids 10=cnix `self_leg=cnix`, 11=leigod `self_leg=leigod`), SAME `sidecar_point_id`. Assert `staticSidecarUplinksForForwardNode` (via reflection) returns 2 uplinks with distinct `interface_name`+`peer_address` and `forward_combination_id` ∈ {10,11}; and that with race OFF it returns only combo 10's lanes (current behavior).
- [ ] **R1.2 New helper** `enabledRouteCombinationsForForward(array $forward): array`:
  ```php
  // returns enabled combo rows (lowest sort_order first, capped) for the forward's race-on route,
  // restricted to the forward's sidecar_point_id; [] when no route / race off.
  private function enabledRouteCombinationsForForward(array $forward): array {
      $routeId = (int) ($forward['forward_route_id'] ?? 0);
      if ($routeId <= 0) return [];
      $route = $this->db->fetchOne('SELECT race_enabled FROM forward_routes WHERE id = :id LIMIT 1', ['id'=>$routeId]);
      if (!is_array($route) || (int)($route['race_enabled'] ?? 0) !== 1) return [];
      $cap = max(1, (int) $this->config->getInt('FORWARD_ROUTE_RACE_CANDIDATE_CAP', 3));
      $rows = $this->db->fetchAll(
          'SELECT fc.* FROM forward_route_combinations frc
             INNER JOIN forward_combinations fc ON fc.id = frc.forward_combination_id
           WHERE frc.forward_route_id = :id AND frc.enabled = 1 AND fc.is_active = 1
           ORDER BY frc.sort_order ASC, frc.id ASC', ['id'=>$routeId]);
      $pointId = (int) ($forward['bond_sidecar_point_id'] ?? 0);
      $out = [];
      foreach ($rows as $fc) {
          if ($pointId > 0 && (int)($fc['sidecar_point_id'] ?? 0) !== $pointId) continue; // same-sidecar only
          $out[] = $fc;
          if (count($out) >= $cap) break;
      }
      return $out;
  }
  ```
- [ ] **R1.3** In `staticSidecarUplinksForForwardNode`, after the early direct-TCP guards (≈L6125) and before the single-combo path (L6126), branch:
  ```php
  $routeCombos = $this->enabledRouteCombinationsForForward($forward);
  if (count($routeCombos) >= 2) {
      $nodeId = (int) ($node['id'] ?? $node['node_id'] ?? 0);
      if ($nodeId <= 0) return [];
      $uplinks = [];
      foreach ($routeCombos as $combo) {
          $combo = $this->hydrateCombinationSidecarFields($combo); // reuse the L6138-6151 point-hydration as a helper
          foreach ($this->buildStaticSidecarUplinkPairForNode($combo, $node) as $lane) {
              if (($lane['slave']['lane'] ?? '') === 'primary') { $uplinks[] = $lane['slave']; }
          }
      }
      if ($uplinks !== []) return array_values($uplinks);
  }
  ```
  (Extract the existing L6138-6151 sidecar-field hydration into `hydrateCombinationSidecarFields(array $combo): array` and call it in both the single-combo path and here, to stay DRY.)
- [ ] **R1.4** Add `forward_combination_id` to the selector candidate dict (L6236): `'forward_combination_id' => $combinationId,` (it's already read at L6228).
- [ ] **R1.5** Run test → green. **R1.6** Regression: run the existing static-sidecar + selector panel tests. **R1.7** Commit.

## Task R2 — Panel: route guard also requires same sidecar_point_id

**Files:** `ProvisioningService.php` `saveForwardRoute` ingress guard; `master/tests/saveforwardroute_ingress_guard_test.php`.
- [ ] **R2.1** Extend the test: two combos same backend_mode but DIFFERENT `sidecar_point_id` → `forward_route_combos_ingress_mismatch`.
- [ ] **R2.2** Add `sidecar_point_id` to the SELECT + the `$ingressKeys` tuple in `saveForwardRoute`.
- [ ] **R2.3** Run → green. Commit.

## Task R3 — Slave: record + report the active basis (observability; dual-write)

**Files:** `slave_agent.py` + `artifacts/slave-agent.py` (`evaluate_sidecar_uplink_selector` L2634; heartbeat builder); panel heartbeat consumer (`NodeController`/wherever forward runtime status lands → persist `forwards.active_combination_id`). Test: `slave-agent/tests/test_route_race_selector.py`.
- [ ] **R3.1 Failing test.** Build a selector dict with 2 candidates (each with `forward_combination_id`), feed `evaluate_sidecar_uplink_selector`; assert the returned state includes `active_forward_combination_id` = the selected candidate's id. Single candidate (monitor) → still reports it.
- [ ] **R3.2** In `evaluate_sidecar_uplink_selector`, where `selected` is finalized (≈L2712), add `state['active_forward_combination_id'] = int(selected.get('forward_combination_id', 0) or 0)`.
- [ ] **R3.3** (smaller follow-on) Surface `active_forward_combination_id` per forward in the heartbeat, and persist to `forwards.active_combination_id` on the panel side. If the heartbeat plumbing is deep, land R3.2 (local state) + a TODO and keep the panel persist as a separate commit.
- [ ] **R3.4** Mirror to artifacts; `diff -q`; bump `AGENT_VERSION` + `versions.json`. Run full slave suite. Commit.

## Task R4 — Verify selector-port stability (likely no-op)

- [ ] `collisionFreeSidecarSelectorPort(firstNodeId, firstPointId, firstCombinationId, forwardId, endpointId)` already keys on stable `forwardId+endpointId`; `firstCombinationId` is the lowest-sort combo (stable order). Confirm with a test that the selector port is unchanged when the active basis flips (the candidate SET/order is stable; only `selected` changes). If not stable, seed on `forward_route_id`. Commit only if a change is needed.

## Task R5 — Live validation (runbook; not runnable on dev box)
Per `docs/2026-06-02-forward-route-b3-b4-handoff-and-validation.md` §B7: route bundling cnix+leigod on one sidecar; confirm both interfaces up, selector picks best, degrade the active basis → sub-second failover with hysteresis, `active_combination_id` updates.

---

## Risk notes
- **Lower risk than feared:** reuses the proven selector racing + target-rewrite verbatim; the panel just supplies more candidates. No selector-internals rewrite.
- Constrain v1 to **same sidecar_point_id + same backend_mode** (R2) so candidates share `sidecar_service_port` and the per-service backend unit — otherwise the selector's single `sidecar_service_port` and the un-raced backend unit would mismatch.
- Still **needs live validation** (R5) before prod — the failover timing/hysteresis can only be proven on real infra.
- Dual-write + `AGENT_VERSION` bump remain mandatory.
