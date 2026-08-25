import importlib.util
import json
import sys
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_ultra_materials", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class UltraClientLeaseTests(unittest.TestCase):
    def test_panel_stop_action_requires_current_policy_generation(self):
        agent = MODULE.ClientAgent.__new__(MODULE.ClientAgent)
        agent.latest_forwards = [{
            "forward_id": 42,
            "backend_mode": "ultra",
            "ultra_access": {"policy_generation": "a" * 64},
        }]
        with mock.patch.object(agent, "log_invalid_forward") as log_invalid, mock.patch.object(agent, "stop_forward") as stop:
            agent.apply_ultra_actions({
                "ultra_actions": [{
                    "forward_id": 42,
                    "policy_generation": "a" * 64,
                    "action": "stop",
                    "reason": "traffic_limit",
                }]
            })
        log_invalid.assert_called_once()
        stop.assert_called_once_with(42, delete_files=True)

    def test_panel_stop_action_does_not_apply_to_stale_generation(self):
        agent = MODULE.ClientAgent.__new__(MODULE.ClientAgent)
        agent.latest_forwards = [{
            "forward_id": 42,
            "backend_mode": "ultra",
            "ultra_access": {"policy_generation": "a" * 64},
        }]
        with mock.patch.object(agent, "stop_forward") as stop:
            agent.apply_ultra_actions({
                "ultra_actions": [{
                    "forward_id": 42,
                    "policy_generation": "b" * 64,
                    "action": "stop",
                }]
            })
        stop.assert_not_called()

    def test_resolve_is_one_authenticated_batch_after_config_pull(self):
        forwards = [
            {
                "forward_id": 42,
                "backend_mode": "ultra",
                "target": "203.0.113.10:443",
                "transport": "tcp+udp",
                "ultra_access": {
                    "protocol": "leikwan-ultra-policy-v2",
                    "session_ticket": "opaque-42",
                    "policy_generation": "a" * 64,
                    "target_digest": "b" * 64,
                    "transport": "tcp+udp",
                },
            },
            {"forward_id": 43, "backend_mode": "current_mode"},
        ]
        response = {
            "ok": True,
            "leases": [
                {
                    "forward_id": 42,
                    "protocol": "leikwan-ultra-policy-v2",
                    "session_ticket": "opaque-42",
                    "policy_generation": "a" * 64,
                    "target_digest": "b" * 64,
                    "nonce_hash": "c" * 64,
                    "lease_id": "ul2_test",
                    "expires_at": "2030-01-01T00:30:00Z",
                    "transport": "tcp+udp",
                    "endpoint": {"ip": "203.0.113.20", "port": 5438},
                    "credential": {"user": "line-user", "password": "line-password"},
                }
            ],
        }
        with mock.patch.object(MODULE, "http_json", return_value=response) as request:
            resolved = MODULE.resolve_ultra_leases(
                "https://panel.example.test",
                "client-agent-token",
                "device-a",
                forwards,
            )

        request.assert_called_once_with(
            "POST",
            "https://panel.example.test/api/client/ultra/lease",
            "client-agent-token",
            {
                "device_id": "device-a",
                "forwards": [mock.ANY],
            },
        )
        body = request.call_args.args[3]
        self.assertEqual("device-a", body["device_id"])
        self.assertEqual(42, body["forwards"][0]["forward_id"])
        self.assertEqual("opaque-42", body["forwards"][0]["session_ticket"])
        self.assertEqual(32, len(body["forwards"][0]["nonce"]))
        self.assertEqual("ul2_test", resolved[0]["_ultra_lease"]["lease_id"])
        self.assertNotIn("_ultra_lease", resolved[1])

    def test_normal_pull_contract_does_not_contain_ports_or_credentials(self):
        access = {"session_ticket": "opaque", "policy_generation": "a" * 64, "transport": "tcp+udp"}
        encoded = json.dumps(access).lower()
        self.assertNotIn('"port"', encoded)
        self.assertNotIn("credential", encoded)
        self.assertNotIn("user", encoded)

    def test_no_ultra_forward_skips_material_request(self):
        with mock.patch.object(MODULE, "http_json") as request:
            resolved = MODULE.resolve_ultra_leases(
                "https://panel.example.test",
                "client-agent-token",
                "device-a",
                [{"forward_id": 43, "backend_mode": "current_mode"}],
            )
        request.assert_not_called()
        self.assertEqual([{"forward_id": 43, "backend_mode": "current_mode"}], resolved)


if __name__ == "__main__":
    unittest.main()
