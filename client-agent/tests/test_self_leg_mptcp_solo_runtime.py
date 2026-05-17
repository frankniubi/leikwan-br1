import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, REPO_ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent = load_module("client_agent_solo_under_test", "client-agent/client_agent.py")
install_runtime = load_module("install_forward_runtime_solo_under_test", "client-agent/install_forward_runtime.py")


class SelfLegMptcpSoloTests(unittest.TestCase):
    def test_self_leg_alone_runs_mptcp_when_solo_allowed(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        family = client_agent.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "self_leg_mptcp_solo_allowed": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "cf_direct_wss",
                    },
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("mptcp", family)

    def test_renderer_caps_subflow_to_one_for_solo_self_leg(self) -> None:
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "/tokyo",
            "sni": "nrt.example.test",
            "mptcp": {"tcp_session_token": "tcp-token", "sidecar_host": "127.0.0.1", "sidecar_port": 8898, "subflow_limit": 4},
            "candidate_pool": [
                {
                    "candidate_kind": "self_leg",
                    "selected_primary": True,
                    "self_leg_mptcp_solo_allowed": True,
                    "runtime_capabilities": ["bond", "mptcp"],
                    "probe_transport": "cf_direct_wss",
                    "dial_host": "nrt.example.test",
                    "dial_connect_ip": "104.16.0.3",
                    "dial_port": 2053,
                    "path_prefix": "/tokyo",
                    "cf_sni_host": "nrt.example.test",
                    "target_sidecar_port": 8899,
                }
            ],
        }
        rendered = install_runtime.render_forward_runtime(forward, {})
        payload = rendered["files"][0]["content"]
        self.assertIn('"subflow_limit": 1', payload)
        self.assertIn('"direct_legs"', payload)


if __name__ == "__main__":
    unittest.main()