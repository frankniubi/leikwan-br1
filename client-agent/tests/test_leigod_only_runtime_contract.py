import importlib.util
import pathlib
import sys
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


install_runtime = load_module("install_forward_runtime_leigod_only_contract_under_test", "client-agent/install_forward_runtime.py")


class LeiGodOnlyRuntimeContractTests(unittest.TestCase):
    def test_dedicated_leigod_ignores_legacy_mptcp_and_renders_single_direct_forward(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "162.245.221.78:80",
                "sni": "edge.example.com",
                "path_prefix": "dedicated-route",
                "transport": "tcp",
                "mode": "mptcp",
                "client_mode": "mptcp",
                "backend_mode": "leigod_mode",
                "active_family": "leigod_only",
                "target_contract": "dedicated_forward_node",
                "sidecar_required": False,
                "aggregation_enabled": False,
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "endpoints": [{"endpoint_id": 316, "host": "203.0.113.12", "port": 443}],
                "bond_sidecar_point_id": 7,
                "bond_sidecar_host": "stale-sidecar.invalid",
                "bond_sidecar_port": 9443,
                "mptcp_sidecar_port": 8898,
                "mptcp": {"tcp_session_token": "stale-token", "sidecar_host": "127.0.0.1", "sidecar_port": 8898},
                "bonding": {"session_token": "stale-token", "remote_target": "127.0.0.1:8899"},
            },
            {"mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py"},
        )

        self.assertIsNone(rendered["start_script_path"])
        self.assertIsNone(rendered["start_script"])
        self.assertNotIn("files", rendered)
        self.assertIn("ExecStart=/usr/local/bin/wstunnel client -L tcp://[::]:11035:162.245.221.78:80", rendered["service_unit"])
        self.assertIn("wss://203.0.113.12:443", rendered["service_unit"])
        self.assertNotIn("leikwan-mptcp-client", rendered["service_unit"])
        self.assertNotIn("127.0.0.1:8898", rendered["service_unit"])

    def test_sidecar_aggregation_mptcp_contract_requires_sidecar_metadata(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "mptcp mode missing sidecar host/port"):
            install_runtime.render_forward_runtime(
                {
                    "forward_id": 48,
                    "listen_port": 11036,
                    "target": "162.245.221.78:80",
                    "sni": "edge.example.com",
                    "path_prefix": "aggregation-route",
                    "transport": "tcp",
                    "mode": "mptcp",
                    "backend_mode": "leigod_mode",
                    "active_family": "sidecar_aggregation",
                    "target_contract": "sidecar_aggregation",
                    "sidecar_required": True,
                    "aggregation_enabled": True,
                    "runtime_preference": "mptcp_preferred_bond_fallback",
                    "endpoints": [{"endpoint_id": 317, "host": "203.0.113.13", "port": 443}],
                    "mptcp": {"tcp_session_token": "tcp-token"},
                },
                {"mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py"},
            )


if __name__ == "__main__":
    unittest.main()
