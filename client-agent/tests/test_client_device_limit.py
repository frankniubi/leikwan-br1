import importlib.util
import io
import json
import logging
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_device_limit", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)

PANEL = "https://v3.leikwanhost.com"
MIRROR = "https://api-leikwanbridge.nbwly.com"
FLAT_BODY = json.dumps(
    {
        "ok": False,
        "error": "client_online_limit_reached",
        "protocol_version": 1,
        "online_device_limit": 2,
        "active_online_devices": 2,
        "details": {"online_device_limit": 2, "active_online_devices": 2},
    }
)
NESTED_BODY = json.dumps(
    {
        "ok": False,
        "error": "client_online_limit_reached",
        "details": {"online_device_limit": 3, "active_online_devices": 3},
    }
)


def http_error(url: str, status: int, body: str) -> urllib.error.HTTPError:
    return urllib.error.HTTPError(url, status, "error", {}, io.BytesIO(body.encode("utf-8")))


def make_agent(state_dir: Path, **config) -> "MODULE.ClientAgent":
    config_path = state_dir / "config.json"
    config_path.write_text(
        json.dumps(
            {
                "master_url": PANEL,
                "panel_mirror_urls": [MIRROR],
                "state_dir": str(state_dir / "state"),
                "agent_token": "token",
                "client_id": 9,
                **config,
            }
        ),
        encoding="utf-8",
    )
    return MODULE.ClientAgent(config_path)


