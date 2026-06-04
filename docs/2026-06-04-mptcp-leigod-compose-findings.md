# MPTCP LeiGod compose model - Phase 0 findings

Scope: read-only/commit-only Phase 0. No code changes, no production changes, and no credential use. Live facts below are from the verified controller/slave read-only checks supplied for 2026-06-04; no new live SSH was run for this document.

## Outcome

- The plan is practical for Phases 1 and 3.
- Phase 2 is practical only with two added tasks:
  1. sync/migrate combo-backed `forwards.client_mode` when `forward_combinations.client_mode` changes;
  2. make the `leigod_mode` + `mptcp` + `single` default cap explicit, preferably `DEFAULT_MAX_LEGS_USED`.
- Phases 4-5 remain deploy-gated and should not be executed now.

## Live/read-only state and outage signature

- Panel DB at `2026-06-04T20:08Z`: combo `5` was `leigod-jp-nrt1-453`, `backend_mode=leigod_mode`, `client_mode=bond`, `runtime_preference=mptcp_preferred_bond_fallback`, `helper_group_cap=4`, `node_group_key=sha-tencent`, `sidecar_point_id=5`, `leigod_line_id=453`.
- Forwards `65` and `67` were disabled/parked (`enabled=0`), both `backend_mode=leigod_mode`, `client_mode=bond`, `forward_combination_id=5`, `line_client_id=3`.
- Active endpoint rows still existed: forward `65` nodes `20/2/19`; forward `67` nodes `2/19/20`.
- Sidecar point `5`: `tokyo-nrt1`, host `185.220.238.143`, `listen_port=8899`, `bond_listen_port=8899`, `mptcp_listen_port=8898`.
- Slave read-only checks at about `2026-06-04T20:12Z` on `111.231.3.220`, `111.229.217.7`, and `150.158.103.182` found `eaddr_count=0` for `EADDRINUSE`, `address already in use`, and `start-limit`/failed-unit grep checks over `leikwan-*` journals; no failed/activating `leikwan-*` units were present.
- Combo-5 LeiGod UoT units were active on those slaves (`lws5c5n2b/p`, `lws5c5n19b/p`, `lws5c5n20b/p`). Listeners showed `leikwan-ingress` on `*:443` and `*:7000`.
- Journals had wstunnel CF websocket handshake errors, but not the `EADDRINUSE`/start-limit signature.
- Sidecar live read through a slave was attempted but blocked by missing `sshpass` on the slave. Mark sidecar Phase-0 live read incomplete; this is not a blocker for commit-only Phases 1-3, but it remains a deploy-readiness item for Phases 4-5.

Verdict: the available live signature does **not** show a slave-side port-collision/restart-storm outage. The redesign targets the bond-runtime re-admit/cooldown class by making the selected LeiGod path true MPTCP and not publishing the bond runtime. It does not by itself fix unrelated CF websocket handshake errors or any future static-uplink port collision; Phase 5 must still verify the deployed MPTCP path does not reintroduce collisions.

## Runtime-selection facts

- `master/src/Support/ProvisioningService.php:3529-3532` reads `forwards.client_mode`, reads combo runtime preference, then sets `$publishesBondRuntime = $clientMode === 'bond' || $runtimePreference === 'mptcp_preferred_bond_fallback';`.
- `master/src/Support/ProvisioningService.php:3556-3564` builds the bond config only when `$publishesBondRuntime` is true, and builds the forward-level MPTCP config only when `$clientMode === 'mptcp'`.
- `master/src/Support/ProvisioningService.php:3624-3629` publishes the client-visible `mode` and `mptcp` fields.
- `master/src/Support/ProvisioningService.php:6418-6421` repeats the task-publish gate from the forward row: it reads `forwards.client_mode`, resolves runtime preference, then computes the same `$publishesBondRuntime` formula.
- `master/src/Support/ProvisioningService.php:6457` emits `publish_bond_runtime`; `master/src/Support/ProvisioningService.php:6476-6480` emits `mptcp_underlay` only when `$clientMode === 'mptcp'`.
- `client-agent/client_agent.py:2632-2636` returns the requested mode immediately when `runtime_preference != "mptcp_preferred_bond_fallback"`. Therefore `client_mode='mptcp'` plus `runtime_preference='single'` selects MPTCP directly.

