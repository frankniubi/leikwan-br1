# Forward 26 TCP + UDP Bad-Leg Health Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop `forward 26` from letting one toxic leg poison either bond TCP sessions or the UDP UoT sidepath.

**Architecture:** Fix the core `leikwan-bond` TCP selector first so sticky/preferred-leg behavior no longer bypasses runtime health. Then keep using client-agent bond endpoint health as the control-plane truth for UDP UoT bootstrap, while letting the client and sidecar UoT runtimes refine leg quality from real runtime failures. Roll out in dependency order: sidecar bond binary, client bond binary, then UDP UoT Python runtimes.

**Tech Stack:** Rust (`wstunnel-main/leikwan-bond`), Python 3.11/3.13, systemd-managed runtimes, `iperf3`, existing `leikwan-ingress` plain TCP mux path, panel-served artifacts under `master` + `client-agent` + `sidecar-agent`.

> **Why this plan changed:** the earlier UDP-only plan assumed TCP was already using bad-leg health correctly. That is false. `select_stream_leg(...)` in `wstunnel-main/leikwan-bond/src/session.rs` still short-circuits interactive TCP traffic to `preferred_leg_id` if the leg is merely active, which means unhealthy legs can keep owning sticky TCP sessions.

---

## File Structure

### Existing files to modify
- `wstunnel-main/leikwan-bond/src/session.rs`
  - Responsibility: core bond TCP/UDP scheduling, leg health scoring, sticky/warmup/bulk path, local health snapshots
  - Why: the TCP sticky shortcut still bypasses health state and effective weight
- `client-agent/install_forward_runtime.py`
  - Responsibility: render client forward runtime units and write `/etc/leikwan/uot-bond-forward-<id>.json`
  - Why: this is where UDP UoT currently needs the existing bond endpoint health metadata propagated into its config
- `client-agent/leikwan_uot_bond.py`
  - Responsibility: client-side UDP UoT runtime over `client -> slave`
  - Why: this is where per-leg outbound UDP scheduling and reconnect behavior need weighted auth plus probation/quarantine
- `sidecar-agent/leikwan_udp_uot_merge.py`
  - Responsibility: sidecar-side UDP UoT merge runtime over `slave -> sidecar`
  - Why: this is where reverse UDP scheduling must accept weighted auth and suppress toxic endpoints
- `client-agent/tests/test_mptcp_bond_regressions.py`
  - Responsibility: render/config regression coverage for client runtime generation
- `client-agent/tests/test_uot_bond_runtime.py`
  - Responsibility: client UoT runtime unit tests
- `sidecar-agent/tests/test_udp_uot_merge_runtime.py`
  - Responsibility: sidecar UoT runtime unit tests
- `docs/BOND_OPERATIONS.md`
  - Responsibility: operator-facing runtime contract and rollout guidance
  - Why: docs must describe both TCP bond health behavior and UDP UoT health behavior together

### Existing files to inspect while implementing
- `client-agent/client_agent.py`
  - Source of `dynamic_priority`, `health_role`, and quarantine/probation rules already feeding runtime rendering
- `docs/superpowers/plans/2026-04-19-bad-leg-adaptive-weighting-and-quarantine.md`
  - Existing design baseline for the bond-core health model already partially implemented in `session.rs`
- Live client snapshot: `/run/leikwan/bond-leg-health-forward-26.json`
  - Real proof of the client bond runtime's TCP/UDP health decisions
- Live client control-plane truth: `/var/lib/leikwan-client-agent/bond-endpoint-health.json`
  - Current endpoint health truth that should feed UDP UoT bootstrap
- Live UDP config: `/etc/leikwan/uot-bond-forward-26.json`
  - Proof of what the UDP UoT runtime is actually consuming
- Live sidecar snapshot: `/var/lib/leikwan-sidecar-4/bond-leg-health.json`
  - Real proof of sidecar bond runtime health state after rollout

---

### Task 1: Make TCP sticky selection respect runtime leg health

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Test: existing `#[cfg(test)]` block in `wstunnel-main/leikwan-bond/src/session.rs`

- [ ] **Step 1: Write the failing TCP sticky-selector tests**

Add these tests near the existing striping/health tests in `wstunnel-main/leikwan-bond/src/session.rs`:

```rust
    #[test]
    fn interactive_tcp_skips_unhealthy_preferred_leg() {
        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        penalize_tcp_leg(&mut legs, 0, TcpPenalty::ControlSocketClosedUnexpectedly);
        penalize_tcp_leg(&mut legs, 0, TcpPenalty::RetransmitBurst);

        let mut cursor = 0usize;
        let selected = select_stream_leg(&legs, &mut cursor, Some(0), 1024, 0, &test_config());

        assert_eq!(legs[0].tcp_health.state, LegHealthState::Degraded);
        assert_eq!(selected, Some(1));
    }

    #[test]
    fn interactive_tcp_keeps_healthy_preferred_leg() {
        let legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        let mut cursor = 0usize;
        let selected = select_stream_leg(&legs, &mut cursor, Some(0), 1024, 0, &test_config());
        assert_eq!(selected, Some(0));
    }
```

