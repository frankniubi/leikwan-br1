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

    def test_apply_wireguard_transit_underlay_single_raises_when_interface_never_appears(self) -> None:
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
                                with self.assertRaisesRegex(RuntimeError, "lw-mptcp-24-0"):
                                    leikwan_mptcp_client.apply_wireguard_transit_underlay_single(
                                        {
                                            "interface_name": "lw-mptcp-24-0",
                                            "config_path": "/etc/wireguard/lw-mptcp-24-0.conf",
                                            "legs": [],
                                        }
                                    )

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
