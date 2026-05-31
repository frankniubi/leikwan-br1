import asyncio
import errno
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import time
import unittest
from types import SimpleNamespace
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


slave_agent_module = load_module("slave_agent_appendix_c_under_test", "slave-agent/slave_agent.py")


class _AgentFactory(unittest.TestCase):
    def make_agent(self):
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        tmp = pathlib.Path(tmp_dir.name)
        config_path = tmp / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "node_token": "token",
            "node_id": 19,
            "state_dir": str(tmp / "state"),
            "systemd_dir": str(tmp / "systemd"),
            "wireguard_config_dir": str(tmp / "wireguard"),
            "wstunnel_bin": "/usr/local/bin/wstunnel",
        }), encoding="utf-8")
        return slave_agent_module.SlaveAgent(config_path)

    def mark_interface_ready(self, agent, *interfaces: str) -> None:
        for iface in interfaces:
            path = agent.wireguard_interface_path(iface)
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("[Interface]\n", encoding="utf-8")


# ---------------------------------------------------------------------------
# BUG-1: empty-feed authoritative-wipe guard
# ---------------------------------------------------------------------------
class EmptyFeedGuardTests(_AgentFactory):
    def _seed_live_state(self, agent):
        # Pretend a live lws* uplink was previously applied and tracked.
        agent.save_static_sidecar_uplink_state({
            "interfaces": {
                "lws4c2n19p": {
                    "interface_name": "lws4c2n19p",
                    "forward_combination_id": 2,
                    "sidecar_point_id": 4,
                    "node_id": 19,
                    "lane": "primary",
                }
            }
        })

    def test_absent_section_does_not_wipe_saved_state_or_tear_down(self):
        agent = self.make_agent()
        self._seed_live_state(agent)
        removed: list[str] = []

        with mock.patch.object(agent, "apply_sidecar_uplink") as apply_mock:
            with mock.patch.object(agent, "watch_static_sidecar_uplink"):
                with mock.patch.object(agent, "remove_sidecar_uplink", side_effect=lambda iface, info=None: removed.append(iface)):
                    with mock.patch.object(agent, "prune_sidecar_uplink_selectors") as prune_mock:
                        # management with NO sidecar_static_uplinks key at all
                        agent.apply_management_state({"agent_config_json": {}}, schedule_updates=False)

        # Saved state must be preserved untouched.
        state = agent.load_static_sidecar_uplink_state()
        self.assertIn("lws4c2n19p", state.get("interfaces", {}))
        # No teardown of the live interface.
        self.assertEqual([], removed)
        apply_mock.assert_not_called()
        prune_mock.assert_not_called()

    def test_present_empty_section_is_authoritative_and_tears_down(self):
        agent = self.make_agent()
        self._seed_live_state(agent)
        removed: list[str] = []

        with mock.patch.object(agent, "apply_sidecar_uplink"):
            with mock.patch.object(agent, "watch_static_sidecar_uplink"):
                with mock.patch.object(agent, "remove_sidecar_uplink", side_effect=lambda iface, info=None: removed.append(iface)):
                    with mock.patch.object(agent, "prune_sidecar_uplink_selectors") as prune_mock:
                        # present but explicitly empty -> teardown intended
                        agent.apply_management_state({"sidecar_static_uplinks": []}, schedule_updates=False)

        # State overwritten to empty active set.
        state = agent.load_static_sidecar_uplink_state()
        self.assertEqual({}, state.get("interfaces", {}))
        self.assertEqual(["lws4c2n19p"], removed)
        prune_mock.assert_called_once()

    def test_cleanup_orphan_sweep_is_noop_when_section_absent(self):
        agent = self.make_agent()
        self._seed_live_state(agent)
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            # An untracked live interface exists on the box.
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws9z9z9z\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(agent, "remove_sidecar_uplink") as remove_mock:
                    agent.cleanup_orphan_sidecar_uplinks(section_present=False)

        # When the section was absent this cycle, the sweep must be a NO-OP:
        # it must not even enumerate interfaces, and certainly not remove one.
        remove_mock.assert_not_called()
        self.assertNotIn("wg show interfaces", commands)

    def test_cleanup_orphan_sweep_runs_when_section_present(self):
        agent = self.make_agent()
        self._seed_live_state(agent)

        def fake_run(command: str, check: bool = True):
            if command == "wg show interfaces":
                # lws4c2n19p is tracked; lws9z9z9z is an orphan.
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws9z9z9z\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        removed: list[str] = []
        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(agent, "remove_sidecar_uplink", side_effect=lambda iface, info=None: removed.append(iface)):
                    agent.cleanup_orphan_sidecar_uplinks(section_present=True)

        self.assertEqual(["lws9z9z9z"], removed)


