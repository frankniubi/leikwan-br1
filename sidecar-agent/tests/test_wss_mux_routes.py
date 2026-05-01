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


wss_mux = load_module("wss_mux_under_test", "sidecar-agent/leikwan_wss_mux.py")


class WssMuxRouteTests(unittest.TestCase):
    def test_current_routes_prefers_backend_port_over_target_port(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            routes_path = pathlib.Path(tmp_dir) / "cf-routes-2053.json"
            routes_path.write_text(
                json.dumps(
                    {
                        "routes": [
                            {
                                "forward_id": 47,
                                "service_name": "cf-forward-47",
                                "path_prefix": "07289d1f5ed25399",
                                "target": "127.0.0.1:8899",
                                "backend_port": 26001,
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            mux = wss_mux.WssPathMux(
                public_port=2053,
                routes_path=routes_path,
                tls_cert_path="unused-cert",
                tls_key_path="unused-key",
            )

            routes = mux.current_routes()

            self.assertEqual(routes[0]["backend_host"], "127.0.0.1")
            self.assertEqual(routes[0]["backend_port"], 26001)

    def test_current_routes_falls_back_to_target_when_backend_port_missing(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            routes_path = pathlib.Path(tmp_dir) / "cf-routes-2053.json"
            routes_path.write_text(
                json.dumps(
                    {
                        "routes": [
                            {
                                "forward_id": 47,
                                "service_name": "cf-forward-47",
                                "path_prefix": "07289d1f5ed25399",
                                "target": "127.0.0.1:8899",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            mux = wss_mux.WssPathMux(
                public_port=2053,
                routes_path=routes_path,
                tls_cert_path="unused-cert",
                tls_key_path="unused-key",
            )

            routes = mux.current_routes()

            self.assertEqual(routes[0]["backend_host"], "127.0.0.1")
            self.assertEqual(routes[0]["backend_port"], 8899)


if __name__ == "__main__":
    unittest.main()
