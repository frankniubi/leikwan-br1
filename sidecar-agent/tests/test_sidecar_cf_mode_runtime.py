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

sidecar_agent = load_module("sidecar_agent_cf_mode_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarCfModeRuntimeTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "agent_token": "token-123",
            "state_dir": str(pathlib.Path(tmp_dir) / "state"),
            "runtime_config_path": str(pathlib.Path(tmp_dir) / "bond-runtime.json"),
            "service_name": "leikwan-bond-sidecar-12",
        }), encoding="utf-8")
        return sidecar_agent.SidecarAgent(config_path)

    def test_apply_management_writes_cf_runtime_route_file(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            management = {
                "cf_runtime": {
                    "listen_host": "0.0.0.0",
                    "listen_port": 443,
                    "tls_cert_path": "/etc/ssl/cf/fullchain.pem",
                    "tls_key_path": "/etc/ssl/cf/privkey.pem",
                    "routes": [
                        {
                            "forward_id": 26,
                            "service_name": "cf-forward-26",
                            "path_prefix": "a43547b6350f72cd",
                            "target": "127.0.0.1:51820",
                        },
                        {
                            "forward_id": 27,
                            "service_name": "cf-forward-27",
                            "path_prefix": "b53547b6350f72ce",
                            "target": "127.0.0.1:51821",
                        },
                    ],
                }
            }
            agent.apply_management(management)
            routes_path = agent.state_dir / "cf-routes-443.json"
            payload = json.loads(routes_path.read_text(encoding="utf-8"))
            self.assertEqual(payload["routes"][0]["path_prefix"], "a43547b6350f72cd")
            self.assertNotEqual(payload["routes"][0]["backend_port"], payload["routes"][1]["backend_port"])
            self.assertEqual(payload["routes"][0]["backend_host"], "127.0.0.1")
            unit_text = (agent.state_dir / "cf-forward-26.service.preview").read_text(encoding="utf-8")
            self.assertIn("wstunnel server ws://127.0.0.1:", unit_text)
            self.assertIn("--restrict-http-upgrade-path-prefix a43547b6350f72cd", unit_text)

    def test_mptcp_cf_route_targets_base_backend_not_uot_shadow(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            management = {
                "mptcp_runtime": {
                    "forward_routes": [
                        {
                            "forward_id": 49,
                            "target_mode": "cf_local_backend",
                            "target_host": "127.0.0.1",
                            "target_port": 8899,
                        }
                    ]
                },
                "cf_runtime": {
                    "listen_host": "0.0.0.0",
                    "listen_port": 443,
                    "tls_cert_path": "/etc/ssl/cf/fullchain.pem",
                    "tls_key_path": "/etc/ssl/cf/privkey.pem",
                    "routes": [
                        {
                            "forward_id": 49,
                            "service_name": "cf-forward-49",
                            "path_prefix": "base",
                            "target": "127.0.0.1:8899",
                            "transport_mode": "tcp+udp",
                        },
                        {
                            "forward_id": 49,
                            "service_name": "cf-forward-49-uot",
                            "path_prefix": "base.uot",
                            "target": "127.0.0.1:8999",
                            "transport_mode": "tcp",
                        },
                        {
                            "forward_id": 49,
                            "service_name": "cf-forward-49-cfwg",
                            "path_prefix": "base.cfwg",
                            "target": "127.0.0.1:14969",
                            "transport_mode": "udp",
                            "shadow_route_key": "base.cfwg",
                            "backend_mode": "wstunnel",
                        },
                    ],
                },
            }

            agent.apply_management(management)

            cf_routes = json.loads((agent.state_dir / "cf-routes-443.json").read_text(encoding="utf-8"))["routes"]
            mptcp_routes = json.loads((agent.state_dir / "mptcp-forward-routes.json").read_text(encoding="utf-8"))["routes"]
            base_backend = next(route["backend_port"] for route in cf_routes if route["service_name"] == "cf-forward-49")
            uot_backend = next(route["backend_port"] for route in cf_routes if route["service_name"] == "cf-forward-49-uot")
            cfwg_backend = next(route["backend_port"] for route in cf_routes if route["service_name"] == "cf-forward-49-cfwg")
            self.assertNotEqual(base_backend, uot_backend)
            self.assertNotEqual(base_backend, cfwg_backend)
            self.assertEqual(mptcp_routes[0]["target_port"], base_backend)
            cfwg_unit = (agent.state_dir / "cf-forward-49-cfwg.service.preview").read_text(encoding="utf-8")
            self.assertIn("wstunnel server ws://127.0.0.1:", cfwg_unit)
            self.assertIn("--restrict-to 127.0.0.1:14969", cfwg_unit)
            self.assertIn("--restrict-http-upgrade-path-prefix base.cfwg", cfwg_unit)

    def test_cf_runtime_preserves_slave_transit_metadata_across_heartbeats(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_managed_cf_metadata([
                {
                    "service_name": "leikwan-cf-slave-lws5c1n12p",
                    "backend_port": 26012,
                    "path_prefix": "lws-5-1-12-p",
                    "target": "127.0.0.1:14980",
                    "interface_name": "lws5c1n12p",
                    "backend_type": "slave_transit_cf_wg",
                }
            ])
            management = {
                "cf_runtime": {
                    "listen_host": "0.0.0.0",
                    "listen_port": 443,
                    "tls_cert_path": "/etc/ssl/cf/fullchain.pem",
                    "tls_key_path": "/etc/ssl/cf/privkey.pem",
                    "routes": [
                        {
                            "forward_id": 47,
                            "service_name": "cf-forward-47",
                            "path_prefix": "base",
                            "target": "127.0.0.1:8899",
                        }
                    ],
                }
            }

            disabled_services: list[str] = []
            with mock.patch.object(
                agent,
                "disable_runtime",
                side_effect=lambda service_name, *_args, **_kwargs: disabled_services.append(service_name),
            ):
                agent.apply_management(management)
                agent.apply_management(management)

            persisted = json.loads(agent.cf_managed_metadata_path().read_text(encoding="utf-8"))
            slave_entries = [
                item
                for item in persisted
                if item.get("service_name") == "leikwan-cf-slave-lws5c1n12p"
            ]
            self.assertEqual(len(slave_entries), 1)
            self.assertEqual(slave_entries[0]["backend_type"], "slave_transit_cf_wg")
            self.assertNotIn("leikwan-cf-slave-lws5c1n12p", disabled_services)

            routes = json.loads((agent.state_dir / "cf-routes-443.json").read_text(encoding="utf-8"))["routes"]
            self.assertTrue(any(route["service_name"] == "leikwan-cf-slave-lws5c1n12p" for route in routes))

    def test_slave_transit_backend_port_is_reallocated_when_occupied_by_wrong_service(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_managed_cf_metadata([
                {
                    "service_name": "leikwan-cf-slave-lws5c1n12p",
                    "backend_port": 26012,
                    "path_prefix": "lws-5-1-12-p",
                    "target": "127.0.0.1:14980",
                    "interface_name": "lws5c1n12p",
                    "backend_type": "slave_transit_cf_wg",
                },
                {
                    "service_name": "cf-forward-47",
                    "backend_port": 26012,
                    "path_prefix": "base",
                    "target": "127.0.0.1:8899",
                },
            ])

            disabled = []
            with mock.patch.object(agent, "systemd_is_active", return_value=False):
                with mock.patch.object(agent, "disable_runtime", side_effect=lambda service_name, *_args: disabled.append(service_name)):
                    with mock.patch.object(sidecar_agent.subprocess, "run") as run_mock:
                        run_mock.return_value.returncode = 0
                        run_mock.return_value.stdout = "LISTEN 0 1024 127.0.0.1:26012 users:((\"wstunnel\",pid=123,fd=9))"
                        port = agent.reconcile_cf_backend_port("leikwan-cf-slave-lws5c1n12p", 26012)

            self.assertEqual(26000, port)
            self.assertIn("cf-forward-47", disabled)
            metadata = json.loads(agent.cf_managed_metadata_path().read_text(encoding="utf-8"))
            self.assertFalse(any(item.get("service_name") == "leikwan-cf-slave-lws5c1n12p" for item in metadata))


if __name__ == "__main__":
    unittest.main()
