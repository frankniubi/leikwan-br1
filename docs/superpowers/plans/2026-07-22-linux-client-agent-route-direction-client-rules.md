# Linux Client-Agent Route Direction Client Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Linux client-agent support for Route Direction Client Rules by parsing the additive pull payload, advertising route-rule capability, installing exact TCP+UDP nftables dispatch/block rules, and publishing a synchronized downloadable artifact.

**Architecture:** The Linux client-agent keeps existing forward/listener runtime setup unchanged, then adds a route-rule layer that maps enabled active `DST IPv4:PORT` rules to route-rule-owned local TCP/UDP connector ports. nftables redirects exact destination traffic only to those connector ports; each connector then opens a marked onward socket to the selected direction binding's `local_endpoint.host` and `local_endpoint.tcp_port`/`udp_port` instead of the original destination IP/port. The route-rule layer compiles active rules into connector service/script renderings plus nftables output redirects, compiles enabled non-active/unready rules into fail-closed nftables blocks, starts connector units before installing nft redirects, fully replaces prior managed route-rule nftables entries on each pull to remove stale rules, and records route-rule summaries in both heartbeat and local pull state. Loop prevention is concrete: route-rule nftables output NAT rules start with a dedicated fwmark bypass, and every route-rule-owned TCP/UDP connector calls `setsockopt(SOL_SOCKET, SO_MARK, ROUTE_RULE_FWMARK_INT)` before connecting toward the binding local endpoint.

**Tech Stack:** Python 3 client-agent, Linux nftables `inet` tables/chains, `socket.SO_MARK`, `unittest`, PowerShell-compatible verification commands, JSON artifact manifest hashing.

---

## Scope

This plan is scoped only to the Linux client-agent implementation files below. It does not implement backend, Android, Windows Desktop, admin UI, app UI, or server write APIs.

Spec reference: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\docs\superpowers\specs\2026-07-22-route-direction-client-rules-design.md`.

## File Structure

- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`
  - Parses `route_directions`, `route_rule_entitlements`, `client_rules`, `direction_runtime_bindings`, and `capabilities` from `/api/client/configs/pull`.
  - Advertises heartbeat fields `supports_route_direction_rules` and `supports_exact_destination_rules`.
  - Builds route-rule plans from server effective state plus local TCP+UDP listener readiness.
  - Assigns deterministic route-rule-owned TCP/UDP connector ports per dispatch rule and records the onward binding endpoint for each connector.
  - Renders TCP connector scripts/units and UDP connector scripts/units; connector scripts set `SO_MARK`/`ROUTE_RULE_FWMARK_INT` before opening onward sockets.
  - Renders and applies nftables exact-destination redirect/block rules under `inet leikwan_route_rules` without modifying existing `inet leikwan_meter` metering logic; active redirects target connector ports, never binding local endpoint ports directly.
  - Computes desired connector IDs/ports from the dispatch plan, stops/disables/removes stale connector units, deletes stale connector scripts/state files, renders/installs current connector scripts and units, starts/restarts current connectors, then installs nftables table/rules/redirects.
  - Removes stale managed route-rule nftables entries on each pull before installing the current managed nftables entries.
  - Records route-rule summaries in heartbeat and `config_pull_state.json`.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\artifacts\client-agent.py`
  - Byte-identical downloadable mirror of `client-agent/client_agent.py`.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\artifacts\versions.json`
  - Updates `client_agent.version`, `client_agent.sha256`, `client_agent.size_bytes`, and `client_agent.size` after the mirror is refreshed.
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_route_direction_rules.py`
  - Covers parsing, heartbeat capability fields, route-rule planning, nftables rendering, loop-prevention ordering, fail-closed rules, stale cleanup, and apply summaries.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_artifact_versions_consistency.py`
  - Adds an exact rollout-version assertion while retaining existing source/artifact mirror and versions.json hash/size checks.

## Route-rule nftables and connector design locked for implementation

- Metering remains in existing table `inet leikwan_meter`; route-rule interception uses a separate table `inet leikwan_route_rules`.
- Route-rule NAT chain: `rr_output_nat` with `type nat hook output priority -100; policy accept;`.
- Route-rule filter chain: `rr_output_filter` with `type filter hook output priority 0; policy accept;`.
- Dedicated fwmark: `0x6c77`.
- Python integer constant: `ROUTE_RULE_FWMARK_INT = int(ROUTE_RULE_FWMARK, 16)`.
- First managed NAT rule: `meta mark 0x6c77 return comment "lw_route_rule_bypass_mark"`.
- Deterministic connector ports:
  - `ROUTE_RULE_CONNECTOR_PORT_BASE = 18000`.
  - `ROUTE_RULE_CONNECTOR_PORT_STRIDE = 2`.
  - Dispatch index is zero-based after sorting dispatch rules by `str(rule_id)`.
  - Index 0 TCP connector port: `ROUTE_RULE_CONNECTOR_PORT_BASE + (0 * ROUTE_RULE_CONNECTOR_PORT_STRIDE) = 18000`.
  - Index 0 UDP connector port: `ROUTE_RULE_CONNECTOR_PORT_BASE + (0 * ROUTE_RULE_CONNECTOR_PORT_STRIDE) + 1 = 18001`.
  - Index N TCP connector port: `ROUTE_RULE_CONNECTOR_PORT_BASE + (N * ROUTE_RULE_CONNECTOR_PORT_STRIDE)`.
  - Index N UDP connector port: `ROUTE_RULE_CONNECTOR_PORT_BASE + (N * ROUTE_RULE_CONNECTOR_PORT_STRIDE) + 1`.
- Active TCP nft rule shape: `ip daddr <destination_ip> tcp dport <destination_port> redirect to :<connector_tcp_port>`.
- Active UDP nft rule shape: `ip daddr <destination_ip> udp dport <destination_port> redirect to :<connector_udp_port>`.
- nftables redirects exact destination traffic to route-rule-owned connector ports only. nftables must not redirect directly to the direction binding's `local_endpoint.tcp_port` or `local_endpoint.udp_port`.
- Each connector's onward target is the binding local endpoint: `binding.local_endpoint.host`, `binding.local_endpoint.tcp_port`, and `binding.local_endpoint.udp_port`. The connector must not connect to the original destination IP/port; doing so bypasses the managed tunnel and leaks traffic to the Internet.
- TCP connector behavior: accept redirected local TCP connections, open an onward TCP socket to `binding.local_endpoint.host:binding.local_endpoint.tcp_port`, set `socket.SO_MARK` to `ROUTE_RULE_FWMARK_INT` before `connect()`, then relay bytes bidirectionally.
- UDP connector behavior: receive redirected UDP datagrams on the route-rule-owned UDP connector port, create/use a marked UDP socket connected to `binding.local_endpoint.host:binding.local_endpoint.udp_port`, call `setsockopt(SOL_SOCKET, SO_MARK, ROUTE_RULE_FWMARK_INT)` before `connect()`, forward datagrams through that socket, and relay replies back to the original local client address/port that sent the redirected datagram.
- Enabled non-active TCP rule shape: `ip daddr <destination_ip> tcp dport <destination_port> reject with tcp reset`.
- Enabled non-active UDP rule shape: `ip daddr <destination_ip> udp dport <destination_port> drop`.
- Managed nft comments all start with `lw_route_rule_`; stale cleanup deletes only rules with that prefix.
- Managed connector service names start with `leikwan-route-rule-`; managed connector scripts live under `/opt/leikwan/route-rule-connectors` and also start with `leikwan-route-rule-`; managed connector runtime state lives under `/run/leikwan/route-rule-connectors` and starts with `leikwan-route-rule-`. Stale cleanup stops, disables, and removes only units/scripts/state with that prefix whose desired connector ID is no longer in the current dispatch plan.
- `apply_route_direction_rules()` ordering is locked and test-enforced: (1) parse snapshot and build the dispatch plan, (2) compute desired connector IDs/ports/specs, (3) clean up stale connector units/scripts/state and old managed nftables handles, (4) render and install current connector scripts and units, (5) start/restart current connector services, then (6) install the nft table/chains/bypass/filter/redirect rules. The implementation must never install new nft redirects before stale connector cleanup and current connector start/restart complete.
- Failure guard: if a previous pull left `leikwan-route-rule-old-tcp.service` or `leikwan-route-rule-rule_1-tcp.service` bound to a connector port that the new desired plan reassigns after rules were removed or reordered, cleanup must stop and disable that stale unit before current connectors start. Otherwise exact destination traffic for the new rule could be redirected to a stale binding endpoint already holding the port.
- The route-rule dispatch state includes `fwmark = "0x6c77"`, `fwmark_int = 27767`, `socket_mark_required = True`, `connector_id`, `connector_tcp_port`, `connector_udp_port`, and explicit `onward_host`, `onward_tcp_port`, `onward_udp_port` fields copied from the binding local endpoint. Tests assert deterministic index-0 connector ports, nft redirect-to-connector behavior, stale-connector-cleanup-before-start-before-nft ordering, deletion of stale scripts/state after removal/reorder, and exact `SO_MARK` before onward connect behavior.

### Task 1: Route-rule pull parser and safe no-op apply method

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_route_direction_rules.py`

- [ ] **Step 1: Write the failing parser and no-op apply tests**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_route_direction_rules.py`:

