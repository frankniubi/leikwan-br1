import importlib.util
import pathlib
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('ultra_ha_agent', ROOT / 'client-agent/client_agent.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class UltraHAStartupTests(unittest.TestCase):
    def make_agent(self):
        agent = object.__new__(module.ClientAgent)
        agent.load_applied = mock.Mock(return_value={'170': {}})
        agent.applied_forward_definition_is_present = mock.Mock(return_value=True)
        agent.systemd_is_active = mock.Mock(return_value=True)
        agent.applied_runtime_mode = mock.Mock(return_value='ultra')
        agent.service_active_age_seconds = mock.Mock(return_value=14)
        agent.local_runtime_probe = mock.Mock(return_value=(False, None, 'refused'))
        agent.local_listener_present = mock.Mock(return_value=(False, None, 'missing'))
        agent.restart_forward_service = mock.Mock()
        return agent

    def forward(self):
        return {'forward_id': 170, 'backend_mode': 'ultra', 'service_status': 'active',
                'listen_port': 10001, 'mode': 'single', 'transport': 'tcp+udp',
                'endpoints': [], 'ultra_access': {'session_ticket': 'test-ticket'}}

    def test_cold_start_waits_for_lease_without_restart(self):
        agent = self.make_agent()
        agent.ensure_runtime_health([self.forward()])
        agent.restart_forward_service.assert_not_called()
        agent.local_runtime_probe.assert_not_called()

    def test_missing_listener_requires_three_spaced_observations(self):
        agent = self.make_agent()
        agent.service_active_age_seconds.return_value = 120
        with mock.patch.object(module.time, 'monotonic', side_effect=[100, 106, 112]):
            agent.ensure_runtime_health([self.forward()])
            agent.ensure_runtime_health([self.forward()])
            agent.restart_forward_service.assert_not_called()
            agent.ensure_runtime_health([self.forward()])
        agent.restart_forward_service.assert_called_once()

    def test_healthy_listener_clears_failure_streak_without_provider_dial(self):
        agent = self.make_agent()
        agent.service_active_age_seconds.return_value = 120
        with mock.patch.object(module.time, 'monotonic', side_effect=[100, 106, 112]):
            agent.ensure_runtime_health([self.forward()])
            agent.ensure_runtime_health([self.forward()])
            agent.local_listener_present.return_value = (True, None, None)
            agent.ensure_runtime_health([self.forward()])
            agent.local_listener_present.return_value = (False, None, 'missing')
            agent.ensure_runtime_health([self.forward()])
        agent.restart_forward_service.assert_not_called()
        agent.local_runtime_probe.assert_not_called()


if __name__ == '__main__':
    unittest.main()