- [ ] **Step 2: Run the tests to verify the first one fails**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-bond interactive_tcp_skips_unhealthy_preferred_leg -- --exact
cargo test -p leikwan-bond interactive_tcp_keeps_healthy_preferred_leg -- --exact
```

Expected:
- `interactive_tcp_skips_unhealthy_preferred_leg` FAILS because `select_stream_leg(...)` only checks `leg.active`
- `interactive_tcp_keeps_healthy_preferred_leg` PASSES

- [ ] **Step 3: Implement the minimal sticky-leg health gate**

In `wstunnel-main/leikwan-bond/src/session.rs`, add a helper near the selector helpers:

```rust
fn preferred_leg_allowed_for_interactive_tcp(legs: &[LegState], leg_id: usize, now: Instant) -> bool {
    let Some(leg) = legs.get(leg_id) else {
        return false;
    };
    if !leg_available_for_new_data(leg, now) {
        return false;
    }
    matches!(leg.tcp_health.state, LegHealthState::Healthy)
}
```

Then change the sticky fast-path inside `select_stream_leg(...)` to:

```rust
    if interactive_flow {
        if let Some(leg_id) = preferred_leg_id {
            if preferred_leg_allowed_for_interactive_tcp(legs, leg_id, Instant::now()) {
                return Some(leg_id);
            }
        }
    }
```

This keeps the sticky path for healthy legs, but forces degraded/probation/quarantined TCP legs back through the normal health-aware selector.

- [ ] **Step 4: Run the targeted Rust tests to verify they pass**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-bond interactive_tcp_skips_unhealthy_preferred_leg -- --exact
cargo test -p leikwan-bond interactive_tcp_keeps_healthy_preferred_leg -- --exact
cargo test -p leikwan-bond toxic_tcp_leg_is_downweighted_before_pool_collapses -- --exact
cargo test -p leikwan-bond leg_health_state_transitions_downward_and_upward -- --exact
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-bond/src/session.rs
git commit -m "fix: make tcp sticky selection respect leg health"
```

### Task 2: Propagate bond endpoint health into UDP UoT config

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Write the failing render test**

Add this test next to the existing UDP sidepath render coverage in `client-agent/tests/test_mptcp_bond_regressions.py`:

```python
    def test_bond_udp_uot_sidepath_renders_health_metadata_and_weights(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp+udp",
                "mode": "bond",
                "endpoints": [
                    {
                        "endpoint_id": 184,
                        "host": "8.163.90.132",
                        "port": 7001,
                        "ingress_auth_token": "token-1",
                        "dynamic_priority": 10,
                        "health_role": "healthy",
                    },
                    {
                        "endpoint_id": 185,
                        "host": "8.163.90.235",
                        "port": 7001,
                        "ingress_auth_token": "token-2",
                        "dynamic_priority": 25,
                        "health_role": "probation",
                    },
                    {
                        "endpoint_id": 186,
                        "host": "8.163.87.42",
                        "port": 7001,
                        "ingress_auth_token": "token-3",
                        "dynamic_priority": 40,
                        "health_role": "quarantined",
                    },
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "ws_priority": 10,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "udp_uot_sidepath": {
                    "enabled_forward_ids": [26],
                    "shadow_route_suffix": ".uot",
                    "sidecar_port": 8999,
                    "reorder_window_packets": 4096,
                    "reorder_window_bytes": 16777216,
                    "reorder_timeout_ms": 25,
                },
            },
        )

        udp_unit = rendered["extra_units"][0]
        config_file = next(item for item in udp_unit["files"] if item["path"] == "/etc/leikwan/uot-bond-forward-26.json")
        content = config_file["content"]
        self.assertIn('"dynamic_priority": 10', content)
        self.assertIn('"dynamic_priority": 25', content)
        self.assertIn('"dynamic_priority": 40', content)
        self.assertIn('"health_role": "healthy"', content)
        self.assertIn('"health_role": "probation"', content)
        self.assertIn('"health_role": "quarantined"', content)
        self.assertIn('"weight": 8', content)
        self.assertIn('"weight": 2', content)
        self.assertIn('"weight": 1', content)
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_udp_uot_sidepath_renders_health_metadata_and_weights -v
```

Expected: FAIL because the current JSON only contains `endpoint_id`, `host`, `port`, and `auth_token`.

- [ ] **Step 3: Implement minimal config propagation**

In `client-agent/install_forward_runtime.py`, add a helper near `udp_uot_sidepath_settings(...)`:

```python
def udp_leg_weight(endpoint: dict, default_priority: int = 10) -> int:
    role = str(endpoint.get("health_role", "healthy") or "healthy").strip().lower()
    if role == "quarantined":
        return 1
    priority = int_or_default(endpoint.get("dynamic_priority"), default_priority)
    delta = max(0, priority - default_priority)
    if delta >= 30:
        return 1
    if delta >= 15:
        return 2
    return 8
```

Then update the `legs` list inside `render_udp_uot_aux_unit(...)` to:

