import hashlib
import json
import pathlib
import re
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class SlaveArtifactConsistencyTests(unittest.TestCase):
    def test_downloadable_slave_agent_artifact_matches_source_and_manifest(self) -> None:
        source_path = REPO_ROOT / "slave-agent" / "slave_agent.py"
        artifact_path = REPO_ROOT / "artifacts" / "slave-agent.py"
        versions_path = REPO_ROOT / "artifacts" / "versions.json"

        source_bytes = source_path.read_bytes()
        artifact_bytes = artifact_path.read_bytes()
        self.assertEqual(
            source_bytes,
            artifact_bytes,
            "artifacts/slave-agent.py must be regenerated whenever slave-agent/slave_agent.py changes",
        )

        text = source_bytes.decode("utf-8")
        match = re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', text)
        self.assertIsNotNone(match)
        declared_version = match.group(1)

        versions = json.loads(versions_path.read_text(encoding="utf-8"))
        slave_agent = versions["slave_agent"]
        digest = hashlib.sha256(source_bytes).hexdigest()

        self.assertEqual(declared_version, slave_agent["version"])
        self.assertEqual(digest, slave_agent["sha256"])
        self.assertEqual(len(source_bytes), slave_agent["size_bytes"])
        self.assertEqual(len(source_bytes), slave_agent["size"])


if __name__ == "__main__":
    unittest.main()
