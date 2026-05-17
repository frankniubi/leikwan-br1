import importlib.util
import json
import pathlib
import sys
import tempfile
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


leikwan_mptcp_client = load_module(
    "leikwan_mptcp_client_setup_cleanup_under_test",
    "client-agent/leikwan_mptcp_client.py",
)


class MptcpSetupAndCleanupRegressions(unittest.TestCase):
    def test_load_json_normalizes_nonpositive_endpoint_id_base(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "mptcp_endpoint_id_base": 0,
                        "mptcp_endpoints": [
                            {
                                "address": "10.203.107.177",
                                "dev": "lw-mptcp-25-0",
                                "subflow": True,
                            }
                        ],
                    }
                ),
                encoding="utf-8",
            )

            payload = leikwan_mptcp_client.load_json(config_path)
            persisted = json.loads(config_path.read_text(encoding="utf-8"))

        self.assertEqual(payload["mptcp_endpoint_id_base"], 1)
        self.assertEqual(persisted["mptcp_endpoint_id_base"], 1)

    def test_cleanup_stale_transit_interfaces_is_scoped_to_current_forward(self) -> None:
        recorded = []

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts == ["wg", "show", "interfaces"]:
                return SimpleNamespace(
                    returncode=0,
                    stdout="lw-mptcp-24-0 lw-mptcp-24-1 lw-mptcp-25-0 lw-mptcp-25-1",
                    stderr="",
                )
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(
                leikwan_mptcp_client,
                "shutil_which",
                side_effect=lambda cmd: "/usr/bin/" + cmd,
            ):
                with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(leikwan_mptcp_client.pathlib.Path, "exists", return_value=False):
                        leikwan_mptcp_client.cleanup_stale_transit_interfaces(
                            {"forward_id": 24},
                            {"lw-mptcp-24-0"},
                        )

        command_strings = [" ".join(parts) for parts in recorded if isinstance(parts, list)]
        self.assertTrue(any("wg-quick@lw-mptcp-24-1.service" in line for line in command_strings))
        self.assertFalse(any("wg-quick@lw-mptcp-25-0.service" in line for line in command_strings))
        self.assertFalse(any("wg-quick@lw-mptcp-25-1.service" in line for line in command_strings))

    def test_apply_wireguard_transit_underlay_single_cleans_existing_interface_before_restart(self) -> None:
        recorded = []

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts[:4] == ["ip", "link", "show", "dev"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(
                leikwan_mptcp_client,
                "shutil_which",
                side_effect=lambda cmd: "/usr/bin/" + cmd,
            ):
                with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(leikwan_mptcp_client, "build_wireguard_interface_config", return_value="[Interface]\n"):
                        with mock.patch.object(leikwan_mptcp_client, "write_text_if_changed", return_value=False):
                            with mock.patch.object(leikwan_mptcp_client.pathlib.Path, "is_file", return_value=True):
                                leikwan_mptcp_client.apply_wireguard_transit_underlay_single(
                                    {
                                        "interface_name": "lw-mptcp-24-0",
                                        "config_path": "/etc/wireguard/lw-mptcp-24-0.conf",
                                        "legs": [],
                                    }
                                )

        command_strings = [" ".join(parts) for parts in recorded if isinstance(parts, list)]
        self.assertTrue(any("systemctl stop wg-quick@lw-mptcp-24-0.service" in line for line in command_strings))
        self.assertTrue(any("wg-quick down lw-mptcp-24-0" in line for line in command_strings))
        self.assertTrue(any("ip link delete dev lw-mptcp-24-0" in line for line in command_strings))
        self.assertTrue(any("systemctl enable wg-quick@lw-mptcp-24-0.service" in line for line in command_strings))
        self.assertTrue(any("wg-quick up /etc/wireguard/lw-mptcp-24-0.conf" in line for line in command_strings))

    def test_apply_wireguard_transit_underlay_single_skips_bad_interface_without_crashing(self) -> None:
        recorded = []

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts[:4] == ["ip", "link", "show", "dev"]:
                return SimpleNamespace(returncode=1, stdout="", stderr="Device does not exist")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(
                leikwan_mptcp_client,
                "shutil_which",
                side_effect=lambda cmd: "/usr/bin/" + cmd,
            ):
                with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(leikwan_mptcp_client, "build_wireguard_interface_config", return_value="[Interface]\n"):
                        with mock.patch.object(leikwan_mptcp_client, "write_text_if_changed", return_value=False):
                            with mock.patch.object(leikwan_mptcp_client.time, "sleep"):
                                result = leikwan_mptcp_client.apply_wireguard_transit_underlay_single(
                                    {
                                        "interface_name": "lw-mptcp-24-0",
                                        "config_path": "/etc/wireguard/lw-mptcp-24-0.conf",
                                        "legs": [],
                                    }
                                )

        self.assertFalse(result)
        command_strings = [" ".join(parts) for parts in recorded if isinstance(parts, list)]
        self.assertTrue(any("ip link delete dev lw-mptcp-24-0" in line for line in command_strings))

    def test_apply_wireguard_transit_underlay_keeps_uot_unit_when_wg_quick_succeeds(self) -> None:
        recorded = []
        removed = []

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts[:4] == ["ip", "link", "show", "dev"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(
                leikwan_mptcp_client,
                "shutil_which",
                side_effect=lambda cmd: "/usr/bin/" + cmd,
            ):
                with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(leikwan_mptcp_client, "build_wireguard_interface_config", return_value="[Interface]\n"):
                        with mock.patch.object(leikwan_mptcp_client, "write_text_if_changed", return_value=False):
                            with mock.patch.object(leikwan_mptcp_client.pathlib.Path, "is_file", return_value=True):
                                with mock.patch.object(
                                    leikwan_mptcp_client,
                                    "remove_uot_client_service_units_for_interface",
                                    side_effect=lambda iface: removed.append(iface) or True,
                                ):
                                    ok = leikwan_mptcp_client.apply_wireguard_transit_underlay_single(
                                        {
                                            "interface_name": "lw-mptcp-47-3",
                                            "config_path": "/etc/wireguard/lw-mptcp-47-3.conf",
                                            "legs": [
                                                {
                                                    "endpoint_id": 320,
                                                    "outer_transport": "udp",
                                                    "local_address": "10.203.201.181",
                                                    "peer_address": "10.203.201.182",
                                                    "mptcp_remote_address": "10.203.201.183",
                                                }
                                            ],
                                        }
                                    )

        self.assertTrue(ok)
        self.assertEqual([], removed)
        command_strings = [" ".join(parts) for parts in recorded if isinstance(parts, list)]
        self.assertTrue(any("wg-quick up /etc/wireguard/lw-mptcp-47-3.conf" in line for line in command_strings))


    def test_build_wireguard_interface_config_supports_client_self_leg_profile(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            private_key = pathlib.Path(tmp_dir) / "private.key"
            private_key.write_text("client-private\n", encoding="utf-8")
            config = leikwan_mptcp_client.build_wireguard_interface_config(
                {
                    "mode": "client_self_leg",
                    "interface_name": "lw-self-47",
                    "local_private_key_path": str(private_key),
                    "listen_port": 43047,
                    "peer_keepalive_seconds": 25,
                    "legs": [
                        {
                            "local_address": "10.204.47.1",
                            "peer_address": "10.204.47.2",
                            "mptcp_remote_address": "10.204.47.2",
                            "peer_public_key": "sidecar-pub",
                            "peer_public_host": "185.220.238.143",
                            "peer_udp_port": 14969,
                        }
                    ],
                }
            )

        self.assertIn("PrivateKey = client-private", config)
        self.assertIn("ListenPort = 43047", config)
        self.assertIn("Address = 10.204.47.1/32", config)
        self.assertIn("PublicKey = sidecar-pub", config)
        self.assertIn("Endpoint = 185.220.238.143:14969", config)
        self.assertIn("AllowedIPs = 10.204.47.2/32", config)
        self.assertIn("PersistentKeepalive = 25", config)
        self.assertIn("PostUp = ip -4 route replace 10.204.47.2/32 dev lw-self-47 src 10.204.47.1", config)

    def test_cleanup_stale_transit_interfaces_is_scoped_to_self_leg_forward(self) -> None:
        recorded = []

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts == ["wg", "show", "interfaces"]:
                return SimpleNamespace(
                    returncode=0,
                    stdout="lw-self-47 lw-self-48 lw-mptcp-47-0",
                    stderr="",
                )
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(
                leikwan_mptcp_client,
                "shutil_which",
                side_effect=lambda cmd: "/usr/bin/" + cmd,
            ):
                with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(leikwan_mptcp_client.pathlib.Path, "exists", return_value=False):
                        leikwan_mptcp_client.cleanup_stale_transit_interfaces(
                            {"forward_id": 47},
                            set(),
                        )

        command_strings = [" ".join(parts) for parts in recorded if isinstance(parts, list)]
        self.assertTrue(any("wg-quick@lw-self-47.service" in line for line in command_strings))
        self.assertFalse(any("wg-quick@lw-self-48.service" in line for line in command_strings))
        self.assertTrue(any("wg-quick@lw-mptcp-47-0.service" in line for line in command_strings))

    def test_main_setup_underlays_only_applies_underlays(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "forward_id": 25,
                        "listen_port": 11023,
                        "transport": "tcp+udp",
                        "allow_tcp_fallback": True,
                    }
                ),
                encoding="utf-8",
            )

            with mock.patch.object(
                leikwan_mptcp_client,
                "parse_args",
                return_value=SimpleNamespace(config=str(config_path), setup_underlays=True),
            ):
                with mock.patch.object(leikwan_mptcp_client, "apply_underlays") as apply_underlays_mock:
                    with mock.patch.object(
                        leikwan_mptcp_client,
                        "configure_mptcp_policy",
                        side_effect=AssertionError("setup-only mode should not touch MPTCP endpoints"),
                    ):
                        with mock.patch.object(
                            leikwan_mptcp_client.asyncio,
                            "run",
                            side_effect=AssertionError("main() should not enter the long-running async relay in setup-only mode"),
                        ):
                            exit_code = leikwan_mptcp_client.main()

        self.assertEqual(exit_code, 0)
        apply_underlays_mock.assert_called_once()


if __name__ == "__main__":
    unittest.main()
