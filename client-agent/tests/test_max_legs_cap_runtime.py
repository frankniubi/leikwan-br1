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


install_runtime = load_module("install_forward_runtime_cap_under_test", "client-agent/install_forward_runtime.py")
client_agent = load_module("client_agent_cap_under_test", "client-agent/client_agent.py")


class MaxLegsCapTests(unittest.TestCase):
    def test_subflow_limit_capped_by_max_legs_used(self) -> None:
        forward = {
            "forward_id": 47,
            "max_legs_used": 4,
            "mptcp": {
                "subflow_limit": 8,
                "mptcp_endpoints": [
                    {"endpoint_id": i, "address": f"10.0.0.{i}", "dev": f"lw-mptcp-47-{i}", "subflow": True}
                    for i in range(8)
                ],
            },
        }
        rendered = install_runtime.render_mptcp_runtime_config(forward)
        self.assertLessEqual(int(rendered["subflow_limit"]), 4)
        self.assertLessEqual(len(rendered["mptcp_endpoints"]), 4)

    def test_candidate_selection_capped_after_latency_sort(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        agent.candidate_measured_rtt_ms = lambda forward, candidate, now=None: float(candidate["rtt"])
        ready = client_agent.ClientAgent.select_forward_candidates(
            agent,
            {
                "max_legs_used": 2,
                "candidate_pool": [
                    {"endpoint_id": 1, "rtt": 50},
                    {"endpoint_id": 2, "rtt": 20},
                    {"endpoint_id": 3, "rtt": 30},
                ],
            },
        )
        self.assertEqual([2, 3], [item["endpoint_id"] for item in ready])
        self.assertTrue(ready[0]["selected_primary"])


if __name__ == "__main__":
    unittest.main()