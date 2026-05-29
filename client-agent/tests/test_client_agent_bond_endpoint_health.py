import json
import pathlib
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

try:
    from test_client_agent_mptcp_health_grace import load_module
except ModuleNotFoundError:  # dotted unittest invocation from repo root
    import importlib
    load_module = importlib.import_module("client-agent.tests.test_client_agent_mptcp_health_grace").load_module


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


    def test_sync_once_skips_noop_apply_when_forward_contract_unchanged(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            state_dir = pathlib.Path(tmp_dir) / "state"
            managed_path = state_dir / "forward-47.conf"
            managed_path.write_text("stable\n", encoding="utf-8")
            forward = {
                "forward_id": 47,
                "service_status": "active",
                "listen_port": 11035,
                "mode": "single",
                "transport": "tcp",
                "target": "127.0.0.1:5201",
                "endpoints": [{"endpoint_id": 317, "host": "47.116.104.15", "port": 7000}],
            }
            signature = agent.forward_contract_signature(forward)
            agent.applied_path.write_text(
                json.dumps(
                    {
                        "47": {
                            "units": [{"service_name": "leikwan-client-47", "managed_paths": [str(managed_path)]}],
                            "managed_paths": [str(managed_path)],
                            "published_mode": "single",
                            "runtime_mode": "single",
                            "contract_signature": signature,
                            "rendered_signature": "existing",
                        }
                    }
                ),
                encoding="utf-8",
            )

            with mock.patch.object(client_agent_module, "http_json_with_headers", return_value=({"forwards": [forward], "client": {"id": 123}}, {})):
                with mock.patch.object(client_agent_module, "http_json", return_value={}):
                    with mock.patch.object(agent, "apply_management_state"):
                        with mock.patch.object(agent, "systemd_is_active", return_value=True):
                            with mock.patch.object(agent, "apply_forward") as apply_mock:
                                with mock.patch.object(agent, "reconcile_global_mptcp_state"):
                                    with mock.patch.object(agent, "run_diagnostics_if_requested"):
                                        with mock.patch.object(agent, "collect_usage_totals", return_value={"bytes_in": 0, "bytes_out": 0}):
                                            with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                                                with mock.patch.object(agent, "time_sync_state", return_value={"status": "ok"}):
                                                    agent.sync_once()

            apply_mock.assert_not_called()
            state = agent.load_pull_state()
            self.assertEqual([47], state["noop_forward_ids"])
            self.assertEqual([47], state["applied_forward_ids"])

    def test_apply_forward_records_new_contract_when_rendered_runtime_is_unchanged(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            state_dir = pathlib.Path(tmp_dir) / "state"
            service_path = state_dir / "leikwan-client-47.service"
            script_path = state_dir / "start-47.sh"
            service_path.write_text("unit\n", encoding="utf-8")
            script_path.write_text("#!/bin/sh\n", encoding="utf-8")
            forward = {
                "forward_id": 47,
                "service_status": "active",
                "listen_port": 11035,
                "mode": "single",
                "transport": "tcp",
                "target": "127.0.0.1:5201",
                "sni": "example.test",
                "path_prefix": "/route",
                "endpoints": [{"endpoint_id": 317, "host": "47.116.104.15", "port": 7000}],
                "last_seen_at": 100,
            }
            rendered = {
                "service_name": "leikwan-client-47",
                "service_path": str(service_path),
                "service_unit": "unit\n",
                "start_script_path": str(script_path),
                "start_script": "#!/bin/sh\n",
            }
            old_contract = "old-contract"
            new_contract = agent.forward_contract_signature(forward)
            rendered_signature = client_agent_module.ClientAgent.rendered_runtime_signature(rendered, "single", "tcp", 11035)
            agent.applied_path.write_text(
                json.dumps(
                    {
                        "47": {
                            "units": [{"service_name": "leikwan-client-47", "managed_paths": [str(service_path), str(script_path)]}],
                            "managed_paths": [str(service_path), str(script_path)],
                            "published_mode": "single",
                            "runtime_mode": "single",
                            "contract_signature": old_contract,
                            "rendered_signature": rendered_signature,
                        }
                    }
                ),
                encoding="utf-8",
            )

            with mock.patch.dict("sys.modules", {"install_forward_runtime": mock.Mock(render_forward_runtime=mock.Mock(return_value=rendered))}):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(agent, "delete_nft_rules") as delete_nft:
                        with mock.patch.object(agent, "add_nft_rules") as add_nft:
                            with mock.patch.object(agent, "mptcp_forward_runtime_interfaces", return_value=[]):
                                with mock.patch.object(client_agent_module.subprocess, "run") as run_mock:
                                    agent.apply_forward(forward, contract_signature=new_contract)

            delete_nft.assert_not_called()
            add_nft.assert_not_called()
            run_mock.assert_not_called()
            applied = agent.load_applied()["47"]
            self.assertEqual(new_contract, applied["contract_signature"])
            self.assertEqual(rendered_signature, applied["rendered_signature"])


    def test_forward_apply_noop_requires_mptcp_underlay_interfaces(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            state_dir = pathlib.Path(tmp_dir) / "state"
            service_path = state_dir / "leikwan-client-47.service"
            config_path = state_dir / "mptcp-forward-47.json"
            service_path.write_text("unit\n", encoding="utf-8")
            config_path.write_text(
                json.dumps(
                    {
                        "underlays": [
                            {"interface_name": "lw-cfwg-47", "legs": [{"endpoint_id": 0, "outer_transport": "wstunnel_udp"}]},
                            {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 317, "outer_transport": "udp"}]},
                        ]
                    }
                ),
                encoding="utf-8",
            )
            agent.applied_path.write_text(
                json.dumps(
                    {
                        "47": {
                            "units": [{"service_name": "leikwan-client-47", "managed_paths": [str(service_path), str(config_path)]}],
                            "managed_paths": [str(service_path), str(config_path)],
                            "published_mode": "mptcp",
                            "runtime_mode": "mptcp",
                            "contract_signature": "same-contract",
                            "rendered_signature": "same-render",
                        }
                    }
                ),
                encoding="utf-8",
            )

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "private_wg_interface_present", side_effect=lambda name: name == "lw-cfwg-47"):
                    self.assertFalse(agent.forward_apply_is_noop(47, "same-contract"))

                with mock.patch.object(agent, "private_wg_interface_present", return_value=True):
                    self.assertTrue(agent.forward_apply_is_noop(47, "same-contract"))

    def test_forward_apply_noop_ignores_missing_standby_mptcp_underlay_interfaces(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            state_dir = pathlib.Path(tmp_dir) / "state"
            service_path = state_dir / "leikwan-client-47.service"
            config_path = state_dir / "mptcp-forward-47.json"
            service_path.write_text("unit\n", encoding="utf-8")
            config_path.write_text(
                json.dumps(
                    {
                        "underlays": [
                            {
                                "interface_name": "lw-cfwg-47",
                                "legs": [{"endpoint_id": 0, "outer_transport": "wstunnel_udp", "leg_role": "primary"}],
                            },
                            {
                                "interface_name": "lw-mptcp-47-0",
                                "legs": [{"endpoint_id": 317, "outer_transport": "udp", "leg_role": "standby", "backup_only": True}],
                            },
                        ]
                    }
                ),
                encoding="utf-8",
            )
            agent.applied_path.write_text(
                json.dumps(
                    {
                        "47": {
                            "units": [{"service_name": "leikwan-client-47", "managed_paths": [str(service_path), str(config_path)]}],
                            "managed_paths": [str(service_path), str(config_path)],
                            "published_mode": "mptcp",
                            "runtime_mode": "mptcp",
                            "contract_signature": "same-contract",
                            "rendered_signature": "same-render",
                        }
                    }
                ),
                encoding="utf-8",
            )

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "private_wg_interface_present", side_effect=lambda name: name == "lw-cfwg-47"):
                    self.assertTrue(agent.forward_apply_is_noop(47, "same-contract"))


    def test_apply_forward_skips_unchanged_mptcp_when_only_standby_underlay_missing(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            tmp = pathlib.Path(tmp_dir)
            service_path = tmp / "leikwan-client-47.service"
            script_path = tmp / "start-47.sh"
            config_path = tmp / "mptcp-forward-47.json"
            service_path.write_text("unit\n", encoding="utf-8")
            script_path.write_text("#!/bin/sh\n", encoding="utf-8")
            config_payload = {
                "underlays": [
                    {"interface_name": "lw-cfwg-47", "legs": [{"endpoint_id": 0, "outer_transport": "wstunnel_udp", "leg_role": "primary"}]},
                    {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 317, "outer_transport": "udp", "leg_role": "standby", "backup_only": True}]},
                ]
            }
            config_text = json.dumps(config_payload, ensure_ascii=False, indent=2) + "\n"
            config_path.write_text(config_text, encoding="utf-8")
            rendered = {
                "service_name": "leikwan-client-47",
                "service_path": str(service_path),
                "service_unit": "unit\n",
                "start_script_path": str(script_path),
                "start_script": "#!/bin/sh\n",
                "files": [{"path": str(config_path), "content": config_text, "mode": 0o600}],
            }
            forward = {
                "forward_id": 47,
                "service_status": "active",
                "listen_port": 11035,
                "mode": "mptcp",
                "transport": "tcp",
                "target": "127.0.0.1:5201",
                "endpoints": [{"endpoint_id": 317, "host": "47.116.104.15", "port": 7000}],
                "mptcp": {"subflow_limit": 2},
            }
            rendered_signature = client_agent_module.ClientAgent.rendered_runtime_signature(rendered, "mptcp", "tcp", 11035)
            contract_signature = agent.forward_contract_signature(forward)
            agent.applied_path.write_text(
                json.dumps(
                    {
                        "47": {
                            "units": [{"service_name": "leikwan-client-47", "managed_paths": [str(service_path), str(script_path), str(config_path)]}],
                            "managed_paths": [str(service_path), str(script_path), str(config_path)],
                            "published_mode": "mptcp",
                            "runtime_mode": "mptcp",
                            "contract_signature": contract_signature,
                            "rendered_signature": rendered_signature,
                        }
                    }
                ),
                encoding="utf-8",
            )

            with mock.patch.dict("sys.modules", {"install_forward_runtime": mock.Mock(render_forward_runtime=mock.Mock(return_value=rendered))}):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(agent, "private_wg_interface_present", side_effect=lambda name: name == "lw-cfwg-47"):
                        with mock.patch.object(agent, "cleanup_mptcp_forward_runtime") as cleanup_mock:
                            with mock.patch.object(client_agent_module.subprocess, "run") as run_mock:
                                agent.apply_forward(forward, contract_signature=contract_signature)

            cleanup_mock.assert_not_called()
            run_mock.assert_not_called()



    def test_mptcp_runtime_selection_ignores_non_active_panel_endpoints_without_health(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            selected = agent.select_mptcp_runtime_endpoints(
                {
                    "forward_id": 47,
                    "mode": "mptcp",
                    "endpoints": [
                        {"endpoint_id": 653, "host": "203.0.113.19", "port": 7000, "health_status": "pending"},
                        {"endpoint_id": 626, "host": "203.0.113.2", "port": 7000, "health_status": "active"},
                    ],
                    "mptcp": {"policy_mode": "latency_first", "active_leg_limit": 2},
                },
                now=100.0,
            )

        self.assertEqual([626], [item["endpoint_id"] for item in selected])

    def test_mptcp_runtime_selection_uses_underlay_leg_health_status(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            selected = agent.select_mptcp_runtime_endpoints(
                {
                    "forward_id": 47,
                    "mode": "mptcp",
                    "endpoints": [
                        {"endpoint_id": 640, "host": "203.0.113.13", "port": 7000},
                        {"endpoint_id": 643, "host": "203.0.113.14", "port": 7000},
                    ],
                    "mptcp": {
                        "policy_mode": "latency_first",
                        "active_leg_limit": 2,
                        "underlays": [
                            {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 640, "health_status": "active"}]},
                            {"interface_name": "lw-mptcp-47-1", "legs": [{"endpoint_id": 643, "health_status": "pending"}]},
                        ],
                    },
                },
                now=100.0,
            )

        self.assertEqual([640], [item["endpoint_id"] for item in selected])

    def test_mptcp_runtime_selection_panel_pending_overrides_stale_local_health(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "47": {
                        "640": {"role": "healthy", "quality_score": 1.0},
                        "643": {"role": "healthy", "quality_score": 1.0},
                    }
                }
            )
            selected = agent.select_mptcp_runtime_endpoints(
                {
                    "forward_id": 47,
                    "mode": "mptcp",
                    "endpoints": [
                        {"endpoint_id": 640, "host": "203.0.113.13", "port": 7000},
                        {"endpoint_id": 643, "host": "203.0.113.14", "port": 7000},
                    ],
                    "mptcp": {
                        "policy_mode": "latency_first",
                        "active_leg_limit": 2,
                        "underlays": [
                            {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 640, "health_status": "active"}]},
                            {"interface_name": "lw-mptcp-47-1", "legs": [{"endpoint_id": 643, "health_status": "pending"}]},
                        ],
                    },
                },
                now=100.0,
            )

        self.assertEqual([640], [item["endpoint_id"] for item in selected])

    def test_refresh_mptcp_runtime_endpoints_forces_runtime_restart(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.latest_forwards = [{"forward_id": 47, "mode": "mptcp"}]
            forward = {"forward_id": 47, "mode": "mptcp", "listen_port": 11035}
            with mock.patch.object(agent, "apply_forward") as apply_mock:
                agent.refresh_mptcp_runtime_endpoints(47, forward, "leikwan-client-47")

            apply_mock.assert_called_once()
            self.assertEqual(forward, apply_mock.call_args.args[0])
            self.assertIn("contract_signature", apply_mock.call_args.kwargs)
            self.assertFalse(apply_mock.call_args.kwargs["force_runtime_restart"])

    def test_mptcp_apply_persists_health_policy_signature_for_noop_sync(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 62,
                "service_status": "active",
                "listen_port": 32502,
                "mode": "mptcp",
                "transport": "tcp+udp",
                "target": "192.0.2.10:32501",
                "sni": "example.test",
                "path_prefix": "route",
                "endpoints": [
                    {"endpoint_id": 619, "host": "203.0.113.19", "port": 7000, "health_status": "active"},
                    {"endpoint_id": 620, "host": "203.0.113.20", "port": 7000, "health_status": "active"},
                    {"endpoint_id": 666, "host": "203.0.113.66", "port": 7000, "health_status": "pending"},
                ],
                "mptcp": {
                    "sidecar_port": 14969,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "subflow_limit": 2,
                    "mptcp_endpoints": [
                        {"endpoint_id": 619, "address": "10.203.15.201", "dev": "lw-mptcp-62-0", "subflow": True},
                        {"endpoint_id": 620, "address": "10.203.15.205", "dev": "lw-mptcp-62-1", "subflow": True},
                        {"endpoint_id": 666, "address": "10.203.15.209", "dev": "lw-mptcp-62-2", "subflow": True},
                    ],
                    "underlays": [
                        {"interface_name": "lw-mptcp-62-0", "legs": [{"endpoint_id": 619, "local_address": "10.203.15.201", "peer_address": "10.203.15.202", "mptcp_remote_address": "10.203.15.203", "health_status": "active"}]},
                        {"interface_name": "lw-mptcp-62-1", "legs": [{"endpoint_id": 620, "local_address": "10.203.15.205", "peer_address": "10.203.15.206", "mptcp_remote_address": "10.203.15.207", "health_status": "active"}]},
                        {"interface_name": "lw-mptcp-62-2", "legs": [{"endpoint_id": 666, "local_address": "10.203.15.209", "peer_address": "10.203.15.210", "mptcp_remote_address": "10.203.15.211", "health_status": "pending"}]},
                    ],
                },
            }
            rendered = {
                "service_name": "leikwan-client-62",
                "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-62.service"),
                "service_unit": "unit\n",
                "start_script_path": str(pathlib.Path(tmp_dir) / "start-62.sh"),
                "start_script": "#!/bin/sh\n",
                "files": [
                    {
                        "path": str(pathlib.Path(tmp_dir) / "mptcp-forward-62.json"),
                        "content": json.dumps({"underlays": []}),
                        "mode": 0o600,
                    }
                ],
            }
            base_signature = agent.forward_contract_signature(forward)
            with mock.patch.dict("sys.modules", {"install_forward_runtime": mock.Mock(render_forward_runtime=mock.Mock(return_value=rendered))}):
                with mock.patch.object(agent, "running_kernel_supports_mptcp", return_value=True):
                    with mock.patch.object(agent, "mptcp_client_path", return_value=pathlib.Path("/usr/local/bin/leikwan-mptcp-client.py")):
                        policy_signature = agent.effective_forward_contract_signature(forward)
                    with mock.patch.object(agent, "systemd_is_active", return_value=True):
                        with mock.patch.object(agent, "delete_nft_rules"):
                            with mock.patch.object(agent, "add_nft_rules"):
                                with mock.patch.object(client_agent_module.subprocess, "run"):
                                    with mock.patch.object(agent, "mptcp_client_path", return_value=pathlib.Path("/usr/local/bin/leikwan-mptcp-client.py")):
                                        agent.apply_forward(forward, contract_signature=base_signature)

            applied = agent.load_applied()["62"]
            self.assertNotEqual(base_signature, applied["contract_signature"])
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "private_wg_interface_present", return_value=True):
                    self.assertTrue(agent.forward_apply_is_noop(62, applied["contract_signature"]))
                    self.assertFalse(agent.forward_apply_is_noop(62, base_signature))

    def test_forward_contract_signature_ignores_observation_only_latency_churn(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            base = {
                "forward_id": 47,
                "service_status": "active",
                "listen_port": 11035,
                "mode": "mptcp",
                "transport": "tcp",
                "target": "185.220.238.143:5201",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "probe_transport": "cf_wg",
                        "runtime_capabilities": ["mptcp"],
                        "measured_rtt_ms": 32.0,
                        "cf_wg_probe_latency_ms": 360.0,
                        "health_role": "healthy",
                        "last_error": "",
                    },
                    {
                        "candidate_kind": "rented_group_leg",
                        "endpoint_id": 317,
                        "host": "47.116.104.15",
                        "port": 7000,
                        "runtime_capabilities": ["mptcp"],
                        "one_leg_rtt_ms": 97.0,
                        "quality_score": 0.51,
                    },
                ],
            }
            changed = json.loads(json.dumps(base))
            changed["candidate_pool"][0]["measured_rtt_ms"] = 41.0
            changed["candidate_pool"][0]["cf_wg_probe_latency_ms"] = 280.0
            changed["candidate_pool"][1]["one_leg_rtt_ms"] = 88.0
            changed["candidate_pool"][1]["quality_score"] = 0.73

            self.assertEqual(agent.forward_contract_signature(base), agent.forward_contract_signature(changed))

    def test_mptcp_effective_signature_ignores_health_latency_churn_when_selection_stable(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 62,
                "service_status": "active",
                "listen_port": 32502,
                "mode": "mptcp",
                "transport": "tcp+udp",
                "target": "192.0.2.10:32501",
                "endpoints": [
                    {"endpoint_id": 619, "host": "203.0.113.19", "port": 7000, "health_status": "active"},
                    {"endpoint_id": 620, "host": "203.0.113.20", "port": 7000, "health_status": "active"},
                ],
                "mptcp": {
                    "sidecar_port": 14969,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "subflow_limit": 2,
                    "mptcp_endpoints": [
                        {"endpoint_id": 619, "address": "10.203.15.201", "dev": "lw-mptcp-62-0", "subflow": True},
                        {"endpoint_id": 620, "address": "10.203.15.205", "dev": "lw-mptcp-62-1", "subflow": True},
                    ],
                    "underlays": [
                        {
                            "interface_name": "lw-mptcp-62-0",
                            "legs": [
                                {
                                    "endpoint_id": 619,
                                    "local_address": "10.203.15.201",
                                    "peer_address": "10.203.15.202",
                                    "mptcp_remote_address": "10.203.15.203",
                                    "health_status": "active",
                                }
                            ],
                        },
                        {
                            "interface_name": "lw-mptcp-62-1",
                            "legs": [
                                {
                                    "endpoint_id": 620,
                                    "local_address": "10.203.15.205",
                                    "peer_address": "10.203.15.206",
                                    "mptcp_remote_address": "10.203.15.207",
                                    "health_status": "active",
                                }
                            ],
                        },
                    ],
                },
            }
            health = {
                "62": {
                    "619": {"role": "healthy", "one_leg_rtt_ms": 13.2, "last_latency_ms": 13.2, "quality_score": 0.93},
                    "620": {"role": "healthy", "one_leg_rtt_ms": 12.6, "last_latency_ms": 12.6, "quality_score": 0.94},
                }
            }
            agent.save_bond_endpoint_health(health)
            with mock.patch.object(agent, "running_kernel_supports_mptcp", return_value=True):
                first_signature = agent.effective_forward_contract_signature(forward)

            health["62"]["619"].update({"one_leg_rtt_ms": 13.9, "last_latency_ms": 13.9, "quality_score": 0.90})
            health["62"]["620"].update({"one_leg_rtt_ms": 12.1, "last_latency_ms": 12.1, "quality_score": 0.95})
            agent.save_bond_endpoint_health(health)

            with mock.patch.object(agent, "running_kernel_supports_mptcp", return_value=True):
                self.assertEqual(first_signature, agent.effective_forward_contract_signature(forward))

    def test_mptcp_effective_signature_ignores_health_role_churn_when_selection_stable(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 62,
                "service_status": "active",
                "listen_port": 32502,
                "mode": "mptcp",
                "transport": "tcp+udp",
                "target": "192.0.2.10:32501",
                "endpoints": [
                    {"endpoint_id": 619, "host": "203.0.113.19", "port": 7000, "health_status": "active"},
                ],
                "mptcp": {
                    "sidecar_port": 14969,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "subflow_limit": 1,
                    "mptcp_endpoints": [
                        {"endpoint_id": 619, "address": "10.203.15.201", "dev": "lw-mptcp-62-0", "subflow": True},
                    ],
                    "underlays": [
                        {
                            "interface_name": "lw-mptcp-62-0",
                            "legs": [
                                {
                                    "endpoint_id": 619,
                                    "local_address": "10.203.15.201",
                                    "peer_address": "10.203.15.202",
                                    "mptcp_remote_address": "10.203.15.203",
                                    "health_status": "active",
                                }
                            ],
                        },
                    ],
                },
            }
            agent.save_bond_endpoint_health(
                {
                    "62": {
                        "619": {
                            "role": "probation",
                            "probation_until": 9999.0,
                            "consecutive_successes": 1,
                            "one_leg_rtt_ms": 13.2,
                            "last_latency_ms": 13.2,
                            "quality_score": 0.5,
                        }
                    }
                }
            )
            with mock.patch.object(agent, "running_kernel_supports_mptcp", return_value=True):
                probation_signature = agent.effective_forward_contract_signature(forward)

            agent.save_bond_endpoint_health(
                {
                    "62": {
                        "619": {
                            "role": "healthy",
                            "probation_until": 0.0,
                            "consecutive_successes": 3,
                            "one_leg_rtt_ms": 13.2,
                            "last_latency_ms": 13.2,
                            "quality_score": 0.95,
                        }
                    }
                }
            )
            with mock.patch.object(agent, "running_kernel_supports_mptcp", return_value=True):
                self.assertEqual(probation_signature, agent.effective_forward_contract_signature(forward))
    def test_mptcp_forward_contract_signature_includes_cf_port_and_wss_path(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            base = {
                "forward_id": 47,
                "service_status": "active",
                "listen_port": 11035,
                "mode": "mptcp",
                "transport": "tcp",
                "target": "127.0.0.1:5201",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "probe_transport": "cf_wg",
                        "dial_host": "nrt1.nbwly.com",
                        "dial_port": 2053,
                        "cf_path_prefix": "tokyo.cfwg",
                        "runtime_capabilities": ["bond", "mptcp", "wireguard"],
                    }
                ],
            }
            changed_port = json.loads(json.dumps(base))
            changed_port["candidate_pool"][0]["dial_port"] = 443
            changed_path = json.loads(json.dumps(base))
            changed_path["candidate_pool"][0]["cf_path_prefix"] = "tokyo-alt.cfwg"

            self.assertNotEqual(agent.forward_contract_signature(base), agent.forward_contract_signature(changed_port))
            self.assertNotEqual(agent.forward_contract_signature(base), agent.forward_contract_signature(changed_path))

    def test_bond_endpoint_becomes_quarantined_after_three_failures(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            for _ in range(3):
                agent.record_bond_endpoint_probe_result(26, 184, False, None, "timeout", now=100.0)
            entry = agent.load_bond_endpoint_health()["26"]["184"]
            self.assertEqual(entry["role"], "quarantined")
            self.assertEqual(entry["quarantine_until"], 100.0 + (30.0 * 60.0))

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
            agent.record_bond_endpoint_probe_result(26, 184, True, 12.0, None, now=50.0 + (30.0 * 60.0) + 10.0)
            entry = agent.load_bond_endpoint_health()["26"]["184"]
            self.assertEqual(entry["role"], "probation")
            self.assertGreater(entry["probation_until"], 50.0 + (30.0 * 60.0) + 10.0)

    def test_force_bond_endpoint_quarantine_holds_endpoint_for_thirty_minutes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            entry = agent.force_bond_endpoint_quarantine(26, 184, "cf_bond_leg_probe_failed", None, now=250.0)

            self.assertEqual(entry["role"], "quarantined")
            self.assertEqual(entry["quarantine_until"], 250.0 + (30.0 * 60.0))

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

    def test_self_leg_primary_adds_helper_when_latency_target_exceeded(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 91,
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "helper_group_cap": 1,
                "desired_latency_ms": 40,
                "candidate_pool": [
                    {"candidate_kind": "self_leg", "device_id": "abc", "measured_rtt_ms": 55.0, "ready": True, "selected_primary": True},
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 316, "origin_group_key": "shanghai-a", "measured_rtt_ms": 38.0, "ready": True},
                ],
            }
            next_forward = agent.apply_latency_target_candidate_escalation(forward)
            selected = [item for item in next_forward["candidate_pool"] if item.get("active_helper")]
            self.assertEqual(1, len(selected))
            self.assertEqual("shanghai-a", selected[0]["origin_group_key"])

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

    def test_ensure_runtime_health_normal_bond_quarantines_endpoint_when_one_leg_probe_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "59": {
                        "571": {
                            "role": "healthy",
                            "consecutive_failures": 0,
                            "consecutive_successes": 3,
                            "quarantine_until": 0.0,
                            "probation_until": 0.0,
                            "last_error": "",
                        },
                        "502": {
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
                "forward_id": 59,
                "service_status": "active",
                "listen_port": 32501,
                "mode": "bond",
                "backend_mode": "current_mode",
                "path_prefix": "normal-route",
                "endpoints": [
                    {"endpoint_id": 571, "host": "111.229.217.7", "port": 7000, "ingress_auth_token": "token-1"},
                    {"endpoint_id": 502, "host": "49.235.160.246", "port": 7000, "ingress_auth_token": "token-2"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "normal-route",
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
                            side_effect=[(True, 1.0, None), (False, None, "bond_hello_ack_timeout")],
                        ):
                            with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                                with mock.patch.object(agent, "refresh_bond_runtime_endpoints") as refresh_mock:
                                    with mock.patch.object(
                                        agent,
                                        "collect_live_bond_slave_connections",
                                        return_value=[{"endpoint_id": 571, "host": "111.229.217.7", "port": 7000}],
                                    ):
                                        agent.ensure_runtime_health([forward])

            restart_mock.assert_not_called()
            refresh_mock.assert_called_once()
            entry = agent.load_bond_endpoint_health()["59"]["502"]
            self.assertEqual(entry["role"], "quarantined")
            self.assertEqual(entry["last_error"], "bond_hello_ack_timeout")


    def test_self_leg_selected_cf_bond_does_not_restart_when_slave_helpers_fail(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 47,
                "service_status": "active",
                "listen_port": 11035,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "path_prefix": "07289d1f5ed25399",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "probe_transport": "cf_direct_wss",
                        "target_sidecar_bond_port": 8899,
                    }
                ],
                "endpoints": [
                    {
                        "endpoint_id": 316,
                        "host": "47.116.11.144",
                        "port": 7000,
                        "ingress_auth_token": "token-1",
                    },
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "07289d1f5ed25399",
                    "ws_priority": 10,
                    "session_token": "session-token-1",
                },
            }

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "local_runtime_probe", return_value=(True, 1.0, None)):
                    with mock.patch.object(agent, "remote_plain_tcp_ready_probe", return_value=(False, None, "empty_plain_tcp_probe_response")):
                        with mock.patch.object(agent, "cf_bond_one_leg_connect_probe", side_effect=AssertionError("failed helper should not be promoted to one-leg bond probe")):
                            with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                                with mock.patch.object(agent, "refresh_bond_runtime_endpoints") as refresh_mock:
                                    with mock.patch.object(agent, "collect_live_bond_slave_connections", return_value=[]):
                                        agent.ensure_runtime_health([forward])

            restart_mock.assert_not_called()
            refresh_mock.assert_not_called()
            entry = agent.load_bond_endpoint_health()["47"]["316"]
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

    def test_cf_bond_one_leg_connect_probe_uses_thirty_minute_leg_cooldown_default(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 26,
                "bonding": {
                    "session_token": "session-token-1",
                },
            }
            endpoint = {
                "endpoint_id": 184,
                "host": "8.163.90.132",
            }

            created_commands: list[list[str]] = []

            class FakeProcess:
                def __init__(self):
                    self.stderr = mock.Mock(read=mock.Mock(return_value=""))
                    self.returncode = 0

                def poll(self):
                    return 0

                def terminate(self):
                    return None

                def wait(self, timeout=None):  # noqa: ANN001
                    return 0

                def kill(self):
                    return None

            with mock.patch.object(client_agent_module.pathlib.Path, "exists", return_value=True):
                with mock.patch.object(client_agent_module.socket, "socket") as socket_ctor:
                    fake_bind_sock = mock.MagicMock()
                    fake_bind_sock.__enter__.return_value = fake_bind_sock
                    fake_bind_sock.getsockname.return_value = ("127.0.0.1", 31000)
                    socket_ctor.return_value = fake_bind_sock
                    with mock.patch.object(client_agent_module.subprocess, "Popen", side_effect=lambda cmd, **kwargs: created_commands.append(cmd) or FakeProcess()):
                        ok, latency_ms, error = agent.cf_bond_one_leg_connect_probe(forward, endpoint, 0)

            self.assertFalse(ok)
            self.assertIsNone(latency_ms)
            self.assertEqual(error, "bond_probe_exit_0")
            self.assertTrue(created_commands)
            self.assertIn("--leg-cooldown-ms", created_commands[0])
            index = created_commands[0].index("--leg-cooldown-ms")
            self.assertEqual(created_commands[0][index + 1], "1800000")

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
                "bonding": {"policy_mode": "latency_first", "ws_priority": 10},
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
                "bonding": {"policy_mode": "latency_first", "ws_priority": 10},
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

    def test_select_bond_runtime_endpoints_latency_first_normal_bond_marks_primary_then_helpers(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "40": {
                        "254": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.75},
                        "256": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.95},
                        "258": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.60},
                    }
                }
            )
            forward = {
                "forward_id": 40,
                "mode": "bond",
                "backend_mode": "current_mode",
                "endpoints": [
                    {"endpoint_id": 254, "host": "8.163.90.132", "port": 7000},
                    {"endpoint_id": 256, "host": "8.163.87.42", "port": 7000},
                    {"endpoint_id": 258, "host": "8.163.71.73", "port": 7000},
                ],
                "bonding": {"policy_mode": "latency_first", "ws_priority": 10},
            }

            selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [256, 254, 258])
            self.assertEqual([item["leg_role"] for item in selected], ["primary", "helper", "helper"])

    def test_select_bond_runtime_endpoints_cf_snapshot_does_not_promote_null_latency_legs_to_perfect(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-47.json").write_text(
                json.dumps(
                    {
                        "forward_id": 47,
                        "legs": [
                            {"endpoint_id": 316, "host": "47.116.11.144", "tcp": {"state": "healthy", "last_reason": None}, "recent_events": []},
                            {"endpoint_id": 318, "host": "101.133.142.65", "tcp": {"state": "healthy", "last_reason": None}, "recent_events": []},
                            {"endpoint_id": 320, "host": "8.133.170.123", "tcp": {"state": "healthy", "last_reason": None}, "recent_events": []},
                        ],
                    }
                ),
                encoding="utf-8",
            )
            agent.save_bond_endpoint_health(
                {
                    "47": {
                        "316": {"role": "healthy", "consecutive_successes": 3, "consecutive_failures": 0, "quality_score": 0.40},
                        "317": {"role": "healthy", "consecutive_successes": 20, "consecutive_failures": 0, "quality_score": 0.7480, "last_latency_ms": 50.4},
                        "318": {"role": "healthy", "consecutive_successes": 3, "consecutive_failures": 0, "quality_score": 0.40},
                        "319": {"role": "healthy", "consecutive_successes": 20, "consecutive_failures": 0, "quality_score": 0.7485, "last_latency_ms": 50.3},
                        "320": {"role": "healthy", "consecutive_successes": 3, "consecutive_failures": 0, "quality_score": 0.40},
                    }
                }
            )
            forward = {
                "forward_id": 47,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 316, "host": "47.116.11.144", "port": 7000},
                    {"endpoint_id": 317, "host": "47.116.104.15", "port": 7000},
                    {"endpoint_id": 318, "host": "101.133.142.65", "port": 7000},
                    {"endpoint_id": 319, "host": "47.116.134.3", "port": 7000},
                    {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
                ],
                "bonding": {"policy_mode": "latency_first", "ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [319, 317, 316])
            self.assertEqual(selected[0]["leg_role"], "primary")
            self.assertTrue(all(item["quality_score"] < 1.0 for item in selected))

    def test_select_bond_runtime_endpoints_non_latency_first_bond_keeps_existing_ordering(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
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

            selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [184, 185])
            self.assertTrue(all("leg_role" not in item for item in selected))


if __name__ == "__main__":
    unittest.main()
