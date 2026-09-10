import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
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
    "slave_agent_clock_under_test",
    "slave-agent/slave_agent.py",
)


class SlaveHeartbeatClockStateTests(unittest.TestCase):
    def make_agent(self) -> slave_agent_module.SlaveAgent:
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        root = pathlib.Path(tmp_dir.name)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "node_id": 1,
                    "machine_id": "machine-1",
                    "state_dir": str(root / "state"),
                    "systemd_dir": str(root / "systemd"),
                    "backend_port_range_start": 20000,
                    "backend_port_range_end": 20010,
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_send_heartbeat_persists_clock_state(self) -> None:
        agent = self.make_agent()
        captured_payload: dict = {}

        with mock.patch.object(agent, "ensure_machine_id"):
            with mock.patch.object(agent, "refresh_network_identity"):
                with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                    with mock.patch.object(agent, "active_service_count", return_value=0):
                        with mock.patch.object(agent, "cpu_usage_hint", return_value=0.0):
                            with mock.patch.object(agent, "memory_usage_percent", return_value=0.0):
                                with mock.patch.object(agent, "disk_usage_percent", return_value=0.0):
                                    with mock.patch.object(
                                        agent,
                                        "time_sync_state",
                                        return_value={"synchronized": True, "service": "ntp", "status": "ok"},
                                    ):
                                        def fake_http(method, url, token, payload=None):
                                            captured_payload["payload"] = payload or {}
                                            return {}, {"date": "Sat, 18 Apr 2026 14:00:00 GMT"}

                                        with mock.patch.object(slave_agent_module, "http_json_with_headers", side_effect=fake_http):
                                            with mock.patch.object(slave_agent_module.time, "time", return_value=1776520800.0):
                                                agent.send_heartbeat()

        self.assertIn("clock_sync", captured_payload["payload"])
        self.assertTrue(captured_payload["payload"]["clock_sync"]["synchronized"])
        state = agent.load_pull_state()
        self.assertIn("clock", state)
        self.assertEqual(state["clock"]["status"], "ok")


if __name__ == "__main__":
    unittest.main()
