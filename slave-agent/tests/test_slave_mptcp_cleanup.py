import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


slave_agent_module = load_module(
    "slave_agent_under_test",
    "slave-agent/slave_agent.py",
)


class SlaveMptcpCleanupTests(unittest.TestCase):
    def make_agent(self) -> slave_agent_module.SlaveAgent:
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        config_path = pathlib.Path(tmp_dir.name) / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(pathlib.Path(tmp_dir.name) / "state"),
                    "systemd_dir": "/etc/systemd/system",
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_remove_mptcp_underlay_uses_metadata_interface_name(self) -> None:
        agent = self.make_agent()
        commands = []

        def fake_run_command(command: str, check: bool = True):
            commands.append(command)
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", return_value="/usr/bin/systemctl"):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run_command):
                with mock.patch.object(agent, "remove_uot_server_service") as remove_uot_mock:
                    with mock.patch.object(slave_agent_module.Path, "exists", return_value=False):
                        agent.remove_mptcp_underlay(
                            "wstunnel-svc-1-169",
                            {"interface_name": "lwf24n5"},
                        )

        self.assertTrue(any("wg-quick@lwf24n5.service" in command for command in commands))
        self.assertTrue(any(command == "wg-quick down lwf24n5" for command in commands))
        self.assertFalse(any("wg-quick@lwm" in command for command in commands))
        remove_uot_mock.assert_called_once_with("lwf24n5")

    def test_cleanup_orphan_mptcp_underlays_removes_untracked_interfaces(self) -> None:
        agent = self.make_agent()
        commands = []
        deleted = []

        def fake_run_command(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lwf19n5 lwf24n5 lwm38", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        def fake_exists(path_self):
            return str(path_self) in {
                "/etc/wireguard/lwf19n5.conf",
                "/etc/wireguard/lwm38.conf",
            }

        def fake_unlink(path_self):
            deleted.append(str(path_self).replace("\\", "/"))

        with mock.patch.object(agent, "load_all_service_metadata", return_value=[
            {
                "status": "active",
                "mptcp_underlay": {"enabled": True, "interface_name": "lwf24n5"},
            }
        ]):
            with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(agent, "remove_uot_server_service") as remove_uot_mock:
                        with mock.patch.object(slave_agent_module.Path, "exists", fake_exists):
                            with mock.patch.object(slave_agent_module.Path, "unlink", fake_unlink):
                                agent.cleanup_orphan_mptcp_underlays()

        self.assertTrue(any("wg-quick@lwf19n5.service" in command for command in commands))
        self.assertTrue(any("wg-quick@lwm38.service" in command for command in commands))
        self.assertTrue(any(command == "wg-quick down lwf19n5" for command in commands))
        self.assertTrue(any(command == "wg-quick down lwm38" for command in commands))
        self.assertFalse(any("wg-quick@lwf24n5.service" in command for command in commands))
        remove_uot_mock.assert_any_call("lwf19n5")
        remove_uot_mock.assert_any_call("lwm38")

    def test_install_slave_script_supports_update_mode(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn("--update", source)
        self.assertIn("--check-update", source)
        self.assertIn("--config", source)
        self.assertIn("installer_version", source)

    def test_apply_management_state_ignores_stale_explicit_update_when_versions_match(self) -> None:
        agent = self.make_agent()
        agent.config["installer_version"] = "2026.04.17.1"
        desired_sha256 = slave_agent_module.file_sha256(REPO_ROOT / "slave-agent" / "slave_agent.py")

        with mock.patch.object(agent, "request_self_update") as update_mock:
            agent.apply_management_state(
                {
                    "agent_config_json": {},
                    "agent_update_request_token": "stale-explicit-token",
                    "desired_agent_version": slave_agent_module.AGENT_VERSION,
                    "desired_agent_sha256": desired_sha256,
                    "desired_installer_version": "2026.04.17.1",
                }
            )

        update_mock.assert_not_called()
        update_state = agent.load_update_state()
        self.assertEqual(update_state.get("last_completed_request_token"), "stale-explicit-token")
        self.assertEqual(update_state.get("status"), "success")

    def test_process_tasks_defers_update_request_until_after_task_results(self) -> None:
        agent = self.make_agent()
        agent.config["installer_version"] = "2026.04.16.1"
        desired_sha256 = slave_agent_module.file_sha256(REPO_ROOT / "slave-agent" / "slave_agent.py")
        events: list[str] = []

        def fake_http_json(method: str, url: str, token: str | None = None, payload: dict | None = None):
            if method == "GET" and "/api/node/tasks/pull" in url:
                return {
                    "management": {
                        "agent_config_json": {},
                        "desired_agent_version": slave_agent_module.AGENT_VERSION,
                        "desired_agent_sha256": desired_sha256,
                        "desired_installer_version": "2026.04.17.1",
                    },
                    "tasks": [
                        {
                            "id": 77,
                            "service_id": 1,
                            "forward_id": 26,
                            "endpoint_id": 186,
                            "payload": {},
                        }
                    ],
                }
            if method == "POST" and url.endswith("/ack"):
                events.append("ack")
                return {"ok": True}
            if method == "POST" and url.endswith("/result"):
                events.append("result")
                return {"ok": True}
            raise AssertionError(f"unexpected http_json call: {method} {url}")

        def fake_handle_task(task: dict) -> dict:
            events.append("handle")
            return {"ok": True}

        def fake_request_self_update(request_token: str) -> None:
            events.append("update")

        with mock.patch.object(slave_agent_module, "http_json", side_effect=fake_http_json):
            with mock.patch.object(agent, "handle_task", side_effect=fake_handle_task):
                with mock.patch.object(agent, "request_self_update", side_effect=fake_request_self_update):
                    with mock.patch.object(agent, "cleanup_orphan_mptcp_underlays"):
                        agent.process_tasks()

        self.assertEqual(events, ["ack", "handle", "result", "update"])

    def test_apply_management_state_does_not_schedule_duplicate_update_while_running(self) -> None:
        agent = self.make_agent()
        now = 1778067400.0
        agent.save_update_state(
            {
                "request_token": "auto-release:agent=2026.04.27.1",
                "status": "running",
                "last_attempt_at": now,
                "last_started_at": now,
            }
        )

        management = {
            "agent_config_json": {},
            "agent_update_request_token": "auto-slave-agent:version=2026.04.27.1",
            "desired_agent_version": "2026.04.27.1",
            "desired_agent_sha256": "b86a5ee57afbee50a6e5a24fca383184c4c2bf02acd0feb28c5a1c0a1379f995",
            "desired_installer_version": "2026.04.24.1",
        }

        with mock.patch.object(slave_agent_module.time, "time", return_value=now + 5):
            with mock.patch.object(agent, "request_self_update") as update_mock:
                agent.apply_management_state(management)

        update_mock.assert_not_called()
        update_state = agent.load_update_state()
        self.assertEqual(update_state.get("request_token"), "auto-release:agent=2026.04.27.1")
        self.assertEqual(update_state.get("status"), "running")


if __name__ == "__main__":
    unittest.main()
