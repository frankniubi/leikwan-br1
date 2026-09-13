import importlib.util
import pathlib
import unittest

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


if __name__ == '__main__':
    unittest.main()
