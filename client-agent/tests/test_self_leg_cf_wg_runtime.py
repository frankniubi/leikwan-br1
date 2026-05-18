import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, REPO_ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent = load_module("client_agent_cfwg_under_test", "client-agent/client_agent.py")
install_runtime = load_module("install_forward_runtime_cfwg_under_test", "client-agent/install_forward_runtime.py")
leikwan_mptcp_client = load_module("leikwan_mptcp_client_cfwg_under_test", "client-agent/leikwan_mptcp_client.py")


class CfWgSelfLegTests(unittest.TestCase):
    def _candidate(self) -> dict:
        return {
            "candidate_kind": "self_leg",
            "selected_primary": True,
            "self_leg_mptcp_solo_allowed": True,
            "runtime_capabilities": ["bond", "mptcp", "wireguard"],
            "probe_transport": "cf_wg",
            "target_sidecar_port": 8899,
            "target_sidecar_mptcp_port": 8898,
            "self_leg_wg": {
                "enabled": True,
                "outer_transport": "wstunnel_udp",
                "interface_name": "lw-cfwg-47",
                "client_address": "10.204.0.1",
                "sidecar_address": "10.204.0.2",
                "sidecar_public_key": "PUBKEY===",
                "endpoint_host": "edge.example.test",
                "endpoint_port": 51820,
                "cf_dial_host": "104.16.0.5",
                "cf_dial_port": 443,
                "cf_sni_host": "edge.example.test",
                "cf_path_prefix": "/tokyo",
            },
        }

    def test_cf_wg_self_leg_passes_through_filter(self) -> None:
        forward = {"forward_id": 47, "candidate_pool": [self._candidate()]}
        selected = install_runtime.selected_mptcp_self_leg_candidates(forward)
        self.assertEqual(1, len(selected))

    def test_cf_wg_self_leg_bootstraps_like_wireguard_self_leg(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        agent.private_wg_interface_present = lambda _interface_name: False
        selected = agent.select_forward_candidates(
            {
                "forward_id": 47,
                "candidate_pool": [
                    self._candidate(),
                    {
                        "candidate_kind": "rented_group_leg",
                        "endpoint_id": 320,
                        "measured_rtt_ms": 70.0,
                        "ready": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                    },
                ],
            }
        )
        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertTrue(selected[0]["selected_primary"])
        self.assertTrue(selected[0]["ready"])

    def test_existing_cf_wg_interface_without_udp_tunnel_still_bootstraps_self_leg(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        agent.private_wg_interface_present = lambda _interface_name: True
        agent.local_listener_present = lambda _port, _transport="tcp", _timeout=2.5: (False, None, "listener_not_found")
        agent.private_wg_self_leg_connect_probe = lambda _forward, _candidate, timeout=3.0: (False, None, "connection refused")
        selected = agent.select_forward_candidates(
            {
                "forward_id": 47,
                "candidate_pool": [
                    self._candidate(),
                    {
                        "candidate_kind": "rented_group_leg",
                        "endpoint_id": 320,
                        "measured_rtt_ms": 70.0,
                        "ready": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                    },
                ],
            }
        )
        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertEqual("cf_wg_udp_tunnel_bootstrap", selected[0]["probe_state"])
        self.assertTrue(selected[0]["selected_primary"])
        self.assertTrue(selected[0]["ready"])

    def test_mptcp_leg_priority_marks_faster_client_self_leg_primary_and_helpers_standby(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        agent.private_wg_interface_present = lambda _interface_name: True
        agent.local_listener_present = lambda _port, _transport="tcp", _timeout=2.5: (True, 0.2, "")
        agent.private_wg_self_leg_connect_probe = lambda _forward, _candidate, timeout=3.0: (True, 22.0, None)
        forward = {
            "forward_id": 47,
            "mode": "mptcp",
            "runtime_preference": "mptcp_preferred_bond_fallback",
            "helper_group_cap": 3,
            "candidate_pool": [
                self._candidate(),
                {
                    "candidate_kind": "rented_group_leg",
                    "endpoint_id": 317,
                    "origin_group_order": 1,
                    "measured_rtt_ms": 61.0,
                    "ready": True,
                    "runtime_capabilities": ["bond", "mptcp"],
                },
                {
                    "candidate_kind": "rented_group_leg",
                    "endpoint_id": 318,
                    "origin_group_order": 2,
                    "measured_rtt_ms": 74.0,
                    "ready": True,
                    "runtime_capabilities": ["bond", "mptcp"],
                },
            ],
        }

        selected = agent.select_forward_candidates(forward)

        self.assertEqual(["self_leg", "rented_group_leg", "rented_group_leg"], [item["candidate_kind"] for item in selected])
        self.assertEqual(["primary", "standby", "standby"], [item["leg_role"] for item in selected])
        self.assertFalse(selected[0]["backup_only"])
        self.assertTrue(selected[1]["backup_only"])
        self.assertTrue(selected[2]["backup_only"])
        self.assertTrue(selected[0]["selected_primary"])

    def test_existing_cf_wg_interface_with_transient_probe_failure_stays_selected(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        agent.private_wg_interface_present = lambda _interface_name: True
        agent.local_listener_present = lambda _port, _transport="tcp", _timeout=2.5: (True, 0.2, "")
        agent.private_wg_self_leg_connect_probe = lambda _forward, _candidate, timeout=3.0: (False, None, "timed out")
        selected = agent.select_forward_candidates(
            {
                "forward_id": 47,
                "candidate_pool": [
                    self._candidate(),
                    {
                        "candidate_kind": "rented_group_leg",
                        "endpoint_id": 320,
                        "measured_rtt_ms": 70.0,
                        "ready": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                    },
                ],
            }
        )
        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertEqual("cf_wg_probe_transient", selected[0]["probe_state"])
        self.assertTrue(selected[0]["selected_primary"])
        self.assertTrue(selected[0]["ready"])

    def test_cf_wg_self_leg_stays_primary_after_slow_live_probe_to_avoid_flapping(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        agent.private_wg_interface_present = lambda _interface_name: True
        agent.local_listener_present = lambda _port, _transport="tcp", _timeout=2.5: (True, 0.2, "")
        agent.private_wg_self_leg_connect_probe = lambda _forward, _candidate, timeout=3.0: (True, 360.0, None)
        selected = agent.select_forward_candidates(
            {
                "forward_id": 47,
                "candidate_pool": [
                    self._candidate(),
                    {
                        "candidate_kind": "rented_group_leg",
                        "endpoint_id": 320,
                        "measured_rtt_ms": 70.0,
                        "ready": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                    },
                ],
            }
        )
        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertEqual(360.0, selected[0]["cf_wg_probe_latency_ms"])
        self.assertLess(selected[0]["measured_rtt_ms"], 70.0)
        self.assertTrue(selected[0]["selected_primary"])

    def test_mptcp_health_skips_cf_wg_synthetic_leg_when_helper_probe_fails(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        calls = []
        agent.systemd_is_active = lambda _service_name: True
        agent.applied_runtime_mode = lambda _forward_id: "mptcp"
        agent.mptcp_setup_in_progress = lambda _forward_id: False
        agent.local_listener_present = lambda _port, _transport="tcp", _timeout=2.5: (True, 1.0, None)
        agent.remote_tcp_probe = lambda host, port, timeout=3.0: (True, 360.0, None) if host == "10.204.53.42" else (False, None, "timed out")
        agent.load_bond_endpoint_health = lambda: {}
        agent.record_bond_endpoint_probe_result = lambda *args, **kwargs: {"role": "healthy"} if args[3] else {"role": "quarantined"}
        agent.refresh_mptcp_runtime_endpoints = lambda *args, **kwargs: calls.append(args)
        agent.restart_forward_service = lambda *args, **kwargs: self.fail("should not restart when cf_wg self-leg is reachable")
        forward = {
            "forward_id": 47,
            "service_status": "active",
            "mode": "mptcp",
            "listen_port": 11035,
            "mptcp": {
                "sidecar_port": 8898,
                "underlays": [
                    {
                        "interface_name": "lw-cfwg-47",
                        "mode": "client_self_leg",
                        "legs": [{"endpoint_id": 0, "outer_transport": "wstunnel_udp", "mptcp_remote_address": "10.204.53.42"}],
                    },
                    {
                        "interface_name": "lw-mptcp-47-0",
                        "mode": "slave_transit",
                        "legs": [{"endpoint_id": 317, "outer_transport": "udp", "mptcp_remote_address": "10.203.201.179", "peer_public_host": "47.116.104.15"}],
                    },
                ],
            },
            "endpoints": [{"endpoint_id": 317, "host": "47.116.104.15", "port": 7000}],
        }

        agent.ensure_runtime_health([forward])

        self.assertEqual([], calls)

    def test_mptcp_health_does_not_refresh_helper_only_shape_when_cf_wg_self_leg_is_primary(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        calls = []
        agent.systemd_is_active = lambda _service_name: True
        agent.applied_runtime_mode = lambda _forward_id: "mptcp"
        agent.mptcp_setup_in_progress = lambda _forward_id: False
        agent.local_listener_present = lambda _port, _transport="tcp", _timeout=2.5: (True, 1.0, None)
        agent.remote_tcp_probe = lambda _host, _port, timeout=3.0: (False, None, "timed out")
        agent.load_bond_endpoint_health = lambda: {}
        agent.record_bond_endpoint_probe_result = lambda *args, **kwargs: {"role": "quarantined"}
        agent.refresh_mptcp_runtime_endpoints = lambda *args, **kwargs: calls.append(args)
        agent.restart_forward_service = lambda *args, **kwargs: self.fail("should not restart selected cf_wg self-leg because helper probes failed")
        forward = {
            "forward_id": 47,
            "service_status": "active",
            "mode": "mptcp",
            "listen_port": 11035,
            "candidate_pool": [self._candidate(), {"candidate_kind": "rented_group_leg", "endpoint_id": 317, "ready": True}],
            "mptcp": {
                "sidecar_port": 8898,
                "underlays": [
                    {
                        "interface_name": "lw-mptcp-47-0",
                        "mode": "slave_transit",
                        "legs": [{"endpoint_id": 317, "outer_transport": "udp", "mptcp_remote_address": "10.203.201.179", "peer_public_host": "47.116.104.15"}],
                    },
                ],
            },
            "endpoints": [{"endpoint_id": 317, "host": "47.116.104.15", "port": 7000}],
        }

        agent.ensure_runtime_health([forward])

        self.assertEqual([], calls)

    def test_mptcp_health_does_not_refresh_partial_helper_failures_when_cf_wg_self_leg_is_primary(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        calls = []
        agent.systemd_is_active = lambda _service_name: True
        agent.applied_runtime_mode = lambda _forward_id: "mptcp"
        agent.mptcp_setup_in_progress = lambda _forward_id: False
        agent.local_listener_present = lambda _port, _transport="tcp", _timeout=2.5: (True, 1.0, None)
        agent.remote_tcp_probe = lambda host, _port, timeout=3.0: (True, 80.0, None) if host == "10.203.201.179" else (False, None, "timed out")
        agent.load_bond_endpoint_health = lambda: {}
        agent.record_bond_endpoint_probe_result = lambda *args, **kwargs: {"role": "healthy"} if args[3] else {"role": "quarantined"}
        agent.refresh_mptcp_runtime_endpoints = lambda *args, **kwargs: calls.append(args)
        agent.restart_forward_service = lambda *args, **kwargs: self.fail("should not restart selected cf_wg self-leg because one helper probe failed")
        forward = {
            "forward_id": 47,
            "service_status": "active",
            "mode": "mptcp",
            "listen_port": 11035,
            "candidate_pool": [
                self._candidate(),
                {"candidate_kind": "rented_group_leg", "endpoint_id": 317, "ready": True},
                {"candidate_kind": "rented_group_leg", "endpoint_id": 318, "ready": True},
            ],
            "mptcp": {
                "sidecar_port": 8898,
                "underlays": [
                    {
                        "interface_name": "lw-mptcp-47-0",
                        "mode": "slave_transit",
                        "legs": [{"endpoint_id": 317, "outer_transport": "udp", "mptcp_remote_address": "10.203.201.179", "peer_public_host": "47.116.104.15"}],
                    },
                    {
                        "interface_name": "lw-mptcp-47-1",
                        "mode": "slave_transit",
                        "legs": [{"endpoint_id": 318, "outer_transport": "udp", "mptcp_remote_address": "10.203.201.187", "peer_public_host": "101.133.142.65"}],
                    },
                ],
            },
            "endpoints": [
                {"endpoint_id": 317, "host": "47.116.104.15", "port": 7000},
                {"endpoint_id": 318, "host": "101.133.142.65", "port": 7000},
            ],
        }

        agent.ensure_runtime_health([forward])

        self.assertEqual([], calls)

    def test_strip_runtime_observation_fields_ignores_live_latency_churn(self) -> None:
        forward = {
            "forward_id": 47,
            "endpoints": [
                {
                    "endpoint_id": 317,
                    "host": "47.116.104.15",
                    "port": 7000,
                    "quality_score": 0.512,
                    "one_leg_rtt_ms": 97.6,
                    "latency_ewma_ms": None,
                    "last_latency_ms": 97.6,
                    "dynamic_priority": 10,
                    "leg_role": "primary",
                }
            ],
            "mptcp": {
                "mptcp_endpoints": [
                    {"endpoint_id": 317, "address": "10.203.201.177", "dev": "lw-mptcp-47-0", "quality_score": 0.5},
                ],
                "underlays": [
                    {"legs": [{"endpoint_id": 317, "quality_score": 0.5, "one_leg_rtt_ms": 97.6}]},
                ],
            },
        }

        cleaned = client_agent.ClientAgent.strip_runtime_observation_fields(forward)

        self.assertEqual(
            {"endpoint_id": 317, "host": "47.116.104.15", "port": 7000, "leg_role": "primary"},
            cleaned["endpoints"][0],
        )
        self.assertEqual(
            {"endpoint_id": 317, "address": "10.203.201.177", "dev": "lw-mptcp-47-0"},
            cleaned["mptcp"]["mptcp_endpoints"][0],
        )
        self.assertEqual({"endpoint_id": 317}, cleaned["mptcp"]["underlays"][0]["legs"][0])

    def test_cf_wg_bond_mode_without_mptcp_token_falls_back_to_bond_family(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        family = client_agent.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "bond",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [self._candidate()],
            },
            mptcp_supported=True,
        )
        self.assertEqual("bond", family)

    def test_cf_wg_self_leg_renders_wstunnel_udp_before_wg_up(self) -> None:
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "/tokyo",
            "sni": "edge.example.test",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "127.0.0.1",
                "sidecar_port": 8898,
            },
            "candidate_pool": [self._candidate()],
        }
        rendered = install_runtime.render_forward_runtime(forward, {})
        self.assertIn("wstunnel client -L udp://127.0.0.1:", rendered["start_script"])
        self.assertIn("udp://127.0.0.1:40047:127.0.0.1:51820?timeout_sec=0", rendered["start_script"])
        self.assertNotIn("udp://127.0.0.1:40047:104.16.0.5:443?timeout_sec=0", rendered["start_script"])
        self.assertIn('ss -lun "sport = :$port"', rendered["start_script"])
        self.assertIn("wss://104.16.0.5:443", rendered["start_script"])

        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        self.assertEqual([], payload["direct_legs"])
        self.assertEqual(1, len(payload["underlays"]))
        underlay = payload["underlays"][0]
        leg = underlay["legs"][0]
        self.assertEqual("wstunnel_udp", leg["outer_transport"])
        self.assertEqual("104.16.0.5", leg["cf_dial_host"])
        self.assertEqual(443, leg["cf_dial_port"])
        self.assertEqual("127.0.0.1", leg["peer_public_host"])
        self.assertEqual(40047, leg["peer_udp_port"])

        with tempfile.TemporaryDirectory() as tmp_dir:
            private_key = pathlib.Path(tmp_dir) / "private.key"
            private_key.write_text("client-private\n", encoding="utf-8")
            underlay["local_private_key_path"] = str(private_key)
            wg_conf = leikwan_mptcp_client.build_wireguard_interface_config(underlay)
        self.assertIn("Endpoint = 127.0.0.1:40047", wg_conf)
        self.assertNotIn("Endpoint = edge.example.test:", wg_conf)

    def test_cf_wg_self_leg_prefers_explicit_dial_connect_ip_over_candidate_order(self) -> None:
        candidate = self._candidate()
        candidate["dial_connect_ip"] = "162.159.39.5"
        candidate["self_leg_cf_candidates"] = ["162.159.39.4", "104.16.0.5"]
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "/tokyo",
            "sni": "edge.example.test",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "127.0.0.1",
                "sidecar_port": 8898,
            },
            "candidate_pool": [candidate],
        }

        rendered = install_runtime.render_forward_runtime(forward, {})

        self.assertIn("wss://162.159.39.5:443", rendered["start_script"])
        self.assertNotIn("wss://162.159.39.4:443", rendered["start_script"])
        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        leg = payload["underlays"][0]["legs"][0]
        self.assertEqual("162.159.39.5", leg["cf_dial_host"])

    def test_cf_wg_self_leg_prefers_ordered_cf_candidate_ip_for_dial(self) -> None:
        candidate = self._candidate()
        candidate["self_leg_cf_candidates"] = ["162.159.39.5", "104.16.0.5"]
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "/tokyo",
            "sni": "edge.example.test",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "127.0.0.1",
                "sidecar_port": 8898,
            },
            "candidate_pool": [candidate],
        }

        rendered = install_runtime.render_forward_runtime(forward, {})

        self.assertIn("wss://162.159.39.5:443", rendered["start_script"])
        self.assertNotIn("wss://104.16.0.5:443", rendered["start_script"])
        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        leg = payload["underlays"][0]["legs"][0]
        self.assertEqual("162.159.39.5", leg["cf_dial_host"])

    def test_cf_wg_self_leg_runtime_uses_cfwg_underlay_as_mptcp_endpoint(self) -> None:
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "/tokyo",
            "sni": "edge.example.test",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "10.203.201.179",
                "sidecar_port": 8898,
                "mptcp_endpoints": [
                    {"endpoint_id": 317, "address": "10.203.201.177", "dev": "lw-mptcp-47-0", "subflow": True},
                ],
                "underlays": [
                    {
                        "interface_name": "lw-mptcp-47-0",
                        "mode": "slave_transit",
                        "legs": [
                            {
                                "endpoint_id": 317,
                                "local_address": "10.203.201.177",
                                "mptcp_remote_address": "10.203.201.179",
                                "peer_public_host": "47.116.104.15",
                            }
                        ],
                    }
                ],
            },
            "candidate_pool": [
                self._candidate(),
                {
                    "candidate_kind": "rented_group_leg",
                    "endpoint_id": 317,
                    "ready": True,
                    "runtime_capabilities": ["bond", "mptcp"],
                    "measured_rtt_ms": 70.0,
                },
            ],
        }

        rendered = install_runtime.render_forward_runtime(forward, {})
        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])

        self.assertEqual("10.204.0.2", payload["remote_host"])
        self.assertEqual(8898, payload["remote_port"])
        self.assertEqual(2, payload["subflow_limit"])
        self.assertEqual(
            [
                {
                    "endpoint_id": 0,
                    "address": "10.204.0.1",
                    "dev": "lw-cfwg-47",
                    "subflow": True,
                    "signal": False,
                    "backup_only": False,
                    "leg_role": "primary",
                    "health_role": "healthy",
                },
                {
                    "endpoint_id": 317,
                    "address": "10.203.201.177",
                    "dev": "lw-mptcp-47-0",
                    "subflow": True,
                    "backup_only": True,
                    "leg_role": "standby",
                    "health_role": "healthy",
                },
            ],
            payload["mptcp_endpoints"],
        )
        self.assertEqual("lw-cfwg-47", payload["underlays"][0]["interface_name"])
        self.assertEqual("client_self_leg", payload["underlays"][0]["mode"])
        self.assertEqual("slave_transit", payload["underlays"][1]["mode"])
        self.assertTrue(payload["underlays"][1]["legs"][0]["backup_only"])
        self.assertEqual("standby", payload["underlays"][1]["legs"][0]["leg_role"])


if __name__ == "__main__":
    unittest.main()
