import importlib.util
import pathlib
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('update_unit_agent', ROOT / 'client-agent/client_agent.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class UpdateUnitCollectionTests(unittest.TestCase):
    def test_transient_update_unit_is_collected_when_it_fails(self):
        """A failed transient update unit must not stay in systemd's failed table.

        The update is retried every UPDATE_RETRY_SECONDS while the panel keeps
        the same token, so without --collect one failed unit per retry piled up
        (440 on the canary host) and the failed-unit table became useless.
        """
        agent = object.__new__(module.ClientAgent)
        agent.update_state_path = lambda: pathlib.Path('/var/lib/leikwan-client-agent/agent_update_state.json')
        with mock.patch.object(module.pathlib.Path, 'exists', return_value=True), \
             mock.patch.object(module.shutil, 'which', return_value='/usr/bin/systemd-run'), \
             mock.patch.object(module.subprocess, 'run') as runner:
            agent.request_self_update('auto-release:ultra_runtime=' + 'a' * 64, 'https://example.invalid')

        argv = runner.call_args[0][0]
        self.assertEqual('systemd-run', argv[0])
        self.assertIn('--collect', argv)
        self.assertLess(argv.index('--collect'), argv.index('--unit'))
        self.assertIn('--no-block', argv)
        self.assertTrue(runner.call_args[1].get('check'))


if __name__ == '__main__':
    unittest.main()
