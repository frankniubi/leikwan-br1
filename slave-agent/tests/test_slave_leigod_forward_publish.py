import hashlib
import importlib.util
import json
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


slave_agent_module = load_module(
    "slave_agent_leigod_forward_publish_under_test",
    "slave-agent/slave_agent.py",
)


class SlaveLeigodForwardPublishTests(unittest.TestCase):
    def make_agent(self):
        temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(temp_dir.cleanup)
        root = pathlib.Path(temp_dir.name)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(root / "state"),
                    "systemd_dir": str(root / "systemd"),
                    "leigod_root": str(root / "leigod"),
                    "backend_port_range_start": 30000,
                    "backend_port_range_end": 30010,
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path), root

    @staticmethod
    def candidate(line_id: int, combination_id: int, order: int) -> dict:
        toml = f"line = {line_id}\n"
        return {
            "combination_id": combination_id,
            "line_id": line_id,
            "config_sha256": hashlib.sha256(toml.encode()).hexdigest(),
            "socks_host": "127.0.0.1",
            "socks_port": 20000 + line_id,
            "combination_sort_order": order,
            "sort_order": order,
            "service_state": "active",
            "tcp_status": "healthy",
            "tcp_connect_ms": 10,
            "udp_status": "unknown",
            "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        }

    def seed_health(self, agent, candidates: list[dict]):
        desired = []
        health = {}
        for candidate in candidates:
            desired.append(
                {
                    "line_id": candidate["line_id"],
                    "config_sha256": candidate["config_sha256"],
                    "socks_host": "127.0.0.1",
                    "socks_port": candidate["socks_port"],
                    "tcp_probe_host": "198.51.100.8",
                    "tcp_probe_port": 80,
                    "udp_probe_host": None,
                    "udp_probe_port": None,
                    "udp_probe_method": None,
                }
            )
            health[candidate["line_id"]] = {
                "line_id": candidate["line_id"],
                "config_sha256": candidate["config_sha256"],
                "service_state": "active",
                "tcp_status": "healthy",
                "tcp_connect_ms": 10,
                "udp_status": "unknown",
                "consecutive_successes": 2,
                "consecutive_failures": 0,
                "last_error": "",
                "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }
        agent.save_leigod_desired_lines(desired)
        agent.save_leigod_line_health(health)

    def payload(self, candidates: list[dict], *, mode: str = "race") -> dict:
        return {
            "target": "127.0.0.1:48316",
            "listen_port": 7443,
            "path_prefix": "dedicated-route",
            "route_key": "dedicated-route",
            "transport_mode": "tcp",
            "filter_profile": "off",
            "ingress_protocol": "wss_path_mux",
            "backend_protocol": "wss_backend",
            "backend_mode": "leigod_mode",
            "leigod_forward": {
                "mode": mode,
                "relay_port": 48316,
                "target": "162.245.221.78:80",
                "candidates": candidates,
                "health_max_age_seconds": 180,
                "switch_improvement_ms": 15,
                "minimum_hold_seconds": 30,
                "failure_threshold": 2,
                "recovery_success_threshold": 2,
            },
        }

    @staticmethod
    def task() -> dict:
        return {"service_id": 2, "forward_id": 47, "endpoint_id": 316, "payload": {}}

    def publish(self, agent, payload: dict):
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            with mock.patch.object(agent, "sync_listener"):
                                with mock.patch.object(agent, "write_filter_marker", return_value={}):
                                    return agent.publish_task(self.task(), payload)

    def test_publish_writes_strict_config_hardened_unit_and_loopback_backend(self):
        agent, _root = self.make_agent()
        candidates = [self.candidate(451, 10, 10), self.candidate(453, 11, 20)]
        self.seed_health(agent, candidates)
        result = self.publish(agent, self.payload(candidates))

        service_name = "wstunnel-svc-2-316"
        config_path = agent.leigod_forward_config_path(service_name)
        config = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertEqual("race", config["mode"])
        self.assertEqual([451, 453], [row["line_id"] for row in config["candidates"]])
        self.assertEqual(
            {"combination_id", "line_id", "socks_host", "socks_port", "sort_order"},
            set(config["candidates"][0]),
        )
        unit_path = agent.service_unit_path(agent.leigod_forward_service_name(service_name))
        unit = unit_path.read_text(encoding="utf-8")
        self.assertIn(
            "ExecStart=/usr/bin/python3 /usr/local/bin/leikwan-leigod-forward-relay.py --config " + str(config_path),
            unit,
        )
        for directive in (
            "Restart=on-failure",
            "RestartSec=3",
            "StartLimitIntervalSec=60",
            "StartLimitBurst=5",
            "NoNewPrivileges=true",
            "PrivateTmp=true",
        ):
            self.assertIn(directive, unit)
        backend = agent.service_unit_path(service_name).read_text(encoding="utf-8")
        self.assertIn('--restrict-to "127.0.0.1:48316"', backend)
        self.assertNotIn("162.245.221.78:80", backend)
        metadata = agent.load_service_metadata(service_name)
        self.assertEqual("162.245.221.78:80", metadata["leigod_forward"]["target"])
        self.assertEqual(48316, metadata["leigod_forward"]["relay_port"])
        self.assertEqual(agent.leigod_forward_service_name(service_name), result["leigod_forward_service_name"])
        self.assertIn(48316, agent.used_backend_ports())
        self.assertIn(20451, agent.used_backend_ports())

    def test_publish_rejects_target_port_and_unhealthy_hash_mismatches(self):
        agent, _root = self.make_agent()
        candidates = [self.candidate(451, 10, 10)]
        self.seed_health(agent, candidates)

        bad_target = self.payload(candidates, mode="single")
        bad_target["target"] = "127.0.0.1:48317"
        with self.assertRaisesRegex(RuntimeError, "relay target mismatch"):
            self.publish(agent, bad_target)

        bad_port = self.payload([dict(candidates[0], socks_port=20452)], mode="single")
        with self.assertRaisesRegex(RuntimeError, "SOCKS port mismatch"):
            self.publish(agent, bad_port)

        health = agent.load_leigod_line_health()
        health[451]["config_sha256"] = "0" * 64
        agent.save_leigod_line_health(health)
        with self.assertRaisesRegex(RuntimeError, "not healthy"):
            self.publish(agent, self.payload(candidates, mode="single"))

    def test_suspend_and_destroy_remove_relay_runtime_files(self):
        agent, _root = self.make_agent()
        candidates = [self.candidate(451, 10, 10)]
        self.seed_health(agent, candidates)
        self.publish(agent, self.payload(candidates, mode="single"))
        service_name = "wstunnel-svc-2-316"
        config_path = agent.leigod_forward_config_path(service_name)
        state_path = agent.leigod_forward_state_path(service_name)
        state_path.write_text("{}", encoding="utf-8")
        relay_unit = agent.service_unit_path(agent.leigod_forward_service_name(service_name))

        commands: list[str] = []
        with mock.patch.object(slave_agent_module.shutil, "which", return_value="/usr/bin/systemctl"):
            with mock.patch.object(
                slave_agent_module,
                "run_command",
                side_effect=lambda command, check=False: commands.append(command)
                or SimpleNamespace(returncode=0, stdout="", stderr=""),
            ):
                with mock.patch.object(agent, "sync_listener"):
                    with mock.patch.object(agent, "remove_sidecar_uplink"):
                        agent.stop_service(self.task(), keep_files=True)
        self.assertFalse(config_path.exists())
        self.assertFalse(state_path.exists())
        self.assertFalse(relay_unit.exists())
        metadata = agent.load_service_metadata(service_name)
        self.assertEqual("suspended", metadata["status"])
        self.assertEqual({}, metadata["leigod_forward"])
        self.assertIn(
            "systemctl stop wstunnel-svc-2-316-leigod-forward.service",
            commands,
        )

        self.publish(agent, self.payload(candidates, mode="single"))
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "sync_listener"):
                with mock.patch.object(agent, "remove_sidecar_uplink"):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        agent.destroy_service(self.task())
        self.assertFalse(config_path.exists())
        self.assertFalse(relay_unit.exists())
        self.assertIsNone(agent.load_service_metadata(service_name))

    def test_heartbeat_reports_bounded_current_selection(self):
        agent, _root = self.make_agent()
        candidates = [self.candidate(451, 10, 10)]
        self.seed_health(agent, candidates)
        self.publish(agent, self.payload(candidates, mode="single"))
        service_name = "wstunnel-svc-2-316"
        agent.write_json(
            agent.leigod_forward_state_path(service_name),
            {
                "mode": "single",
                "selected_tcp_line_id": 451,
                "selected_udp_line_id": None,
                "updated_at": 1785110400,
                "candidates": [],
            },
        )
        selections = agent.collect_leigod_forward_selections()
        self.assertEqual(1, len(selections))
        self.assertEqual(451, selections[0]["selected_line_id"])
        self.assertEqual(10, selections[0]["selected_combination_id"])
        self.assertEqual(47, selections[0]["forward_id"])

    def test_transport_change_restarts_instead_of_rejected_hup(self):
        agent, _root = self.make_agent()
        candidates = [self.candidate(451, 10, 10)]
        self.seed_health(agent, candidates)
        runtime = agent.validate_leigod_forward_payload(
            "wstunnel-svc-2-316",
            self.task(),
            self.payload(candidates, mode="single"),
            "127.0.0.1:48316",
        )
        assert runtime is not None
        with mock.patch.object(agent, "ensure_backend_service"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
                    agent.ensure_leigod_forward_runtime("wstunnel-svc-2-316", runtime)

        changed = dict(runtime)
        changed["runtime_config"] = dict(runtime["runtime_config"], transport="udp")
        commands: list[str] = []
        with mock.patch.object(agent, "ensure_backend_service"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(slave_agent_module.shutil, "which", return_value="/usr/bin/systemctl"):
                    with mock.patch.object(
                        slave_agent_module,
                        "run_command",
                        side_effect=lambda command, check=False: commands.append(command)
                        or SimpleNamespace(returncode=0, stdout="", stderr=""),
                    ):
                        agent.ensure_leigod_forward_runtime("wstunnel-svc-2-316", changed)

        self.assertIn("systemctl restart wstunnel-svc-2-316-leigod-forward.service", commands)
        self.assertNotIn("systemctl kill -s HUP wstunnel-svc-2-316-leigod-forward.service", commands)


if __name__ == "__main__":
    unittest.main()