```python
        "legs": [
            {
                "endpoint_id": int(endpoint.get("endpoint_id", 0) or 0),
                "host": str(endpoint["host"]),
                "port": int(endpoint["port"]),
                "auth_token": str(endpoint.get("ingress_auth_token", "") or bond.get("ingress_auth_token", "")),
                "dynamic_priority": int_or_default(endpoint.get("dynamic_priority"), int_or_default(bond.get("ws_priority"), 10)),
                "health_role": str(endpoint.get("health_role", "healthy") or "healthy"),
                "weight": udp_leg_weight(endpoint, int_or_default(bond.get("ws_priority"), 10)),
            }
            for endpoint in endpoints
        ],
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_udp_uot_sidepath_renders_health_metadata_and_weights -v
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add client-agent/install_forward_runtime.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat: propagate bond health into udp uot config"
```

### Task 3: Add backward-compatible weighted auth payload on the client UDP UoT runtime

**Files:**
- Modify: `client-agent/leikwan_uot_bond.py`
- Test: `client-agent/tests/test_uot_bond_runtime.py`

- [ ] **Step 1: Write the failing auth payload test**

Add this test in `client-agent/tests/test_uot_bond_runtime.py`:

```python
    def test_build_leg_auth_payload_encodes_token_endpoint_and_weight(self) -> None:
        leg = uot_bond.LegConfig(
            endpoint_id=184,
            host="8.163.90.132",
            port=7001,
            auth_token="token-1",
            weight=8,
        )
        payload = uot_bond.build_leg_auth_payload(leg, "udp-token")
        decoded = json.loads(payload.decode("utf-8"))
        self.assertEqual(decoded["token"], "udp-token")
        self.assertEqual(decoded["endpoint_id"], 184)
        self.assertEqual(decoded["weight"], 8)
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
python -m unittest client-agent.tests.test_uot_bond_runtime.UotBondRuntimeTests.test_build_leg_auth_payload_encodes_token_endpoint_and_weight -v
```

Expected: FAIL because `build_leg_auth_payload` does not exist.

- [ ] **Step 3: Implement the auth payload helper and use it**

In `client-agent/leikwan_uot_bond.py`, add:

```python
def build_leg_auth_payload(leg_config: LegConfig, session_token: str) -> bytes:
    return json.dumps(
        {
            "token": session_token,
            "endpoint_id": int(leg_config.endpoint_id),
            "weight": max(1, int(leg_config.weight or 1)),
        },
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")
```

Then replace the current auth frame payload in `leg_loop(...)` with:

```python
payload=build_leg_auth_payload(leg_config, self.config.session_token),
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
python -m unittest client-agent.tests.test_uot_bond_runtime.UotBondRuntimeTests.test_build_leg_auth_payload_encodes_token_endpoint_and_weight -v
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add client-agent/leikwan_uot_bond.py client-agent/tests/test_uot_bond_runtime.py
git commit -m "feat: send weighted udp uot leg auth payload"
```

### Task 4: Add client-side UDP probation/quarantine for dead UoT legs

**Files:**
- Modify: `client-agent/leikwan_uot_bond.py`
- Test: `client-agent/tests/test_uot_bond_runtime.py`

- [ ] **Step 1: Write the failing leg-penalty test**

Add this test in `client-agent/tests/test_uot_bond_runtime.py`:

```python
    def test_penalize_leg_quarantines_after_three_failures(self) -> None:
        config = uot_bond.RuntimeConfig(
            forward_id=26,
            listen_udp="127.0.0.1:26026",
            route_key="rk",
            session_token="st",
            reorder_window_packets=16,
            reorder_window_bytes=4096,
            reorder_timeout_ms=10,
            legs=[uot_bond.LegConfig(endpoint_id=184, host="8.163.90.132", port=7001, auth_token="t1", weight=8)],
            stats_path="NUL",
        )
        runtime = uot_bond.Runtime(config)
        leg = runtime.leg_states[0]
        runtime.penalize_leg(leg, now_ms=1000)
        self.assertGreaterEqual(leg.weight, 1)
        runtime.penalize_leg(leg, now_ms=1100)
        self.assertGreaterEqual(leg.suppress_until_ms, 1100)
        runtime.penalize_leg(leg, now_ms=1200)
        self.assertEqual(leg.weight, 0)
        self.assertGreater(leg.suppress_until_ms, 1200)
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
python -m unittest client-agent.tests.test_uot_bond_runtime.UotBondRuntimeTests.test_penalize_leg_quarantines_after_three_failures -v
```

Expected: FAIL because `penalize_leg` does not exist.

- [ ] **Step 3: Implement minimal client-side UDP penalties**

In `client-agent/leikwan_uot_bond.py`, add constants and extend `LegState`:

```python
LEG_PROBATION_AFTER_FAILURES = 2
LEG_QUARANTINE_AFTER_FAILURES = 3
LEG_PROBATION_SECONDS = 5.0
LEG_QUARANTINE_SECONDS = 30.0

@dataclasses.dataclass
class LegState:
    endpoint_id: int
    weight: int = 1
    active: bool = True
    base_weight: int = 1
    consecutive_failures: int = 0
    suppress_until_ms: int = 0
```

Update `Runtime.__init__` so each `LegState` gets `base_weight=max(1, leg.weight)`.

Add these methods:

