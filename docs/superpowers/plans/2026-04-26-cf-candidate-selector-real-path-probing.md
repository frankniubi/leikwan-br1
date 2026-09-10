# CF Candidate Selector Real-Path Probing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make slave-side `cf_mode` choose CF IPs using a real websocket-path probe instead of naive TCP-only latency, while keeping UDP untouched.

**Architecture:** Keep the change entirely in `slave-agent/slave_agent.py`. Add a real-path probe that measures TCP connect, TLS handshake, and websocket upgrade on the actual `Host` and `path_prefix`, then teach `select_cf_location_ip()` to use bounded candidate probing, failure cooldown, and improvement-threshold switching. Panel and client behavior stay unchanged except for version rollout and restart.

**Tech Stack:** Python 3, `unittest`, existing slave-agent service metadata, existing `cf-location-best-ip.json` state file, live panel artifact tree on `10.0.10.196`.

**Current branch:** `codex/android-single-bond-linux-parity`

---

## File Structure

### Production files
- Modify: `slave-agent/slave_agent.py`
  - Add a real websocket-path probe helper
  - Add bounded multi-sample candidate probing
  - Add candidate cooldown/failure bookkeeping in CF location state
  - Teach `select_cf_location_ip()` to keep cached IPs unless the improvement threshold is met
- Modify: `artifacts/versions.json`
  - Bump `slave_agent.version`

### Test files
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`
  - Add focused regression tests for real-path probing, threshold switching, and cooldown suppression

### Deployment targets
- Panel artifact tree: `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`
- Panel host: `10.0.10.196`
- Shanghai slaves: `316-320`
- Client: `sh36`

---

### Task 1: Add failing selector tests first

**Files:**
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`
- Test: `slave-agent/tests/test_slave_cf_location_group_selection.py`

- [ ] **Step 1: Add a failing test proving the selector keeps the cached IP when the improvement threshold is not met**

Append this test method to `SlaveCfLocationSelectionTests` in `slave-agent/tests/test_slave_cf_location_group_selection.py`:

```python
    def test_select_cf_location_ip_keeps_cached_ip_when_improvement_below_threshold(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.39.10",
                        "last_latency_ms": 40.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 0.0,
                        "backup_ips": ["162.159.39.11"],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {},
                    }
                }
            )
            with mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.39.10", "162.159.39.11"]):
                with mock.patch.object(
                    agent,
                    "probe_cf_candidates",
                    return_value=[
                        {"ip": "162.159.39.11", "ok": True, "latency_ms": 30.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                        {"ip": "162.159.39.10", "ok": True, "latency_ms": 40.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                    ],
                ):
                    selected = agent.select_cf_location_ip(
                        {
                            "location_group_id": 1,
                            "cidrs": ["162.159.39.0/24"],
                            "dial_port": 2053,
                            "probe_mode": "tcp",
                            "switch_improvement_ms": 15,
                            "cache_ttl_seconds": 30,
                            "max_candidates": 8,
                        }
                    )

            self.assertEqual(selected["selected_ip"], "162.159.39.10")
```

