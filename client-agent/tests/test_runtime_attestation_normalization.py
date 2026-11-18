import contextlib
import hashlib
import importlib.util
import json
import pathlib
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('attestation_agent', ROOT / 'client-agent/client_agent.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RuntimeAttestationNormalizationTests(unittest.TestCase):
    def test_runtime_that_omits_the_optional_flags_is_forwarded(self):
        """The Go runtime drops debugger/instrumented when they are false.

        Requiring a bool here rejected every healthy runtime, so the panel never
        received a fingerprint from an agent-managed host at all.
        """
        result = module._normalize_runtime_attestation({
            'fingerprint': '2026.09.13.3.452c8fe8',
            'self_sha256': 'a' * 64,
        })
        self.assertIsNotNone(result)
        self.assertEqual('2026.09.13.3.452c8fe8', result['fingerprint'])
        self.assertEqual('a' * 64, result['self_sha256'])
        self.assertIsNone(result['debugger'])
        self.assertIsNone(result['instrumented'])

    def test_explicit_flags_survive(self):
        result = module._normalize_runtime_attestation({
            'fingerprint': '2026.09.13.3.452c8fe8',
            'self_sha256': 'b' * 64,
            'debugger': True,
            'instrumented': False,
        })
        self.assertIsNotNone(result)
        self.assertTrue(result['debugger'])
        self.assertFalse(result['instrumented'])

    def test_malformed_input_is_still_dropped(self):
        valid = '2026.09.13.3.452c8fe8'
        self.assertIsNone(module._normalize_runtime_attestation(None))
        self.assertIsNone(module._normalize_runtime_attestation({'fingerprint': valid}))
        self.assertIsNone(module._normalize_runtime_attestation({'fingerprint': valid, 'self_sha256': 'nope'}))
        self.assertIsNone(module._normalize_runtime_attestation({'fingerprint': 'not a fingerprint', 'self_sha256': 'c' * 64}))
        self.assertIsNone(module._normalize_runtime_attestation({
            'fingerprint': valid, 'self_sha256': 'c' * 64, 'debugger': 'yes',
        }))


class InstalledRuntimeIdentityTests(unittest.TestCase):
    fingerprint = '2026.11.13.1.1ed417036c60'

    def make_agent(self, path):
        agent = object.__new__(module.ClientAgent)
        agent.config = {'ultra_bin': str(path), 'agent_token': 'test-token'}
        agent.latest_forwards = []
        agent.systemd_main_pid = mock.Mock(return_value=0)
        return agent

    def test_reads_build_stamp_and_file_hash_and_caches_until_replaced(self):
        with tempfile.TemporaryDirectory() as td:
            path = pathlib.Path(td) / 'ultra-client'
            path.write_bytes(b'first-runtime')
            agent = self.make_agent(path)
            result = subprocess.CompletedProcess([], 0, self.fingerprint + '\n', '')
            with mock.patch.object(module.subprocess, 'run', return_value=result) as run:
                first = agent.installed_ultra_runtime_identity()
                self.assertEqual(self.fingerprint, first['fingerprint'])
                self.assertEqual(hashlib.sha256(b'first-runtime').hexdigest(), first['self_sha256'])
                first['fingerprint'] = 'tampered'
                self.assertEqual(self.fingerprint, agent.installed_ultra_runtime_identity()['fingerprint'])
                run.assert_called_once_with([str(path), '--version'], text=True, capture_output=True, check=False, timeout=5)
                path.write_bytes(b'new-runtime-with-different-size')
                self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), agent.installed_ultra_runtime_identity()['self_sha256'])
                self.assertEqual(2, run.call_count)

    def test_missing_invalid_failing_and_timed_out_binary_are_not_reported(self):
        with tempfile.TemporaryDirectory() as td:
            path = pathlib.Path(td) / 'ultra-client'
            agent = self.make_agent(path)
            with mock.patch.object(module.subprocess, 'run') as run:
                self.assertIsNone(agent.installed_ultra_runtime_identity())
                run.assert_not_called()
            path.write_bytes(b'runtime')
            cases = [subprocess.CompletedProcess([], 0, 'unknown', ''),
                     subprocess.CompletedProcess([], 1, self.fingerprint, ''),
                     subprocess.TimeoutExpired([], 5), OSError('cannot execute')]
            for result in cases:
                agent = self.make_agent(path)
                kwargs = {'side_effect': result} if isinstance(result, Exception) else {'return_value': result}
                with self.subTest(result=result), mock.patch.object(module.subprocess, 'run', **kwargs) as run, \
                     mock.patch.object(module.time, 'monotonic', side_effect=[100, 101, 701]):
                    self.assertIsNone(agent.installed_ultra_runtime_identity())
                    self.assertIsNone(agent.installed_ultra_runtime_identity())
                    self.assertEqual(1, run.call_count)
                    self.assertIsNone(agent.installed_ultra_runtime_identity())
                    self.assertEqual(2, run.call_count)

    def test_running_runtime_wins_and_never_borrows_installed_identity(self):
        agent = self.make_agent('unused')
        agent.systemd_main_pid.return_value = 123
        agent.installed_ultra_runtime_identity = mock.Mock(side_effect=AssertionError('borrowed installed identity'))
        agent.latest_forwards = [{'forward_id': 7, 'backend_mode': 'ultra'}]
        old = {'fingerprint': '2026.11.10.1.abcdef12', 'self_sha256': 'a' * 64}
        self.assertEqual(old['fingerprint'], agent.heartbeat_runtime_attestation([
            {'forward_id': 7, 'runtime_attestation': old},
        ])['fingerprint'])
        self.assertEqual({}, agent.heartbeat_runtime_attestation([{'forward_id': 7}]))
        self.assertEqual({}, agent.heartbeat_runtime_attestation([]))

    def test_dead_runtime_status_cannot_reintroduce_old_identity(self):
        agent = self.make_agent('unused')
        current = {'fingerprint': self.fingerprint, 'self_sha256': 'b' * 64}
        agent.installed_ultra_runtime_identity = mock.Mock(return_value=current)
        statuses = [{'forward_id': 7, 'runtime_attestation': {
            'fingerprint': '2026.11.10.1.abcdef12', 'self_sha256': 'a' * 64,
        }}]
        self.assertEqual(current, agent.heartbeat_runtime_attestation(statuses))
        self.assertNotIn('runtime_attestation', statuses[0])

    def test_sync_heartbeat_repairs_stale_session_before_next_lease(self):
        agent = self.make_agent('unused')
        agent.missing_forward_observations = {}
        current = {'fingerprint': self.fingerprint, 'self_sha256': 'b' * 64}
        agent.installed_ultra_runtime_identity = mock.Mock(return_value=current)
        stored = {'fingerprint': '2026.11.10.1.abcdef12'}
        outcomes = []

        def lease(*args, **kwargs):
            outcomes.append(stored['fingerprint'] == self.fingerprint)
            if not outcomes[-1]:
                raise RuntimeError('http 409 runtime_version_too_old')
            return []

        def heartbeat(method, route, token, payload):
            self.assertEqual('/api/client/heartbeat', route)
            stored.update(payload['runtime_attestation'])
            return {'ok': True}

        with contextlib.ExitStack() as stack:
            stack.enter_context(mock.patch.object(agent, 'pull_client_configs', return_value=(
                {'forwards': [], 'client': {}}, {}, 'https://panel.example',
            )))
            stack.enter_context(mock.patch.object(module, 'resolve_ultra_leases', side_effect=lease))
            stack.enter_context(mock.patch.object(module, 'collect_ultra_status', return_value=[]))
            stack.enter_context(mock.patch.object(agent, 'control_plane_request', side_effect=heartbeat))
            for name, value in (
                ('ultra_selection_mode', 'auto'), ('device_id', 'device'), ('lease_device_key', None),
                ('apply_management_state', None), ('consume_local_refresh_requests', set()),
                ('load_applied', {}), ('write_applied_state', None), ('prune_orphan_forward_units', None),
                ('reconcile_global_mptcp_state', None), ('schedule_debug_log_upload', None),
                ('run_diagnostics_if_requested', None), ('collect_usage_totals', {'bytes_in': 0, 'bytes_out': 0}),
                ('ensure_wireguard_identity', ''), ('time_sync_state', {}),
                ('helper_artifact_hashes', {key: '' for key in ('install_forward_runtime_sha256', 'mptcp_client_sha256', 'wstunnel_sha256', 'cfwg_watchdog_sha256')}),
                ('running_kernel_supports_mptcp', False), ('mptcp_capability', {}), ('update_convergence_summary', {}),
                ('wireguard_listen_port', 0), ('wireguard_fallback_tcp_port', 0),
                ('apply_ultra_actions', None), ('commit_usage_state', None), ('save_pull_state', None),
                ('panel_clock_state_from_headers', {}), ('load_bond_leg_reputation_snapshots', []),
            ):
                stack.enter_context(mock.patch.object(agent, name, return_value=value))
            agent.sync_once()
            agent.sync_once()
        self.assertEqual([False, True], outcomes)
        self.assertEqual(current, stored)


if __name__ == '__main__':
    unittest.main()
