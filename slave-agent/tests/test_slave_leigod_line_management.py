import hashlib
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
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
    "slave_agent_leigod_line_management_under_test",
    "slave-agent/slave_agent.py",
)


class FakeControlSocket:
    def __init__(self, replies: bytes):
        self.replies = bytearray(replies)
        self.sent: list[bytes] = []

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def settimeout(self, _timeout):
        return None

    def sendall(self, payload: bytes):
        self.sent.append(payload)

    def recv(self, size: int) -> bytes:
        payload = bytes(self.replies[:size])
        del self.replies[:size]
        return payload


class FakeUdpSocket:
    def __init__(self):
        self.sent: tuple[bytes, tuple[str, int]] | None = None

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def bind(self, _address):
        return None

    def getsockname(self):
        return ("127.0.0.1", 39001)

    def settimeout(self, _timeout):
        return None

    def sendto(self, payload: bytes, address: tuple[str, int]):
        self.sent = (payload, address)

    def recvfrom(self, _size: int):
        assert self.sent is not None
        request, _relay = self.sent
        header_size = 10  # RSV + FRAG + ATYP + IPv4 + port
        dns_query = request[header_size:]
        dns_response = dns_query[:2] + b"\x81\x80" + dns_query[4:]
        return request[:header_size] + dns_response, ("127.0.0.1", 41000)