```python
    def penalize_leg(self, leg_state: LegState, now_ms: int | None = None) -> None:
        now_ms = int(time.time() * 1000) if now_ms is None else now_ms
        leg_state.consecutive_failures += 1
        if leg_state.consecutive_failures >= LEG_QUARANTINE_AFTER_FAILURES:
            leg_state.weight = 0
            leg_state.suppress_until_ms = now_ms + int(LEG_QUARANTINE_SECONDS * 1000)
        elif leg_state.consecutive_failures >= LEG_PROBATION_AFTER_FAILURES:
            leg_state.weight = max(1, leg_state.base_weight // 4)
            leg_state.suppress_until_ms = now_ms + int(LEG_PROBATION_SECONDS * 1000)
        else:
            leg_state.weight = max(1, leg_state.base_weight // 2)
            leg_state.suppress_until_ms = 0
        self.selector.refresh()

    def restore_leg(self, leg_state: LegState) -> None:
        leg_state.consecutive_failures = 0
        leg_state.suppress_until_ms = 0
        leg_state.weight = leg_state.base_weight
        self.selector.refresh()
```

Update `WeightedLegSelector` to support a `refresh()` method and rebuild its deque when weights change.

In `leg_loop(...)`:
- before reconnecting, sleep until `suppress_until_ms` if needed
- after a successful auth, call `restore_leg(leg_state)`
- in the exception handler, call `penalize_leg(leg_state)`

- [ ] **Step 4: Run the targeted tests to verify they pass**

Run:

```bash
python -m unittest client-agent.tests.test_uot_bond_runtime.UotBondRuntimeTests.test_penalize_leg_quarantines_after_three_failures -v
python -m unittest client-agent.tests.test_uot_bond_runtime -v
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add client-agent/leikwan_uot_bond.py client-agent/tests/test_uot_bond_runtime.py
git commit -m "feat: add udp uot client leg quarantine"
```

### Task 5: Make the sidecar accept weighted auth payloads without breaking old clients

**Files:**
- Modify: `sidecar-agent/leikwan_udp_uot_merge.py`
- Test: `sidecar-agent/tests/test_udp_uot_merge_runtime.py`

- [ ] **Step 1: Write the failing sidecar auth parse tests**

Add these tests in `sidecar-agent/tests/test_udp_uot_merge_runtime.py`:

```python
    def test_parse_leg_auth_payload_accepts_weighted_json_payload(self) -> None:
        payload = json.dumps(
            {
                "token": "udp-token",
                "endpoint_id": 184,
                "weight": 8,
            }
        ).encode("utf-8")
        parsed = merge_runtime.parse_leg_auth_payload(payload)
        self.assertEqual(parsed["token"], "udp-token")
        self.assertEqual(parsed["endpoint_id"], 184)
        self.assertEqual(parsed["weight"], 8)

    def test_parse_leg_auth_payload_accepts_legacy_plain_token(self) -> None:
        parsed = merge_runtime.parse_leg_auth_payload(b"legacy-token")
        self.assertEqual(parsed["token"], "legacy-token")
        self.assertEqual(parsed["endpoint_id"], 0)
        self.assertEqual(parsed["weight"], 1)
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
python -m unittest sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_parse_leg_auth_payload_accepts_weighted_json_payload sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_parse_leg_auth_payload_accepts_legacy_plain_token -v
```

Expected: FAIL because `parse_leg_auth_payload` does not exist.

- [ ] **Step 3: Implement backward-compatible auth parsing and weighted leg registration**

In `sidecar-agent/leikwan_udp_uot_merge.py`, add:

```python
def parse_leg_auth_payload(payload: bytes) -> dict[str, Any]:
    text = payload.decode("utf-8")
    try:
        decoded = json.loads(text)
    except json.JSONDecodeError:
        return {
            "token": text,
            "endpoint_id": 0,
            "weight": 1,
        }
    if not isinstance(decoded, dict):
        raise RuntimeError("auth payload must be an object")
    token = str(decoded.get("token", "") or "").strip()
    if token == "":
        raise RuntimeError("auth payload missing token")
    return {
        "token": token,
        "endpoint_id": int(decoded.get("endpoint_id", 0) or 0),
        "weight": max(1, int(decoded.get("weight", 1) or 1)),
    }
```

Extend `LegState`:

```python
@dataclasses.dataclass
class LegState:
    leg_id: int
    scope_key: object
    writer: asyncio.StreamWriter
    claims: dict[str, Any]
    weight: int = 1
    write_lock: asyncio.Lock = dataclasses.field(default_factory=asyncio.Lock)
```

Update `_register_leg(...)` so it inserts each leg into `scope_leg_order` according to its weight:

```python
    def _register_leg(self, leg_state: LegState) -> None:
        self.active_legs[leg_state.leg_id] = leg_state
        leg_order = self.scope_leg_order.setdefault(leg_state.scope_key, deque())
        if leg_state.leg_id not in leg_order:
            for _ in range(max(1, int(leg_state.weight or 1))):
                leg_order.append(leg_state.leg_id)
        self.scope_claims[leg_state.scope_key] = dict(leg_state.claims)
        self.stats["live_legs"] = len(self.active_legs)
```

