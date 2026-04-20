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
                        }
                    ],
                }
            }
            agent.apply_management(management)
            routes_path = agent.state_dir / "cf-routes-443.json"
            payload = json.loads(routes_path.read_text(encoding="utf-8"))
            self.assertEqual(payload["routes"][0]["path_prefix"], "a43547b6350f72cd")
            unit_text = (agent.state_dir / "cf-forward-26.service.preview").read_text(encoding="utf-8")
            self.assertIn("wstunnel server ws://127.0.0.1:", unit_text)
            self.assertIn("--restrict-http-upgrade-path-prefix a43547b6350f72cd", unit_text)


if __name__ == "__main__":
    unittest.main()
