# sh36 cf_wg Checkpoint — Next Fixes (handoff)

**Date:** 2026-05-18
**Branch baseline:** `master` (last commit `c1ad531 fix(client): bootstrap cf_wg while udp tunnel starts`)
**Working branch:** `fix/sh36-cfwg-route-and-heartbeat-republish-gate`
**Owner:** ops + agentic worker
**Plan reference:** `docs/superpowers/plans/2026-05-17-panel-republish-client-as-slave-and-multigroup-leg-cap-plan.md` (Task 11 addendum)

---

## TL;DR

The Tokyo (sh36) **client-as-a-slave / cf_wg** flow is up but two real bugs remain after `c1ad531`:

1. **Loss ~30–40 % on the cf_wg leg.** The panel's `cf_runtime.routes` does not publish a UDP-capable backend that targets the sidecar's WireGuard listen port. The client renders `wstunnel client -L udp://… wss://<cf_ip> --http-upgrade-path-prefix <bond_path>`, but on the sidecar that exact `<bond_path>` resolves to the **bond TCP backend** (`127.0.0.1:<bond_remote_port>`). WireGuard datagrams arrive at a TCP-only listener; the WSS handshake succeeds (client thinks the leg is up), but every inner UDP packet is effectively dropped or mis-framed inside the sidecar.
2. **Panel keeps uploading and saturates the user's 30 Mbps uplink.**
   `SidecarAgentController::heartbeat()` calls `republishActiveForwardsForSidecarPoint()` **on every heartbeat (every 30 s)**, regardless of whether anything in the runtime state actually changed. Because of (1) `service_endpoints.health_status` keeps oscillating in `pending`/`degraded`/`error` for the cf_wg-bound forwards, so each heartbeat re-queues `publish` provision_tasks for every leg of every active forward bound to that sidecar. Slaves poll `/api/node/tasks` every 5 s and pull the multi-KB per-task payload back. On top of that the heartbeat response body itself (`cf_runtime` + `mptcp_runtime.forward_routes` + `self_leg_client_peers`) is non-trivial and goes out on every heartbeat too.

This handoff is the actionable to-do list. The minimal targeted code changes have been drafted on
`fix/sh36-cfwg-route-and-heartbeat-republish-gate` against `master` and are described under "Fix
plan" below. Live verification on sh36 must be done by the operator (the agent does not touch live
hosts from inside the plan).

---

## What we know is working

- Task 11 (`feat(panel,client): add cf_wg self-leg transport for WG over CF IP under CF Bond`)
  has landed. Saving a forward-combination with `backend_mode = cf_mode` and
  `self_leg_cf_transport = cf_wg` correctly produces a `self_leg_wg` payload with
  `outer_transport = 'wstunnel_udp'` plus `cf_dial_host`, `cf_dial_port`, `cf_sni_host`,
  `cf_path_prefix`, and `interface_name = lw-cfwg-<forward_id>`.
- The client-side `install_forward_runtime.py` correctly:
  - filters `selected_mptcp_self_leg_candidates()` so a `wstunnel_udp` cf_wg WG profile is **not**
    suppressed,
  - rewrites the WG `Endpoint =` line to `127.0.0.1:<40000 + (forward_id % 2000)>`,
  - emits a `wstunnel client -L udp://127.0.0.1:<local_udp>:<cf_dial_host>:<cf_dial_port>?…
    wss://<cf_dial_host>:<cf_dial_port>` line **before** `wg-quick up`,
  - waits for the local UDP listener via `wait_for_local_listener` (UDP branch via
    `ss -lun`) before bringing up `lw-cfwg-<forward_id>`.
- `c1ad531 fix(client): bootstrap cf_wg while udp tunnel starts` keeps the candidate as
  `selected_primary=true` even before the local UDP listener is ready, with
  `probe_state = "cf_wg_udp_tunnel_bootstrap"`. So the runtime starts; it just leaks loss.

So the failure is **not** "cf_wg never came up". It is "cf_wg came up but its bytes go to the wrong
backend on the sidecar".

---

