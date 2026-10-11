import hashlib
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]


class ReleasePreflightTests(unittest.TestCase):
    def test_actual_embedded_guard_rejects_mismatched_releases(self):
        source = (ROOT / 'install/client-agent-update.sh').read_text()
        guard = source.split("<<'PYGUARD'\n", 1)[1].split('\nPYGUARD', 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            script, agent, manifest = [root / name for name in ['guard.py', 'agent.py', 'manifest.json']]
            script.write_text(guard)
            data = b'AGENT_VERSION = "2026.09.11.2"\n'
            agent.write_bytes(data)
            digest = hashlib.sha256(data).hexdigest()
            for version, sha, token, expected in [
                ('2026.09.11.2', digest, 'explicit', 0),
                ('2026.09.11.2', digest, 'auto-release:agent=2026.09.11.2', 0),
                ('old', digest, 'explicit', 1),
                ('2026.09.11.2', '0' * 64, 'explicit', 1),
                ('2026.09.11.2', digest, 'auto-release:agent=old', 1),
                ('2026.09.11.2', digest, 'auto-release:agent_sha256=' + '0' * 64, 1),
            ]:
                manifest.write_text(json.dumps({'client_agent': {'version': version, 'sha256': sha}}))
                result = subprocess.run([sys.executable, str(script), str(manifest), str(agent), token], capture_output=True)
                self.assertEqual(result.returncode, expected, result.stderr.decode())


if __name__ == '__main__':
    unittest.main()
