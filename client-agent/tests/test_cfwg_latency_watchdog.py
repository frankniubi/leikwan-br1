import importlib.util
import json
import pathlib
import sys
import tempfile
import types
import unittest
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, REPO_ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


watchdog_module = load_module("cfwg_latency_watchdog_under_test", "client-agent/cfwg_latency_watchdog.py")
client_agent_module = load_module("client_agent_watchdog_integration_under_test", "client-agent/client_agent.py")


class FakeClock:
    def __init__(self) -> None:
        self.value = 1000.0

    def now(self) -> float:
        return self.value

    def advance(self, seconds: float) -> None:
        self.value += seconds


class FakeRunner:
    def __init__(self, latencies: list[float | None]) -> None:
        self.latencies = list(latencies)
        self.commands: list[list[str]] = []

    def __call__(self, command: list[str]):
        self.commands.append(list(command))
        if command[:1] == ["ping"]:
            latency = self.latencies.pop(0)
            if latency is None:
                return watchdog_module.CommandResult(returncode=1, stdout="", stderr="timeout")
            return watchdog_module.CommandResult(
                returncode=0,
                stdout=f"64 bytes from 10.204.53.42: icmp_seq=1 ttl=64 time={latency:.1f} ms\n",
                stderr="",
            )
        return watchdog_module.CommandResult(returncode=0, stdout="", stderr="")


