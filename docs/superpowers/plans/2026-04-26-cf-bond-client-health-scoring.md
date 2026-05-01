# CF Bond Client Health Scoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `bond + cf_mode` forwards reuse the normal client-side bad-leg health policy on the next render/restart, so bad CF slave legs are deprioritized or omitted without changing slave-side CF IP selection.

**Architecture:** Keep the change entirely in `client-agent/client_agent.py`. Read the local bond runtime snapshot for CF bond forwards, project its TCP leg state into the existing endpoint health cache, then let the current `select_bond_runtime_endpoints()` logic do the usual healthy/probation/quarantined ordering. Do not change `slave-agent` CF IP selection or panel provisioning.

**Tech Stack:** Python 3, `unittest`, existing client-agent state JSON files, existing bond snapshot format under `/run/leikwan`.

---

## File Structure

### Production files
- Modify: `client-agent/client_agent.py`
  - Add helper to load a forward-specific bond snapshot
  - Add helper to map CF bond runtime TCP state into the existing endpoint health cache format
  - Add helper to reconcile CF bond runtime health into the persisted cache
  - Hook reconciliation into the bond render path before endpoint selection

### Test files
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
  - Add focused regression tests for CF bond snapshot -> endpoint health behavior
  - Keep existing normal bond tests intact

### No-change files for this plan
- `slave-agent/slave_agent.py`
- `master/src/Support/ProvisioningService.php`
- `sidecar-agent/*`

---

### Task 1: Add failing CF bond health-selection regressions

**Files:**
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Test: `client-agent/tests/test_client_agent_bond_endpoint_health.py`

- [ ] **Step 1: Add a failing test proving CF bond snapshot quarantines a leg out of selected endpoints**

Append this test method to `ClientAgentBondEndpointHealthTests` in `client-agent/tests/test_client_agent_bond_endpoint_health.py`:

```python
    def test_select_bond_runtime_endpoints_uses_cf_snapshot_to_omit_quarantined_leg(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps(
                    {
                        "forward_id": 26,
                        "legs": [
                            {
                                "endpoint_id": 184,
                                "host": "8.163.90.132",
                                "tcp": {
                                    "state": "quarantined",
                                    "last_reason": "stream_open_timeout",
                                },
                                "recent_events": [
                                    {
                                        "protocol": "tcp",
                                        "from": "probation",
                                        "to": "quarantined",
                                        "reason": "stream_open_timeout",
                                        "observed_at": "2026-04-26T11:00:00Z",
                                    }
                                ],
                            },
                            {
                                "endpoint_id": 185,
                                "host": "8.163.90.235",
                                "tcp": {
                                    "state": "healthy",
                                    "last_reason": None,
                                },
                                "recent_events": [],
                            },
                        ],
                    }
                ),
                encoding="utf-8",
            )

            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [185])
            self.assertEqual(selected[0]["health_role"], "healthy")
```

- [ ] **Step 2: Run the new test and verify it fails for the expected reason**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_uses_cf_snapshot_to_omit_quarantined_leg
```

Expected: FAIL because the current implementation ignores the CF bond runtime snapshot and still returns both endpoints.

- [ ] **Step 3: Add a failing test proving CF bond still keeps one fallback if every leg is bad**

Append this test method to the same class:

```python
    def test_select_bond_runtime_endpoints_keeps_one_cf_snapshot_fallback_when_all_legs_bad(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps(
                    {
                        "forward_id": 26,
                        "legs": [
                            {
                                "endpoint_id": 184,
                                "host": "8.163.90.132",
                                "tcp": {"state": "quarantined", "last_reason": "recv_reset"},
                                "recent_events": [],
                            },
                            {
                                "endpoint_id": 185,
                                "host": "8.163.90.235",
                                "tcp": {"state": "quarantined", "last_reason": "stream_open_timeout"},
                                "recent_events": [],
                            },
                        ],
                    }
                ),
                encoding="utf-8",
            )

            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual(len(selected), 1)
            self.assertEqual(selected[0]["endpoint_id"], 184)
            self.assertEqual(selected[0]["health_role"], "quarantined")
