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

sidecar_agent = load_module('sidecar_agent_donation_under_test', 'sidecar-agent/sidecar_agent.py')

class DonationReverseProbePayloadTests(unittest.TestCase):
    def test_management_targets_are_probed_and_reported_on_heartbeat(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            cfg = pathlib.Path(tmp) / 'config.json'
            cfg.write_text(json.dumps({'master_url':'https://panel.example','agent_token':'token','state_dir':str(pathlib.Path(tmp)/'state'), 'runtime_config_path': str(pathlib.Path(tmp)/'runtime.json'), 'service_name': 'sc'}), encoding='utf-8')
            agent = sidecar_agent.SidecarAgent(cfg)
            target = {'forward_combination_id': 12, 'donor_id': 55, 'client_id': 44, 'forward_id': 47, 'protocol': 'tcp', 'public_host': 'donor.example', 'public_port': 24443}
            agent.apply_management({'donation_reverse_probe_targets': [target]})
            agent.probe_donation_target = lambda t: {'ok': True, 'latency_ms': 22.2, 'checked_at': '2026-05-18T00:00:00Z'}
            payload = agent.build_heartbeat_payload()
            self.assertIn('donation_reverse_probe', payload)
            self.assertEqual(55, payload['donation_reverse_probe'][0]['donor_id'])
            self.assertTrue(payload['donation_reverse_probe'][0]['ok'])

if __name__ == '__main__':
    unittest.main()
