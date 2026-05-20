# CFWG Forward 47 wstunnel Sticky Port + Path Routing + ICMP Latency Watchdog

Source handoff: [`leikwan-br1/docs/superpowers/handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md`](../../handoffs/2026-05-20-cfwg47-known-good-restore-assessment.md)

This is the seventh deliverable in the CFWG forward 47 plan directory and is an extension, not a correction, of the five-doc assessment in `01-` through `05-`. The corrections to those docs live in [`00-plan-corrections.md`](./00-plan-corrections.md). This file does three things, in order:

1. Documents the wstunnel server architecture as it ships today (which port is sticky, how forwards are demuxed by HTTP upgrade path, where the per-route backend ports come from), so the next coder agent does not re-derive it from source.
2. Identifies the one remaining stickiness gap (per-route wstunnel backend port allocation in `[26000, 26100)` is dynamic, which is correct enough at runtime but produces non-deterministic systemd unit text that defeats byte-equal harness assertions across cold starts) and proposes a deterministic-by-`forward_id` allocation policy.
3. Designs a client-side ICMP latency watchdog for `lw-cfwg-<id>` interfaces. The watchdog observes WG peer reachability via ICMP, classifies degradation, and applies a three-tier escalation: restart the wstunnel client process, rotate the CF dial IP within `self_leg_cf_candidates`, and (if the panel publishes alternates) rotate the `cf_path_prefix` to a sibling alternate. The watchdog is the runtime counterpart of the existing render-time candidate ranking in `client_agent.py:2269 select_forward_candidates` and `:2379 apply_latency_target_candidate_escalation`; those run once at apply time, the watchdog runs continuously.

Two new contract semantics (I and J) are added on top of the eight (A through H) enumerated in [`01-cfwg-contract-assessment.md`](./01-cfwg-contract-assessment.md). Both must be assertable by the FEAT-002 harness before the watchdog is rolled out to live clients.

## Architecture as it ships today

The CFWG forward 47 path is `client (sh36) -> wstunnel client over WSS -> CF edge -> public mux on the sidecar -> per-forward wstunnel server backend -> sidecar WG listen port (14969) -> WG peer 10.204.53.42`. The handoff and `01-cfwg-contract-assessment.md` describe the same path. What follows is the level of detail the wstunnel-specific work needs but the assessment does not spell out.

### The public sticky port (already sticky)

The sidecar runs ONE TLS-terminating mux on the public port. The port is `cf_runtime.listen_port` (typically 2053) and is set by the panel in the heartbeat payload. The renderer is `sidecar_agent.py:809 render_cf_mux_unit`, which writes a systemd unit that starts `leikwan-wss-mux.py` with `--listen-port {listen_port}`. The mux is `sidecar-agent/leikwan_wss_mux.py`, a small asyncio TLS+HTTP server that:

