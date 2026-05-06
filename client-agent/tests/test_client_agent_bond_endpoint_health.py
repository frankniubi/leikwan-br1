import json
import pathlib
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

from test_client_agent_mptcp_health_grace import load_module


client_agent_module = load_module(
    "client_agent_bond_endpoint_health_under_test",
    "client-agent/client_agent.py",
)


class ClientAgentBondEndpointHealthTests(unittest.TestCase):
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

    def test_bond_endpoint_becomes_quarantined_after_three_failures(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            for _ in range(3):
                agent.record_bond_endpoint_probe_result(26, 184, False, None, "timeout", now=100.0)
            entry = agent.load_bond_endpoint_health()["26"]["184"]
            self.assertEqual(entry["role"], "quarantined")
            self.assertGreater(entry["quarantine_until"], 100.0)

    def test_bond_endpoint_reenters_as_probation_before_healthy(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            health = {
                "26": {
                    "184": {
                        "role": "quarantined",
                        "consecutive_failures": 3,
                        "consecutive_successes": 0,
                        "quarantine_until": 50.0,
                        "probation_until": 0.0,
                        "last_error": "timeout",
                    }
                }
            }
            agent.save_bond_endpoint_health(health)
            agent.record_bond_endpoint_probe_result(26, 184, True, 12.0, None, now=60.0)
            entry = agent.load_bond_endpoint_health()["26"]["184"]
            self.assertEqual(entry["role"], "probation")
            self.assertGreater(entry["probation_until"], 60.0)

    def test_record_bond_endpoint_probe_result_tracks_quality_score_from_latency(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            first = agent.record_bond_endpoint_probe_result(26, 184, True, 12.0, None, now=100.0)
            second = agent.record_bond_endpoint_probe_result(26, 185, True, 180.0, None, now=100.0)

            self.assertGreater(first["quality_score"], second["quality_score"])
            self.assertGreaterEqual(first["quality_score"], 0.9)
            self.assertLessEqual(second["quality_score"], 0.5)

    def test_select_bond_runtime_endpoints_omits_quarantined_nodes_but_keeps_best_bad_fallback(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "26": {
                        "184": {
                            "role": "quarantined",
                            "quarantine_until": 200.0,
                            "consecutive_failures": 5,
                            "consecutive_successes": 0,
                        },
                        "185": {
                            "role": "probation",
                            "probation_until": 200.0,
                            "consecutive_failures": 2,
                            "consecutive_successes": 0,
                        },
                        "186": {
                            "role": "healthy",
                            "quarantine_until": 0.0,
                            "probation_until": 0.0,
                            "consecutive_failures": 0,
                            "consecutive_successes": 4,
                        },
                    }
                }
            )
            selected = agent.select_bond_runtime_endpoints(
                {
                    "forward_id": 26,
                    "mode": "bond",
                    "endpoints": [
                        {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                        {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                        {"endpoint_id": 186, "host": "8.163.87.42", "port": 7001},
                    ],
                    "bonding": {"ws_priority": 10},
                },
                now=100.0,
            )
            self.assertEqual([item["endpoint_id"] for item in selected], [186, 185])
            self.assertEqual(selected[0]["health_role"], "healthy")
            self.assertEqual(selected[1]["health_role"], "probation")

    def test_ensure_runtime_health_records_bond_probe_results_and_refreshes_runtime_on_role_change(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "26": {
                        "184": {
                            "role": "healthy",
                            "consecutive_failures": 0,
                            "consecutive_successes": 3,
                            "quarantine_until": 0.0,
                            "probation_until": 0.0,
                            "last_error": "",
                        },
                        "185": {
                            "role": "healthy",
                            "consecutive_failures": 1,
                            "consecutive_successes": 0,
                            "quarantine_until": 0.0,
                            "probation_until": 0.0,
                            "last_error": "timeout",
                        },
                    }
                }
            )
            forward = {
                "forward_id": 26,
                "service_status": "active",
                "listen_port": 11024,
                "mode": "bond",
                "path_prefix": "a43547b6350f72cd",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "ingress_auth_token": "token-2"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "ws_priority": 10,
                },
            }

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "local_forward_probe", return_value=(True, 1.0, None)):
                    with mock.patch.object(agent, "local_listener_present", return_value=(True, 1.0, None)):
                        with mock.patch.object(
                            agent,
                            "remote_plain_tcp_ready_probe",
                            side_effect=[(True, 1.0, None), (False, None, "timeout")],
                        ):
                            with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                                with mock.patch.object(agent, "refresh_bond_runtime_endpoints") as refresh_mock:
                                    with mock.patch.object(
                                        agent,
                                        "collect_live_bond_slave_connections",
                                        return_value=[{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}],
                                    ):
                                        agent.ensure_runtime_health([forward])

            restart_mock.assert_not_called()
            refresh_mock.assert_called_once()
            entry = agent.load_bond_endpoint_health()["26"]["185"]
            self.assertEqual(entry["role"], "probation")

    def test_ensure_runtime_health_cf_bond_quarantines_endpoint_when_one_leg_probe_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "26": {
                        "184": {
                            "role": "healthy",
                            "consecutive_failures": 0,
                            "consecutive_successes": 3,
                            "quarantine_until": 0.0,
                            "probation_until": 0.0,
                            "last_error": "",
                        },
                        "185": {
                            "role": "healthy",
                            "consecutive_failures": 0,
                            "consecutive_successes": 3,
                            "quarantine_until": 0.0,
                            "probation_until": 0.0,
                            "last_error": "",
                        },
                    }
                }
            )
            forward = {
                "forward_id": 26,
                "service_status": "active",
                "listen_port": 11024,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "path_prefix": "a43547b6350f72cd",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "ingress_auth_token": "token-2"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "ws_priority": 10,
                    "session_token": "session-token-1",
                },
            }

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "local_runtime_probe", return_value=(True, 1.0, None)):
                    with mock.patch.object(
                        agent,
                        "remote_plain_tcp_ready_probe",
                        side_effect=[(True, 1.0, None), (True, 1.0, None)],
                    ):
                        with mock.patch.object(
                            agent,
                            "cf_bond_one_leg_connect_probe",
                            side_effect=[(True, 1.0, None), (False, None, "timeout")],
                        ):
                            with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                                with mock.patch.object(agent, "refresh_bond_runtime_endpoints") as refresh_mock:
                                    with mock.patch.object(
                                        agent,
                                        "collect_live_bond_slave_connections",
                                        return_value=[{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}],
                                    ):
                                        agent.ensure_runtime_health([forward])

            restart_mock.assert_not_called()
            refresh_mock.assert_called_once()
            entry = agent.load_bond_endpoint_health()["26"]["185"]
            self.assertEqual(entry["role"], "quarantined")

    def test_service_status_matches_bond_restart_rule_for_connection_reset_warning(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(
                client_agent_module.subprocess,
                "run",
                side_effect=[
                    SimpleNamespace(
                        stdout="[2026-04-17T17:48:09Z WARN  leikwan_bond::session] bond tcp stream 2 local read failed: Connection reset by peer (os error 104)\n",
                        stderr="",
                        returncode=0,
                    )
                ],
            ):
                self.assertTrue(agent.service_status_matches_bond_restart_rule("leikwan-client-26"))

    def test_service_status_matches_bond_restart_rule_for_broken_pipe_warning(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(
                client_agent_module.subprocess,
                "run",
                side_effect=[
                    SimpleNamespace(
                        stdout="WARN bond tcp stream 2 local write failed: Broken pipe (os error 32)\n",
                        stderr="",
                        returncode=0,
                    )
                ],
            ):
                self.assertTrue(agent.service_status_matches_bond_restart_rule("leikwan-client-26"))

    def test_ensure_runtime_health_does_not_restart_bond_forward_on_status_restart_rule_alone(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 26,
                "service_status": "active",
                "listen_port": 11024,
                "mode": "bond",
                "path_prefix": "a43547b6350f72cd",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "ws_priority": 10,
                },
            }

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "service_status_matches_bond_restart_rule", return_value=True):
                    with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                        with mock.patch.object(agent, "local_runtime_probe", return_value=(True, 1.0, None)) as runtime_probe:
                            with mock.patch.object(agent, "remote_plain_tcp_ready_probe", return_value=(True, 1.0, None)):
                                with mock.patch.object(
                                    agent,
                                    "collect_live_bond_slave_connections",
                                    return_value=[{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}],
                                ):
                                    agent.ensure_runtime_health([forward])

            runtime_probe.assert_called_once_with(11024, "tcp+udp")
            restart_mock.assert_not_called()

    def test_local_runtime_probe_uses_udp_listener_for_udp_only_forward(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(
                agent,
                "local_forward_probe",
                side_effect=AssertionError("udp-only probe should not use tcp connect"),
            ):
                with mock.patch.object(
                    agent,
                    "local_listener_present",
                    return_value=(True, 1.0, None),
                ) as listener_mock:
                    ok, latency_ms, error = agent.local_runtime_probe(11024, "udp")

            self.assertTrue(ok)
            self.assertEqual(latency_ms, 1.0)
            self.assertIsNone(error)
            listener_mock.assert_called_once_with(11024, "udp")

    def test_local_runtime_probe_requires_udp_listener_for_tcp_udp_forward(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(agent, "local_forward_probe", return_value=(True, 1.0, None)) as tcp_mock:
                with mock.patch.object(
                    agent,
                    "local_listener_present",
                    return_value=(False, None, "listener_not_found"),
                ) as listener_mock:
                    ok, latency_ms, error = agent.local_runtime_probe(11024, "tcp+udp")

            self.assertFalse(ok)
            self.assertIsNone(latency_ms)
            self.assertEqual(error, "udp listener probe failed: listener_not_found")
            tcp_mock.assert_called_once_with(11024)
            listener_mock.assert_called_once_with(11024, "udp")

    def test_remote_ready_probe_prefers_curl_for_wss_ready_check(self) -> None:
        with mock.patch.object(client_agent_module.shutil, "which", side_effect=lambda name: "/usr/bin/curl" if name == "curl" else None):
            with mock.patch.object(
                client_agent_module.subprocess,
                "run",
                return_value=SimpleNamespace(stdout="200", stderr="", returncode=0),
            ) as run_mock:
                with mock.patch.object(
                    client_agent_module.socket,
                    "create_connection",
                    side_effect=AssertionError("curl path should avoid raw socket TLS probe"),
                ):
                    ok, latency_ms, error = client_agent_module.ClientAgent.remote_ready_probe(
                        "47.116.11.144",
                        7000,
                        "br1.nbwly.com",
                        "07289d1f5ed25399",
                    )

        self.assertTrue(ok)
        self.assertIsNone(error)
        self.assertIsNotNone(latency_ms)
        command = run_mock.call_args.args[0]
        self.assertIn("curl", command[0])
        self.assertIn("https://47.116.11.144:7000/_leikwan/readyz/07289d1f5ed25399", command)
        self.assertIn("Host: br1.nbwly.com", command)

    def test_collect_live_bond_slave_connections_prefers_established_panel_endpoints(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(client_agent_module.shutil, "which", return_value="ss"):
                with mock.patch.object(
                    client_agent_module.subprocess,
                    "run",
                    return_value=SimpleNamespace(
                        stdout=(
                            "ESTAB 0 0 172.21.255.189:43198 8.163.90.132:7001 users:((\"leikwan-ingress\",pid=188230,fd=11))\n"
                            "ESTAB 0 0 172.21.255.189:43199 8.163.90.235:7001 users:((\"leikwan-ingress\",pid=188231,fd=11))\n"
                        ),
                        stderr="",
                        returncode=0,
                    ),
                ):
                    actual = agent.collect_live_bond_slave_connections(
                        {
                            "forward_id": 26,
                            "endpoints": [
                                {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                                {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                            ],
                        }
                    )

        self.assertEqual([item["endpoint_id"] for item in actual], [184, 185])
        self.assertEqual(actual[0]["live_remote"], "8.163.90.132:7001")

    def test_ensure_runtime_health_restarts_on_published_runtime_mismatch(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 26,
                "service_status": "active",
                "listen_port": 11024,
                "mode": "bond",
                "transport": "tcp+udp",
                "path_prefix": "a43547b6350f72cd",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "ws_priority": 10,
                },
            }

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "service_status_matches_bond_restart_rule", return_value=False):
                    with mock.patch.object(agent, "local_runtime_probe", return_value=(True, 1.0, None)):
                        with mock.patch.object(agent, "remote_plain_tcp_ready_probe", return_value=(True, 1.0, None)):
                            with mock.patch.object(agent, "collect_live_bond_slave_connections", return_value=[]):
                                with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                                    agent.ensure_runtime_health([forward])

            restart_mock.assert_called_once_with(
                26,
                "leikwan-client-26",
                "published runtime mismatch: no live slave connections matched panel endpoints",
            )

    def test_sync_once_persists_panel_clock_state_from_http_date(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(
                client_agent_module,
                "http_json_with_headers",
                return_value=(
                    {
                        "forwards": [],
                        "client": {"id": 123},
                    },
                    {"date": "Sat, 18 Apr 2026 14:00:00 GMT"},
                ),
            ):
                with mock.patch.object(client_agent_module, "http_json", return_value={}):
                    with mock.patch.object(agent, "apply_management_state"):
                        with mock.patch.object(agent, "reconcile_global_mptcp_state"):
                            with mock.patch.object(agent, "run_diagnostics_if_requested"):
                                with mock.patch.object(agent, "collect_usage_totals", return_value={"bytes_in": 0, "bytes_out": 0}):
                                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                                        with mock.patch.object(
                                            agent,
                                            "time_sync_state",
                                            return_value={"synchronized": True, "service": "ntp", "status": "ok"},
                                        ):
                                            with mock.patch.object(client_agent_module.time, "time", return_value=1776520800.0):
                                                agent.sync_once()

            state = agent.load_pull_state()
            self.assertIn("clock", state)
            self.assertEqual(state["clock"]["status"], "ok")
            self.assertEqual(state["clock"]["service"], "ntp")

    def test_select_bond_runtime_endpoints_uses_cf_snapshot_to_omit_quarantined_leg(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps(
                    {
                        "forward_id": 26,
                        "legs": [
                            {
                                "endpoint_id": 184,
                                "host": "8.163.90.132",
                                "tcp": {
                                    "state": "quarantined",
                                    "last_reason": "stream_open_timeout",
                                },
                                "recent_events": [
                                    {
                                        "protocol": "tcp",
                                        "from": "probation",
                                        "to": "quarantined",
                                        "reason": "stream_open_timeout",
                                        "observed_at": "2026-04-26T11:00:00Z",
                                    }
                                ],
                            },
                            {
                                "endpoint_id": 185,
                                "host": "8.163.90.235",
                                "tcp": {
                                    "state": "healthy",
                                    "last_reason": None,
                                },
                                "recent_events": [],
                            },
                        ],
                    }
                ),
                encoding="utf-8",
            )

            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [185])
            self.assertEqual(selected[0]["health_role"], "healthy")

    def test_select_bond_runtime_endpoints_keeps_one_cf_snapshot_fallback_when_all_legs_bad(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps(
                    {
                        "forward_id": 26,
                        "legs": [
                            {
                                "endpoint_id": 184,
                                "host": "8.163.90.132",
                                "tcp": {"state": "quarantined", "last_reason": "recv_reset"},
                                "recent_events": [],
                            },
                            {
                                "endpoint_id": 185,
                                "host": "8.163.90.235",
                                "tcp": {"state": "quarantined", "last_reason": "stream_open_timeout"},
                                "recent_events": [],
                            },
                        ],
                    }
                ),
                encoding="utf-8",
            )

            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual(len(selected), 1)
            self.assertEqual(selected[0]["endpoint_id"], 184)
            self.assertEqual(selected[0]["health_role"], "quarantined")

    def test_select_bond_runtime_endpoints_cf_prefers_only_healthy_legs_over_probation(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps(
                    {
                        "forward_id": 26,
                        "legs": [
                            {
                                "endpoint_id": 184,
                                "host": "8.163.90.132",
                                "tcp": {"state": "probation", "last_reason": "stream_open_timeout"},
                                "recent_events": [],
                            },
                            {
                                "endpoint_id": 185,
                                "host": "8.163.90.235",
                                "tcp": {"state": "healthy", "last_reason": None},
                                "recent_events": [],
                            },
                            {
                                "endpoint_id": 186,
                                "host": "8.163.87.42",
                                "tcp": {"state": "quarantined", "last_reason": "recv_reset"},
                                "recent_events": [],
                            },
                        ],
                    }
                ),
                encoding="utf-8",
            )

            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                    {"endpoint_id": 186, "host": "8.163.87.42", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [185])
            self.assertEqual(selected[0]["health_role"], "healthy")

    def test_select_bond_runtime_endpoints_cf_limits_healthy_set_to_best_three_by_priority(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 40,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 254, "host": "8.163.90.132", "port": 7000},
                    {"endpoint_id": 255, "host": "8.163.69.176", "port": 7000},
                    {"endpoint_id": 256, "host": "8.163.87.42", "port": 7000},
                    {"endpoint_id": 257, "host": "8.163.90.235", "port": 7000},
                    {"endpoint_id": 258, "host": "8.163.71.73", "port": 7000},
                ],
                "bonding": {"ws_priority": 10},
            }
            agent.save_bond_endpoint_health(
                {
                    "40": {
                        "254": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 1.0},
                        "255": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.2},
                        "256": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.95},
                        "257": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.1},
                        "258": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.9},
                    }
                }
            )

            selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [254, 256, 258])

    def test_select_bond_runtime_endpoints_non_cf_bond_ignores_cf_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps(
                    {
                        "forward_id": 26,
                        "legs": [
                            {
                                "endpoint_id": 184,
                                "host": "8.163.90.132",
                                "tcp": {"state": "quarantined", "last_reason": "stream_open_timeout"},
                                "recent_events": [],
                            }
                        ],
                    }
                ),
                encoding="utf-8",
            )

            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "current_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [184, 185])
            self.assertTrue(all(item["health_role"] == "healthy" for item in selected))


if __name__ == "__main__":
    unittest.main()
