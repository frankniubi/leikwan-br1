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


slave_agent_module = load_module("slave_agent_udp_uot_under_test", "slave-agent/slave_agent.py")


class SlaveUdpUotShadowRouteTests(unittest.TestCase):
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
                    "systemd_dir": str(pathlib.Path(tmp_dir.name) / "systemd"),
                    "ingress_bin": "/usr/local/bin/leikwan-ingress",
                    "udp_uot_sidepath": {
                        "enabled_forward_ids": [26],
                        "shadow_route_suffix": ".uot",
                        "sidecar_port": 8999,
                    },
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def publish_with_mocks(self, agent: slave_agent_module.SlaveAgent, task: dict, payload: dict):
        with mock.patch.object(agent, "ensure_nft_table"):
            with mock.patch.object(agent, "delete_nft_rules") as delete_nft_rules:
                with mock.patch.object(agent, "add_nft_rules"):
                    with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                        agent.publish_task(task, payload)
        return delete_nft_rules

    def test_publish_task_creates_shadow_uot_service_for_enabled_forward(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }

        self.publish_with_mocks(agent, task, payload)

        routes = agent.listener_routes(7001)
        route_keys = [item["route_key"] for item in routes]
        self.assertIn("a43547b6350f72cd", route_keys)
        self.assertIn("a43547b6350f72cd.uot", route_keys)
        uot_route = next(item for item in routes if item["route_key"] == "a43547b6350f72cd.uot")
        self.assertEqual(uot_route["backend_protocol"], "tcp_relay")
        self.assertEqual(uot_route["backend_port"] > 0, True)

    def test_shadow_uot_backend_targets_sidecar_8999(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }

        self.publish_with_mocks(agent, task, payload)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        metadata = agent.load_service_metadata(shadow_name)
        self.assertEqual(metadata["target"], "138.252.162.120:8999")
        self.assertEqual(metadata["route_key"], "a43547b6350f72cd.uot")
        self.assertEqual(metadata["ingress_auth_token"], "token-123")

    def test_republish_same_forward_keeps_single_shadow_and_no_extra_destroy(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        payload_republish = dict(payload)
        payload_republish["target"] = "138.252.162.120:9010"

        first_delete_call = self.publish_with_mocks(agent, task, payload)
        second_delete_call = self.publish_with_mocks(agent, task, payload_republish)

        self.assertEqual(first_delete_call.call_count, 1)
        self.assertEqual(second_delete_call.call_count, 1)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        base_name = "wstunnel-svc-1-184"
        self.assertEqual(agent.load_service_metadata(base_name)["target"], "138.252.162.120:9010")
        self.assertEqual(agent.load_service_metadata(shadow_name)["target"], "138.252.162.120:8999")

        shadow_metadata_files = list(agent.services_dir.glob("*-udp-uot.json"))
        self.assertEqual(len(shadow_metadata_files), 1)
        self.assertTrue(agent.service_unit_path(shadow_name).exists())

    def test_destroy_removes_shadow_metadata_and_route(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        self.publish_with_mocks(agent, task, payload)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        self.assertIsNotNone(agent.load_service_metadata(shadow_name))
        self.assertTrue(agent.service_unit_path(shadow_name).exists())

        with mock.patch.object(agent, "delete_nft_rules"):
            agent.destroy_service(task)

        routes = agent.listener_routes(7001)
        route_keys = [item["route_key"] for item in routes]
        self.assertNotIn("a43547b6350f72cd", route_keys)
        self.assertNotIn("a43547b6350f72cd.uot", route_keys)
        self.assertIsNone(agent.load_service_metadata(shadow_name))
        self.assertFalse(agent.service_unit_path(shadow_name).exists())
