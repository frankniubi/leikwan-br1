import copy
import hashlib
import importlib.util
import json
import os
import pathlib
import stat
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
    "slave_agent_linux_hybrid_under_test",
    "slave-agent/slave_agent.py",
)


class LeigodLinuxHybridRuntimeTests(unittest.TestCase):
    def make_agent(self):
        temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(temp_dir.cleanup)
        root = pathlib.Path(temp_dir.name)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.test",
                    "node_id": 101,
                    "node_token": "node-token",
                    "state_dir": str(root / "state"),
                    "systemd_dir": str(root / "systemd"),
                    "leigod_root": str(root / "leigod"),
                    "leigod_bin": sys.executable,
                    "backend_port_range_start": 30000,
                    "backend_port_range_end": 30010,
                }
            ),
            encoding="utf-8",
        )
        return slave_agent.SlaveAgent(config_path), root

    @staticmethod
    def managed_line(candidate: dict, *, generation: int | None = None) -> dict:
        line_id = int(candidate["line_id"])
        return {
            "line_id": line_id,
            "label": f"line-{line_id}",
            "config_sha256": candidate["config_sha256"],
            "socks_host": candidate["socks_host"],
            "socks_port": candidate["socks_port"],
            "tcp_probe_host": "198.51.100.8",
            "tcp_probe_port": 80,
            "toml": f"line = {line_id}\n",
            "lease_id": candidate["lease_id"],
            "lease_generation": generation or candidate["lease_generation"],
            "lease_state": "active",
            "max_parallel_lanes": 1,
            "lease_owner_type": "node",
        }

    @staticmethod
    def candidate(line_id: int = 451, route_id: int = 451) -> dict:
        toml = f"line = {line_id}\n"
        return {
            "combination_id": 10,
            "line_id": line_id,
            "leg_route_id": route_id,
            "lease_id": f"00000000-0000-4000-8000-{line_id:012d}",
            "lease_generation": 7,
            "config_sha256": hashlib.sha256(toml.encode()).hexdigest(),
            "socks_host": "127.0.0.1",
            "socks_port": 20000 + line_id,
        }

    @staticmethod
    def task() -> dict:
        return {"service_id": 2, "forward_id": 47, "endpoint_id": 316, "payload": {}}

    def payload(self) -> dict:
        return {
            "target": "127.0.0.1:48316",
            "listen_port": 7003,
            "path_prefix": "fixed",
            "route_key": "fixed",
            "transport_mode": "tcp",
            "filter_profile": "off",
            "ingress_protocol": "plain_tcp_mux",
            "backend_protocol": "tcp_relay",
            "backend_mode": "leigod_mode",
            "publish_bond_runtime": True,
            "leigod_forward": {
                "mode": "bond",
                "relay_port": 48316,
                "target": "198.51.100.50:7443",
                "candidates": [self.candidate()],
                "health_max_age_seconds": 180,
            },
            "hybrid_relay": {
                "placement": "linux_hybrid",
                "target": "198.51.100.50:7443",
            },
            "server_side_bond": None,
        }

    def seed_lines(
        self,
        agent,
        candidates: list[dict],
        *,
        owner_type: str = "node",
        owner_id: int = 101,
    ) -> None:
        checked_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        desired = []
        health = {}
        for row in candidates:
            desired.append(
                {
                    "line_id": row["line_id"],
                    "config_sha256": row["config_sha256"],
                    "socks_host": row["socks_host"],
                    "socks_port": row["socks_port"],
                    "tcp_probe_host": "198.51.100.8",
                    "tcp_probe_port": 80,
                    "lease_id": row["lease_id"],
                    "lease_generation": row["lease_generation"],
                    "lease_state": "active",
                    "max_parallel_lanes": 1,
                    "lease_owner_type": owner_type,
                    "lease_owner_id": owner_id,
                }
            )
            health[row["line_id"]] = {
                "line_id": row["line_id"],
                "config_sha256": row["config_sha256"],
                "service_state": "active",
                "tcp_status": "healthy",
                "tcp_connect_ms": 10,
                "udp_status": "unknown",
                "checked_at": checked_at,
                "lease_id": row["lease_id"],
                "lease_generation": row["lease_generation"],
                "lease_state": "active",
                "max_parallel_lanes": 1,
                "lease_owner_type": owner_type,
                "lease_owner_id": owner_id,
            }
        agent.save_leigod_desired_lines(desired)
        agent.save_leigod_line_health(health)

    def publish(
        self,
        agent,
        payload: dict,
        events: list[str] | None = None,
        *,
        seed: bool = True,
    ):
        if seed:
            self.seed_lines(agent, payload["leigod_forward"]["candidates"])
        events = events if events is not None else []
        original_relay = agent.ensure_leigod_forward_runtime

        def relay(service_name, runtime):
            events.append("relay")
            return original_relay(service_name, runtime)

        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_leigod_forward_runtime", side_effect=relay):
                with mock.patch.object(
                    agent,
                    "ensure_server_side_bond_runtime",
                    side_effect=lambda *_: events.append("bond"),
                ):
                    with mock.patch.object(
                        agent,
                        "ensure_backend_service",
                        side_effect=lambda *_args, **_kwargs: events.append("backend"),
                    ):
                        with mock.patch.object(agent, "ensure_nft_table"):
                            with mock.patch.object(agent, "delete_nft_rules"):
                                with mock.patch.object(agent, "add_nft_rules"):
                                    with mock.patch.object(agent, "sync_listener"):
                                        with mock.patch.object(agent, "write_filter_marker", return_value={}):
                                            result = agent.publish_task(self.task(), payload)
        return result, events

    def test_validation_accepts_one_fenced_flat_relay_candidate(self):
        agent, _root = self.make_agent()
        payload = self.payload()
        self.seed_lines(agent, payload["leigod_forward"]["candidates"])

        runtime = agent.validate_leigod_forward_payload(
            "wstunnel-svc-2-316", self.task(), payload, payload["target"]
        )

        self.assertEqual("linux_hybrid", runtime["placement"])
        self.assertEqual([451], [row["leg_route_id"] for row in runtime["runtime_config"]["candidates"]])
        self.assertEqual("linux_hybrid", runtime["runtime_config"]["placement"])

    def test_validation_rejects_stale_client_owned_duplicate_and_mismatched_contracts(self):
        agent, _root = self.make_agent()
        valid = self.payload()
        stale = copy.deepcopy(valid)
        stale["leigod_forward"]["candidates"][0]["lease_generation"] = 6

        client_owned = copy.deepcopy(valid)

        missing_owner = copy.deepcopy(valid)

        wrong_owner = copy.deepcopy(valid)

        duplicate = copy.deepcopy(valid)
        second = self.candidate(452, 451)
        duplicate["leigod_forward"]["candidates"].append(second)

        target_mismatch = copy.deepcopy(valid)
        target_mismatch["hybrid_relay"]["target"] = "198.51.100.51:7443"

        non_tcp = copy.deepcopy(valid)
        non_tcp["transport_mode"] = "tcp+udp"

        nested = copy.deepcopy(valid)
        nested["server_side_bond"] = {"aggregation_placement": "slave_sidecar"}

        cases = [
            (stale, "lease", "node", 101, valid["leigod_forward"]["candidates"]),
            (client_owned, "node", "client_device", 101, client_owned["leigod_forward"]["candidates"]),
            (missing_owner, "node", "", 0, missing_owner["leigod_forward"]["candidates"]),
            (wrong_owner, "node", "node", 102, wrong_owner["leigod_forward"]["candidates"]),
            (duplicate, "route", "node", 101, duplicate["leigod_forward"]["candidates"]),
            (target_mismatch, "target", "node", 101, target_mismatch["leigod_forward"]["candidates"]),
            (non_tcp, "TCP", "node", 101, non_tcp["leigod_forward"]["candidates"]),
            (nested, "server_side_bond", "node", 101, nested["leigod_forward"]["candidates"]),
        ]
        for payload, message, owner_type, owner_id, desired_candidates in cases:
            self.seed_lines(
                agent,
                desired_candidates,
                owner_type=owner_type,
                owner_id=owner_id,
            )
            with self.subTest(message=message), self.assertRaisesRegex(RuntimeError, message):
                agent.validate_leigod_forward_payload(
                    "wstunnel-svc-2-316", self.task(), payload, payload["target"]
                )

    def test_publish_starts_relay_then_ingress_and_never_starts_slave_bond_client(self):
        agent, _root = self.make_agent()
        payload = self.payload()

        result, events = self.publish(agent, payload)

        self.assertEqual("relay", events[0])
        self.assertIn("backend", events)
        self.assertNotIn("bond", events)
        self.assertIsNone(result["server_side_bond_service_name"])
        metadata = agent.load_service_metadata("wstunnel-svc-2-316")
        self.assertEqual("linux_hybrid", metadata["hybrid_relay"]["placement"])
        self.assertEqual({}, metadata["server_side_bond"])
        lease_id = payload["leigod_forward"]["candidates"][0]["lease_id"]
        self.assertNotIn(lease_id, json.dumps(metadata, sort_keys=True))
        self.assertEqual(
            hashlib.sha256(lease_id.encode()).hexdigest(),
            metadata["leigod_forward"]["candidates"][0]["lease_id_sha256"],
        )
        self.assertEqual(
            0o600,
            stat.S_IMODE(agent.metadata_path("wstunnel-svc-2-316").stat().st_mode),
        )
        config = json.loads(
            agent.leigod_forward_config_path("wstunnel-svc-2-316").read_text(encoding="utf-8")
        )
        self.assertEqual("127.0.0.1", config["listen_host"])

    def test_mode_change_suspend_and_destroy_keep_unrelated_line_leases(self):
        agent, _root = self.make_agent()
        payload = self.payload()
        extra = self.candidate(499, 499)
        self.seed_lines(agent, payload["leigod_forward"]["candidates"] + [extra])
        self.publish(agent, payload, seed=False)
        service_name = "wstunnel-svc-2-316"
        config_path = agent.leigod_forward_config_path(service_name)

        legacy = copy.deepcopy(payload)
        legacy.pop("hybrid_relay")
        legacy.pop("leigod_forward")
        legacy.pop("server_side_bond")
        legacy["target"] = "198.51.100.50:7443"
        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_backend_service"):
                with mock.patch.object(agent, "ensure_nft_table"):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(agent, "sync_listener"):
                                with mock.patch.object(agent, "write_filter_marker", return_value={}):
                                    agent.publish_task(self.task(), legacy)
        self.assertFalse(config_path.exists())
        self.assertEqual({451, 499}, {row["line_id"] for row in agent.load_leigod_desired_lines()})

        self.publish(agent, payload, seed=False)
        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "sync_listener"):
                with mock.patch.object(agent, "remove_sidecar_uplink"):
                    agent.stop_service(self.task(), keep_files=True)
        self.assertFalse(config_path.exists())

        self.publish(agent, payload, seed=False)
        with mock.patch.object(slave_agent.shutil, "which", return_value=None):
            with mock.patch.object(agent, "sync_listener"):
                with mock.patch.object(agent, "remove_sidecar_uplink"):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        agent.destroy_service(self.task())
        self.assertFalse(config_path.exists())
        self.assertEqual({451, 499}, {row["line_id"] for row in agent.load_leigod_desired_lines()})

    def test_lease_revocation_removes_only_the_referencing_hybrid_relay(self):
        agent, _root = self.make_agent()
        payload = self.payload()
        extra = self.candidate(499, 499)
        self.seed_lines(agent, payload["leigod_forward"]["candidates"] + [extra])
        self.publish(agent, payload, seed=False)
        service_name = "wstunnel-svc-2-316"

        removed = agent.remove_hybrid_relays_referencing_lines({451})

        self.assertEqual({service_name}, removed)
        self.assertFalse(agent.leigod_forward_config_path(service_name).exists())
        metadata = agent.load_service_metadata(service_name)
        self.assertEqual({}, metadata["leigod_forward"])
        self.assertEqual({}, metadata["hybrid_relay"])
        self.assertEqual("degraded", metadata["status"])
        self.assertEqual({451, 499}, {row["line_id"] for row in agent.load_leigod_desired_lines()})

    def test_lease_generation_change_removes_stale_relay_and_keeps_unrelated_line(self):
        agent, root = self.make_agent()
        payload = self.payload()
        leased = payload["leigod_forward"]["candidates"][0]
        unrelated = self.candidate(499, 499)
        self.seed_lines(agent, [leased, unrelated])
        self.publish(agent, payload, seed=False)
        session_path = root / "leigod" / "session.json"
        session_path.parent.mkdir(parents=True, exist_ok=True)
        session_path.write_text("{}\n", encoding="utf-8")

        with mock.patch.object(agent, "ensure_leigod_line_service"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                agent.apply_leigod_lines(
                    [
                        self.managed_line(leased, generation=8),
                        self.managed_line(unrelated),
                    ]
                )

        service_name = "wstunnel-svc-2-316"
        self.assertFalse(agent.leigod_forward_config_path(service_name).exists())
        metadata = agent.load_service_metadata(service_name)
        self.assertEqual({}, metadata["hybrid_relay"])
        desired = {row["line_id"]: row for row in agent.load_leigod_desired_lines()}
        self.assertEqual(8, desired[451]["lease_generation"])
        self.assertEqual(7, desired[499]["lease_generation"])


if __name__ == "__main__":
    unittest.main()
