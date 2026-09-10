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

slave_agent = load_module('slave_agent_donation_under_test', 'slave-agent/slave_agent.py')

class DonationSelfCheckPayloadTests(unittest.TestCase):
    def test_heartbeat_payload_includes_donation_self_check_from_policy_metadata(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            cfg = pathlib.Path(tmp) / 'config.json'
            cfg.write_text(json.dumps({'master_url':'https://panel.example','node_token':'token','state_dir':str(pathlib.Path(tmp)/'state'), 'systemd_dir': str(pathlib.Path(tmp)/'systemd')}), encoding='utf-8')
            agent = slave_agent.SlaveAgent(cfg)
            agent.ensure_wireguard_identity = lambda: 'pub'
            agent.cpu_usage_hint = lambda: 0.0
            agent.memory_usage_percent = lambda: 0.0
            agent.disk_usage_percent = lambda: 0.0
            agent.time_sync_state = lambda: {'status': 'unknown'}
            agent.active_service_count = lambda: 1
            metadata = {
                'service_name': 'svc', 'forward_id': 47, 'endpoint_id': 316,
                'listen_port': 24443, 'target': '127.0.0.1:8899', 'transport_mode': 'tcp+udp',
                'donation_policy': {'forward_combination_id': 12, 'desired_latency_ms': 60, 'donation_min_open_ports': 1, 'donor_client_id': 44, 'desktop_client_session_id': 123, 'device_id': 'dev-1'},
                'cf_mode': {'dial_host': '162.159.39.5', 'dial_port': 443, 'sni_host': 'nrt.example', 'path_prefix': 'abc'},
            }
            agent.save_service_metadata('svc', metadata)
            agent.probe_donation_chain_latency_ms = lambda m: 33.3
            agent.probe_donation_open_ports = lambda m: [{'protocol': 'tcp', 'port': 24443, 'ok': True}]
            payload = agent.build_heartbeat_payload()
            self.assertIn('donation_self_check', payload)
            self.assertEqual(12, payload['donation_self_check'][0]['forward_combination_id'])
            self.assertEqual(44, payload['donation_self_check'][0]['donor_client_id'])
            self.assertEqual(123, payload['donation_self_check'][0]['desktop_client_session_id'])
            self.assertEqual('dev-1', payload['donation_self_check'][0]['device_id'])
            self.assertEqual(33.3, payload['donation_self_check'][0]['measured_chain_latency_ms'])

if __name__ == '__main__':
    unittest.main()
