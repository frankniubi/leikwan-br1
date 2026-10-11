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
    def make_renewal_agent(self):
        agent = object.__new__(MODULE.ClientAgent)
        agent.config = {"agent_token": "synthetic-token"}
        agent.state_dir = Path("/tmp/ultra-test")
        agent.running_kernel_supports_mptcp = lambda: False
        agent.ultra_selection_mode = lambda: "auto"
        agent.systemd_is_active = lambda _name: True
        agent.send_ultra_leases = mock.Mock(return_value=True)
        agent.latest_forwards = [{
            "forward_id": 42, "backend_mode": "ultra", "service_status": "active",
            "target": "203.0.113.10:443", "transport": "tcp+udp",
            "ultra_access": {"renew_after": MODULE.format_rfc3339(1893456000.0)},
        }]
        import threading
        agent._forward_lock = lambda _id: threading.Lock()
        return agent

    def test_independent_renewal_delivers_without_calling_sync_or_restarting(self):
        agent = self.make_renewal_agent()
        forward = dict(agent.latest_forwards[0])
        lease = {"lease_id": "test-lease", "credential": {"user": "u", "pass": "p"}}
        resolved = {**forward, "_ultra_lease": lease, "_ultra_leases": [lease]}
        agent.sync_once = mock.Mock(side_effect=AssertionError("must not wait for config/diagnostics"))
        with mock.patch.object(MODULE.time, "time", return_value=1893456000.0), \
             mock.patch.object(MODULE, "pull_client_configs", return_value=({"forwards": [forward], "client": {"current_device_id": "d"}}, {}, "https://panel.test")), \
             mock.patch.object(MODULE, "resolve_ultra_leases", return_value=[resolved]):
            agent.renew_ultra_leases_once()
        agent.sync_once.assert_not_called()
        agent.send_ultra_leases.assert_called_once()
        self.assertEqual(42, agent.send_ultra_leases.call_args.args[0])
        self.assertGreater(agent._ultra_renew_schedule[42], 1893456000.0)

    def test_independent_renewal_does_not_revive_a_concurrently_disabled_forward(self):
        agent = self.make_renewal_agent()
        forward = dict(agent.latest_forwards[0])
        lease = {"lease_id": "test-lease", "credential": {"user": "u", "pass": "p"}}
        def resolve(*_args, **_kwargs):
            agent.latest_forwards = [{**forward, "service_status": "inactive"}]
            return [{**forward, "_ultra_lease": lease, "_ultra_leases": [lease]}]
        with mock.patch.object(MODULE.time, "time", return_value=1893456000.0), \
             mock.patch.object(MODULE, "pull_client_configs", return_value=({"forwards": [forward]}, {}, "https://panel.test")), \
             mock.patch.object(MODULE, "resolve_ultra_leases", side_effect=resolve):
            agent.renew_ultra_leases_once()
        agent.send_ultra_leases.assert_not_called()

    def test_independent_renewal_skips_static_target_changes(self):
        agent = self.make_renewal_agent()
        changed = {**agent.latest_forwards[0], "target": "203.0.113.11:443"}
        with mock.patch.object(MODULE.time, "time", return_value=1893456000.0), \
             mock.patch.object(MODULE, "pull_client_configs", return_value=({"forwards": [changed]}, {}, "https://panel.test")), \
             mock.patch.object(MODULE, "resolve_ultra_leases") as resolve:
            agent.renew_ultra_leases_once()
        resolve.assert_not_called()
        agent.send_ultra_leases.assert_not_called()

    def test_batch_delivery_has_total_deadline_not_three_seventy_second_waits(self):
        agent = object.__new__(MODULE.ClientAgent)
        clock = [100.0]
        waits = []
        class DelayedSocket:
            def __enter__(self): return self
            def __exit__(self, *_args): pass
            def settimeout(self, value): self.timeout = value
            def connect(self, _path): pass
            def sendall(self, _data): pass
            def recv(self, _count):
                waits.append(self.timeout)
                clock[0] += self.timeout
                raise TimeoutError("adapter timeout")
        lease = {
            "policy_generation": "a" * 64, "lease_id": "test",
            "expires_at": MODULE.format_rfc3339(190),
            "endpoint": {"ip": "192.0.2.1", "port": 5438},
            "credential": {"user": "synthetic", "password": "synthetic"},
        }
        with mock.patch.object(MODULE.time, "monotonic", side_effect=lambda: clock[0]), \
             mock.patch.object(MODULE.time, "time", side_effect=lambda: clock[0]), \
             mock.patch.object(MODULE.time, "sleep", side_effect=lambda n: clock.__setitem__(0, clock[0] + n)), \
             mock.patch.object(MODULE.pathlib.Path, "exists", return_value=True), \
             mock.patch.object(MODULE.socket, "AF_UNIX", 1, create=True), \
             mock.patch.object(MODULE.socket, "socket", side_effect=lambda *_: DelayedSocket()):
            self.assertFalse(agent.send_ultra_leases(42, [lease, lease]))
        self.assertLessEqual(clock[0] - 100, 70.01)
        self.assertEqual(1, len(waits), "a full adapter deadline must not be repeated three times")

    def test_single_lease_waits_for_verified_ack(self):
        agent = object.__new__(MODULE.ClientAgent)
        agent.remember_ultra_leases = mock.Mock()
        clock = [100.0]
        body = b'{"ok":true,"forward_id":42,"policy_generation":"' + b"a" * 64 + b'"}'

        class DelayedAckSocket:
            def __enter__(self): return self
            def __exit__(self, *_args): pass
            def settimeout(self, value): self.timeout = value
            def connect(self, _path): pass
            def sendall(self, _data): self.header = True
            def recv(self, _count):
                if self.header:
                    if self.timeout < 3.2:
                        clock[0] += self.timeout
                        raise TimeoutError("verification still running")
                    clock[0] += 3.2
                    self.header = False
                    return MODULE.struct.pack(">I", len(body))
                return body

        lease = {
            "policy_generation": "a" * 64, "lease_id": "single",
            "expires_at": MODULE.format_rfc3339(190),
            "endpoint": {"ip": "192.0.2.1", "port": 5438},
            "credential": {"user": "synthetic", "password": "synthetic"},
        }
        with mock.patch.object(MODULE.time, "monotonic", side_effect=lambda: clock[0]), \
             mock.patch.object(MODULE.time, "time", side_effect=lambda: clock[0]), \
             mock.patch.object(MODULE.time, "sleep", side_effect=lambda n: clock.__setitem__(0, clock[0] + n)), \
             mock.patch.object(MODULE.pathlib.Path, "exists", return_value=True), \
             mock.patch.object(MODULE.socket, "AF_UNIX", 1, create=True), \
             mock.patch.object(MODULE.socket, "socket", side_effect=lambda *_: DelayedAckSocket()):
            self.assertTrue(agent.send_ultra_lease(42, lease))
        agent.remember_ultra_leases.assert_called_once_with(42, [lease])

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
                {"path": "/run/ultra/forward-42.lease", "content": "token-a", "mode": 0o600, "restart_on_change": False},
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
            "ultra_access": {"session_ticket": "token-a", "policy_generation": "a" * 64},
        }
        second = {
            **first,
            "ultra_access": {"session_ticket": "token-b", "policy_generation": "b" * 64},
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
