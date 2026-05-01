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
    "slave_agent_path_prefix_cleanup_under_test",
    "slave-agent/slave_agent.py",
)


class SlavePathPrefixCleanupTests(unittest.TestCase):
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
                    "backend_port_range_start": 20000,
                    "backend_port_range_end": 20010,
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_publish_task_replaces_previous_active_service_for_same_forward(self) -> None:
        agent = self.make_agent()
        old_service_name = slave_agent_module.safe_name(1, 184)
        old_metadata = {
            "service_name": old_service_name,
            "service_id": 1,
            "forward_id": 26,
            "endpoint_id": 184,
            "listen_port": 7000,
            "backend_port": 6002,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "relay_protocols": [],
            "relay_target": "",
            "relay_service_name": "",
            "relay_client_service_name": "",
            "relay_client_port": 0,
            "mptcp_underlay": {},
            "filter_profile": "off",
            "status": "active",
        }
        agent.write_json(agent.metadata_path(old_service_name), old_metadata)

        task = {
            "service_id": 1,
            "forward_id": 26,
            "endpoint_id": 190,
            "payload": {},
        }
        payload = {
            "target": "138.252.162.120:9000",
            "listen_port": 7000,
            "path_prefix": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "filter_profile": "off",
        }

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                                result = agent.publish_task(task, payload)

        new_service_name = slave_agent_module.safe_name(1, 190)
        self.assertEqual(result["service_name"], new_service_name)
        self.assertFalse(agent.metadata_path(old_service_name).exists())
        self.assertTrue(agent.metadata_path(new_service_name).exists())

    def test_cleanup_superseded_forward_services_keeps_latest_endpoint(self) -> None:
        agent = self.make_agent()
        old_service_name = slave_agent_module.safe_name(1, 24)
        new_service_name = slave_agent_module.safe_name(1, 174)

        for service_name, endpoint_id in [(old_service_name, 24), (new_service_name, 174)]:
            agent.write_json(
                agent.metadata_path(service_name),
                {
                    "service_name": service_name,
                    "service_id": 1,
                    "forward_id": 24,
                    "endpoint_id": endpoint_id,
                    "listen_port": 7000,
                    "backend_port": 6000 + endpoint_id,
                    "target": "138.252.163.34:14999",
                    "path_prefix": "0cd6d70c5c71c1d2",
                    "transport_mode": "tcp+udp",
                    "relay_protocols": [],
                    "relay_target": "",
                    "relay_service_name": "",
                    "relay_client_service_name": "",
                    "relay_client_port": 0,
                    "mptcp_underlay": {},
                    "filter_profile": "off",
                    "status": "active",
                },
            )

        with mock.patch.object(agent, "destroy_service") as destroy_mock:
            agent.cleanup_superseded_forward_services()

        destroy_mock.assert_called_once()
        destroyed_task = destroy_mock.call_args[0][0]
        self.assertEqual(destroyed_task["endpoint_id"], 24)


if __name__ == "__main__":
    unittest.main()