class SlaveLeigodLineManagementTests(unittest.TestCase):
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
                    "leigod_bin": str(root / "bin" / "leigod"),
                }
            ),
            encoding="utf-8",
        )
        agent = slave_agent_module.SlaveAgent(config_path)
        pathlib.Path(agent.config["leigod_bin"]).parent.mkdir(parents=True, exist_ok=True)
        pathlib.Path(agent.config["leigod_bin"]).write_text("binary", encoding="utf-8")
        return agent, root

    @staticmethod
    def line(line_id: int = 451, *, udp: bool = True) -> dict:
        toml = f"line = {line_id}\n"
        return {
            "line_id": line_id,
            "label": f"line-{line_id}",
            "config_sha256": hashlib.sha256(toml.encode()).hexdigest(),
            "toml": toml,
            "socks_host": "127.0.0.1",
            "socks_port": 20000 + line_id,
            "tcp_probe_host": "198.51.100.8",
            "tcp_probe_port": 80,
            "udp_probe_host": "203.0.113.53" if udp else None,
            "udp_probe_port": 53 if udp else None,
            "udp_probe_method": "dns" if udp else None,
        }

    @classmethod
    def leased_line(cls, line_id: int = 451, *, state: str = "pending") -> dict:
        line = cls.line(line_id)
        line.update(
            {
                "lease_id": "00000000-0000-4000-8000-000000000012",
                "lease_generation": 3,
                "lease_state": state,
                "max_parallel_lanes": 2,
            }
        )
        return line

    def test_management_writes_verified_toml_0600_after_session(self):
        agent, _root = self.make_agent()
        observed: list[tuple[int, bool, str]] = []

        def record_start(line_id: int, _toml: str = "", expected_sha256: str = ""):
            root = pathlib.Path(agent.config["leigod_root"])
            observed.append(
                (
                    line_id,
                    (root / "session.json").exists(),
                    hashlib.sha256((root / "lines" / f"{line_id}.toml").read_bytes()).hexdigest(),
                )
            )

        chmod_calls: list[tuple[str, int]] = []
        real_chmod = os.chmod
        with mock.patch.object(
            slave_agent_module.os,
            "chmod",
            side_effect=lambda path, mode: chmod_calls.append((str(path), mode)) or real_chmod(path, mode),
        ):
            with mock.patch.object(agent, "ensure_leigod_line_service", side_effect=record_start):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    agent.apply_management_state(
                        {"leigod_session_json": '{"token":"secret"}', "leigod_lines": [self.line()]},
                        schedule_updates=False,
                    )

        line_path = pathlib.Path(agent.config["leigod_root"]) / "lines" / "451.toml"
        self.assertEqual("line = 451\n", line_path.read_text(encoding="utf-8"))
        self.assertTrue(any(path.endswith("451.toml") and mode == 0o600 for path, mode in chmod_calls))
        self.assertEqual([(451, True, self.line()["config_sha256"])], observed)

    def test_absent_key_is_noop_and_empty_list_respects_active_relay_reference(self):
        agent, _root = self.make_agent()
        line = self.line()
        root = pathlib.Path(agent.config["leigod_root"])
        with mock.patch.object(agent, "ensure_leigod_line_service"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                agent.apply_management_state(
                    {"leigod_session_json": "{}", "leigod_lines": [line]},
                    schedule_updates=False,
                )
        line_path = root / "lines" / "451.toml"
        before = agent.leigod_desired_state_path().read_bytes()
        agent.apply_management_state({}, schedule_updates=False)
        self.assertEqual(before, agent.leigod_desired_state_path().read_bytes())

        config_path = agent.leigod_forward_config_path("wstunnel-svc-2-316")
        agent.write_json(config_path, {"candidates": [{"line_id": 451}]})
        agent.apply_management_state({"leigod_lines": []}, schedule_updates=False)
        self.assertTrue(line_path.exists(), "referenced line must survive authoritative empty management")

        config_path.unlink()
        commands: list[str] = []
        with mock.patch.object(slave_agent_module.shutil, "which", return_value="/usr/bin/systemctl"):
            with mock.patch.object(
                slave_agent_module,
                "run_command",
                side_effect=lambda command, check=False: commands.append(command)
                or SimpleNamespace(returncode=0, stdout="", stderr=""),
            ):
                agent.apply_management_state({"leigod_lines": []}, schedule_updates=False)
        self.assertFalse(line_path.exists())
        self.assertIn("systemctl stop leigod@451.service", commands)

    def test_hash_mismatch_never_overwrites_or_starts_line(self):
        agent, _root = self.make_agent()
        bad = self.line()
        bad["config_sha256"] = "0" * 64
        with mock.patch.object(agent, "ensure_leigod_line_service") as ensure:
            agent.apply_management_state({"leigod_session_json": "{}", "leigod_lines": [bad]}, schedule_updates=False)
        ensure.assert_not_called()
        path = pathlib.Path(agent.config["leigod_root"]) / "lines" / "451.toml"
        self.assertFalse(path.exists())
        health = agent.load_leigod_line_health()[451]
        self.assertEqual("failed", health["service_state"])
        self.assertEqual("config_hash_mismatch", health["last_error"])

    def test_lease_managed_rows_require_and_persist_complete_fence(self):
        agent, _root = self.make_agent()
        incomplete = self.line()
        incomplete["lease_id"] = "00000000-0000-4000-8000-000000000012"
        with self.assertRaisesRegex(RuntimeError, "incomplete leigod line lease fence"):
            agent.apply_management_state(
                {"leigod_session_json": "{}", "leigod_lines": [incomplete]},
                schedule_updates=False,
            )

        leased = self.leased_line()
        with mock.patch.object(agent, "ensure_leigod_line_service"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                agent.apply_management_state(
                    {"leigod_session_json": "{}", "leigod_lines": [leased]},
                    schedule_updates=False,
                )

        desired = agent.load_leigod_desired_lines()[0]
        self.assertEqual(leased["lease_id"], desired["lease_id"])
        self.assertEqual(3, desired["lease_generation"])
        self.assertEqual("pending", desired["lease_state"])
        health = agent.load_leigod_line_health()[451]
        self.assertEqual(leased["lease_id"], health["lease_id"])
        self.assertEqual(3, health["lease_generation"])

        with mock.patch.object(agent, "ensure_leigod_line_service"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                agent.apply_management_state(
                    {"leigod_session_json": "{}", "leigod_lines": [self.line()]},
                    schedule_updates=False,
                )
        rolled_back = agent.load_leigod_line_health()[451]
        self.assertNotIn("lease_id", rolled_back)
        self.assertNotIn("lease_generation", rolled_back)

    def test_revocation_holds_referenced_line_and_reports_revoking_until_cleanup(self):
        agent, root = self.make_agent()
        leased = self.leased_line(state="active")
        with mock.patch.object(agent, "ensure_leigod_line_service"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                agent.apply_management_state(
                    {"leigod_session_json": "{}", "leigod_lines": [leased]},
                    schedule_updates=False,
                )

        config_path = agent.leigod_forward_config_path("wstunnel-svc-2-316")
        agent.write_json(config_path, {"candidates": [{"line_id": 451}]})
        agent.apply_management_state({"leigod_lines": []}, schedule_updates=False)
        desired = agent.load_leigod_desired_lines()[0]
        health = agent.load_leigod_line_health()[451]
        self.assertEqual("revoking", desired["lease_state"])
        self.assertEqual("revoking", health["service_state"])
        self.assertEqual(leased["lease_id"], health["lease_id"])
        self.assertTrue((root / "leigod" / "lines" / "451.toml").exists())

        config_path.unlink()
        with mock.patch.object(slave_agent_module.shutil, "which", return_value="/usr/bin/systemctl"):
            with mock.patch.object(slave_agent_module, "run_command", return_value=SimpleNamespace(returncode=0, stdout="", stderr="")):
                agent.apply_management_state({"leigod_lines": []}, schedule_updates=False)
        self.assertEqual([], agent.load_leigod_desired_lines())
        self.assertNotIn(451, agent.load_leigod_line_health())

    def test_line_template_uses_configured_systemd_directory(self):
        agent, root = self.make_agent()
        commands: list[str] = []
        with mock.patch.object(slave_agent_module.shutil, "which", return_value="/usr/bin/systemctl"):
            with mock.patch.object(agent, "systemd_is_active", return_value=False):
                with mock.patch.object(
                    slave_agent_module,
                    "run_command",
                    side_effect=lambda command, check=False: commands.append(command)
                    or SimpleNamespace(returncode=0, stdout="", stderr=""),
                ):
                    agent.apply_management_state(
                        {"leigod_session_json": "{}", "leigod_lines": [self.line()]},
                        schedule_updates=False,
                    )

        unit_path = root / "systemd" / "leigod@.service"
        self.assertTrue(unit_path.exists())
        self.assertIn(str(pathlib.Path(agent.config["leigod_bin"])), unit_path.read_text(encoding="utf-8"))
        self.assertIn("systemctl daemon-reload", commands)

    def test_complete_tcp_and_udp_probes_produce_truthful_health(self):
        agent, _root = self.make_agent()
        line = self.line()
        agent.save_leigod_desired_lines([agent.desired_leigod_line_without_toml(line)])
        root = pathlib.Path(agent.config["leigod_root"])
        (root / "lines").mkdir(parents=True, exist_ok=True)
        (root / "session.json").write_text("{}", encoding="utf-8")
        (root / "lines" / "451.toml").write_bytes(line["toml"].encode("utf-8"))
        tcp_control = FakeControlSocket(
            b"\x05\x00" + b"\x05\x00\x00\x01\x7f\x00\x00\x01\x9c\x40"
        )
        udp_control = FakeControlSocket(
            b"\x05\x00" + b"\x05\x00\x00\x01\x7f\x00\x00\x01\xa0\x28"
        )
        udp_socket = FakeUdpSocket()
        with mock.patch.object(slave_agent_module.shutil, "which", return_value="/usr/bin/systemctl"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(
                    slave_agent_module.socket,
                    "create_connection",
                    side_effect=[tcp_control, udp_control],
                ):
                    with mock.patch.object(slave_agent_module.socket, "socket", return_value=udp_socket):
                        health = agent.probe_leigod_line(agent.load_leigod_desired_lines()[0])

        self.assertEqual("active", health["service_state"], health)
        self.assertEqual("healthy", health["tcp_status"])
        self.assertEqual("healthy", health["udp_status"])
        self.assertTrue(any(payload.startswith(b"\x05\x01\x00") for payload in tcp_control.sent))
        self.assertTrue(any(payload.startswith(b"\x05\x03\x00") for payload in udp_control.sent))
        self.assertIsNotNone(udp_socket.sent)

    def test_heartbeat_contains_bounded_health_without_toml(self):
        agent, _root = self.make_agent()
        line = self.leased_line(state="active")
        agent.save_leigod_desired_lines([agent.desired_leigod_line_without_toml(line)])
        agent.save_leigod_line_health(
            {
                451: {
                    "line_id": 451,
                    "config_sha256": line["config_sha256"],
                    "service_state": "active",
                    "tcp_status": "healthy",
                    "tcp_connect_ms": 12,
                    "udp_status": "unknown",
                    "consecutive_successes": 1,
                    "consecutive_failures": 0,
                    "last_error": "",
                    "checked_at": "2026-07-27T00:00:00Z",
                    "lease_id": line["lease_id"],
                    "lease_generation": line["lease_generation"],
                    "lease_state": line["lease_state"],
                }
            }
        )
        with mock.patch.object(agent, "ensure_machine_id"):
            with mock.patch.object(agent, "refresh_network_identity"):
                with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                    with mock.patch.object(agent, "time_sync_state", return_value={}):
                        with mock.patch.object(agent, "running_kernel_supports_mptcp", return_value=False):
                            with mock.patch.object(agent, "cpu_usage_hint", return_value=0):
                                with mock.patch.object(agent, "memory_usage_percent", return_value=0):
                                    with mock.patch.object(agent, "disk_usage_percent", return_value=0):
                                        payload = agent.build_heartbeat_payload()
        self.assertEqual(451, payload["leigod_line_health"][0]["line_id"])
        self.assertEqual(line["lease_id"], payload["leigod_line_health"][0]["lease_id"])
        self.assertEqual(3, payload["leigod_line_health"][0]["lease_generation"])
        self.assertNotIn("toml", json.dumps(payload).lower())

    def test_task_cycle_probes_managed_lines_once_after_double_management_apply(self):
        agent, _root = self.make_agent()
        management = {"leigod_lines": [self.line()]}
        with mock.patch.object(slave_agent_module, "http_json", return_value={"management": management, "tasks": []}):
            with mock.patch.object(agent, "apply_management_state") as apply:
                with mock.patch.object(agent, "probe_managed_leigod_lines") as probe:
                    with mock.patch.object(agent, "cleanup_orphan_sidecar_uplinks"):
                        with mock.patch.object(agent, "cleanup_orphan_mptcp_underlays"):
                            agent.process_tasks()
        self.assertEqual(2, apply.call_count)
        probe.assert_called_once_with(force=False)


if __name__ == "__main__":
    unittest.main()