# ---------------------------------------------------------------------------
# BUG-5: self-update failure_count cross-token carry-over
# ---------------------------------------------------------------------------
class SelfUpdateFailureCountTests(_AgentFactory):
    def test_new_token_resets_failure_count_before_first_failure(self):
        agent = self.make_agent()
        # Prior token sat at failure_count=2 with the breaker still closed (max=3).
        agent.save_update_state({
            "request_token": "old-token",
            "status": "error",
            "last_error": "boom",
            "failure_count": 2,
            "last_attempt_at": 0,
        })

        # A desired_agent_version mismatch makes management_requires_local_update
        # true so the scheduler actually proceeds to request_self_update rather
        # than short-circuiting to "already up to date".
        management = {
            "agent_update_request_token": "new-token",
            "desired_agent_version": "9999.99.99.9",
        }

        def boom(token):
            raise RuntimeError("update failed for new token")

        with mock.patch.object(agent, "request_self_update", side_effect=boom):
            with mock.patch.object(slave_agent_module.time, "time", return_value=5000):
                with self.assertRaises(RuntimeError):
                    agent.apply_management_state(management, schedule_updates=True)

        state = agent.load_update_state()
        # New token's FIRST failure must be 1 (reset from the stale 2), not 3.
        self.assertEqual(1, int(state.get("failure_count", -1)))
        self.assertEqual("new-token", state.get("request_token"))
        # Breaker remains closed: a subsequent retry of the same token is allowed.
        self.assertTrue(agent.should_retry_update(state, "new-token", 99999.0))