- Accepts the TLS handshake (CF edge has already terminated the outer TLS; the sidecar's TLS is the inner CF-origin TLS leg).
- Reads up to `MAX_HTTP_HEADER_BYTES` of HTTP request headers (`leikwan_wss_mux.py:9`).
- Calls `WssPathMux.resolve_route(request_path)` (`leikwan_wss_mux.py:88`) which iterates routes ordered by `len(route_path)` descending (longest-prefix first; `:79`) and matches via `path_matches_prefix` (exact match or `route_path + '/'` prefix).
- If the request is a WebSocket upgrade and the route matched, opens a TCP connection to the matched route's `(backend_host, backend_port)` and bidirectionally relays bytes (`:139 proxy_request`, `:128 relay`).

The route table is `cf-routes-<listen>.json` written by `sidecar_agent.py` in the cf_runtime block (the per-route entries are appended into `rendered_routes` and serialized at the cf-routes path inside `apply_management`). The mux re-reads the file when its mtime changes (`leikwan_wss_mux.py:46`); no service restart is needed for a route table change.

External clients (sh36, every other client behind any forward) ALWAYS hit the same `(IP, listen_port)` pair (e.g. `wss://162.159.39.5:2053`). The user's request for "wstunnel server sticks on specific port" is satisfied at this layer today; sub-section "Stickiness gap and proposed fix" below covers the one place where stickiness is incomplete.

### Per-forward path-prefix routing (already path-routed)

Each forward gets a unique HTTP-upgrade path prefix on the same public port. The panel mints the prefix:

- For plain CF forwards (TCP): `forwards.path_prefix` (a hex string seeded by the forward id, by panel-side convention).
- For CFWG forwards (cf_wg self-leg): the same `forwards.path_prefix` with the `.cfwg` suffix appended at `ProvisioningService.php:3012` (`$wgProfile['cf_path_prefix'] = $cfBasePathPrefix . '.cfwg';`).

The panel emits one `cf_runtime.routes[*]` entry per active forward in the heartbeat to the sidecar. For cfwg, the entry is built at `SidecarAgentController.php:213-222` (the four-condition gate + emit; see `00-plan-corrections.md` line table). The sidecar takes the per-route entry and renders one wstunnel server backend unit per entry via `sidecar_agent.py:791 render_cf_backend_unit`:

```text
ExecStart=/usr/local/bin/wstunnel server ws://127.0.0.1:{backend_port} \
  --restrict-to {target} \
  --restrict-http-upgrade-path-prefix {path_prefix} \
  --websocket-ping-frequency-sec 5
```

Three flags carry the path-routed contract:

- `ws://127.0.0.1:{backend_port}`: this is the LOOPBACK port the per-forward wstunnel server listens on. Allocated in `[26000, 26100)` by `sidecar_agent.py:770 allocate_cf_backend_port`. The mux at port 2053 dials this loopback port after the path match. External clients never touch this port directly.
- `--restrict-to {target}`: the wstunnel server only forwards inner-decapsulated bytes to this address. For cfwg this is `127.0.0.1:14969` (the sidecar's own WireGuard listen port). For plain CF this is `127.0.0.1:<bond_remote_port>`.
- `--restrict-http-upgrade-path-prefix {path_prefix}`: the wstunnel server rejects any incoming WebSocket upgrade whose path prefix does not match. This is a defense-in-depth check against a misrouted mux: even if the mux somehow proxied a `cfwg-A` request to the `cfwg-B` backend, the backend would reject the upgrade rather than silently forward bytes to the wrong WG listen port.

So the path-routing contract is already a TWO-LAYER design: the mux (public port 2053) does the routing, and each backend (loopback 26xxx) verifies its own path prefix. The user's request for "using different path to tell client to receive different forward data" is satisfied at this layer today. The risk surface is panel-side correctness (does `forwards.path_prefix` for forward 47 actually match the path the wstunnel server backend is hardened to accept?) and that risk is exactly what the FEAT-002 render-diff harness is designed to catch.

### Where the per-forward `start_script` line is built (client side)

`install_forward_runtime.py:1015-1029 render_forward_runtime` (cf_wg branch) emits the client-side wstunnel client invocation:

```text
/usr/local/bin/wstunnel client \
  -L udp://127.0.0.1:{local_udp}:{cf_target_host}:{cf_target_port}?timeout_sec=0 \
  --tls-sni-override {cf_sni_host} \
  --http-headers Host: {cf_sni_host} \
  --http-upgrade-path-prefix {cf_path_prefix} \
  wss://{cf_dial_host}:{cf_dial_port}
```

The `cf_path_prefix` carried into this command is byte-equal to the `path_prefix` carried into the sidecar's `--restrict-http-upgrade-path-prefix` flag (after the `.cfwg` suffix is appended panel-side for cfwg forwards). That is the load-bearing equality the harness asserts.

## Stickiness gap and proposed fix

The mux's public port is sticky by panel-side configuration. The per-forward `path_prefix` is sticky by panel-side convention (it derives from `forwards.path_prefix`, a hex seeded by the forward id). The one remaining non-sticky element is the per-route loopback `backend_port` on the sidecar.

### Current behavior

`sidecar_agent.py:770 allocate_cf_backend_port` walks `cf-managed.json` (the persisted metadata file at `state_dir / "cf-managed.json"`):

1. If a row with `service_name == <this route's service_name>` already exists with `backend_port > 0`, return that port (sticky-within-installation).
2. Otherwise return the first port in `range(26000, 26100)` that is not in `used` (the set of `backend_port`s for OTHER service names) and not in `reserved` (caller-supplied for batch allocation).

Within a single sidecar installation that has not lost `cf-managed.json`, this is sticky enough: the same forward always lands on the same backend port. The gap appears in three operational scenarios:

1. **Cold install on a freshly-provisioned sidecar host.** `cf-managed.json` does not yet exist; the sidecar will assign port `26000` to whichever route happens to come first in `cf_runtime.routes[*]`, then `26001`, and so on. The order is panel-side and may shift between heartbeats.
2. **`cf-managed.json` wipe.** Operator runs a cleanup or the file is corrupted; allocation re-runs from scratch with the same first-come, first-served behavior.
3. **FEAT-002 fixture stability.** The render-diff harness in `03-regression-fixture-plan.md` asserts byte-equality on `expected-cf-backend-cf-forward-47-cfwg.service`, which contains the line `ws://127.0.0.1:{backend_port}`. If `backend_port` is non-deterministic, the fixture is fragile: a re-render against the same panel payload may produce port `26000` one day and `26002` the next depending on prior allocation history. The harness then either fails spuriously or has to special-case the port substitution, both of which are unsatisfying.

### Proposed deterministic policy

Allocate `backend_port = 26000 + (forward_id % 100)`, and only fall back to the linear scan if that slot is occupied by a different `service_name`. Concretely:

```python
def allocate_cf_backend_port(self, service_name: str, reserved_ports: set[int] | None = None, *, forward_id: int = 0) -> int:
    reserved = set(reserved_ports or set())
    used: dict[int, str] = {}  # port -> service_name owning it
    existing = 0
    for item in self.load_managed_cf_metadata():
        if not isinstance(item, dict):
            continue
        backend_port = int(item.get("backend_port", 0) or 0)
        if backend_port <= 0:
            continue
        owner = str(item.get("service_name", ""))
        if owner == service_name:
            existing = backend_port
            continue
        used[backend_port] = owner
    if existing > 0 and existing not in reserved and used.get(existing) != service_name:
        return existing  # respect sticky-within-installation
    if forward_id > 0:
        preferred = 26000 + (forward_id % 100)
        if preferred not in reserved and used.get(preferred) is None:
            return preferred
    for port in range(26000, 26100):
        if port not in reserved and used.get(port) is None:
            return port
    raise RuntimeError("no free sidecar cf backend port available")
```

Three properties hold:

- Existing installations keep their assignments (backward-compatible; `existing` branch fires first).
- Cold installs and `cf-managed.json` wipes converge to a forward-id-deterministic port. Forward 47 always lands on `26047`. Forward 26 always lands on `26026`. Forward 49 always lands on `26049`.
- Fixture stability: the harness can hard-code `26047` in `expected-cf-backend-cf-forward-47-cfwg.service` and the assertion holds across cold starts, panel re-pushes, and `cf-managed.json` wipes.

The `forward_id % 100` collision space is `[0, 100)` and the allocation space is `[26000, 26100)`, so collisions only happen across forwards whose ids share the same residue mod 100 (e.g. forward 47 and forward 147). When that happens the linear-scan fallback fires, and the LATER allocator falls forward to the next free slot; the EARLIER allocator keeps its preferred port. This preserves forward 47's stickiness as long as no migration ever introduces an existing forward 147, which is acceptable because (a) the panel chooses forward ids and can avoid the collision, and (b) the existing assignment in `cf-managed.json` survives across rounds, so the only window for a destructive collision is the very first heartbeat after both forwards exist simultaneously without any `cf-managed.json` entry.

### Why not just hash the path_prefix into the port

A surface-level alternative is `backend_port = 26000 + (hash(path_prefix) % 100)`. Two reasons not to:

1. The path prefix is panel-rotatable (in principle the panel could rotate `forwards.path_prefix` for a forward without changing the forward id; today it does not, but nothing in `ProvisioningService.php` prevents it). Using `forward_id` ties the port to the stable identifier the panel exposes.
2. Hash-of-string allocation makes the port space harder to reason about during incidents. `26000 + (forward_id % 100)` is greppable: an operator who sees `ws://127.0.0.1:26047` immediately knows the forward id is `47`, `147`, `247`, etc.

### Test coverage for the deterministic policy

Extend `leikwan-br1/sidecar-agent/tests/test_cfwg_forward_47_route_render.py` (proposed in `03-regression-fixture-plan.md`) with one assertion:

```python
self.assertEqual(rendered["routes"][0]["backend_port"], 26047)
```

And one assertion in `test_sidecar_cf_mode_runtime.py` (or a new test) that exercises the cold-start path with `cf-managed.json` absent, runs the renderer, and verifies the resulting backend_port equals `26000 + (forward_id % 100)`. Add a second test that pre-seeds `cf-managed.json` with a colliding service_name on `26047`, runs the renderer for forward 47, and verifies the linear-scan fallback fires (port `26000` is the first free slot in that scenario). Both tests fall under the FEAT-002 harness umbrella and run alongside the existing `test_sidecar_cf_mode_runtime.py` cf_wg test (which already asserts `--restrict-to 127.0.0.1:14969` and `--restrict-http-upgrade-path-prefix base.cfwg`).

## Contract semantic I: per-forward backend_port = 26000 + (forward_id % 100)

This is a new contract semantic on top of the eight (A through H) in `01-cfwg-contract-assessment.md`. Adopting it requires:

1. The `allocate_cf_backend_port` change above (`sidecar-agent/sidecar_agent.py`).
2. A `sidecar_agent` artifact regeneration (this is one of the four lineages flagged by R-06 / C-05; the regeneration must happen alongside the policy change, not separately).
3. A FEAT-002 harness assertion that pins `backend_port = 26047` for forward 47 in the expected fixture.
4. An entry in `01-cfwg-contract-assessment.md` (added by the next coder agent, not by this plan) under a new section "I. Per-forward backend_port determinism".

The semantic is asserted at three points in the wire/rendered output, each of which the harness already pins or can pin:

- `cf-routes-<listen>.json`: each `routes[*].backend_port` field equals the forward-id-derived port.
- `cf-forward-<id>-cfwg.service` (and the analogous tcp `cf-forward-<id>.service`): the `ws://127.0.0.1:{backend_port}` line in the systemd unit equals the forward-id-derived port.
- The mux's runtime resolution (`leikwan_wss_mux.py:88 resolve_route`) returns `(backend_host='127.0.0.1', backend_port=<forward-id-derived>)` for the matching path. This last point is not directly assertable from a unit test (the mux is a long-running asyncio process), but it is implicit in the cf-routes JSON the mux reads.

## ICMP latency watchdog (the auto-restart / auto-path-correct feature)

The handoff and the existing five-doc plan are silent on what happens when `lw-cfwg-47` is up but degrading. The user's request reads "auto restart or auto path correction depending on WG latency by ping (icmp)". The design below answers that.

### What the watchdog observes

For each `lw-cfwg-<id>` interface the client agent has rendered, a side-car watchdog process runs ICMP echo at a fixed cadence (default 5 seconds) bound to that specific interface (`ping -I lw-cfwg-<id> -c 1 -W 1 <peer_address>`). The peer address is the WG `[Peer] AllowedIPs` value (the same target the handoff ping-tests against on sh36, e.g. `10.204.53.42`).

The watchdog maintains a sliding window of the last N samples (default N=12, i.e. one minute at 5-second cadence) per interface and computes:

- `loss_pct`: fraction of samples in the window that timed out (no reply within `-W 1`).
- `rtt_avg_ms`: arithmetic mean of `rtt` for the samples that did reply.
- `rtt_p95_ms`: 95th percentile of `rtt` for the samples that did reply.

Three thresholds, all panel-publishable per forward (semantic J below):

- `loss_pct_warn` (default 5%): degradation suspected.
- `loss_pct_critical` (default 20%): degradation confirmed.
- `rtt_p95_ms_critical` (default `rtt_baseline_ms * 3`, where `rtt_baseline_ms` is the EWMA of the last hour of `rtt_avg_ms` samples while in the steady state, lower-bounded at 80ms to avoid pathological behavior on already-low-latency links).

The watchdog only acts if it sees TWO consecutive windows (i.e. ~2 minutes) above critical thresholds. This prevents a single packet-loss burst from triggering churn.

### Three-tier escalation

When the watchdog confirms degradation, it walks an escalation ladder. Each tier fires once and then yields back to the watchdog for one more observation window before escalating further. If a tier resolves the degradation (sliding window returns below `loss_pct_warn` AND `rtt_p95_ms_critical`), the watchdog resets the ladder for that interface.

**Tier 1: Restart the per-forward wstunnel client process.**

The cheapest correction. The wstunnel client process is supervised by systemd as `leikwan-client-<forward_id>.service` (rendered by `install_forward_runtime.py`). The watchdog runs `systemctl restart leikwan-client-<forward_id>.service`. The restarted wstunnel client opens a fresh TCP connection to the CF edge, which often resolves transient socket-level wedges (TCP RST not propagated, websocket ping pong stuck after a CF edge migration, etc.). The WG handshake on `lw-cfwg-<id>` re-establishes within one keepalive interval (25 seconds in the handoff evidence). Cost: ~30 seconds of dropped packets.

**Tier 2: Rotate the CF dial IP within `self_leg_cf_candidates`.**

If Tier 1 does not resolve degradation, the watchdog selects the next IP from the published `self_leg_cf_candidates` list (`client_agent.py:2517` reads this list today during render-time selection) and re-renders the forward with that IP pinned. Re-render uses the existing `apply_forward` path (`client_agent.py:1044`), which the FEAT-002 harness already covers. Concretely:

- The watchdog writes a small override file `/var/lib/leikwan-client-agent/cfwg-watchdog-pin/<forward_id>.json` containing `{"cf_dial_host_pin": "<next_candidate_ip>"}`.
- `install_forward_runtime.py self_leg_wg_underlay()` is extended to read that pin file and, if present and within a TTL (default 10 minutes), use the pinned IP as `cf_dial_host` instead of `self_leg_cf_candidates[0]`.
- The watchdog calls `client_agent.apply_forward(forward)` (in-process, since the watchdog is itself a thread inside `leikwan-client-agent.service`).

The pin TTL ensures that if the entire candidate list is degraded, the watchdog does not get stuck on a bad pin; after TTL expiry the pin is dropped and the panel-published order resumes. Cost: ~1 minute of dropped packets (Tier 2 includes a Tier 1 restart implicitly).

**Tier 3: Rotate the `cf_path_prefix` to a published alternate.**

If Tier 2 does not resolve degradation, the watchdog cycles the path prefix. This requires the panel to publish alternates; today the panel only emits one path prefix per forward (`<base>.cfwg` for cfwg). The proposed change is to add `cf_path_prefix_alternates: ["<base>.cfwg.alt1", "<base>.cfwg.alt2"]` to the `self_leg_wg` block when the operator has enabled the feature for that forward. The sidecar must also emit shadow routes for each alternate (additional entries in `cf_runtime.routes[*]` with the same `target` and `backend_port` but distinct `path_prefix`); `SidecarAgentController.php` line 216 emit logic must be extended to iterate alternates.

Tier 3 only fires if alternates are published. Forwards that do not publish alternates stop escalating after Tier 2 and the watchdog reports a structured failure event back to the panel via the existing diagnostics channel (`client_agent.py:3335 run_diagnostics_if_requested`). Cost: ~1 minute of dropped packets and a panel-side observability surface for the operator.

### Why ICMP and not a higher-level probe

The handoff's success criterion for forward 47 is specifically `ping -I lw-cfwg-47 -c 3 -W 1 10.204.53.42` returning 0% loss. Reusing ICMP gives the watchdog the exact same signal the operator uses to declare success. Higher-level probes (TCP connect to a service behind the WG peer, DNS resolution over the tunnel) introduce confounders that depend on services running on the peer side; ICMP is the lowest-confounder probe and matches the handoff's working definition of "the path is up".

### Why a separate watchdog and not just `wg show` polling

`wg show lw-cfwg-<id>` exposes `latest handshake` and `transfer: received/sent` counters. Polling those is cheaper than ICMP, but they have a fundamental gap: if the wstunnel client wedges in a state where it sends bytes but receives nothing, `received` stops increasing and `latest handshake` ages out, but the kernel's WG state machine may still report the interface up for tens of seconds before the WG handshake actually fails. ICMP-bound-to-interface forces an end-to-end round-trip and is therefore more sensitive to silent failure.

A lower-cost compromise is to combine both: poll `wg show` once per cycle and only run ICMP when `wg show` reports zero growth in `received` since the last cycle. The doc proposes ICMP-always for simplicity in the first version; the next coder agent may add the `wg show` short-circuit as an optimization.

### Where the watchdog lives

Inside `leikwan-client-agent.service`, in a new module `client-agent/cfwg_latency_watchdog.py`. The module exposes:

```python
class CfwgLatencyWatchdog:
    def __init__(self, agent: ClientAgent) -> None: ...
    async def run_forever(self) -> None: ...  # called from the agent's main loop
    def observe_forward(self, forward_id: int, interface_name: str, peer_address: str, thresholds: dict) -> None: ...
    def stop_observing(self, forward_id: int) -> None: ...
```

The agent's main pull loop registers each cf_wg forward with the watchdog after a successful `apply_forward` and unregisters on forward retirement. The watchdog runs in its own asyncio task; ICMP is invoked via `asyncio.create_subprocess_exec("/bin/ping", ...)` to avoid raw-socket privilege requirements (the agent already runs as root for systemd management, so privileged raw sockets would also work, but `/bin/ping` is the lower-friction path and matches the handoff's diagnostic command exactly).

### Watchdog config publishing

The thresholds and the alternates list are panel-published per forward, embedded in the same `self_leg_wg` block that already carries `cf_path_prefix`, `cf_dial_host`, etc. New fields (semantic J below):

- `cf_latency_watchdog`: object, optional. If absent, the watchdog observes but does not act (it logs degradation events but never escalates).
  - `enabled`: bool, default `false`. Master switch.
  - `probe_interval_seconds`: int, default 5.
  - `window_samples`: int, default 12.
  - `loss_pct_warn`: float, default 0.05.
  - `loss_pct_critical`: float, default 0.20.
  - `rtt_p95_ms_critical_multiplier`: float, default 3.0 (multiplier applied to `rtt_baseline_ms`).
  - `rtt_p95_ms_floor`: int, default 80.
  - `escalation_dwell_windows`: int, default 2 (must see this many consecutive windows above critical before escalating).
  - `cf_path_prefix_alternates`: list of strings, default `[]`. Empty disables Tier 3.

The panel-side change is in `ProvisioningService.php buildSelfLegWireGuardProfile` (private, line 3056) which adds the `cf_latency_watchdog` block to `$wgProfile`. The client-side change is in `install_forward_runtime.py self_leg_wg_underlay()` which passes through the block to the rendered forward dict, and in `client_agent.py apply_forward` (line 1044) which extracts the block and registers/updates the watchdog observation for that forward.

## Contract semantic J: cf_latency_watchdog observes ICMP and escalates predictably

This is a new contract semantic on top of A through I. Adopting it requires:

1. The new client-side module `client-agent/cfwg_latency_watchdog.py` and its integration in `client_agent.py` apply_forward / pull-loop.
2. The `install_forward_runtime.py` passthrough for `cf_latency_watchdog` and `cf_dial_host_pin` (the watchdog's runtime override file).
3. The panel-side publish of `cf_latency_watchdog` and (optionally) `cf_path_prefix_alternates` in `ProvisioningService.php buildSelfLegWireGuardProfile`.
4. The sidecar-side shadow-route emit for each alternate in `SidecarAgentController.php` (the four-condition gate at line 213 needs to iterate alternates; `service_name` becomes `cf-forward-<id>-cfwg-alt<n>` for the n-th alternate).
5. FEAT-002 harness extensions:
   - `leikwan-br1/client-agent/tests/test_cfwg_forward_47_render_diff.py` asserts the `cf_latency_watchdog` block round-trips from `panel-payload.json` into `rendered["start_script"]` neighborhood (the watchdog config does not affect the wstunnel client invocation, but it lands in the agent's in-memory state and that should be observable via a new `rendered["watchdog_config"]` field on the renderer's return dict).
   - A new test `leikwan-br1/client-agent/tests/test_cfwg_latency_watchdog.py` exercises the watchdog's three-tier escalation against a mocked `asyncio.create_subprocess_exec` that returns scripted ICMP outputs (loss bursts, latency spikes, recovery). Assertions: Tier 1 fires once after dwell, Tier 2 writes the pin file with the next candidate, Tier 3 cycles `cf_path_prefix` through alternates, the ladder resets on recovery.
   - `leikwan-br1/sidecar-agent/tests/test_cfwg_forward_47_route_render.py` asserts that when `cf_path_prefix_alternates` is non-empty, the sidecar emits N+1 shadow routes (the canonical `.cfwg` plus N alternates) all pointing at the same `(target, backend_port)`.
   - `leikwan-br1/master/tests/cfwg_forward_47_panel_render_diff_test.php` asserts `assignedForwardConfigsForClient` (line 2647 per `00-plan-corrections.md`) carries `cf_latency_watchdog` on the candidate dict when the panel feature flag is enabled.

The semantic is asserted at four points: panel publish, client render, watchdog runtime behavior, sidecar shadow-route emit. All four are FEAT-002 deliverables under the umbrella of forward 47 fixtures.

## Rollout sequencing

The deterministic-port change (semantic I) is independent of and a prerequisite for the watchdog rollout (semantic J). Sequence:

1. **Stage 1: deterministic backend_port.** Implement, ship via the panel artifact lineage (this is one of the four R-06 lineages; the sidecar manifest entry must be regenerated). The harness pins `backend_port = 26047` for forward 47. The unpin runbook from `04-rollout-unpin-runbook.md` runs as designed; the only behavioral difference is that the sidecar now writes `26047` instead of `26000` (or whichever port was assigned previously) for forward 47. Existing installations that already have a `cf-managed.json` entry keep their assignment; new installations and post-wipe re-renders converge to `26047`. No watchdog interaction.
2. **Stage 2: watchdog observe-only.** Ship the watchdog module with `cf_latency_watchdog.enabled = false` panel-default. The watchdog logs degradation events but takes no action. Use this stage to validate the threshold defaults and the dwell-window logic on real traffic without risking churn. Recommend at least one week of observation across multiple forwards.
3. **Stage 3: watchdog Tier 1 only.** Panel toggles `enabled = true` for a small set of forwards (forward 47 included as the canary). Tier 2 and Tier 3 are gated off until Tier 1's restart-only behavior has been observed not to cause harmful cascades (e.g. a misconfigured forward that triggers a restart loop).
4. **Stage 4: Tier 2 enabled.** After Stage 3 has been stable for at least a week, the panel publishes `self_leg_cf_candidates` ordered by independent path quality (this ordering already exists today; Stage 4 is mostly a docs/runbook change).
5. **Stage 5: Tier 3 enabled.** The panel publishes `cf_path_prefix_alternates`; the sidecar emits the shadow routes. This is the largest behavioral change and should be staged behind a per-forward flag.

Each stage has its own pre-flight harness run and its own rollback. The unpin runbook in `04-` is the template; new sections per stage follow the same structure (pre-flight host backup, pre-flight repo harness, toggle, verify, rollback).

## Non-goals for this doc

- This doc does not propose changing the public mux port (`cf_runtime.listen_port`), the TLS stack inside the mux, or the path-prefix concatenation rule (`<base>.cfwg`). All three are working as designed; the user's request to "stick on a specific port" is satisfied at the public mux layer today, and the deterministic-backend-port proposal in semantic I is an internal stickiness improvement, not a public-port change.
- This doc does not replace the FEAT-002 fixture-based harness with a live ICMP-driven test on real hosts. The harness mocks ICMP via `asyncio.create_subprocess_exec`; only the staged rollout exercises real ICMP, and that is gated by the runbook in `04-` plus the new per-stage pre-flight checks listed in "Rollout sequencing".
- This doc does not propose moving the wstunnel server backends off systemd. The systemd-supervised model is intentional (the watchdog drives `systemctl restart` for Tier 1) and matches the rest of the agent ecosystem.
- This doc does not propose any change to the `disable_auto_update` gate. The pin still holds during Stages 1-5; clearing it is a separate operator decision per `04-` Sections 3 and 4.
- This doc does not propose ripping out `apply_latency_target_candidate_escalation` (`client_agent.py:2379`) or the existing render-time candidate ranking logic. Those run once at apply time and continue to do so; the watchdog is a complementary runtime-time mechanism, not a replacement.

## Reading-order pointer

This is the last doc in the directory for this assessment cycle. After it, the next coder agent's entry point is FEAT-002 implementation, which combines:

- The fixture and harness design from `03-regression-fixture-plan.md` (with the corrections in `00-plan-corrections.md`).
- The two new contract semantics I and J from this doc.
- The four manifest lineages from `00-plan-corrections.md` C-05.

When the FEAT-002 harness is green against synthetic and live-captured fixtures, the FEAT-003 unpin runbook in `04-rollout-unpin-runbook.md` becomes runnable. The deterministic-port change (Stage 1 above) ships first, then the watchdog observation stages roll out per the sequencing above.