```python
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_route_rules_under_test", "client-agent/client_agent.py")


class RouteDirectionRuleTests(unittest.TestCase):
    def make_agent(self):
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        return agent

    def sample_payload(self) -> dict:
        return {
            "ok": True,
            "client": {"id": 7, "name": "linux-client"},
            "forwards": [],
            "route_directions": [
                {"id": "dir_sh_jp_1", "label": "沪日1", "status": "available", "is_available": True, "sort_order": 10},
            ],
            "route_rule_entitlements": [
                {
                    "service_scope_id": "svc_scope_1001",
                    "service_id": 1001,
                    "subscription_id": 501,
                    "max_rules": 5,
                    "remaining_rules": 3,
                    "allowed_direction_ids": ["dir_sh_jp_1"],
                }
            ],
            "client_rules": [
                {
                    "id": "rule_1",
                    "service_scope_id": "svc_scope_1001",
                    "service_id": 1001,
                    "subscription_id": 501,
                    "destination_ip": "93.184.216.34",
                    "destination_port": 443,
                    "protocol": "tcp_udp",
                    "direction_id": "dir_sh_jp_1",
                    "enabled": True,
                    "effective_state": "active",
                    "sync_version": 17,
                }
            ],
            "direction_runtime_bindings": [
                {
                    "service_scope_id": "svc_scope_1001",
                    "direction_id": "dir_sh_jp_1",
                    "binding_state": "ready",
                    "client_runtime_binding_id": "crb_svc_scope_1001_dir_sh_jp_1",
                    "runtime_ref": {"type": "forward_ref", "forward_id": 10001, "selectable": False, "transport": "tcp_udp"},
                    "local_endpoint": {"host": "127.0.0.1", "tcp_port": 11001, "udp_port": 11001},
                    "tcp_ready": True,
                    "udp_ready": True,
                }
            ],
            "capabilities": {"route_direction_rules": True, "exact_destination_rules": True},
        }

    def test_parse_route_direction_rule_payload_preserves_contract_arrays_and_capabilities(self) -> None:
        agent = self.make_agent()

        snapshot = agent.parse_route_direction_rule_payload(self.sample_payload())

        self.assertEqual("dir_sh_jp_1", snapshot["route_directions"][0]["id"])
        self.assertEqual("沪日1", snapshot["route_directions"][0]["label"])
        self.assertEqual("svc_scope_1001", snapshot["route_rule_entitlements"][0]["service_scope_id"])
        self.assertEqual(["dir_sh_jp_1"], snapshot["route_rule_entitlements"][0]["allowed_direction_ids"])
        self.assertEqual("rule_1", snapshot["client_rules"][0]["id"])
        self.assertEqual("93.184.216.34", snapshot["client_rules"][0]["destination_ip"])
        self.assertEqual(443, snapshot["client_rules"][0]["destination_port"])
        self.assertEqual("tcp_udp", snapshot["client_rules"][0]["protocol"])
        self.assertEqual("dir_sh_jp_1", snapshot["client_rules"][0]["direction_id"])
        self.assertEqual("ready", snapshot["direction_runtime_bindings"][0]["binding_state"])
        self.assertEqual(11001, snapshot["direction_runtime_bindings"][0]["local_endpoint"]["tcp_port"])
        self.assertTrue(snapshot["server_capabilities"]["route_direction_rules"])
        self.assertTrue(snapshot["server_capabilities"]["exact_destination_rules"])
        self.assertEqual(1, snapshot["summary"]["directions_total"])
        self.assertEqual(1, snapshot["summary"]["client_rules_total"])
        self.assertEqual(1, snapshot["summary"]["bindings_total"])

    def test_parse_route_direction_rule_payload_missing_fields_defaults_to_empty_unsupported_shape(self) -> None:
        agent = self.make_agent()

        snapshot = agent.parse_route_direction_rule_payload({"ok": True, "client": {}, "forwards": []})

        self.assertEqual([], snapshot["route_directions"])
        self.assertEqual([], snapshot["route_rule_entitlements"])
        self.assertEqual([], snapshot["client_rules"])
        self.assertEqual([], snapshot["direction_runtime_bindings"])
        self.assertFalse(snapshot["server_capabilities"]["route_direction_rules"])
        self.assertFalse(snapshot["server_capabilities"]["exact_destination_rules"])
        self.assertEqual(0, snapshot["summary"]["client_rules_total"])

    def test_apply_route_direction_rules_exists_as_noop_before_nft_implementation(self) -> None:
        agent = self.make_agent()
        snapshot = agent.parse_route_direction_rule_payload(self.sample_payload())

        summary = agent.apply_route_direction_rules(snapshot)

        self.assertEqual("noop", summary["status"])
        self.assertEqual(1, summary["client_rules_total"])
        self.assertEqual([], summary["installed_rule_ids"])
        self.assertEqual([], summary["blocked_rule_ids"])
        self.assertEqual([], summary["stale_rule_ids_removed"])


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run tests to verify they fail for the missing parser**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: FAIL with `AttributeError: 'ClientAgent' object has no attribute 'parse_route_direction_rule_payload'`.

- [ ] **Step 3: Add route-rule parser helpers and a no-op apply method**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`, add these constants after `HELPER_CANDIDATE_KINDS`:

```python
ROUTE_RULE_ACTIVE_STATE = "active"
ROUTE_RULE_READY_STATE = "ready"
ROUTE_RULE_PROTOCOL = "tcp_udp"
```

In the same file, add these methods immediately after `save_pull_state`:

```python
    @staticmethod
    def route_rule_dict_list(value: object) -> list[dict]:
        if not isinstance(value, list):
            return []
        return [dict(item) for item in value if isinstance(item, dict)]

    @staticmethod
    def route_rule_bool(value: object) -> bool:
        if value is True:
            return True
        if value is False or value is None:
            return False
        return str(value).strip().lower() in {"1", "true", "yes", "on"}

    def parse_route_direction_rule_payload(self, payload: dict) -> dict:
        route_directions = self.route_rule_dict_list(payload.get("route_directions"))
        route_rule_entitlements = self.route_rule_dict_list(payload.get("route_rule_entitlements"))
        client_rules = self.route_rule_dict_list(payload.get("client_rules"))
        direction_runtime_bindings = self.route_rule_dict_list(payload.get("direction_runtime_bindings"))
        capabilities_raw = payload.get("capabilities", {})
        if not isinstance(capabilities_raw, dict):
            capabilities_raw = {}
        server_capabilities = {
            "route_direction_rules": self.route_rule_bool(capabilities_raw.get("route_direction_rules")),
            "exact_destination_rules": self.route_rule_bool(capabilities_raw.get("exact_destination_rules")),
        }
        summary = {
            "directions_total": len(route_directions),
            "entitlements_total": len(route_rule_entitlements),
            "client_rules_total": len(client_rules),
            "bindings_total": len(direction_runtime_bindings),
            "server_route_direction_rules": server_capabilities["route_direction_rules"],
            "server_exact_destination_rules": server_capabilities["exact_destination_rules"],
        }
        return {
            "route_directions": route_directions,
            "route_rule_entitlements": route_rule_entitlements,
            "client_rules": client_rules,
            "direction_runtime_bindings": direction_runtime_bindings,
            "server_capabilities": server_capabilities,
            "summary": summary,
        }

    def apply_route_direction_rules(self, snapshot: dict) -> dict:
        summary = dict(snapshot.get("summary", {})) if isinstance(snapshot.get("summary"), dict) else {}
        summary.update(
            {
                "status": "noop",
                "installed_rule_ids": [],
                "blocked_rule_ids": [],
                "stale_rule_ids_removed": [],
            }
        )
        return summary
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: PASS with 3 tests passing.

- [ ] **Step 5: Commit**

Run:

```powershell
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" add "client-agent/client_agent.py" "client-agent/tests/test_route_direction_rules.py"
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" commit -m @'
feat(client-agent): parse route direction rule payload

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

Expected: commit succeeds.

