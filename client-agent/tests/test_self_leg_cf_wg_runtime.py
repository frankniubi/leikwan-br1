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
                "client_address": "10.204.0.1/30",
                "sidecar_address": "10.204.0.2/30",
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


if __name__ == "__main__":
    unittest.main()
