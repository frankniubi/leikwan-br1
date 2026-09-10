import importlib.util
import json
import pathlib
import sys
import unittest
from types import SimpleNamespace
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


install_runtime = load_module(
    "install_forward_runtime_leigod_slave_preconnect_reuse_under_test",
    "client-agent/install_forward_runtime.py",
)
leikwan_mptcp_client = load_module(
    "leikwan_mptcp_client_leigod_slave_preconnect_reuse_under_test",
    "client-agent/leikwan_mptcp_client.py",
)


class LeigodSlavePreconnectReuseRuntimeTests(unittest.TestCase):
    def test_relay_only_slave_preconnect_does_not_establish_local_wg_or_uot(self) -> None:
        slave_public_host = "slave-public.example.test"
        forward = {
            "forward_id": 73,
            "listen_port": 17324,
            "mode": "mptcp",
            "backend_mode": "leigod_mode",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "leigod-preconnect",
            "sni": "",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "10.255.73.2",
                "sidecar_port": 8898,
                "slave_relay_host": slave_public_host,
                "slave_relay_port": 7324,
                "underlays": [],
                "mptcp_endpoints": [],
                "manage_mptcp_endpoints": False,
                "subflow_limit": 1,
            },
            "endpoints": [],
        }

        rendered = install_runtime.render_forward_runtime(
            forward,
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43000,
            },
        )
        config_file = next(
            item for item in rendered["files"] if item["path"] == "/etc/leikwan/mptcp-forward-73.json"
        )
        payload = json.loads(config_file["content"])
        rendered_text = "\n".join(
            [
                rendered["service_unit"],
                rendered["start_script"],
                config_file["content"],
            ]
        )

        self.assertEqual(slave_public_host, payload["slave_relay_host"])
        self.assertEqual(7324, payload["slave_relay_port"])
        self.assertIn(f'"slave_relay_host": "{slave_public_host}"', config_file["content"])
        self.assertIn('"slave_relay_port": 7324', config_file["content"])
        self.assertIn(
            "/usr/bin/python3 /usr/local/bin/leikwan-mptcp-client.py --config /etc/leikwan/mptcp-forward-73.json",
            rendered["start_script"],
        )

        self.assertEqual([], payload["underlays"])
        self.assertEqual([], payload["mptcp_endpoints"])
        self.assertFalse(payload["manage_mptcp_endpoints"])
        self.assertEqual(1, payload["subflow_limit"])

        self.assertNotIn("wstunnel client -L udp://127.0.0.1", rendered_text)
        self.assertNotIn("wg-quick up", rendered_text)
        self.assertNotIn("wg-quick@", rendered_text)
        self.assertNotIn("/etc/wireguard/lw-mptcp-", rendered_text)
        self.assertNotIn("lw-mptcp-", rendered_text)
        self.assertNotIn("leikwan-uot", rendered_text)

        recorded_commands = []

        def fake_run_command(parts, check=False):
            recorded_commands.append(parts)
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                with mock.patch.object(
                    leikwan_mptcp_client,
                    "apply_wireguard_transit_underlays",
                    side_effect=AssertionError("empty underlays must not start local WireGuard setup"),
                ):
                    with mock.patch.object(
                        leikwan_mptcp_client,
                        "ensure_uot_client_service",
                        side_effect=AssertionError("relay-only preconnect must not start local UoT"),
                    ):
                        leikwan_mptcp_client.setup_runtime(payload)

        self.assertEqual([], recorded_commands)


if __name__ == "__main__":
    unittest.main()