## Bug #1 — cf_wg loss (~30 %–40 %)

### Symptom

Live forward 47 (sh36 → Tokyo) reports ~30–40 % packet loss after the client switches the MPTCP
runtime onto the cf_wg self-leg. Inside the sidecar `journalctl -u leikwan-sidecar-bond-*` shows
no WireGuard handshake completion for `lw-cfwg-47`, but the wstunnel WSS handshake to
`<cf_ip>:443` does complete and stays open.

### Root cause

The client's wstunnel CLI uses the forward's bond `path_prefix` (`/tokyo`-style) for both:

- the existing TCP bond leg (rented helpers + sidecar bond TCP backend), and
- the new cf_wg UDP leg (WG datagrams over WSS).

On the sidecar, `cf_runtime.routes[]` published by
`master/src/Controllers/SidecarAgentController.php::heartbeat()` always emits **one** route per
forward with that bond `path_prefix` and `target = 127.0.0.1:<bond_remote_port>`. That target is a
TCP-only bond backend (the wstunnel server unit started by `render_cf_backend_unit()` does
`wstunnel server ws://127.0.0.1:<backend_port> --restrict-to <target>` — and `<target>` here is
the bond TCP backend, not the WireGuard UDP listen port).

When the cf_wg client therefore sends WG datagrams via WSS using that same `path_prefix`, they
land on the bond TCP backend and are silently dropped or scrambled. There is **no** sidecar
runtime route that maps `<path_prefix>.cfwg` (or any other distinct path) to the sidecar's
`wireguard_listen_port` (default 14969) over UDP.

Evidence in code:

- Panel: `SidecarAgentController::heartbeat()` only emits `cf_runtime.routes` for the bond path
  prefix (and a `<path>.uot` shadow path when `transport_mode` contains UDP). It never emits a
  cfwg path.
- Panel: `ProvisioningService::buildSelfLegCandidate()` populates `wgProfile['cf_path_prefix']`
  from `$config['path_prefix']`, i.e. the same bond `path_prefix`.
- Sidecar: `leikwan_wss_mux.py::resolve_route()` only longest-prefix-matches on the routes file
  written from `cf_runtime.routes`, which currently contains a TCP backend for that path.
- Sidecar: there is no UDP terminator on `wireguard_listen_port` reachable through wss-mux today.

### Fix B (panel-side, smallest viable change)

1. **Panel publishes a separate cf_wg path** when `forwards.self_leg_cf_transport = 'cf_wg'`:
   - In `SidecarAgentController::heartbeat()` `$cfRows` loop, when the forward has
     `forward_combination_self_leg_cf_transport = 'cf_wg'` AND `backend_mode = cf_mode`, append an
     extra route entry (after the bond row, like the existing `.uot` shadow):

     ```php
     $carry[] = [
         'forward_id' => $forwardId,
         'service_name' => 'cf-forward-' . $forwardId . '-cfwg',
         'path_prefix' => $pathPrefix . '.cfwg',
         'target' => '127.0.0.1:' . $sidecarWireguardListenPort,
         'transport_mode' => 'udp',
         'backend_mode' => 'direct',
     ];
     ```

     The `direct` `backend_mode` is what makes
     `sidecar_agent.apply_management.cf_runtime` skip the wstunnel server unit and use the WG
     listen port as the literal backend (see `if direct_backend:` branch).

2. **Panel selects the new path for the cf_wg client renderer** by setting
   `wgProfile['cf_path_prefix'] = $config['path_prefix'] . '.cfwg'` inside
   `ProvisioningService::buildSelfLegCandidate()` when `$wantCfWg` is true.
3. **Panel must read `forward_combinations.self_leg_cf_transport` into the cf_rows** so step (1)
   can branch on it. Add `combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport`
   to the `$cfRows` SELECT in `SidecarAgentController::heartbeat()`.
4. **Panel must read the sidecar's `wireguard_listen_port`** in
   `SidecarAgentController::heartbeat()` from `$runtimeState['wireguard_listen_port']`. There is
   already a helper `sidecarMptcpListenPortForHeartbeat()` we can reuse.

