"""W1 control-plane outage survival on the Linux agent.

- The agent asks for a 30 min offline grant on every config pull and Ultra
  lease request, primary and mirrors alike.
- Network / 5xx control-plane failures back off exponentially with full jitter
  (5 s -> 60 s, reset on success); 4xx business errors keep their cadence.
- A runtime that is alive and idle on purpose (awaiting/lapsed policy) is not
  restarted; a still-valid cached lease is re-delivered instead.
"""

from __future__ import annotations

import datetime
import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("client_agent_offline_survival", REPO_ROOT / "client-agent" / "client_agent.py")
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
sys.modules["client_agent_offline_survival"] = module
spec.loader.exec_module(module)

HEADER = "X-Leikwan-Ultra-Offline-Validity"
GENERATION = "a" * 64


def rfc3339(epoch: float) -> str:
    return datetime.datetime.fromtimestamp(epoch, tz=datetime.timezone.utc).isoformat().replace("+00:00", "Z")


class UpperBoundRandom:
    """Deterministic stand-in for random: always the top of the jitter window."""

    def __init__(self) -> None:
        self.calls: list[tuple[float, float]] = []

    def uniform(self, low: float, high: float) -> float:
        self.calls.append((low, high))
        return high


def ultra_forward(forward_id: int = 170, **extra) -> dict:
    forward = {
        "forward_id": forward_id,
        "backend_mode": "ultra",
        "service_status": "active",
        "listen_port": 10001,
        "mode": "single",
        "transport": "tcp+udp",
        "target": "10.0.0.1:443",
        "endpoints": [],
        "ultra_access": {
            "session_ticket": "ticket",
            "policy_generation": GENERATION,
            "transport": "tcp+udp",
        },
    }
    forward.update(extra)
    return forward


class OfflineValidityHeaderTests(unittest.TestCase):
    def test_config_pull_sends_offline_validity_to_primary_and_mirror(self) -> None:
        calls: list[tuple[str, dict]] = []

        def request(method, url, token, payload=None, *, extra_headers=None):
            calls.append((url, dict(extra_headers or {})))
            if url.startswith("https://primary.example"):
                raise TimeoutError("primary down")
            return {"forwards": []}, {}

        config = {"master_url": "https://primary.example", "panel_mirror_urls": ["https://mirror.example"]}
        with mock.patch.object(module, "http_json_with_headers", side_effect=request):
            _payload, _headers, endpoint = module.pull_client_configs(config, "token")

        self.assertEqual("https://mirror.example", endpoint)
        self.assertEqual(2, len(calls))
        for _url, headers in calls:
            self.assertEqual("1800", headers.get(HEADER))

    def test_lease_resolve_sends_offline_validity_to_primary_and_fallback(self) -> None:
        calls: list[tuple[str, dict]] = []
        lease = {"forward_id": 170, "lease_id": "l1", "policy_generation": GENERATION}

        def request(method, url, token, payload=None, *, extra_headers=None):
            calls.append((url, dict(extra_headers or {})))
            if url.startswith("https://primary.example"):
                raise TimeoutError("primary down")
            return {"ok": True, "leases": [lease]}

        with mock.patch.object(module, "http_json", side_effect=request):
            module.resolve_ultra_leases(
                "https://primary.example",
                "token",
                "device",
                [ultra_forward()],
                fallback_endpoints=["https://mirror.example"],
            )
        self.assertEqual(
            ["https://primary.example/api/client/ultra/lease", "https://mirror.example/api/client/ultra/lease"],
            [url for url, _ in calls],
        )
        for _url, headers in calls:
            self.assertEqual({HEADER: "1800", "x-device-id": "device"}, headers)

    def test_http_json_rejects_header_injection(self) -> None:
        with self.assertRaises(ValueError):
            module.http_json("GET", "https://example.invalid/x", "t", extra_headers={"X-A": "1\r\nX-B: 2"})

    def test_agent_accepts_up_to_one_hour_of_validity(self) -> None:
        self.assertEqual(1800, module.ULTRA_OFFLINE_VALIDITY_SECONDS)
        self.assertGreaterEqual(module.ULTRA_MAX_POLICY_VALIDITY_SECONDS, 3600)


