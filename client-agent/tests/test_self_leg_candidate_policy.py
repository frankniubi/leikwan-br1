import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_self_leg_under_test", "client-agent/client_agent.py")


class SelfLegCandidatePolicyTests(unittest.TestCase):
    def test_selects_self_leg_as_primary_when_lowest_rtt(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        selected = client_agent_module.ClientAgent.select_forward_candidates(
            agent,
            {
                "candidate_pool": [
                    {"candidate_kind": "self_leg", "device_id": "abc", "measured_rtt_ms": 31.0, "ready": True, "runtime_capabilities": ["bond"]},
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 316, "measured_rtt_ms": 44.0, "ready": True, "runtime_capabilities": ["bond", "mptcp"]},
                ]
            },
        )
        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertTrue(selected[0]["selected_primary"])

    def test_probes_self_leg_and_prefers_it_when_direct_cf_is_lower_latency(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        forward = {
            "forward_id": 47,
            "candidate_pool": [
                {
                    "candidate_kind": "self_leg",
                    "device_id": "abc",
                    "ready": True,
                    "runtime_capabilities": ["bond"],
                    "probe_transport": "cf_direct_wss",
                    "dial_host": "162.159.39.16",
                    "dial_port": 2053,
                    "cf_sni_host": "m-jp-4.scipnet.info",
                    "path_prefix": "07289d1f5ed25399",
                },
                {
                    "candidate_kind": "rented_group_leg",
                    "endpoint_id": 316,
                    "measured_rtt_ms": 82.0,
                    "ready": True,
                    "runtime_capabilities": ["bond", "mptcp"],
                },
            ],
        }
        agent.self_leg_direct_cf_connect_probe = lambda _forward, _candidate, timeout=3.0: (True, 34.0, None)

        selected = client_agent_module.ClientAgent.select_forward_candidates(agent, forward)

        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertEqual(34.0, selected[0]["measured_rtt_ms"])
        self.assertTrue(selected[0]["selected_primary"])

    def test_uses_cached_slave_health_when_rented_candidates_have_no_inline_measurement(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.load_bond_endpoint_health = lambda: {"47": {"316": {"one_leg_rtt_ms": 51.0}}}
        selected = client_agent_module.ClientAgent.select_forward_candidates(
            agent,
            {
                "forward_id": 47,
                "candidate_pool": [
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 316, "ready": True, "runtime_capabilities": ["bond", "mptcp"]},
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 317, "measured_rtt_ms": 83.0, "ready": True, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
        )

        self.assertEqual(316, selected[0]["endpoint_id"])
        self.assertEqual(51.0, selected[0]["measured_rtt_ms"])




    def test_private_wg_self_leg_bootstraps_before_interface_exists(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.private_wg_interface_present = lambda _interface_name: False
        selected = agent.select_forward_candidates(
            {
                "forward_id": 47,
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "probe_transport": "private_wg",
                        "runtime_capabilities": ["bond", "mptcp", "wireguard"],
                        "self_leg_wg": {
                            "enabled": True,
                            "interface_name": "lw-self-47",
                            "sidecar_address": "10.204.47.2",
                            "endpoint_host": "185.220.238.143",
                            "endpoint_port": 14969,
                        },
                        "target_sidecar_mptcp_port": 8898,
                    },
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 320, "measured_rtt_ms": 70.0, "ready": True, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            }
        )

        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertTrue(selected[0]["selected_primary"])
        self.assertEqual("private_wg_bootstrap", selected[0].get("probe_state"))

    def test_private_wg_self_leg_probe_uses_sidecar_private_address(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        probes = []

        def fake_probe(host, port, timeout=3.0):
            probes.append((host, port, timeout))
            return True, 28.4, None

        agent.private_wg_self_leg_probe = fake_probe
        selected = agent.select_forward_candidates(
            {
                "forward_id": 47,
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "probe_transport": "private_wg",
                        "runtime_capabilities": ["bond", "mptcp", "wireguard"],
                        "self_leg_wg": {
                            "enabled": True,
                            "sidecar_address": "10.204.47.2",
                        },
                        "target_sidecar_mptcp_port": 8898,
                    },
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 320, "measured_rtt_ms": 70.0, "ready": True, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            }
        )

        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertEqual(28.4, selected[0]["measured_rtt_ms"])
        self.assertEqual(("10.204.47.2", 8898, 3.0), probes[0])
        self.assertGreaterEqual(len(probes), 1)

    def test_private_wg_self_leg_probe_retries_transient_timeouts_and_keeps_best_rtt(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        calls = []
        results = iter([
            (False, None, "timed out"),
            (True, 57.8, None),
            (True, 55.2, None),
        ])

        def fake_remote_tcp_probe(host, port, timeout=3.0):
            calls.append((host, port, timeout))
            return next(results)

        agent.remote_tcp_probe = fake_remote_tcp_probe
        ok, latency_ms, error = agent.private_wg_self_leg_connect_probe(
            {"forward_id": 47},
            {
                "probe_transport": "private_wg",
                "self_leg_wg": {"enabled": True, "sidecar_address": "10.204.53.42"},
                "target_sidecar_mptcp_port": 8898,
            },
            timeout=3.0,
        )

        self.assertTrue(ok)
        self.assertEqual(55.2, latency_ms)
        self.assertIsNone(error)
        self.assertEqual(3, len(calls))



if __name__ == "__main__":
    unittest.main()
