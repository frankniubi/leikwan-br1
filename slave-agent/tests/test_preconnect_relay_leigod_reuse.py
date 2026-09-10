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
    assert spec is not None and spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


slave_agent_module = load_module(
    "slave_agent_preconnect_reuse_under_test",
    "slave-agent/slave_agent.py",
)


class SlavePreconnectRelayLeigodReuseTests(unittest.TestCase):
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
                    "state_dir": str(root / "state"),
                    "systemd_dir": str(root / "systemd"),
                    "log_level": "INFO",
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_preconnect_relay_honors_public_bind_health_and_advertise_fields(self) -> None:
        agent = self.make_agent()
        service_name = "wstunnel-svc-1-190"
        listen_host = "0.0.0.0"
        advertise_host = "198.51.100.24"
        sidecar_host = "10.203.24.215"
        sidecar_port = 14969
        underlay = {
            "slave_preconnect": True,
            "slave_address": "10.203.24.214",
            "preconnect_relay_port": 7324,
            "preconnect_listen_host": listen_host,
            "preconnect_advertise_host": advertise_host,
            "preconnect_health_port": 7325,
            "sidecar_address": sidecar_host,
            "sidecar_mptcp_port": sidecar_port,
            "tcp_session_token": "session-token",
            "forward_id": 47,
        }

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            result = agent.ensure_preconnect_relay(service_name, underlay)

        relay_service = agent.preconnect_relay_service_name(service_name)
        config_path = agent.preconnect_relay_config_path(service_name)
        unit_path = pathlib.Path(str(agent.config.get("systemd_dir"))) / f"{relay_service}.service"
        config = json.loads(config_path.read_text(encoding="utf-8"))
        unit_body = unit_path.read_text(encoding="utf-8")

        self.assertEqual(listen_host, config["listen_host"])
        self.assertEqual(7324, config["listen_port"])
        self.assertEqual(7325, config["health_port"])
        self.assertEqual(advertise_host, config["advertise_host"])
        self.assertEqual(sidecar_host, config["sidecar_host"])
        self.assertEqual(sidecar_port, config["sidecar_port"])
        self.assertIn(f"--config {config_path}", unit_body)
        self.assertEqual(listen_host, result["relay_host"])
        self.assertEqual(advertise_host, result["relay_advertise_host"])
        self.assertEqual(7324, result["relay_port"])
        self.assertEqual(7325, result["health_port"])


if __name__ == "__main__":
    unittest.main()