```

- [ ] **Step 4: Run both failing CF tests together**

Run:

```bash
python -m unittest \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_uses_cf_snapshot_to_omit_quarantined_leg \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_keeps_one_cf_snapshot_fallback_when_all_legs_bad
```

Expected: both FAIL because no CF snapshot reconciliation exists yet.

- [ ] **Step 5: Add a failing test proving non-CF bond ignores the CF snapshot path**

Append this test method to the same class:

```python
    def test_select_bond_runtime_endpoints_non_cf_bond_ignores_cf_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps(
                    {
                        "forward_id": 26,
                        "legs": [
                            {
                                "endpoint_id": 184,
                                "host": "8.163.90.132",
                                "tcp": {"state": "quarantined", "last_reason": "stream_open_timeout"},
                                "recent_events": [],
                            }
                        ],
                    }
                ),
                encoding="utf-8",
            )

            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "current_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [184, 185])
            self.assertTrue(all(item["health_role"] == "healthy" for item in selected))
```

- [ ] **Step 6: Run the full bond endpoint health test module and verify only the new CF tests fail**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
```

Expected: existing tests stay green, new CF snapshot tests fail.

- [ ] **Step 7: Commit after the tests are green later, not while red**

Do not commit in the red state. Commit this file together with the production change in Task 2.

### Task 2: Implement CF bond snapshot reconciliation in the client agent

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Test: `client-agent/tests/test_client_agent_bond_endpoint_health.py`

- [ ] **Step 1: Add helper to load a forward-specific bond runtime snapshot**

Insert this helper near the existing snapshot helpers in `client-agent/client_agent.py`, directly after `load_bond_leg_reputation_snapshots()`:

```python
    def load_forward_bond_runtime_snapshot(self, forward_id: int) -> dict[str, object]:
        if forward_id <= 0:
            return {}
        snapshot_path = self.bond_leg_snapshot_dir() / f"bond-leg-health-forward-{forward_id}.json"
        if not snapshot_path.exists():
            return {}
        try:
            payload = json.loads(snapshot_path.read_text(encoding="utf-8"))
        except Exception:  # noqa: BLE001
            return {}
        return payload if isinstance(payload, dict) else {}
```

- [ ] **Step 2: Add helper to map CF bond runtime state into the persisted health-cache shape**

Insert this helper below `record_bond_endpoint_probe_result()` in `client-agent/client_agent.py`:

```python
    @staticmethod
    def cf_bond_runtime_health_entry(leg: dict, now: float) -> tuple[str, dict] | None:
        endpoint_id = int(leg.get("endpoint_id", 0) or 0)
        tcp = leg.get("tcp", {}) if isinstance(leg.get("tcp", {}), dict) else {}
        if endpoint_id <= 0 or tcp == {}:
            return None

        runtime_state = str(tcp.get("state", "healthy") or "healthy").strip().lower()
        runtime_reason = str(tcp.get("last_reason", "") or "").strip()

        role = "healthy"
        quarantine_until = 0.0
        probation_until = 0.0
        if runtime_state == "quarantined":
            role = "quarantined"
            quarantine_until = now + 90.0
        elif runtime_state in {"probation", "degraded"}:
            role = "probation"
            probation_until = now + 60.0

        return (
            str(endpoint_id),
            {
                "role": role,
                "consecutive_failures": 3 if role == "quarantined" else (2 if role == "probation" else 0),
                "consecutive_successes": 3 if role == "healthy" else 0,
                "quarantine_until": quarantine_until,
                "probation_until": probation_until,
                "last_error": runtime_reason,
                "last_latency_ms": None,
            },
        )
```

- [ ] **Step 3: Add helper to reconcile CF bond runtime health into the persisted cache**

Insert this helper below `cf_bond_runtime_health_entry()` in `client-agent/client_agent.py`:

```python
    def reconcile_cf_bond_runtime_health(self, forward: dict, now: float | None = None) -> dict:
        now = time.time() if now is None else now
        if self.normalize_mode(forward.get("mode", "single")) != "bond":
            return self.load_bond_endpoint_health()
        if str(forward.get("backend_mode", "current_mode") or "current_mode") != "cf_mode":
            return self.load_bond_endpoint_health()

        forward_id = int(forward.get("forward_id", 0) or 0)
        if forward_id <= 0:
            return self.load_bond_endpoint_health()

        snapshot = self.load_forward_bond_runtime_snapshot(forward_id)
        legs = snapshot.get("legs", []) if isinstance(snapshot.get("legs", []), list) else []
        health = self.load_bond_endpoint_health()
        scoped = health.setdefault(str(forward_id), {})
        changed = False

        for leg in legs:
            if not isinstance(leg, dict):
                continue
            mapped = self.cf_bond_runtime_health_entry(leg, now)
            if mapped is None:
                continue
            endpoint_id, entry = mapped
            previous = scoped.get(endpoint_id)
            if previous != entry:
                scoped[endpoint_id] = entry
                changed = True

        if changed:
            self.save_bond_endpoint_health(health)
        return health
```

