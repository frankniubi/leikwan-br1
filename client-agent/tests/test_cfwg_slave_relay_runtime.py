"""Tests for cfwg slave-relayed WG underlay rendering.

When self_leg_wg.slave_relay is configured, the client creates a second WG interface
(lw-cfsr-*) whose wstunnel WSS goes through the slave's CF mux on a different path,
providing a stable backup when the direct CF route has periodic loss bursts.
"""
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


install_runtime = load_module("install_forward_runtime_slave_relay_under_test", "client-agent/install_forward_runtime.py")
leikwan_mptcp_client = load_module("leikwan_mptcp_client_slave_relay_under_test", "client-agent/leikwan_mptcp_client.py")


class CfwgSlaveRelayTests(unittest.TestCase):
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
                "cf_path_prefix": "index.m3u8",
                "slave_relay": {
                    "enabled": True,
                    "slave_host": "47.116.11.144",
                    "slave_port": 2053,
                    "slave_path_prefix": "Windows-9.1.iso",
                    "slave_sni_host": "nrt1.nbwly.com",
                    "client_address": "10.204.1.1",
                    "sidecar_address": "10.204.1.2",
                    "cf_target_port": 51820,
                },
            },
        }

    def test_slave_relay_disabled_returns_none(self) -> None:
        candidate = self._candidate()
        candidate["self_leg_wg"]["slave_relay"]["enabled"] = False
        forward = {"forward_id": 47}
        result = install_runtime.self_leg_slave_relay_wg_underlay(candidate, forward)
        self.assertIsNone(result)

    def test_slave_relay_missing_returns_none(self) -> None:
        candidate = self._candidate()
        del candidate["self_leg_wg"]["slave_relay"]
        forward = {"forward_id": 47}
        result = install_runtime.self_leg_slave_relay_wg_underlay(candidate, forward)
        self.assertIsNone(result)

    def test_slave_relay_builds_correct_underlay(self) -> None:
        candidate = self._candidate()
        forward = {"forward_id": 47}
        result = install_runtime.self_leg_slave_relay_wg_underlay(candidate, forward)
        self.assertIsNotNone(result)
        self.assertEqual("lw-cfsr-47", result["interface_name"])
        self.assertEqual("client_self_leg_slave_relay", result["mode"])
        self.assertTrue(result["prefer_mptcp_endpoint"])
        self.assertEqual(1, len(result["legs"]))

        leg = result["legs"][0]
        self.assertEqual("wstunnel_udp_via_slave", leg["outer_transport"])
        self.assertEqual("10.204.1.1", leg["local_address"])
        self.assertEqual("10.204.1.2", leg["peer_address"])
        self.assertEqual("10.204.1.2", leg["mptcp_remote_address"])
        self.assertEqual("PUBKEY===", leg["peer_public_key"])
        self.assertEqual("127.0.0.1", leg["peer_public_host"])
        # local_udp_port = 40000 + (47 % 2000) + 1 = 40048
        self.assertEqual(40048, leg["peer_udp_port"])
        self.assertEqual("47.116.11.144", leg["slave_dial_host"])
        self.assertEqual(2053, leg["slave_dial_port"])
        self.assertEqual("nrt1.nbwly.com", leg["slave_sni_host"])
        self.assertEqual("Windows-9.1.iso", leg["slave_path_prefix"])
        self.assertEqual("127.0.0.1", leg["cf_target_host"])
        self.assertEqual(51820, leg["cf_target_port"])

    def test_slave_relay_uses_custom_interface_name(self) -> None:
        candidate = self._candidate()
        candidate["self_leg_wg"]["slave_relay"]["interface_name"] = "lw-relay-47"
        forward = {"forward_id": 47}
        result = install_runtime.self_leg_slave_relay_wg_underlay(candidate, forward)
        self.assertEqual("lw-relay-47", result["interface_name"])

    def test_slave_relay_falls_back_to_primary_addresses(self) -> None:
        candidate = self._candidate()
        del candidate["self_leg_wg"]["slave_relay"]["client_address"]
        del candidate["self_leg_wg"]["slave_relay"]["sidecar_address"]
        forward = {"forward_id": 47}
        result = install_runtime.self_leg_slave_relay_wg_underlay(candidate, forward)
        leg = result["legs"][0]
        # Falls back to parent wg addresses
        self.assertEqual("10.204.0.1", leg["local_address"])
        self.assertEqual("10.204.0.2", leg["peer_address"])

    def test_slave_relay_renders_wstunnel_in_start_script(self) -> None:
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "index.m3u8",
            "sni": "nrt1.nbwly.com",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "127.0.0.1",
                "sidecar_port": 8898,
            },
            "candidate_pool": [self._candidate()],
        }
        rendered = install_runtime.render_forward_runtime(forward, {})
        script = rendered["start_script"]

        # Direct cfwg leg
        self.assertIn("wss://104.16.0.5:443", script)
        self.assertIn('"index.m3u8.cfwg"', script)
        # Slave-relayed cfwg leg
        self.assertIn("wss://47.116.11.144:2053", script)
        self.assertIn('"Windows-9.1.iso"', script)
        slave_line = next(line for line in script.splitlines() if "wss://47.116.11.144:2053" in line)
        self.assertNotIn("--tls-sni-override nrt1.nbwly.com", slave_line)
        self.assertIn('--http-headers "Host: nrt1.nbwly.com"', slave_line)

        # Verify config has both underlays
        config_file = next(f for f in rendered["files"] if f["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        underlays = payload["underlays"]
        self.assertEqual(2, len(underlays))
        self.assertEqual("client_self_leg", underlays[0]["mode"])
        self.assertEqual("lw-cfwg-47", underlays[0]["interface_name"])
        self.assertEqual("client_self_leg_slave_relay", underlays[1]["mode"])
        self.assertEqual("lw-cfsr-47", underlays[1]["interface_name"])

    def test_slave_relay_mptcp_endpoints_include_both_legs(self) -> None:
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "index.m3u8",
            "sni": "nrt1.nbwly.com",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "127.0.0.1",
                "sidecar_port": 8898,
                "mptcp_endpoints": [
                    {"endpoint_id": 317, "address": "10.203.201.177", "dev": "lw-mptcp-47-0", "subflow": True},
                ],
                "underlays": [
                    {
                        "interface_name": "lw-mptcp-47-0",
                        "mode": "slave_transit",
                        "legs": [{"endpoint_id": 317, "local_address": "10.203.201.177", "mptcp_remote_address": "10.203.201.179"}],
                    }
                ],
            },
            "candidate_pool": [self._candidate()],
            "endpoints": [{"endpoint_id": 317, "host": "47.116.104.15", "port": 7000}],
        }
        rendered = install_runtime.render_forward_runtime(forward, {})
        config_file = next(f for f in rendered["files"] if f["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])

        # Direct cfwg primary + slave relay primary + helper as standby
        endpoints = payload["mptcp_endpoints"]
        primary_addresses = [ep["address"] for ep in endpoints if not ep.get("backup_only", False)]
        standby_addresses = [ep["address"] for ep in endpoints if ep.get("backup_only", False)]
        self.assertIn("10.204.0.1", primary_addresses)
        self.assertIn("10.204.1.1", primary_addresses)
        self.assertIn("10.203.201.177", standby_addresses)

    def test_slave_preconnect_does_not_disable_mptcp_underlay_bonding_when_multiple_helpers_render(self) -> None:
        """MPTCP slave-preconnect must still aggregate client->slave WG legs.

        The client connects to one slave preconnect relay as the sidecar-side egress,
        but it should use the rendered WG underlays as application-level bond legs.
        Otherwise traffic only moves on the selected relay interface and MPTCP mode
        cannot aggregate throughput.
        """
        forward = {
            "forward_id": 64,
            "listen_port": 32512,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "speed",
            "sni": "",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "10.203.24.91",
                "sidecar_port": 14969,
                "remote_host": "10.203.24.91",
                "remote_port": 14969,
                "relay_host": "10.203.24.91",
                "relay_port": 14969,
                "slave_relay_host": "10.203.24.90",
                "slave_relay_port": 39087,
                "subflow_limit": 2,
                "mptcp_endpoints": [
                    {"endpoint_id": 630, "address": "10.203.24.89", "dev": "lw-mptcp-64-0", "subflow": True},
                    {"endpoint_id": 631, "address": "10.203.24.93", "dev": "lw-mptcp-64-1", "subflow": True},
                ],
                "underlays": [
                    {
                        "interface_name": "lw-mptcp-64-0",
                        "mode": "slave_transit",
                        "legs": [
                            {
                                "endpoint_id": 630,
                                "local_address": "10.203.24.89",
                                "slave_address": "10.203.24.90",
                                "peer_address": "10.203.24.90",
                                "mptcp_remote_address": "10.203.24.91",
                                "slave_preconnect": True,
                                "preconnect_relay_port": 39087,
                            }
                        ],
                    },
                    {
                        "interface_name": "lw-mptcp-64-1",
                        "mode": "slave_transit",
                        "legs": [
                            {
                                "endpoint_id": 631,
                                "local_address": "10.203.24.93",
                                "slave_address": "10.203.24.94",
                                "peer_address": "10.203.24.94",
                                "mptcp_remote_address": "10.203.24.95",
                                "slave_preconnect": True,
                                "preconnect_relay_port": 39124,
                            }
                        ],
                    },
                ],
            },
        }

        rendered = install_runtime.render_forward_runtime(forward, {
            "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
            "state_dir": "/var/lib/leikwan-client-agent",
            "wireguard_listen_port": 43000,
        })
        config_file = next(f for f in rendered["files"] if f["path"] == "/etc/leikwan/mptcp-forward-64.json")
        payload = json.loads(config_file["content"])

        self.assertEqual("tcp", payload["bond_mode"])
        self.assertEqual(2, len(payload["underlays"]))
        self.assertEqual(2, len(payload["mptcp_endpoints"]))
        self.assertEqual("10.203.24.90", payload["slave_relay_host"])
        self.assertEqual(39087, payload["slave_relay_port"])

    def test_mptcp_slave_transit_renders_wstunnel_without_domain_sni_for_direct_ip(self) -> None:
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "index.m3u8",
            "sni": "nrt1.nbwly.com",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "10.203.201.175",
                "sidecar_port": 8898,
                "slave_relay_host": "10.203.201.174",
                "slave_relay_port": 7598,
                "slave_relay_uot_port": 7599,
                "slave_relays": [
                    {
                        "endpoint_id": 500,
                        "host": "10.203.201.174",
                        "port": 7598,
                        "client_address": "10.203.201.173",
                        "slave_address": "10.203.201.174",
                        "sidecar_address": "10.203.201.175",
                    }
                ],
                "mptcp_endpoints": [
                    {"endpoint_id": 500, "address": "10.203.201.173", "dev": "lw-mptcp-47-0", "subflow": True},
                ],
                "underlays": [
                    {
                        "interface_name": "lw-mptcp-47-0",
                        "mode": "slave_transit",
                        "legs": [
                            {
                                "endpoint_id": 500,
                                "outer_transport": "wstunnel_udp_via_slave",
                                "peer_udp_port": 40283,
                                "local_address": "10.203.201.173",
                                "peer_address": "10.203.201.174",
                                "mptcp_remote_address": "10.203.201.175",
                                "slave_dial_host": "47.116.11.144",
                                "slave_dial_port": 7329,
                                "slave_sni_host": "nrt1.nbwly.com",
                                "slave_path_prefix": "content/81743c72/episode-143.mp4",
                                "cf_target_host": "127.0.0.1",
                                "cf_target_port": 7329,
                            }
                        ],
                    }
                ],
            },
        }
        rendered = install_runtime.render_forward_runtime(forward, {})
        script = rendered["start_script"]
        line = next(line for line in script.splitlines() if "wss://47.116.11.144:7329" in line)
        self.assertIn("udp://127.0.0.1:40283:127.0.0.1:7329", line)
        self.assertNotIn("--tls-sni-override nrt1.nbwly.com", line)
        self.assertIn('--http-headers "Host: nrt1.nbwly.com"', line)
        self.assertIn("/usr/local/bin/leikwan-mptcp-client.py --config /etc/leikwan/mptcp-forward-47.json --setup-underlays", script)
        self.assertLess(script.index("--setup-underlays"), script.index('RUST_RELAY="/var/lib/leikwan-client-agent/leikwan-mptcp-relay"'))
        config_file = next(f for f in rendered["files"] if f["path"] == "/etc/leikwan/mptcp-forward-47.json")
        payload = json.loads(config_file["content"])
        self.assertEqual("10.203.201.174", payload["slave_relay_host"])
        self.assertEqual(7598, payload["slave_relay_port"])
        self.assertEqual(7599, payload["slave_relay_uot_port"])
        self.assertEqual(1, len(payload["slave_relays"]))

    def test_slave_relay_wg_config_uses_localhost_endpoint(self) -> None:
        """Verify the generated WG config points to 127.0.0.1:local_port for the slave relay."""
        candidate = self._candidate()
        forward = {"forward_id": 47}
        underlay = install_runtime.self_leg_slave_relay_wg_underlay(candidate, forward)

        with tempfile.TemporaryDirectory() as tmp_dir:
            private_key = pathlib.Path(tmp_dir) / "private.key"
            private_key.write_text("client-private\n", encoding="utf-8")
            underlay["local_private_key_path"] = str(private_key)
            wg_conf = leikwan_mptcp_client.build_wireguard_interface_config(underlay)

        self.assertIn("Endpoint = 127.0.0.1:40048", wg_conf)
        self.assertIn("10.204.1.1/32", wg_conf)


if __name__ == "__main__":
    unittest.main()