- [ ] **Step 2: Run the new cached-threshold test and verify it fails**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection.SlaveCfLocationSelectionTests.test_select_cf_location_ip_keeps_cached_ip_when_improvement_below_threshold
```

Expected: FAIL because the current selector always picks the numerically best probe result and does not honor `switch_improvement_ms` this way.

- [ ] **Step 3: Add a failing test proving the selector switches when the improvement threshold is exceeded**

Append this test method:

```python
    def test_select_cf_location_ip_switches_when_improvement_threshold_is_met(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.39.10",
                        "last_latency_ms": 55.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 0.0,
                        "backup_ips": ["162.159.39.11"],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {},
                    }
                }
            )
            with mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.39.10", "162.159.39.11"]):
                with mock.patch.object(
                    agent,
                    "probe_cf_candidates",
                    return_value=[
                        {"ip": "162.159.39.11", "ok": True, "latency_ms": 30.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                        {"ip": "162.159.39.10", "ok": True, "latency_ms": 55.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                    ],
                ):
                    selected = agent.select_cf_location_ip(
                        {
                            "location_group_id": 1,
                            "cidrs": ["162.159.39.0/24"],
                            "dial_port": 2053,
                            "probe_mode": "tcp",
                            "switch_improvement_ms": 15,
                            "cache_ttl_seconds": 30,
                            "max_candidates": 8,
                        }
                    )

            self.assertEqual(selected["selected_ip"], "162.159.39.11")
```

- [ ] **Step 4: Add a failing test proving candidates on cooldown are skipped**

Append this test method:

```python
    def test_select_cf_location_ip_skips_candidates_in_cooldown(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            now = 200.0
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.39.10",
                        "last_latency_ms": 50.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 0.0,
                        "backup_ips": [],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {
                            "162.159.39.11": {
                                "consecutive_failures": 3,
                                "cooldown_until": 260.0,
                            }
                        },
                    }
                }
            )
            with mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.39.10", "162.159.39.11", "162.159.39.12"]):
                with mock.patch.object(
                    agent,
                    "probe_cf_candidates",
                    return_value=[
                        {"ip": "162.159.39.12", "ok": True, "latency_ms": 45.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                        {"ip": "162.159.39.10", "ok": True, "latency_ms": 50.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                    ],
                ):
                    with mock.patch.object(slave_agent.time, "time", return_value=now):
                        selected = agent.select_cf_location_ip(
                            {
                                "location_group_id": 1,
                                "cidrs": ["162.159.39.0/24"],
                                "dial_port": 2053,
                                "probe_mode": "tcp",
                                "switch_improvement_ms": 15,
                                "cache_ttl_seconds": 30,
                                "max_candidates": 8,
                            }
                        )

            self.assertEqual(selected["selected_ip"], "162.159.39.10")
```

- [ ] **Step 5: Add a failing test proving the real-path probe records latency, jitter, and failures from a websocket probe helper**

Append this test method:

```python
    def test_probe_cf_candidates_uses_real_path_probe_samples(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(
                agent,
                "probe_cf_websocket_path",
                side_effect=[
                    (True, 40.0, None),
                    (True, 44.0, None),
                    (False, None, "timeout"),
                    (True, 30.0, None),
                ],
            ):
                results = agent.probe_cf_candidates(
                    ["162.159.39.10", "162.159.39.11"],
                    {
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                        "probe_samples": 2,
                    },
                )

            self.assertEqual(results[0]["ip"], "162.159.39.11")
            self.assertEqual(results[0]["latency_ms"], 30.0)
            self.assertEqual(results[0]["jitter_ms"], 0.0)
            self.assertEqual(results[0]["failures"], 1)
            self.assertEqual(results[1]["ip"], "162.159.39.10")
            self.assertEqual(results[1]["latency_ms"], 42.0)
            self.assertEqual(results[1]["jitter_ms"], 4.0)
```

- [ ] **Step 6: Run the focused selector tests and verify the new ones fail**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
```

Expected: FAIL on the four new selector tests because the current implementation has no threshold keep, no cooldown filtering, and no real-path probe helper.

- [ ] **Step 7: Do not commit in the red state**

Wait until Task 2 turns the suite green.

### Task 2: Implement real-path probing and threshold-based selection

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`
- Test: `slave-agent/tests/test_slave_cf_location_group_selection.py`

- [ ] **Step 1: Add a websocket-path probe helper in `slave-agent/slave_agent.py`**

Insert this helper below `tcp_ping()`:

```python
    def probe_cf_websocket_path(
        self,
        ip: str,
        port: int,
        sni_host: str,
        path_prefix: str,
        timeout: float = 1.0,
    ) -> tuple[bool, float | None, str | None]:
        request_path = "/" + normalize_path_prefix(path_prefix)
        host_header = sni_host.strip() or ip
        server_name = sni_host.strip() or host_header
        started = time.time()
        try:
            with socket.create_connection((ip, port), timeout=timeout) as raw_sock:
                context = ssl.create_default_context()
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                with context.wrap_socket(raw_sock, server_hostname=server_name) as tls_sock:
                    tls_sock.settimeout(timeout)
                    request_bytes = (
                        f"GET {request_path} HTTP/1.1\r\n"
                        f"Host: {host_header}\r\n"
                        "Upgrade: websocket\r\n"
                        "Connection: Upgrade\r\n"
                        "Sec-WebSocket-Key: dGVzdC1sZWlrd2FuLXByb2Jl\r\n"
                        "Sec-WebSocket-Version: 13\r\n\r\n"
                    ).encode("utf-8")
                    tls_sock.sendall(request_bytes)
                    response_head = tls_sock.recv(4096).decode("latin-1", errors="ignore")
            status_line = response_head.split("\r\n", 1)[0]
            if " 101 " not in status_line:
                return False, None, status_line or "websocket_upgrade_failed"
            return True, round((time.time() - started) * 1000, 1), None
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)
```

- [ ] **Step 2: Replace the old TCP-only candidate probe with multi-sample real-path probing**

Replace `probe_cf_candidates()` in `slave-agent/slave_agent.py` with:

```python
    def probe_cf_candidates(self, candidates: list[str], cf_mode: dict[str, Any]) -> list[dict[str, Any]]:
        port = int(cf_mode.get("dial_port", 443) or 443)
        sni_host = str(cf_mode.get("sni_host", "") or "")
        path_prefix = str(cf_mode.get("path_prefix", "") or "")
        sample_count = max(1, min(3, int(cf_mode.get("probe_samples", 2) or 2)))
        results: list[dict[str, Any]] = []

        for ip in candidates:
            latencies: list[float] = []
            failures = 0
            last_error = ""
            for _ in range(sample_count):
                ok, latency_ms, error = self.probe_cf_websocket_path(ip, port, sni_host, path_prefix)
                if ok and latency_ms is not None:
                    latencies.append(latency_ms)
                else:
                    failures += 1
                    last_error = error or "probe_failed"
            if latencies:
                avg_latency = round(sum(latencies) / len(latencies), 1)
                jitter = round(max(latencies) - min(latencies), 1) if len(latencies) > 1 else 0.0
                results.append(
                    {
                        "ip": ip,
                        "ok": True,
                        "latency_ms": avg_latency,
                        "jitter_ms": jitter,
                        "failures": failures,
                        "error": last_error,
                    }
                )
            else:
                results.append(
                    {
                        "ip": ip,
                        "ok": False,
                        "latency_ms": 999999.0,
                        "jitter_ms": 999999.0,
                        "failures": failures,
                        "error": last_error or "probe_failed",
                    }
                )

        results.sort(key=lambda item: (not item["ok"], item["latency_ms"], item["jitter_ms"], item["failures"], item["ip"]))
        return results
```

- [ ] **Step 3: Add a helper that decides whether a candidate should be skipped because of cooldown**

Insert this helper below `probe_cf_candidates()`:

```python
    @staticmethod
    def candidate_on_cooldown(candidate_stats: dict[str, Any], ip: str, now: float) -> bool:
        item = candidate_stats.get(ip, {}) if isinstance(candidate_stats.get(ip), dict) else {}
        return float(item.get("cooldown_until", 0) or 0) > now
```

- [ ] **Step 4: Replace `select_cf_location_ip()` with threshold-aware selection that honors cooldown and cached state**

Replace the body of `select_cf_location_ip()` with:

```python
    def select_cf_location_ip(self, cf_mode: dict[str, Any]) -> dict[str, Any]:
        group_id = int(cf_mode.get("location_group_id", 0) or 0)
        state = self.load_cf_location_state()
        cache_key = str(group_id)
        cached = state.get(cache_key, {}) if isinstance(state.get(cache_key), dict) else {}
        now = time.time()
        ttl = max(30, int(cf_mode.get("cache_ttl_seconds", 120) or 120))
        if cached and (now - float(cached.get("last_probe_at", 0) or 0)) < ttl and str(cached.get("selected_ip", "")) != "":
            return cached

        candidate_limit = max(1, min(8, int(cf_mode.get("max_candidates", 8) or 8)))
        improvement_ms = max(0, int(cf_mode.get("switch_improvement_ms", 15) or 15))
        cooldown_seconds = max(60, ttl)
        candidate_stats = cached.get("candidate_stats", {}) if isinstance(cached.get("candidate_stats"), dict) else {}

        candidates = self.expand_cf_candidates(list(cf_mode.get("cidrs", [])), int(cf_mode.get("max_candidates", 32) or 32))
        filtered = [ip for ip in candidates if not self.candidate_on_cooldown(candidate_stats, ip, now)]
        filtered = filtered[:candidate_limit]
        results = self.probe_cf_candidates(filtered, cf_mode)

        successes = [item for item in results if item["ok"]]
        if not successes:
            if cached and str(cached.get("selected_ip", "")) != "":
                cached["last_probe_at"] = now
                state[cache_key] = cached
                self.save_cf_location_state(state)
                return cached
            raise RuntimeError("no reachable CF candidates")

        for item in results:
            stats = candidate_stats.setdefault(item["ip"], {})
            if item["ok"]:
                stats["last_latency_ms"] = item["latency_ms"]
                stats["last_jitter_ms"] = item["jitter_ms"]
                stats["last_probe_at"] = now
                stats["consecutive_failures"] = 0
                stats["cooldown_until"] = 0
            else:
                stats["last_probe_at"] = now
                stats["consecutive_failures"] = int(stats.get("consecutive_failures", 0) or 0) + 1
                stats["cooldown_until"] = now + cooldown_seconds
                stats["last_error"] = item["error"]

        best = successes[0]
        selected = best
        current_ip = str(cached.get("selected_ip", "") or "")
        current_success = next((item for item in successes if item["ip"] == current_ip), None)
        if current_success is not None and current_ip != str(best["ip"]):
            improvement = float(current_success["latency_ms"] or 0) - float(best["latency_ms"] or 0)
            if improvement < improvement_ms:
                selected = current_success

        state[cache_key] = {
            "location_group_id": group_id,
            "selected_ip": selected["ip"],
            "last_latency_ms": selected["latency_ms"],
            "last_jitter_ms": selected["jitter_ms"],
            "last_probe_at": now,
            "backup_ips": [item["ip"] for item in successes if item["ip"] != selected["ip"]][:3],
            "consecutive_failures": int(candidate_stats.get(selected["ip"], {}).get("consecutive_failures", 0) or 0),
            "cooldown_until": float(candidate_stats.get(selected["ip"], {}).get("cooldown_until", 0) or 0),
            "candidate_stats": candidate_stats,
        }
        self.save_cf_location_state(state)
        return state[cache_key]
```

- [ ] **Step 5: Run the selector test suite and verify it passes**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
```

Expected: PASS.

- [ ] **Step 6: Commit the slave-agent code and tests**

Run:

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_slave_cf_location_group_selection.py
git commit -m "feat: probe cf candidates using real websocket path"
```

Expected: commit succeeds with only those files staged.

### Task 3: Bump the slave-agent version and panel artifact metadata

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Modify: `artifacts/versions.json`

- [ ] **Step 1: Bump the slave-agent version constant**

Update the version constant in `slave-agent/slave_agent.py` from its current value to the new rollout version:

```python
AGENT_VERSION = "2026.04.26.3"
```

- [ ] **Step 2: Bump the panel artifact metadata for `slave_agent`**

Update only the `slave_agent.version` field in `artifacts/versions.json`:

```json
"slave_agent": {
    "version": "2026.04.26.3",
    "path": "slave-agent/slave_agent.py",
    "artifact_url": "/artifacts/slave-agent.py"
}
```

- [ ] **Step 3: Verify the targeted test suite still passes after the version bump**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
```

Expected: PASS.

- [ ] **Step 4: Commit the version bump**

Run:

```bash
git add slave-agent/slave_agent.py artifacts/versions.json
git commit -m "chore: bump slave agent version for cf candidate probe rollout"
```

Expected: commit succeeds.

### Task 4: Push to panel, restart Shanghai slaves and client, and verify live behavior

**Files:**
- Modify: live panel artifact tree only
- Test: live Shanghai slaves `316-320` and client `sh36`

- [ ] **Step 1: Back up and push the new slave-agent to the panel**

Run from the operator workstation:

```bash
PANEL_HOST=10.0.10.196
PANEL_PASS='Frank1212.'
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
REPO_ROOT=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
sshpass -p "$PANEL_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PANEL_HOST \
  "mkdir -p /root/codex-backups/panel-slave-agent-$STAMP && \
   cp -a $REPO_ROOT/slave-agent/slave_agent.py /root/codex-backups/panel-slave-agent-$STAMP/slave_agent.py.before && \
   cp -a $REPO_ROOT/artifacts/versions.json /root/codex-backups/panel-slave-agent-$STAMP/versions.json.before"
sshpass -p "$PANEL_PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null slave-agent/slave_agent.py root@$PANEL_HOST:/tmp/slave_agent.py.new
sshpass -p "$PANEL_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$PANEL_HOST 'bash -s' <<'REMOTE'
set -euo pipefail
REPO_ROOT=/www/wwwroot/v3.leikwanhost.com/leikwan-v3
install -o www -g www -m 0755 /tmp/slave_agent.py.new $REPO_ROOT/slave-agent/slave_agent.py
rm -f /tmp/slave_agent.py.new
python3 - <<'PY'
import json, pathlib
path = pathlib.Path('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json')
obj = json.loads(path.read_text(encoding='utf-8'))
obj['slave_agent']['version'] = '2026.04.26.3'
path.write_text(json.dumps(obj, ensure_ascii=False, indent=4) + '\n', encoding='utf-8')
PY
sha256sum $REPO_ROOT/slave-agent/slave_agent.py
REMOTE
```

Expected: panel file replaced, backup created, and remote hash printed.

- [ ] **Step 2: Verify the public panel artifact serves the new slave-agent**

Run:

```bash
curl -fsSL https://v3.leikwanhost.com/artifacts/slave-agent.py | sha256sum
```

Expected: hash matches the local `slave-agent/slave_agent.py` hash.

- [ ] **Step 3: Restart the Shanghai slave agents and forward-47 backend services**

Run:

```bash
for pair in '47.116.11.144 316' '47.116.104.15 317' '101.133.142.65 318' '47.116.134.3 319' '8.133.170.123 320'; do
  set -- $pair
  HOST="$1"; EID="$2"
  PASS=$(awk -F'|' -v h="$HOST" '$1==h {print $4; exit}' shanghais.txt cantons.txt 2>/dev/null)
  sshpass -p "$PASS" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null slave-agent/slave_agent.py root@$HOST:/usr/local/bin/leikwan-slave-agent.py
  sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$HOST \
    "chmod 0755 /usr/local/bin/leikwan-slave-agent.py && systemctl restart leikwan-slave-agent.service && systemctl restart wstunnel-svc-2-$EID.service"
done
```

Expected: all five slave agents and their `wstunnel-svc-2-31x.service` units restart successfully.

- [ ] **Step 4: Verify the live Shanghai slave cmdlines now reflect the new selected IP choices**

Run:

```bash
for pair in '47.116.11.144 316' '47.116.104.15 317' '101.133.142.65 318' '47.116.134.3 319' '8.133.170.123 320'; do
  set -- $pair
  HOST="$1"; EID="$2"
  PASS=$(awk -F'|' -v h="$HOST" '$1==h {print $4; exit}' shanghais.txt cantons.txt 2>/dev/null)
  echo "=== $HOST $EID ==="
  sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$HOST \
    "PID=\$(systemctl show -p MainPID --value wstunnel-svc-2-$EID.service); tr '\0' ' ' </proc/\$PID/cmdline; echo"
done
```

Expected: the live `wstunnel client` command should dial selected CF IPs, preserve `--tls-sni-override nrt1.nbwly.com`, and preserve the real `--http-upgrade-path-prefix`.

- [ ] **Step 5: Restart the client after the Shanghai rollout**

Run:

```bash
systemctl restart leikwan-client-47.service
sleep 75
systemctl is-active leikwan-client-47.service
```

Expected: client service is `active`.

- [ ] **Step 6: Run the exact TCP verification command**

Run:

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M
```

Expected: `iperf_rc=0` and throughput meaningfully better than the current rolled-back baseline.

- [ ] **Step 7: Confirm UDP remains untouched**

Run:

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -u
```

Expected: current UDP behavior is unchanged. Do not attempt to fix UDP in this plan.

- [ ] **Step 8: If throughput still does not improve, stop and report that the issue is deeper than selector quality**

Report explicitly if the rollout does not improve TCP:
- selected-IP wiring is fixed
- candidate ranking is stronger
- but the remaining issue is still elsewhere in the live path

Do not expand into unrelated layers inside this plan.

---

## Spec Coverage Self-Review

- Spec requirement: slave-side only -> covered by Tasks 1-4, no client/panel schema changes
- Spec requirement: 3 to 5 second budget with bounded candidate slice -> covered by Task 2 Step 4 with bounded candidate limit and no same-cycle retry
- Spec requirement: real path signal -> covered by Task 2 Steps 1-2
- Spec requirement: keep cached IP unless improvement threshold is met -> covered by Task 1 Steps 1-2 and Task 2 Step 4
- Spec requirement: cooldown/failure memory matters -> covered by Task 1 Step 4 and Task 2 Step 4
- Spec requirement: push new version to panel and restart Shanghai slaves plus client -> covered by Task 4

## Placeholder Scan Self-Review

- No `TBD` or `TODO` placeholders
- Each code-edit step includes concrete code
- Each verification step includes exact commands
- Branch name is included explicitly in the plan header as requested

## Type Consistency Self-Review

Planned helper names are consistent throughout this plan:
- `probe_cf_websocket_path`
- `probe_cf_candidates`
- `candidate_on_cooldown`
- `select_cf_location_ip`

The rollout version is consistently `2026.04.26.3` in both `slave-agent/slave_agent.py` and `artifacts/versions.json`.