- [ ] **Step 4: Hook reconciliation into the bond render path before endpoint selection**

In `apply_forward()` in `client-agent/client_agent.py`, replace the current bond endpoint selection block:

```python
        if mode == "bond":
            render_forward["endpoints"] = self.select_bond_runtime_endpoints(render_forward)
```

with:

```python
        if mode == "bond":
            self.reconcile_cf_bond_runtime_health(render_forward)
            render_forward["endpoints"] = self.select_bond_runtime_endpoints(render_forward)
```

This keeps the hook narrow and only affects the render path.

- [ ] **Step 5: Run the focused CF health tests and verify they pass**

Run:

```bash
python -m unittest \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_uses_cf_snapshot_to_omit_quarantined_leg \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_keeps_one_cf_snapshot_fallback_when_all_legs_bad \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_non_cf_bond_ignores_cf_snapshot
```

Expected: PASS.

- [ ] **Step 6: Run the full bond endpoint health suite**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
```

Expected: PASS.

- [ ] **Step 7: Run the broader bond regression suite**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions
```

Expected: PASS.

- [ ] **Step 8: Commit the production change and tests**

Run:

```bash
git add client-agent/client_agent.py client-agent/tests/test_client_agent_bond_endpoint_health.py
git commit -m "feat: apply normal bond health scoring to cf bond client selection"
```

Expected: commit succeeds with only those two files staged.

### Task 3: Live verification for `forward 47`

**Files:**
- Modify: none
- Test: live `forward 47` on client `sh36`

- [ ] **Step 1: Confirm the runtime is still TCP-only before the live test**

Run on the client:

```bash
sed -n '1,80p' /usr/local/bin/leikwan-client-start-47.sh
```

Expected: only `--protocol tcp` is present for the `leikwan-bond client` line. Do not enable UDP.

- [ ] **Step 2: Force a fresh render/restart of `forward 47` using the new selection logic**

Use the existing client-agent refresh path already used in this repo to re-apply `forward 47`, then restart the unit:

```bash
systemctl restart leikwan-client-47.service
sleep 75
systemctl is-active leikwan-client-47.service
```

Expected: service is `active`.

- [ ] **Step 3: Inspect the generated runtime script and confirm bad CF legs are deprioritized or omitted**

Run:

```bash
grep -n -- '--legs' /usr/local/bin/leikwan-client-start-47.sh
cat /run/leikwan/bond-leg-health-forward-47.json
```

Expected:
- CF bond legs marked `quarantined` in the snapshot should not appear unless all legs are bad
- healthier legs should remain present

- [ ] **Step 4: Run the exact TCP verification command**

Run:

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M
```

Expected: `iperf_rc=0` and improved receiver throughput relative to the current warmed multi-leg baseline.

- [ ] **Step 5: Compare against known baselines**

Use these current reference points when judging improvement:

- warmed multi-leg baseline: roughly `66.5 Mbits/sec`
- single-leg `101.133.142.65` bakeoff: roughly `79.5 Mbits/sec`

Success threshold for this pass:
- multi-leg throughput should improve above the current warmed baseline
- UDP remains untouched

- [ ] **Step 6: If live throughput does not improve, stop and report the conclusion instead of expanding scope**

If throughput is still poor after the client-side CF bond health scoring change, report:

- the client selector is no longer the likely primary bottleneck
- the remaining issue is the underlying slave/network path quality

Do not expand into slave-side CF IP selection work in this plan.

---

## Spec Coverage Self-Review

- Spec requirement: client-side only, no slave CF IP changes -> covered by Task 2 and explicit no-change scope
- Spec requirement: use local bond snapshot -> covered by Task 2 Step 1 and Step 3
- Spec requirement: map to existing healthy/probation/quarantined behavior -> covered by Task 2 Step 2 and render hook in Step 4
- Spec requirement: keep one fallback if all legs are bad -> covered by Task 1 Step 3 and Step 5
- Spec requirement: non-CF bond unchanged -> covered by Task 1 Step 5 and Task 2 Step 5
- Spec requirement: live verification on `forward 47` -> covered by Task 3

## Placeholder Scan Self-Review

- No `TBD`/`TODO` placeholders
- All code-edit steps include concrete code blocks
- All test and verification steps include exact commands
- File paths are explicit and scoped

## Type Consistency Self-Review

Planned helper names are used consistently throughout the tasks:

- `load_forward_bond_runtime_snapshot`
- `cf_bond_runtime_health_entry`
- `reconcile_cf_bond_runtime_health`

The hook site is consistently `apply_forward()` before `select_bond_runtime_endpoints()`.
