import importlib.util
import json
import pathlib
import re
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class ArtifactVersionsConsistencyTests(unittest.TestCase):
    def test_client_agent_artifact_version_matches_declared_agent_version_and_hash(self) -> None:
        agent_path = REPO_ROOT / "client-agent" / "client_agent.py"
        versions_path = REPO_ROOT / "artifacts" / "versions.json"

        text = agent_path.read_text(encoding="utf-8")
        match = re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', text)
        self.assertIsNotNone(match)
        declared_version = match.group(1)

        versions = json.loads(versions_path.read_text(encoding="utf-8"))
        client_agent = versions["client_agent"]

        self.assertEqual(declared_version, client_agent["version"])

        import hashlib
        digest = hashlib.sha256(agent_path.read_bytes()).hexdigest()
        self.assertEqual(digest, client_agent["sha256"])
        self.assertEqual(agent_path.stat().st_size, client_agent["size_bytes"])


    def test_client_agent_installer_artifact_hash_is_current(self) -> None:
        installer_path = REPO_ROOT / "install" / "install-client-agent.sh"
        versions_path = REPO_ROOT / "artifacts" / "versions.json"

        import hashlib
        versions = json.loads(versions_path.read_text(encoding="utf-8"))
        client_agent_installer = versions["client_agent_installer"]

        digest = hashlib.sha256(installer_path.read_bytes()).hexdigest()
        self.assertEqual(digest, client_agent_installer["sha256"])
        self.assertEqual(installer_path.stat().st_size, client_agent_installer["size_bytes"])

    def test_cfwg_latency_watchdog_artifact_hash_is_current(self) -> None:
        watchdog_path = REPO_ROOT / "client-agent" / "cfwg_latency_watchdog.py"
        versions_path = REPO_ROOT / "artifacts" / "versions.json"

        import hashlib
        versions = json.loads(versions_path.read_text(encoding="utf-8"))
        cfwg_watchdog = versions["cfwg_latency_watchdog"]

        self.assertEqual("client-agent/cfwg_latency_watchdog.py", cfwg_watchdog["path"])
        digest = hashlib.sha256(watchdog_path.read_bytes()).hexdigest()
        self.assertEqual(digest, cfwg_watchdog["sha256"])
        self.assertEqual(watchdog_path.stat().st_size, cfwg_watchdog["size_bytes"])


if __name__ == "__main__":
    unittest.main()
