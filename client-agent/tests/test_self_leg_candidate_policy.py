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


if __name__ == "__main__":
    unittest.main()
