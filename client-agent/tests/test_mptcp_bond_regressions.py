import asyncio
import contextlib
import importlib.util
import json
import pathlib
import socket
import struct
import sys
import tempfile
import types
import unittest
import urllib.parse
from types import SimpleNamespace
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


install_forward_runtime = load_module(
    "install_forward_runtime_under_test",
    "client-agent/install_forward_runtime.py",
)
leikwan_mptcp_client = load_module(
    "leikwan_mptcp_client_under_test",
    "client-agent/leikwan_mptcp_client.py",
)
client_agent_module = load_module(
    "client_agent_under_test",
    "client-agent/client_agent.py",
)
sidecar_agent_module = load_module(
    "sidecar_agent_under_test",
    "sidecar-agent/sidecar_agent.py",
)
leikwan_mptcp_sidecar = load_module(
    "leikwan_mptcp_sidecar_under_test",
    "sidecar-agent/leikwan_mptcp_sidecar.py",
)
leikwan_uot = load_module(
    "leikwan_uot_under_test",
    "client-agent/leikwan_uot.py",
)


class BondAndMptcpRegressionTests(unittest.TestCase):
    def test_client_heartbeat_includes_bond_leg_reputation_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            snapshot_path = snapshot_dir / "bond-leg-health-forward-26.json"
            snapshot_path.write_text(
                json.dumps(
                    {
                        "observer_type": "client",
                        "observer_id": 123,
                        "forward_id": 26,
                        "legs": [{"endpoint_id": 185, "host": "8.163.90.235"}],
                    }
                ),
                encoding="utf-8",
            )
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            captured: dict[str, object] = {}

            def fake_pull(method: str, url: str, token: str, payload: dict | None = None):
                self.assertEqual(method, "GET")
                self.assertEqual("/api/client/configs/pull", urllib.parse.urlparse(url).path)
                return ({"forwards": [], "client": {"id": 123}}, {"date": "Sun, 19 Apr 2026 12:00:00 GMT"})

            def fake_post(method: str, url: str, token: str, payload: dict | None = None):
                self.assertEqual(method, "POST")
                self.assertTrue(url.endswith("/api/client/heartbeat"))
                captured["payload"] = payload or {}
                return {"ok": True}

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                with mock.patch.object(agent, "reconcile_global_mptcp_state"):
                    with mock.patch.object(agent, "run_diagnostics_if_requested"):
                        with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                            with mock.patch.object(client_agent_module, "http_json_with_headers", side_effect=fake_pull):
                                with mock.patch.object(client_agent_module, "http_json", side_effect=fake_post):
                                    agent.sync_once()

            payload = captured.get("payload")
            self.assertIsInstance(payload, dict)
            reputation = payload.get("bond_leg_reputation")
            self.assertEqual(
                reputation,
                {
                    "forwards": [
                        {
                            "observer_type": "client",
                            "observer_id": 123,
                            "forward_id": 26,
                            "legs": [{"endpoint_id": 185, "host": "8.163.90.235"}],
                        }
                    ]
                },
            )

    def test_mptcp_runtime_filters_out_self_leg_candidates(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        candidates = agent.filter_mptcp_capable_candidates(
            [
                {"candidate_kind": "self_leg", "runtime_capabilities": ["bond"]},
                {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
            ]
        )
        self.assertEqual(1, len(candidates))
        self.assertEqual("rented_group_leg", candidates[0]["candidate_kind"])

    def test_sidecar_heartbeat_includes_bond_leg_reputation_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            state_dir.mkdir(parents=True, exist_ok=True)
            snapshot_path = state_dir / "bond-leg-health.json"
            snapshot_path.write_text(
                json.dumps(
                    {
                        "observer_type": "sidecar",
                        "observer_id": 7,
                        "forward_id": 26,
                        "legs": [{"host": "8.163.90.235"}],
                    }
                ),
                encoding="utf-8",
            )
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-7",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            captured: dict[str, object] = {}

            def fake_heartbeat(method: str, url: str, token: str, payload: dict | None = None):
                self.assertEqual(method, "POST")
                self.assertTrue(url.endswith("/api/sidecar/heartbeat"))
                captured["payload"] = payload or {}
                return ({"management": {}}, {"date": "Sun, 19 Apr 2026 12:00:00 GMT"})

            with mock.patch.object(agent, "apply_management"):
                with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                    with mock.patch.object(sidecar_agent_module, "http_json_with_headers", side_effect=fake_heartbeat):
                        agent.heartbeat_once()

            payload = captured.get("payload")
            self.assertIsInstance(payload, dict)
            self.assertEqual(
                payload.get("bond_leg_reputation"),
                {
                    "observer_type": "sidecar",
                    "observer_id": 7,
                    "forward_id": 26,
                    "legs": [{"host": "8.163.90.235"}],
                },
            )

    def test_uot_client_replies_from_configured_listen_port(self) -> None:
        asyncio.run(self._assert_uot_client_replies_from_configured_listen_port())

    def test_uot_client_reconnects_after_remote_disconnect(self) -> None:
        asyncio.run(self._assert_uot_client_reconnects_after_remote_disconnect())

    def test_render_quic_leg_prefers_explicit_quic_server_name(self) -> None:
        leg = install_forward_runtime.render_quic_leg_for_endpoint(
            {"host": "8.163.69.176", "port": 7000},
            {
                "quic_enabled": True,
                "quic_server_name": "sidecar.example.com",
                "quic_insecure": True,
            },
        )

        self.assertIsNotNone(leg)
        self.assertIn("server_name=sidecar.example.com", leg)
        self.assertNotIn("server_name=8.163.69.176", leg)

    def test_bond_wss_only_join_timeout_uses_panel_value(self) -> None:
        join_timeout = install_forward_runtime.adaptive_join_timeout_ms(
            {
                "join_timeout_ms": 400,
                "adaptive_join_timeout": True,
                "join_timeout_floor_ms": 35,
                "join_timeout_ceiling_ms": 400,
                "join_timeout_per_leg_ms": 20,
                "quic_enabled": True,
            },
            [
                {"host": "8.163.90.132", "port": 7000, "supports_quic_leg": False},
                {"host": "8.163.90.235", "port": 7000, "supports_quic_leg": False},
                {"host": "8.163.87.42", "port": 7000, "supports_quic_leg": False},
                {"host": "8.163.69.176", "port": 7000, "supports_quic_leg": False},
                {"host": "8.163.71.73", "port": 7000, "supports_quic_leg": False},
            ],
        )

        self.assertEqual(join_timeout, 400)

    def test_bond_start_script_waits_for_local_leg_listeners(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp+udp",
                "mode": "bond",
                "endpoints": [
                    {"host": "8.163.90.132", "port": 7000, "supports_quic_leg": False},
                    {"host": "8.163.90.235", "port": 7000, "supports_quic_leg": False},
                ],
                "bonding": {
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "join_timeout_ms": 400,
                    "adaptive_join_timeout": True,
                    "join_timeout_floor_ms": 35,
                    "join_timeout_ceiling_ms": 400,
                    "join_timeout_per_leg_ms": 20,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
            },
        )

        script = rendered["start_script"]
        self.assertIn("wait_for_local_listener()", script)
        self.assertIn("wait_for_local_listener 20962", script)
        self.assertIn("wait_for_local_listener 20963", script)
        self.assertIn("wait_for_local_listener 21162", script)
        self.assertIn("wait_for_local_listener 21163", script)

    def test_bond_plain_tcp_ingress_uses_leikwan_ingress_client(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp+udp",
                "mode": "bond",
                "endpoints": [
                    {
                        "host": "8.163.90.132",
                        "port": 7001,
                        "supports_quic_leg": False,
                        "ingress_auth_token": "token-123",
                    },
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "join_timeout_ms": 400,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        script = rendered["start_script"]
        self.assertIn("/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:20962", script)
        self.assertIn("--remote 8.163.90.132:7001", script)
        self.assertIn("--route-key a43547b6350f72cd", script)
        self.assertIn("--auth-token token-123", script)

    def test_bond_plain_tcp_ingress_renders_udp_bond_runtime(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp+udp",
                "mode": "bond",
                "endpoints": [
                    {
                        "host": "8.163.90.132",
                        "port": 7001,
                        "supports_quic_leg": False,
                        "ingress_auth_token": "token-123",
                    },
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "join_timeout_ms": 400,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        script = rendered["start_script"]
        self.assertIn("--protocol tcp --listen 0.0.0.0:11024", script)
        self.assertIn("--protocol udp --listen 0.0.0.0:11024", script)
        self.assertIn("/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:21162", script)
        self.assertIn("tcp://127.0.0.1:21162?priority=10", script)

    def test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp+udp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "ingress_auth_token": "token-2"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "udp_uot_sidepath": {
                    "enabled_forward_ids": [26],
                    "shadow_route_suffix": ".uot",
                    "sidecar_port": 8999,
                    "reorder_window_packets": 4096,
                    "reorder_window_bytes": 16777216,
                    "reorder_timeout_ms": 25,
                },
            },
        )

        self.assertIn("--protocol tcp --listen 0.0.0.0:11024", rendered["start_script"])
        self.assertNotIn("--protocol udp --listen 0.0.0.0:11024", rendered["start_script"])
        self.assertEqual(len(rendered["extra_units"]), 1)
        udp_unit = rendered["extra_units"][0]
        self.assertEqual(udp_unit["service_name"], "leikwan-client-26-udp-uot")
        self.assertIn("ExecStart=/usr/local/bin/leikwan-uot-bond --config /etc/leikwan/uot-bond-forward-26.json", udp_unit["service_unit"])
        config_file = next(item for item in udp_unit["files"] if item["path"] == "/etc/leikwan/uot-bond-forward-26.json")
        self.assertIn('"route_key": "a43547b6350f72cd.uot"', config_file["content"])
        self.assertIn('"session_token": "udp-token"', config_file["content"])

    def test_bond_udp_uot_sidepath_does_not_split_pure_udp_transport(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "udp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "ingress_auth_token": "token-2"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "udp_uot_sidepath": {
                    "enabled_forward_ids": [26],
                    "shadow_route_suffix": ".uot",
                    "sidecar_port": 8999,
                    "reorder_window_packets": 4096,
                    "reorder_window_bytes": 16777216,
                    "reorder_timeout_ms": 25,
                },
            },
        )

        self.assertIn("--protocol udp --listen 0.0.0.0:11024", rendered["start_script"])
        self.assertNotIn("--protocol tcp --listen 0.0.0.0:11024", rendered["start_script"])
        self.assertEqual(rendered.get("extra_units", []), [])

    def test_bond_udp_uot_sidepath_renders_health_metadata_and_weights(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp+udp",
                "mode": "bond",
                "endpoints": [
                    {
                        "endpoint_id": 184,
                        "host": "8.163.90.132",
                        "port": 7001,
                        "ingress_auth_token": "token-1",
                        "dynamic_priority": 10,
                        "health_role": "healthy",
                    },
                    {
                        "endpoint_id": 185,
                        "host": "8.163.90.235",
                        "port": 7001,
                        "ingress_auth_token": "token-2",
                        "dynamic_priority": 25,
                        "health_role": "probation",
                    },
                    {
                        "endpoint_id": 186,
                        "host": "8.163.87.42",
                        "port": 7001,
                        "ingress_auth_token": "token-3",
                        "dynamic_priority": 40,
                        "health_role": "quarantined",
                    },
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "ws_priority": 10,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "udp_uot_sidepath": {
                    "enabled_forward_ids": [26],
                    "shadow_route_suffix": ".uot",
                    "sidecar_port": 8999,
                    "reorder_window_packets": 4096,
                    "reorder_window_bytes": 16777216,
                    "reorder_timeout_ms": 25,
                },
            },
        )

        udp_unit = rendered["extra_units"][0]
        config_file = next(item for item in udp_unit["files"] if item["path"] == "/etc/leikwan/uot-bond-forward-26.json")
        content = config_file["content"]
        self.assertIn('"dynamic_priority": 10', content)
        self.assertIn('"dynamic_priority": 25', content)
        self.assertIn('"dynamic_priority": 40', content)
        self.assertIn('"health_role": "healthy"', content)
        self.assertIn('"health_role": "probation"', content)
        self.assertIn('"health_role": "quarantined"', content)
        self.assertIn('"weight": 8', content)
        self.assertIn('"weight": 2', content)
        self.assertIn('"weight": 1', content)

    def test_bond_runtime_uses_endpoint_dynamic_priority(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "dynamic_priority": 10, "ingress_auth_token": "token-1"},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "dynamic_priority": 25, "ingress_auth_token": "token-2"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "join_timeout_ms": 400,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        script = rendered["start_script"]
        self.assertIn("tcp://127.0.0.1:20962?priority=10", script)
        self.assertIn("tcp://127.0.0.1:20963?priority=25", script)

    def test_bond_runtime_renders_new_scheduler_flags(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "193.227.109.82:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp",
                "mode": "bond",
                "endpoints": [{"host": "8.163.90.132", "port": 7001, "dynamic_priority": 10, "ingress_auth_token": "token-1"}],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "a43547b6350f72cd",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "warmup_trigger_bytes": 65536,
                    "warmup_slice_bytes": 65536,
                    "warmup_leg_delay_ms": 20,
                    "bulk_promote_bytes": 262144,
                    "leg_cooldown_ms": 1800000,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        script = rendered["start_script"]
        self.assertIn("--warmup-trigger-bytes 65536", script)
        self.assertIn("--warmup-slice-bytes 65536", script)
        self.assertIn("--warmup-leg-delay-ms 20", script)
        self.assertIn("--bulk-promote-bytes 262144", script)
        self.assertIn("--leg-cooldown-ms 1800000", script)

    def test_bond_latency_first_runtime_marks_primary_and_helper_legs(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 40,
                "listen_port": 11030,
                "target": "45.196.220.47:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "5b6997520f04a681",
                "transport": "tcp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 256, "host": "8.163.87.42", "port": 7000, "dynamic_priority": 10, "ingress_auth_token": "token-a", "leg_role": "primary"},
                    {"endpoint_id": 254, "host": "8.163.90.132", "port": 7000, "dynamic_priority": 10, "ingress_auth_token": "token-b", "leg_role": "helper"},
                ],
                "bonding": {
                    "policy_mode": "latency_first",
                    "udp_primary_only": True,
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "5b6997520f04a681",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        script = rendered["start_script"]
        self.assertIn("role=primary", script)
        self.assertIn("role=helper", script)
        self.assertIn("--latency-first", script)

    def test_bond_latency_first_udp_runtime_sets_primary_only_flag(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 40,
                "listen_port": 11030,
                "target": "45.196.220.47:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "5b6997520f04a681",
                "transport": "udp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 256, "host": "8.163.87.42", "port": 7000, "dynamic_priority": 10, "leg_role": "primary"},
                    {"endpoint_id": 254, "host": "8.163.90.132", "port": 7000, "dynamic_priority": 10, "leg_role": "helper"},
                ],
                "bonding": {
                    "policy_mode": "latency_first",
                    "udp_primary_only": True,
                    "ingress_mode": "wss_path_mux",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        self.assertIn("--protocol udp --listen 0.0.0.0:11030", rendered["start_script"])
        self.assertIn("--latency-first", rendered["start_script"])
        self.assertIn("--udp-primary-only", rendered["start_script"])

    def test_remote_plain_tcp_ready_probe_uses_ping(self) -> None:
        sent_payloads: list[bytes] = []

        class FakeSocket:
            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def settimeout(self, timeout):
                return None

            def sendall(self, payload: bytes) -> None:
                sent_payloads.append(payload)

            def recv(self, size: int) -> bytes:
                return b"OK\n"

        with mock.patch.object(client_agent_module.socket, "create_connection", return_value=FakeSocket()):
            ok, _latency_ms, err = client_agent_module.ClientAgent.remote_plain_tcp_ready_probe(
                "8.163.90.132",
                7001,
                "a43547b6350f72cd",
                "token-123",
            )

        self.assertTrue(ok)
        self.assertIsNone(err)
        self.assertEqual(
            sent_payloads,
            [b"LEIKWAN-INGRESS 1 PING a43547b6350f72cd token-123\n"],
        )

    def test_mptcp_start_script_fails_fast_when_underlay_setup_fails(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 24,
                "listen_port": 20851,
                "target": "127.0.0.1:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "test-prefix",
                "transport": "tcp+udp",
                "mode": "mptcp",
                "endpoints": [{"host": "8.163.69.176", "port": 7000}],
                "mptcp": {
                    "sidecar_host": "10.203.103.1",
                    "sidecar_port": 8443,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "manage_mptcp_endpoints": True,
                    "mptcp_endpoints": [
                        {
                            "address": "10.203.103.113",
                            "dev": "lw-mptcp-24-0",
                            "subflow": True,
                        }
                    ],
                    "underlays": [
                        {
                            "interface_name": "lw-mptcp-24-0",
                            "legs": [
                                {
                                    "local_address": "10.203.103.113",
                                    "peer_address": "10.203.103.1",
                                    "mptcp_remote_address": "10.203.103.2",
                                    "peer_public_key": "peer-pub",
                                    "peer_public_host": "8.163.69.176",
                                    "peer_udp_port": 51820,
                                }
                            ],
                        }
                    ],
                },
            },
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43024,
            },
        )

        self.assertEqual(1, rendered["start_script"].count("--setup-underlays"))
        self.assertIn("setup-underlays returned", rendered["start_script"])
        self.assertNotIn("--setup-underlays || true", rendered["start_script"])

    def test_mptcp_start_script_does_not_run_setup_underlays_twice_after_fix(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 24,
                "listen_port": 20851,
                "target": "127.0.0.1:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "test-prefix",
                "transport": "tcp+udp",
                "mode": "mptcp",
                "endpoints": [{"host": "8.163.69.176", "port": 7000}],
                "mptcp": {
                    "sidecar_host": "10.203.103.1",
                    "sidecar_port": 8443,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "manage_mptcp_endpoints": True,
                    "mptcp_endpoints": [
                        {
                            "address": "10.203.103.113",
                            "dev": "lw-mptcp-24-0",
                            "subflow": True,
                        }
                    ],
                    "underlays": [
                        {
                            "interface_name": "lw-mptcp-24-0",
                            "legs": [
                                {
                                    "local_address": "10.203.103.113",
                                    "peer_address": "10.203.103.1",
                                    "mptcp_remote_address": "10.203.103.2",
                                    "peer_public_key": "peer-pub",
                                    "peer_public_host": "8.163.69.176",
                                    "peer_udp_port": 51820,
                                }
                            ],
                        }
                    ],
                },
            },
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43024,
            },
        )

        self.assertEqual(1, rendered["start_script"].count("--setup-underlays"))

    def test_mptcp_runtime_includes_allow_tcp_fallback_flag(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 24,
                "listen_port": 20851,
                "target": "127.0.0.1:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "test-prefix",
                "transport": "tcp+udp",
                "mode": "mptcp",
                "endpoints": [{"host": "8.163.69.176", "port": 7000}],
                "mptcp": {
                    "sidecar_host": "10.203.103.115",
                    "sidecar_port": 14969,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                    "allow_tcp_fallback": True,
                },
            },
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43024,
            },
        )

        config_file = next(file for file in rendered["files"] if file["path"] == "/etc/leikwan/mptcp-forward-24.json")
        payload = json.loads(config_file["content"])
        self.assertTrue(payload["allow_tcp_fallback"])

    def test_semantic_agent_config_uses_derived_wireguard_ports(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "bond_bin": "/usr/local/bin/leikwan-bond",
                        "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            semantic = agent.semantic_agent_config()

            self.assertEqual(semantic["wireguard_listen_port"], 43123)
            self.assertEqual(semantic["wireguard_fallback_tcp_port"], 53123)

    def test_apply_forward_renders_runtime_with_derived_wireguard_ports(self) -> None:
        captured = {}

        def fake_render_forward_runtime(forward_arg, agent_config):
            captured["agent_config"] = dict(agent_config)
            return {
                "service_name": "leikwan-client-24",
                "service_path": str(REPO_ROOT / "tmp-service.service"),
                "service_unit": "[Unit]\nDescription=Test\n",
                "start_script_path": str(REPO_ROOT / "tmp-start.sh"),
                "start_script": "#!/usr/bin/env bash\n",
                "files": [],
            }

        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "bond_bin": "/usr/local/bin/leikwan-bond",
                        "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            sys.modules["install_forward_runtime"] = types.SimpleNamespace(
                render_forward_runtime=fake_render_forward_runtime
            )

            forward = {
                "forward_id": 24,
                "listen_port": 20851,
                "target": "127.0.0.1:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "test-prefix",
                "transport": "tcp",
                "mode": "mptcp",
                "endpoints": [{"host": "8.163.69.176", "port": 7000}],
                "mptcp": {
                    "sidecar_host": "10.203.103.1",
                    "sidecar_port": 8443,
                    "tcp_session_token": "tcp-token",
                },
            }

            with mock.patch.object(agent, "load_applied", return_value={}):
                with mock.patch.object(agent, "systemd_is_active", return_value=False):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                                with mock.patch.object(client_agent_module.subprocess, "run"):
                                    agent.apply_forward(forward)

        self.assertEqual(captured["agent_config"]["wireguard_listen_port"], 43123)
        self.assertEqual(captured["agent_config"]["wireguard_fallback_tcp_port"], 53123)
        self.assertIn("install_forward_runtime_sha256", captured["agent_config"])
        self.assertTrue(captured["agent_config"]["install_forward_runtime_sha256"])

    def test_apply_forward_tracks_aux_units_and_stop_forward_removes_them(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)
            forward = {
                "forward_id": 26,
                "listen_port": 11024,
                "transport": "tcp+udp",
                "mode": "bond",
                "service_status": "active",
                "endpoints": [{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}],
                "bonding": {"ingress_mode": "plain_tcp_mux", "route_key": "a43547b6350f72cd"},
            }

            def fake_render_forward_runtime(_forward_arg, _agent_config):
                return {
                    "service_name": "leikwan-client-26",
                    "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26.service"),
                    "service_unit": "[Unit]\nDescription=TCP\n",
                    "start_script_path": str(pathlib.Path(tmp_dir) / "leikwan-client-start-26.sh"),
                    "start_script": "#!/usr/bin/env bash\n",
                    "files": [],
                    "extra_units": [
                        {
                            "service_name": "leikwan-client-26-udp-uot",
                            "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service"),
                            "service_unit": "[Unit]\nDescription=UDP\n",
                            "start_script_path": "",
                            "start_script": "",
                            "files": [
                                {
                                    "path": str(pathlib.Path(tmp_dir) / "uot-bond-forward-26.json"),
                                    "content": "{}\n",
                                    "mode": 0o600,
                                }
                            ],
                        }
                    ],
                }

            with mock.patch.dict(sys.modules, {"install_forward_runtime": types.SimpleNamespace(render_forward_runtime=fake_render_forward_runtime)}):
                with mock.patch.object(agent, "load_applied", return_value={}):
                    with mock.patch.object(agent, "systemd_is_active", return_value=False):
                        with mock.patch.object(agent, "delete_nft_rules"):
                            with mock.patch.object(agent, "add_nft_rules"):
                                with mock.patch.object(client_agent_module.subprocess, "run"):
                                    agent.apply_forward(forward)

            applied = json.loads((state_dir / "applied.json").read_text(encoding="utf-8"))
            self.assertEqual(
                [item["service_name"] for item in applied["26"]["units"]],
                ["leikwan-client-26", "leikwan-client-26-udp-uot"],
            )

            with mock.patch.object(client_agent_module.subprocess, "run"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    agent.stop_forward(26, delete_files=True)

            self.assertFalse((pathlib.Path(tmp_dir) / "leikwan-client-26.service").exists())
            self.assertFalse((pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service").exists())
            self.assertFalse((pathlib.Path(tmp_dir) / "uot-bond-forward-26.json").exists())

    def test_apply_forward_reapply_removes_stale_aux_unit_and_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)
            forward = {
                "forward_id": 26,
                "listen_port": 11024,
                "transport": "tcp+udp",
                "mode": "bond",
                "service_status": "active",
                "endpoints": [{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}],
                "bonding": {"ingress_mode": "plain_tcp_mux", "route_key": "a43547b6350f72cd"},
            }

            split_state = {"enabled": True}

            def fake_render_forward_runtime(_forward_arg, _agent_config):
                base = {
                    "service_name": "leikwan-client-26",
                    "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26.service"),
                    "service_unit": "[Unit]\nDescription=TCP\n",
                    "start_script_path": str(pathlib.Path(tmp_dir) / "leikwan-client-start-26.sh"),
                    "start_script": "#!/usr/bin/env bash\n",
                    "files": [],
                }
                if not split_state["enabled"]:
                    return base
                base["extra_units"] = [
                    {
                        "service_name": "leikwan-client-26-udp-uot",
                        "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service"),
                        "service_unit": "[Unit]\nDescription=UDP\n",
                        "start_script_path": "",
                        "start_script": "",
                        "files": [
                            {
                                "path": str(pathlib.Path(tmp_dir) / "uot-bond-forward-26.json"),
                                "content": "{\n  \"v\": 1\n}\n",
                                "mode": 0o600,
                            }
                        ],
                    }
                ]
                return base

            with mock.patch.dict(sys.modules, {"install_forward_runtime": types.SimpleNamespace(render_forward_runtime=fake_render_forward_runtime)}):
                with mock.patch.object(agent, "systemd_is_active", return_value=False):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(client_agent_module.subprocess, "run"):
                                agent.apply_forward(forward)

                self.assertTrue((pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service").exists())
                self.assertTrue((pathlib.Path(tmp_dir) / "uot-bond-forward-26.json").exists())

                split_state["enabled"] = False
                with mock.patch.object(agent, "systemd_is_active", return_value=False):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(client_agent_module.subprocess, "run") as run_mock:
                                agent.apply_forward(forward)

                commands = [str(call.args[0]) for call in run_mock.call_args_list]
                self.assertTrue(any("systemctl stop leikwan-client-26-udp-uot.service" in command for command in commands))
                self.assertTrue(any("systemctl disable leikwan-client-26-udp-uot.service" in command for command in commands))
                self.assertFalse((pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service").exists())
                self.assertFalse((pathlib.Path(tmp_dir) / "uot-bond-forward-26.json").exists())
                applied = json.loads((state_dir / "applied.json").read_text(encoding="utf-8"))
                self.assertEqual(
                    [item["service_name"] for item in applied["26"]["units"]],
                    ["leikwan-client-26"],
                )

    def test_apply_forward_restarts_aux_unit_when_only_aux_config_changes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)
            forward = {
                "forward_id": 26,
                "listen_port": 11024,
                "transport": "tcp+udp",
                "mode": "bond",
                "service_status": "active",
                "endpoints": [{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}],
                "bonding": {"ingress_mode": "plain_tcp_mux", "route_key": "a43547b6350f72cd"},
            }

            aux_config_state = {"content": "{\n  \"session_token\": \"udp-token-v1\"\n}\n"}

            def fake_render_forward_runtime(_forward_arg, _agent_config):
                return {
                    "service_name": "leikwan-client-26",
                    "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26.service"),
                    "service_unit": "[Unit]\nDescription=TCP\n",
                    "start_script_path": str(pathlib.Path(tmp_dir) / "leikwan-client-start-26.sh"),
                    "start_script": "#!/usr/bin/env bash\n",
                    "files": [],
                    "extra_units": [
                        {
                            "service_name": "leikwan-client-26-udp-uot",
                            "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-26-udp-uot.service"),
                            "service_unit": "[Unit]\nDescription=UDP\n",
                            "start_script_path": "",
                            "start_script": "",
                            "files": [
                                {
                                    "path": str(pathlib.Path(tmp_dir) / "uot-bond-forward-26.json"),
                                    "content": aux_config_state["content"],
                                    "mode": 0o600,
                                }
                            ],
                        }
                    ],
                }

            with mock.patch.dict(sys.modules, {"install_forward_runtime": types.SimpleNamespace(render_forward_runtime=fake_render_forward_runtime)}):
                with mock.patch.object(agent, "systemd_is_active", return_value=False):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(client_agent_module.subprocess, "run"):
                                agent.apply_forward(forward)

                aux_config_state["content"] = "{\n  \"session_token\": \"udp-token-v2\"\n}\n"
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(client_agent_module.subprocess, "run") as run_mock:
                                agent.apply_forward(forward)

                commands = [str(call.args[0]) for call in run_mock.call_args_list]
                self.assertTrue(any("systemctl restart leikwan-client-26-udp-uot.service" in command for command in commands))

    def test_apply_forward_uses_selected_bond_runtime_endpoints(self) -> None:
        captured = {}

        def fake_render_forward_runtime(forward_arg, agent_config):
            captured["forward"] = dict(forward_arg)
            return {
                "service_name": "leikwan-client-26",
                "service_path": str(REPO_ROOT / "tmp-service.service"),
                "service_unit": "[Unit]\nDescription=Test\n",
                "start_script_path": str(REPO_ROOT / "tmp-start.sh"),
                "start_script": "#!/usr/bin/env bash\n",
                "files": [],
            }

        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "bond_bin": "/usr/local/bin/leikwan-bond",
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            sys.modules["install_forward_runtime"] = types.SimpleNamespace(
                render_forward_runtime=fake_render_forward_runtime
            )

            forward = {
                "forward_id": 26,
                "listen_port": 11024,
                "target": "127.0.0.1:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "a43547b6350f72cd",
                "transport": "tcp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            with mock.patch.object(agent, "load_applied", return_value={}):
                with mock.patch.object(agent, "systemd_is_active", return_value=False):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                                with mock.patch.object(client_agent_module.subprocess, "run"):
                                    with mock.patch.object(
                                        agent,
                                        "select_bond_runtime_endpoints",
                                        return_value=[{"endpoint_id": 185, "host": "8.163.90.235", "port": 7001, "dynamic_priority": 25}],
                                    ) as select_mock:
                                        agent.apply_forward(forward)

        select_mock.assert_called_once()
        self.assertEqual(
            [endpoint["endpoint_id"] for endpoint in captured["forward"]["endpoints"]],
            [185],
        )

    def test_configure_mptcp_policy_raises_when_endpoint_device_is_missing(self) -> None:
        def fake_run_command(parts, check=False):
            if parts[:4] == ["ip", "mptcp", "limits", "set"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:2] == ["sysctl", "-w"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts == ["ip", "mptcp", "endpoint", "show"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts == ["ip", "link", "show", "dev", "lw-mptcp-24-0"]:
                return SimpleNamespace(returncode=1, stdout="", stderr="Device does not exist")
            if parts[:4] == ["ip", "mptcp", "endpoint", "add"]:
                raise AssertionError("configure_mptcp_policy should not add endpoints before the interface exists")
            raise AssertionError(f"unexpected command: {parts}")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(leikwan_mptcp_client, "running_kernel_supports_mptcp", return_value=True):
                with mock.patch.object(leikwan_mptcp_client, "shutil_which", return_value="/sbin/ip"):
                    with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                        with self.assertRaisesRegex(RuntimeError, "lw-mptcp-24-0"):
                            leikwan_mptcp_client.configure_mptcp_policy(
                            {
                                "manage_mptcp_endpoints": True,
                                "subflow_limit": 5,
                                "mptcp_endpoint_id_base": 240,
                                "mptcp_endpoints": [
                                    {
                                        "address": "10.203.103.113",
                                        "dev": "lw-mptcp-24-0",
                                        "subflow": True,
                                    }
                                ],
                            }
                        )

    def test_apply_forward_does_not_crash_service_when_private_endpoint_device_is_missing(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)

            def fake_render_forward_runtime(_forward_arg, _agent_config):
                return {
                    "service_name": "leikwan-client-47",
                    "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-47.service"),
                    "service_unit": "[Unit]\nDescription=47\n",
                    "start_script_path": str(pathlib.Path(tmp_dir) / "leikwan-client-start-47.sh"),
                    "start_script": "#!/usr/bin/env bash\n",
                    "files": [],
                }

            previous = {
                "47": {
                    "runtime_mode": "mptcp",
                    "rendered_signature": "old-rendered-signature",
                    "contract_signature": "old-contract-signature",
                    "units": [],
                    "managed_paths": [],
                }
            }
            forward = {
                "forward_id": 47,
                "listen_port": 11035,
                "target": "185.220.238.143:5201",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "07289d1f5ed25399",
                "transport": "tcp+udp",
                "mode": "mptcp",
                "service_status": "active",
                "endpoints": [{"host": "49.235.160.246", "port": 7000}],
                "mptcp": {
                    "sidecar_host": "10.203.201.139",
                    "sidecar_port": 8898,
                    "tcp_session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                },
            }

            with mock.patch.dict(sys.modules, {"install_forward_runtime": types.SimpleNamespace(render_forward_runtime=fake_render_forward_runtime)}):
                with mock.patch.object(agent, "load_applied", return_value=previous):
                    with mock.patch.object(agent, "systemd_is_active", return_value=False):
                        with mock.patch.object(agent, "delete_nft_rules"):
                            with mock.patch.object(agent, "add_nft_rules"):
                                with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                                    with mock.patch.object(client_agent_module.subprocess, "run"):
                                        with mock.patch.object(agent, "cleanup_mptcp_forward_runtime"):
                                            agent.apply_forward(forward)

    def test_configure_mptcp_policy_skips_missing_backup_endpoint_device(self) -> None:
        recorded = []

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts[:4] == ["ip", "mptcp", "limits", "set"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:2] == ["sysctl", "-w"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts == ["ip", "mptcp", "endpoint", "show"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts == ["ip", "link", "show", "dev", "lw-cfwg-47"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts == ["ip", "link", "show", "dev", "lw-mptcp-47-0"]:
                return SimpleNamespace(returncode=1, stdout="", stderr="Device does not exist")
            if parts[:4] == ["ip", "mptcp", "endpoint", "add"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            raise AssertionError(f"unexpected command: {parts}")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(leikwan_mptcp_client, "running_kernel_supports_mptcp", return_value=True):
                with mock.patch.object(leikwan_mptcp_client, "shutil_which", return_value="/sbin/ip"):
                    with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                        leikwan_mptcp_client.configure_mptcp_policy(
                            {
                                "manage_mptcp_endpoints": True,
                                "subflow_limit": 3,
                                "mptcp_endpoint_id_base": 220,
                                "mptcp_endpoints": [
                                    {
                                        "address": "10.204.53.41",
                                        "dev": "lw-cfwg-47",
                                        "subflow": True,
                                    },
                                    {
                                        "address": "10.203.201.177",
                                        "dev": "lw-mptcp-47-0",
                                        "subflow": True,
                                        "backup_only": True,
                                    },
                                ],
                            }
                        )

        flattened = [" ".join(parts) for parts in recorded if isinstance(parts, list)]
        self.assertTrue(any("endpoint add 10.204.53.41 id 221 dev lw-cfwg-47 subflow" in line for line in flattened))
        self.assertFalse(any("endpoint add 10.203.201.177 id 222 dev lw-mptcp-47-0 subflow backup" in line for line in flattened))

    def test_configure_mptcp_policy_deletes_conflicting_managed_window_endpoint(self) -> None:
        recorded = []
        endpoint_show_outputs = iter(
            [
                "\n".join(
                    [
                        "10.203.107.177 id 10 subflow dev if19639",
                        "10.203.107.181 id 3 subflow dev lw-mptcp-25-1",
                        "10.203.107.189 id 4 subflow dev lw-mptcp-25-2",
                    ]
                ),
                "\n".join(
                    [
                        "10.203.107.181 id 3 subflow dev lw-mptcp-25-1",
                        "10.203.107.189 id 4 subflow dev lw-mptcp-25-2",
                    ]
                ),
            ]
        )

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts[:4] == ["ip", "mptcp", "limits", "set"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:2] == ["sysctl", "-w"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts == ["ip", "mptcp", "endpoint", "show"]:
                return SimpleNamespace(returncode=0, stdout=next(endpoint_show_outputs), stderr="")
            if parts == ["ip", "link", "show", "dev", "lw-mptcp-25-0"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:5] == ["ip", "mptcp", "endpoint", "delete", "id"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:4] == ["ip", "mptcp", "endpoint", "add"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            raise AssertionError(f"unexpected command: {parts}")

        with mock.patch.object(leikwan_mptcp_client.os, "name", "posix"):
            with mock.patch.object(leikwan_mptcp_client, "running_kernel_supports_mptcp", return_value=True):
                with mock.patch.object(leikwan_mptcp_client, "shutil_which", return_value="/sbin/ip"):
                    with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                        leikwan_mptcp_client.configure_mptcp_policy(
                        {
                            "manage_mptcp_endpoints": True,
                            "subflow_limit": 5,
                            "mptcp_endpoint_id_base": 1,
                            "mptcp_endpoints": [
                                {"address": "10.203.107.177", "dev": "lw-mptcp-25-0", "subflow": True},
                                {"address": "10.203.107.181", "dev": "lw-mptcp-25-1", "subflow": True},
                                {"address": "10.203.107.189", "dev": "lw-mptcp-25-2", "subflow": True},
                            ],
                        }
                    )

        flattened = [" ".join(parts) for parts in recorded]
        self.assertTrue(any("endpoint delete id 10" in line for line in flattened))
        self.assertTrue(any("endpoint add 10.203.107.177 id 2 dev lw-mptcp-25-0 subflow" in line for line in flattened))

    def test_client_uot_port_is_derived_uniquely_per_leg(self) -> None:
        port_a = leikwan_mptcp_client.local_uot_udp_port(
            "lw-mptcp-24-0",
            {"endpoint_id": 174, "peer_tcp_fallback_port": 9968},
        )
        port_b = leikwan_mptcp_client.local_uot_udp_port(
            "lw-mptcp-24-1",
            {"endpoint_id": 175, "peer_tcp_fallback_port": 9968},
        )

        self.assertNotEqual(port_a, 9968)
        self.assertNotEqual(port_b, 9968)
        self.assertNotEqual(port_a, port_b)

    def test_sidecar_uot_port_is_derived_uniquely_per_leg(self) -> None:
        port_a = leikwan_mptcp_sidecar.local_uot_udp_port(
            "lw-sc-3-0",
            {"endpoint_id": 174, "peer_tcp_fallback_port": 9968},
        )
        port_b = leikwan_mptcp_sidecar.local_uot_udp_port(
            "lw-sc-3-1",
            {"endpoint_id": 175, "peer_tcp_fallback_port": 9968},
        )

        self.assertNotEqual(port_a, 9968)
        self.assertNotEqual(port_b, 9968)
        self.assertNotEqual(port_a, port_b)

    async def _assert_uot_client_replies_from_configured_listen_port(self) -> None:
        reply_payload = b"world"
        received_payloads: list[bytes] = []

        async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            try:
                header = await reader.readexactly(4)
                length = struct.unpack(">I", header)[0]
                payload = await reader.readexactly(length)
                received_payloads.append(payload)
                writer.write(struct.pack(">I", len(reply_payload)))
                writer.write(reply_payload)
                await writer.drain()
                await asyncio.sleep(0.1)
            finally:
                writer.close()
                await writer.wait_closed()

        server = await asyncio.start_server(handler, host="127.0.0.1", port=0)
        server_port = server.sockets[0].getsockname()[1]

        probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        probe.bind(("127.0.0.1", 0))
        listen_port = probe.getsockname()[1]
        probe.close()

        uot_task = asyncio.create_task(
            leikwan_uot.run_client(("127.0.0.1", listen_port), ("127.0.0.1", server_port))
        )

        wg_sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        wg_sock.bind(("127.0.0.1", 0))
        wg_sock.settimeout(2.0)
        try:
            await asyncio.sleep(0.1)
            initial_payload = b"hello"
            wg_sock.sendto(initial_payload, ("127.0.0.1", listen_port))
            data, addr = await asyncio.to_thread(wg_sock.recvfrom, 65535)
        finally:
            uot_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await uot_task
            server.close()
            await server.wait_closed()
            wg_sock.close()

        self.assertEqual(received_payloads, [b"hello"])
        self.assertEqual(data, reply_payload)
        self.assertEqual(addr, ("127.0.0.1", listen_port))

    async def _assert_uot_client_reconnects_after_remote_disconnect(self) -> None:
        reply_payload = b"reconnected"
        received_payloads: list[bytes] = []
        first_disconnect = asyncio.Event()

        connection_count = 0

        async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            nonlocal connection_count
            connection_count += 1
            try:
                if connection_count == 1:
                    first_disconnect.set()
                    return
                header = await reader.readexactly(4)
                length = struct.unpack(">I", header)[0]
                payload = await reader.readexactly(length)
                received_payloads.append(payload)
                writer.write(struct.pack(">I", len(reply_payload)))
                writer.write(reply_payload)
                await writer.drain()
                await asyncio.sleep(0.1)
            finally:
                writer.close()
                await writer.wait_closed()

        server = await asyncio.start_server(handler, host="127.0.0.1", port=0)
        server_port = server.sockets[0].getsockname()[1]

        probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        probe.bind(("127.0.0.1", 0))
        listen_port = probe.getsockname()[1]
        probe.close()

        uot_task = asyncio.create_task(
            leikwan_uot.run_client(("127.0.0.1", listen_port), ("127.0.0.1", server_port))
        )

        wg_sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        wg_sock.bind(("127.0.0.1", 0))
        wg_sock.settimeout(2.0)
        try:
            await asyncio.wait_for(first_disconnect.wait(), 2.0)
            await asyncio.sleep(0.3)
            self.assertFalse(uot_task.done(), "uot client should stay alive after remote disconnect")

            retry_payload = b"retry"
            wg_sock.sendto(retry_payload, ("127.0.0.1", listen_port))
            data, addr = await asyncio.to_thread(wg_sock.recvfrom, 65535)
        finally:
            uot_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await uot_task
            server.close()
            await server.wait_closed()
            wg_sock.close()

        self.assertEqual(connection_count, 2)
        self.assertEqual(received_payloads, [b"retry"])
        self.assertEqual(data, reply_payload)
        self.assertEqual(addr, ("127.0.0.1", listen_port))

    def test_setup_runtime_skips_mptcp_policy_when_underlays_fail(self) -> None:
        with mock.patch.object(
            leikwan_mptcp_client,
            "apply_underlays",
            return_value={"failed_interface_names": ["lw-cfwg-47"], "active_interface_names": []},
        ) as apply_mock:
            with mock.patch.object(leikwan_mptcp_client, "configure_mptcp_policy") as policy_mock:
                leikwan_mptcp_client.setup_runtime({"forward_id": 47})

        apply_mock.assert_called_once()
        policy_mock.assert_not_called()

    def test_setup_runtime_allows_endpoint_policy_when_private_underlays_survive_and_helper_fails(self) -> None:
        with mock.patch.object(
            leikwan_mptcp_client,
            "apply_underlays",
            return_value={
                "failed_interface_names": ["lw-mptcp-47-0"],
                "active_interface_names": ["lw-cfwg-47", "lw-cfsr-47"],
            },
        ) as apply_mock:
            with mock.patch.object(leikwan_mptcp_client, "configure_mptcp_policy") as policy_mock:
                leikwan_mptcp_client.setup_runtime({"forward_id": 47})

        apply_mock.assert_called_once()
        policy_mock.assert_called_once()

    def test_sidecar_agent_can_apply_state_dir_config_patch(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "shared-state"),
                        "service_name": "leikwan-bond-sidecar-3",
                        "runtime_config_path": "/etc/leikwan-sidecar/runtime.json",
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            next_state_dir = str(pathlib.Path(tmp_dir) / "point-3-state")
            next_runtime_path = "/etc/leikwan-sidecar-3/runtime.json"
            agent.apply_config_patch(
                {
                    "state_dir": next_state_dir,
                    "runtime_config_path": next_runtime_path,
                }
            )

            self.assertEqual(str(agent.state_dir), next_state_dir)
            self.assertEqual(str(agent.runtime_config_path()).replace("\\", "/"), next_runtime_path)
            persisted = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertEqual(persisted["state_dir"], next_state_dir)
            self.assertEqual(persisted["runtime_config_path"], next_runtime_path)

    def test_client_agent_config_patch_can_add_and_remove_udp_uot_sidepath(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            sidepath = {
                "enabled_forward_ids": [26],
                "shadow_route_suffix": ".uot",
                "sidecar_port": 8999,
            }

            agent.apply_config_patch({"udp_uot_sidepath": sidepath})
            persisted = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertEqual(persisted["udp_uot_sidepath"], sidepath)

            agent.apply_config_patch({"udp_uot_sidepath": None})
            persisted = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertNotIn("udp_uot_sidepath", persisted)

    def test_sidecar_agent_config_patch_can_add_and_remove_udp_uot_sidepath(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "shared-state"),
                        "service_name": "leikwan-bond-sidecar-3",
                        "runtime_config_path": "/etc/leikwan-sidecar/runtime.json",
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            sidepath = {
                "enabled_forward_ids": [26],
                "sidecar_port": 8999,
                "reorder_window_packets": 4096,
            }

            agent.apply_config_patch({"udp_uot_sidepath": sidepath})
            persisted = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertEqual(persisted["udp_uot_sidepath"], sidepath)

            agent.apply_config_patch({"udp_uot_sidepath": None})
            persisted = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertNotIn("udp_uot_sidepath", persisted)

    def test_sidecar_start_script_fails_fast_when_setup_only_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "sidecar-state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-3",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                        agent.apply_management(
                            {
                                "mode": "mptcp",
                                "bind_host": "0.0.0.0",
                                "listen_port": 14969,
                                "token_secret": "secret",
                                "underlays": [],
                                "mptcp_policy": {},
                            }
                        )

            start_script = (state_dir / "start-sidecar.sh").read_text(encoding="utf-8")
            self.assertIn("--setup-only", start_script)
            self.assertNotIn("|| true", start_script)

    def test_sidecar_bond_only_runtime_forces_tcp_fallback_listener(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "sidecar-state"
            runtime_path = pathlib.Path(tmp_dir) / "runtime.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-2",
                        "runtime_config_path": str(runtime_path),
                        "allow_tcp_fallback": False,
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                        agent.apply_management(
                            {
                                "mode": "bond",
                                "bind_host": "0.0.0.0",
                                "listen_port": 14961,
                                "token_secret": "secret",
                                "underlays": [],
                                "mptcp_policy": {},
                            }
                        )

            runtime_config = json.loads(runtime_path.read_text(encoding="utf-8"))
            self.assertTrue(runtime_config["allow_tcp_fallback"])

    def test_sidecar_bond_only_runtime_uses_bond_server_binary(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "sidecar-state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-2",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                        agent.apply_management(
                            {
                                "mode": "bond",
                                "bind_host": "0.0.0.0",
                                "listen_port": 14961,
                                "token_secret": "secret",
                                "underlays": [],
                                "mptcp_policy": {},
                            }
                        )

            start_script = (state_dir / "start-sidecar.sh").read_text(encoding="utf-8")
            self.assertIn("/usr/local/bin/leikwan-bond", start_script)
            self.assertIn('exec "$BOND_SERVER" server --listen "$LISTEN" --token-secret "$TOKEN_SECRET"', start_script)
            self.assertNotIn("--startup-warmup-bytes-per-leg", start_script)
            self.assertNotIn("leikwan-mptcp-relay\" server --config", start_script)

    def test_sidecar_bond_runtime_renders_negotiated_startup_warmup(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "sidecar-state"
            runtime_path = pathlib.Path(tmp_dir) / "runtime.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-2",
                        "runtime_config_path": str(runtime_path),
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                        agent.apply_management(
                            {
                                "bond_runtime": {
                                    "bind_host": "0.0.0.0",
                                    "listen_port": 14961,
                                    "token_secret": "secret",
                                    "forward_ids": [26],
                                    "startup_warmup_bytes_per_leg": 1_048_576,
                                }
                            }
                        )

            start_script = (state_dir / "start-sidecar.sh").read_text(encoding="utf-8")
            self.assertIn("--startup-warmup-bytes-per-leg 1048576", start_script)
            self.assertEqual(
                1_048_576,
                json.loads(runtime_path.read_text(encoding="utf-8"))["startup_warmup_bytes_per_leg"],
            )

    def test_sidecar_bond_runtime_rejects_oversized_startup_warmup(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                    }
                ),
                encoding="utf-8",
            )
            agent = sidecar_agent_module.SidecarAgent(config_path)

            with self.assertRaisesRegex(RuntimeError, "startup warmup"):
                agent.apply_management(
                    {
                        "bond_runtime": {
                            "bind_host": "0.0.0.0",
                            "listen_port": 14961,
                            "token_secret": "secret",
                            "startup_warmup_bytes_per_leg": 2_097_153,
                        }
                    }
                )

    def test_sidecar_bond_runtime_exports_single_forward_id_when_unambiguous(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "sidecar-state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-4",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                        agent.apply_management(
                            {
                                "bond_runtime": {
                                    "bind_host": "0.0.0.0",
                                    "listen_port": 9000,
                                    "token_secret": "secret",
                                    "forward_ids": [26],
                                }
                            }
                        )

            start_script = (state_dir / "start-sidecar.sh").read_text(encoding="utf-8")
            self.assertIn("export LEIKWAN_BOND_FORWARD_ID='26'", start_script)

    def test_sidecar_update_scheduling_does_not_skip_runtime_rewrite(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "sidecar-state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-4",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "systemd_is_active", return_value=True):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                        with mock.patch.object(agent, "request_self_update") as update_mock:
                            agent.apply_management(
                                {
                                    "desired_bond_sha256": "deadbeef",
                                    "bond_runtime": {
                                        "bind_host": "0.0.0.0",
                                        "listen_port": 9000,
                                        "token_secret": "secret",
                                        "forward_ids": [26],
                                    },
                                }
                            )

            update_mock.assert_called_once()
            start_script = (state_dir / "start-sidecar.sh").read_text(encoding="utf-8")
            self.assertIn("export LEIKWAN_BOND_FORWARD_ID='26'", start_script)

    def test_sidecar_mptcp_policy_removes_stale_point_local_endpoints(self) -> None:
        recorded = []
        endpoint_show_outputs = iter(
            [
                "\n".join(
                    [
                        "10.203.103.115 id 31 signal dev lw-sc-3-0",
                        "10.203.103.119 id 32 signal dev lw-sc-3-1",
                        "10.203.103.127 id 33 signal dev lw-sc-3-2",
                        "10.203.103.123 id 35 signal dev lw-sc-3-4",
                        "10.203.82.27 id 36 signal dev if96",
                    ]
                ),
                "\n".join(
                    [
                        "10.203.103.115 id 31 signal dev lw-sc-3-0",
                        "10.203.103.119 id 32 signal dev lw-sc-3-1",
                        "10.203.103.127 id 33 signal dev lw-sc-3-2",
                        "10.203.103.123 id 35 signal dev lw-sc-3-4",
                    ]
                ),
            ]
        )

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts[:4] == ["ip", "mptcp", "limits", "set"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:2] == ["sysctl", "-w"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts == ["ip", "mptcp", "endpoint", "show"]:
                return SimpleNamespace(returncode=0, stdout=next(endpoint_show_outputs), stderr="")
            if parts == ["ip", "link", "show", "dev", "lw-sc-3-3"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:5] == ["ip", "mptcp", "endpoint", "delete", "id"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if parts[:4] == ["ip", "mptcp", "endpoint", "add"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            raise AssertionError(f"unexpected command: {parts}")

        with mock.patch.object(leikwan_mptcp_sidecar.os, "name", "posix"):
            with mock.patch.object(leikwan_mptcp_sidecar, "shutil_which", return_value="/sbin/ip"):
                with mock.patch.object(leikwan_mptcp_sidecar, "run_command", side_effect=fake_run_command):
                    leikwan_mptcp_sidecar.configure_mptcp_policy(
                        {
                            "manage_mptcp_endpoints": True,
                            "subflow_limit": 5,
                            "mptcp_endpoint_id_base": 30,
                            "mptcp_endpoints": [
                                {"address": "10.203.103.115", "dev": "lw-sc-3-0", "signal": True},
                                {"address": "10.203.103.119", "dev": "lw-sc-3-1", "signal": True},
                                {"address": "10.203.103.127", "dev": "lw-sc-3-2", "signal": True},
                                {"address": "10.203.103.111", "dev": "lw-sc-3-3", "signal": True},
                                {"address": "10.203.103.123", "dev": "lw-sc-3-4", "signal": True},
                            ],
                        }
                    )

        flattened = [" ".join(parts) for parts in recorded]
        self.assertTrue(any("endpoint delete id 36" in line for line in flattened))
        self.assertTrue(any("endpoint add 10.203.103.111 id 34 dev lw-sc-3-3 signal" in line for line in flattened))

    def test_sidecar_cleanup_is_scoped_to_point_prefix(self) -> None:
        recorded = []

        def fake_run_command(parts, check=False):
            recorded.append(parts)
            if parts == ["wg", "show", "interfaces"]:
                return SimpleNamespace(returncode=0, stdout="lw-sc-2-0 lw-sc-3-0 lw-sc-3-1", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(leikwan_mptcp_sidecar.os, "name", "posix"):
            with mock.patch.object(leikwan_mptcp_sidecar, "shutil_which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(leikwan_mptcp_sidecar, "run_command", side_effect=fake_run_command):
                    with mock.patch.object(leikwan_mptcp_sidecar.pathlib.Path, "exists", return_value=False):
                        leikwan_mptcp_sidecar.cleanup_stale_transit_interfaces(
                            {"underlays": [{"interface_name": "lw-sc-3-0"}, {"interface_name": "lw-sc-3-1"}]},
                            {"lw-sc-3-0", "lw-sc-3-1"},
                        )

        flattened = [" ".join(parts) for parts in recorded]
        self.assertFalse(any("lw-sc-2-0" in line for line in flattened))

    def test_stop_forward_deletes_mptcp_underlay_runtime_artifacts(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            state_dir.mkdir(parents=True, exist_ok=True)
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "bond_bin": "/usr/local/bin/leikwan-bond",
                        "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)

            service_path = pathlib.Path("/etc/systemd/system/leikwan-client-19.service")
            start_path = pathlib.Path("/usr/local/bin/leikwan-client-start-19.sh")
            config_json_path = pathlib.Path("/etc/leikwan/mptcp-forward-19.json")
            extra_path = pathlib.Path("/tmp/leikwan-extra-managed")
            for path in [service_path, start_path, config_json_path, extra_path]:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("x", encoding="utf-8")

            with mock.patch.object(agent, "load_applied", return_value={
                "19": {
                    "managed_paths": [str(config_json_path), str(extra_path)],
                }
            }):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(client_agent_module.subprocess, "run"):
                        with mock.patch.object(agent, "cleanup_mptcp_forward_runtime") as cleanup_mock:
                            agent.stop_forward(19, delete_files=True)

            cleanup_mock.assert_called_once_with(19)
            self.assertFalse(service_path.exists())
            self.assertFalse(start_path.exists())
            self.assertFalse(config_json_path.exists())
            self.assertFalse(extra_path.exists())

    def test_cleanup_orphan_mptcp_runtime_removes_deleted_forward_interfaces(self) -> None:
        commands = []
        deleted_paths = []

        def fake_run(command, shell=False, text=False, capture_output=False, check=False):
            commands.append(command)
            if command == ["wg", "show", "interfaces"]:
                return SimpleNamespace(returncode=0, stdout="lw-mptcp-19-0 lw-mptcp-19-1 lw-mptcp-24-0", stderr="")
            if isinstance(command, str) and "systemctl list-units" in command:
                return SimpleNamespace(returncode=0, stdout="leikwan-uot-lw-mptcp-19-0-101.service\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        def fake_exists(path_self):
            return str(path_self) in {
                "/etc/wireguard/lw-mptcp-19-0.conf",
                "/etc/wireguard/lw-mptcp-19-1.conf",
                "/etc/systemd/system",
            }

        def fake_glob(path_self, pattern):
            if str(path_self) == "/etc/systemd/system" and pattern == "leikwan-uot-lw-mptcp-19-*.service":
                return [pathlib.Path("/etc/systemd/system/leikwan-uot-lw-mptcp-19-0-101.service")]
            return []

        def fake_unlink(path_self):
            deleted_paths.append(str(path_self))

        with mock.patch.object(client_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run):
                with mock.patch.object(client_agent_module.pathlib.Path, "exists", fake_exists):
                    with mock.patch.object(client_agent_module.pathlib.Path, "glob", fake_glob):
                        with mock.patch.object(client_agent_module.pathlib.Path, "unlink", fake_unlink):
                            client_agent_module.ClientAgent.cleanup_orphan_mptcp_runtime({24})

        command_strings = [command if isinstance(command, str) else " ".join(command) for command in commands]
        self.assertTrue(any("wg-quick@lw-mptcp-19-0.service" in command for command in command_strings))
        self.assertTrue(any("wg-quick@lw-mptcp-19-1.service" in command for command in command_strings))
        self.assertFalse(any("wg-quick@lw-mptcp-24-0.service" in command for command in command_strings))

    def test_cleanup_orphan_mptcp_runtime_removes_deleted_cfwg_and_cfsr_interfaces(self) -> None:
        commands = []
        deleted_paths = []

        def fake_run(command, shell=False, text=False, capture_output=False, check=False):
            commands.append(command)
            if command == ["wg", "show", "interfaces"]:
                return SimpleNamespace(returncode=0, stdout="lw-cfwg-59 lw-cfsr-59 lw-cfwg-24 lw-mptcp-24-0", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        def fake_exists(path_self):
            return str(path_self) in {
                "/etc/wireguard/lw-cfwg-59.conf",
                "/etc/wireguard/lw-cfsr-59.conf",
                "/etc/systemd/system",
            }

        def fake_glob(path_self, pattern):
            return []

        def fake_unlink(path_self):
            deleted_paths.append(str(path_self))

        with mock.patch.object(client_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run):
                with mock.patch.object(client_agent_module.pathlib.Path, "exists", fake_exists):
                    with mock.patch.object(client_agent_module.pathlib.Path, "glob", fake_glob):
                        with mock.patch.object(client_agent_module.pathlib.Path, "unlink", fake_unlink):
                            client_agent_module.ClientAgent.cleanup_orphan_mptcp_runtime({24})

        command_strings = [command if isinstance(command, str) else " ".join(command) for command in commands]
        self.assertTrue(any("wg-quick@lw-cfwg-59.service" in command for command in command_strings))
        self.assertTrue(any("wg-quick@lw-cfsr-59.service" in command for command in command_strings))
        self.assertFalse(any("wg-quick@lw-cfwg-24.service" in command for command in command_strings))

    def test_apply_forward_cleans_mptcp_runtime_when_switching_to_bond(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)

            def fake_render_forward_runtime(_forward_arg, _agent_config):
                return {
                    "service_name": "leikwan-client-59",
                    "service_path": str(pathlib.Path(tmp_dir) / "leikwan-client-59.service"),
                    "service_unit": "[Unit]\nDescription=Bond\n",
                    "start_script_path": str(pathlib.Path(tmp_dir) / "leikwan-client-start-59.sh"),
                    "start_script": "#!/usr/bin/env bash\n",
                    "files": [],
                }

            previous = {
                "59": {
                    "runtime_mode": "mptcp",
                    "rendered_signature": "old-rendered-signature",
                    "contract_signature": "old-contract-signature",
                    "units": [],
                    "managed_paths": [],
                }
            }
            forward = {
                "forward_id": 59,
                "listen_port": 32501,
                "target": "38.207.137.131:32501",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "566ff12a38fd8869",
                "transport": "tcp+udp",
                "mode": "bond",
                "service_status": "active",
                "endpoints": [{"host": "111.229.217.7", "port": 7000}],
                "bonding": {"ingress_mode": "plain_tcp_mux", "route_key": "566ff12a38fd8869"},
            }

            with mock.patch.dict(sys.modules, {"install_forward_runtime": types.SimpleNamespace(render_forward_runtime=fake_render_forward_runtime)}):
                with mock.patch.object(agent, "load_applied", return_value=previous):
                    with mock.patch.object(agent, "systemd_is_active", return_value=False):
                        with mock.patch.object(agent, "delete_nft_rules"):
                            with mock.patch.object(agent, "add_nft_rules"):
                                with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                                    with mock.patch.object(client_agent_module.subprocess, "run"):
                                        with mock.patch.object(agent, "cleanup_mptcp_forward_runtime") as cleanup_mock:
                                            agent.apply_forward(forward)

            cleanup_mock.assert_called_once_with(59)

    def test_apply_forward_cleans_stale_mptcp_interfaces_when_already_recorded_as_bond(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)

            service_path = pathlib.Path(tmp_dir) / "leikwan-client-59.service"
            start_path = pathlib.Path(tmp_dir) / "leikwan-client-start-59.sh"
            service_path.write_text("[Unit]\nDescription=Bond\n", encoding="utf-8")
            start_path.write_text("#!/usr/bin/env bash\n", encoding="utf-8")

            def fake_render_forward_runtime(_forward_arg, _agent_config):
                return {
                    "service_name": "leikwan-client-59",
                    "service_path": str(service_path),
                    "service_unit": "[Unit]\nDescription=Bond\n",
                    "start_script_path": str(start_path),
                    "start_script": "#!/usr/bin/env bash\n",
                    "files": [],
                }

            previous = {
                "59": {
                    "runtime_mode": "bond",
                    "published_mode": "bond",
                    "rendered_signature": "same-rendered-signature",
                    "contract_signature": "old-contract-signature",
                    "units": [
                        {
                            "service_name": "leikwan-client-59",
                            "managed_paths": [str(service_path), str(start_path)],
                        }
                    ],
                    "managed_paths": [str(service_path), str(start_path)],
                }
            }
            forward = {
                "forward_id": 59,
                "listen_port": 32501,
                "target": "38.207.137.131:32501",
                "sni": "nrt1.nbwly.com",
                "path_prefix": "566ff12a38fd8869",
                "transport": "tcp+udp",
                "mode": "bond",
                "service_status": "active",
                "endpoints": [{"host": "111.229.217.7", "port": 7000}],
                "bonding": {"ingress_mode": "plain_tcp_mux", "route_key": "566ff12a38fd8869"},
            }

            commands = []

            def fake_run(command, shell=False, text=False, capture_output=False, check=False):
                commands.append(command)
                if command == ["wg", "show", "interfaces"]:
                    return SimpleNamespace(returncode=0, stdout="lw-cfsr-59 lw-mptcp-59-0", stderr="")
                return SimpleNamespace(returncode=0, stdout="", stderr="")

            with mock.patch.dict(sys.modules, {"install_forward_runtime": types.SimpleNamespace(render_forward_runtime=fake_render_forward_runtime)}):
                with mock.patch.object(client_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                    with mock.patch.object(agent, "load_applied", return_value=previous):
                        with mock.patch.object(agent, "systemd_is_active", return_value=True):
                            with mock.patch.object(agent, "delete_nft_rules"):
                                with mock.patch.object(agent, "add_nft_rules"):
                                    with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                                        with mock.patch.object(agent, "rendered_runtime_signature", return_value="same-rendered-signature"):
                                            with mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run):
                                                agent.apply_forward(forward)

            command_strings = [command if isinstance(command, str) else " ".join(command) for command in commands]
            self.assertTrue(any("wg-quick@lw-cfsr-59.service" in command for command in command_strings))
            self.assertTrue(any("wg-quick@lw-mptcp-59-0.service" in command for command in command_strings))

    def test_reconcile_global_mptcp_state_uses_max_subflow_limit(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
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
            agent = client_agent_module.ClientAgent(config_path)

            recorded = []

            def fake_run(command, shell=False, text=False, capture_output=False, check=False):
                recorded.append(command)
                return SimpleNamespace(returncode=0, stdout="", stderr="")

            forwards = [
                {"forward_id": 24, "service_status": "active", "mode": "mptcp", "mptcp": {"subflow_limit": 5}},
                {"forward_id": 25, "service_status": "active", "mode": "mptcp", "mptcp": {"subflow_limit": 5}},
            ]

            with mock.patch.object(client_agent_module.os, "name", "posix"):
                with mock.patch.object(client_agent_module.shutil, "which", return_value="/sbin/ip"):
                    with mock.patch.object(client_agent_module.subprocess, "run", side_effect=fake_run):
                        agent.reconcile_global_mptcp_state(forwards)

        command_strings = [command if isinstance(command, str) else " ".join(command) for command in recorded]
        self.assertTrue(any("ip mptcp limits set subflow 5 add_addr_accepted 5" in command for command in command_strings))
        self.assertFalse(any("subflow 10" in command for command in command_strings))


if __name__ == "__main__":
    unittest.main()
