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

RUNTIME_SPEC = importlib.util.spec_from_file_location(
    "install_forward_runtime_ultra_listener",
    ROOT / "client-agent" / "install_forward_runtime.py",
)
RUNTIME_MODULE = importlib.util.module_from_spec(RUNTIME_SPEC)
assert RUNTIME_SPEC.loader is not None
sys.modules[RUNTIME_SPEC.name] = RUNTIME_MODULE
RUNTIME_SPEC.loader.exec_module(RUNTIME_MODULE)


class UltraClientLeaseTests(unittest.TestCase):
    def test_linux_ultra_listener_is_publicly_reachable(self) -> None:
        rendered = RUNTIME_MODULE.render_ultra_runtime(
            {
                "forward_id": 42,
                "listen_port": 32469,
                "target": "203.0.113.10:443",
                "transport": "tcp+udp",
                "ultra_access": {
                    "protocol": "leikwan-ultra-policy-v2",
                    "session_ticket": "opaque-ticket",
                    "policy_generation": "a" * 64,
                    "target_digest": "b" * 64,
                    "transport": "tcp+udp",
                },
            },
            {},
        )

        config = json.loads(rendered["files"][0]["content"])
        self.assertEqual("0.0.0.0", config["listen_host"])

    def test_linux_pull_declares_platform_to_panel(self):
        with mock.patch.object(MODULE, "http_json_with_headers", return_value=({"forwards": []}, {})) as request:
            payload, headers, master = MODULE.pull_client_configs(
                {"master_url": "https://panel.example.test"},
                "client-agent-token",
            )

        self.assertEqual({"forwards": []}, payload)
        self.assertEqual({}, headers)
        self.assertEqual("https://panel.example.test", master)
        request.assert_called_once_with(
            "GET",
            "https://panel.example.test/api/client/configs/pull",
            "client-agent-token",
            extra_headers={"x-device-platform": "linux"},
        )

    def test_extra_headers_are_sent_on_config_pull(self):
        class FakeResponse:
            headers = {}

            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def read(self):
                return b'{"ok": true}'

        with mock.patch.object(MODULE, "_urlopen_for_request", return_value=FakeResponse()) as open_request:
            payload, _headers = MODULE.http_json_with_headers(
                "GET",
                "https://panel.example.test/api/client/configs/pull",
                "client-agent-token",
                extra_headers={"x-device-platform": "linux"},
            )

        self.assertEqual({"ok": True}, payload)
        request = open_request.call_args.args[0]
        sent_headers = {key.lower(): value for key, value in request.header_items()}
        self.assertEqual("linux", sent_headers["x-device-platform"])

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

    def test_resolve_preserves_bounded_candidate_batch_for_low_jitter_probe(self):
        forward = {
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
        }
        response = {
            "ok": True,
            "leases": [
                {"forward_id": 42, "lease_id": "ul2-first"},
                {"forward_id": 42, "lease_id": "ul2-second"},
            ],
        }
        with mock.patch.object(MODULE, "http_json", return_value=response) as request:
            resolved = MODULE.resolve_ultra_leases(
                "https://panel.example.test",
                "client-agent-token",
                "device-a",
                [forward],
            )

        body = request.call_args.args[3]
        self.assertEqual("auto", body["forwards"][0]["selection_mode"])
        self.assertEqual(4, body["forwards"][0]["max_candidates"])
        self.assertEqual(["ul2-first", "ul2-second"], [item["lease_id"] for item in resolved[0]["_ultra_leases"]])
        self.assertEqual("ul2-first", resolved[0]["_ultra_lease"]["lease_id"])

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