In `handle_leg(...)`, replace the plain token decode with:

```python
auth_payload = parse_leg_auth_payload(first_frame.payload)
token = str(auth_payload["token"])
...
leg_state = LegState(
    leg_id=leg_id,
    scope_key=scope_key,
    writer=writer,
    claims=claims,
    weight=max(1, int(auth_payload.get("weight", 1) or 1)),
)
```

- [ ] **Step 4: Run the targeted tests to verify they pass**

Run:

```bash
python -m unittest sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_parse_leg_auth_payload_accepts_weighted_json_payload sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_parse_leg_auth_payload_accepts_legacy_plain_token -v
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add sidecar-agent/leikwan_udp_uot_merge.py sidecar-agent/tests/test_udp_uot_merge_runtime.py
git commit -m "feat: accept weighted udp uot auth payloads"
```

### Task 6: Add sidecar-side endpoint probation/quarantine for reverse UDP

**Files:**
- Modify: `sidecar-agent/leikwan_udp_uot_merge.py`
- Test: `sidecar-agent/tests/test_udp_uot_merge_runtime.py`

- [ ] **Step 1: Write the failing sidecar penalty tests**

Add these tests in `sidecar-agent/tests/test_udp_uot_merge_runtime.py`:

```python
    def test_weighted_scope_selection_prefers_higher_weight_leg(self) -> None:
        config = merge_runtime.RuntimeConfig(
            listen_host="127.0.0.1",
            listen_port=8999,
            token_secret="secret-123",
            allowed_forward_ids=[26],
            reorder_window_packets=8,
            reorder_window_bytes=4096,
            reorder_timeout_ms=25,
            stats_path="NUL",
        )
        runtime = merge_runtime.Runtime(config)
        scope = merge_runtime.build_scope_key(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
            }
        )
        runtime._register_leg(merge_runtime.LegState(leg_id=1, scope_key=scope, writer=mock.Mock(), claims={"forward_id": 26}, weight=8))
        runtime._register_leg(merge_runtime.LegState(leg_id=2, scope_key=scope, writer=mock.Mock(), claims={"forward_id": 26}, weight=2))
        picks = [runtime.pick_leg(scope).leg_id for _ in range(10)]
        self.assertEqual(picks.count(1), 8)
        self.assertEqual(picks.count(2), 2)

    def test_apply_endpoint_failure_quarantines_after_three_failures(self) -> None:
        config = merge_runtime.RuntimeConfig(
            listen_host="127.0.0.1",
            listen_port=8999,
            token_secret="secret-123",
            allowed_forward_ids=[26],
            reorder_window_packets=8,
            reorder_window_bytes=4096,
            reorder_timeout_ms=25,
            stats_path="NUL",
        )
        runtime = merge_runtime.Runtime(config)
        runtime.apply_endpoint_failure(184, now_ms=1000)
        runtime.apply_endpoint_failure(184, now_ms=1100)
        runtime.apply_endpoint_failure(184, now_ms=1200)
        penalty = runtime.endpoint_penalties[184]
        self.assertEqual(penalty["failures"], 3)
        self.assertGreater(penalty["suppress_until_ms"], 1200)
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
python -m unittest sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_weighted_scope_selection_prefers_higher_weight_leg sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_apply_endpoint_failure_quarantines_after_three_failures -v
```

Expected: FAIL because weighting and endpoint penalties do not exist yet.

- [ ] **Step 3: Implement reverse-path penalties**

In `sidecar-agent/leikwan_udp_uot_merge.py`, add constants and penalty state:

```python
LEG_PROBATION_AFTER_FAILURES = 2
LEG_QUARANTINE_AFTER_FAILURES = 3
LEG_PROBATION_SECONDS = 5.0
LEG_QUARANTINE_SECONDS = 30.0
```

In `Runtime.__init__(...)` add:

```python
self.endpoint_penalties: dict[int, dict[str, int]] = {}
```

Add methods:

```python
    def apply_endpoint_failure(self, endpoint_id: int, now_ms: int | None = None) -> None:
        if endpoint_id <= 0:
            return
        now_ms = int(time.time() * 1000) if now_ms is None else now_ms
        penalty = self.endpoint_penalties.setdefault(endpoint_id, {"failures": 0, "suppress_until_ms": 0})
        penalty["failures"] = int(penalty.get("failures", 0) or 0) + 1
        if penalty["failures"] >= LEG_QUARANTINE_AFTER_FAILURES:
            penalty["suppress_until_ms"] = now_ms + int(LEG_QUARANTINE_SECONDS * 1000)
        elif penalty["failures"] >= LEG_PROBATION_AFTER_FAILURES:
            penalty["suppress_until_ms"] = now_ms + int(LEG_PROBATION_SECONDS * 1000)
        else:
            penalty["suppress_until_ms"] = 0

    def clear_endpoint_failure(self, endpoint_id: int) -> None:
        if endpoint_id <= 0:
            return
        self.endpoint_penalties[endpoint_id] = {"failures": 0, "suppress_until_ms": 0}
```

