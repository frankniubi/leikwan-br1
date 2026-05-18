import importlib.util
import pathlib
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module('client_agent_self_leg_best_ip_under_test', 'client-agent/client_agent.py')
install_runtime = load_module('install_forward_runtime_self_leg_best_ip_under_test', 'client-agent/install_forward_runtime.py')


class SelfLegBestIpOverrideTests(unittest.TestCase):
    def test_client_agent_prefers_forward_specific_self_leg_best_ip_map(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            state_dir = pathlib.Path(tmp_dir)
            best_map = state_dir / 'self_leg_cf_best_ips.json'
            best_map.write_text('{"47": {"07289d1f5ed25399": ["162.159.39.4", "162.159.39.1"]}}\n', encoding='utf-8')
            env_path = state_dir / 'best_ip.env'
            env_path.write_text('CURRENT_BEST_IP=162.159.39.3\n', encoding='utf-8')
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.config = {
                'state_dir': str(state_dir),
                'self_leg_best_ip_env': str(env_path),
            }
            resolved = client_agent_module.ClientAgent.resolve_self_leg_connect_ip(
                agent,
                {
                    'candidate_kind': 'self_leg',
                    'probe_transport': 'cf_direct_wss',
                    'dial_host': 'm-jp-4.scipnet.info',
                    'path_prefix': '07289d1f5ed25399',
                },
                {'forward_id': 47},
            )
        self.assertEqual('162.159.39.4', resolved)

    def test_forward_specific_map_overrides_panel_explicit_self_leg_ip(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            state_dir = pathlib.Path(tmp_dir)
            best_map = state_dir / 'self_leg_cf_best_ips.json'
            best_map.write_text('{"47": {"07289d1f5ed25399": ["162.159.39.4"]}}\n', encoding='utf-8')
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.config = {'state_dir': str(state_dir)}
            resolved = client_agent_module.ClientAgent.resolve_self_leg_connect_ip(
                agent,
                {
                    'candidate_kind': 'self_leg',
                    'probe_transport': 'cf_direct_wss',
                    'dial_host': 'm-jp-4.scipnet.info',
                    'dial_connect_ip': '104.16.0.3',
                    'path_prefix': '07289d1f5ed25399',
                },
                {'forward_id': 47},
            )
        self.assertEqual('162.159.39.4', resolved)


    def test_self_leg_probe_rejects_late_websocket_520_after_local_listener_opens(self) -> None:
        class FakeSocket:
            def __enter__(self):
                return self
            def __exit__(self, exc_type, exc, tb):
                return False
            def bind(self, address):
                pass
            def getsockname(self):
                return ("127.0.0.1", 45123)

        class FakeProbeSocket:
            def __enter__(self):
                return self
            def __exit__(self, exc_type, exc, tb):
                return False
            def settimeout(self, timeout):
                pass
            def sendall(self, payload):
                pass
            def recv(self, size):
                raise TimeoutError("no data")

        class FakeProcess:
            last = None
            returncode = None
            def __init__(self, *args, **kwargs):
                self.stderr = kwargs.get("stderr")
                FakeProcess.last = self
                if self.stderr is not None:
                    self.stderr.write("Starting TCP server listening cnx on 127.0.0.1:45123\n")
                    self.stderr.flush()
            def poll(self):
                return None
            def emit_520(self):
                if self.stderr is not None:
                    self.stderr.write("failed to do websocket handshake with the server wss://162.159.39.4:2053\nCaused by:\n    Invalid status code: 520\n")
                    self.stderr.flush()
            def terminate(self):
                pass
            def wait(self, timeout=None):
                return 0

        def emit_520_after_sleep(_seconds):
            if FakeProcess.last is not None:
                FakeProcess.last.emit_520()

        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {"wstunnel_bin": "/usr/local/bin/wstunnel"}
        with mock.patch.object(client_agent_module.pathlib.Path, "exists", return_value=True),              mock.patch.object(client_agent_module.socket, "socket", return_value=FakeSocket()),              mock.patch.object(client_agent_module.socket, "create_connection", return_value=FakeProbeSocket()),              mock.patch.object(client_agent_module.subprocess, "Popen", FakeProcess),              mock.patch.object(client_agent_module.time, "sleep", side_effect=emit_520_after_sleep):
            ok, latency_ms, error = client_agent_module.ClientAgent.probe_self_leg_websocket_path(
                agent,
                "162.159.39.4",
                2053,
                "nrt1.nbwly.com",
                "07289d1f5ed25399",
                8899,
                timeout=1.0,
            )
        self.assertFalse(ok)
        self.assertIsNone(latency_ms)
        self.assertIn("520", str(error))


    def test_self_leg_probe_does_not_send_application_bytes_before_declaring_wstunnel_path_valid(self) -> None:
        class FakeSocket:
            def __enter__(self):
                return self
            def __exit__(self, exc_type, exc, tb):
                return False
            def bind(self, address):
                pass
            def getsockname(self):
                return ("127.0.0.1", 45124)

        class FakeProbeSocket:
            sent_payloads = []
            recv_calls = 0
            def __enter__(self):
                return self
            def __exit__(self, exc_type, exc, tb):
                return False
            def settimeout(self, timeout):
                pass
            def sendall(self, payload):
                FakeProbeSocket.sent_payloads.append(payload)
            def recv(self, size):
                FakeProbeSocket.recv_calls += 1
                raise TimeoutError("no data")

        class FakeProcess:
            returncode = None
            def __init__(self, *args, **kwargs):
                pass
            def poll(self):
                return None
            def terminate(self):
                pass
            def wait(self, timeout=None):
                return 0

        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {"wstunnel_bin": "/usr/local/bin/wstunnel"}
        with mock.patch.object(client_agent_module.pathlib.Path, "exists", return_value=True),              mock.patch.object(client_agent_module.socket, "socket", return_value=FakeSocket()),              mock.patch.object(client_agent_module.socket, "create_connection", return_value=FakeProbeSocket()),              mock.patch.object(client_agent_module.subprocess, "Popen", FakeProcess):
            ok, latency_ms, error = client_agent_module.ClientAgent.probe_self_leg_websocket_path(
                agent,
                "162.159.39.4",
                2053,
                "nrt1.nbwly.com",
                "07289d1f5ed25399",
                8899,
                timeout=1.0,
            )
        self.assertTrue(ok)
        self.assertIsNotNone(latency_ms)
        self.assertIsNone(error)
        self.assertEqual([], FakeProbeSocket.sent_payloads)

    def test_client_agent_reads_current_best_ip_for_hostname_self_leg(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            env_path = pathlib.Path(tmp_dir) / 'best_ip.env'
            env_path.write_text('CURRENT_BEST_IP=162.159.39.3\n', encoding='utf-8')
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.config = {'self_leg_best_ip_env': str(env_path)}
            resolved = client_agent_module.ClientAgent.resolve_self_leg_connect_ip(
                agent,
                {
                    'candidate_kind': 'self_leg',
                    'probe_transport': 'cf_direct_wss',
                    'dial_host': 'm-jp-4.scipnet.info',
                },
            )
        self.assertEqual('162.159.39.3', resolved)

    def test_self_leg_probe_uses_curl_connect_time_for_latency_metric(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        candidate = {
            'candidate_kind': 'self_leg',
            'probe_transport': 'cf_direct_wss',
            'dial_host': 'm-jp-4.scipnet.info',
            'dial_connect_ip': '162.159.39.3',
            'dial_port': 8443,
            'cf_sni_host': 'm-jp-4.scipnet.info',
            'path_prefix': '07289d1f5ed25399',
        }
        with mock.patch.object(client_agent_module.shutil, 'which', return_value='/usr/bin/curl'), \
             mock.patch.object(client_agent_module.subprocess, 'run', return_value=SimpleNamespace(stdout='200 0.050599 0.108543', stderr='', returncode=0)):
            ok, latency_ms, error = client_agent_module.ClientAgent.self_leg_direct_cf_connect_probe(agent, {'forward_id': 47}, candidate, timeout=3.0)
        self.assertTrue(ok)
        self.assertEqual(50.6, latency_ms)
        self.assertIsNone(error)

    def test_selected_cf_wg_self_leg_carries_best_map_connect_ip_for_runtime_render(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            state_dir = pathlib.Path(tmp_dir)
            (state_dir / 'self_leg_cf_best_ips.json').write_text(
                '{"47": {"07289d1f5ed25399": ["162.159.39.5", "162.159.39.4"]}}\n',
                encoding='utf-8',
            )
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.config = {'state_dir': str(state_dir)}
            agent.private_wg_interface_present = lambda _interface_name: True
            agent.local_listener_present = lambda _port, _transport='tcp', _timeout=2.5: (True, 0.2, '')
            agent.private_wg_self_leg_connect_probe = lambda _forward, _candidate, timeout=3.0: (True, 40.0, None)
            selected = client_agent_module.ClientAgent.select_forward_candidates(
                agent,
                {
                    'forward_id': 47,
                    'path_prefix': '07289d1f5ed25399',
                    'candidate_pool': [
                        {
                            'candidate_kind': 'self_leg',
                            'probe_transport': 'cf_wg',
                            'dial_host': 'nrt1.nbwly.com',
                            'dial_port': 2053,
                            'cf_sni_host': 'nrt1.nbwly.com',
                            'path_prefix': '07289d1f5ed25399',
                            'runtime_capabilities': ['bond', 'mptcp', 'wireguard'],
                            'self_leg_cf_candidates': ['162.159.39.4', '104.16.0.3'],
                            'self_leg_wg': {
                                'enabled': True,
                                'outer_transport': 'wstunnel_udp',
                                'interface_name': 'lw-cfwg-47',
                                'sidecar_address': '10.204.53.42',
                            },
                            'target_sidecar_mptcp_port': 8898,
                        },
                    ],
                },
            )
        self.assertEqual('self_leg', selected[0]['candidate_kind'])
        self.assertEqual('162.159.39.5', selected[0].get('dial_connect_ip'))

    def test_selected_self_leg_carries_resolved_connect_ip_for_runtime_render(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            env_path = pathlib.Path(tmp_dir) / 'best_ip.env'
            env_path.write_text('CURRENT_BEST_IP=162.159.39.3\n', encoding='utf-8')
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.config = {'state_dir': str(pathlib.Path(tmp_dir)), 'self_leg_best_ip_env': str(env_path)}
            agent.load_bond_endpoint_health = lambda: {'47': {'318': {'one_leg_rtt_ms': 68.9}}}
            agent.self_leg_direct_cf_connect_probe = lambda forward, candidate, timeout=3.0: (True, 30.5, None)
            selected = client_agent_module.ClientAgent.select_forward_candidates(
                agent,
                {
                    'forward_id': 47,
                    'candidate_pool': [
                        {
                            'candidate_kind': 'self_leg',
                            'probe_transport': 'cf_direct_wss',
                            'dial_host': 'm-jp-4.scipnet.info',
                            'dial_port': 8443,
                            'cf_sni_host': 'm-jp-4.scipnet.info',
                            'path_prefix': '07289d1f5ed25399',
                            'runtime_capabilities': ['bond', 'mptcp'],
                        },
                        {
                            'candidate_kind': 'rented_group_leg',
                            'endpoint_id': 318,
                            'host': '101.133.142.65',
                            'port': 7000,
                            'runtime_capabilities': ['bond', 'mptcp'],
                        },
                    ],
                },
            )
        self.assertEqual('self_leg', selected[0]['candidate_kind'])
        self.assertEqual('162.159.39.3', selected[0].get('dial_connect_ip'))

    def test_self_leg_probe_uses_real_wstunnel_path_probe_when_target_sidecar_port_present(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            fake_wstunnel = pathlib.Path(tmp_dir) / 'wstunnel'
            fake_wstunnel.write_text('', encoding='utf-8')
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.config = {'wstunnel_bin': str(fake_wstunnel)}
            candidate = {
                'candidate_kind': 'self_leg',
                'probe_transport': 'cf_direct_wss',
                'dial_host': 'm-jp-4.scipnet.info',
                'dial_connect_ip': '104.16.0.3',
                'dial_port': 2053,
                'cf_sni_host': 'nrt1.nbwly.com',
                'path_prefix': '07289d1f5ed25399',
                'target_sidecar_port': 8899,
            }
            with mock.patch.object(client_agent_module.ClientAgent, 'probe_self_leg_websocket_path', return_value=(True, 41.2, None)) as probe_mock:
                ok, latency_ms, error = client_agent_module.ClientAgent.self_leg_direct_cf_connect_probe(agent, {'forward_id': 47}, candidate, timeout=3.0)
        self.assertTrue(ok)
        self.assertEqual(41.2, latency_ms)
        self.assertIsNone(error)
        probe_mock.assert_called_once()


    def test_self_leg_bond_probe_rejects_local_listener_without_session_established(self) -> None:
        class FakeSocket:
            next_port = 45130

            def __init__(self, *args, **kwargs):
                self.port = FakeSocket.next_port
                FakeSocket.next_port += 1

            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def bind(self, address):
                pass

            def getsockname(self):
                return ("127.0.0.1", self.port)

        class FakeProbeSocket:
            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

        class FakeProcess:
            returncode = None

            def __init__(self, command, stdout=None, stderr=None, text=None):
                self.command = command
                self.log = stderr or stdout
                if self.log is not None:
                    if "wstunnel" in str(command[0]):
                        self.log.write("Starting TCP server listening cnx on 127.0.0.1:45130\n")
                    else:
                        self.log.write("bond client listening on 127.0.0.1:45131\n")
                    self.log.flush()

            def poll(self):
                return None

            def terminate(self):
                pass

            def wait(self, timeout=None):
                return 0

            def kill(self):
                pass

        now = [100.0]

        def fake_time():
            now[0] += 0.25
            return now[0]

        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {"wstunnel_bin": "/usr/local/bin/wstunnel", "bond_bin": "/usr/local/bin/leikwan-bond"}

        with mock.patch.object(client_agent_module.pathlib.Path, "exists", return_value=True), \
             mock.patch.object(client_agent_module.socket, "socket", side_effect=lambda *args, **kwargs: FakeSocket()), \
             mock.patch.object(client_agent_module.socket, "create_connection", return_value=FakeProbeSocket()), \
             mock.patch.object(client_agent_module.subprocess, "Popen", FakeProcess), \
             mock.patch.object(client_agent_module.time, "time", side_effect=fake_time), \
             mock.patch.object(client_agent_module.time, "sleep", return_value=None):
            ok, latency_ms, error = client_agent_module.ClientAgent.probe_self_leg_bond_path(
                agent,
                "162.159.39.4",
                2053,
                "nrt1.nbwly.com",
                "07289d1f5ed25399",
                8899,
                "tcp-token",
                {"policy_mode": "latency_first"},
                timeout=0.2,
            )

        self.assertFalse(ok)
        self.assertIsNone(latency_ms)
        self.assertIsNotNone(error)


    def test_self_leg_probe_uses_bond_handshake_when_bond_token_is_available(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        agent.self_leg_candidate_edge_latency_ms = lambda ip, port, sni_host, timeout=3.0: None
        candidate = {
            'candidate_kind': 'self_leg',
            'probe_transport': 'cf_direct_wss',
            'dial_host': 'nrt1.nbwly.com',
            'dial_port': 2053,
            'cf_sni_host': 'nrt1.nbwly.com',
            'path_prefix': '07289d1f5ed25399',
            'target_sidecar_port': 8899,
            'target_sidecar_bond_port': 8899,
            'self_leg_cf_candidates': ['162.159.39.4', '162.159.39.8'],
        }
        calls = []

        def fake_bond_probe(ip, port, sni_host, path_prefix, target_port, session_token, bonding, timeout=3.0):
            calls.append((ip, target_port, session_token))
            table = {
                '162.159.39.4': (False, None, 'Invalid status code: 520'),
                '162.159.39.8': (True, 31.5, None),
            }
            return table[ip]

        agent.probe_self_leg_bond_path = fake_bond_probe
        agent.probe_self_leg_websocket_path = mock.Mock(side_effect=AssertionError('websocket-only probe should not decide bond self-leg'))

        ok, latency_ms, error = client_agent_module.ClientAgent.self_leg_direct_cf_connect_probe(
            agent,
            {'forward_id': 47, 'bonding': {'session_token': 'tcp-token'}},
            candidate,
            timeout=3.0,
        )

        self.assertTrue(ok)
        self.assertEqual(31.5, latency_ms)
        self.assertIsNone(error)
        self.assertEqual('162.159.39.8', candidate.get('dial_connect_ip'))
        self.assertEqual([('162.159.39.4', 8899, 'tcp-token'), ('162.159.39.8', 8899, 'tcp-token')], calls)


    def test_client_agent_selects_best_ip_from_panel_published_self_leg_candidates(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        agent.self_leg_candidate_edge_latency_ms = lambda ip, port, sni_host, timeout=3.0: None
        candidate = {
            'candidate_kind': 'self_leg',
            'probe_transport': 'cf_direct_wss',
            'dial_host': 'm-jp-4.scipnet.info',
            'dial_port': 2053,
            'cf_sni_host': 'nrt1.nbwly.com',
            'path_prefix': '07289d1f5ed25399',
            'target_sidecar_port': 8899,
            'self_leg_cf_candidates': ['162.159.39.4', '162.159.39.1', '104.16.0.3'],
        }
        calls = []
        def fake_probe(ip, port, sni_host, path_prefix, target_port, timeout=3.0):
            calls.append(ip)
            table = {
                '162.159.39.4': (True, 48.0, None),
                '162.159.39.1': (True, 52.0, None),
                '104.16.0.3': (True, 101.0, None),
            }
            return table[ip]
        agent.probe_self_leg_websocket_path = fake_probe
        ok, latency_ms, error = client_agent_module.ClientAgent.self_leg_direct_cf_connect_probe(agent, {'forward_id': 47}, candidate, timeout=3.0)
        self.assertTrue(ok)
        self.assertEqual(48.0, latency_ms)
        self.assertIsNone(error)
        self.assertEqual('162.159.39.4', candidate.get('dial_connect_ip'))
        self.assertEqual(['162.159.39.4', '162.159.39.1', '104.16.0.3'], calls)


    def test_self_leg_probe_expands_panel_published_cidrs_locally(self) -> None:
        from unittest import mock

        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        agent.self_leg_candidate_edge_latency_ms = lambda ip, port, sni_host, timeout=3.0: None
        candidate = {
            'candidate_kind': 'self_leg',
            'probe_transport': 'cf_direct_wss',
            'dial_host': 'm-jp-4.scipnet.info',
            'dial_port': 2053,
            'cf_sni_host': 'nrt1.nbwly.com',
            'path_prefix': '07289d1f5ed25399',
            'target_sidecar_port': 8899,
            'self_leg_cf_candidates': ['104.16.0.3'],
            'self_leg_cf_cidrs': ['162.159.39.0/24', '104.16.0.0/13'],
            'self_leg_cf_max_candidates': 4,
        }
        calls = []
        def fake_probe(ip, port, sni_host, path_prefix, target_port, timeout=3.0):
            calls.append(ip)
            table = {
                '104.16.0.3': (True, 101.0, None),
                '162.159.39.4': (True, 48.0, None),
                '162.159.39.1': (True, 52.0, None),
            }
            return table[ip]
        agent.probe_self_leg_websocket_path = fake_probe
        with mock.patch.object(client_agent_module.ClientAgent, 'expand_self_leg_cf_candidate_ips', return_value=['162.159.39.4', '162.159.39.1']) as expand_mock:
            ok, latency_ms, error = client_agent_module.ClientAgent.self_leg_direct_cf_connect_probe(agent, {'forward_id': 47}, candidate, timeout=3.0)
        self.assertTrue(ok)
        self.assertEqual(48.0, latency_ms)
        self.assertIsNone(error)
        self.assertEqual('162.159.39.4', candidate.get('dial_connect_ip'))
        self.assertEqual(['104.16.0.3', '162.159.39.4', '162.159.39.1'], calls)
        expand_mock.assert_called_once()


    def test_self_leg_probe_prefers_lowest_edge_latency_among_working_candidates(self) -> None:
        from unittest import mock

        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        candidate = {
            'candidate_kind': 'self_leg',
            'probe_transport': 'cf_direct_wss',
            'dial_host': 'm-jp-4.scipnet.info',
            'dial_port': 2053,
            'cf_sni_host': 'nrt1.nbwly.com',
            'path_prefix': '07289d1f5ed25399',
            'target_sidecar_port': 8899,
            'self_leg_cf_candidates': ['104.16.0.3', '162.159.39.4', '162.159.39.1'],
        }
        with mock.patch.object(client_agent_module.ClientAgent, 'self_leg_candidate_edge_latency_ms', side_effect=[101.0, 30.1, 52.0]),              mock.patch.object(client_agent_module.ClientAgent, 'probe_self_leg_websocket_path', side_effect=[(True, 150.0, None), (True, 150.0, None), (True, 150.0, None)]):
            ok, latency_ms, error = client_agent_module.ClientAgent.self_leg_direct_cf_connect_probe(agent, {'forward_id': 47}, candidate, timeout=3.0)
        self.assertTrue(ok)
        self.assertEqual(30.1, latency_ms)
        self.assertIsNone(error)
        self.assertEqual('162.159.39.4', candidate.get('dial_connect_ip'))


    def test_self_leg_probe_ranks_successful_websocket_candidates_by_edge_latency_not_backend_wait_noise(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        candidate = {
            'candidate_kind': 'self_leg',
            'probe_transport': 'cf_direct_wss',
            'dial_host': 'm-jp-4.scipnet.info',
            'dial_port': 2053,
            'cf_sni_host': 'nrt1.nbwly.com',
            'path_prefix': '07289d1f5ed25399',
            'target_sidecar_port': 8899,
            'self_leg_cf_candidates': ['104.16.0.3', '162.159.39.8', '162.159.39.4'],
        }
        websocket_latencies = {
            '104.16.0.3': (True, 1603.1, None),
            '162.159.39.8': (True, 1604.0, None),
            '162.159.39.4': (True, 1603.5, None),
        }
        edge_latencies = {
            '104.16.0.3': 171.3,
            '162.159.39.8': 30.7,
            '162.159.39.4': 31.8,
        }
        agent.probe_self_leg_websocket_path = lambda ip, port, sni_host, path_prefix, target_port, timeout=3.0: websocket_latencies[ip]
        agent.self_leg_candidate_edge_latency_ms = lambda ip, port, sni_host, timeout=3.0: edge_latencies[ip]

        ok, latency_ms, error = client_agent_module.ClientAgent.self_leg_direct_cf_connect_probe(agent, {'forward_id': 47}, candidate, timeout=3.0)

        self.assertTrue(ok)
        self.assertEqual(30.7, latency_ms)
        self.assertIsNone(error)
        self.assertEqual('162.159.39.8', candidate.get('dial_connect_ip'))


    def test_same_agent_reprobes_self_leg_instead_of_reusing_stale_bad_probe(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        agent.load_bond_endpoint_health = lambda: {'47': {'318': {'one_leg_rtt_ms': 68.9}}}
        forward = {
            'forward_id': 47,
            'candidate_pool': [
                {
                    'candidate_kind': 'self_leg',
                    'probe_transport': 'cf_direct_wss',
                    'dial_host': 'nrt1.nbwly.com',
                    'dial_port': 2053,
                    'cf_sni_host': 'nrt1.nbwly.com',
                    'path_prefix': '07289d1f5ed25399',
                    'target_sidecar_port': 8899,
                    'self_leg_cf_candidates': ['162.159.39.29'],
                    'runtime_capabilities': ['bond', 'mptcp'],
                },
                {
                    'candidate_kind': 'rented_group_leg',
                    'endpoint_id': 318,
                    'host': '10.203.201.187',
                    'port': 7000,
                    'runtime_capabilities': ['bond', 'mptcp'],
                },
            ],
        }
        probe_results = iter([(True, 150.7, None), (True, 30.0, None)])
        calls = []

        def fake_probe(ip, port, sni_host, path_prefix, target_port, timeout=3.0):
            calls.append(ip)
            return next(probe_results)

        agent.probe_self_leg_websocket_path = fake_probe
        first = client_agent_module.ClientAgent.select_forward_candidates(agent, forward)
        second = client_agent_module.ClientAgent.select_forward_candidates(agent, forward)

        self.assertEqual('rented_group_leg', first[0]['candidate_kind'])
        self.assertEqual('self_leg', second[0]['candidate_kind'])
        self.assertEqual(30.0, second[0].get('measured_rtt_ms'))
        self.assertEqual(['162.159.39.29', '162.159.39.29'], calls)


    def test_same_agent_reuses_recent_fast_self_leg_probe_to_avoid_render_churn(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        agent.config = {}
        agent.load_bond_endpoint_health = lambda: {'47': {'318': {'one_leg_rtt_ms': 68.9}}}
        forward = {
            'forward_id': 47,
            'candidate_pool': [
                {
                    'candidate_kind': 'self_leg',
                    'probe_transport': 'cf_direct_wss',
                    'dial_host': 'nrt1.nbwly.com',
                    'dial_port': 2053,
                    'cf_sni_host': 'nrt1.nbwly.com',
                    'path_prefix': '07289d1f5ed25399',
                    'target_sidecar_port': 8899,
                    'self_leg_cf_candidates': ['162.159.39.6'],
                    'runtime_capabilities': ['bond', 'mptcp'],
                },
                {
                    'candidate_kind': 'rented_group_leg',
                    'endpoint_id': 318,
                    'host': '10.203.201.187',
                    'port': 7000,
                    'runtime_capabilities': ['bond', 'mptcp'],
                },
            ],
        }
        calls = []

        def fake_probe(ip, port, sni_host, path_prefix, target_port, timeout=3.0):
            calls.append(ip)
            return (True, 31.0, None)

        agent.probe_self_leg_websocket_path = fake_probe
        first = client_agent_module.ClientAgent.select_forward_candidates(agent, forward)
        second = client_agent_module.ClientAgent.select_forward_candidates(agent, forward)

        self.assertEqual('self_leg', first[0]['candidate_kind'])
        self.assertEqual('self_leg', second[0]['candidate_kind'])
        self.assertEqual('162.159.39.6', first[0].get('dial_connect_ip'))
        self.assertEqual('162.159.39.6', second[0].get('dial_connect_ip'))
        self.assertEqual(31.0, second[0].get('measured_rtt_ms'))
        self.assertEqual(['162.159.39.6'], calls)

    def test_render_mptcp_self_leg_prefers_explicit_connect_ip_over_hostname(self) -> None:
        rendered = install_runtime.render_forward_runtime(
            {
                'forward_id': 47,
                'listen_port': 11035,
                'target': '185.220.238.143:5201',
                'sni': 'm-jp-4.scipnet.info',
                'path_prefix': '07289d1f5ed25399',
                'transport': 'tcp',
                'mode': 'mptcp',
                'backend_mode': 'cf_mode',
                'endpoints': [
                    {'endpoint_id': 320, 'host': '8.133.170.123', 'port': 7000},
                ],
                'candidate_pool': [
                    {
                        'candidate_kind': 'self_leg',
                        'selected_primary': True,
                        'runtime_capabilities': ['bond', 'mptcp'],
                        'dial_host': 'm-jp-4.scipnet.info',
                        'dial_connect_ip': '162.159.39.3',
                        'dial_port': 8443,
                        'cf_sni_host': 'm-jp-4.scipnet.info',
                        'path_prefix': '07289d1f5ed25399',
                    }
                ],
                'mptcp': {
                    'sidecar_host': '10.203.201.183',
                    'sidecar_port': 8898,
                    'tcp_session_token': 'tcp-token',
                    'udp_session_token': 'udp-token',
                    'subflow_limit': 2,
                    'mptcp_endpoints': [
                        {'endpoint_id': 320, 'address': '10.203.201.181', 'dev': 'lw-mptcp-47-0', 'subflow': True},
                    ],
                    'underlays': [
                        {'interface_name': 'lw-mptcp-47-0', 'legs': [{'endpoint_id': 320, 'local_address': '10.203.201.181', 'mptcp_remote_address': '10.203.201.183'}]},
                    ],
                },
            },
            {
                'mptcp_client_bin': '/usr/local/bin/leikwan-mptcp-client.py',
                'state_dir': '/var/lib/leikwan-client-agent',
                'wireguard_listen_port': 43047,
            },
        )

        script = rendered['start_script']
        self.assertIn('wss://162.159.39.3:8443', script)
        self.assertNotIn('wss://m-jp-4.scipnet.info:8443', script)
        self.assertIn('--tls-sni-override m-jp-4.scipnet.info', script)


if __name__ == '__main__':
    unittest.main()
