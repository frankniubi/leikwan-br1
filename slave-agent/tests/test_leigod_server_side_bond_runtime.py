import copy
import hashlib
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import time
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


slave_agent = load_module(
    "slave_agent_server_side_bond_under_test",
    "slave-agent/slave_agent.py",
)


class LeigodServerSideBondRuntimeTests(unittest.TestCase):
    def make_agent(self):
        temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(temp_dir.cleanup)
        root = pathlib.Path(temp_dir.name)
        bond_bin = root / "bin" / "leikwan-bond"
        bond_bin.parent.mkdir(parents=True)
        bond_bin.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
        os.chmod(bond_bin, 0o755)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.test",
                    "node_token": "node-token",
                    "state_dir": str(root / "state"),
                    "systemd_dir": str(root / "systemd"),
                    "leigod_root": str(root / "leigod"),
                    "bond_bin": str(bond_bin),
                    "backend_port_range_start": 30000,
                    "backend_port_range_end": 30010,
                }
            ),
            encoding="utf-8",
        )
        return slave_agent.SlaveAgent(config_path), root, bond_bin

    @staticmethod
    def candidate(line_id: int, combination_id: int) -> dict:
        toml = f"line = {line_id}\n"
        return {
            "combination_id": combination_id,
            "line_id": line_id,
            "leg_route_id": line_id,
            "config_sha256": hashlib.sha256(toml.encode()).hexdigest(),
            "socks_host": "127.0.0.1",
            "socks_port": 20000 + line_id,
        }

    @staticmethod
    def task() -> dict:
        return {"service_id": 2, "forward_id": 47, "endpoint_id": 316, "payload": {}}

    def seed_health(self, agent, candidates: list[dict]) -> None:
        checked_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        agent.save_leigod_desired_lines(
            [
                {
                    "line_id": row["line_id"],
                    "config_sha256": row["config_sha256"],
                    "socks_host": "127.0.0.1",
                    "socks_port": row["socks_port"],
                    "tcp_probe_host": "198.51.100.8",
                    "tcp_probe_port": 80,
                }
                for row in candidates
            ]
        )
        agent.save_leigod_line_health(
            {
                row["line_id"]: {
                    "line_id": row["line_id"],
                    "config_sha256": row["config_sha256"],
                    "service_state": "active",
                    "tcp_status": "healthy",
                    "tcp_connect_ms": 10,
                    "udp_status": "unknown",
                    "checked_at": checked_at,
                }
                for row in candidates
            }
        )

    def payload(self) -> dict:
        candidates = [self.candidate(451, 10), self.candidate(453, 11)]
        return {
            "target": "127.0.0.1:58316",
            "listen_port": 7443,
            "path_prefix": "dedicated-route",
            "route_key": "dedicated-route",
            "transport_mode": "tcp",
            "filter_profile": "off",
            "ingress_protocol": "plain_tcp_mux",
            "backend_protocol": "tcp_relay",
            "backend_mode": "leigod_mode",
            "publish_bond_runtime": True,
            "leigod_forward": {
                "mode": "bond",
                "relay_port": 48316,
                "target": "198.51.100.25:18501",
                "candidates": candidates,
                "health_max_age_seconds": 180,
            },
            "server_side_bond": {
                "aggregation_placement": "slave_sidecar",
                "frontend_port": 58316,
                "leg_relay_target": "127.0.0.1:48316",
                "sidecar_target": "198.51.100.25:18501",
                "session_token": "signed-session-token.payload",
                "legs": [{"leg_route_id": 451}, {"leg_route_id": 453}],
                "adaptive_line_admission": True,
                "throughput_min_mbps": 10.0,
                "throughput_relative_floor": 0.35,
                "throughput_min_sample_bytes": 1_048_576,
                "throughput_min_sample_ms": 1_000,
                "throughput_bad_windows": 3,
                "throughput_recovery_windows": 2,
                "throughput_quarantine_ms": 60_000,
                "throughput_probe_interval_ms": 30_000,
                "throughput_probe_bytes": 1_048_576,
            },
        }

    def publish(self, agent, payload: dict):
        self.seed_health(agent, payload["leigod_forward"]["candidates"])
        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            with mock.patch.object(agent, "sync_listener"):
                                with mock.patch.object(agent, "write_filter_marker", return_value={}):
                                    return agent.publish_task(self.task(), payload)

    def test_validation_accepts_complete_server_side_contract(self):
        agent, _root, _bond_bin = self.make_agent()
        payload = self.payload()

        runtime = agent.validate_server_side_bond_payload(
            "wstunnel-svc-2-316", payload, payload["target"]
        )

        self.assertEqual(58316, runtime["frontend_port"])
        self.assertEqual(48316, runtime["relay_port"])
        self.assertEqual([451, 453], runtime["leg_route_ids"])
        self.assertEqual("signed-session-token.payload", runtime["session_token"])
        self.assertTrue(runtime["adaptive_line_admission"])

    def test_validation_rejects_incomplete_or_unsafe_contracts(self):
        agent, _root, _bond_bin = self.make_agent()
        valid = self.payload()
        invalid_cases: dict[str, tuple[dict, str]] = {}

        missing_relay = copy.deepcopy(valid)
        missing_relay.pop("leigod_forward")
        invalid_cases["missing bond relay"] = (missing_relay, "bond relay")

        one_leg = copy.deepcopy(valid)
        one_leg["server_side_bond"]["legs"] = one_leg["server_side_bond"]["legs"][:1]
        invalid_cases["one leg"] = (one_leg, "at least two")

        duplicate_leg = copy.deepcopy(valid)
        duplicate_leg["server_side_bond"]["legs"][1]["leg_route_id"] = 451
        invalid_cases["duplicate leg"] = (duplicate_leg, "duplicate")

        zero_leg = copy.deepcopy(valid)
        zero_leg["server_side_bond"]["legs"][0]["leg_route_id"] = 0
        invalid_cases["zero leg"] = (zero_leg, "route")

        wrong_relay = copy.deepcopy(valid)
        wrong_relay["server_side_bond"]["leg_relay_target"] = "127.0.0.1:48317"
        invalid_cases["mismatched relay"] = (wrong_relay, "relay target")

        no_token = copy.deepcopy(valid)
        no_token["server_side_bond"]["session_token"] = ""
        invalid_cases["missing token"] = (no_token, "session token")

        port_collision = copy.deepcopy(valid)
        port_collision["listen_port"] = 8316
        port_collision["target"] = "127.0.0.1:8316"
        port_collision["server_side_bond"]["frontend_port"] = 8316
        invalid_cases["public local collision"] = (port_collision, "port collision")

        bad_threshold = copy.deepcopy(valid)
        bad_threshold["server_side_bond"]["throughput_relative_floor"] = float("nan")
        invalid_cases["invalid threshold"] = (bad_threshold, "throughput_relative_floor")

        udp_carrier = copy.deepcopy(valid)
        udp_carrier["transport_mode"] = "tcp+udp"
        invalid_cases["non tcp carrier"] = (udp_carrier, "TCP")

        for label, (payload, message) in invalid_cases.items():
            with self.subTest(label=label), self.assertRaisesRegex(RuntimeError, message):
                agent.validate_server_side_bond_payload(
                    "wstunnel-svc-2-316", payload, payload.get("target", "")
                )

    def test_publish_writes_private_credentials_hardened_unit_and_sanitized_metadata(self):
        agent, _root, _bond_bin = self.make_agent()
        payload = self.payload()

        result = self.publish(agent, payload)

        service_name = "wstunnel-svc-2-316"
        credential_path = agent.server_side_bond_credential_path(service_name)
        unit_path = agent.service_unit_path(agent.server_side_bond_service_name(service_name))
        credential = credential_path.read_text(encoding="utf-8")
        unit = unit_path.read_text(encoding="utf-8")
        metadata = agent.load_service_metadata(service_name)
        self.assertEqual(0o600, credential_path.stat().st_mode & 0o777)
        self.assertIn("signed-session-token.payload", credential)
        self.assertNotIn("signed-session-token.payload", unit)
        self.assertIn(f"EnvironmentFile={credential_path}", unit)
        self.assertIn("--listen 127.0.0.1:58316", unit)
        self.assertIn("leg_route_id=451", unit)
        self.assertIn("leg_route_id=453", unit)
        self.assertIn("--adaptive-line-admission", unit)
        self.assertIn("--throughput-min-mbps 10.0", unit)
        self.assertNotIn("signed-session-token.payload", json.dumps(metadata))
        self.assertTrue(metadata["server_side_bond"]["session_token_present"])
        self.assertEqual(
            hashlib.sha256(b"signed-session-token.payload").hexdigest(),
            metadata["server_side_bond"]["session_token_sha256"],
        )
        self.assertEqual(
            agent.server_side_bond_service_name(service_name),
            result["server_side_bond_service_name"],
        )
        self.assertIn(58316, agent.used_backend_ports())

    def test_publish_orders_relay_then_bond_then_backend(self):
        agent, _root, _bond_bin = self.make_agent()
        payload = self.payload()
        self.seed_health(agent, payload["leigod_forward"]["candidates"])
        events: list[str] = []

        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_leigod_forward_runtime", side_effect=lambda *_: events.append("relay")):
                with mock.patch.object(agent, "ensure_server_side_bond_runtime", side_effect=lambda *_: events.append("bond")):
                    with mock.patch.object(agent, "ensure_backend_service", side_effect=lambda *_args, **_kwargs: events.append("backend")):
                        with mock.patch.object(agent, "ensure_nft_table"):
                            with mock.patch.object(agent, "delete_nft_rules"):
                                with mock.patch.object(agent, "add_nft_rules"):
                                    with mock.patch.object(agent, "sync_listener"):
                                        with mock.patch.object(agent, "write_filter_marker", return_value={}):
                                            agent.publish_task(self.task(), payload)

        self.assertEqual(["relay", "bond", "backend"], events[:3])

    def test_publish_failure_leaves_existing_public_route_inactive(self):
        agent, _root, _bond_bin = self.make_agent()
        payload = self.payload()
        self.publish(agent, payload)
        self.seed_health(agent, payload["leigod_forward"]["candidates"])

        with mock.patch.object(agent, "ensure_leigod_forward_runtime"):
            with mock.patch.object(
                agent,
                "ensure_server_side_bond_runtime",
                side_effect=RuntimeError("bond failed"),
            ):
                with mock.patch.object(agent, "sync_listener"):
                    with self.assertRaisesRegex(RuntimeError, "bond failed"):
                        agent.publish_task(self.task(), payload)

        self.assertEqual("provisioning", agent.load_service_metadata("wstunnel-svc-2-316")["status"])

    def test_mode_change_suspend_and_destroy_remove_only_bond_runtime(self):
        agent, _root, _bond_bin = self.make_agent()
        payload = self.payload()
        self.publish(agent, payload)
        service_name = "wstunnel-svc-2-316"
        credential_path = agent.server_side_bond_credential_path(service_name)
        unit_path = agent.service_unit_path(agent.server_side_bond_service_name(service_name))

        legacy = copy.deepcopy(payload)
        legacy.pop("server_side_bond")
        legacy["target"] = "127.0.0.1:48316"
        self.publish(agent, legacy)
        self.assertFalse(credential_path.exists())
        self.assertFalse(unit_path.exists())
        self.assertEqual({}, agent.load_service_metadata(service_name)["server_side_bond"])

        self.publish(agent, payload)
        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "sync_listener"):
                with mock.patch.object(agent, "remove_sidecar_uplink"):
                    agent.stop_service(self.task(), keep_files=True)
        self.assertFalse(credential_path.exists())
        self.assertFalse(unit_path.exists())
        metadata = agent.load_service_metadata(service_name)
        self.assertEqual("suspended", metadata["status"])
        self.assertEqual({}, metadata["server_side_bond"])

        self.publish(agent, payload)
        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "sync_listener"):
                with mock.patch.object(agent, "remove_sidecar_uplink"):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        agent.destroy_service(self.task())
        self.assertFalse(credential_path.exists())
        self.assertFalse(unit_path.exists())
        self.assertIsNone(agent.load_service_metadata(service_name))

    def test_missing_bond_binary_blocks_activation(self):
        agent, _root, bond_bin = self.make_agent()
        payload = self.payload()
        runtime = agent.validate_server_side_bond_payload(
            "wstunnel-svc-2-316", payload, payload["target"]
        )
        bond_bin.unlink()

        with self.assertRaisesRegex(RuntimeError, "bond binary"):
            agent.ensure_server_side_bond_runtime("wstunnel-svc-2-316", runtime)


if __name__ == "__main__":
    unittest.main()
