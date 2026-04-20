import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module

slave_agent = load_module("slave_agent_cf_mode_under_test", "slave-agent/slave_agent.py")


class SlaveCfLocationSelectionTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "node_token": "token-123",
            "state_dir": str(pathlib.Path(tmp_dir) / "state"),
            "systemd_dir": str(pathlib.Path(tmp_dir) / "systemd"),
            "wstunnel_bin": "/usr/local/bin/wstunnel",
            "backend_port_range_start": 24000,
            "backend_port_range_end": 24010,
        }), encoding="utf-8")
        return slave_agent.SlaveAgent(config_path)

    def test_same_group_reuses_selected_ip_across_forwards(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(agent, 'probe_cf_candidates', return_value=[{'ip': '104.16.10.10', 'latency_ms': 18.2, 'jitter_ms': 1.4, 'failures': 0}]):
                first = agent.select_cf_location_ip({
                    'location_group_id': 7,
                    'cidrs': ['104.16.0.0/13'],
                    'probe_mode': 'tcp',
                    'dial_port': 443,
                })
                second = agent.select_cf_location_ip({
                    'location_group_id': 7,
                    'cidrs': ['172.64.0.0/13'],
                    'probe_mode': 'tcp',
                    'dial_port': 443,
                })
            self.assertEqual(first['selected_ip'], '104.16.10.10')
            self.assertEqual(second['selected_ip'], '104.16.10.10')

    def test_render_cf_backend_unit_uses_selected_ip_and_sni(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            unit = agent.render_cf_wss_backend_unit(
                service_name='wstunnel-svc-1-184',
                backend_port=24001,
                target='127.0.0.1:51820',
                selected_ip='104.16.10.10',
                remote_port=443,
                sni_host='nrt.example.com',
                path_prefix='a43547b6350f72cd',
                transport_mode='tcp',
            )
            self.assertIn('wss://104.16.10.10:443', unit)
            self.assertIn('--tls-sni-override nrt.example.com', unit)
            self.assertIn('--http-headers "Host: nrt.example.com"', unit)
            self.assertIn('--http-upgrade-path-prefix "a43547b6350f72cd"', unit)
