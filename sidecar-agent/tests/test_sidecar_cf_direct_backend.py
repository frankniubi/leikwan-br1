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


sidecar_agent = load_module("sidecar_agent_cf_direct_backend_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarCfDirectBackendRegressionTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "agent_token": "token-123",
            "state_dir": str(pathlib.Path(tmp_dir) / "state"),
            "runtime_config_path": str(pathlib.Path(tmp_dir) / "bond-runtime.json"),
            "service_name": "leikwan-bond-sidecar-5",
        }), encoding="utf-8")
        return sidecar_agent.SidecarAgent(config_path)

    def test_cf_runtime_can_publish_direct_backend_without_local_wstunnel_backend(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir, mock.patch.object(sidecar_agent.subprocess, "run"):
            agent = self.make_agent(tmp_dir)
            management = {
                "cf_runtime": {
                    "listen_host": "0.0.0.0",
                    "listen_port": 2053,
                    "tls_cert_path": "/etc/ssl/cf/cert.crt",
                    "tls_key_path": "/etc/ssl/cf/private.key",
                    "routes": [
                        {
                            "forward_id": 47,
                            "service_name": "cf-forward-47",
                            "path_prefix": "07289d1f5ed25399",
                            "target": "127.0.0.1:8899",
                            "transport_mode": "tcp",
                            "backend_mode": "direct",
                        }
                    ],
                }
            }

            agent.apply_management(management)

            routes_path = agent.state_dir / "cf-routes-2053.json"
            routes = json.loads(routes_path.read_text(encoding="utf-8"))["routes"]
            self.assertEqual(1, len(routes))
            self.assertEqual("127.0.0.1", routes[0]["backend_host"])
            self.assertEqual(8899, routes[0]["backend_port"])
            self.assertEqual("direct", routes[0]["backend_mode"])
            self.assertFalse((agent.state_dir / "cf-forward-47.service.preview").exists())


if __name__ == "__main__":
    unittest.main()