# ---------------------------------------------------------------------------
# C.7a: shared restart ledger / circuit breaker
# ---------------------------------------------------------------------------
class RestartLedgerTests(_AgentFactory):
    def setUp(self):
        self._ledger_dir = tempfile.TemporaryDirectory()
        self.addCleanup(self._ledger_dir.cleanup)
        self._patch = mock.patch.object(
            slave_agent_module,
            "RESTART_LEDGER_DIR",
            pathlib.Path(self._ledger_dir.name),
        )
        self._patch.start()
        self.addCleanup(self._patch.stop)

    def test_breaker_allows_then_records_and_suppresses_fourth(self):
        now = 10_000.0
        iface = "lws4c2n19p"
        # First three restarts within window: allowed.
        for _ in range(3):
            self.assertTrue(
                slave_agent_module.restart_circuit_breaker_allow(iface, "static-uplink-watch", now=now)
            )
        ledger_file = pathlib.Path(self._ledger_dir.name) / f"{iface}.log"
        lines = ledger_file.read_text(encoding="utf-8").splitlines()
        self.assertEqual(3, len(lines))
        # Byte-format contract: "<epoch> <token>" per line.
        for line in lines:
            parts = line.split(" ")
            self.assertEqual(2, len(parts))
            self.assertEqual(str(int(now)), parts[0])
            self.assertEqual("static-uplink-watch", parts[1])
        # Each appended line ends with a newline (append-only contract).
        self.assertTrue(ledger_file.read_text(encoding="utf-8").endswith("\n"))

        # Fourth within window: SUPPRESSED and no new line appended.
        with self.assertLogs(level="WARNING") as cm:
            self.assertFalse(
                slave_agent_module.restart_circuit_breaker_allow(iface, "static-uplink-watch", now=now)
            )
        self.assertEqual(3, len(ledger_file.read_text(encoding="utf-8").splitlines()))
        self.assertTrue(any("circuit breaker open" in m for m in cm.output))

    def test_breaker_ignores_entries_outside_window(self):
        iface = "lwsoldwin"
        ledger_file = pathlib.Path(self._ledger_dir.name) / f"{iface}.log"
        old = int(1000)
        ledger_file.write_text(
            f"{old} cfwg-latency\n{old} cfwg-latency\n{old} cfwg-latency\n",
            encoding="utf-8",
        )
        # now far beyond the 600s window -> stale entries don't count.
        self.assertTrue(
            slave_agent_module.restart_circuit_breaker_allow(iface, "selector-lane", now=old + 10_000)
        )

    def test_breaker_fail_open_on_unreadable_dir(self):
        # Point the ledger at a path that cannot be created/read; must fail-open
        # (allow the restart) and never raise.
        bogus = pathlib.Path(self._ledger_dir.name) / "afile"
        bogus.write_text("not a dir", encoding="utf-8")
        with mock.patch.object(slave_agent_module, "RESTART_LEDGER_DIR", bogus / "sub"):
            self.assertTrue(
                slave_agent_module.restart_circuit_breaker_allow("lwsx", "handshake-watchdog", now=time.time())
            )

    def test_window_and_max_overridable_by_env(self):
        iface = "lwsenvtest"
        with mock.patch.dict(os.environ, {"LEIKWAN_RESTART_LEDGER_MAX": "1"}):
            self.assertTrue(
                slave_agent_module.restart_circuit_breaker_allow(iface, "selector-lane", now=20_000.0)
            )
            with self.assertLogs(level="WARNING"):
                self.assertFalse(
                    slave_agent_module.restart_circuit_breaker_allow(iface, "selector-lane", now=20_000.0)
                )

    def test_selector_lane_restart_suppressed_by_breaker(self):
        agent = self.make_agent()
        self.mark_interface_ready(agent, "lws4c2n19p", "lws4c2n19b")
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1"},
                {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5"},
            ],
            "max_active_avg_ms": 70,
            "restart_cooldown_seconds": 60,
        }
        # Pre-fill the ledger so the primary lane is already at MAX restarts.
        ledger_file = pathlib.Path(self._ledger_dir.name) / "lws4c2n19p.log"
        ledger_file.write_text("999 selector-lane\n999 selector-lane\n999 selector-lane\n", encoding="utf-8")

        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws4c2n19b\n", stderr="")
            if "lws4c2n19p" in command and command.startswith("ping "):
                # primary unhealthy
                return SimpleNamespace(returncode=1, stdout="3 packets transmitted, 0 received, 100% packet loss", stderr="")
            if "lws4c2n19b" in command and command.startswith("ping "):
                return SimpleNamespace(returncode=0, stdout="rtt min/avg/max/mdev = 30.000/32.000/35.000/1.000 ms\n3 packets transmitted, 3 received, 0% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=999):
                    with self.assertLogs(level="WARNING"):
                        state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary", "last_restart_at": {}})

        # The breaker is open for the primary lane: NO restart issued for it.
        self.assertFalse(any("restart leikwan-wgcf-lws4c2n19p" in c for c in commands))
        self.assertFalse(any("restart wg-quick@lws4c2n19p" in c for c in commands))
        self.assertNotIn("lws4c2n19p", state["restarted_lanes"])

    def test_static_uplink_watch_restart_suppressed_by_breaker(self):
        agent = self.make_agent()
        ledger_file = pathlib.Path(self._ledger_dir.name) / "lws4c2n19p.log"
        ledger_file.write_text("999 static-uplink-watch\n999 static-uplink-watch\n999 static-uplink-watch\n", encoding="utf-8")
        commands: list[str] = []

        uplink = {
            "interface_name": "lws4c2n19p",
            "watchdog": {"enabled": True, "handshake_stale_seconds": 15, "restart_cooldown_seconds": 1},
        }

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show lws4c2n19p latest-handshakes":
                # a seen-but-stale handshake -> would normally trigger restart
                return SimpleNamespace(returncode=0, stdout="peer 100\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=999):
                    with self.assertLogs(level="WARNING"):
                        agent.watch_static_sidecar_uplink(uplink)

        self.assertNotIn("systemctl restart wg-quick@lws4c2n19p.service", commands)


# ---------------------------------------------------------------------------
# C.7c: monitor-only selector handling
# ---------------------------------------------------------------------------
class MonitorOnlySelectorTests(_AgentFactory):
    def test_single_lane_monitor_one_candidate_probes_but_never_restarts(self):
        agent = self.make_agent()
        self.mark_interface_ready(agent, "lws4c2n19p")
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "single_lane_monitor": True,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1"},
            ],
            "max_active_avg_ms": 70,
            "restart_cooldown_seconds": 60,
        }
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p\n", stderr="")
            if command.startswith("ping "):
                # unhealthy -> would otherwise be a restart candidate
                return SimpleNamespace(returncode=1, stdout="3 packets transmitted, 0 received, 100% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary", "last_restart_at": {}})

        # A latency/loss sample IS recorded.
        self.assertEqual(1, len(state["samples"]))
        self.assertEqual(100.0, state["samples"][0]["loss_percent"])
        # Zero restarts issued.
        self.assertFalse(any(c.startswith("systemctl restart") for c in commands))
        self.assertEqual([], state["restarted_lanes"])

    def test_single_lane_monitor_flag_honored_even_with_multiple_candidates(self):
        # single_lane_monitor=true must suppress restart/switch even if the
        # panel happens to carry >1 candidate (proves the flag is honored, not
        # merely the len(samples)<=1 fallback).
        agent = self.make_agent()
        self.mark_interface_ready(agent, "lws4c2n19p", "lws4c2n19b")
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "single_lane_monitor": True,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1"},
                {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5"},
            ],
            "max_active_avg_ms": 70,
            "switch_improvement_ms": 5,
            "restart_cooldown_seconds": 60,
        }
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws4c2n19b\n", stderr="")
            if "lws4c2n19p" in command and command.startswith("ping "):
                # active primary is far worse than backup -> would normally switch
                return SimpleNamespace(returncode=0, stdout="rtt min/avg/max/mdev = 90.000/95.000/100.000/2.000 ms\n3 packets transmitted, 3 received, 0% packet loss", stderr="")
            if "lws4c2n19b" in command and command.startswith("ping "):
                return SimpleNamespace(returncode=0, stdout="rtt min/avg/max/mdev = 20.000/22.000/24.000/1.000 ms\n3 packets transmitted, 3 received, 0% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary", "last_restart_at": {}})

        # Both lanes probed.
        self.assertEqual(2, len(state["samples"]))
        # No switch despite backup being much better.
        self.assertEqual("primary", state["active_lane"])
        self.assertEqual("keep_active", state["switch_reason"])
        # No restarts.
        self.assertFalse(any(c.startswith("systemctl restart") for c in commands))
        self.assertEqual([], state["restarted_lanes"])