class ControlPlaneBackoffTests(unittest.TestCase):
    def test_exponential_full_jitter_caps_and_resets(self) -> None:
        rng = UpperBoundRandom()
        backoff = module.ControlPlaneBackoff(rng=rng)
        self.assertEqual([5.0, 10.0, 20.0, 40.0, 60.0, 60.0], [backoff.next_delay() for _ in range(6)])
        self.assertTrue(all(low == 0.0 for low, _ in rng.calls))
        backoff.reset()
        self.assertEqual(5.0, backoff.next_delay())

    def test_jitter_is_floored(self) -> None:
        class Zero:
            def uniform(self, low, high):
                return 0.0

        self.assertEqual(module.CONTROL_PLANE_BACKOFF_MIN_SECONDS, module.ControlPlaneBackoff(rng=Zero()).next_delay())

    def test_only_network_and_5xx_failures_are_transient(self) -> None:
        transient = [
            TimeoutError("timed out"),
            ConnectionResetError("reset"),
            module.ControlPlaneUnavailableError("a: timed out; b: http 503 x"),
            RuntimeError("http 502 https://v3.leikwanhost.com/api/client/configs/pull: bad gateway"),
            RuntimeError("http 500 https://v3.leikwanhost.com: oops"),
        ]
        business = [
            RuntimeError("http 401 https://v3.leikwanhost.com: denied"),
            RuntimeError("http 404 https://v3.leikwanhost.com: missing"),
            RuntimeError("http 422 https://v3.leikwanhost.com: invalid"),
            ValueError("bug"),
        ]
        for error in transient:
            self.assertTrue(module.is_transient_control_plane_error(error), error)
        for error in business:
            self.assertFalse(module.is_transient_control_plane_error(error), error)

    def make_sync_agent(self):
        agent = object.__new__(module.ClientAgent)
        agent.config = {"poll_interval_seconds": 30, "client_id": 9}
        agent.latest_forwards = []
        agent.save_pull_state = mock.Mock()
        agent._sync_backoff = module.ControlPlaneBackoff(rng=UpperBoundRandom())
        return agent

    def test_sync_loop_backs_off_on_outage_and_resets_on_success(self) -> None:
        agent = self.make_sync_agent()
        agent.sync_once = mock.Mock(side_effect=module.ControlPlaneUnavailableError("all endpoints timed out"))
        delays = [agent.sync_with_backoff(1000.0) for _ in range(5)]
        self.assertEqual([5.0, 10.0, 20.0, 40.0, 60.0], delays)

        agent.sync_once = mock.Mock(return_value=None)
        normal = agent.sync_with_backoff(1000.0)
        self.assertEqual(30.0, normal)
        self.assertEqual(0, agent._sync_backoff.failures)

        agent.sync_once = mock.Mock(side_effect=TimeoutError("down again"))
        self.assertEqual(5.0, agent.sync_with_backoff(1000.0))

    def test_sync_loop_keeps_cadence_for_business_errors(self) -> None:
        agent = self.make_sync_agent()
        agent.sync_once = mock.Mock(side_effect=RuntimeError("http 401 https://v3.leikwanhost.com: denied"))
        self.assertEqual(30.0, agent.sync_with_backoff(1000.0))
        self.assertEqual(0, agent._sync_backoff.failures)

    def make_renew_agent(self, now: float):
        agent = object.__new__(module.ClientAgent)
        agent.config = {"agent_token": "token", "master_url": "https://primary.example"}
        forward = ultra_forward()
        forward["ultra_access"]["renew_after"] = rfc3339(now - 60)
        agent.latest_forwards = [forward]
        agent._renew_backoff = module.ControlPlaneBackoff(rng=UpperBoundRandom())
        return agent

    def test_renewal_backs_off_on_network_failure_and_resets(self) -> None:
        now = 1_800_000_000.0
        agent = self.make_renew_agent(now)
        agent.pull_client_configs = mock.Mock(side_effect=module.ControlPlaneUnavailableError("down"))
        scheduled = []
        with mock.patch.object(module.time, "time", return_value=now):
            for _ in range(5):
                with self.assertRaises(module.ControlPlaneUnavailableError):
                    agent.renew_ultra_leases_once()
                scheduled.append(agent._ultra_renew_schedule[170] - now)
                agent._ultra_renew_schedule[170] = now  # due again on the next tick
        self.assertEqual([5.0, 10.0, 20.0, 40.0, 60.0], scheduled)

        agent.pull_client_configs = mock.Mock(return_value=({"forwards": [], "client": {}}, {}, "https://primary.example"))
        with mock.patch.object(module.time, "time", return_value=now):
            agent.renew_ultra_leases_once()
        # A successful pull without a compatible forward returns before the
        # lease request; the next outage starts from the base delay again
        # once a lease round trip succeeds.
        agent.forward_contract_signature = mock.Mock(return_value="same")
        agent.ultra_selection_mode = mock.Mock(return_value="auto")
        agent.pull_client_configs = mock.Mock(return_value=({"forwards": [ultra_forward()], "client": {}}, {}, "https://primary.example"))
        agent._ultra_renew_schedule[170] = now
        with mock.patch.object(module.time, "time", return_value=now), \
             mock.patch.object(module, "resolve_ultra_leases", return_value=[]):
            agent.renew_ultra_leases_once()
        self.assertEqual(0, agent._renew_backoff.failures)

    def test_renewal_keeps_ten_second_retry_for_business_errors(self) -> None:
        now = 1_800_000_000.0
        agent = self.make_renew_agent(now)
        agent.pull_client_configs = mock.Mock(side_effect=RuntimeError("http 403 https://primary.example: suspended"))
        with mock.patch.object(module.time, "time", return_value=now):
            with self.assertRaises(RuntimeError):
                agent.renew_ultra_leases_once()
        self.assertEqual(now + 10, agent._ultra_renew_schedule[170])
        self.assertEqual(0, agent._renew_backoff.failures)


