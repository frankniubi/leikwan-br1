import json
import os
import pathlib
import platform
import tempfile
import time
import unittest
from unittest import mock

try:
    from test_client_agent_mptcp_health_grace import load_module
except ModuleNotFoundError:  # dotted unittest invocation from repo root
    import importlib
    load_module = importlib.import_module("client-agent.tests.test_client_agent_mptcp_health_grace").load_module


client_agent_module = load_module(
    "client_agent_ultra_rollback_recovery_under_test",
    "client-agent/client_agent.py",
)

platform.release()

GEN_A = "a" * 64
GEN_C = "c" * 64


def lease(generation: str = GEN_A, expires_at: float | None = None, lease_id: str = "lease-42") -> dict:
    payload = {
        "protocol": "leikwan-ultra-policy-v2",
        "policy_generation": generation,
        "lease_id": lease_id,
        "endpoint": {"ip": "203.0.113.20", "port": 5438},
        "credential": {"user": "user", "password": "password"},
    }
    if expires_at is not None:
        payload["expires_at"] = client_agent_module.format_rfc3339(expires_at)
    return payload


class UltraRollbackRecoveryTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "client_id": 123,
                    "agent_token": "token",
                    "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                }
            ),
            encoding="utf-8",
        )
        return client_agent_module.ClientAgent(config_path)

    def ultra_forward(self, forward_id: int = 42) -> dict:
        return {
            "forward_id": forward_id,
            "backend_mode": "ultra",
            "service_status": "active",
            "mode": "single",
            "transport": "tcp+udp",
            "listen_port": 12000 + forward_id,
            "target": "198.51.100.10:443",
            "ultra_access": {
                "protocol": "leikwan-ultra-policy-v2",
                "session_ticket": f"ticket-{forward_id}",
                "policy_generation": GEN_A,
                "target_digest": "b" * 64,
                "transport": "tcp+udp",
            },
        }

    def write_applied_ultra(self, agent, state_dir: pathlib.Path, service_path, config_path) -> None:
        agent.applied_path.write_text(
            json.dumps(
                {
                    "42": {
                        "units": [{"service_name": "ultra-forward-42", "managed_paths": [str(service_path), str(config_path)]}],
                        "managed_paths": [str(service_path), str(config_path)],
                        "published_mode": "ultra",
                        "runtime_mode": "single",
                        "contract_signature": "old-contract",
                        "rendered_signature": "old-render",
                    }
                }
            ),
            encoding="utf-8",
        )

    # --- rollback keeps the content cache honest and restarts once ------------

    @unittest.skipUnless(hasattr(os, "fchmod"), "rollback restores files with os.fchmod")
    def test_retry_after_rolled_back_target_change_restarts_the_service(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            state_dir = pathlib.Path(tmp_dir) / "state"
            service_path = state_dir / "ultra-forward-42.service"
            config_path = state_dir / "forward-42.json"
            service_path.write_text("unit\n", encoding="utf-8")
            old_config = json.dumps({"policy_generation": GEN_A, "target_digest": "1" * 64}, sort_keys=True) + "\n"
            new_config = json.dumps({"policy_generation": GEN_C, "target_digest": "2" * 64}, sort_keys=True) + "\n"
            config_path.write_text(old_config, encoding="utf-8")
            config_path.chmod(0o600)
            self.write_applied_ultra(agent, state_dir, service_path, config_path)
            agent.last_ultra_leases[42] = [lease(lease_id="old-lease")]
            forward = self.ultra_forward()
            forward["_ultra_lease"] = lease(GEN_C, lease_id="new-lease")
            rendered = {
                "service_name": "ultra-forward-42",
                "service_path": str(service_path),
                "service_unit": "unit\n",
                "start_script_path": "",
                "start_script": "",
                "files": [{"path": str(config_path), "content": new_config, "mode": 0o600}],
            }
            deliveries = {"fail_new": True}

            def deliver(forward_id, leases):
                if leases[0].get("lease_id") == "old-lease":
                    return True
                return not deliveries["fail_new"]

            with mock.patch.dict(
                "sys.modules",
                {"install_forward_runtime": mock.Mock(render_forward_runtime=mock.Mock(return_value=rendered))},
            ):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(agent, "mptcp_forward_runtime_interfaces", return_value=[]):
                        with mock.patch.object(agent, "delete_nft_rules"):
                            with mock.patch.object(agent, "add_nft_rules"):
                                with mock.patch.object(agent, "send_ultra_leases", side_effect=deliver):
                                    with mock.patch.object(client_agent_module.subprocess, "run") as run_mock:
                                        with self.assertRaisesRegex(RuntimeError, "ultra lease delivery failed"):
                                            agent.apply_forward(forward, contract_signature="new-contract")
                                        self.assertEqual(old_config, config_path.read_text(encoding="utf-8"))
                                        self.assertEqual(
                                            config_path.read_text(encoding="utf-8"),
                                            client_agent_module.WRITTEN_RUNTIME_CONTENT[str(config_path)],
                                            "the content cache must describe what is on disk after a rollback",
                                        )

                                        run_mock.reset_mock()
                                        deliveries["fail_new"] = False
                                        agent.apply_forward(forward, contract_signature="new-contract")

            restart_calls = [
                call.args[0]
                for call in run_mock.call_args_list
                if call.args and "systemctl restart ultra-forward-42.service" in str(call.args[0])
            ]
            self.assertEqual(1, len(restart_calls), "the retry must restart the service onto the restored-then-reapplied files")
            self.assertEqual(new_config, config_path.read_text(encoding="utf-8"))
            self.assertEqual("new-contract", agent.load_applied()["42"]["contract_signature"])

    @unittest.skipUnless(hasattr(os, "fchmod"), "rollback restores files with os.fchmod")
    def test_rollback_restart_is_requested_once_not_forever(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            state_dir = pathlib.Path(tmp_dir) / "state"
            service_path = state_dir / "ultra-forward-42.service"
            config_path = state_dir / "forward-42.json"
            service_path.write_text("unit\n", encoding="utf-8")
            config_path.write_text("{}\n", encoding="utf-8")
            config_path.chmod(0o600)
            snapshot = agent._capture_runtime_files({str(service_path), str(config_path)})
            with mock.patch.object(client_agent_module.subprocess, "run"):
                with mock.patch.object(agent, "send_ultra_leases", return_value=True):
                    agent._rollback_ultra_runtime_apply(
                        42, {}, {}, True,
                        {str(service_path), str(config_path)}, {str(service_path), str(config_path)},
                        {"ultra-forward-42"}, {"ultra-forward-42"}, snapshot, [lease()],
                    )
            self.assertIn(42, agent._ultra_restart_once)

    # --- failed lease delivery is reported as degraded ------------------------

    def test_mark_ultra_apply_failures_degrades_only_failed_forwards(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent._ultra_apply_failures[42] = "lease_delivery_failed"
            statuses = [
                {"forward_id": 42, "state": "ready", "error_code": ""},
                {"forward_id": 43, "state": "ready", "error_code": ""},
                {"forward_id": 44, "state": "error", "error_code": "boom"},
            ]
            marked = agent.mark_ultra_apply_failures(statuses, {42, 43})
            by_id = {entry["forward_id"]: entry for entry in marked}
            self.assertEqual("degraded", by_id[42]["state"])
            self.assertEqual("lease_delivery_failed", by_id[42]["error_code"])
            self.assertEqual("ready", by_id[43]["state"])
            self.assertEqual("error", by_id[44]["state"])
            self.assertEqual("ready", statuses[0]["state"], "the runtime's own status must not be mutated")

    def test_sync_once_reports_degraded_when_lease_delivery_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = self.ultra_forward()
            enriched = dict(forward)
            enriched["_ultra_lease"] = lease(GEN_A)
            heartbeats: list[dict] = []

            def control_plane_request(method, path, token, payload=None, *args, **kwargs):
                if path == "/api/client/heartbeat":
                    heartbeats.append(payload)
                return {}

            runtime_status = [{"forward_id": 42, "state": "ready", "error_code": ""}]
            with mock.patch.object(client_agent_module, "http_json_with_headers", return_value=({"forwards": [forward], "client": {"id": 123}}, {})):
                with mock.patch.object(client_agent_module, "resolve_ultra_leases", return_value=[enriched]):
                    with mock.patch.object(client_agent_module, "collect_ultra_status", return_value=runtime_status):
                        with mock.patch.object(agent, "control_plane_request", side_effect=control_plane_request):
                            with mock.patch.object(agent, "apply_management_state"):
                                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                                    with mock.patch.object(
                                        agent, "apply_forward",
                                        side_effect=client_agent_module.UltraLeaseDeliveryError("ultra lease delivery failed"),
                                    ), mock.patch.object(agent, "should_force_runtime_restart", return_value=False):
                                        with mock.patch.object(agent, "reconcile_global_mptcp_state"):
                                            with mock.patch.object(agent, "run_diagnostics_if_requested"):
                                                with mock.patch.object(agent, "collect_usage_totals", return_value={"bytes_in": 0, "bytes_out": 0}):
                                                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                                                        with mock.patch.object(agent, "time_sync_state", return_value={"status": "ok"}):
                                                            agent.sync_once()

            self.assertEqual(1, len(heartbeats))
            reported = {entry["forward_id"]: entry for entry in heartbeats[0]["ultra_status"]}
            self.assertEqual("degraded", reported[42]["state"])

    # --- bounded network grace ------------------------------------------------

    def test_grace_is_requested_inside_the_lead_time_and_bounded_by_the_lease_expiry(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            now = 1_800_000_000.0
            expires = now + 30.0
            agent.last_ultra_leases[42] = [lease(GEN_A, expires_at=expires)]
            with mock.patch.object(agent, "send_ultra_control_command", return_value=True) as send:
                self.assertTrue(agent.extend_ultra_grace(42, now=now))
            command = send.call_args.args[1]
            self.assertEqual("extend_grace", command["op"])
            self.assertEqual(GEN_A, command["policy_generation"])
            granted = client_agent_module.parse_rfc3339(command["grace_until"])
            self.assertEqual(expires + client_agent_module.ULTRA_NETWORK_GRACE_SECONDS, granted)
            self.assertLessEqual(granted - expires, client_agent_module.ULTRA_NETWORK_GRACE_SECONDS)

    def test_grace_is_not_requested_while_the_lease_is_still_comfortably_valid(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            now = 1_800_000_000.0
            agent.last_ultra_leases[42] = [lease(GEN_A, expires_at=now + 600.0)]
            with mock.patch.object(agent, "send_ultra_control_command", return_value=True) as send:
                self.assertFalse(agent.extend_ultra_grace(42, now=now))
            send.assert_not_called()

    def test_grace_never_outlives_the_bound_past_the_lease_expiry(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            now = 1_800_000_000.0
            expires = now - client_agent_module.ULTRA_NETWORK_GRACE_SECONDS - 1.0
            agent.last_ultra_leases[42] = [lease(GEN_A, expires_at=expires)]
            with mock.patch.object(agent, "send_ultra_control_command", return_value=True) as send:
                self.assertFalse(agent.extend_ultra_grace(42, now=now))
            send.assert_not_called()

    def test_grace_is_not_asked_twice_for_the_same_generation(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            now = 1_800_000_000.0
            agent.last_ultra_leases[42] = [lease(GEN_A, expires_at=now + 20.0)]
            with mock.patch.object(agent, "send_ultra_control_command", return_value=True) as send:
                self.assertTrue(agent.extend_ultra_grace(42, now=now))
                self.assertFalse(agent.extend_ultra_grace(42, now=now + 5.0))
            self.assertEqual(1, send.call_count)

    def test_grace_needs_a_delivered_lease_and_a_runtime_that_accepts_it(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            now = 1_800_000_000.0
            with mock.patch.object(agent, "send_ultra_control_command", return_value=True) as send:
                self.assertFalse(agent.extend_ultra_grace(42, now=now), "nothing was ever delivered")
            send.assert_not_called()
            agent.last_ultra_leases[42] = [lease(GEN_A, expires_at=now + 20.0)]
            with mock.patch.object(agent, "send_ultra_control_command", return_value=False):
                self.assertFalse(agent.extend_ultra_grace(42, now=now), "the runtime refused")
            self.assertNotIn(42, agent._ultra_grace_until, "a refused extension must not be remembered as granted")

    def test_sync_once_asks_for_grace_when_the_panel_withholds_the_lease_near_expiry(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            state_dir = pathlib.Path(tmp_dir) / "state"
            service_path = state_dir / "ultra-forward-42.service"
            config_path = state_dir / "ultra-forward-42.json"
            service_path.write_text("unit\n", encoding="utf-8")
            config_path.write_text("{}\n", encoding="utf-8")
            self.write_applied_ultra(agent, state_dir, service_path, config_path)
            agent.last_ultra_leases[42] = [lease(GEN_A, expires_at=time.time() + 20.0)]
            forward = self.ultra_forward()

            def http_json_side_effect(method, url, token, payload=None, *, extra_headers=None):
                if url.endswith("/api/client/ultra/lease"):
                    raise RuntimeError("http 503 panel unavailable")
                return {"ok": True}

            with mock.patch.object(client_agent_module, "http_json_with_headers", return_value=({"forwards": [forward], "client": {"id": 123}}, {})):
                with mock.patch.object(client_agent_module, "http_json", side_effect=http_json_side_effect):
                    with mock.patch.object(agent, "apply_management_state"):
                        with mock.patch.object(agent, "systemd_is_active", return_value=True):
                            with mock.patch.object(agent, "stop_forward") as stop_mock:
                                with mock.patch.object(agent, "send_ultra_control_command", return_value=True) as send:
                                    with mock.patch.object(agent, "reconcile_global_mptcp_state"):
                                        with mock.patch.object(agent, "run_diagnostics_if_requested"):
                                            with mock.patch.object(agent, "collect_usage_totals", return_value={"bytes_in": 0, "bytes_out": 0}):
                                                with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                                                    with mock.patch.object(agent, "time_sync_state", return_value={"status": "ok"}):
                                                        agent.sync_once()

            stop_mock.assert_not_called()
            self.assertEqual(1, send.call_count)
            self.assertEqual("extend_grace", send.call_args.args[1]["op"])


if __name__ == "__main__":
    unittest.main()
