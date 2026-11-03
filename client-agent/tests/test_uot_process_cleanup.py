import importlib.util
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


leikwan_mptcp_client = load_module(
    "leikwan_mptcp_client_uot_process_cleanup_under_test",
    "client-agent/leikwan_mptcp_client.py",
)


class UotProcessCleanupTests(unittest.TestCase):
    @unittest.skipUnless(
        sys.platform.startswith("linux"),
        "UoT client systemd service and UDP listener cleanup are Linux-only",
    )
    def test_ensure_uot_client_service_kills_conflicting_udp_listener_processes(self) -> None:
        commands: list[list[str]] = []

        leg = {
            "endpoint_id": 179,
            "outer_transport": "tcp",
            "local_uot_udp_port": 53240,
            "peer_tcp_fallback_port": 9975,
            "peer_public_host": "8.163.90.132",
        }

        def fake_run_command(parts: list[str], check: bool = False):
            commands.append(parts)
            if parts[:2] == ["pgrep", "-f"]:
                return SimpleNamespace(returncode=0, stdout="1812478\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(leikwan_mptcp_client, "shutil_which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(leikwan_mptcp_client, "write_text_if_changed", return_value=False):
                        leikwan_mptcp_client.ensure_uot_client_service("lw-mptcp-25-0", leg)

        rendered = [" ".join(parts) for parts in commands]
        self.assertTrue(any("systemctl stop leikwan-uot-lw-mptcp-25-0-179.service" in item for item in rendered))
        self.assertTrue(any("pgrep -f leikwan-uot.py client --listen-udp 127.0.0.1:53240" in item for item in rendered))
        self.assertTrue(any("kill -TERM 1812478" in item for item in rendered))
        self.assertTrue(any("systemctl restart leikwan-uot-lw-mptcp-25-0-179.service" in item for item in rendered))


if __name__ == "__main__":
    unittest.main()