In `handle_leg(...)`:
- read `endpoint_id` from `auth_payload`
- before registering the leg, reject it if it is still suppressed
- after a good auth, call `clear_endpoint_failure(endpoint_id)`
- on disconnect/error in the `finally` block, call `apply_endpoint_failure(endpoint_id)`
- if a leg is in probation/quarantine but not fully suppressed, keep its `weight` reduced:

```python
penalty = self.endpoint_penalties.get(endpoint_id, {})
if int(penalty.get("failures", 0) or 0) >= LEG_QUARANTINE_AFTER_FAILURES:
    leg_state.weight = 1
elif int(penalty.get("failures", 0) or 0) >= LEG_PROBATION_AFTER_FAILURES:
    leg_state.weight = max(1, leg_state.weight // 4)
```

- [ ] **Step 4: Run the targeted tests to verify they pass**

Run:

```bash
python -m unittest sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_weighted_scope_selection_prefers_higher_weight_leg sidecar-agent.tests.test_udp_uot_merge_runtime.UdpUotMergeRuntimeTests.test_apply_endpoint_failure_quarantines_after_three_failures -v
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add sidecar-agent/leikwan_udp_uot_merge.py sidecar-agent/tests/test_udp_uot_merge_runtime.py
git commit -m "feat: add udp uot sidecar leg quarantine"
```

### Task 7: Full local verification across bond TCP and UDP UoT

**Files:**
- Modify: none
- Test: existing Rust and Python test files above

- [ ] **Step 1: Run the focused Rust bond-core suite**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-bond interactive_tcp_skips_unhealthy_preferred_leg -- --exact
cargo test -p leikwan-bond interactive_tcp_keeps_healthy_preferred_leg -- --exact
cargo test -p leikwan-bond toxic_tcp_leg_is_downweighted_before_pool_collapses -- --exact
cargo test -p leikwan-bond toxic_udp_leg_is_downweighted_before_pool_collapses -- --exact
cargo test -p leikwan-bond leg_health_state_transitions_downward_and_upward -- --exact
cargo test -p leikwan-bond local_snapshot_serialization_shape_includes_protocol_health_and_events -- --exact
cd ..
```

Expected: PASS

- [ ] **Step 2: Run the focused Python UDP suites**

Run:

```bash
python -m unittest client-agent.tests.test_uot_bond_runtime sidecar-agent.tests.test_udp_uot_merge_runtime client-agent.tests.test_mptcp_bond_regressions -v
```

Expected: all UDP-related tests pass

- [ ] **Step 3: Run compile/build verification**

Run:

```bash
cd wstunnel-main
cargo build --release -p leikwan-bond
cd ..
python -m py_compile client-agent/install_forward_runtime.py client-agent/leikwan_uot_bond.py client-agent/tests/test_uot_bond_runtime.py sidecar-agent/leikwan_udp_uot_merge.py sidecar-agent/tests/test_udp_uot_merge_runtime.py client-agent/tests/test_mptcp_bond_regressions.py
```

Expected:
- `cargo build --release -p leikwan-bond` succeeds and produces `wstunnel-main/target/release/leikwan-bond`
- `py_compile` prints nothing

- [ ] **Step 4: Commit the verification gate**

```bash
git add wstunnel-main/leikwan-bond/src/session.rs client-agent/install_forward_runtime.py client-agent/leikwan_uot_bond.py sidecar-agent/leikwan_udp_uot_merge.py client-agent/tests/test_mptcp_bond_regressions.py client-agent/tests/test_uot_bond_runtime.py sidecar-agent/tests/test_udp_uot_merge_runtime.py
git commit -m "test: verify bond tcp and udp bad-leg health handling"
```

### Task 8: Roll out safely, bond core first, then UDP UoT

**Files:**
- Modify: remote live copies only
- Test: live `iperf3`, runtime snapshots, journals

> **Remote access rule:** run all SSH/SCP commands from WSL, and load the live host/jump values from the local-only credential files (`leikwan-v3.txt`, `cantons.txt`, `shanghais.txt`) into shell vars before starting. Use `$PANEL_HOST`, `$CLIENT_HOST`, `$SLAVE_HOP`, and `$SIDECAR_HOST` in the commands below. Do not paste secrets into repo files.

- [ ] **Step 1: Back up the live binaries and runtime files before rollout**

Run:

```bash
ssh "$PANEL_HOST" "mkdir -p /root/codex-backups/tcp-udp-bad-leg-health/panel && cp /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-bond_linux_amd64 /root/codex-backups/tcp-udp-bad-leg-health/panel/ && cp /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py /root/codex-backups/tcp-udp-bad-leg-health/panel/ && cp /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/leikwan_uot_bond.py /root/codex-backups/tcp-udp-bad-leg-health/panel/ && cp /www/wwwroot/v3.leikwanhost.com/leikwan-v3/sidecar-agent/leikwan_udp_uot_merge.py /root/codex-backups/tcp-udp-bad-leg-health/panel/"