## Exact early-return fix site

In `slave-agent/slave_agent.py`, the true-MPTCP branch is:

- condition: `slave-agent/slave_agent.py:3169`;
- `apply_mptcp_underlay(...)`: `slave-agent/slave_agent.py:3196`;
- selector handling: `slave-agent/slave_agent.py:3197-3200`;
- early `return`: `slave-agent/slave_agent.py:3201`;
- normal `apply_sidecar_uplink(...)` is after the return at `slave-agent/slave_agent.py:3211-3212`.

Fix site for Phase 1: insert the static-uplink apply in that true-MPTCP branch after `apply_mptcp_underlay(...)` at `slave-agent/slave_agent.py:3196` and before the early return at `slave-agent/slave_agent.py:3201`.

## Forward-level MPTCP block

- The client runtime gate requires the forward-level `mptcp.tcp_session_token` to be non-empty and either `mptcp.sidecar_port` or `mptcp.remote_port` to be positive: `client-agent/client_agent.py:2619-2630`.
- `buildMptcpClientConfig(...)` emits `sidecar_port`, `remote_port`, and `tcp_session_token` at `master/src/Support/ProvisioningService.php:7784-7792`.
- The client-visible forward gets that block as `mptcp` only through `buildClientConfig(...)`, where `$mptcpConfig` is created only for `$clientMode === 'mptcp'` and returned as `'mptcp' => $mptcpConfig`: `master/src/Support/ProvisioningService.php:3564` and `master/src/Support/ProvisioningService.php:3628-3629`.
- The slave underlay payload also emits `sidecar_mptcp_port` and tokens at `master/src/Support/ProvisioningService.php:9112-9114`, but that underlay-level payload is not the block `client-agent/client_agent.py:2619-2630` reads for runtime selection.

Verdict: the forward-level MPTCP block is populated for a LeiGod combo only when the actual `forwards.client_mode` row is `mptcp`. It is not enough to update only `forward_combinations.client_mode`.

Phase-2 gap to add: existing combo-backed forwards read `forwards.client_mode`, not only `forward_combinations.client_mode` (`master/src/Support/ProvisioningService.php:3529`, `master/src/Support/ProvisioningService.php:6418`). Current `saveForwardCombination(...)` syncs only denormalized `backend_mode` and `cf_location_group_id` into `forwards` (`master/src/Support/ProvisioningService.php:637-658`), so Phase 2 must sync and migrate `forwards.client_mode` as well.

## Aggregation cap

- Current live combo `5` had `helper_group_cap=4`.
- Code default is different for a new or zero-cap `leigod_mode` + `mptcp` + `single` tuple: `defaultMaxLegsUsedForCombination(...)` returns `DEFAULT_MAX_LEGS_USED` only for `client_mode='bond'`, hybrid runtime preference, or `backend_mode='cf_mode'`; otherwise it returns `0` (`master/src/Support/ProvisioningService.php:397-409`).
- `DEFAULT_MAX_LEGS_USED` is `4` (`master/src/Support/ProvisioningService.php:14`).
- `pickFastestSlavesForCombination(...)` treats cap `0` as unlimited because it slices only when `$cap > 0` (`master/src/Support/ProvisioningService.php:6896`).

Verdict: live combo `5` is capped at `4`, but the default tuple `defaultMaxLegsUsedForCombination('mptcp', 'single', 'leigod_mode')` returns `0` (unlimited), not the explicit `>1` cap requested by the plan. Phase 2 should make this tuple return `DEFAULT_MAX_LEGS_USED` or document the unlimited behavior clearly; prefer `DEFAULT_MAX_LEGS_USED`.
