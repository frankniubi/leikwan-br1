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

client_agent = load_module('client_agent_donor_under_test', 'client-agent/client_agent.py')
install_runtime = load_module('install_runtime_donor_under_test', 'client-agent/install_forward_runtime.py')

class DonatedSlaveLegTests(unittest.TestCase):
    def test_donated_slave_leg_sorts_and_filters_like_rented_helper(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        selected = client_agent.ClientAgent.select_forward_candidates(agent, {
            'max_legs_used': 2,
            'candidate_pool': [
                {'candidate_kind': 'rented_group_leg', 'endpoint_id': 1, 'measured_rtt_ms': 50, 'runtime_capabilities': ['bond', 'mptcp'], 'ready': True},
                {'candidate_kind': 'donated_slave_leg', 'endpoint_id': 9001, 'donor_id': 9, 'measured_rtt_ms': 20, 'runtime_capabilities': ['bond', 'mptcp'], 'ready': True},
                {'candidate_kind': 'rented_group_leg', 'endpoint_id': 2, 'measured_rtt_ms': 30, 'runtime_capabilities': ['bond', 'mptcp'], 'ready': True},
            ],
        })
        self.assertEqual(['donated_slave_leg', 'rented_group_leg'], [item['candidate_kind'] for item in selected])
        self.assertTrue(selected[0]['selected_primary'])
        capable = client_agent.ClientAgent.filter_mptcp_capable_candidates(selected)
        self.assertEqual(2, len(capable))

    def test_latency_escalation_and_runtime_cap_count_donated_helpers(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        forward = {
            'desired_latency_ms': 30,
            'helper_group_cap': 2,
            'candidate_pool': [
                {'candidate_kind': 'self_leg', 'selected_primary': True, 'measured_rtt_ms': 80, 'ready': True},
                {'candidate_kind': 'donated_slave_leg', 'endpoint_id': 5, 'measured_rtt_ms': 10, 'ready': True},
                {'candidate_kind': 'rented_group_leg', 'endpoint_id': 6, 'measured_rtt_ms': 20, 'ready': True},
            ],
            'mptcp': {'subflow_limit': 8, 'mptcp_endpoints': [{'endpoint_id': i} for i in range(4)]},
            'max_legs_used': 2,
        }
        escalated = client_agent.ClientAgent.apply_latency_target_candidate_escalation(agent, forward)
        active = [item for item in escalated['candidate_pool'] if item.get('active_helper')]
        self.assertEqual(['donated_slave_leg', 'rented_group_leg'], [item['candidate_kind'] for item in active])
        rendered = install_runtime.render_mptcp_runtime_config(forward)
        self.assertEqual(2, rendered['subflow_limit'])
        self.assertEqual(2, len(rendered['mptcp_endpoints']))

    def test_donor_log_decoration(self) -> None:
        self.assertEqual('donor=true', client_agent.ClientAgent.donor_log_label({'candidate_kind': 'donated_slave_leg'}))
        self.assertEqual('donor=false', client_agent.ClientAgent.donor_log_label({'candidate_kind': 'rented_group_leg'}))

if __name__ == '__main__':
    unittest.main()
