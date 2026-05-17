import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest

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
                    ],
                },
            }

            agent.apply_management(management)

            cf_routes = json.loads((agent.state_dir / "cf-routes-443.json").read_text(encoding="utf-8"))["routes"]
            mptcp_routes = json.loads((agent.state_dir / "mptcp-forward-routes.json").read_text(encoding="utf-8"))["routes"]
            base_backend = next(route["backend_port"] for route in cf_routes if route["service_name"] == "cf-forward-49")
            uot_backend = next(route["backend_port"] for route in cf_routes if route["service_name"] == "cf-forward-49-uot")
            self.assertNotEqual(base_backend, uot_backend)
            self.assertEqual(mptcp_routes[0]["target_port"], base_backend)


if __name__ == "__main__":
    unittest.main()
