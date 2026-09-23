import importlib.util
import json
import sys
import tempfile
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
            extra_headers={
                "x-device-platform": "linux",
                "x-device-architecture": MODULE.local_runtime_architecture(),
                "X-Leikwan-Ultra-Offline-Validity": "1800",
            },
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
            extra_headers={"X-Leikwan-Ultra-Offline-Validity": "1800"},
        )
        body = request.call_args.args[3]
        self.assertEqual("device-a", body["device_id"])
        self.assertEqual(42, body["forwards"][0]["forward_id"])
        self.assertEqual("opaque-42", body["forwards"][0]["session_ticket"])
        self.assertEqual(32, len(body["forwards"][0]["nonce"]))
        self.assertEqual("ul2_test", resolved[0]["_ultra_lease"]["lease_id"])
        self.assertNotIn("_ultra_lease", resolved[1])

    def test_resolve_batches_more_than_four_forwards_without_dropping_the_tail(self):
        forwards = []
        responses = []
        for forward_id in range(42, 47):
            forwards.append(
                {
                    "forward_id": forward_id,
                    "backend_mode": "ultra",
                    "target": f"203.0.113.{forward_id}:443",
                    "transport": "tcp+udp",
                    "ultra_access": {
                        "protocol": "leikwan-ultra-policy-v2",
                        "session_ticket": f"opaque-{forward_id}",
                        "policy_generation": "a" * 64,
                        "target_digest": "b" * 64,
                        "transport": "tcp+udp",
                    },
                }
            )
        responses = [
            {
                "ok": True,
                "leases": [
                    {"forward_id": forward_id, "lease_id": f"ul2-{forward_id}"}
                    for forward_id in range(42, 46)
                ],
            },
            {"ok": True, "leases": [{"forward_id": 46, "lease_id": "ul2-46"}]},
        ]

        with mock.patch.object(MODULE, "http_json", side_effect=responses) as request:
            resolved = MODULE.resolve_ultra_leases(
                "https://panel.example.test",
                "client-agent-token",
                "device-a",
                forwards,
            )

        self.assertEqual(2, request.call_count)
        self.assertEqual([42, 43, 44, 45], [item["forward_id"] for item in request.call_args_list[0].args[3]["forwards"]])
        self.assertEqual([46], [item["forward_id"] for item in request.call_args_list[1].args[3]["forwards"]])
        self.assertEqual(
            [42, 43, 44, 45, 46],
            [item["_ultra_lease"]["forward_id"] for item in resolved],
        )

    def test_resolve_keeps_successful_batches_when_tail_is_policy_rejected(self):
        forwards = []
        for forward_id in range(42, 47):
            forwards.append(
                {
                    "forward_id": forward_id,
                    "backend_mode": "ultra",
                    "target": f"203.0.113.{forward_id}:443",
                    "transport": "tcp+udp",
                    "ultra_access": {
                        "session_ticket": f"opaque-{forward_id}",
                        "policy_generation": "a" * 64,
                    },
                }
            )

        with mock.patch.object(
            MODULE,
            "http_json",
            side_effect=[
                {"ok": True, "leases": [{"forward_id": forward_id, "lease_id": f"ul2-{forward_id}"} for forward_id in range(42, 46)]},
                RuntimeError("http 409 https://panel.example.test/api/client/ultra/lease: lease_scope_invalid"),
            ],
        ):
            resolved = MODULE.resolve_ultra_leases("https://panel.example.test", "token", "device", forwards)

        self.assertEqual(1, sum(1 for item in resolved if "_ultra_lease" not in item))
        self.assertEqual("ul2-42", resolved[0]["_ultra_lease"]["lease_id"])
        self.assertNotIn("_ultra_lease", resolved[-1])

    def test_resolve_continues_after_an_invalid_first_batch(self):
        forwards = []
        for forward_id in range(42, 47):
            forwards.append(
                {
                    "forward_id": forward_id,
                    "backend_mode": "ultra",
                    "target": f"203.0.113.{forward_id}:443",
                    "ultra_access": {"session_ticket": f"opaque-{forward_id}", "policy_generation": "a" * 64},
                }
            )

        with mock.patch.object(
            MODULE,
            "http_json",
            side_effect=[
                RuntimeError("http 409 https://panel.example.test/api/client/ultra/lease: lease_scope_invalid"),
                {"ok": True, "leases": [{"forward_id": 46, "lease_id": "ul2-46"}]},
            ],
        ):
            resolved = MODULE.resolve_ultra_leases("https://panel.example.test", "token", "device", forwards)

        self.assertNotIn("_ultra_lease", resolved[0])
        self.assertEqual("ul2-46", resolved[-1]["_ultra_lease"]["lease_id"])

    def test_ultra_rendered_signature_ignores_policy_generation_rotation(self):
        def rendered(generation: str, target_digest: str = "b" * 64):
            return {
                "service_name": "ultra-forward-42",
                "service_path": "/etc/systemd/system/ultra-forward-42.service",
                "service_unit": "unit\n",
                "files": [
                    {
                        "path": "/etc/ultra/forward-42.json",
                        "content": json.dumps(
                            {
                                "forward_id": 42,
                                "listen_port": 32469,
                                "target": "203.0.113.10:443",
                                "policy_generation": generation,
                                "target_digest": target_digest,
                            },
                            sort_keys=True,
                        ),
                    }
                ],
            }

        first = MODULE.ClientAgent.rendered_runtime_signature(rendered("a" * 64), "single", "tcp+udp", 32469)
        rotated = MODULE.ClientAgent.rendered_runtime_signature(rendered("c" * 64), "single", "tcp+udp", 32469)
        changed_target = MODULE.ClientAgent.rendered_runtime_signature(
            rendered("c" * 64, "d" * 64), "single", "tcp+udp", 32469
        )

        self.assertEqual(first, rotated)
        self.assertNotEqual(first, changed_target)

    def test_resolve_uses_mirror_only_when_primary_post_has_no_response(self):
        forward = {"forward_id": 42, "backend_mode": "ultra", "target": "203.0.113.10:443", "ultra_access": {"session_ticket": "opaque", "policy_generation": "a" * 64}}
        response = {"ok": True, "leases": [{"forward_id": 42, "lease_id": "mirror-lease"}]}
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "endpoint.json"
            with mock.patch.object(MODULE, "http_json", side_effect=[TimeoutError("primary timeout"), response]) as request:
                resolved = MODULE.resolve_ultra_leases(
                    "https://v3.leikwanhost.com", "token", "device", [forward],
                    fallback_endpoints=["https://v3.leikwanhost.com", "https://api-leikwanbridge.nbwly.com"],
                    state_path=state,
                )
            self.assertEqual("mirror-lease", resolved[0]["_ultra_lease"]["lease_id"])
            self.assertEqual("https://api-leikwanbridge.nbwly.com/api/client/ultra/lease", request.call_args_list[1].args[1])
            self.assertEqual("https://api-leikwanbridge.nbwly.com", json.loads(state.read_text())["current_endpoint"])

    def test_resolve_does_not_duplicate_post_after_http_response(self):
        forward = {"forward_id": 42, "backend_mode": "ultra", "target": "203.0.113.10:443", "ultra_access": {"session_ticket": "opaque", "policy_generation": "a" * 64}}
        with tempfile.TemporaryDirectory() as td:
            with mock.patch.object(MODULE, "http_json", side_effect=RuntimeError("http 503 https://v3.leikwanhost.com: unavailable")) as request:
                with self.assertRaises(RuntimeError):
                    MODULE.resolve_ultra_leases(
                        "https://v3.leikwanhost.com", "token", "device", [forward],
                        fallback_endpoints=["https://v3.leikwanhost.com", "https://api-leikwanbridge.nbwly.com"],
                        state_path=Path(td) / "endpoint.json",
                    )
            request.assert_called_once()

    def test_resolve_defaults_to_bounded_candidate_batch(self):
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

    def test_resolve_can_opt_in_to_bounded_candidate_batch(self):
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
                selection_mode="auto",
            )

        body = request.call_args.args[3]
        self.assertEqual("auto", body["forwards"][0]["selection_mode"])
        self.assertEqual(4, body["forwards"][0]["max_candidates"])
        self.assertEqual(["ul2-first", "ul2-second"], [item["lease_id"] for item in resolved[0]["_ultra_leases"]])

    def test_resolve_keeps_distinct_lines_on_one_relay_endpoint(self):
        forward = {
            "forward_id": 42,
            "backend_mode": "ultra",
            "target": "203.0.113.10:443",
            "transport": "udp",
            "ultra_access": {
                "session_ticket": "opaque-42",
                "policy_generation": "a" * 64,
            },
        }
        endpoint = {"ip": "203.0.113.20", "port": 5438}
        response = {
            "ok": True,
            "leases": [
                {"forward_id": 42, "lease_id": "line-a", "selection_ref": "ref-a", "endpoint": endpoint, "credential": {"user": "a"}},
                {"forward_id": 42, "lease_id": "line-a-duplicate", "selection_ref": "ref-a", "endpoint": endpoint, "credential": {"user": "a"}},
                {"forward_id": 42, "lease_id": "line-b", "selection_ref": "ref-b", "endpoint": endpoint, "credential": {"user": "b"}},
            ],
        }
        with mock.patch.object(MODULE, "http_json", return_value=response):
            resolved = MODULE.resolve_ultra_leases(
                "https://panel.example.test", "client-agent-token", "device-a", [forward]
            )

        self.assertEqual(["line-a", "line-b"], [item["lease_id"] for item in resolved[0]["_ultra_leases"]])

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
