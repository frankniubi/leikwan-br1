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


client_agent_module = load_module("client_agent_runtime_pref_under_test", "client-agent/client_agent.py")


class RuntimePreferenceTests(unittest.TestCase):
    def test_self_leg_primary_forces_bond_family(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {"candidate_kind": "self_leg", "selected_primary": True, "runtime_capabilities": ["bond"]},
                    {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("bond", family)

    def test_rented_only_candidates_prefer_mptcp(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {"candidate_kind": "rented_group_leg", "selected_primary": True, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("mptcp", family)


if __name__ == "__main__":
    unittest.main()