### Task 2: Heartbeat capability advertisement and pull observability

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_route_direction_rules.py`

- [ ] **Step 1: Add failing capability and sync observability tests**

Add this import near the top of `test_route_direction_rules.py`:

```python
import subprocess
```

Add these methods inside `RouteDirectionRuleTests` before the `if __name__ == "__main__":` block:

```python
    def test_route_rule_capability_fields_advertise_exact_destination_when_nft_exists(self) -> None:
        agent = self.make_agent()
        with mock.patch.object(client_agent_module.shutil, "which", return_value="/usr/sbin/nft"):
            fields = agent.route_rule_capability_fields()

        self.assertEqual(
            {
                "supports_route_direction_rules": True,
                "supports_exact_destination_rules": True,
            },
            fields,
        )

    def test_route_rule_capability_fields_keep_exact_destination_false_without_nft(self) -> None:
        agent = self.make_agent()
        with mock.patch.object(client_agent_module.shutil, "which", return_value=None):
            fields = agent.route_rule_capability_fields()

        self.assertEqual(
            {
                "supports_route_direction_rules": True,
                "supports_exact_destination_rules": False,
            },
            fields,
        )

    def test_sync_once_reports_route_rule_capabilities_and_summary(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text("{}", encoding="utf-8")
            agent = self.make_agent()
            agent.config = {"master_url": "https://panel.example", "agent_token": "token", "client_id": 7}
            agent.state_dir = tmp
            agent.applied_path = tmp / "applied.json"
            agent.applied_path.write_text("{}", encoding="utf-8")
            agent.pull_state_path = tmp / "config_pull_state.json"
            agent.usage_state_path = tmp / "usage_state.json"
            agent.latest_forwards = []
            agent.health_restart_after = {}
            agent.health_reorder_after = {}
            agent.invalid_forward_log_after = {}
            agent.self_leg_probe_cache = {}
            agent._cfwg_latency_watchdog = None

            helper_path = tmp / "helper.py"
            helper_path.write_text("print('helper')\n", encoding="utf-8")
            agent.apply_management_state = lambda client_meta: None
            agent.consume_local_refresh_requests = lambda: set()
            agent.load_applied = lambda: {}
            agent.reconcile_global_mptcp_state = lambda forwards: None
            agent.run_diagnostics_if_requested = lambda master, token, client_meta, forwards: None
            agent.collect_usage_totals = lambda: {"bytes_in": 11, "bytes_out": 22}
            agent.ensure_wireguard_identity = lambda: "wg-pub"
            agent.time_sync_state = lambda: {"status": "ok"}
            agent.install_forward_runtime_path = lambda: helper_path
            agent.mptcp_client_path = lambda: helper_path
            agent.wireguard_listen_port = lambda: 51820
            agent.wireguard_fallback_tcp_port = lambda: 0
            agent.load_bond_leg_reputation_snapshots = lambda: []
            agent.running_kernel_supports_mptcp = lambda: True
            agent.mptcp_capability = lambda: {"supported": True}
            agent.panel_clock_state_from_headers = lambda headers: {"status": "ok"}

            captured_heartbeats = []

            def fake_http_json(method, url, token, payload=None):
                if url == "https://panel.example/api/client/heartbeat":
                    captured_heartbeats.append(dict(payload))
                return {"ok": True}

            with mock.patch.object(client_agent_module.shutil, "which", return_value="/usr/sbin/nft"), \
                 mock.patch.object(client_agent_module, "http_json_with_headers", return_value=(self.sample_payload(), {"date": "Wed, 22 Jul 2026 00:00:00 GMT"})), \
                 mock.patch.object(client_agent_module, "http_json", side_effect=fake_http_json):
                agent.sync_once()

            heartbeat = captured_heartbeats[0]
            self.assertTrue(heartbeat["supports_route_direction_rules"])
            self.assertTrue(heartbeat["supports_exact_destination_rules"])
            self.assertEqual(1, heartbeat["route_rule_summary"]["client_rules_total"])
            self.assertEqual("noop", heartbeat["route_rule_summary"]["status"])

            pull_state = json.loads(agent.pull_state_path.read_text(encoding="utf-8"))
            self.assertEqual(1, pull_state["route_rule_summary"]["client_rules_total"])
            self.assertEqual("noop", pull_state["route_rule_summary"]["status"])
            self.assertEqual(1, pull_state["route_directions_total"])
            self.assertEqual(1, pull_state["client_rules_total"])
```

- [ ] **Step 2: Run tests to verify they fail for missing capability integration**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: FAIL with `AttributeError: 'ClientAgent' object has no attribute 'route_rule_capability_fields'`.

- [ ] **Step 3: Add capability fields method**

In `client_agent.py`, add this method immediately after `apply_route_direction_rules`:

```python
    def route_rule_capability_fields(self) -> dict:
        return {
            "supports_route_direction_rules": True,
            "supports_exact_destination_rules": shutil.which("nft") is not None,
        }
```

- [ ] **Step 4: Parse route rules at the start of `sync_once`**

In `sync_once`, replace this block:

```python
        payload, response_headers = http_json_with_headers("GET", f"{master}/api/client/configs/pull", token)
        forwards = payload.get("forwards", [])
        self.latest_forwards = [forward for forward in forwards if isinstance(forward, dict)]
        client_meta = payload.get("client", {})
```

with:

```python
        payload, response_headers = http_json_with_headers("GET", f"{master}/api/client/configs/pull", token)
        route_rule_snapshot = self.parse_route_direction_rule_payload(payload)
        route_rule_summary = self.apply_route_direction_rules(route_rule_snapshot)
        forwards = payload.get("forwards", [])
        self.latest_forwards = [forward for forward in forwards if isinstance(forward, dict)]
        client_meta = payload.get("client", {})
```

- [ ] **Step 5: Add route-rule capability fields and summary to heartbeat**

In `sync_once`, replace the existing `http_json("POST", f"{master}/api/client/heartbeat", token, {...})` block with this exact block:

```python
        heartbeat_payload = {
            "agent_version": AGENT_VERSION,
            "usage_bytes_in": usage_totals["bytes_in"],
            "usage_bytes_out": usage_totals["bytes_out"],
            "supports_mptcp": self.running_kernel_supports_mptcp(),
            "mptcp_capability": self.mptcp_capability(),
            "kernel_release": platform.release(),
            "installer_version": str(self.config.get("installer_version", "") or ""),
            "installer_sha256": str(self.config.get("installer_sha256", "") or ""),
            "install_forward_runtime_sha256": file_sha256(self.install_forward_runtime_path()),
            "mptcp_client_sha256": file_sha256(self.mptcp_client_path()),
            "cfwg_watchdog_sha256": file_sha256(cfwg_watchdog_path) if cfwg_watchdog_path.is_file() else "",
            "wireguard_public_key": wireguard_public_key,
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            "clock_sync": local_clock,
            "bond_leg_reputation": {
                "forwards": self.load_bond_leg_reputation_snapshots(),
            },
            "route_rule_summary": route_rule_summary,
        }
        heartbeat_payload.update(self.route_rule_capability_fields())
        http_json(
            "POST",
            f"{master}/api/client/heartbeat",
            token,
            heartbeat_payload,
        )
```

- [ ] **Step 6: Add route-rule summary to pull-state observability**

In the dictionary passed to `self.save_pull_state(...)` near the end of `sync_once`, add these entries after `inactive_forward_ids`:

```python
                "route_rule_summary": route_rule_summary,
                "route_directions_total": int(route_rule_summary.get("directions_total", 0) or 0),
                "client_rules_total": int(route_rule_summary.get("client_rules_total", 0) or 0),
                "direction_runtime_bindings_total": int(route_rule_summary.get("bindings_total", 0) or 0),
```

- [ ] **Step 7: Run tests to verify they pass**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: PASS with 6 tests passing.

- [ ] **Step 8: Commit**

Run:

```powershell
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" add "client-agent/client_agent.py" "client-agent/tests/test_route_direction_rules.py"
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" commit -m @'
feat(client-agent): advertise route rule capability

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

Expected: commit succeeds.

### Task 3: Route-rule runtime planning, TCP+UDP readiness, and deterministic connector ports

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_route_direction_rules.py`

- [ ] **Step 1: Add failing route-rule planning tests**

Add these methods inside `RouteDirectionRuleTests` before the `if __name__ == "__main__":` block:

```python
    def test_build_route_direction_rule_plan_dispatches_active_rule_when_binding_and_tcp_udp_listener_are_ready(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (True, 1.2, None)
        snapshot = agent.parse_route_direction_rule_payload(self.sample_payload())

        plan = agent.build_route_direction_rule_plan(snapshot)

        self.assertEqual(["rule_1"], plan["summary"]["installed_rule_ids"])
        self.assertEqual([], plan["summary"]["blocked_rule_ids"])
        dispatch = plan["dispatch_rules"][0]
        self.assertEqual("rule_1", dispatch["rule_id"])
        self.assertEqual("93.184.216.34", dispatch["destination_ip"])
        self.assertEqual(443, dispatch["destination_port"])
        self.assertEqual("127.0.0.1", dispatch["onward_host"])
        self.assertEqual(11001, dispatch["onward_tcp_port"])
        self.assertEqual(11001, dispatch["onward_udp_port"])
        self.assertEqual(18000, dispatch["connector_tcp_port"])
        self.assertEqual(18001, dispatch["connector_udp_port"])
        self.assertEqual("0x6c77", dispatch["fwmark"])
        self.assertEqual(27767, dispatch["fwmark_int"])
        self.assertTrue(dispatch["socket_mark_required"])

    def test_build_route_direction_rule_plan_assigns_zero_based_connector_ports_without_off_by_one(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (True, 1.2, None)
        payload = self.sample_payload()
        payload["client_rules"].append(
            {
                "id": "rule_2",
                "service_scope_id": "svc_scope_1001",
                "service_id": 1001,
                "subscription_id": 501,
                "destination_ip": "8.8.8.8",
                "destination_port": 53,
                "protocol": "tcp_udp",
                "direction_id": "dir_sh_jp_1",
                "enabled": True,
                "effective_state": "active",
                "sync_version": 18,
            }
        )
        snapshot = agent.parse_route_direction_rule_payload(payload)

        plan = agent.build_route_direction_rule_plan(snapshot)

        by_id = {rule["rule_id"]: rule for rule in plan["dispatch_rules"]}
        self.assertEqual(18000, by_id["rule_1"]["connector_tcp_port"])
        self.assertEqual(18001, by_id["rule_1"]["connector_udp_port"])
        self.assertEqual(18002, by_id["rule_2"]["connector_tcp_port"])
        self.assertEqual(18003, by_id["rule_2"]["connector_udp_port"])
        self.assertNotIn("tcp_port", by_id["rule_1"], "dispatch plan must not expose ambiguous direct redirect ports")
        self.assertNotIn("udp_port", by_id["rule_1"], "dispatch plan must not expose ambiguous direct redirect ports")

    def test_build_route_direction_rule_plan_blocks_enabled_non_active_rule(self) -> None:
        agent = self.make_agent()
        payload = self.sample_payload()
        payload["client_rules"][0]["effective_state"] = "not_allowed_by_plan"
        snapshot = agent.parse_route_direction_rule_payload(payload)

        plan = agent.build_route_direction_rule_plan(snapshot)

        self.assertEqual([], plan["dispatch_rules"])
        self.assertEqual(["rule_1"], plan["summary"]["blocked_rule_ids"])
        block = plan["block_rules"][0]
        self.assertEqual("rule_1", block["rule_id"])
        self.assertEqual("not_allowed_by_plan", block["reason"])
        self.assertEqual("93.184.216.34", block["destination_ip"])
        self.assertEqual(443, block["destination_port"])

    def test_build_route_direction_rule_plan_blocks_active_rule_when_udp_listener_is_not_ready(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (False, None, "udp listener probe failed: listener_not_found")
        snapshot = agent.parse_route_direction_rule_payload(self.sample_payload())

        plan = agent.build_route_direction_rule_plan(snapshot)

        self.assertEqual([], plan["dispatch_rules"])
        self.assertEqual(["rule_1"], plan["summary"]["blocked_rule_ids"])
        self.assertEqual("listener_not_ready", plan["block_rules"][0]["reason"])
        self.assertEqual("udp listener probe failed: listener_not_found", plan["block_rules"][0]["detail"])
```

- [ ] **Step 2: Run tests to verify they fail for missing planner**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: FAIL with `AttributeError: 'ClientAgent' object has no attribute 'build_route_direction_rule_plan'`.

- [ ] **Step 3: Add route-rule planning constants**

In `client_agent.py`, extend the route-rule constants block to exactly:

```python
ROUTE_RULE_ACTIVE_STATE = "active"
ROUTE_RULE_READY_STATE = "ready"
ROUTE_RULE_PROTOCOL = "tcp_udp"
ROUTE_RULE_FWMARK = "0x6c77"
ROUTE_RULE_FWMARK_INT = int(ROUTE_RULE_FWMARK, 16)
ROUTE_RULE_CONNECTOR_PORT_BASE = 18000
ROUTE_RULE_CONNECTOR_PORT_STRIDE = 2
```

- [ ] **Step 4: Add route-rule planning helpers**

In `client_agent.py`, add these methods after `route_rule_capability_fields`:

```python
    @staticmethod
    def route_rule_text(value: object) -> str:
        return str(value or "").strip()

    @staticmethod
    def route_rule_int(value: object) -> int:
        try:
            return int(value)
        except (TypeError, ValueError):
            return 0

    @staticmethod
    def route_rule_enabled(rule: dict) -> bool:
        value = rule.get("enabled")
        if value is True:
            return True
        if value is False or value is None:
            return False
        return str(value).strip().lower() in {"1", "true", "yes", "on"}

    @staticmethod
    def route_rule_valid_ipv4(value: object) -> str:
        candidate = str(value or "").strip()
        try:
            address = ipaddress.ip_address(candidate)
        except ValueError:
            return ""
        return candidate if address.version == 4 and str(address) == candidate else ""

    @staticmethod
    def route_rule_port(value: object) -> int:
        try:
            port = int(value)
        except (TypeError, ValueError):
            return 0
        return port if 1 <= port <= 65535 else 0

    @staticmethod
    def route_rule_binding_key(service_scope_id: object, direction_id: object) -> str:
        return f"{str(service_scope_id or '').strip()}\x1f{str(direction_id or '').strip()}"

    @staticmethod
    def route_rule_local_endpoint(binding: dict) -> dict:
        endpoint = binding.get("local_endpoint", {})
        return endpoint if isinstance(endpoint, dict) else {}

    def route_rule_binding_listener_ready(self, binding: dict) -> tuple[bool, str]:
        endpoint = self.route_rule_local_endpoint(binding)
        host = self.route_rule_text(endpoint.get("host"))
        tcp_port = self.route_rule_port(endpoint.get("tcp_port"))
        udp_port = self.route_rule_port(endpoint.get("udp_port"))
        if host != "127.0.0.1" or tcp_port <= 0 or udp_port <= 0:
            return False, "local_endpoint_invalid"
        if tcp_port == udp_port:
            ok, _latency_ms, error = self.local_runtime_probe(tcp_port, "tcp+udp")
            return ok, "" if ok else str(error or "listener_not_ready")
        tcp_ok, _tcp_latency, tcp_error = self.local_forward_probe(tcp_port)
        if not tcp_ok:
            return False, str(tcp_error or "tcp_listener_not_ready")
        udp_ok, _udp_latency, udp_error = self.local_listener_present(udp_port, "udp")
        if not udp_ok:
            return False, str(udp_error or "udp_listener_not_ready")
        return True, ""

    @staticmethod
    def route_rule_connector_ports(index: int) -> tuple[int, int]:
        tcp_port = ROUTE_RULE_CONNECTOR_PORT_BASE + (int(index) * ROUTE_RULE_CONNECTOR_PORT_STRIDE)
        udp_port = tcp_port + 1
        return tcp_port, udp_port
```

- [ ] **Step 5: Add `build_route_direction_rule_plan`**

In `client_agent.py`, add this method immediately after `route_rule_binding_listener_ready`:

```python
    def build_route_direction_rule_plan(self, snapshot: dict) -> dict:
        bindings_by_key: dict[str, dict] = {}
        for binding in snapshot.get("direction_runtime_bindings", []):
            if not isinstance(binding, dict):
                continue
            key = self.route_rule_binding_key(binding.get("service_scope_id"), binding.get("direction_id"))
            bindings_by_key[key] = binding

        dispatch_rules: list[dict] = []
        block_rules: list[dict] = []
        ignored_rule_ids: list[str] = []
        invalid_rule_ids: list[str] = []
        dispatch_index = 0

        for rule in sorted(snapshot.get("client_rules", []), key=lambda item: str(item.get("id", "")) if isinstance(item, dict) else ""):
            if not isinstance(rule, dict):
                continue
            rule_id = self.route_rule_text(rule.get("id"))
            if rule_id == "":
                continue
            destination_ip = self.route_rule_valid_ipv4(rule.get("destination_ip"))
            destination_port = self.route_rule_port(rule.get("destination_port"))
            if not self.route_rule_enabled(rule):
                ignored_rule_ids.append(rule_id)
                continue
            if destination_ip == "" or destination_port <= 0:
                invalid_rule_ids.append(rule_id)
                continue

            effective_state = self.route_rule_text(rule.get("effective_state")).lower() or "runtime_missing"
            base_rule = {
                "rule_id": rule_id,
                "service_scope_id": self.route_rule_text(rule.get("service_scope_id")),
                "direction_id": self.route_rule_text(rule.get("direction_id")),
                "destination_ip": destination_ip,
                "destination_port": destination_port,
                "protocol": ROUTE_RULE_PROTOCOL,
            }
            if effective_state != ROUTE_RULE_ACTIVE_STATE:
                block_rules.append({**base_rule, "reason": effective_state, "detail": "server_effective_state"})
                continue

            binding = bindings_by_key.get(self.route_rule_binding_key(rule.get("service_scope_id"), rule.get("direction_id")))
            if binding is None:
                block_rules.append({**base_rule, "reason": "runtime_missing", "detail": "binding_missing"})
                continue
            endpoint = self.route_rule_local_endpoint(binding)
            onward_host = self.route_rule_text(endpoint.get("host"))
            onward_tcp_port = self.route_rule_port(endpoint.get("tcp_port"))
            onward_udp_port = self.route_rule_port(endpoint.get("udp_port"))
            binding_ready = (
                self.route_rule_text(binding.get("binding_state")).lower() == ROUTE_RULE_READY_STATE
                and bool(binding.get("tcp_ready"))
                and bool(binding.get("udp_ready"))
            )
            if not binding_ready:
                block_rules.append({**base_rule, "reason": "runtime_missing", "detail": "binding_not_ready"})
                continue
            listener_ready, listener_detail = self.route_rule_binding_listener_ready(binding)
            if not listener_ready:
                block_rules.append({**base_rule, "reason": "listener_not_ready", "detail": listener_detail})
                continue
            connector_tcp_port, connector_udp_port = self.route_rule_connector_ports(dispatch_index)
            dispatch_index += 1
            dispatch_rules.append(
                {
                    **base_rule,
                    "connector_tcp_port": connector_tcp_port,
                    "connector_udp_port": connector_udp_port,
                    "onward_host": onward_host,
                    "onward_tcp_port": onward_tcp_port,
                    "onward_udp_port": onward_udp_port,
                    "fwmark": ROUTE_RULE_FWMARK,
                    "fwmark_int": ROUTE_RULE_FWMARK_INT,
                    "socket_mark_required": True,
                    "client_runtime_binding_id": self.route_rule_text(binding.get("client_runtime_binding_id")),
                }
            )

        summary = dict(snapshot.get("summary", {})) if isinstance(snapshot.get("summary"), dict) else {}
        summary.update(
            {
                "installed_rule_ids": [rule["rule_id"] for rule in dispatch_rules],
                "blocked_rule_ids": [rule["rule_id"] for rule in block_rules],
                "ignored_rule_ids": ignored_rule_ids,
                "invalid_rule_ids": invalid_rule_ids,
                "active_dispatch_rules": len(dispatch_rules),
                "fail_closed_rules": len(block_rules),
            }
        )
        return {
            "dispatch_rules": dispatch_rules,
            "block_rules": block_rules,
            "ignored_rule_ids": ignored_rule_ids,
            "invalid_rule_ids": invalid_rule_ids,
            "summary": summary,
        }
```

- [ ] **Step 6: Run tests to verify they pass**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: PASS with 9 tests passing.

- [ ] **Step 7: Commit**

Run:

```powershell
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" add "client-agent/client_agent.py" "client-agent/tests/test_route_direction_rules.py"
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" commit -m @'
feat(client-agent): plan route direction rule runtime state

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

Expected: commit succeeds.

### Task 4: Connector rendering, nftables redirects to connector ports, and fwmark loop-prevention ordering

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_route_direction_rules.py`

- [ ] **Step 1: Add failing connector, nftables rendering, SO_MARK, UDP association, and stale-cleanup parser tests**

Add these methods inside `RouteDirectionRuleTests` before the `if __name__ == "__main__":` block:

```python
    def test_route_rule_nft_redirects_have_mark_bypass_before_exact_destination_rules(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (True, 1.2, None)
        plan = agent.build_route_direction_rule_plan(agent.parse_route_direction_rule_payload(self.sample_payload()))

        commands = agent.render_route_rule_nft_add_commands(plan)

        bypass_index = next(index for index, command in enumerate(commands) if "meta mark 0x6c77 return" in command)
        tcp_redirect_index = next(index for index, command in enumerate(commands) if "tcp dport 443 redirect to :18000" in command)
        udp_redirect_index = next(index for index, command in enumerate(commands) if "udp dport 443 redirect to :18001" in command)
        self.assertLess(bypass_index, tcp_redirect_index)
        self.assertLess(bypass_index, udp_redirect_index)
        self.assertIn('comment "lw_route_rule_rule_1_redirect_tcp"', commands[tcp_redirect_index])
        self.assertIn('comment "lw_route_rule_rule_1_redirect_udp"', commands[udp_redirect_index])
        self.assertFalse(any("redirect to :11001" in command for command in commands), "nft must redirect to connector ports, not binding local_endpoint ports")

    def test_render_route_rule_connector_specs_use_binding_local_endpoint_not_original_destination(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (True, 1.2, None)
        plan = agent.build_route_direction_rule_plan(agent.parse_route_direction_rule_payload(self.sample_payload()))

        specs = agent.render_route_rule_connector_specs(plan)

        self.assertEqual(2, len(specs))
        tcp_spec = next(spec for spec in specs if spec["protocol"] == "tcp")
        udp_spec = next(spec for spec in specs if spec["protocol"] == "udp")
        self.assertEqual("leikwan-route-rule-rule_1-tcp.service", tcp_spec["unit_name"])
        self.assertEqual("leikwan-route-rule-rule_1-udp.service", udp_spec["unit_name"])
        self.assertEqual(18000, tcp_spec["listen_port"])
        self.assertEqual(18001, udp_spec["listen_port"])
        self.assertEqual("127.0.0.1", tcp_spec["onward_host"])
        self.assertEqual(11001, tcp_spec["onward_port"])
        self.assertEqual("127.0.0.1", udp_spec["onward_host"])
        self.assertEqual(11001, udp_spec["onward_port"])
        self.assertNotEqual("93.184.216.34", tcp_spec["onward_host"])
        self.assertNotEqual(443, tcp_spec["onward_port"])
        self.assertEqual(27767, tcp_spec["fwmark_int"])
        self.assertEqual(27767, udp_spec["fwmark_int"])

    def test_rendered_connector_script_sets_so_mark_before_connecting_to_binding_endpoint(self) -> None:
        agent = self.make_agent()
        spec = {
            "protocol": "tcp",
            "listen_host": "127.0.0.1",
            "listen_port": 18000,
            "onward_host": "127.0.0.1",
            "onward_port": 11001,
            "fwmark_int": 27767,
        }

        script = agent.render_route_rule_connector_script(spec)

        setsockopt_index = script.index("setsockopt(socket.SOL_SOCKET, socket.SO_MARK, ROUTE_RULE_FWMARK_INT)")
        connect_index = script.index("connect((ONWARD_HOST, ONWARD_PORT))")
        self.assertLess(setsockopt_index, connect_index)
        self.assertIn("ROUTE_RULE_FWMARK_INT = 27767", script)
        self.assertIn("ONWARD_HOST = '127.0.0.1'", script)
        self.assertIn("ONWARD_PORT = 11001", script)
        self.assertNotIn("93.184.216.34", script)
        self.assertNotIn("443", script)

    def test_rendered_udp_connector_documents_association_and_replies_to_client_socket(self) -> None:
        agent = self.make_agent()
        spec = {
            "protocol": "udp",
            "listen_host": "127.0.0.1",
            "listen_port": 18001,
            "onward_host": "127.0.0.1",
            "onward_port": 11001,
            "fwmark_int": 27767,
        }

        script = agent.render_route_rule_connector_script(spec)

        self.assertIn("client_addr", script)
        self.assertIn("recvfrom", script)
        self.assertIn("onward.send(data)", script)
        self.assertIn("listener.sendto(reply, client_addr)", script)
        self.assertIn("setsockopt(socket.SOL_SOCKET, socket.SO_MARK, ROUTE_RULE_FWMARK_INT)", script)
        self.assertLess(
            script.index("setsockopt(socket.SOL_SOCKET, socket.SO_MARK, ROUTE_RULE_FWMARK_INT)"),
            script.index("connect((ONWARD_HOST, ONWARD_PORT))"),
        )

    def test_route_rule_nft_block_rules_cover_tcp_and_udp_for_enabled_non_active_rule(self) -> None:
        agent = self.make_agent()
        payload = self.sample_payload()
        payload["client_rules"][0]["effective_state"] = "unavailable"
        plan = agent.build_route_direction_rule_plan(agent.parse_route_direction_rule_payload(payload))

        commands = agent.render_route_rule_nft_add_commands(plan)

        self.assertTrue(any("rr_output_filter ip daddr 93.184.216.34 tcp dport 443 reject with tcp reset" in command for command in commands))
        self.assertTrue(any("rr_output_filter ip daddr 93.184.216.34 udp dport 443 drop" in command for command in commands))
        self.assertFalse(any("redirect to" in command for command in commands if "rule_1" in command))

    def test_route_rule_delete_commands_remove_only_managed_route_rule_comments(self) -> None:
        agent = self.make_agent()
        nft_stdout = '''
        table inet leikwan_route_rules {
            chain rr_output_nat {
                type nat hook output priority -100; policy accept;
                meta mark 0x6c77 return comment "lw_route_rule_bypass_mark" # handle 9
                ip daddr 93.184.216.34 tcp dport 443 redirect to :11001 comment "lw_route_rule_rule_old_redirect_tcp" # handle 10
                ip daddr 1.1.1.1 tcp dport 443 counter comment "operator_rule_not_owned" # handle 11
            }
            chain rr_output_filter {
                type filter hook output priority 0; policy accept;
                ip daddr 8.8.8.8 udp dport 443 drop comment "lw_route_rule_rule_old_block_udp" # handle 12
            }
        }
        '''

        commands = agent.route_rule_delete_commands_from_nft_list(nft_stdout)

        self.assertEqual(
            [
                "nft delete rule inet leikwan_route_rules rr_output_nat handle 9",
                "nft delete rule inet leikwan_route_rules rr_output_nat handle 10",
                "nft delete rule inet leikwan_route_rules rr_output_filter handle 12",
            ],
            commands,
        )
```

- [ ] **Step 2: Run tests to verify they fail for missing nft helpers**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: FAIL with `AttributeError: 'ClientAgent' object has no attribute 'render_route_rule_nft_add_commands'`.

- [ ] **Step 3: Add nftables route-rule constants**

In `client_agent.py`, add these constants immediately after `ROUTE_RULE_FWMARK_INT`:

```python
ROUTE_RULE_NFT_TABLE = "leikwan_route_rules"
ROUTE_RULE_NFT_NAT_CHAIN = "rr_output_nat"
ROUTE_RULE_NFT_FILTER_CHAIN = "rr_output_filter"
ROUTE_RULE_COMMENT_PREFIX = "lw_route_rule"
ROUTE_RULE_CONNECTOR_SERVICE_PREFIX = "leikwan-route-rule"
```

- [ ] **Step 4: Add connector spec and script rendering helpers**

In `client_agent.py`, add these methods immediately after `build_route_direction_rule_plan`:

```python
    @staticmethod
    def route_rule_comment_token(value: object) -> str:
        token = re.sub(r"[^A-Za-z0-9_.:-]+", "_", str(value or "").strip())
        return token.strip("_") or "unnamed"

    def render_route_rule_connector_specs(self, plan: dict) -> list[dict]:
        specs: list[dict] = []
        for rule in plan.get("dispatch_rules", []):
            rule_token = self.route_rule_comment_token(rule["rule_id"])
            specs.append(
                {
                    "rule_id": rule["rule_id"],
                    "connector_id": f"{rule_token}-tcp",
                    "protocol": "tcp",
                    "unit_name": f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-{rule_token}-tcp.service",
                    "script_name": f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-{rule_token}-tcp.py",
                    "state_name": f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-{rule_token}-tcp.json",
                    "listen_host": "127.0.0.1",
                    "listen_port": int(rule["connector_tcp_port"]),
                    "onward_host": rule["onward_host"],
                    "onward_port": int(rule["onward_tcp_port"]),
                    "fwmark_int": int(rule["fwmark_int"]),
                }
            )
            specs.append(
                {
                    "rule_id": rule["rule_id"],
                    "connector_id": f"{rule_token}-udp",
                    "protocol": "udp",
                    "unit_name": f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-{rule_token}-udp.service",
                    "script_name": f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-{rule_token}-udp.py",
                    "state_name": f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-{rule_token}-udp.json",
                    "listen_host": "127.0.0.1",
                    "listen_port": int(rule["connector_udp_port"]),
                    "onward_host": rule["onward_host"],
                    "onward_port": int(rule["onward_udp_port"]),
                    "fwmark_int": int(rule["fwmark_int"]),
                }
            )
        return specs

    def render_route_rule_connector_script(self, spec: dict) -> str:
        protocol = str(spec["protocol"])
        if protocol == "tcp":
            body = """
def relay_tcp(client):
    onward = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    onward.setsockopt(socket.SOL_SOCKET, socket.SO_MARK, ROUTE_RULE_FWMARK_INT)
    onward.connect((ONWARD_HOST, ONWARD_PORT))
    sockets = [client, onward]
    try:
        while True:
            readable, _, _ = select.select(sockets, [], [], 60)
            if not readable:
                continue
            for sock in readable:
                data = sock.recv(65536)
                if not data:
                    return
                (onward if sock is client else client).sendall(data)
    finally:
        client.close()
        onward.close()


def main():
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    listener.bind((LISTEN_HOST, LISTEN_PORT))
    listener.listen(256)
    while True:
        client, _client_addr = listener.accept()
        threading.Thread(target=relay_tcp, args=(client,), daemon=True).start()
"""
        elif protocol == "udp":
            body = """
def main():
    listener = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    listener.bind((LISTEN_HOST, LISTEN_PORT))
    associations = {}
    while True:
        data, client_addr = listener.recvfrom(65536)
        onward = associations.get(client_addr)
        if onward is None:
            onward = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            onward.setsockopt(socket.SOL_SOCKET, socket.SO_MARK, ROUTE_RULE_FWMARK_INT)
            onward.connect((ONWARD_HOST, ONWARD_PORT))
            onward.settimeout(0.001)
            associations[client_addr] = onward
        onward.send(data)
        while True:
            try:
                reply = onward.recv(65536)
            except socket.timeout:
                break
            listener.sendto(reply, client_addr)
"""
        else:
            raise ValueError(f"unsupported route-rule connector protocol: {protocol}")
        return f'''#!/usr/bin/env python3
import select
import socket
import threading

LISTEN_HOST = {spec["listen_host"]!r}
LISTEN_PORT = {int(spec["listen_port"])}
ONWARD_HOST = {spec["onward_host"]!r}
ONWARD_PORT = {int(spec["onward_port"])}
ROUTE_RULE_FWMARK_INT = {int(spec["fwmark_int"])}
{body}

if __name__ == "__main__":
    main()
'''
```

These helpers make loop prevention executable instead of prose: the rendered TCP and UDP scripts both set `SO_MARK` to `ROUTE_RULE_FWMARK_INT` before `connect((ONWARD_HOST, ONWARD_PORT))`, and `ONWARD_HOST`/`ONWARD_PORT` are the binding local endpoint fields, not the original destination.

- [ ] **Step 5: Add nftables comment and command rendering helpers**

In `client_agent.py`, add these methods immediately after `render_route_rule_connector_script`:

```python
    def route_rule_managed_comment(self, rule_id: object, action: str, protocol: str) -> str:
        return f"{ROUTE_RULE_COMMENT_PREFIX}_{self.route_rule_comment_token(rule_id)}_{action}_{protocol}"

    def route_rule_nft_ensure_commands(self) -> list[str]:
        return [
            f"nft list table inet {ROUTE_RULE_NFT_TABLE} >/dev/null 2>&1 || nft add table inet {ROUTE_RULE_NFT_TABLE}",
            f"nft list chain inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_NAT_CHAIN} >/dev/null 2>&1 || nft add chain inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_NAT_CHAIN} '{{ type nat hook output priority -100; policy accept; }}'",
            f"nft list chain inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_FILTER_CHAIN} >/dev/null 2>&1 || nft add chain inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_FILTER_CHAIN} '{{ type filter hook output priority 0; policy accept; }}'",
        ]

    def route_rule_nft_bypass_command(self) -> str:
        return f'nft add rule inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_NAT_CHAIN} meta mark {ROUTE_RULE_FWMARK} return comment "{ROUTE_RULE_COMMENT_PREFIX}_bypass_mark"'

    def route_rule_nft_redirect_commands(self, rule: dict) -> list[str]:
        rule_id = rule["rule_id"]
        destination_ip = rule["destination_ip"]
        destination_port = int(rule["destination_port"])
        connector_tcp_port = int(rule["connector_tcp_port"])
        connector_udp_port = int(rule["connector_udp_port"])
        return [
            f'nft add rule inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_NAT_CHAIN} ip daddr {destination_ip} tcp dport {destination_port} redirect to :{connector_tcp_port} comment "{self.route_rule_managed_comment(rule_id, "redirect", "tcp")}"',
            f'nft add rule inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_NAT_CHAIN} ip daddr {destination_ip} udp dport {destination_port} redirect to :{connector_udp_port} comment "{self.route_rule_managed_comment(rule_id, "redirect", "udp")}"',
        ]

    def route_rule_nft_block_commands(self, rule: dict) -> list[str]:
        rule_id = rule["rule_id"]
        destination_ip = rule["destination_ip"]
        destination_port = int(rule["destination_port"])
        return [
            f'nft add rule inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_FILTER_CHAIN} ip daddr {destination_ip} tcp dport {destination_port} reject with tcp reset comment "{self.route_rule_managed_comment(rule_id, "block", "tcp")}"',
            f'nft add rule inet {ROUTE_RULE_NFT_TABLE} {ROUTE_RULE_NFT_FILTER_CHAIN} ip daddr {destination_ip} udp dport {destination_port} drop comment "{self.route_rule_managed_comment(rule_id, "block", "udp")}"',
        ]

    def render_route_rule_nft_add_commands(self, plan: dict) -> list[str]:
        commands = self.route_rule_nft_ensure_commands()
        commands.append(self.route_rule_nft_bypass_command())
        for rule in plan.get("dispatch_rules", []):
            commands.extend(self.route_rule_nft_redirect_commands(rule))
        for rule in plan.get("block_rules", []):
            commands.extend(self.route_rule_nft_block_commands(rule))
        return commands
```

- [ ] **Step 6: Add stale managed-rule delete parser**

In `client_agent.py`, add this method immediately after `render_route_rule_nft_add_commands`:

```python
    def route_rule_delete_commands_from_nft_list(self, nft_stdout: str) -> list[str]:
        commands: list[str] = []
        current_chain = ""
        for line in str(nft_stdout or "").splitlines():
            stripped = line.strip()
            chain_match = re.match(r"chain\s+(\w+)", stripped)
            if chain_match:
                current_chain = chain_match.group(1)
                continue
            if f'comment "{ROUTE_RULE_COMMENT_PREFIX}_' not in stripped:
                continue
            handle_match = re.search(r"# handle (\d+)", stripped)
            if handle_match and current_chain:
                commands.append(f"nft delete rule inet {ROUTE_RULE_NFT_TABLE} {current_chain} handle {handle_match.group(1)}")
        return commands
```

- [ ] **Step 7: Run tests to verify they pass**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: PASS with 16 tests passing.

- [ ] **Step 8: Commit**

Run:

```powershell
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" add "client-agent/client_agent.py" "client-agent/tests/test_route_direction_rules.py"
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" commit -m @'
feat(client-agent): render exact destination nft rules

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

Expected: commit succeeds.

### Task 5: Clean stale connectors before current connector start and nftables route rules

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_route_direction_rules.py`

- [ ] **Step 1: Add failing apply tests for stale connector cleanup, current connector start-before-nft ordering, dispatch, block, and removal/reorder cleanup**

Add these methods inside `RouteDirectionRuleTests` before the `if __name__ == "__main__":` block:

```python
    def test_render_route_rule_connector_unit_points_to_rendered_connector_script(self) -> None:
        agent = self.make_agent()
        script_path = pathlib.Path("/opt/leikwan/route-rule-connectors/leikwan-route-rule-rule_1-tcp.py")
        spec = {"protocol": "tcp", "rule_id": "rule_1"}

        unit = agent.render_route_rule_connector_unit(spec, script_path)

        self.assertIn("Description=LeiKwan route-rule tcp connector for rule_1", unit)
        self.assertIn(f"ExecStart=/usr/bin/env python3 {script_path}", unit)
        self.assertIn("Restart=always", unit)
        self.assertIn("WantedBy=multi-user.target", unit)

    def test_discover_current_and_stale_route_rule_connector_artifacts_from_desired_plan(self) -> None:
        agent = self.make_agent()
        desired_specs = [
            {
                "connector_id": "rule_1-tcp",
                "unit_name": "leikwan-route-rule-rule_1-tcp.service",
                "script_name": "leikwan-route-rule-rule_1-tcp.py",
                "state_name": "leikwan-route-rule-rule_1-tcp.json",
                "listen_port": 18000,
            },
            {
                "connector_id": "rule_1-udp",
                "unit_name": "leikwan-route-rule-rule_1-udp.service",
                "script_name": "leikwan-route-rule-rule_1-udp.py",
                "state_name": "leikwan-route-rule-rule_1-udp.json",
                "listen_port": 18001,
            },
        ]
        discovered_units = [
            "leikwan-route-rule-rule_1-tcp.service",
            "leikwan-route-rule-rule_1-udp.service",
            "leikwan-route-rule-old-tcp.service",
            "unrelated.service",
        ]
        discovered_scripts = [
            pathlib.Path("/opt/leikwan/route-rule-connectors/leikwan-route-rule-rule_1-tcp.py"),
            pathlib.Path("/opt/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.py"),
            pathlib.Path("/opt/leikwan/route-rule-connectors/manual.py"),
        ]
        discovered_state = [
            pathlib.Path("/run/leikwan/route-rule-connectors/leikwan-route-rule-rule_1-tcp.json"),
            pathlib.Path("/run/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.json"),
            pathlib.Path("/run/leikwan/route-rule-connectors/manual.json"),
        ]

        current, stale = agent.classify_route_rule_connector_artifacts(
            desired_specs,
            discovered_units,
            discovered_scripts,
            discovered_state,
        )

        self.assertEqual(
            ["leikwan-route-rule-rule_1-tcp.service", "leikwan-route-rule-rule_1-udp.service"],
            current["units"],
        )
        self.assertEqual(["leikwan-route-rule-old-tcp.service"], stale["units"])
        self.assertEqual(
            [pathlib.Path("/opt/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.py")],
            stale["scripts"],
        )
        self.assertEqual(
            [pathlib.Path("/run/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.json")],
            stale["state_files"],
        )
        self.assertNotIn(pathlib.Path("/opt/leikwan/route-rule-connectors/manual.py"), stale["scripts"])
        self.assertNotIn(pathlib.Path("/run/leikwan/route-rule-connectors/manual.json"), stale["state_files"])

    def test_cleanup_stale_route_rule_connectors_stops_disables_removes_units_scripts_and_state_files(self) -> None:
        agent = self.make_agent()
        stale = {
            "units": ["leikwan-route-rule-old-tcp.service", "leikwan-route-rule-old-udp.service"],
            "scripts": [pathlib.Path("/opt/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.py")],
            "state_files": [pathlib.Path("/run/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.json")],
        }
        commands = []
        removed = []

        def fake_run(command, shell=True, text=True, capture_output=True, check=False):
            commands.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

        with mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run), \
             mock.patch.object(client_agent_module.pathlib.Path, "unlink", lambda path, missing_ok=True: removed.append(path)):
            removed_summary = agent.cleanup_stale_route_rule_connectors(stale)

        self.assertEqual(
            [
                "systemctl stop leikwan-route-rule-old-tcp.service",
                "systemctl disable leikwan-route-rule-old-tcp.service",
                "rm -f /etc/systemd/system/leikwan-route-rule-old-tcp.service",
                "systemctl stop leikwan-route-rule-old-udp.service",
                "systemctl disable leikwan-route-rule-old-udp.service",
                "rm -f /etc/systemd/system/leikwan-route-rule-old-udp.service",
                "systemctl daemon-reload",
            ],
            commands,
        )
        self.assertEqual(stale["scripts"] + stale["state_files"], removed)
        self.assertEqual(2, removed_summary["stale_units_removed"])
        self.assertEqual(1, removed_summary["stale_scripts_removed"])
        self.assertEqual(1, removed_summary["stale_state_files_removed"])

    def test_apply_route_direction_rules_deletes_stale_connectors_before_current_start_and_nft_redirects(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (True, 1.2, None)
        snapshot = agent.parse_route_direction_rule_payload(self.sample_payload())
        commands = []

        def fake_discover(desired_specs):
            commands.append("discover-connectors")
            return {
                "current": {"units": [], "scripts": [], "state_files": []},
                "stale": {
                    "units": ["leikwan-route-rule-old-tcp.service"],
                    "scripts": [pathlib.Path("/opt/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.py")],
                    "state_files": [pathlib.Path("/run/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.json")],
                },
            }

        def fake_cleanup(stale):
            commands.append("systemctl stop leikwan-route-rule-old-tcp.service")
            commands.append("systemctl disable leikwan-route-rule-old-tcp.service")
            commands.append("rm -f /etc/systemd/system/leikwan-route-rule-old-tcp.service")
            commands.append("unlink /opt/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.py")
            commands.append("unlink /run/leikwan/route-rule-connectors/leikwan-route-rule-old-tcp.json")
            return {"stale_units_removed": 1, "stale_scripts_removed": 1, "stale_state_files_removed": 1}

        def fake_install(desired_specs):
            commands.append("render-install-current-connectors")
            return ["render-install-current-connectors"]

        def fake_start(desired_specs):
            start_commands = [
                "systemctl restart leikwan-route-rule-rule_1-tcp.service",
                "systemctl restart leikwan-route-rule-rule_1-udp.service",
            ]
            commands.extend(start_commands)
            return start_commands

        agent.discover_route_rule_connector_artifacts = fake_discover
        agent.cleanup_stale_route_rule_connectors = fake_cleanup
        agent.install_route_rule_connector_services = fake_install
        agent.start_route_rule_connector_services = fake_start

        def fake_run(command, shell=True, text=True, capture_output=False, check=False):
            commands.append(command)
            if command == "nft -a list table inet leikwan_route_rules":
                return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

        with mock.patch.object(client_agent_module.shutil, "which", return_value="/usr/sbin/nft"), \
             mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run):
            summary = agent.apply_route_direction_rules(snapshot)

        cleanup_index = commands.index("systemctl stop leikwan-route-rule-old-tcp.service")
        install_index = commands.index("render-install-current-connectors")
        start_tcp_index = commands.index("systemctl restart leikwan-route-rule-rule_1-tcp.service")
        redirect_index = next(index for index, command in enumerate(commands) if "rule_1_redirect_tcp" in command)
        self.assertLess(cleanup_index, install_index)
        self.assertLess(cleanup_index, start_tcp_index)
        self.assertLess(start_tcp_index, redirect_index)
        self.assertEqual(1, summary["stale_units_removed"])
        self.assertEqual(1, summary["stale_scripts_removed"])
        self.assertEqual(1, summary["stale_state_files_removed"])

    def test_apply_route_direction_rules_removes_reordered_connector_holding_reused_port_before_current_start(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (True, 1.2, None)
        payload = self.sample_payload()
        payload["client_rules"][0]["id"] = "rule_9"
        payload["client_rules"][0]["direction_id"] = "dir_sh_jp_1"
        payload["client_rules"].append(
            {
                "id": "rule_1",
                "service_scope_id": "svc_scope_1001",
                "service_id": 1001,
                "subscription_id": 501,
                "destination_ip": "8.8.8.8",
                "destination_port": 53,
                "protocol": "tcp_udp",
                "direction_id": "dir_sh_jp_1",
                "enabled": True,
                "effective_state": "active",
                "sync_version": 19,
            }
        )
        snapshot = agent.parse_route_direction_rule_payload(payload)
        commands = []

        def fake_discover(desired_specs):
            # rule_1 now owns index-0 port 18000 after reorder. The old rule_9 unit used to own
            # that same port and must be stopped before rule_1 starts, or new 8.8.8.8:53 traffic
            # could be routed to rule_9's stale binding endpoint.
            desired_by_unit = {spec["unit_name"]: spec for spec in desired_specs}
            self.assertEqual(18000, desired_by_unit["leikwan-route-rule-rule_1-tcp.service"]["listen_port"])
            return {
                "current": {"units": [], "scripts": [], "state_files": []},
                "stale": {
                    "units": ["leikwan-route-rule-rule_9-tcp.service"],
                    "scripts": [pathlib.Path("/opt/leikwan/route-rule-connectors/leikwan-route-rule-rule_9-tcp.py")],
                    "state_files": [pathlib.Path("/run/leikwan/route-rule-connectors/leikwan-route-rule-rule_9-tcp.json")],
                },
            }

        agent.discover_route_rule_connector_artifacts = fake_discover
        agent.cleanup_stale_route_rule_connectors = lambda stale: commands.append("systemctl stop leikwan-route-rule-rule_9-tcp.service") or {"stale_units_removed": 1, "stale_scripts_removed": 1, "stale_state_files_removed": 1}
        agent.install_route_rule_connector_services = lambda desired_specs: commands.append("render-install-current-connectors") or ["render-install-current-connectors"]
        agent.start_route_rule_connector_services = lambda desired_specs: commands.extend([f"systemctl restart {spec['unit_name']}" for spec in desired_specs]) or [f"systemctl restart {spec['unit_name']}" for spec in desired_specs]

        def fake_run(command, shell=True, text=True, capture_output=False, check=False):
            commands.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

        with mock.patch.object(client_agent_module.shutil, "which", return_value="/usr/sbin/nft"), \
             mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run):
            agent.apply_route_direction_rules(snapshot)

        stop_old_index = commands.index("systemctl stop leikwan-route-rule-rule_9-tcp.service")
        start_rule_1_index = commands.index("systemctl restart leikwan-route-rule-rule_1-tcp.service")
        redirect_rule_1_index = next(index for index, command in enumerate(commands) if "rule_1_redirect_tcp" in command)
        self.assertLess(stop_old_index, start_rule_1_index)
        self.assertLess(start_rule_1_index, redirect_rule_1_index)

    def test_apply_route_direction_rules_deletes_stale_then_installs_dispatch_and_block_rules(self) -> None:
        agent = self.make_agent()
        agent.local_runtime_probe = lambda listen_port, transport: (True, 1.2, None)
        payload = self.sample_payload()
        payload["client_rules"].append(
            {
                "id": "rule_2",
                "service_scope_id": "svc_scope_1001",
                "service_id": 1001,
                "subscription_id": 501,
                "destination_ip": "8.8.8.8",
                "destination_port": 53,
                "protocol": "tcp_udp",
                "direction_id": "dir_sh_jp_1",
                "enabled": True,
                "effective_state": "runtime_missing",
                "sync_version": 18,
            }
        )
        snapshot = agent.parse_route_direction_rule_payload(payload)
        commands = []

        agent.discover_route_rule_connector_artifacts = lambda desired_specs: {"current": {"units": [], "scripts": [], "state_files": []}, "stale": {"units": [], "scripts": [], "state_files": []}}
        agent.cleanup_stale_route_rule_connectors = lambda stale: {"stale_units_removed": 0, "stale_scripts_removed": 0, "stale_state_files_removed": 0}
        agent.install_route_rule_connector_services = lambda desired_specs: commands.append("render-install-current-connectors") or ["render-install-current-connectors"]
        agent.start_route_rule_connector_services = lambda desired_specs: commands.extend([
            "systemctl restart leikwan-route-rule-rule_1-tcp.service",
            "systemctl restart leikwan-route-rule-rule_1-udp.service",
        ]) or [
            "systemctl restart leikwan-route-rule-rule_1-tcp.service",
            "systemctl restart leikwan-route-rule-rule_1-udp.service",
        ]

        def fake_run(command, shell=True, text=True, capture_output=False, check=False):
            commands.append(command)
            if command == "nft -a list table inet leikwan_route_rules":
                return subprocess.CompletedProcess(
                    command,
                    0,
                    stdout='''
                    table inet leikwan_route_rules {
                        chain rr_output_nat {
                            ip daddr 203.0.113.7 tcp dport 443 redirect to :11001 comment "lw_route_rule_old_redirect_tcp" # handle 10
                        }
                    }
                    ''',
                    stderr="",
                )
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")

        with mock.patch.object(client_agent_module.shutil, "which", return_value="/usr/sbin/nft"), \
             mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run):
            summary = agent.apply_route_direction_rules(snapshot)

        self.assertEqual("ok", summary["status"])
        self.assertEqual(["rule_1"], summary["installed_rule_ids"])
        self.assertEqual(["rule_2"], summary["blocked_rule_ids"])
        self.assertEqual(1, summary["stale_rule_count"])
        delete_index = next(index for index, command in enumerate(commands) if command == "nft delete rule inet leikwan_route_rules rr_output_nat handle 10")
        install_index = next(index for index, command in enumerate(commands) if command == "render-install-current-connectors")
        start_tcp_index = next(index for index, command in enumerate(commands) if command == "systemctl restart leikwan-route-rule-rule_1-tcp.service")
        start_udp_index = next(index for index, command in enumerate(commands) if command == "systemctl restart leikwan-route-rule-rule_1-udp.service")
        redirect_index = next(index for index, command in enumerate(commands) if "rule_1_redirect_tcp" in command)
        block_index = next(index for index, command in enumerate(commands) if "rule_2_block_udp" in command)
        self.assertLess(delete_index, install_index)
        self.assertLess(install_index, start_tcp_index)
        self.assertLess(install_index, start_udp_index)
        self.assertLess(start_tcp_index, redirect_index)
        self.assertLess(start_udp_index, redirect_index)
        self.assertLess(delete_index, block_index)
        self.assertFalse(any("redirect to :11001" in command for command in commands), "apply must never install direct-to-binding nft redirects")
```

- [ ] **Step 2: Run tests to verify they fail because stale connector helpers and apply ordering are not executable**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: FAIL first at `test_discover_current_and_stale_route_rule_connector_artifacts_from_desired_plan` with `AttributeError: 'ClientAgent' object has no attribute 'classify_route_rule_connector_artifacts'`, or if that helper was already added, FAIL in `test_apply_route_direction_rules_deletes_stale_connectors_before_current_start_and_nft_redirects` because `apply_route_direction_rules()` is still no-op and does not run stale connector cleanup before current connector start and nft redirect install.

- [ ] **Step 3: Add connector unit render/apply helpers**

In `client_agent.py`, add these methods immediately after `route_rule_delete_commands_from_nft_list`:

```python
    def route_rule_connector_dir(self) -> pathlib.Path:
        return pathlib.Path("/opt/leikwan/route-rule-connectors")

    def route_rule_connector_state_dir(self) -> pathlib.Path:
        return pathlib.Path("/run/leikwan/route-rule-connectors")

    @staticmethod
    def route_rule_managed_connector_name(name: object) -> bool:
        return str(name or "").startswith(f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-")

    def render_route_rule_connector_unit(self, spec: dict, script_path: pathlib.Path) -> str:
        return f'''[Unit]
Description=LeiKwan route-rule {spec["protocol"]} connector for {spec["rule_id"]}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/env python3 {script_path}
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
'''

    def list_route_rule_connector_units(self) -> list[str]:
        result = subprocess.run(
            f"systemctl list-unit-files '{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-*.service' --no-legend --no-pager",
            shell=True,
            text=True,
            capture_output=True,
            check=False,
        )
        units: list[str] = []
        for line in str(result.stdout or "").splitlines():
            unit_name = line.strip().split(None, 1)[0] if line.strip() else ""
            if unit_name.endswith(".service") and self.route_rule_managed_connector_name(unit_name):
                units.append(unit_name)
        return sorted(set(units))

    def classify_route_rule_connector_artifacts(
        self,
        desired_specs: list[dict],
        discovered_units: list[str],
        discovered_scripts: list[pathlib.Path],
        discovered_state_files: list[pathlib.Path],
    ) -> tuple[dict, dict]:
        desired_units = {str(spec["unit_name"]) for spec in desired_specs}
        desired_scripts = {str(spec["script_name"]) for spec in desired_specs}
        desired_state = {str(spec["state_name"]) for spec in desired_specs}
        managed_units = [unit for unit in discovered_units if self.route_rule_managed_connector_name(unit)]
        managed_scripts = [path for path in discovered_scripts if self.route_rule_managed_connector_name(path.name)]
        managed_state = [path for path in discovered_state_files if self.route_rule_managed_connector_name(path.name)]
        current = {
            "units": [unit for unit in managed_units if unit in desired_units],
            "scripts": [path for path in managed_scripts if path.name in desired_scripts],
            "state_files": [path for path in managed_state if path.name in desired_state],
        }
        stale = {
            "units": [unit for unit in managed_units if unit not in desired_units],
            "scripts": [path for path in managed_scripts if path.name not in desired_scripts],
            "state_files": [path for path in managed_state if path.name not in desired_state],
        }
        return current, stale

    def discover_route_rule_connector_artifacts(self, desired_specs: list[dict]) -> dict:
        connector_dir = self.route_rule_connector_dir()
        state_dir = self.route_rule_connector_state_dir()
        discovered_units = self.list_route_rule_connector_units()
        discovered_scripts = sorted(connector_dir.glob(f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-*.py")) if connector_dir.exists() else []
        discovered_state = sorted(state_dir.glob(f"{ROUTE_RULE_CONNECTOR_SERVICE_PREFIX}-*.json")) if state_dir.exists() else []
        current, stale = self.classify_route_rule_connector_artifacts(
            desired_specs,
            discovered_units,
            discovered_scripts,
            discovered_state,
        )
        return {"current": current, "stale": stale}

    def cleanup_stale_route_rule_connectors(self, stale: dict) -> dict:
        commands: list[str] = []
        for unit_name in stale.get("units", []):
            if not self.route_rule_managed_connector_name(unit_name):
                continue
            commands.append(f"systemctl stop {unit_name}")
            commands.append(f"systemctl disable {unit_name}")
            commands.append(f"rm -f /etc/systemd/system/{unit_name}")
        if commands:
            commands.append("systemctl daemon-reload")
        for command in commands:
            subprocess.run(command, shell=True, text=True, capture_output=True, check=False)
        scripts_removed = 0
        for script_path in stale.get("scripts", []):
            if self.route_rule_managed_connector_name(pathlib.Path(script_path).name):
                pathlib.Path(script_path).unlink(missing_ok=True)
                scripts_removed += 1
        state_files_removed = 0
        for state_path in stale.get("state_files", []):
            if self.route_rule_managed_connector_name(pathlib.Path(state_path).name):
                pathlib.Path(state_path).unlink(missing_ok=True)
                state_files_removed += 1
        return {
            "stale_units_removed": len([unit for unit in stale.get("units", []) if self.route_rule_managed_connector_name(unit)]),
            "stale_scripts_removed": scripts_removed,
            "stale_state_files_removed": state_files_removed,
        }

    def install_route_rule_connector_services(self, desired_specs: list[dict]) -> list[str]:
        commands: list[str] = []
        connector_dir = self.route_rule_connector_dir()
        connector_dir.mkdir(parents=True, exist_ok=True)
        self.route_rule_connector_state_dir().mkdir(parents=True, exist_ok=True)
        for spec in desired_specs:
            script_path = connector_dir / spec["script_name"]
            unit_path = pathlib.Path("/etc/systemd/system") / spec["unit_name"]
            script_path.write_text(self.render_route_rule_connector_script(spec), encoding="utf-8")
            script_path.chmod(0o755)
            unit_path.write_text(self.render_route_rule_connector_unit(spec, script_path), encoding="utf-8")
            commands.append(f"systemctl enable {spec['unit_name']}")
        commands.insert(0, "systemctl daemon-reload")
        for command in commands:
            subprocess.run(command, shell=True, text=True, capture_output=True, check=False)
        return commands

    def start_route_rule_connector_services(self, desired_specs: list[dict]) -> list[str]:
        commands = [f"systemctl restart {spec['unit_name']}" for spec in desired_specs]
        for command in commands:
            subprocess.run(command, shell=True, text=True, capture_output=True, check=False)
        return commands
```

The connector lifecycle is split into discovery, stale cleanup, install, and start phases so `apply_route_direction_rules()` can make ordering executable: stale units/scripts/state are removed before current scripts and units are installed; current connector services are restarted before any nft redirect is installed.

- [ ] **Step 4: Replace the no-op `apply_route_direction_rules` with connector-first nftables application**

In `client_agent.py`, replace the Task 1 no-op `apply_route_direction_rules` method with:

```python
    def apply_route_direction_rules(self, snapshot: dict) -> dict:
        plan = self.build_route_direction_rule_plan(snapshot)
        summary = dict(plan.get("summary", {}))
        if not summary:
            summary = dict(snapshot.get("summary", {})) if isinstance(snapshot.get("summary"), dict) else {}
        if shutil.which("nft") is None:
            summary.update(
                {
                    "status": "unsupported",
                    "error": "nft_missing",
                    "stale_rule_count": 0,
                    "stale_rule_ids_removed": [],
                }
            )
            return summary

        desired_specs = self.render_route_rule_connector_specs(plan)
        discovered = self.discover_route_rule_connector_artifacts(desired_specs)
        stale_connector_summary = self.cleanup_stale_route_rule_connectors(discovered["stale"])

        list_result = subprocess.run(
            f"nft -a list table inet {ROUTE_RULE_NFT_TABLE}",
            shell=True,
            text=True,
            capture_output=True,
            check=False,
        )
        delete_commands = self.route_rule_delete_commands_from_nft_list(list_result.stdout)
        for command in delete_commands:
            subprocess.run(command, shell=True, text=True, capture_output=True, check=False)

        install_commands = self.install_route_rule_connector_services(desired_specs)
        start_commands = self.start_route_rule_connector_services(desired_specs)
        for command in self.render_route_rule_nft_add_commands(plan):
            subprocess.run(command, shell=True, text=True, capture_output=True, check=False)

        summary.update(
            {
                "status": "ok",
                "error": "",
                "stale_rule_count": len(delete_commands),
                "connector_install_command_count": len(install_commands),
                "connector_start_command_count": len(start_commands),
                "stale_rule_ids_removed": [],
                **stale_connector_summary,
            }
        )
        return summary
```

This method is introduced only after `build_route_direction_rule_plan`, connector discovery/classification/cleanup helpers, connector render/install/start helpers, `render_route_rule_nft_add_commands`, and `route_rule_delete_commands_from_nft_list` exist, so there is no intermediate commit where `apply_route_direction_rules()` calls undefined dispatch, connector, stale-cleanup, or block helpers. The critical failure guard is the first three operational calls after planning: compute `desired_specs`, discover stale artifacts relative to that desired set, and stop/disable/delete stale units/scripts/state before installing and restarting current connectors. That prevents a stale unit that still holds reused connector port 18000 from receiving traffic for a reordered new exact destination.

- [ ] **Step 5: Run tests to verify they pass**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: PASS with 22 tests passing.

- [ ] **Step 6: Commit**

Run:

```powershell
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" add "client-agent/client_agent.py" "client-agent/tests/test_route_direction_rules.py"
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" commit -m @'
feat(client-agent): apply exact destination route rules

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

Expected: commit succeeds.

### Task 6: Artifact mirror, exact rollout version, and versions.json sync

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\artifacts\client-agent.py`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\artifacts\versions.json`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests\test_artifact_versions_consistency.py`

- [ ] **Step 1: Add failing exact rollout-version assertion**

In `test_artifact_versions_consistency.py`, add this test method inside `ArtifactVersionsConsistencyTests` after `test_client_agent_artifact_version_matches_declared_agent_version_and_hash`:

```python
    def test_client_agent_route_rule_rollout_version_is_exact(self) -> None:
        expected_version = "2026.07.22.1"
        for path in [REPO_ROOT / "client-agent" / "client_agent.py", REPO_ROOT / "artifacts" / "client-agent.py"]:
            text = path.read_text(encoding="utf-8")
            match = re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', text)
            self.assertIsNotNone(match, f"{path} must declare AGENT_VERSION")
            self.assertEqual(expected_version, match.group(1), f"{path} must declare the route-rule rollout version")

        versions = json.loads((REPO_ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8"))
        self.assertEqual(expected_version, versions["client_agent"]["version"])
```

- [ ] **Step 2: Run artifact tests to verify they fail before the exact bump and mirror sync**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_artifact_versions_consistency.py" -v
```

Expected on the pre-route-rule baseline: FAIL because `AGENT_VERSION` is `2026.05.29.9` instead of exact target `2026.07.22.1`, or because the artifact mirror/versions.json hash and size do not match the edited source.

- [ ] **Step 3: Set the exact source `AGENT_VERSION`**

In `client-agent/client_agent.py`, replace:

```python
AGENT_VERSION = "2026.05.29.9"
```

with:

```python
AGENT_VERSION = "2026.07.22.1"
```

- [ ] **Step 4: Mirror source to downloadable artifact and update versions.json deterministically**

Run this PowerShell script:

```powershell
$repo = "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout"
$source = Join-Path $repo "client-agent\client_agent.py"
$artifact = Join-Path $repo "artifacts\client-agent.py"
Copy-Item -Path $source -Destination $artifact -Force
$bytes = [System.IO.File]::ReadAllBytes($artifact)
$sha256 = [System.BitConverter]::ToString([System.Security.Cryptography.SHA256]::Create().ComputeHash($bytes)).Replace("-", "").ToLowerInvariant()
$versionsPath = Join-Path $repo "artifacts\versions.json"
$versions = Get-Content -Path $versionsPath -Raw | ConvertFrom-Json
$versions.client_agent.version = "2026.07.22.1"
$versions.client_agent.sha256 = $sha256
$versions.client_agent.size_bytes = $bytes.Length
$versions.client_agent.size = $bytes.Length
$versions | ConvertTo-Json -Depth 20 | Set-Content -Path $versionsPath -Encoding utf8
```

Expected: command completes without errors; `artifacts/client-agent.py` is byte-identical to `client-agent/client_agent.py`; `artifacts/versions.json` contains `client_agent.version = "2026.07.22.1"` and matching hash/size values for the artifact bytes.

- [ ] **Step 5: Run route-rule tests against source**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: PASS with 22 tests passing.

- [ ] **Step 6: Run artifact consistency tests**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_artifact_versions_consistency.py" -v
```

Expected: PASS, including source/artifact byte identity, exact `2026.07.22.1` version assertion, and versions.json hash/size checks.

- [ ] **Step 7: Commit**

Run:

```powershell
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" add "client-agent/client_agent.py" "artifacts/client-agent.py" "artifacts/versions.json" "client-agent/tests/test_artifact_versions_consistency.py"
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" commit -m @'
feat(client-agent): publish route rule agent artifact

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
'@
```

Expected: commit succeeds.

### Task 7: Final Linux client-agent verification only

**Files:**
- Verify only; do not edit source, tests, artifacts, or manifest in this task.

- [ ] **Step 1: Run route-rule unit tests**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_route_direction_rules.py" -v
```

Expected: PASS with 22 tests passing.

- [ ] **Step 2: Run artifact consistency tests**

Run:

```powershell
python -m unittest discover -s "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\tests" -p "test_artifact_versions_consistency.py" -v
```

Expected: PASS, including byte-identical source/artifact mirror, exact route-rule rollout version, and versions.json hash/size.

- [ ] **Step 3: Compile source and artifact Python files**

Run:

```powershell
python -m py_compile "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\client-agent\client_agent.py" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\artifacts\client-agent.py"
```

Expected: PASS with no syntax errors.

- [ ] **Step 4: Check the scoped diff**

Run:

```powershell
git -C "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout" diff --check -- "client-agent/client_agent.py" "artifacts/client-agent.py" "artifacts/versions.json" "client-agent/tests/test_route_direction_rules.py" "client-agent/tests/test_artifact_versions_consistency.py"
```

Expected: PASS with no whitespace errors.

- [ ] **Step 5: Stop on any verification failure**

If any command in Task 7 fails, do not edit files in Task 7 and do not make a verification commit. Stop execution, capture the failing command and error output, and write a new concrete implementation-plan task that names the exact failing test/command and the exact files to change.

## Self-Review

### Spec coverage

- Pull parsing: Task 1 parses `route_directions`, `route_rule_entitlements`, `client_rules`, `direction_runtime_bindings`, and `capabilities` from `/api/client/configs/pull`.
- Capability advertisement: Task 2 adds heartbeat fields `supports_route_direction_rules` and `supports_exact_destination_rules`; exact-destination support is true only when `nft` is available on Linux.
- Exact TCP+UDP dispatch: Tasks 3-5 classify active rules, assign deterministic route-rule-owned connector ports, render TCP plus UDP connector services, and render nftables redirects to connector ports rather than the binding local endpoint ports.
- Connector onward target safety: Tasks 3-5 require every connector spec/script to connect only to the binding `local_endpoint.host` plus `local_endpoint.tcp_port`/`udp_port`; tests assert the original destination IP/port is not used as an onward target, preventing bypass-to-Internet leakage.
- UDP association behavior: Task 4 defines and tests UDP connector behavior: receive redirected datagrams, forward via a marked UDP socket connected to the binding local endpoint, and relay replies back to the original local client address/port.
- Fail-closed behavior: Tasks 3-5 classify enabled non-active or unready rules into block specs and render exact TCP reset plus UDP drop rules.
- Stale connector cleanup and ordering: Tasks 4-5 parse existing managed route-rule nftables handles, compute desired connector specs from the current dispatch plan, classify current versus stale `leikwan-route-rule-*` units/scripts/state, stop/disable/remove stale connector units, delete stale connector scripts and runtime state files, delete managed `lw_route_rule_*` nft rules, install current connector scripts/units, restart current connector services, and only then install current-pull nft redirects. Tests cover rule removal and reorder scenarios where an old connector unit holds a port that the desired plan reuses.
- Loop prevention: Task 4 renders the dedicated `meta mark 0x6c77 return` bypass before redirects; Task 3 dispatch state requires `socket_mark_required = True`, exact `fwmark = "0x6c77"`, and `fwmark_int = 27767`; Task 4 connector scripts call `setsockopt(socket.SOL_SOCKET, socket.SO_MARK, ROUTE_RULE_FWMARK_INT)` before `connect((ONWARD_HOST, ONWARD_PORT))`.
- Pull and heartbeat observability: Task 2 records `route_rule_summary` in heartbeat and `config_pull_state.json`; Task 5 changes the summary from `noop` to actual install/block/stale cleanup counts.
- Artifact sync: Task 6 sets exact `AGENT_VERSION = "2026.07.22.1"`, mirrors `client-agent/client_agent.py` to `artifacts/client-agent.py`, and updates `artifacts/versions.json` hash/size/version deterministically.
- Linux client-agent only: every implementation/test/artifact path in the plan is one of the five requested implementation paths; no backend, Android, Windows, or admin files are modified.

### Deferred-detail scan

This plan contains concrete file paths, commands, expected fail/pass outcomes, code snippets, deterministic connector port formulas, connector script/unit snippets, SO_MARK loop-prevention snippets, UDP association behavior, nftables command shapes, version value, hash update command, and commit commands. It does not rely on deferred implementation wording.

### Type and name consistency

The same names are used consistently across tasks: `parse_route_direction_rule_payload`, `apply_route_direction_rules`, `route_rule_capability_fields`, `build_route_direction_rule_plan`, `render_route_rule_nft_add_commands`, `route_rule_delete_commands_from_nft_list`, `ROUTE_RULE_FWMARK`, `ROUTE_RULE_NFT_TABLE`, `dispatch_rules`, `block_rules`, and `route_rule_summary`.
