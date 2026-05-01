# Bond Multi-Slave Aggregation And Node Health Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve single-flow bond throughput by letting additional slaves join earlier in a controlled warm-up stage, while automatically de-prioritizing or quarantining bad slave nodes instead of repeatedly feeding them fresh traffic.

**Architecture:** Keep the current `client -> slave -> sidecar` topology and the async `wstunnel-main/leikwan-bond/src/session.rs` transport. Add a two-level policy: a Rust-side stream scheduler with `sticky -> warmup -> bulk` striping stages plus fast per-leg cooldowns, and a client-agent-side persistent endpoint health store that marks slaves as `healthy`, `probation`, or `quarantined` when rendering bond legs.

**Tech Stack:** Python client agent/runtime renderer, Rust Tokio bond transport, existing bond CLI flags and client health probes

---

## Scope Guardrails

- Do not replace `leikwan-bond` with a new transport.
- Do not change topology away from `client -> slave -> sidecar`.
- Do not require panel schema changes in the first pass.
- Do not block all traffic when every slave looks bad; always keep a least-bad fallback path.
- Keep UDP semantics unchanged in the first pass; focus this plan on TCP aggregation and endpoint health policy.

## Default Policy

- `warmup_trigger_bytes = 65536`
- `warmup_slice_bytes = 65536`
- `warmup_leg_delay_ms = 80`
- `bulk_promote_bytes = 262144`
- `leg_cooldown_ms = 3000`
- Client-agent health policy:
  - 2 consecutive probe failures -> `probation`
  - 3 consecutive probe failures -> `quarantined` for 90 seconds
  - first post-quarantine success -> `probation`
  - 3 consecutive successes -> `healthy`

## File Map

- Create: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `wstunnel-main/leikwan-bond/src/main.rs`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`

### Task 1: Lock Down Client-Agent Bond Endpoint Health Policy

**Files:**
- Create: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Modify: `client-agent/client_agent.py`

- [ ] **Step 1: Write failing client-agent health tests**

Create `client-agent/tests/test_client_agent_bond_endpoint_health.py` with:

```python
import json
import pathlib
import tempfile
import unittest

from test_client_agent_mptcp_health_grace import load_module


client_agent_module = load_module(
    "client_agent_bond_endpoint_health_under_test",
    "client-agent/client_agent.py",
)


class ClientAgentBondEndpointHealthTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "client_id": 123,
                    "agent_token": "token",
                    "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                }
            ),
            encoding="utf-8",
        )
        return client_agent_module.ClientAgent(config_path)

    def test_bond_endpoint_becomes_quarantined_after_three_failures(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            for _ in range(3):
                agent.record_bond_endpoint_probe_result(26, 184, False, None, "timeout", now=100.0)
            entry = agent.load_bond_endpoint_health()["26"]["184"]
            self.assertEqual(entry["role"], "quarantined")
            self.assertGreater(entry["quarantine_until"], 100.0)

    def test_bond_endpoint_reenters_as_probation_before_healthy(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            health = {
                "26": {
                    "184": {
                        "role": "quarantined",
                        "consecutive_failures": 3,
                        "consecutive_successes": 0,
                        "quarantine_until": 50.0,
                        "probation_until": 0.0,
                        "last_error": "timeout",
                    }
                }
            }
            agent.save_bond_endpoint_health(health)
            agent.record_bond_endpoint_probe_result(26, 184, True, 12.0, None, now=60.0)
            entry = agent.load_bond_endpoint_health()["26"]["184"]
            self.assertEqual(entry["role"], "probation")
            self.assertGreater(entry["probation_until"], 60.0)

    def test_select_bond_runtime_endpoints_omits_quarantined_nodes_but_keeps_best_bad_fallback(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "26": {
                        "184": {"role": "quarantined", "quarantine_until": 200.0, "consecutive_failures": 5, "consecutive_successes": 0},
                        "185": {"role": "probation", "probation_until": 200.0, "consecutive_failures": 2, "consecutive_successes": 0},
                        "186": {"role": "healthy", "quarantine_until": 0.0, "probation_until": 0.0, "consecutive_failures": 0, "consecutive_successes": 4},
                    }
                }
            )
            selected = agent.select_bond_runtime_endpoints(
                {
                    "forward_id": 26,
                    "mode": "bond",
                    "endpoints": [
                        {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                        {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                        {"endpoint_id": 186, "host": "8.163.87.42", "port": 7001},
                    ],
                    "bonding": {"ws_priority": 10},
                },
                now=100.0,
            )
            self.assertEqual([item["endpoint_id"] for item in selected], [186, 185])
            self.assertEqual(selected[0]["health_role"], "healthy")
            self.assertEqual(selected[1]["health_role"], "probation")
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
```

Expected:

- missing `load_bond_endpoint_health`
- missing `save_bond_endpoint_health`
- missing `record_bond_endpoint_probe_result`
- missing `select_bond_runtime_endpoints`

- [ ] **Step 3: Implement persistent endpoint health helpers**

In `client-agent/client_agent.py`, add:

```python
    def bond_endpoint_health_path(self) -> pathlib.Path:
        return self.state_dir / "bond-endpoint-health.json"

    def load_bond_endpoint_health(self) -> dict:
        path = self.bond_endpoint_health_path()
        if not path.exists():
            return {}
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}

    def save_bond_endpoint_health(self, payload: dict) -> None:
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.write_text_if_changed(self.bond_endpoint_health_path(), json.dumps(payload, indent=2, sort_keys=True) + "\n")
```

Add a state update helper:

```python
    def record_bond_endpoint_probe_result(
        self,
        forward_id: int,
        endpoint_id: int,
        ok: bool,
        latency_ms: float | None,
        error: str | None,
        now: float | None = None,
    ) -> dict:
        now = time.time() if now is None else now
        health = self.load_bond_endpoint_health()
        entry = health.setdefault(str(forward_id), {}).setdefault(
            str(endpoint_id),
            {
                "role": "healthy",
                "consecutive_failures": 0,
                "consecutive_successes": 0,
                "quarantine_until": 0.0,
                "probation_until": 0.0,
                "last_error": "",
                "last_latency_ms": None,
            },
        )
        if ok:
            entry["consecutive_successes"] = int(entry.get("consecutive_successes", 0) or 0) + 1
            entry["consecutive_failures"] = 0
            entry["last_error"] = ""
            entry["last_latency_ms"] = latency_ms
            if float(entry.get("quarantine_until", 0.0) or 0.0) > 0:
                entry["role"] = "probation"
                entry["probation_until"] = max(float(entry.get("probation_until", 0.0) or 0.0), now + 60.0)
                entry["quarantine_until"] = 0.0
            elif int(entry["consecutive_successes"]) >= 3:
                entry["role"] = "healthy"
                entry["probation_until"] = 0.0
        else:
            entry["consecutive_failures"] = int(entry.get("consecutive_failures", 0) or 0) + 1
            entry["consecutive_successes"] = 0
            entry["last_error"] = error or "probe_failed"
            if int(entry["consecutive_failures"]) >= 3:
                entry["role"] = "quarantined"
                entry["quarantine_until"] = now + 90.0
                entry["probation_until"] = 0.0
            elif int(entry["consecutive_failures"]) >= 2:
                entry["role"] = "probation"
                entry["probation_until"] = max(float(entry.get("probation_until", 0.0) or 0.0), now + 60.0)
        self.save_bond_endpoint_health(health)
        return entry
```

- [ ] **Step 4: Implement endpoint selection with fallback**

In `client-agent/client_agent.py`, add:

```python
    def select_bond_runtime_endpoints(self, forward: dict, now: float | None = None) -> list[dict]:
        now = time.time() if now is None else now
        endpoints = [dict(endpoint) for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        bonding = forward.get("bonding", {}) if isinstance(forward.get("bonding", {}), dict) else {}
        base_priority = int(bonding.get("ws_priority", 10) or 10)
        health = self.load_bond_endpoint_health().get(str(int(forward.get("forward_id", 0) or 0)), {})

        decorated = []
        for endpoint in endpoints:
            endpoint_id = str(int(endpoint.get("endpoint_id", 0) or 0))
            entry = dict(health.get(endpoint_id, {}))
            role = str(entry.get("role", "healthy") or "healthy")
            quarantine_until = float(entry.get("quarantine_until", 0.0) or 0.0)
            probation_until = float(entry.get("probation_until", 0.0) or 0.0)
            if role == "quarantined" and quarantine_until <= now:
                role = "probation"
            if role == "probation" and probation_until <= now and int(entry.get("consecutive_successes", 0) or 0) >= 3:
                role = "healthy"

            endpoint["health_role"] = role
            if role == "healthy":
                endpoint["dynamic_priority"] = base_priority
            elif role == "probation":
                endpoint["dynamic_priority"] = base_priority + 15
            else:
                endpoint["dynamic_priority"] = base_priority + 30
            decorated.append(endpoint)

        preferred = [item for item in decorated if item["health_role"] != "quarantined"]
        if preferred:
            return preferred

        decorated.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
        return decorated[:1]
```

- [ ] **Step 5: Re-run the tests and verify GREEN**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
python -m py_compile client-agent/client_agent.py
```

Expected: all pass.

### Task 2: Render Bond Legs From Health Roles Instead Of Raw Endpoint Order

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `client-agent/client_agent.py`

- [ ] **Step 1: Add failing runtime-render tests**

Extend `client-agent/tests/test_mptcp_bond_regressions.py` with:

```python
    def test_bond_runtime_uses_endpoint_dynamic_priority(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "transport": "tcp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "dynamic_priority": 10},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "dynamic_priority": 25},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "join_timeout_ms": 400,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {"bond_bin": "/usr/local/bin/leikwan-bond", "bond_ingress_bin": "/usr/local/bin/leikwan-ingress"},
        )
        script = rendered["start_script"]
        self.assertIn("tcp://127.0.0.1:20962?priority=10", script)
        self.assertIn("tcp://127.0.0.1:20963?priority=25", script)

    def test_bond_runtime_renders_new_scheduler_flags(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "transport": "tcp",
                "mode": "bond",
                "endpoints": [{"host": "8.163.90.132", "port": 7001, "dynamic_priority": 10}],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "warmup_trigger_bytes": 65536,
                    "warmup_slice_bytes": 65536,
                    "warmup_leg_delay_ms": 80,
                    "bulk_promote_bytes": 262144,
                    "leg_cooldown_ms": 3000,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {"bond_bin": "/usr/local/bin/leikwan-bond", "bond_ingress_bin": "/usr/local/bin/leikwan-ingress"},
        )
        script = rendered["start_script"]
        self.assertIn("--warmup-trigger-bytes 65536", script)
        self.assertIn("--warmup-slice-bytes 65536", script)
        self.assertIn("--warmup-leg-delay-ms 80", script)
        self.assertIn("--bulk-promote-bytes 262144", script)
        self.assertIn("--leg-cooldown-ms 3000", script)
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_runtime_uses_endpoint_dynamic_priority
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_runtime_renders_new_scheduler_flags
```

Expected:

- dynamic priority is ignored
- scheduler flags are missing

- [ ] **Step 3: Apply endpoint selection before rendering**

In `client-agent/client_agent.py`, inside `apply_forward(...)`, add:

```python
        render_forward = dict(forward)
        if mode == "bond":
            render_forward["endpoints"] = self.select_bond_runtime_endpoints(render_forward)
        runtime = install_forward_runtime.render_forward_runtime(render_forward, agent_config)
```

- [ ] **Step 4: Teach the runtime renderer to honor dynamic priority and new defaults**

In `client-agent/install_forward_runtime.py`, inside bond mode, add:

```python
        warmup_trigger_bytes = int_or_default(bond.get("warmup_trigger_bytes"), 65536)
        warmup_slice_bytes = int_or_default(bond.get("warmup_slice_bytes"), 65536)
        warmup_leg_delay_ms = int_or_default(bond.get("warmup_leg_delay_ms"), 80)
        bulk_promote_bytes = int_or_default(bond.get("bulk_promote_bytes"), 262144)
        leg_cooldown_ms = int_or_default(bond.get("leg_cooldown_ms"), 3000)
```

Use per-endpoint priority if present:

```python
                endpoint_priority = int_or_default(endpoint.get("dynamic_priority"), ws_priority)
                if use_wss_as_active_legs or not standby_wss_enabled(bond):
                    tcp_leg_targets.append(render_tcp_leg(f"127.0.0.1:{tcp_back}", endpoint_priority))
```

Append new flags to the bond command:

```python
                f"--warmup-trigger-bytes {warmup_trigger_bytes} "
                f"--warmup-slice-bytes {warmup_slice_bytes} "
                f"--warmup-leg-delay-ms {warmup_leg_delay_ms} "
                f"--bulk-promote-bytes {bulk_promote_bytes} "
                f"--leg-cooldown-ms {leg_cooldown_ms} &"
```

- [ ] **Step 5: Re-run the tests and verify GREEN**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_runtime_uses_endpoint_dynamic_priority
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_runtime_renders_new_scheduler_flags
python -m py_compile client-agent/install_forward_runtime.py client-agent/client_agent.py
```

Expected: all pass.

### Task 3: Lock The New Rust Scheduler Shape With Unit Tests

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`

- [ ] **Step 1: Add failing unit tests for striping stages and cooldown**

At the bottom of `wstunnel-main/leikwan-bond/src/session.rs`, add:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    fn fake_leg(id: usize, priority: u16, inflight_bytes: usize, smoothed_rtt_ms: u64) -> LegState {
        let (tx, _rx) = mpsc::unbounded_channel();
        LegState {
            id,
            sender: tx,
            active: true,
            inflight_bytes,
            transport: LegTransport::Tcp,
            priority,
            smoothed_rtt: Duration::from_millis(smoothed_rtt_ms),
            label: format!("leg-{id}"),
            spec_index: Some(id),
            cooldown_until: None,
            penalty_count: 0,
        }
    }

    #[test]
    fn sticky_stage_keeps_one_leg_for_small_streams() {
        assert_eq!(
            decide_striping_stage(16 * 1024, 0, 1, 3, &SessionConfig::test_defaults()),
            TcpStripingStage::Sticky
        );
    }

    #[test]
    fn warmup_stage_starts_before_full_bulk_threshold() {
        assert_eq!(
            decide_striping_stage(64 * 1024, 0, 1, 3, &SessionConfig::test_defaults()),
            TcpStripingStage::Warmup
        );
    }

    #[test]
    fn bulk_stage_allows_all_healthy_legs_for_large_streams() {
        assert_eq!(
            allowed_leg_count(TcpStripingStage::Bulk, 5),
            5
        );
    }

    #[test]
    fn cooled_down_leg_is_skipped_for_new_data_when_alternatives_exist() {
        let now = Instant::now();
        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        legs[0].cooldown_until = Some(now + Duration::from_secs(2));
        let mut cursor = 0usize;
        let selected = select_leg_for_data_with_cooldown(
            &legs,
            &mut cursor,
            64 * 1024,
            0,
            &SessionConfig::test_defaults(),
            now,
        );
        assert_eq!(selected, Some(1));
    }
}
```

- [ ] **Step 2: Run the crate tests and verify RED**

Run:

```bash
cargo test -p leikwan-bond
```

Expected:

- new helpers and fields do not exist yet

- [ ] **Step 3: Add stage and cooldown primitives**

In `wstunnel-main/leikwan-bond/src/session.rs`, add:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum TcpStripingStage {
    Sticky,
    Warmup,
    Bulk,
}
```

Extend `SessionConfig`:

```rust
    pub warmup_trigger_bytes: usize,
    pub warmup_slice_bytes: usize,
    pub warmup_leg_delay: Duration,
    pub bulk_promote_bytes: usize,
    pub leg_cooldown: Duration,
```

Extend `LegState`:

```rust
    cooldown_until: Option<Instant>,
    penalty_count: u8,
```

Add pure helpers:

```rust
fn decide_striping_stage(
    sent_bytes: usize,
    backlog_bytes: usize,
    warmup_used_legs: usize,
    active_leg_count: usize,
    config: &SessionConfig,
) -> TcpStripingStage {
    if active_leg_count < 2 {
        return TcpStripingStage::Sticky;
    }
    let total_bytes = sent_bytes.saturating_add(backlog_bytes);
    if total_bytes >= config.bulk_promote_bytes {
        return TcpStripingStage::Bulk;
    }
    if total_bytes >= config.warmup_trigger_bytes || warmup_used_legs >= 2 {
        return TcpStripingStage::Warmup;
    }
    TcpStripingStage::Sticky
}

fn allowed_leg_count(stage: TcpStripingStage, healthy_leg_count: usize) -> usize {
    match stage {
        TcpStripingStage::Sticky => healthy_leg_count.min(1),
        TcpStripingStage::Warmup => healthy_leg_count.min(2),
        TcpStripingStage::Bulk => healthy_leg_count.max(1),
    }
}

fn leg_available_for_new_data(leg: &LegState, now: Instant) -> bool {
    if !leg.active {
        return false;
    }
    !leg.cooldown_until.is_some_and(|deadline| deadline > now)
}
```

- [ ] **Step 4: Re-run the crate tests and verify GREEN**

Run:

```bash
cargo test -p leikwan-bond
```

Expected: tests pass.

### Task 4: Implement `sticky -> warmup -> bulk` Scheduling And Fast Leg Cooldown

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/main.rs`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`

- [ ] **Step 1: Add CLI flags and thread them into `SessionConfig`**

In `wstunnel-main/leikwan-bond/src/main.rs`, add CLI arguments:

```rust
        #[arg(long, default_value_t = 65536)]
        warmup_trigger_bytes: usize,
        #[arg(long, default_value_t = 65536)]
        warmup_slice_bytes: usize,
        #[arg(long, default_value_t = 80)]
        warmup_leg_delay_ms: u64,
        #[arg(long, default_value_t = 262144)]
        bulk_promote_bytes: usize,
        #[arg(long, default_value_t = 3000)]
        leg_cooldown_ms: u64,
```

and populate:

```rust
                    warmup_trigger_bytes,
                    warmup_slice_bytes,
                    warmup_leg_delay: Duration::from_millis(warmup_leg_delay_ms),
                    bulk_promote_bytes,
                    leg_cooldown: Duration::from_millis(leg_cooldown_ms),
```

- [ ] **Step 2: Track striping state per stream**

In `TcpStreamState`, add:

```rust
    striping_stage: TcpStripingStage,
    warmup_leg_count: usize,
    warmup_stage_started_at: Option<Instant>,
    warmup_leg_cursor: usize,
    warmup_leg_bytes: usize,
```

Initialize:

```rust
            striping_stage: TcpStripingStage::Sticky,
            warmup_leg_count: 1,
            warmup_stage_started_at: None,
            warmup_leg_cursor: 0,
            warmup_leg_bytes: 0,
```

- [ ] **Step 3: Replace binary `bulk_mode` promotion with stage transitions**

In `flush_tcp_stream_backlogs(...)`, replace the current one-shot `bulk_mode` promotion with:

```rust
            let backlog_bytes = state.local_backlog.iter().map(|chunk| chunk.len()).sum::<usize>() + payload.len();
            state.striping_stage = decide_striping_stage(
                state.outbound_next_offset as usize,
                backlog_bytes,
                state.warmup_leg_count,
                legs.iter().filter(|leg| leg.active).count(),
                config,
            );
```

Then cap the eligible legs by stage:

```rust
            let now = Instant::now();
            let eligible_leg_limit = allowed_leg_count(
                state.striping_stage,
                legs.iter().filter(|leg| leg_available_for_new_data(leg, now)).count(),
            );
```

Warmup policy:

- `Sticky`: 1 leg
- `Warmup`: 2 legs, rotating every `warmup_slice_bytes` or after `warmup_leg_delay`
- `Bulk`: all healthy legs

- [ ] **Step 4: Penalize bad legs without collapsing the stream**

In the `LegClosed` branch and retransmit-heavy paths:

```rust
fn apply_leg_cooldown(legs: &mut [LegState], leg_id: usize, cooldown: Duration) {
    if let Some(leg) = legs.get_mut(leg_id) {
        leg.cooldown_until = Some(Instant::now() + cooldown);
        leg.penalty_count = leg.penalty_count.saturating_add(1);
    }
}
```

Call it from:

- `TcpTransportEvent::LegClosed`
- retransmit path when `chunk.transmit_count >= 3`

Do **not** use cooldown for control frames unless there is an alternative; ACK/FIN/RESET still need a path out.

- [ ] **Step 5: Re-run the Rust tests and verify GREEN**

Run:

```bash
cargo test -p leikwan-bond
cargo build --release -p leikwan-bond
```

Expected: pass.

### Task 5: Surface Health Decisions In Diagnostics And Verify End To End

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`

- [ ] **Step 1: Add client diagnostic output for endpoint role**

In `client-agent/client_agent.py`, extend endpoint diagnostics payload with:

```python
                        endpoint_results.append({
                            "endpoint_id": int(endpoint.get("endpoint_id", 0)),
                            "host": host,
                            "port": port,
                            "reachable": reachable,
                            "latency_ms": latency_ms,
                            "error": err,
                            "health_role": str(endpoint.get("health_role", "unknown")),
                            "dynamic_priority": int(endpoint.get("dynamic_priority", 0) or 0),
                            "segment": "client_slave",
                        })
```

- [ ] **Step 2: Add Rust logs for stage changes and cooldown**

In `wstunnel-main/leikwan-bond/src/session.rs`, log:

```rust
info!(
    "bond tcp stream {} stage {:?} active_legs={} eligible_legs={}",
    stream_id,
    state.striping_stage,
    legs.iter().filter(|leg| leg.active).count(),
    legs.iter().filter(|leg| leg_available_for_new_data(leg, Instant::now())).count(),
);
```

and:

```rust
warn!("bond leg {} entering cooldown for {:?}", leg.label, config.leg_cooldown);
```

- [ ] **Step 3: Verify targeted Python tests**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
python -m unittest client-agent.tests.test_mptcp_bond_regressions
```

Expected: pass.

- [ ] **Step 4: Verify targeted Rust tests**

Run:

```bash
cargo test -p leikwan-bond
```

Expected: pass.

- [ ] **Step 5: Live validation on forward `26`**

On the client:

```bash
sed -n '1,260p' /usr/local/bin/leikwan-client-start-26.sh
timeout 20s iperf3 -c 127.0.0.1 -p 11024 -R -b 500M
journalctl -u leikwan-client-26 --no-pager -n 200 --since '10 min ago'
```

Success criteria:

- the rendered start script shows endpoint-specific priorities
- warmup / bulk flags are present on the bond process
- logs show stage transitions instead of staying sticky for the whole stream
- recently bad legs enter cooldown instead of immediately attracting fresh data again
- throughput is at least no worse than the current baseline, with fewer hard resets caused by one bad slave

## Follow-Up Notes

- If the first pass works, the next optional step is to move the new defaults into `ProvisioningService.php` / `.env` so tuning can be centralized.
- If probe-only health is too weak, add a later phase that feeds Rust-side retransmit / cooldown counters back into the client-agent health store.
