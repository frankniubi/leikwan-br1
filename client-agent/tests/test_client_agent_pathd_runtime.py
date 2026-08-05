import hashlib
import importlib.util
import json
import os
import pathlib
import stat
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


client_agent = load_module("client_agent_pathd_runtime_under_test", "client-agent/client_agent.py")


class ClientAgentPathdRuntimeTests(unittest.TestCase):
    def make_agent(self, command_runner=None):
        temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(temp_dir.cleanup)
        root = pathlib.Path(temp_dir.name)
        pathd_bin = root / "bin" / "leikwan-pathd"
        provider_bin = root / "bin" / "provider"
        pathd_bin.parent.mkdir(parents=True)
        pathd_bin.write_bytes(b"pathd-binary")
        provider_bin.write_bytes(b"provider-binary")
        if os.name != "nt":
            pathd_bin.chmod(0o755)
            provider_bin.chmod(0o755)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.test",
                    "agent_token": "agent-token",
                    "client_id": 20,
                    "device_id": "linux-device-a",
                    "state_dir": str(root / "state"),
                    "pathd_bin": str(pathd_bin),
                    "leigod_bin": str(provider_bin),
                    "pathd_runtime_dir": str(root / "run"),
                    "systemd_dir": str(root / "systemd"),
                    "pathd_status_timeout_seconds": 0,
                }
            ),
            encoding="utf-8",
        )
        agent = client_agent.ClientAgent(config_path, command_runner=command_runner)
        return agent, root, pathd_bin

    @staticmethod
    def leased_line(line_id: int = 451, state: str = "active") -> dict:
        toml = f"line = {line_id}\n"
        return {
            "catalog_id": line_id - 440,
            "line_id": line_id,
            "config_sha256": hashlib.sha256(toml.encode()).hexdigest(),
            "toml": toml,
            "socks_host": "127.0.0.1",
            "socks_port": 20000 + line_id,
            "lease_id": f"00000000-0000-4000-8000-{line_id:012d}",
            "lease_generation": 7,
            "lease_state": state,
            "lease_owner_type": "client_device",
            "lease_owner_id": 20,
            "lease_owner_device_id": "linux-device-a",
            "max_parallel_lanes": 2,
            "ready_deadline_at": "2099-08-06 00:00:00",
            "expires_at": "2099-08-06 00:00:00",
        }

    @classmethod
    def path_runtime(cls, forward_id: int, listen_port: int, endpoint_id: int) -> dict:
        local = cls.leased_line()
        slave_line = 452 + endpoint_id
        return {
            "forward_id": forward_id,
            "listen_port": listen_port,
            "path_prefix": f"route-{forward_id}",
            "path_runtime": {
                "engine": "linux_pathd",
                "placement": "linux_hybrid",
                "protocol": "tcp",
                "egress": {
                    "host": "198.51.100.50",
                    "port": 7443,
                    "session_token": f"session-{forward_id}",
                },
                "local_leases": [
                    {
                        "execution_group": "client:20:linux-device-a",
                        "physical_line_id": 451,
                        "lease_id": local["lease_id"],
                        "lease_generation": 7,
                        "max_lanes": 2,
                        "routes": [
                            {"lane": 1, "route_id": 451},
                            {"lane": 2, "route_id": 1_004_512},
                        ],
                    }
                ],
                "slave_legs": [
                    {
                        "execution_group": f"node:{endpoint_id}",
                        "endpoint_id": endpoint_id,
                        "node_id": endpoint_id,
                        "host": "192.0.2.10",
                        "port": 7003,
                        "ingress_auth_token": f"auth-{endpoint_id}",
                        "physical_line_id": slave_line,
                        "lease_id": f"00000000-0000-4000-8000-{slave_line:012d}",
                        "lease_generation": 3,
                        "route_id": slave_line,
                    }
                ],
                "policy": {
                    "adaptive_line_admission": True,
                    "throughput_min_mbps": 10.0,
                    "latency_first": True,
                    "interactive_max_bytes": 65536,
                },
            },
        }

    def payload(self, pathd_bin: pathlib.Path, forwards=None, lines=None, revision: int = 12) -> dict:
        return {
            "config_revision": revision,
            "client": {
                "id": 20,
                "desired_pathd_version": "0.2.0",
                "desired_pathd_sha256": hashlib.sha256(pathd_bin.read_bytes()).hexdigest(),
            },
            "forwards": forwards if forwards is not None else [
                self.path_runtime(42, 11042, 312),
                self.path_runtime(41, 11041, 311),
            ],
            "linux_path_leases": {
                "session_json": '{"token":"root-only"}',
                "lines": lines if lines is not None else [self.leased_line()],
            },
        }

    def test_builds_one_deterministic_generation_for_two_forwards(self):
        agent, _root, pathd_bin = self.make_agent()

        desired = agent.build_pathd_desired(self.payload(pathd_bin))

        self.assertEqual(12, desired["generation"])
        self.assertEqual([451], [row["line_id"] for row in desired["local_leases"]])
        self.assertEqual([41, 42], [row["forward_id"] for row in desired["forwards"]])
        self.assertEqual(["endpoint-311", "endpoint-312"], sorted(desired["auth_refs"]))
        self.assertEqual(3, len(desired["forwards"][0]["legs"]))
        self.assertEqual("0.0.0.0:11041", desired["forwards"][0]["listen"])
        self.assertNotIn("auth-311", json.dumps(desired["forwards"][0], sort_keys=True))

    def test_pending_lease_starts_provider_generation_without_forwards(self):
        commands = []
        holder = {}

        def command_runner(parts):
            commands.append(tuple(parts))
            if parts[:3] == ["systemctl", "enable", "--now"]:
                desired = json.loads(holder["agent"].pathd_desired_path().read_text(encoding="utf-8"))
                holder["agent"].pathd_status_path().write_text(
                    json.dumps(
                        {
                            "generation": desired["generation"],
                            "status": "degraded",
                            "forwards": {},
                            "local_lines": [],
                        }
                    ),
                    encoding="utf-8",
                )
            return 0

        agent, _root, pathd_bin = self.make_agent(command_runner=command_runner)
        holder["agent"] = agent
        payload = self.payload(pathd_bin, forwards=[], lines=[self.leased_line(state="pending")])

        desired = agent.build_pathd_desired(payload)

        self.assertEqual([], desired["forwards"])
        self.assertEqual("00000000-0000-4000-8000-000000000451", desired["local_leases"][0]["lease_id"])
        self.assertTrue(agent.reconcile_pathd(payload))
        self.assertIn(("systemctl", "enable", "--now", "leikwan-pathd.service"), commands)

    def test_expired_pending_ready_deadline_is_rejected(self):
        agent, _root, pathd_bin = self.make_agent()
        stale_pending = self.leased_line(state="pending")
        stale_pending["ready_deadline_at"] = "2000-01-01 00:00:00"

        with self.assertRaisesRegex(RuntimeError, "deadline"):
            agent.build_pathd_desired(self.payload(pathd_bin, forwards=[], lines=[stale_pending]))

    def test_local_lease_owner_is_required_and_device_scoped(self):
        agent, _root, pathd_bin = self.make_agent()
        base_line = self.leased_line()
        cases = [
            ("missing owner type", {key: value for key, value in base_line.items() if key != "lease_owner_type"}),
            ("missing owner id", {key: value for key, value in base_line.items() if key != "lease_owner_id"}),
            (
                "missing owner device",
                {key: value for key, value in base_line.items() if key != "lease_owner_device_id"},
            ),
            ("wrong owner type", dict(base_line, lease_owner_type="node")),
            ("wrong owner id", dict(base_line, lease_owner_id=21)),
            ("wrong owner device", dict(base_line, lease_owner_device_id="linux-device-b")),
        ]

        for label, line in cases:
            with self.subTest(label=label):
                with self.assertRaisesRegex(RuntimeError, "owner"):
                    agent.build_pathd_desired(self.payload(pathd_bin, forwards=[], lines=[line]))

    def test_runtime_execution_groups_match_the_fenced_owner(self):
        agent, _root, pathd_bin = self.make_agent()
        wrong_client = self.payload(pathd_bin)
        wrong_client["forwards"][0]["path_runtime"]["local_leases"][0]["execution_group"] = (
            "client:20:linux-device-b"
        )
        wrong_node = self.payload(pathd_bin)
        wrong_node["forwards"][0]["path_runtime"]["slave_legs"][0]["execution_group"] = "node:999"

        for label, payload in [("client", wrong_client), ("node", wrong_node)]:
            with self.subTest(owner=label):
                with self.assertRaisesRegex(RuntimeError, "execution group"):
                    agent.build_pathd_desired(payload)

    def test_reconcile_writes_root_only_then_hups_one_system_service(self):
        events = []
        holder = {}

        def command_runner(parts):
            events.append(("command", tuple(parts)))
            if parts[:4] == ["systemctl", "kill", "-s", "HUP"]:
                desired = json.loads(holder["agent"].pathd_desired_path().read_text(encoding="utf-8"))
                events.append(("observed_generation", desired["generation"]))
                holder["agent"].pathd_status_path().write_text(
                    json.dumps(
                        {
                            "generation": desired["generation"],
                            "status": "ready",
                            "forwards": {
                                str(row["forward_id"]): {"listener": "ready", "sessions": 0}
                                for row in desired["forwards"]
                            },
                            "local_lines": [],
                        }
                    ),
                    encoding="utf-8",
                )
            return 0

        agent, _root, pathd_bin = self.make_agent(command_runner=command_runner)
        holder["agent"] = agent
        old = self.payload(pathd_bin, revision=11)
        old["forwards"] = []
        agent.atomic_write_pathd_desired(agent.build_pathd_desired(old))
        agent.pathd_status_path().write_text(
            json.dumps({"generation": 11, "status": "ready", "forwards": {}, "local_lines": []}),
            encoding="utf-8",
        )

        real_replace = client_agent.os.replace

        def record_replace(source, destination):
            events.append(("replace", pathlib.Path(destination).name))
            return real_replace(source, destination)

        with mock.patch.object(
            client_agent.os,
            "fsync",
            side_effect=lambda _fd: events.append(("fsync",)),
        ):
            with mock.patch.object(client_agent.os, "replace", side_effect=record_replace):
                self.assertTrue(agent.reconcile_pathd(self.payload(pathd_bin)))

        desired = json.loads(agent.pathd_desired_path().read_text(encoding="utf-8"))
        self.assertEqual(12, desired["generation"])
        if os.name != "nt":
            self.assertEqual(0o600, stat.S_IMODE(agent.pathd_desired_path().stat().st_mode))
        self.assertIn(("observed_generation", 12), events)
        unit = agent.pathd_unit_path().read_text(encoding="utf-8")
        self.assertIn("KillMode=control-group", unit)
        self.assertIn("NoNewPrivileges=true", unit)
        self.assertIn("ProtectHome=true", unit)
        self.assertIn(
            f"ExecStart={agent.pathd_binary_path()} --config {agent.pathd_runtime_dir()}/desired.json"
            f" --status {agent.pathd_runtime_dir()}/status.json",
            unit,
        )
        self.assertEqual([agent.pathd_unit_path()], list(agent.pathd_unit_path().parent.glob("leikwan-pathd*.service")))
        self.assertEqual([], list(agent.pathd_unit_path().parent.glob("leikwan-client-*.service")))
        fsync_index = next(index for index, event in enumerate(events) if event[0] == "fsync")
        replace_index = next(index for index, event in enumerate(events) if event[0] == "replace")
        hup_index = next(
            index
            for index, event in enumerate(events)
            if event == ("command", ("systemctl", "kill", "-s", "HUP", "leikwan-pathd.service"))
        )
        self.assertLess(fsync_index, replace_index)
        self.assertLess(replace_index, hup_index)

    def test_invalid_or_rejected_generation_retains_previous_desired(self):
        commands = []
        agent, _root, pathd_bin = self.make_agent(
            command_runner=lambda parts: commands.append(tuple(parts)) or 0
        )
        previous = self.payload(pathd_bin, revision=11)
        previous["forwards"] = []
        agent.atomic_write_pathd_desired(agent.build_pathd_desired(previous))
        previous_bytes = agent.pathd_desired_path().read_bytes()
        agent.pathd_status_path().write_text(
            json.dumps({"generation": 11, "status": "ready", "forwards": {}, "local_lines": []}),
            encoding="utf-8",
        )

        self.assertFalse(agent.reconcile_pathd(self.payload(pathd_bin, revision=12)))
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())
        self.assertNotIn(("systemctl", "stop", "leikwan-pathd.service"), commands)

        stale_generation = self.payload(pathd_bin, revision=10)
        self.assertFalse(agent.reconcile_pathd(stale_generation))
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())

        same_generation_drift = self.payload(pathd_bin, revision=11)
        self.assertFalse(agent.reconcile_pathd(same_generation_drift))
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())

        duplicate = self.payload(pathd_bin)
        duplicate["forwards"][1]["listen_port"] = duplicate["forwards"][0]["listen_port"]
        with self.assertRaisesRegex(RuntimeError, "listener"):
            agent.build_pathd_desired(duplicate)
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())

        stale_fence = self.payload(pathd_bin)
        stale_fence["linux_path_leases"]["lines"][0]["lease_generation"] = 8
        with self.assertRaisesRegex(RuntimeError, "stale"):
            agent.build_pathd_desired(stale_fence)
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())

        malformed = self.payload(pathd_bin)
        malformed["forwards"][0]["path_runtime"]["unexpected_secret"] = "must-not-activate"
        with self.assertRaisesRegex(RuntimeError, "unsupported"):
            agent.build_pathd_desired(malformed)
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())

        bad_meta = self.payload(pathd_bin)
        bad_meta["client"]["desired_pathd_sha256"] = "0" * 64
        self.assertFalse(agent.reconcile_pathd(bad_meta))
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())

        missing_binary = self.payload(pathd_bin)
        pathd_bin.unlink()
        self.assertFalse(agent.reconcile_pathd(missing_binary))
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())

    def test_capability_is_cleared_when_artifact_becomes_hash_mismatched(self):
        agent, _root, pathd_bin = self.make_agent()
        payload = self.payload(pathd_bin)
        self.assertTrue(agent.validate_pathd_artifact(payload["client"]))
        self.assertNotEqual({}, agent.pathd_heartbeat(payload["client"])["capability"])

        pathd_bin.write_bytes(b"tampered-pathd-binary")

        self.assertEqual({}, agent.pathd_heartbeat(payload["client"])["capability"])

    def test_malformed_pathd_status_restores_previous_generation(self):
        commands = []
        agent, _root, pathd_bin = self.make_agent(command_runner=lambda parts: commands.append(tuple(parts)) or 0)
        previous = self.payload(pathd_bin, revision=11)
        previous["forwards"] = []
        agent.atomic_write_pathd_desired(agent.build_pathd_desired(previous))
        previous_bytes = agent.pathd_desired_path().read_bytes()
        agent.pathd_status_path().write_text(
            json.dumps({"generation": "secret-not-a-generation", "status": "ready", "forwards": {}}),
            encoding="utf-8",
        )

        self.assertFalse(agent.reconcile_pathd(self.payload(pathd_bin, revision=12)))
        self.assertEqual(previous_bytes, agent.pathd_desired_path().read_bytes())
        self.assertNotIn(("systemctl", "stop", "leikwan-pathd.service"), commands)

    def test_sync_collects_hybrid_forwards_before_per_forward_renderer_and_reports_health(self):
        agent, _root, pathd_bin = self.make_agent()
        payload = self.payload(pathd_bin)
        events = []
        heartbeats = []
        agent.apply_management_state = lambda _meta: None
        agent.consume_local_refresh_requests = lambda: set()
        agent.reconcile_pathd = lambda _payload: events.append("pathd") or True
        agent.apply_forward = lambda *_args, **_kwargs: self.fail("hybrid forward used per-forward renderer")
        agent.reconcile_global_mptcp_state = lambda _forwards: None
        agent.run_diagnostics_if_requested = lambda *_args: None
        agent.collect_usage_totals = lambda: {"bytes_in": 0, "bytes_out": 0}
        agent.ensure_wireguard_identity = lambda: ""
        agent.time_sync_state = lambda: {}
        agent.load_bond_leg_reputation_snapshots = lambda: []
        agent.running_kernel_supports_mptcp = lambda: False
        agent.latest_pathd_metadata = {
            "version": payload["client"]["desired_pathd_version"],
            "sha256": payload["client"]["desired_pathd_sha256"],
        }

        def fake_post(_method, _url, _token, body=None, **_kwargs):
            heartbeats.append(body)
            return {}

        with mock.patch.object(client_agent, "http_json_with_headers", return_value=(payload, {})):
            with mock.patch.object(client_agent, "http_json", side_effect=fake_post):
                agent.sync_once()

        self.assertEqual(["pathd"], events)
        applied = json.loads(agent.applied_path.read_text(encoding="utf-8"))
        self.assertEqual("linux_pathd", applied["41"]["runtime_mode"])
        self.assertEqual("linux_pathd", applied["42"]["runtime_mode"])
        self.assertEqual(payload["client"]["desired_pathd_sha256"], heartbeats[0]["leikwan_pathd"]["sha256"])
        self.assertEqual("linux-device-a", heartbeats[0]["device_id"])

    def test_sync_rejects_unknown_path_engine_without_touching_existing_listener(self):
        agent, _root, pathd_bin = self.make_agent()
        malformed = self.path_runtime(41, 11041, 311)
        malformed["path_runtime"]["engine"] = "unknown-engine"
        payload = self.payload(pathd_bin, forwards=[malformed], lines=[])
        previous_applied = {
            "41": {
                "runtime_mode": "single",
                "service_name": "leikwan-client-41",
                "contract_signature": "previous-good",
            }
        }
        agent.applied_path.write_text(json.dumps(previous_applied), encoding="utf-8")
        agent.apply_management_state = lambda _meta: None
        agent.consume_local_refresh_requests = lambda: set()
        agent.apply_forward = lambda *_args, **_kwargs: self.fail("unknown engine reached renderer")
        agent.stop_forward = lambda *_args, **_kwargs: self.fail("existing listener was stopped")
        agent.reconcile_global_mptcp_state = lambda _forwards: None
        agent.run_diagnostics_if_requested = lambda *_args: None
        agent.collect_usage_totals = lambda: {"bytes_in": 0, "bytes_out": 0}
        agent.ensure_wireguard_identity = lambda: ""
        agent.time_sync_state = lambda: {}
        agent.load_bond_leg_reputation_snapshots = lambda: []
        agent.running_kernel_supports_mptcp = lambda: False

        with mock.patch.object(client_agent, "http_json_with_headers", return_value=(payload, {})):
            with mock.patch.object(client_agent, "http_json", return_value={}):
                agent.sync_once()

        applied = json.loads(agent.applied_path.read_text(encoding="utf-8"))
        self.assertEqual(previous_applied, applied)

    def test_legacy_health_and_watchdog_ignore_pathd_forwards(self):
        agent, _root, _pathd_bin = self.make_agent()
        forward = self.path_runtime(41, 11041, 311)
        agent.systemd_is_active = lambda _name: self.fail("legacy service health checked pathd forward")
        agent.restart_forward_service = lambda *_args: self.fail("legacy service restarted pathd forward")

        agent.ensure_runtime_health([forward])

        class FakeWatchdog:
            def update_config(self, _config):
                pass

            def tick(self, _forwards):
                self.fail("CFWG watchdog received pathd forward")

        fake_watchdog = FakeWatchdog()
        fake_watchdog.fail = self.fail
        agent.config["cfwg_latency_watchdog"] = {"enabled": True, "forward_ids": [41]}
        agent._cfwg_latency_watchdog = fake_watchdog

        agent.run_cfwg_latency_watchdog([forward])

    def test_heartbeat_is_bounded_and_excludes_desired_secrets(self):
        agent, _root, pathd_bin = self.make_agent()
        payload = self.payload(pathd_bin)
        agent.latest_linux_path_leases = payload["linux_path_leases"]
        agent.latest_pathd_metadata = {
            "version": payload["client"]["desired_pathd_version"],
            "sha256": payload["client"]["desired_pathd_sha256"],
        }
        line = payload["linux_path_leases"]["lines"][0]
        agent.pathd_status_path().parent.mkdir(parents=True, exist_ok=True)
        agent.pathd_status_path().write_text(
            json.dumps(
                {
                    "generation": 12,
                    "status": "session-41",
                    "forwards": {
                        "41": {"listener": "root-only", "sessions": 2},
                        "session-41": {"listener": "ready", "sessions": 1},
                    },
                    "local_lines": [
                        {
                            "line_id": 451,
                            "lease_id": line["lease_id"],
                            "lease_generation": 7,
                            "config_sha256": line["config_sha256"],
                            "service_state": "line = 451",
                            "tcp_status": "session-41",
                            "tcp_connect_ms": "root-only",
                            "last_error_code": "session-41",
                        }
                    ],
                }
            ),
            encoding="utf-8",
        )

        report = agent.pathd_heartbeat(payload["client"])

        self.assertEqual(11, report["local_lines"][0]["catalog_id"])
        encoded = json.dumps(report, sort_keys=True)
        self.assertNotIn("root-only", encoded)
        self.assertNotIn("line = 451", encoded)
        self.assertNotIn("session-41", encoded)


if __name__ == "__main__":
    unittest.main()
