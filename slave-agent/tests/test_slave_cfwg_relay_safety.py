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
    "slave_agent_cfwg_relay_safety_under_test",
    "slave-agent/slave_agent.py",
)


class SlaveCfwgRelaySafetyTests(unittest.TestCase):
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
        agent = slave_agent_module.SlaveAgent(config_path)
        pathlib.Path(agent.config["systemd_dir"]).mkdir(parents=True, exist_ok=True)
        return agent

    def base_metadata(self, agent, service_name="wstunnel-svc-2-316"):
        return {
            "service_name": service_name,
            "service_id": 2,
            "forward_id": 47,
            "endpoint_id": 316,
            "listen_port": 7443,
            "backend_port": 20000,
            "target": "127.0.0.1:8899",
            "path_prefix": "07289d1f5ed25399",
            "route_key": "07289d1f5ed25399",
            "backend_mode": "cf_mode",
            "cf_mode": {
                "dial_host": "185.220.238.143",
                "dial_port": 2053,
                "sni_host": "nrt1.nbwly.com",
                "path_prefix": "07289d1f5ed25399",
            },
            "selected_cf_ip": "162.159.39.5",
            "ingress_protocol": "wss_path_mux",
            "backend_protocol": "wss_backend",
            "transport_mode": "tcp",
            "relay_protocols": [],
            "relay_target": "",
            "relay_service_name": "",
            "relay_client_service_name": "",
            "relay_client_port": 0,
            "mptcp_underlay": {},
            "sidecar_uplink": {},
            "filter_profile": "off",
            "status": "active",
        }

    def relay_payload(self, path="static/js/deadbeef.chunk.js"):
        return {
            "enabled": True,
            "path_algorithm": "cfwg-relay-v1",
            "slave_path_prefix": path,
            "cf_target_host": "127.0.0.1",
            "cf_target_port": 14969,
            "cf_dial_host": "185.220.238.143",
            "cf_dial_port": 2053,
            "cf_sni_host": "nrt1.nbwly.com",
            "cf_path_prefix": "07289d1f5ed25399.cfwg",
        }


    def test_register_and_check_report_cfwg_relay_capability(self) -> None:
        agent = self.make_agent()
        with mock.patch.object(agent, "ensure_machine_id"):
            with mock.patch.object(agent, "refresh_network_identity"):
                with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                    with mock.patch.object(slave_agent_module, "http_json", return_value={"node_id": 12, "api_token": "new-token"}) as http_json:
                        agent.register()

        payload = http_json.call_args.args[3]
        self.assertTrue(payload["supports_cfwg_relay"])
        self.assertTrue(agent.check()["capabilities"]["supports_cfwg_relay"])

    def test_cfwg_relay_publish_preflights_path_conflicts(self) -> None:
        agent = self.make_agent()
        parent_service = "wstunnel-svc-2-316"
        agent.write_json(agent.metadata_path(parent_service), self.base_metadata(agent, parent_service))
        conflict_service = "wstunnel-svc-9-999"
        conflict = self.base_metadata(agent, conflict_service)
        conflict.update(
            {
                "service_name": conflict_service,
                "service_id": 9,
                "forward_id": 99,
                "endpoint_id": 999,
                "path_prefix": "static/js/deadbeef.chunk.js",
                "route_key": "static/js/deadbeef.chunk.js",
                "backend_port": 20005,
            }
        )
        agent.write_json(agent.metadata_path(conflict_service), conflict)

        with self.assertRaises(RuntimeError):
            agent.ensure_cfwg_relay_service(
                {"service_id": 2, "forward_id": 47, "endpoint_id": 316},
                {"cf_mode": self.base_metadata(agent)["cf_mode"]},
                self.base_metadata(agent, parent_service),
                self.relay_payload(),
                parent_service,
                7443,
            )

    def test_lifecycle_names_include_existing_cfwg_relay_service(self) -> None:
        agent = self.make_agent()
        base_service = "wstunnel-svc-2-316"
        relay_service = base_service + "-cfwg-relay"
        agent.write_json(agent.metadata_path(relay_service), {"service_name": relay_service, "status": "active"})

        names = agent.lifecycle_service_names(
            {"service_id": 2, "forward_id": 47, "endpoint_id": 316},
            {},
        )

        self.assertIn(base_service, names)
        self.assertIn(relay_service, names)

    def test_publish_without_cfwg_relay_removes_stale_relay_route(self) -> None:
        agent = self.make_agent()
        base_service = "wstunnel-svc-2-316"
        relay_service = base_service + "-cfwg-relay"
        agent.write_json(agent.metadata_path(relay_service), self.base_metadata(agent, relay_service) | {
            "service_name": relay_service,
            "path_prefix": "static/js/deadbeef.chunk.js",
            "route_key": "static/js/deadbeef.chunk.js",
            "cfwg_relay_parent": base_service,
        })
        agent.service_unit_path(relay_service).write_text("stale", encoding="utf-8")

        task = {"service_id": 2, "forward_id": 47, "endpoint_id": 316, "payload": {}}
        payload = {
            "target": "127.0.0.1:8899",
            "listen_port": 7443,
            "path_prefix": "07289d1f5ed25399",
            "route_key": "07289d1f5ed25399",
            "transport_mode": "tcp",
            "filter_profile": "off",
            "backend_mode": "cf_mode",
            "cf_mode": self.base_metadata(agent)["cf_mode"],
        }

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "select_cf_location_ip", return_value={"selected_ip": "162.159.39.5"}):
                with mock.patch.object(agent, "ensure_nft_table"):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(agent, "ensure_backend_service"):
                                with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                                    agent.publish_task(task, payload)

        self.assertFalse(agent.metadata_path(relay_service).exists())
        self.assertFalse(agent.service_unit_path(relay_service).exists())


if __name__ == "__main__":
    unittest.main()