class IdleAwareHealthTests(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.run_dir = pathlib.Path(self._tmp.name)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def make_agent(self):
        agent = object.__new__(module.ClientAgent)
        agent.ultra_runtime_dir = self.run_dir
        agent.ultra_status_expected_uid = os.getuid() if hasattr(os, "getuid") else 0
        agent.last_ultra_leases = {}
        agent.load_applied = mock.Mock(return_value={"170": {}})
        agent.applied_forward_definition_is_present = mock.Mock(return_value=True)
        agent.systemd_is_active = mock.Mock(return_value=True)
        agent.service_active_age_seconds = mock.Mock(return_value=300.0)
        agent.local_listener_present = mock.Mock(return_value=(False, None, "missing"))
        agent.restart_forward_service = mock.Mock()
        agent.send_ultra_leases = mock.Mock(return_value=True)
        return agent

    def write_status(self, **fields) -> pathlib.Path:
        status = {"forward_id": 170, "state": "degraded", "updated_at": rfc3339(module.time.time())}
        status.update(fields)
        path = self.run_dir / "forward-170.status.json"
        path.write_text(json.dumps(status), encoding="utf-8")
        return path

    def run_checks(self, agent, count: int = 5) -> None:
        with mock.patch.object(module.time, "monotonic", side_effect=[100.0 + 20.0 * index for index in range(count * 4)]):
            for _ in range(count):
                agent.ensure_runtime_health([ultra_forward()])

    def test_lapsed_runtime_is_not_restarted(self) -> None:
        agent = self.make_agent()
        self.write_status(policy_state="lapsed", error_code="policy_lapsed")
        self.run_checks(agent)
        agent.restart_forward_service.assert_not_called()
        agent.send_ultra_leases.assert_not_called()  # nothing valid to deliver

    def test_awaiting_legacy_error_code_is_not_restarted(self) -> None:
        agent = self.make_agent()
        self.write_status(error_code="awaiting_policy")
        self.run_checks(agent)
        agent.restart_forward_service.assert_not_called()

    def test_policy_state_takes_precedence_over_error_code(self) -> None:
        agent = self.make_agent()
        self.write_status(policy_state="active", error_code="policy_lapsed")
        self.run_checks(agent)
        agent.restart_forward_service.assert_called()

    def test_active_runtime_without_listener_is_still_restarted(self) -> None:
        agent = self.make_agent()
        self.write_status(policy_state="active")
        self.run_checks(agent)
        agent.restart_forward_service.assert_called()
        self.assertIn("listener absent", agent.restart_forward_service.call_args.args[2])

    def test_status_older_than_last_lease_delivery_is_ignored(self) -> None:
        agent = self.make_agent()
        self.write_status(policy_state="lapsed", updated_at=rfc3339(module.time.time() - 600))
        status_path = self.run_dir / "forward-170.status.json"
        os.utime(status_path, (module.time.time() - 600, module.time.time() - 600))
        agent._ultra_lease_delivered_at = {170: module.time.time()}
        self.run_checks(agent)
        agent.restart_forward_service.assert_called()

    def test_missing_status_file_keeps_listener_restart(self) -> None:
        agent = self.make_agent()
        self.run_checks(agent)
        agent.restart_forward_service.assert_called()

    def test_dead_process_is_restarted_whatever_the_status_says(self) -> None:
        agent = self.make_agent()
        agent.systemd_is_active.return_value = False
        self.write_status(policy_state="lapsed")
        agent.ensure_runtime_health([ultra_forward()])
        agent.restart_forward_service.assert_called_once_with(170, "ultra-forward-170", "systemd inactive")

    def cache_valid_lease(self, agent) -> None:
        agent.last_ultra_leases = {
            170: [{"lease_id": "l1", "policy_generation": GENERATION, "expires_at": rfc3339(module.time.time() + 1500)}]
        }

    def test_awaiting_after_restart_gets_cached_lease_instead_of_restart(self) -> None:
        agent = self.make_agent()
        agent.service_active_age_seconds.return_value = 3.0  # just restarted by systemd
        self.cache_valid_lease(agent)
        self.write_status(policy_state="awaiting", error_code="awaiting_policy")
        agent.ensure_runtime_health([ultra_forward()])
        agent.send_ultra_leases.assert_called_once()
        self.assertEqual(170, agent.send_ultra_leases.call_args.args[0])
        self.assertEqual("l1", agent.send_ultra_leases.call_args.args[1][0]["lease_id"])
        agent.restart_forward_service.assert_not_called()

    def test_runtime_refusing_a_valid_lease_is_restarted(self) -> None:
        agent = self.make_agent()
        agent.send_ultra_leases.return_value = False
        self.cache_valid_lease(agent)
        self.write_status(policy_state="awaiting")
        self.run_checks(agent)
        agent.restart_forward_service.assert_called()

    def test_expired_cached_lease_is_not_redelivered(self) -> None:
        agent = self.make_agent()
        agent.last_ultra_leases = {
            170: [{"lease_id": "old", "policy_generation": GENERATION, "expires_at": rfc3339(module.time.time() - 5)}]
        }
        self.write_status(policy_state="lapsed")
        self.run_checks(agent)
        agent.send_ultra_leases.assert_not_called()
        agent.restart_forward_service.assert_not_called()

    def test_status_owned_by_another_user_is_not_trusted(self) -> None:
        if not hasattr(os, "getuid"):
            self.skipTest("file ownership is not modelled on this platform")
        agent = self.make_agent()
        agent.ultra_status_expected_uid = os.getuid() + 1
        self.write_status(policy_state="lapsed")
        self.run_checks(agent)
        agent.restart_forward_service.assert_called()

    def test_heartbeat_status_carries_policy_state(self) -> None:
        self.write_status(policy_state="lapsed")
        (self.run_dir / "forward-171.status.json").write_text(
            json.dumps({"forward_id": 171, "state": "degraded", "error_code": "awaiting_policy"}), encoding="utf-8"
        )
        (self.run_dir / "forward-172.status.json").write_text(
            json.dumps({"forward_id": 172, "state": "ready"}), encoding="utf-8"
        )
        expected_uid = os.getuid() if hasattr(os, "getuid") else 0
        statuses = {item["forward_id"]: item for item in module.collect_ultra_status(self.run_dir, expected_uid=expected_uid)}
        self.assertEqual("lapsed", statuses[170]["policy_state"])
        self.assertEqual("awaiting", statuses[171]["policy_state"])
        self.assertNotIn("policy_state", statuses[172])


if __name__ == "__main__":
    unittest.main()