The client side does not need any change for this fix, because `wgProfile['cf_path_prefix']` is
the exact string the client uses as `--http-upgrade-path-prefix`. Once the panel emits
`<path>.cfwg` and the sidecar publishes a matching UDP→WG-listen-port route, the cf_wg WG
handshake completes and loss collapses to the underlying CF link's loss (≈0 %).

### Why this does not break the existing bond TCP flow

- Existing bond TCP traffic still uses `<path_prefix>` (no `.cfwg` suffix). Unchanged.
- Existing UDP UoT shadow traffic still uses `<path_prefix>.uot`. Unchanged.
- `cf_wg` is opt-in per forward combination (`self_leg_cf_transport = 'cf_wg'`).
- When `self_leg_cf_transport = 'cf_direct'` (default), no `.cfwg` route is published.

---

## Bug #2 — panel keeps uploading on every sidecar heartbeat

### Symptom

User reports the panel is constantly pushing data and almost saturating their 30 Mbps uplink. The
sh36 client/sidecar heartbeats fire every 30 s, but the panel's outbound traffic appears
continuous.

### Root cause

`master/src/Controllers/SidecarAgentController.php::heartbeat()` line ~85 calls
`$this->provisioning->republishActiveForwardsForSidecarPoint((int) $point['id']);` **on every
heartbeat**, no matter what the heartbeat content is.

`republishActiveForwardsForSidecarPoint()` walks every active MPTCP-mode forward bound to that
sidecar and calls `reconcileForwardEndpoints()`, which:

- reads `service_endpoints` for every forward,
- compares against `pickNodesForGroups()` and panel-side health status,
- whenever an endpoint has `health_status` in `{pending, error, degraded}` it **re-queues a
  `publish` provision_task** for that endpoint with the full per-forward config payload
  (multi-KB).

Because Bug #1 keeps the cf_wg leg looking unhealthy in the loop above (handshake never
completes), endpoints flap into `degraded`/`error` and stay there. So every 30 s the panel
re-queues N×M provision_tasks. Slaves poll `/api/node/tasks` every 5 s and pull the bodies back.
The full-config rewrite per forward also flows out as part of the heartbeat response body itself
(`cf_runtime`, `mptcp_runtime.forward_routes`, `self_leg_client_peers`).

Simple math: ~50 forwards × ~5 endpoints × ~3 KB JSON = ~750 KB per republish cycle, every 30 s,
plus the 5-s task-pull amplification. That is comfortably over 200 kbit/s sustained outbound on a
reverse channel that the user only has 30 Mbps of, and it is wholly unnecessary while the
runtime is steady-state.

### Fix A (panel-side, smallest viable change)

In `SidecarAgentController::heartbeat()`, replace the unconditional
`republishActiveForwardsForSidecarPoint()` call with a **change-gated** call:

- Compute a heartbeat fingerprint `$wgFingerprint = sha1(($runtimeState['wireguard_public_key']
  ?? '') . ':' . ($runtimeState['wireguard_listen_port'] ?? 0) . ':' .
  ($runtimeState['wireguard_fallback_tcp_port'] ?? 0))`.
- Compare against the previously-stored `last_heartbeat_fingerprint` on
  `sidecar_points.agent_config_json` (a new key inside the existing JSON column — no schema
  change required).
- Only call `republishActiveForwardsForSidecarPoint()` when:
  - the fingerprint changed, **or**
  - the panel detects an admin change touching this point's combinations / sidecar runtime
    (already covered by `republishActiveForwardsForCombination()` / `saveSidecarPoint()`), **or**
  - the heartbeat is the first one after `last_heartbeat_at` is null/older than a configurable
    cooldown (default 10 minutes, env `SIDECAR_REPUBLISH_FORCE_INTERVAL_SECONDS`), to keep the
    safety-net republish.

Persist `last_heartbeat_fingerprint` and `last_heartbeat_republish_at` back into
`agent_config_json` inside the existing UPDATE.

