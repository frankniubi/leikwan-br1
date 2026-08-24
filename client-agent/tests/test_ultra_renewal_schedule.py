import importlib.util
import sys
import tempfile
import unittest
from unittest import mock
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_ultra_schedule", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class UltraRenewalScheduleTests(unittest.TestCase):
    def test_control_plane_mirrors_are_https_bounded_and_primary_first(self):
        self.assertEqual(
            ["https://primary.example", "https://mirror-a.example", "https://mirror-b.example"],
            MODULE.control_plane_endpoints(
                {
                    "master_url": "https://primary.example/",
                    "panel_mirror_urls": [
                        "https://mirror-a.example/",
                        "http://insecure.example",
                        "https://mirror-b.example",
                        "https://mirror-c.example",
                        "https://mirror-d.example",
                    ],
                }
            ),
        )

    def test_normal_polling_stays_at_configured_interval_before_renewal(self):
        delay = MODULE.ultra_config_poll_delay(
            [
                {
                    "forward_id": 42,
                    "backend_mode": "ultra",
                    "ultra_access": {
                        "renew_after": "2030-01-01T00:20:00Z",
                        "expires_at": "2030-01-01T00:30:00Z",
                    },
                }
            ],
            now=1893456000.0,
            base_delay=30,
            stable_key="client-a",
        )
        self.assertEqual(30.0, delay)

    def test_polling_accelerates_near_expiry_without_busy_loop(self):
        now = 1893456000.0
        delay = MODULE.ultra_config_poll_delay(
            [
                {
                    "forward_id": 42,
                    "backend_mode": "ultra",
                    "ultra_access": {
                        "renew_after": MODULE.format_rfc3339(now - 1),
                        "expires_at": MODULE.format_rfc3339(now + 90),
                    },
                }
            ],
            now=now,
            base_delay=30,
            stable_key="client-a",
        )
        self.assertGreaterEqual(delay, 5.0)
        self.assertLessEqual(delay, 10.0)

    def test_missing_grant_uses_bounded_urgent_retry(self):
        delay = MODULE.ultra_config_poll_delay(
            [{"forward_id": 42, "backend_mode": "ultra"}],
            now=1893456000.0,
            base_delay=30,
            stable_key="client-a",
        )
        self.assertGreaterEqual(delay, 5.0)
        self.assertLessEqual(delay, 10.0)

    def test_ultra_runtime_does_not_require_panel_slave_endpoints(self):
        self.assertFalse(
            MODULE.forward_requires_panel_endpoints(
                {"backend_mode": "ultra", "endpoint_mode": "ultra", "endpoints": []},
                "single",
            )
        )
        self.assertTrue(
            MODULE.forward_requires_panel_endpoints(
                {"backend_mode": "current_mode", "endpoint_mode": "slave_group", "endpoints": []},
                "single",
            )
        )
        self.assertEqual("ultra-forward-42", MODULE.forward_runtime_service_name({"forward_id": 42, "backend_mode": "ultra"}))

    def test_ultra_access_is_not_part_of_restart_contract_signature(self):
        first = {
            "service_name": "ultra-forward-42",
            "service_path": "/etc/systemd/system/ultra-forward-42.service",
            "service_unit": "unit",
            "files": [
                {"path": "/etc/ultra/forward-42.json", "content": "static", "mode": 0o600},
                {"path": "/run/ultra/forward-42.access.json", "content": "token-a", "mode": 0o600, "restart_on_change": False},
            ],
        }
        second = {**first, "files": [first["files"][0], {**first["files"][1], "content": "token-b"}]}
        self.assertEqual(
            MODULE.ClientAgent.rendered_runtime_signature(first, "single", "tcp+udp", 12000),
            MODULE.ClientAgent.rendered_runtime_signature(second, "single", "tcp+udp", 12000),
        )

    def test_ultra_token_and_material_are_not_part_of_static_forward_contract(self):
        agent = object.__new__(MODULE.ClientAgent)
        agent.config = {}
        agent.state_dir = Path("/tmp/ultra-test")
        agent.running_kernel_supports_mptcp = lambda: False
        first = {
            "forward_id": 42,
            "backend_mode": "ultra",
            "target": "203.0.113.10:443",
            "ultra_access": {"token": "token-a"},
            "_ultra_material": {"sealed_material": "um1.a"},
        }
        second = {
            **first,
            "ultra_access": {"token": "token-b"},
            "_ultra_material": {"sealed_material": "um1.b"},
        }
        self.assertEqual(agent.forward_contract_signature(first), agent.forward_contract_signature(second))

    def test_reload_only_file_is_written_and_signalled_without_restart(self):
        agent = object.__new__(MODULE.ClientAgent)
        agent.systemd_is_active = lambda _name: True
        with tempfile.TemporaryDirectory() as directory:
            access_path = Path(directory) / "forward.access.json"
            rendered = {
                "service_name": "ultra-forward-42",
                "files": [
                    {
                        "path": str(access_path),
                        "content": "new-token\n",
                        "mode": None,
                        "restart_on_change": False,
                        "reload_signal": "HUP",
                    }
                ],
            }
            with mock.patch.object(MODULE.subprocess, "run") as run:
                self.assertTrue(agent.apply_reload_only_files(rendered))
            self.assertEqual("new-token\n", access_path.read_text(encoding="utf-8"))
            run.assert_called_once_with(
                ["systemctl", "kill", "-s", "HUP", "ultra-forward-42.service"],
                check=False,
            )


if __name__ == "__main__":
    unittest.main()