ssh "$CLIENT_HOST" "mkdir -p /root/codex-backups/tcp-udp-bad-leg-health/client && cp /usr/local/bin/leikwan-bond /root/codex-backups/tcp-udp-bad-leg-health/client/ && cp /usr/local/bin/install_forward_runtime.py /root/codex-backups/tcp-udp-bad-leg-health/client/ && cp /usr/local/bin/leikwan-uot-bond.py /root/codex-backups/tcp-udp-bad-leg-health/client/ && cp /etc/leikwan/uot-bond-forward-26.json /root/codex-backups/tcp-udp-bad-leg-health/client/"

ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "mkdir -p /root/codex-backups/tcp-udp-bad-leg-health/sidecar && cp /usr/local/bin/leikwan-bond /root/codex-backups/tcp-udp-bad-leg-health/sidecar/ && cp /usr/local/bin/leikwan-udp-uot-merge.py /root/codex-backups/tcp-udp-bad-leg-health/sidecar/ && cp /var/lib/leikwan-sidecar-4/udp-uot-runtime.json /root/codex-backups/tcp-udp-bad-leg-health/sidecar/"
```

Expected: backup dirs exist on panel, client, and sidecar.

- [ ] **Step 2: Publish the new bond binary and deploy it to the sidecar first**

Run:

```bash
scp wstunnel-main/target/release/leikwan-bond "$PANEL_HOST:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-bond_linux_amd64"
scp -o ProxyJump="$SLAVE_HOP" wstunnel-main/target/release/leikwan-bond "$SIDECAR_HOST:/usr/local/bin/leikwan-bond"
ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "chmod 755 /usr/local/bin/leikwan-bond && systemctl restart leikwan-bond-sidecar-4.service && sleep 3 && systemctl is-active leikwan-bond-sidecar-4.service"
```

Expected: `active`

- [ ] **Step 3: Deploy the new bond binary to the client second**

Run:

```bash
scp wstunnel-main/target/release/leikwan-bond "$CLIENT_HOST:/usr/local/bin/leikwan-bond"
ssh "$CLIENT_HOST" "chmod 755 /usr/local/bin/leikwan-bond && systemctl restart leikwan-client-26.service && sleep 3 && systemctl is-active leikwan-client-26.service"
```

Expected: `active`

- [ ] **Step 4: Only after TCP verification starts looking good, deploy the UDP UoT Python changes**

Run:

```bash
scp client-agent/install_forward_runtime.py "$PANEL_HOST:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py"
scp client-agent/leikwan_uot_bond.py "$PANEL_HOST:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/leikwan_uot_bond.py"
scp sidecar-agent/leikwan_udp_uot_merge.py "$PANEL_HOST:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/sidecar-agent/leikwan_udp_uot_merge.py"

scp client-agent/install_forward_runtime.py "$CLIENT_HOST:/usr/local/bin/install_forward_runtime.py"
scp client-agent/leikwan_uot_bond.py "$CLIENT_HOST:/usr/local/bin/leikwan-uot-bond.py"
ssh "$CLIENT_HOST" "systemctl restart leikwan-client-agent.service && sleep 6 && systemctl restart leikwan-client-26-udp-uot.service && sleep 2 && systemctl is-active leikwan-client-agent.service && systemctl is-active leikwan-client-26-udp-uot.service"

scp -o ProxyJump="$SLAVE_HOP" sidecar-agent/leikwan_udp_uot_merge.py "$SIDECAR_HOST:/usr/local/bin/leikwan-udp-uot-merge.py"
ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "systemctl restart leikwan-bond-sidecar-4-udp-uot.service && sleep 2 && systemctl is-active leikwan-bond-sidecar-4-udp-uot.service"
```

Expected:

```text
active
active
active
```

- [ ] **Step 5: Verify the client is consuming the new UDP leg health metadata**

Run:

```bash
ssh "$CLIENT_HOST" "cat /etc/leikwan/uot-bond-forward-26.json"
```

Expected:
- each leg includes `dynamic_priority`
- each leg includes `health_role`
- each leg includes `weight`

### Task 9: Live verification and rollback rule

**Files:**
- Modify: none
- Test: live runtime only

- [ ] **Step 1: Verify reverse TCP no longer lets one toxic leg dominate**

Run:

```bash
ssh "$CLIENT_HOST" "timeout 15 iperf3 -c 127.0.0.1 -p 11024 -R -b 400M -t 10"
ssh "$CLIENT_HOST" "cat /run/leikwan/bond-leg-health-forward-26.json"
ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "cat /var/lib/leikwan-sidecar-4/bond-leg-health.json && journalctl -u leikwan-bond-sidecar-4.service -n 120 --no-pager"
```

Expected:
- reverse TCP throughput lands materially closer to the known good-4-leg range than the `16-22 Mbps` toxic-leg collapse
- the toxic leg shows reduced `tcp.effective_weight` or a non-healthy `tcp.state`
- the pool stays multi-leg instead of effectively pinning the session to the bad leg

- [ ] **Step 2: Verify low-rate reverse UDP still works**

Run:

```bash
ssh "$CLIENT_HOST" "timeout 12 iperf3 -c 127.0.0.1 -p 11024 -b 10M -R -u -t 5"
```

Expected:
- command completes
- loss is not worse than the current best-v10 baseline

- [ ] **Step 3: Verify harsh reverse UDP behavior improved**

Run:

```bash
ssh "$CLIENT_HOST" "timeout 15 iperf3 -c 127.0.0.1 -p 11024 -b 300M -R -u -t 10"
ssh "$CLIENT_HOST" "cat /run/leikwan/uot-bond-forward-26.stats.json"
ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "cat /var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json && journalctl -u leikwan-bond-sidecar-4-udp-uot.service -n 120 --no-pager"
```

Expected:
- `datagrams_dropped_gap` falls materially relative to the current best-v10 bad run
- repeated toxic legs disappear or re-enter as lower-weight legs
- reverse UDP no longer collapses toward near-single-digit Mbps just because one or two legs are bad

- [ ] **Step 4: Verify sidecar restart auto-recovery still works**

Run:

```bash
ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "systemctl restart leikwan-bond-sidecar-4-udp-uot.service"
ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "for i in $(seq 1 12); do cat /var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json; sleep 1; done"
```

Expected:
- sidecar returns to `live_legs = 5` automatically
- client does not need a manual UoT bounce to heal

- [ ] **Step 5: Roll back immediately if either TCP or UDP regresses**

Run this only if the new rollout is worse than the known good baseline:

```bash
ssh "$PANEL_HOST" "cp /root/codex-backups/tcp-udp-bad-leg-health/panel/leikwan-bond_linux_amd64 /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-bond_linux_amd64 && cp /root/codex-backups/tcp-udp-bad-leg-health/panel/install_forward_runtime.py /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py && cp /root/codex-backups/tcp-udp-bad-leg-health/panel/leikwan_uot_bond.py /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/leikwan_uot_bond.py && cp /root/codex-backups/tcp-udp-bad-leg-health/panel/leikwan_udp_uot_merge.py /www/wwwroot/v3.leikwanhost.com/leikwan-v3/sidecar-agent/leikwan_udp_uot_merge.py"