# ---------------------------------------------------------------------------
# C.7g: all-lanes-degraded visibility
# ---------------------------------------------------------------------------
class AllUnhealthyVisibilityTests(_AgentFactory):
    def test_all_unhealthy_logs_warn_and_sets_health_marker(self):
        agent = self.make_agent()
        self.mark_interface_ready(agent, "lws4c2n19p", "lws4c2n19b")
        selector = {
            "enabled": True,
            "service_name": "wstunnel-svc-2-571",
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1"},
                {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5"},
            ],
            "restart_cooldown_seconds": 60,
        }

        def fake_run(command: str, check: bool = True):
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws4c2n19b\n", stderr="")
            if command.startswith("ping "):
                return SimpleNamespace(returncode=1, stdout="3 packets transmitted, 0 received, 100% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with self.assertLogs(level="WARNING") as cm:
                        state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary", "last_restart_at": {}})

        self.assertEqual("all_unhealthy", state["switch_reason"])
        # A health-event marker must be set on the state JSON.
        self.assertTrue(bool(state.get("health_event")))
        self.assertEqual("all_unhealthy", state["health_event"].get("kind"))
        self.assertTrue(any("all" in m.lower() and "unhealthy" in m.lower() for m in cm.output))

    def test_interface_missing_is_distinguished_from_ping_failed(self):
        agent = self.make_agent()
        # Only primary's interface exists; backup interface missing.
        self.mark_interface_ready(agent, "lws4c2n19p")
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1"},
                {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5"},
            ],
        }

        def fake_run(command: str, check: bool = True):
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p\n", stderr="")
            if command.startswith("ping "):
                return SimpleNamespace(returncode=1, stdout="3 packets transmitted, 0 received, 100% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with self.assertLogs(level="WARNING"):
                        state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary"})

        samples_by_lane = {s["lane"]: s for s in state["samples"]}
        self.assertEqual("interface_missing", samples_by_lane["backup"].get("error"))
        # primary failed via ping, not interface_missing.
        self.assertNotEqual("interface_missing", samples_by_lane["primary"].get("error"))


# ---------------------------------------------------------------------------
# Item 6: missing tests for already-shipped features
# ---------------------------------------------------------------------------
class OrphanCleanupBehaviorTests(_AgentFactory):
    def test_cleanup_removes_untracked_lws_interface_and_units_keeps_tracked(self):
        agent = self.make_agent()
        # Track exactly one interface; the other is an orphan.
        agent.save_static_sidecar_uplink_state({
            "interfaces": {
                "lws4c2n19p": {"interface_name": "lws4c2n19p"},
            }
        })
        # A selector config for the tracked service still exists on disk so its
        # selector unit is considered live.
        kept_service = "wstunnel-svc-2-571"
        kept_cfg = agent.sidecar_uplink_selector_config_path(kept_service)
        kept_cfg.parent.mkdir(parents=True, exist_ok=True)
        kept_cfg.write_text(json.dumps({"enabled": True, "candidates": [{"interface_name": "lws4c2n19p"}]}), encoding="utf-8")

        list_units_output = (
            "wg-quick@lws9z9z9z.service loaded active running\n"
            "wg-quick@lws4c2n19p.service loaded active running\n"
            "leikwan-wgcf-lws9z9z9z.service loaded active running\n"
            "wstunnel-svc-9-999-sidecar-selector.service loaded active running\n"
            f"{kept_service}-sidecar-selector.service loaded active running\n"
        )
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws9z9z9z\n", stderr="")
            if command.startswith("systemctl list-units"):
                return SimpleNamespace(returncode=0, stdout=list_units_output, stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        removed: list[str] = []
        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(agent, "remove_sidecar_uplink", side_effect=lambda iface, info=None: removed.append(iface)):
                    with mock.patch.object(agent, "prune_sidecar_uplink_selectors"):
                        agent.cleanup_orphan_sidecar_uplinks(section_present=True)

        # The orphan interface is torn down; the tracked one is kept.
        self.assertEqual(["lws9z9z9z"], removed)

        # wg-quick orphan unit stopped/disabled; tracked wg-quick unit untouched.
        self.assertIn("systemctl stop wg-quick@lws9z9z9z.service", commands)
        self.assertIn("systemctl disable wg-quick@lws9z9z9z.service", commands)
        self.assertNotIn("systemctl stop wg-quick@lws4c2n19p.service", commands)

        # leikwan-wgcf orphan unit stopped/disabled.
        self.assertIn("systemctl stop leikwan-wgcf-lws9z9z9z.service", commands)
        self.assertIn("systemctl disable leikwan-wgcf-lws9z9z9z.service", commands)

        # Orphan selector unit (no config on disk) stopped; tracked selector kept.
        self.assertIn("systemctl stop wstunnel-svc-9-999-sidecar-selector.service", commands)
        self.assertNotIn(f"systemctl stop {kept_service}-sidecar-selector.service", commands)


class SelectorProxyServeTests(_AgentFactory):
    def test_serve_clean_exits_on_eaddrinuse(self):
        agent = self.make_agent()
        selector = {"enabled": True, "selector_port": 45200, "sidecar_service_port": 9000, "candidates": []}
        proxy = slave_agent_module.SidecarUplinkSelectorProxy(agent, "wstunnel-svc-2-571", selector)

        async def boom(*args, **kwargs):
            raise OSError(errno.EADDRINUSE, "address already in use")

        with mock.patch.object(agent, "evaluate_sidecar_uplink_selector", return_value={"active_target_host": "", "active_target_port": 0}):
            with mock.patch.object(agent, "save_sidecar_uplink_selector_state"):
                with mock.patch.object(agent, "load_sidecar_uplink_selector_state", return_value={}):
                    with mock.patch.object(slave_agent_module.asyncio, "start_server", side_effect=boom):
                        # Must NOT raise — clean exit.
                        asyncio.run(proxy.serve())


if __name__ == "__main__":
    unittest.main()