class CfwgLatencyWatchdogTests(unittest.TestCase):
    def _write_runtime(self, root: pathlib.Path) -> None:
        root.mkdir(parents=True, exist_ok=True)
        (root / "mptcp-forward-47.json").write_text(
            json.dumps(
                {
                    "underlays": [
                        {
                            "mode": "client_self_leg",
                            "interface_name": "lw-cfwg-47",
                            "legs": [
                                {
                                    "outer_transport": "wstunnel_udp",
                                    "peer_address": "10.204.53.42",
                                }
                            ],
                        }
                    ]
                }
            ),
            encoding="utf-8",
        )

    def test_restarts_forward_service_after_sustained_high_icmp_latency(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([53.0, 52.0, 54.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={
                    "enabled": True,
                    "forward_ids": [47],
                    "probe_interval_seconds": 0,
                    "window_samples": 3,
                    "critical_rtt_ms": 45,
                    "dwell_windows": 1,
                    "cooldown_seconds": 300,
                },
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)

            self.assertIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)
            self.assertNotIn(["systemctl", "restart", "wg-quick@lw-cfwg-47.service"], runner.commands)


    def test_restart_action_can_restart_wstunnel_and_wireguard_units(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([70.0, 70.0, 70.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={
                    "enabled": True,
                    "forward_ids": [47],
                    "probe_interval_seconds": 0,
                    "window_samples": 3,
                    "critical_rtt_ms": 45,
                    "dwell_windows": 1,
                    "cooldown_seconds": 300,
                    "restart_actions": ["wstunnel", "wireguard"],
                },
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)

            self.assertIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)
            self.assertIn(["systemctl", "restart", "wg-quick@lw-cfwg-47.service"], runner.commands)

    def test_learns_best_rtt_and_writes_state_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([31.0, 32.0, 31.5])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={
                    "enabled": True,
                    "forward_ids": [47],
                    "probe_interval_seconds": 0,
                    "window_samples": 3,
                    "critical_rtt_ms": 70,
                    "critical_rtt_delta_ms": 10,
                    "state_write_interval_seconds": 5,
                },
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(3)

            state = json.loads((base / "state" / "cfwg-latency-watchdog-state.json").read_text(encoding="utf-8"))
            self.assertLessEqual(state["47"]["best_rtt_ms"], 31.0)


    def test_persisted_best_rtt_detects_regression_after_agent_restart(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            state_dir = base / "state"
            state_dir.mkdir()
            (state_dir / "cfwg-latency-watchdog-state.json").write_text(
                json.dumps({"47": {"best_rtt_ms": 31.0, "last_restart_at": 0, "bad_windows": 0, "last_event": ""}}),
                encoding="utf-8",
            )
            clock = FakeClock()
            runner = FakeRunner([45.0, 45.0, 45.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=state_dir,
                config={
                    "enabled": True,
                    "forward_ids": [47],
                    "probe_interval_seconds": 0,
                    "window_samples": 3,
                    "critical_rtt_ms": 70,
                    "critical_rtt_delta_ms": 10,
                    "dwell_windows": 1,
                    "cooldown_seconds": 0,
                },
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)

            self.assertIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)


    def test_baseline_rtt_and_delta_detect_regression_from_best_latency(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([31.0, 32.0, 31.5, 45.0, 45.5, 46.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={
                    "enabled": True,
                    "forward_ids": [47],
                    "probe_interval_seconds": 0,
                    "window_samples": 3,
                    "critical_rtt_ms": 70,
                    "critical_rtt_delta_ms": 10,
                    "baseline_rtt_ms": {"47": 31},
                    "dwell_windows": 1,
                    "cooldown_seconds": 300,
                },
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)
            self.assertNotIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)
            self.assertIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)


    def test_regression_from_learned_best_restarts_without_explicit_baseline(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([31.0, 32.0, 31.5, 45.0, 45.0, 45.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={
                    "enabled": True,
                    "forward_ids": [47],
                    "probe_interval_seconds": 0,
                    "window_samples": 3,
                    "critical_rtt_ms": 70,
                    "critical_rtt_delta_ms": 0,
                    "relative_degradation_factor": 1.35,
                    "min_relative_degradation_ms": 8,
                    "dwell_windows": 1,
                    "cooldown_seconds": 0,
                },
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)
            self.assertEqual([], [cmd for cmd in runner.commands if cmd == ["systemctl", "restart", "leikwan-client-47.service"]])

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)

            self.assertIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)

    def test_healthy_latency_does_not_restart(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([31.0, 32.0, 31.5])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={"enabled": True, "probe_interval_seconds": 0, "window_samples": 3, "critical_rtt_ms": 45, "dwell_windows": 1},
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)

            self.assertNotIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)

    def test_cooldown_prevents_restart_loop(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([70.0, 70.0, 70.0, 80.0, 80.0, 80.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={"enabled": True, "probe_interval_seconds": 0, "window_samples": 3, "critical_rtt_ms": 45, "dwell_windows": 1, "cooldown_seconds": 300},
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(6):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)

            restarts = [cmd for cmd in runner.commands if cmd == ["systemctl", "restart", "leikwan-client-47.service"]]
            self.assertEqual(1, len(restarts))

    def test_dwell_windows_requires_two_complete_bad_windows(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([60.0, 60.0, 60.0, 61.0, 61.0, 61.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={"enabled": True, "probe_interval_seconds": 0, "window_samples": 3, "critical_rtt_ms": 45, "dwell_windows": 2, "cooldown_seconds": 300},
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)
            self.assertNotIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)

            for _ in range(3):
                watchdog.tick([{"forward_id": 47, "service_status": "active"}])
                clock.advance(1)
            self.assertIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)


    def test_rotate_cf_ip_action_writes_pin_and_restarts_forward_service(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            runtime_dir = base / "etc" / "leikwan"
            self._write_runtime(runtime_dir)
            clock = FakeClock()
            runner = FakeRunner([70.0, 70.0, 70.0])
            watchdog = watchdog_module.CfwgLatencyWatchdog(
                state_dir=base / "state",
                config={
                    "enabled": True,
                    "forward_ids": [47],
                    "probe_interval_seconds": 0,
                    "window_samples": 3,
                    "critical_rtt_ms": 45,
                    "dwell_windows": 1,
                    "cooldown_seconds": 0,
                    "restart_actions": ["rotate_cf_ip", "wstunnel"],
                    "enable_cf_ip_rotation": True,
                    "pin_ttl_seconds": 600,
                },
                command_runner=runner,
                time_fn=clock.now,
                runtime_config_dir=runtime_dir,
            )
            forwards = [
                {
                    "forward_id": 47,
                    "service_status": "active",
                    "candidate_pool": [
                        {
                            "probe_transport": "cf_wg",
                            "self_leg_cf_candidates": ["162.159.39.5", "104.16.0.5"],
                            "self_leg_wg": {"cf_dial_host": "162.159.39.5"},
                        }
                    ],
                }
            ]

            for _ in range(3):
                watchdog.tick(forwards)
                clock.advance(1)

            pin = json.loads((base / "state" / "cfwg-watchdog-pin" / "47.json").read_text(encoding="utf-8"))
            self.assertEqual("104.16.0.5", pin["cf_dial_host_pin"])
            self.assertIn(["systemctl", "restart", "leikwan-client-47.service"], runner.commands)


    def test_restart_service_without_rerendering_when_runtime_signature_is_unchanged(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            service_path = base / "leikwan-client-47.service"
            script_path = base / "leikwan-client-start-47.sh"
            service_unit = "[Unit]\nDescription=CFWG test\n"
            start_script = "#!/usr/bin/env bash\necho cfwg\n"
            rendered = {
                "service_name": "leikwan-client-47",
                "service_path": str(service_path),
                "service_unit": service_unit,
                "start_script_path": str(script_path),
                "start_script": start_script,
                "files": [],
            }
            rendered_signature = client_agent_module.ClientAgent.rendered_runtime_signature(rendered, "mptcp", "tcp+udp", 32216)
            state_dir = base / "state"
            state_dir.mkdir()
            (state_dir / "applied.json").write_text(
                json.dumps(
                    {
                        "47": {
                            "units": [{"service_name": "leikwan-client-47", "managed_paths": [str(service_path), str(script_path)]}],
                            "managed_paths": [str(service_path), str(script_path)],
                            "published_mode": "mptcp",
                            "runtime_mode": "mptcp",
                            "contract_signature": "old",
                            "rendered_signature": rendered_signature,
                            "updated_at": 1,
                        }
                    }
                ),
                encoding="utf-8",
            )
            service_path.write_text(service_unit, encoding="utf-8")
            script_path.write_text(start_script, encoding="utf-8")

            config_path = base / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 3,
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)
            forward = {
                "forward_id": 47,
                "listen_port": 32216,
                "target": "127.0.0.1:14969",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "07289d1f5ed25399",
                "transport": "tcp+udp",
                "mode": "mptcp",
                "endpoints": [],
                "mptcp": {"sidecar_host": "10.204.53.42", "sidecar_port": 14969, "tcp_session_token": "t", "udp_session_token": "u"},
            }
            previous_module = sys.modules.get("install_forward_runtime")
            sys.modules["install_forward_runtime"] = types.SimpleNamespace(render_forward_runtime=lambda _forward, _agent_config: rendered)
            commands = []
            try:
                agent.systemd_is_active = lambda _name: True
                agent.private_wg_interface_present = lambda _name: True
                with mock.patch.object(agent, "delete_nft_rules") as delete_nft:
                    with mock.patch.object(agent, "add_nft_rules") as add_nft:
                        with mock.patch.object(agent, "cleanup_mptcp_forward_runtime") as cleanup:
                            with mock.patch.object(client_agent_module.subprocess, "run", side_effect=lambda command, *args, **kwargs: commands.append(command) or types.SimpleNamespace(returncode=0, stdout="", stderr="")):
                                agent.apply_forward(forward, force_runtime_restart=True)
            finally:
                if previous_module is None:
                    sys.modules.pop("install_forward_runtime", None)
                else:
                    sys.modules["install_forward_runtime"] = previous_module

            delete_nft.assert_not_called()
            add_nft.assert_not_called()
            cleanup.assert_not_called()
            self.assertIn("systemctl restart leikwan-client-47.service", commands)

class ClientAgentCfwgLatencyWatchdogIntegrationTests(unittest.TestCase):

    def test_client_manifest_includes_latency_watchdog_module(self) -> None:
        versions = json.loads((REPO_ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8"))
        self.assertIn("cfwg_latency_watchdog", versions)
        self.assertEqual("client-agent/cfwg_latency_watchdog.py", versions["cfwg_latency_watchdog"]["path"])

    def test_auto_update_token_checks_latency_watchdog_artifact_hash(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            base = pathlib.Path(tmp)
            config_path = base / "config.json"
            config_path.write_text(
                json.dumps({"master_url": "https://panel.example.com", "client_id": 3, "agent_token": "token", "state_dir": str(base / "state")}),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)

            token = agent.auto_update_request_token({"desired_cfwg_watchdog_sha256": "0" * 64})

        self.assertIn("cfwg_watchdog=", token)


    def test_management_patch_accepts_latency_watchdog_and_artifact_config(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            config_path = pathlib.Path(tmp) / "config.json"
            config_path.write_text(
                json.dumps({"master_url": "https://panel.example.com", "client_id": 3, "agent_token": "token", "state_dir": str(pathlib.Path(tmp) / "state")}),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)
            agent.apply_config_patch(
                {
                    "artifact_base_url": "https://artifacts.example.com",
                    "disable_auto_update": True,
                    "cfwg_latency_watchdog": {"enabled": True, "forward_ids": [47], "critical_rtt_ms": 45},
                }
            )

            saved = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertEqual("https://artifacts.example.com", saved["artifact_base_url"])
        self.assertTrue(saved["disable_auto_update"])
        self.assertEqual({"enabled": True, "forward_ids": [47], "critical_rtt_ms": 45}, saved["cfwg_latency_watchdog"])


    def test_management_patch_accepts_baseline_and_restart_action_options(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            config_path = pathlib.Path(tmp) / "config.json"
            config_path.write_text(
                json.dumps({"master_url": "https://panel.example.com", "client_id": 3, "agent_token": "token", "state_dir": str(pathlib.Path(tmp) / "state")}),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)
            watchdog_config = {
                "enabled": True,
                "forward_ids": [47],
                "critical_rtt_ms": 55,
                "critical_rtt_delta_ms": 12,
                "baseline_rtt_ms": {"47": 31},
                "restart_actions": ["wstunnel", "wireguard"],
            }
            agent.apply_config_patch({"cfwg_latency_watchdog": watchdog_config})

            saved = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertEqual(watchdog_config, saved["cfwg_latency_watchdog"])

    def test_run_helper_ticks_existing_watchdog_when_enabled(self) -> None:
        class FakeWatchdog:
            last = None
            def __init__(self, state_dir, config):
                self.state_dir = pathlib.Path(state_dir)
                self.config = config
                self.updated = None
                self.ticked = None
                FakeWatchdog.last = self
            def update_config(self, config: dict) -> None:
                self.updated = config
            def tick(self, forwards):
                self.ticked = list(forwards)

        with tempfile.TemporaryDirectory() as tmp:
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.config = {"state_dir": tmp, "cfwg_latency_watchdog": {"enabled": True, "forward_ids": [47]}}
            agent.state_dir = pathlib.Path(tmp)
            agent._cfwg_latency_watchdog = None
            previous_module = sys.modules.get("cfwg_latency_watchdog")
            sys.modules["cfwg_latency_watchdog"] = types.SimpleNamespace(CfwgLatencyWatchdog=FakeWatchdog)
            try:
                client_agent_module.ClientAgent.run_cfwg_latency_watchdog(agent, [{"forward_id": 47, "service_status": "active"}])
            finally:
                if previous_module is None:
                    sys.modules.pop("cfwg_latency_watchdog", None)
                else:
                    sys.modules["cfwg_latency_watchdog"] = previous_module

        self.assertIsNotNone(FakeWatchdog.last)
        self.assertEqual({"enabled": True, "forward_ids": [47]}, FakeWatchdog.last.config)
        self.assertEqual([{"forward_id": 47, "service_status": "active"}], FakeWatchdog.last.ticked)


if __name__ == "__main__":
    unittest.main()


