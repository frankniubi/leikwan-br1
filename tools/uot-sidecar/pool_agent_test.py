import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('pool_agent', Path(__file__).with_name('pool-agent.py'))
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class PoolAgentTests(unittest.TestCase):
    def test_https_required(self):
        with self.assertRaises(ValueError):
            agent.request({'panel_url': 'http://example.test'}, 'config')

    def test_failed_probe_reports_unhealthy(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            config = {'forwards': {}}
            path.write_bytes((json.dumps(config, sort_keys=True, indent=2) + '\n').encode())
            document = {'config': config, 'config_hash': 'a' * 64}
            with patch.object(agent, 'request', side_effect=[document, {}]) as request, \
                 patch.object(agent.subprocess, 'run') as run:
                run.return_value.returncode = 0
                self.assertFalse(agent.run_once({'config_path': str(path)}))
                self.assertFalse(request.call_args.args[2]['healthy'])

    def test_validation_failure_preserves_config(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            path.write_bytes(b'original')
            with patch.object(agent, 'request', side_effect=[{'config': {}, 'config_hash': 'a' * 64}, {}]), \
                 patch.object(agent.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, 'check')):
                with self.assertRaises(subprocess.CalledProcessError):
                    agent.run_once({'config_path': str(path)})
            self.assertEqual(path.read_bytes(), b'original')
            self.assertEqual(len(list(path.parent.iterdir())), 1)

    @unittest.skipUnless(os.environ.get('UOT_TEST_BINARY'), 'set UOT_TEST_BINARY to built sidecar')
    def test_real_legacy_protocol_echo(self):
        with tempfile.TemporaryDirectory() as directory:
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            config = {'listen_host': '127.0.0.1', 'listen_port': port,
                      'keepalive_seconds': 0.2,
                      'forwards': {'17': {'token': 'integration-test-token', 'targets': []}}}
            path = Path(directory) / 'config.json'
            path.write_text(json.dumps(config))
            process = subprocess.Popen([os.environ['UOT_TEST_BINARY'], '--config', str(path)],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                for _ in range(30):
                    try:
                        if agent.probe(config):
                            break
                    except OSError:
                        time.sleep(0.1)
                else:
                    self.fail('real UOT2 UDP echo failed')
                wrong = dict(config, forwards={'17': {'token': 'wrong'}})
                self.assertFalse(agent.probe(wrong))
                # Legacy HELLO without new capabilities still receives KEEPALIVE.
                sid = b'k' * 16
                with socket.create_connection(('127.0.0.1', port), timeout=3) as conn:
                    conn.settimeout(3)
                    hello = {'forward_id': 17, 'token': 'integration-test-token',
                             'target': '127.0.0.1:9', 'frame_payload_max': 1200}
                    conn.sendall(agent.wire(1, sid, json.dumps(hello).encode()))
                    self.assertEqual(agent.read_frame(conn)[0], 2)
                    self.assertEqual(agent.read_frame(conn)[:2], (6, sid))
                    conn.sendall(agent.wire(6, sid))
                    self.assertEqual(agent.read_frame(conn)[:2], (6, sid))
                    conn.sendall(agent.wire(5, sid))
            finally:
                process.terminate()
                process.wait(timeout=10)

    def test_failed_restart_rolls_back(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.json'
            path.write_bytes(b'original')
            with patch.object(agent, 'request', side_effect=[{'config': {}, 'config_hash': 'a' * 64}, {}]), \
                 patch.object(agent.subprocess, 'run', side_effect=[None, subprocess.CalledProcessError(1, 'restart'), None]) as run:
                with self.assertRaises(subprocess.CalledProcessError):
                    agent.run_once({'config_path': str(path)})
                self.assertEqual(run.call_count, 3)
            self.assertEqual(path.read_bytes(), b'original')
            self.assertEqual(path.with_suffix('.json.pool-backup').read_bytes(), b'original')


if __name__ == '__main__':
    unittest.main()