ssh "$CLIENT_HOST" "cp /root/codex-backups/tcp-udp-bad-leg-health/client/leikwan-bond /usr/local/bin/leikwan-bond && cp /root/codex-backups/tcp-udp-bad-leg-health/client/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py && cp /root/codex-backups/tcp-udp-bad-leg-health/client/leikwan-uot-bond.py /usr/local/bin/leikwan-uot-bond.py && cp /root/codex-backups/tcp-udp-bad-leg-health/client/uot-bond-forward-26.json /etc/leikwan/uot-bond-forward-26.json && systemctl restart leikwan-client-26.service && systemctl restart leikwan-client-26-udp-uot.service"

ssh -J "$SLAVE_HOP" "$SIDECAR_HOST" "cp /root/codex-backups/tcp-udp-bad-leg-health/sidecar/leikwan-bond /usr/local/bin/leikwan-bond && cp /root/codex-backups/tcp-udp-bad-leg-health/sidecar/leikwan-udp-uot-merge.py /usr/local/bin/leikwan-udp-uot-merge.py && cp /root/codex-backups/tcp-udp-bad-leg-health/sidecar/udp-uot-runtime.json /var/lib/leikwan-sidecar-4/udp-uot-runtime.json && systemctl restart leikwan-bond-sidecar-4.service && systemctl restart leikwan-bond-sidecar-4-udp-uot.service"
```

Expected: service state returns to the pre-rollout baseline.

### Task 10: Update operator docs after behavior changes

**Files:**
- Modify: `docs/BOND_OPERATIONS.md`

- [ ] **Step 1: Document the new TCP + UDP bad-leg behavior**

Add a short section like this under the `forward 26` operations area in `docs/BOND_OPERATIONS.md`:

```markdown
### Forward 26 bad-leg health

`forward 26` now has two layers of bad-leg protection:

1. `leikwan-bond` TCP session scheduling no longer keeps sticky/preferred-leg behavior on a leg once that leg has been downgraded by runtime health.
2. UDP UoT bootstrap now inherits client-agent bond endpoint health as per-leg `dynamic_priority`, `health_role`, and `weight`, then the client and sidecar UoT runtimes apply temporary probation/quarantine on repeated runtime failures.

Rollout order:
- deploy sidecar `leikwan-bond` first
- deploy client `leikwan-bond` second
- verify reverse TCP health snapshots and throughput
- only then deploy `install_forward_runtime.py`, `leikwan_uot_bond.py`, and `leikwan_udp_uot_merge.py`

Rollback rule:
- if reverse TCP falls back toward the toxic-leg collapse, roll back the bond binary first
- if TCP looks good but UDP regresses, roll back only the UDP UoT Python pieces
```

- [ ] **Step 2: Commit**

```bash
git add docs/BOND_OPERATIONS.md
git commit -m "docs: describe tcp and udp bad-leg health rollout"
```

## Self-Review

- Spec coverage: this plan now covers the real dependency chain, TCP bond core first, then UDP UoT parity work.
- Placeholder scan: no TODO/TBD placeholders remain.
- Type consistency: the same field names are used throughout: `dynamic_priority`, `health_role`, `weight`, `endpoint_id`, `token`, `effective_weight`, `preferred_leg_id`.

Plan complete and saved to `docs/superpowers/plans/2026-04-20-udp-uot-bad-leg-health.md`. Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration

2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