This is a strictly additive behavior change: any explicit panel save of a forward, combination,
sidecar point, client, or service still calls `republishActiveForwardsFor*` directly on the save
path (Tasks 1 & 2 of `2026-05-17-panel-republish-client-as-slave-and-multigroup-leg-cap-plan.md`),
so no admin change is delayed. The only thing that goes away is the unconditional 30-s churn
caused by sidecar heartbeats.

### Why this does not break Tokyo cf_wg readiness

- Once Fix B (Bug #1) is in place, cf_wg endpoints stay healthy; nothing new triggers republish.
- If cf_wg is not yet healthy, the bootstrap republish still happens on the first heartbeat after
  the panel save (via the auto-republish wired in Task 2 of the plan above).
- The 10-min force-republish acts as a belt-and-braces safety net for the case where panel state
  drifts.

---

## Fix plan / to-do (in order)

The branch `fix/sh36-cfwg-route-and-heartbeat-republish-gate` already contains drafts of (1) and
(2). Run the full test sweep before pushing.

- [x] **Step 0 — confirm baseline.** `git status` clean on `master`.
- [x] **Step 1 — write this handoff document.** `docs/superpowers/handoffs/2026-05-18-sh36-cfwg-checkpoint-next-fixes.md`.
- [ ] **Step 2 — Fix B (panel cf_wg route).**
  - File: `master/src/Controllers/SidecarAgentController.php`
    - Add `combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport` to the
      `$cfRows` SELECT.
    - In the `array_reduce` over `$cfRows`, when the row has
      `forward_combination_self_leg_cf_transport === 'cf_wg'` AND `backend_mode === 'cf_mode'`,
      append an extra route entry as described in "Fix B" above. Read the WG listen port from
      `$runtimeState['wireguard_listen_port']` (fall back to
      `sidecarMptcpListenPortForHeartbeat()` only if heartbeats have not yet reported one).
  - File: `master/src/Support/ProvisioningService.php`
    - In `buildSelfLegCandidate()` cf_wg branch, set
      `$wgProfile['cf_path_prefix'] = (string) ($config['path_prefix'] ?? '/') . '.cfwg'` instead
      of the bare `path_prefix`.
  - Tests:
    - Create `master/tests/sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php`
      asserting that when `forward_combination_self_leg_cf_transport = 'cf_wg'` and `backend_mode
      = 'cf_mode'`, the heartbeat controller emits a route `cf-forward-<id>-cfwg` with
      `path_prefix` ending `.cfwg`, `transport_mode = 'udp'`, `backend_mode = 'direct'`, and a
      target host:port that uses the sidecar's WG listen port.
    - Update `master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php` to additionally
      assert `cf_path_prefix` carries the `.cfwg` suffix.
- [ ] **Step 3 — Fix A (heartbeat republish gate).**
  - File: `master/src/Controllers/SidecarAgentController.php`
    - Compute `$wgFingerprint` from `$runtimeState`.
    - Read `last_heartbeat_fingerprint`/`last_heartbeat_republish_at` from
      `$existingAgentConfig`.
    - Decide `$shouldRepublish = ($wgFingerprint !== $previous) || ($previousAt + force_interval
      < time())`.
    - Only call `republishActiveForwardsForSidecarPoint()` when `$shouldRepublish`.
    - Write `last_heartbeat_fingerprint` and (if republished)
      `last_heartbeat_republish_at` back into `$runtimeState`.
  - Test:
    - Create `master/tests/sidecar_heartbeat_republish_gate_regression_test.php` asserting that
      the controller source carries the gate snippets (`last_heartbeat_fingerprint`,
      `SIDECAR_REPUBLISH_FORCE_INTERVAL_SECONDS`, conditional `republishActiveForwardsForSidecarPoint`).
- [ ] **Step 4 — Local sweep.**
  - `php -l master/src/Controllers/SidecarAgentController.php`
  - `php -l master/src/Support/ProvisioningService.php`
  - `php master/tests/provisioning_self_leg_cf_wg_transport_regression_test.php`
  - `php master/tests/sidecar_cfwg_route_publishes_wg_listen_port_regression_test.php`
  - `php master/tests/sidecar_heartbeat_republish_gate_regression_test.php`
  - `php master/tests/sidecar_cf_runtime_target_regression_test.php`
  - `php master/tests/sidecar_downlink_regressions_test.php`
  - `python -m unittest client-agent.tests.test_self_leg_cf_wg_runtime client-agent.tests.test_self_leg_mptcp_solo_runtime client-agent.tests.test_runtime_preference_mptcp_bond_fallback`
- [ ] **Step 5 — Push branch and open PR.** Do not merge until live verification on sh36 below
  is green.

---

## Live verification (operator must drive — agent does not touch live hosts)

After the fix branch is deployed to the panel and clients pull a fresh config:

1. **Panel save → republish.** Edit the forward combination on the admin panel, click Save once,
   no other change. Tail panel logs for ~5 minutes and confirm:
   - one `republishForwardEndpoints` burst per forward bound to the combination right after
     save (Task 2 of the cap plan), then
   - **no further** `republishForwardEndpoints` lines triggered by heartbeats unless the sidecar
     reports a new `wireguard_public_key`/`wireguard_listen_port`/`wireguard_fallback_tcp_port`.
2. **Sidecar cf_wg route exists.** On the sidecar:
   - `sudo cat /var/lib/leikwan-sidecar-<pid>/cf-routes-443.json | jq '.routes[] | select(.service_name | endswith("-cfwg"))'`
     must contain a route whose `path_prefix` ends `.cfwg`, `backend_host = 127.0.0.1`,
     `backend_port = <sidecar wireguard_listen_port>`.
   - `sudo ss -lun | grep <wireguard_listen_port>` must show the WG listen port live.
3. **Client cleanup before any measurement.** Tear down stale interfaces before sampling
   (per the cap plan's Step 3 in Task 10).
4. **Client cf_wg up.** On the client:
   - `sudo wg show lw-cfwg-47` must show a non-zero `latest handshake` and `transfer rx` > 0
     within 30 s of the local UDP wstunnel listener coming up.
   - `ip -o link show | awk -F': ' '/lw-cfwg-/{print $2}' | cut -d@ -f1` must list `lw-cfwg-47`.
5. **Loss falls.** `ping -c 555 -i 1 -W 1` through the forwarded Tokyo target must show
   loss in low single digits, not 24–40 %. Throughput `iperf3 -c 127.0.0.1 -p 11035 -R -b 300M
   -t 12` must materially exceed the previous baseline.
6. **Panel uplink.** Operator monitors the panel's outbound rate (e.g. via `iftop` on the panel
   host's outbound interface). After the fixes the steady-state outbound should drop from
   "almost saturating 30 Mbps" to baseline (heartbeat responses + occasional admin pushes
   only).

---

## Anti-divergence rules

- Do not introduce new admin form fields. Both fixes are server-internal.
- Do not add new DB columns. Bug #2 reuses the existing `agent_config_json` JSON column on
  `sidecar_points` for `last_heartbeat_fingerprint` / `last_heartbeat_republish_at`.
- Do not touch slave-agent, sidecar-agent, Rust relay, Windows/Android/Desktop clients.
- Do not refactor `reconcileForwardEndpoints()`. The fix is upstream of it.
- Do not change `client-agent/install_forward_runtime.py` cf_wg rendering — it is correct, the
  panel was just publishing the wrong path.
- Do not bump dependencies, lockfiles, build configs.
- Do not force-push, rebase, or amend.
- Do not run `Republish All Forwards` against a live environment from inside the plan.

---

## Out of scope / explicitly deferred

- Per-leg WG keepalive tuning under the cf_wg path (sidecar-side keepalive is already 25 s; if
  CF kills idle WSS connections faster, that becomes a follow-up).
- Adding a tenant-visible dashboard for cf_wg health.
- Windows / Android cf_wg parity. Linux only for this pass.
- The two recurring-heartbeat patterns (`recordAgentUsage` and the heartbeat response body
  itself) — these are out of scope for this handoff. Bug #2 only addresses the unconditional
  republish-on-heartbeat amplifier.
