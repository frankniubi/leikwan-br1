import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_highloss_self_leg_under_test", "client-agent/client_agent.py")
install_runtime = load_module("install_forward_runtime_highloss_self_leg_under_test", "client-agent/install_forward_runtime.py")


class HighLossSelfLegRuntimeRegressionTests(unittest.TestCase):
    def test_mptcp_runtime_keeps_primary_underlay_aligned_with_sorted_fastest_endpoint(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.load_bond_endpoint_health = lambda: {
            "47": {
                "316": {"role": "healthy", "one_leg_rtt_ms": 110.4, "quality_score": 0.45},
                "320": {"role": "healthy", "one_leg_rtt_ms": 82.8, "quality_score": 0.75},
            }
        }
        forward = {
            "forward_id": 47,
            "mode": "mptcp",
            "endpoints": [
                {"endpoint_id": 316, "host": "47.116.11.144", "port": 7000},
                {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
            ],
            "mptcp": {
                "sidecar_port": 8898,
                "subflow_limit": 2,
                "mptcp_endpoints": [
                    {"endpoint_id": 316, "address": "10.203.201.173", "dev": "lw-mptcp-47-0", "subflow": True},
                    {"endpoint_id": 320, "address": "10.203.201.181", "dev": "lw-mptcp-47-1", "subflow": True},
                ],
                "underlays": [
                    {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 316, "local_address": "10.203.201.173", "mptcp_remote_address": "10.203.201.175"}]},
                    {"interface_name": "lw-mptcp-47-1", "legs": [{"endpoint_id": 320, "local_address": "10.203.201.181", "mptcp_remote_address": "10.203.201.183"}]},
                ],
            },
        }

        next_forward = agent.apply_mptcp_runtime_health_policy(forward, now=100.0)

        self.assertEqual(320, next_forward["endpoints"][0]["endpoint_id"])
        self.assertEqual(320, next_forward["mptcp"]["underlays"][0]["legs"][0]["endpoint_id"])
        self.assertEqual("10.203.201.183", next_forward["mptcp"]["remote_host"])
        self.assertEqual(320, next_forward["mptcp"]["mptcp_endpoints"][0]["endpoint_id"])
        self.assertEqual("10.203.201.181", next_forward["mptcp"]["mptcp_endpoints"][0]["address"])


    def test_mptcp_runtime_maps_endpointless_mptcp_addresses_by_underlay_local_address(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.load_bond_endpoint_health = lambda: {
            "47": {
                "316": {"role": "healthy", "one_leg_rtt_ms": 110.4, "quality_score": 0.45},
                "317": {"role": "healthy", "one_leg_rtt_ms": 109.0, "quality_score": 0.46},
                "318": {"role": "healthy", "one_leg_rtt_ms": 96.1, "quality_score": 0.52},
                "320": {"role": "healthy", "one_leg_rtt_ms": 82.8, "quality_score": 0.59},
            }
        }
        forward = {
            "forward_id": 47,
            "mode": "mptcp",
            "endpoints": [
                {"endpoint_id": 316, "host": "47.116.11.144", "port": 7000},
                {"endpoint_id": 317, "host": "47.116.104.15", "port": 7000},
                {"endpoint_id": 318, "host": "101.133.142.65", "port": 7000},
                {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
            ],
            "mptcp": {
                "sidecar_port": 8898,
                "subflow_limit": 4,
                # Live panel payloads before this fix did not carry endpoint_id here.
                "mptcp_endpoints": [
                    {"address": "10.203.201.173", "dev": "lw-mptcp-47-0", "subflow": True},
                    {"address": "10.203.201.177", "dev": "lw-mptcp-47-1", "subflow": True},
                    {"address": "10.203.201.185", "dev": "lw-mptcp-47-2", "subflow": True},
                    {"address": "10.203.201.181", "dev": "lw-mptcp-47-4", "subflow": True},
                ],
                "underlays": [
                    {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 316, "local_address": "10.203.201.173", "mptcp_remote_address": "10.203.201.175"}]},
                    {"interface_name": "lw-mptcp-47-1", "legs": [{"endpoint_id": 317, "local_address": "10.203.201.177", "mptcp_remote_address": "10.203.201.179"}]},
                    {"interface_name": "lw-mptcp-47-2", "legs": [{"endpoint_id": 318, "local_address": "10.203.201.185", "mptcp_remote_address": "10.203.201.187"}]},
                    {"interface_name": "lw-mptcp-47-4", "legs": [{"endpoint_id": 320, "local_address": "10.203.201.181", "mptcp_remote_address": "10.203.201.183"}]},
                ],
            },
        }

        next_forward = agent.apply_mptcp_runtime_health_policy(forward, now=100.0)

        self.assertEqual(320, next_forward["endpoints"][0]["endpoint_id"])
        self.assertEqual(320, next_forward["mptcp"]["underlays"][0]["legs"][0]["endpoint_id"])
        self.assertEqual("10.203.201.181", next_forward["mptcp"]["underlays"][0]["legs"][0]["local_address"])
        self.assertEqual("10.203.201.183", next_forward["mptcp"]["remote_host"])
        self.assertEqual(320, next_forward["mptcp"]["mptcp_endpoints"][0]["endpoint_id"])
        self.assertEqual("10.203.201.181", next_forward["mptcp"]["mptcp_endpoints"][0]["address"])
        self.assertEqual("lw-mptcp-47-0", next_forward["mptcp"]["mptcp_endpoints"][0]["dev"])

    def test_mptcp_leg_candidates_probe_slave_preconnect_health_endpoint(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        forward = {
            "forward_id": 62,
            "mode": "mptcp",
            "endpoints": [
                {"endpoint_id": 619, "host": "111.229.217.7", "port": 7000},
            ],
            "mptcp": {
                "sidecar_port": 14969,
                "slave_relay_host": "10.203.15.202",
                "slave_relay_port": 38825,
                "underlays": [
                    {
                        "interface_name": "lw-mptcp-62-0",
                        "legs": [
                            {
                                "endpoint_id": 619,
                                "local_address": "10.203.15.201",
                                "slave_address": "10.203.15.202",
                                "peer_address": "10.203.15.202",
                                "mptcp_remote_address": "10.203.15.203",
                                "slave_preconnect": True,
                                "preconnect_relay_port": 38825,
                            }
                        ],
                    }
                ],
            },
        }

        candidates = agent.mptcp_leg_candidates(forward, forward["mptcp"])

        self.assertEqual(1, len(candidates))
        self.assertEqual("10.203.15.202", candidates[0]["host"])
        self.assertEqual(38826, candidates[0]["port"])
        self.assertEqual(38825, candidates[0]["relay_port"])
        self.assertEqual("preconnect_http", candidates[0]["health_probe"])

    def test_mptcp_runtime_updates_slave_relay_to_selected_preconnect_primary(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.load_bond_endpoint_health = lambda: {
            "62": {
                "619": {"role": "healthy", "one_leg_rtt_ms": 45.0, "quality_score": 0.4},
                "620": {"role": "healthy", "one_leg_rtt_ms": 8.0, "quality_score": 0.9},
            }
        }
        forward = {
            "forward_id": 62,
            "mode": "mptcp",
            "endpoints": [
                {"endpoint_id": 619, "host": "111.229.217.7", "port": 7000},
                {"endpoint_id": 620, "host": "150.158.103.182", "port": 7000},
            ],
            "mptcp": {
                "sidecar_port": 14969,
                "slave_relay_host": "10.203.15.202",
                "slave_relay_port": 38825,
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
                                "slave_address": "10.203.15.202",
                                "peer_address": "10.203.15.202",
                                "mptcp_remote_address": "10.203.15.203",
                                "slave_preconnect": True,
                                "preconnect_relay_port": 38825,
                            }
                        ],
                    },
                    {
                        "interface_name": "lw-mptcp-62-1",
                        "legs": [
                            {
                                "endpoint_id": 620,
                                "local_address": "10.203.15.205",
                                "slave_address": "10.203.15.206",
                                "peer_address": "10.203.15.206",
                                "mptcp_remote_address": "10.203.15.207",
                                "slave_preconnect": True,
                                "preconnect_relay_port": 38832,
                            }
                        ],
                    },
                ],
            },
        }

        next_forward = agent.apply_mptcp_runtime_health_policy(forward, now=100.0)

        self.assertEqual(620, next_forward["endpoints"][0]["endpoint_id"])
        self.assertEqual("10.203.15.206", next_forward["mptcp"]["slave_relay_host"])
        self.assertEqual(38832, next_forward["mptcp"]["slave_relay_port"])
        self.assertEqual(620, next_forward["mptcp"]["underlays"][0]["legs"][0]["endpoint_id"])
        self.assertEqual("lw-mptcp-62-0", next_forward["mptcp"]["mptcp_endpoints"][0]["dev"])

    def test_mptcp_runtime_keeps_standby_preconnect_underlay_for_recovery(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.load_bond_endpoint_health = lambda: {
            "62": {
                "619": {"role": "healthy", "one_leg_rtt_ms": 8.0, "quality_score": 0.95},
                "620": {"role": "quarantined", "one_leg_rtt_ms": None, "quality_score": 0.0, "quarantine_until": 9999.0},
            }
        }
        forward = {
            "forward_id": 62,
            "mode": "mptcp",
            "endpoints": [
                {"endpoint_id": 619, "host": "111.229.217.7", "port": 7000},
                {"endpoint_id": 620, "host": "150.158.103.182", "port": 7000},
            ],
            "mptcp": {
                "sidecar_port": 14969,
                "subflow_limit": 1,
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
                                "slave_address": "10.203.15.202",
                                "peer_address": "10.203.15.202",
                                "mptcp_remote_address": "10.203.15.203",
                                "slave_preconnect": True,
                                "preconnect_relay_port": 38825,
                            }
                        ],
                    },
                    {
                        "interface_name": "lw-mptcp-62-1",
                        "legs": [
                            {
                                "endpoint_id": 620,
                                "local_address": "10.203.15.205",
                                "slave_address": "10.203.15.206",
                                "peer_address": "10.203.15.206",
                                "mptcp_remote_address": "10.203.15.207",
                                "slave_preconnect": True,
                                "preconnect_relay_port": 38832,
                            }
                        ],
                    },
                ],
            },
        }

        next_forward = agent.apply_mptcp_runtime_health_policy(forward, now=100.0)

        self.assertEqual([619, 620], [endpoint["endpoint_id"] for endpoint in next_forward["endpoints"]])
        self.assertEqual("standby", next_forward["endpoints"][1]["leg_role"])
        self.assertTrue(next_forward["mptcp"]["mptcp_endpoints"][1]["backup_only"])
        self.assertEqual(620, next_forward["mptcp"]["underlays"][1]["legs"][0]["endpoint_id"])

    def test_mptcp_runtime_keeps_two_quarantined_preconnect_standbys_behind_private_self_leg(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.load_bond_endpoint_health = lambda: {
            "47": {
                "622": {"role": "quarantined", "one_leg_rtt_ms": None, "quality_score": 0.0, "quarantine_until": 9999.0},
                "623": {"role": "quarantined", "one_leg_rtt_ms": None, "quality_score": 0.0, "quarantine_until": 9999.0},
                "626": {"role": "quarantined", "one_leg_rtt_ms": None, "quality_score": 0.0, "quarantine_until": 9999.0},
            }
        }
        forward = {
            "forward_id": 47,
            "mode": "mptcp",
            "candidate_pool": [
                {
                    "candidate_kind": "self_leg",
                    "selected_primary": True,
                    "probe_transport": "private_wg",
                    "runtime_capabilities": ["bond", "mptcp"],
                }
            ],
            "endpoints": [
                {"endpoint_id": 622, "host": "111.229.217.7", "port": 7000},
                {"endpoint_id": 623, "host": "150.158.103.182", "port": 7000},
                {"endpoint_id": 626, "host": "111.231.3.220", "port": 7000},
            ],
            "mptcp": {
                "sidecar_port": 8898,
                "subflow_limit": 2,
                "mptcp_endpoints": [
                    {"endpoint_id": 622, "address": "10.203.201.201", "dev": "lw-mptcp-47-0", "subflow": True},
                    {"endpoint_id": 623, "address": "10.203.201.205", "dev": "lw-mptcp-47-1", "subflow": True},
                    {"endpoint_id": 626, "address": "10.203.201.217", "dev": "lw-mptcp-47-2", "subflow": True},
                ],
                "underlays": [
                    {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 622, "local_address": "10.203.201.201", "slave_address": "10.203.201.202", "peer_address": "10.203.201.202", "mptcp_remote_address": "10.203.201.203", "slave_preconnect": True, "preconnect_relay_port": 38860}]},
                    {"interface_name": "lw-mptcp-47-1", "legs": [{"endpoint_id": 623, "local_address": "10.203.201.205", "slave_address": "10.203.201.206", "peer_address": "10.203.201.206", "mptcp_remote_address": "10.203.201.207", "slave_preconnect": True, "preconnect_relay_port": 38867}]},
                    {"interface_name": "lw-mptcp-47-2", "legs": [{"endpoint_id": 626, "local_address": "10.203.201.217", "slave_address": "10.203.201.218", "peer_address": "10.203.201.218", "mptcp_remote_address": "10.203.201.219", "slave_preconnect": True, "preconnect_relay_port": 38888}]},
                ],
            },
        }

        next_forward = agent.apply_mptcp_runtime_health_policy(forward, now=100.0)

        self.assertEqual([622, 623], [endpoint["endpoint_id"] for endpoint in next_forward["endpoints"]])
        self.assertEqual(["primary", "standby"], [endpoint["leg_role"] for endpoint in next_forward["endpoints"]])
        self.assertEqual(["lw-mptcp-47-0", "lw-mptcp-47-1"], [underlay["interface_name"] for underlay in next_forward["mptcp"]["underlays"]])
        self.assertTrue(next_forward["mptcp"]["mptcp_endpoints"][1].get("backup_only"))

    def test_panel_mptcp_endpoint_payload_carries_endpoint_id_for_health_remap(self) -> None:
        panel_php = (REPO_ROOT / "master/src/Support/ProvisioningService.php").read_text(encoding="utf-8")
        self.assertIn("'endpoint_id' => (int) ($leg['endpoint_id'] ?? 0)", panel_php)


    def test_mptcp_reapply_does_not_drop_live_underlays_when_runtime_files_unchanged(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            rendered = {
                "service_name": "leikwan-client-47",
                "service_path": str(tmp / "leikwan-client-47.service"),
                "service_unit": "[Service]\nExecStart=/bin/true\n",
                "start_script_path": str(tmp / "start-47.sh"),
                "start_script": "#!/bin/sh\nexit 0\n",
                "files": [
                    {
                        "path": str(tmp / "mptcp-forward-47.json"),
                        "content": "{}",
                        "mode": 0o644,
                    }
                ],
                "extra_units": [],
            }
            sys.modules["install_forward_runtime"] = SimpleNamespace(
                render_forward_runtime=lambda forward, agent_config: rendered
            )
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.select_forward_candidates = lambda forward: []
            agent.running_kernel_supports_mptcp = lambda: True
            agent.choose_runtime_family = lambda forward, supported: "mptcp"
            agent.apply_mptcp_runtime_health_policy = lambda forward: forward
            agent.semantic_agent_config = lambda: {}
            agent.bond_binary_path = lambda: pathlib.Path("/bin/true")
            agent.mptcp_client_path = lambda: pathlib.Path(__file__)
            agent.delete_nft_rules = lambda forward_id: None
            agent.add_nft_rules = lambda forward_id, listen_port, transport: None
            agent.load_applied = lambda: {
                "47": {
                    "units": [{"service_name": "leikwan-client-47", "managed_paths": []}],
                    "managed_paths": [str(tmp / "mptcp-forward-47.json")],
                    "published_mode": "mptcp",
                    "runtime_mode": "mptcp",
                }
            }
            agent.previous_mptcp_underlay_interfaces = lambda previous: []
            agent.write_text_if_changed = lambda *args, **kwargs: False
            agent.systemd_is_active = lambda service_name: True
            agent.applied_path = tmp / "applied.json"
            cleanup_calls = []
            agent.cleanup_mptcp_forward_runtime = lambda forward_id: cleanup_calls.append(forward_id)

            agent.apply_forward(
                {
                    "forward_id": 47,
                    "listen_port": 11035,
                    "mode": "mptcp",
                    "transport": "tcp",
                    "target": "185.220.238.143:5201",
                    "endpoints": [{"endpoint_id": 320, "host": "8.133.170.123", "port": 7000}],
                    "mptcp": {"subflow_limit": 1},
                }
            )

        self.assertEqual([], cleanup_calls)


    def test_select_forward_candidates_prefers_self_leg_when_cf_direct_is_faster_than_slave(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.load_bond_endpoint_health = lambda: {
            "47": {
                "316": {"role": "healthy", "one_leg_rtt_ms": 110.0, "quality_score": 0.4},
                "320": {"role": "healthy", "one_leg_rtt_ms": 82.0, "quality_score": 0.8},
            }
        }
        agent.self_leg_direct_cf_connect_probe = lambda forward, candidate, timeout=3.0: (True, 61.0, None)

        selected = agent.select_forward_candidates(
            {
                "forward_id": 47,
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "probe_transport": "cf_direct_wss",
                        "dial_host": "m-jp-4.scipnet.info",
                        "dial_port": 8443,
                        "cf_sni_host": "m-jp-4.scipnet.info",
                        "path_prefix": "tokyo-path",
                        "runtime_capabilities": ["bond"],
                    },
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 320, "host": "8.133.170.123", "port": 7000, "runtime_capabilities": ["bond", "mptcp"]},
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 316, "host": "47.116.11.144", "port": 7000, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            }
        )

        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertTrue(selected[0]["selected_primary"])
        self.assertEqual(61.0, selected[0]["measured_rtt_ms"])


    def test_cf_direct_self_leg_bond_runtime_keeps_rented_helpers(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.reconcile_cf_bond_runtime_health = lambda forward, now=None: {
            "47": {
                "320": {"role": "healthy", "one_leg_rtt_ms": 82.0, "quality_score": 0.8},
                "317": {"role": "healthy", "one_leg_rtt_ms": 107.5, "quality_score": 0.4},
            }
        }

        selected = agent.select_bond_runtime_endpoints(
            {
                "forward_id": 47,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "bonding": {"policy_mode": "latency_first", "ws_priority": 10},
                "endpoints": [
                    {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
                    {"endpoint_id": 317, "host": "47.116.104.15", "port": 7000},
                ],
            },
            now=100.0,
        )

        self.assertEqual([320, 317], [item["endpoint_id"] for item in selected])
        self.assertEqual("primary", selected[0]["leg_role"])
        self.assertEqual("helper", selected[1]["leg_role"])

    def test_self_leg_primary_renders_direct_cf_wstunnel_leg_without_slave_endpoint(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "tokyo-path",
                "transport": "tcp",
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [],
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "dial_host": "162.159.39.16",
                        "dial_port": 2053,
                        "cf_sni_host": "nrt1.nbwly.com",
                        "path_prefix": "tokyo-path",
                    }
                ],
                "bonding": {
                    "remote_target": "127.0.0.1:8898",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "ingress_mode": "wss_path_mux",
                    "policy_mode": "latency_first",
                    "ws_priority": 10,
                },
            },
            {"bond_bin": "/usr/local/bin/leikwan-bond"},
        )

        script = rendered["start_script"]
        self.assertIn("wstunnel client -L tcp://127.0.0.1:", script)
        self.assertIn("tcp://127.0.0.1:21739:127.0.0.1:8898", script)
        self.assertIn("wss://162.159.39.16:2053", script)
        self.assertIn("--tls-sni-override nrt1.nbwly.com", script)
        self.assertIn('--http-headers "Host: nrt1.nbwly.com"', script)
        self.assertIn('--http-upgrade-path-prefix "tokyo-path"', script)
        self.assertIn("role=primary", script)
        self.assertIn("display_host=self_leg", script)
        self.assertNotIn("leikwan-ingress client", script)


    def test_cf_bond_runtime_derives_remote_target_when_panel_omits_bonding_remote_target(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "tokyo-path",
                "transport": "tcp",
                "mode": "bond",
                "backend_mode": "cf_mode",
                "bond_sidecar_port": 8899,
                "endpoints": [
                    {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
                ],
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "dial_host": "162.159.39.16",
                        "dial_port": 2053,
                        "cf_sni_host": "nrt1.nbwly.com",
                        "path_prefix": "tokyo-path",
                        "target_sidecar_bond_port": 8899,
                    }
                ],
                "bonding": {
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "ingress_mode": "wss_path_mux",
                    "policy_mode": "latency_first",
                    "ws_priority": 10,
                },
            },
            {"bond_bin": "/usr/local/bin/leikwan-bond"},
        )

        self.assertIn("tcp://127.0.0.1:21739:127.0.0.1:8899", rendered["start_script"])
        self.assertIn("tcp://127.0.0.1:21740:127.0.0.1:8899", rendered["start_script"])

    def test_bond_self_leg_uses_explicit_bond_backend_when_both_ports_published(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "tokyo-path",
                "transport": "tcp",
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [],
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "dial_host": "162.159.39.16",
                        "dial_port": 2053,
                        "cf_sni_host": "nrt1.nbwly.com",
                        "path_prefix": "tokyo-path",
                        "target_sidecar_port": 8899,
                        "target_sidecar_bond_port": 8899,
                        "target_sidecar_mptcp_port": 8898,
                    }
                ],
                "bonding": {
                    "remote_target": "127.0.0.1:8898",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "ingress_mode": "wss_path_mux",
                    "policy_mode": "latency_first",
                    "ws_priority": 10,
                },
            },
            {"bond_bin": "/usr/local/bin/leikwan-bond"},
        )

        script = rendered["start_script"]
        self.assertIn("tcp://127.0.0.1:21739:127.0.0.1:8899", script)

    def test_runtime_preference_keeps_mptcp_for_private_self_leg(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = agent.choose_runtime_family(
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "mptcp": {"tcp_session_token": "tcp-token", "sidecar_port": 8898},
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "private_wg",
                    },
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 320, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )

        self.assertEqual("mptcp", family)

    def test_mptcp_render_adds_private_self_leg_wstunnel_primary_and_bond_mode(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "tokyo-path",
                "transport": "tcp",
                "mode": "mptcp",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
                ],
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "private_wg",
                        "dial_host": "162.159.39.16",
                        "dial_port": 2053,
                        "cf_sni_host": "nrt1.nbwly.com",
                        "path_prefix": "tokyo-path",
                        "target_sidecar_port": 8899,
                        "target_sidecar_mptcp_port": 8898,
                    }
                ],
                "mptcp": {
                    "sidecar_host": "10.203.201.183",
                    "sidecar_port": 8898,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "subflow_limit": 2,
                    "mptcp_endpoints": [
                        {"endpoint_id": 320, "address": "10.203.201.181", "dev": "lw-mptcp-47-0", "subflow": True},
                    ],
                    "underlays": [
                        {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 320, "local_address": "10.203.201.181", "mptcp_remote_address": "10.203.201.183"}]},
                    ],
                },
            },
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43047,
            },
        )

        script = rendered["start_script"]
        self.assertIn("wstunnel client -L tcp://127.0.0.1:", script)
        self.assertIn("tcp://127.0.0.1:39559:127.0.0.1:8899", script)
        self.assertIn("wss://162.159.39.16:2053", script)
        self.assertIn("--tls-sni-override nrt1.nbwly.com", script)
        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        self.assertEqual("tcp", payload["bond_mode"])
        self.assertEqual("127.0.0.1", payload["remote_host"])
        self.assertEqual(39559, payload["remote_port"])
        self.assertEqual(8898, payload["relay_port"])
        self.assertEqual(1, len(payload["direct_legs"]))
        self.assertEqual("self_leg", payload["direct_legs"][0]["candidate_kind"])
        self.assertLess(payload["direct_legs"][0]["priority"], payload["mptcp_endpoints"][0].get("priority", 100))



    def test_cf_mptcp_private_wg_self_leg_preserves_mptcp_relay_port(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "tokyo-path",
                "transport": "tcp",
                "mode": "mptcp",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 622, "host": "111.229.217.7", "port": 7000},
                ],
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "private_wg",
                        "dial_host": "162.159.39.16",
                        "dial_port": 2053,
                        "cf_sni_host": "nrt1.nbwly.com",
                        "path_prefix": "tokyo-path",
                        "target_sidecar_port": 8899,
                        "target_sidecar_bond_port": 8899,
                        "target_sidecar_mptcp_port": 8898,
                    }
                ],
                "mptcp": {
                    "sidecar_host": "10.203.201.203",
                    "sidecar_port": 8898,
                    "remote_port": 8898,
                    "slave_relay_host": "10.203.201.202",
                    "slave_relay_port": 38860,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "subflow_limit": 2,
                    "mptcp_endpoints": [
                        {"endpoint_id": 622, "address": "10.203.201.201", "dev": "lw-mptcp-47-0", "subflow": True},
                    ],
                    "underlays": [
                        {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 622, "local_address": "10.203.201.201", "mptcp_remote_address": "10.203.201.203"}]},
                    ],
                },
            },
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43047,
            },
        )

        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        self.assertEqual(8898, payload["remote_port"])
        self.assertEqual(8898, payload["relay_port"])


    def test_mptcp_private_wg_self_leg_renders_real_wg_profile_and_uses_sidecar_private_address(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "tokyo-path",
                "transport": "tcp",
                "mode": "mptcp",
                "backend_mode": "cf_mode",
                "endpoints": [],
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp", "wireguard"],
                        "probe_transport": "private_wg",
                        "self_leg_wg": {
                            "enabled": True,
                            "interface_name": "lw-self-47",
                            "client_address": "10.204.47.1",
                            "sidecar_address": "10.204.47.2",
                            "sidecar_public_key": "sidecar-pub",
                            "endpoint_host": "185.220.238.143",
                            "endpoint_port": 14969,
                            "persistent_keepalive": 25,
                        },
                    }
                ],
                "mptcp": {
                    "sidecar_host": "185.220.238.143",
                    "sidecar_port": 8898,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "subflow_limit": 1,
                    "mptcp_endpoints": [],
                    "underlays": [],
                },
            },
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43047,
            },
        )

        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        self.assertEqual("10.204.47.2", payload["remote_host"])
        self.assertEqual(8898, payload["remote_port"])
        self.assertEqual("185.220.238.143", payload["relay_host"])
        self.assertEqual(8898, payload["relay_port"])
        self.assertEqual([], payload["direct_legs"])
        self.assertEqual(1, len(payload["underlays"]))
        underlay = payload["underlays"][0]
        self.assertEqual("client_self_leg", underlay["mode"])
        self.assertEqual("lw-self-47", underlay["interface_name"])
        self.assertEqual("/var/lib/leikwan-client-agent/wireguard/private.key", underlay["local_private_key_path"])
        self.assertEqual(43047, underlay["listen_port"])
        leg = underlay["legs"][0]
        self.assertEqual("10.204.47.1", leg["local_address"])
        self.assertEqual("10.204.47.2", leg["peer_address"])
        self.assertEqual("10.204.47.2", leg["mptcp_remote_address"])
        self.assertEqual("sidecar-pub", leg["peer_public_key"])
        self.assertEqual("185.220.238.143", leg["peer_public_host"])
        self.assertEqual(14969, leg["peer_udp_port"])
        self.assertIn("--setup-underlays", rendered["start_script"])
        self.assertNotIn("wstunnel client -L tcp://127.0.0.1", rendered["start_script"])

    def test_mptcp_render_ignores_self_leg_when_rented_slave_is_selected_primary(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "tokyo-path",
                "transport": "tcp",
                "mode": "mptcp",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
                ],
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "runtime_capabilities": ["bond", "mptcp"],
                        "dial_host": "162.159.39.16",
                        "dial_port": 2053,
                        "cf_sni_host": "nrt1.nbwly.com",
                        "path_prefix": "tokyo-path",
                    },
                    {
                        "candidate_kind": "rented_group_leg",
                        "endpoint_id": 320,
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                    },
                ],
                "mptcp": {
                    "sidecar_host": "10.203.201.183",
                    "sidecar_port": 8898,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "subflow_limit": 1,
                    "mptcp_endpoints": [
                        {"endpoint_id": 320, "address": "10.203.201.181", "dev": "lw-mptcp-47-0", "subflow": True},
                    ],
                    "underlays": [
                        {"interface_name": "lw-mptcp-47-0", "legs": [{"endpoint_id": 320, "local_address": "10.203.201.181", "mptcp_remote_address": "10.203.201.183"}]},
                    ],
                },
            },
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43047,
            },
        )

        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        self.assertEqual("10.203.201.183", payload["remote_host"])
        self.assertEqual(8898, payload["remote_port"])
        self.assertEqual([], payload["direct_legs"])
        self.assertNotIn("wss://162.159.39.16:2053", rendered["start_script"])

    def test_panel_self_leg_candidate_advertises_mptcp_capability(self) -> None:
        panel_php = (REPO_ROOT / "master/src/Support/ProvisioningService.php").read_text(encoding="utf-8")
        self.assertIn("'runtime_capabilities' => ['bond', 'mptcp']", panel_php)



    def test_self_leg_candidate_uses_sidecar_cf_endpoint_not_private_mptcp_port(self) -> None:
        panel_php = (REPO_ROOT / "master/src/Support/ProvisioningService.php").read_text(encoding="utf-8")
        self.assertIn("approved_upstream_realizations", panel_php)
        self.assertIn("cf_listen_port", panel_php)
        self.assertNotIn("'dial_host' => (string) ($config['bond_remote_host'] ?? '')", panel_php)
        self.assertNotIn("'dial_port' => (int) ($config['bond_remote_port'] ?? 0)", panel_php)


if __name__ == "__main__":
    unittest.main()