class DeviceIdPersistenceTests(unittest.TestCase):
    def test_device_id_is_created_once_and_reused(self):
        with tempfile.TemporaryDirectory() as td:
            first = MODULE.load_or_create_device_id(Path(td))
            self.assertRegex(first, r"^linux-[0-9a-f]{32}$")
            path = Path(td) / "device-id"
            self.assertEqual(first, path.read_text(encoding="utf-8").strip())
            self.assertEqual(first, MODULE.load_or_create_device_id(Path(td)))
            self.assertEqual([path], [item for item in Path(td).iterdir()])

    def test_agent_reuses_persisted_id_across_restarts(self):
        with tempfile.TemporaryDirectory() as td:
            first = make_agent(Path(td)).device_id()
            second = make_agent(Path(td)).device_id()
            self.assertEqual(first, second)
            self.assertRegex(first, r"^linux-[0-9a-f]{32}$")

    def test_invalid_device_id_file_is_replaced(self):
        with tempfile.TemporaryDirectory() as td:
            (Path(td) / "device-id").write_text("bad id\n", encoding="utf-8")
            value = MODULE.load_or_create_device_id(Path(td))
            self.assertRegex(value, r"^linux-[0-9a-f]{32}$")
            self.assertEqual(value, (Path(td) / "device-id").read_text(encoding="utf-8").strip())

    def test_unpersistable_device_id_is_never_sent(self):
        with tempfile.TemporaryDirectory() as td:
            with mock.patch.object(MODULE.tempfile, "mkstemp", side_effect=PermissionError("read-only")):
                self.assertEqual("", MODULE.load_or_create_device_id(Path(td)))
            self.assertNotIn("x-device-id", MODULE.device_request_headers(""))

    def test_state_dir_migration_keeps_device_id(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            original = agent.device_id()
            new_state = Path(td) / "moved"
            agent.apply_config_patch({"state_dir": str(new_state)})
            self.assertEqual(new_state, agent.state_dir)
            self.assertEqual(original, (new_state / "device-id").read_text(encoding="utf-8").strip())
            self.assertEqual(original, agent.device_id())


class DeviceIdHeaderTests(unittest.TestCase):
    def test_pull_sends_persisted_device_id(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            with mock.patch.object(MODULE, "http_json_with_headers", return_value=({"forwards": []}, {})) as request:
                agent.pull_client_configs("token")
            headers = request.call_args.kwargs["extra_headers"]
            self.assertEqual(agent.device_id(), headers["x-device-id"])
            self.assertEqual("linux", headers["x-device-platform"])

    def test_heartbeat_and_diagnostics_send_persisted_device_id(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            with mock.patch.object(MODULE, "http_json", return_value={"ok": True}) as request:
                agent.control_plane_request("POST", "/api/client/heartbeat", "token", {})
                agent.control_plane_request("POST", "/api/client/diagnostics/result", "token", {})
            for call in request.call_args_list:
                self.assertEqual(agent.device_id(), call.kwargs["extra_headers"]["x-device-id"])

    def test_explicit_extra_headers_keep_device_id(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            with mock.patch.object(MODULE, "http_json_with_headers", return_value=({"ok": True}, {})) as request:
                agent.control_plane_request("POST", "/api/client/debug/log", "token", {}, extra_headers={"x-extra": "1"})
            headers = request.call_args.kwargs["extra_headers"]
            self.assertEqual(agent.device_id(), headers["x-device-id"])
            self.assertEqual("1", headers["x-extra"])

    def test_check_live_pull_sends_device_id(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            with mock.patch.object(agent, "systemd_is_active", return_value=True), \
                 mock.patch.object(agent, "ensure_wireguard_identity", return_value=""), \
                 mock.patch.object(MODULE, "http_json", return_value={"forwards": []}) as request:
                agent.check(live=True)
            self.assertEqual(agent.device_id(), request.call_args.kwargs["extra_headers"]["x-device-id"])


class DeviceLimitParsingTests(unittest.TestCase):
    def test_flat_fields_are_parsed(self):
        error = MODULE.parse_device_limit_error(429, FLAT_BODY, PANEL)
        self.assertIsInstance(error, MODULE.DeviceLimitReachedError)
        self.assertEqual((2, 2), (error.online_device_limit, error.active_online_devices))

    def test_nested_details_are_fallback(self):
        error = MODULE.parse_device_limit_error(429, NESTED_BODY, PANEL)
        self.assertEqual((3, 3), (error.online_device_limit, error.active_online_devices))

    def test_counts_unknown_and_other_errors(self):
        error = MODULE.parse_device_limit_error(429, json.dumps({"error": "client_online_limit_reached"}), PANEL)
        self.assertIsNone(error.online_device_limit)
        self.assertEqual(
            "device limit reached: disconnect another device or ask your provider to raise the device limit; retrying in 60s",
            error.user_message(60),
        )
        self.assertIsNone(MODULE.parse_device_limit_error(429, json.dumps({"error": "rate_limited"}), PANEL))
        self.assertIsNone(MODULE.parse_device_limit_error(429, "not json", PANEL))
        self.assertIsNone(MODULE.parse_device_limit_error(503, FLAT_BODY, PANEL))

    def test_http_json_raises_dedicated_error(self):
        url = f"{PANEL}/api/client/heartbeat"
        with mock.patch.object(MODULE, "_urlopen_for_request", side_effect=http_error(url, 429, FLAT_BODY)):
            with self.assertRaises(MODULE.DeviceLimitReachedError) as caught:
                MODULE.http_json("POST", url, "token", {})
        self.assertEqual(
            "device limit reached (2 of 2 devices online): disconnect another device or ask your provider "
            "to raise the device limit; retrying in 60s",
            caught.exception.user_message(60),
        )
        with mock.patch.object(MODULE, "_urlopen_for_request", side_effect=http_error(url, 429, NESTED_BODY)):
            with self.assertRaises(MODULE.DeviceLimitReachedError):
                MODULE.http_json_with_headers("GET", url, "token")

    def test_not_classified_as_transient(self):
        error = MODULE.parse_device_limit_error(429, FLAT_BODY, PANEL)
        self.assertFalse(MODULE.is_transient_control_plane_error(error))
        self.assertNotIsInstance(error, MODULE.ControlPlaneUnavailableError)


class DeviceLimitNoFailoverTests(unittest.TestCase):
    def test_pull_does_not_rotate_mirrors_or_penalize(self):
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "endpoint.json"
            error = MODULE.parse_device_limit_error(429, FLAT_BODY, PANEL)
            with mock.patch.object(MODULE, "http_json_with_headers", side_effect=error) as request:
                with self.assertRaises(MODULE.DeviceLimitReachedError):
                    MODULE.pull_client_configs({"master_url": PANEL, "panel_mirror_urls": [MIRROR]}, "token", state_path=state)
            request.assert_called_once()
            self.assertFalse(state.exists())

    def test_control_plane_request_does_not_rotate_mirrors(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            error = MODULE.parse_device_limit_error(429, FLAT_BODY, PANEL)
            with mock.patch.object(MODULE, "http_json", side_effect=error) as request:
                with self.assertRaises(MODULE.DeviceLimitReachedError):
                    agent.control_plane_request("POST", "/api/client/heartbeat", "token", {})
            request.assert_called_once()
            self.assertEqual({}, agent.endpoint_selector.failures)

    def test_lease_does_not_rotate_mirrors(self):
        forward = {
            "forward_id": 7,
            "backend_mode": "ultra",
            "target": "203.0.113.9:443",
            "ultra_access": {"session_ticket": "opaque", "policy_generation": "a" * 64},
        }
        error = MODULE.parse_device_limit_error(429, FLAT_BODY, PANEL)
        with mock.patch.object(MODULE, "http_json", side_effect=error) as request:
            with self.assertRaises(MODULE.DeviceLimitReachedError):
                MODULE.resolve_ultra_leases(PANEL, "token", "linux-" + "0" * 32, [forward], fallback_endpoints=[MIRROR])
        request.assert_called_once()


class DeviceLimitSyncLoopTests(unittest.TestCase):
    def test_sync_loop_keeps_steady_cadence_and_records_state(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            agent.stop_forward = mock.Mock()
            error = MODULE.parse_device_limit_error(429, FLAT_BODY, PANEL)
            with mock.patch.object(MODULE, "http_json_with_headers", side_effect=error), \
                 self.assertLogs(level=logging.WARNING) as logs:
                delays = [agent.sync_with_backoff(1000.0) for _ in range(4)]
            self.assertEqual([60.0] * 4, delays)
            self.assertEqual(0, agent._sync_backoff.failures)
            agent.stop_forward.assert_not_called()
            self.assertIn(
                "device limit reached (2 of 2 devices online): disconnect another device or ask your provider "
                "to raise the device limit; retrying in 60s",
                logs.output[-1],
            )
            self.assertFalse(any("control plane unavailable" in line for line in logs.output))
            state = agent.load_pull_state()
            self.assertEqual("device_limit_reached", state["status"])
            self.assertEqual("client_online_limit_reached", state["error_code"])
            self.assertEqual(2, state["online_device_limit"])
            self.assertEqual(2, state["active_online_devices"])
            self.assertIn("device limit reached (2 of 2 devices online)", state["last_error"])

            with mock.patch.object(agent, "systemd_is_active", return_value=True), \
                 mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                summary = agent.check()
            self.assertFalse(summary["ok"])
            self.assertIn("device limit reached (2 of 2 devices online)", summary["device_limit_error"])

    def test_successful_sync_clears_device_limit_state(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            agent.save_pull_state(
                {
                    "status": "device_limit_reached",
                    "error_code": "client_online_limit_reached",
                    "online_device_limit": 2,
                    "active_online_devices": 2,
                    "last_error": "device limit reached",
                }
            )
            with mock.patch.object(MODULE, "http_json_with_headers", return_value=({"forwards": [], "client": {"id": 9}}, {})), \
                 mock.patch.object(MODULE, "http_json", return_value={"ok": True}), \
                 mock.patch.object(agent, "apply_management_state"), \
                 mock.patch.object(agent, "reconcile_global_mptcp_state"), \
                 mock.patch.object(agent, "run_diagnostics_if_requested"), \
                 mock.patch.object(agent, "ensure_wireguard_identity", return_value=""), \
                 mock.patch.object(agent, "collect_usage_totals", return_value={"bytes_in": 0, "bytes_out": 0}), \
                 mock.patch.object(agent, "commit_usage_state"):
                agent.sync_with_backoff(1000.0)
            state = agent.load_pull_state()
            self.assertEqual("ok", state["status"])
            self.assertEqual("", state["last_error"])
            for key in ("error_code", "online_device_limit", "active_online_devices"):
                self.assertNotIn(key, state)

    def test_heartbeat_refusal_is_logged_not_raised(self):
        with tempfile.TemporaryDirectory() as td:
            agent = make_agent(Path(td))
            error = MODULE.parse_device_limit_error(429, NESTED_BODY, PANEL)

            def post(method, url, token, payload=None, *, extra_headers=None):
                raise error

            with mock.patch.object(MODULE, "http_json_with_headers", return_value=({"forwards": [], "client": {"id": 9}}, {})), \
                 mock.patch.object(MODULE, "http_json", side_effect=post), \
                 mock.patch.object(agent, "apply_management_state"), \
                 mock.patch.object(agent, "reconcile_global_mptcp_state"), \
                 mock.patch.object(agent, "run_diagnostics_if_requested"), \
                 mock.patch.object(agent, "ensure_wireguard_identity", return_value=""), \
                 mock.patch.object(agent, "collect_usage_totals", return_value={"bytes_in": 0, "bytes_out": 0}), \
                 mock.patch.object(agent, "commit_usage_state") as commit_usage, \
                 self.assertLogs(level=logging.WARNING) as logs:
                self.assertEqual(60.0, agent.sync_with_backoff(1000.0))
            commit_usage.assert_not_called()
            self.assertIn("device limit reached (3 of 3 devices online)", logs.output[-1])
            self.assertEqual("device_limit_reached", agent.load_pull_state()["status"])

    def test_renewal_retries_at_device_limit_cadence(self):
        schedule: dict = {}
        error = MODULE.parse_device_limit_error(429, FLAT_BODY, PANEL)
        backoff = MODULE.ControlPlaneBackoff()
        MODULE.ClientAgent._schedule_renewal_backoff(schedule, [{"forward_id": 5}], 1000.0, backoff, error)
        self.assertEqual({5: 1060.0}, schedule)
        self.assertEqual(0, backoff.failures)


if __name__ == "__main__":
    unittest.main()
