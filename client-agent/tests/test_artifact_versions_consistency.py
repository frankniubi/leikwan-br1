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

    def test_client_agent_artifact_mirror_matches_source(self) -> None:
        agent_path = REPO_ROOT / "client-agent" / "client_agent.py"
        artifact_path = REPO_ROOT / "artifacts" / "client-agent.py"

        self.assertEqual(agent_path.read_bytes(), artifact_path.read_bytes())

    def test_install_forward_runtime_artifact_and_manifest_match_source(self) -> None:
        source_path = REPO_ROOT / "client-agent" / "install_forward_runtime.py"
        artifact_path = REPO_ROOT / "artifacts" / "install-forward-runtime.py"
        versions_path = REPO_ROOT / "artifacts" / "versions.json"

        self.assertEqual(source_path.read_bytes(), artifact_path.read_bytes())

        import hashlib
        versions = json.loads(versions_path.read_text(encoding="utf-8"))
        runtime = versions["install_forward_runtime"]
        self.assertEqual(hashlib.sha256(source_path.read_bytes()).hexdigest(), runtime["sha256"])
        self.assertEqual(source_path.stat().st_size, runtime["size_bytes"])

    def test_pathd_artifact_matches_manifest_and_cargo_version(self) -> None:
        artifact_path = REPO_ROOT / "artifacts" / "wstunnel" / "leikwan-pathd_linux_amd64"
        versions = json.loads((REPO_ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8"))
        entry = versions["leikwan_pathd_linux_amd64"]
        cargo_text = (REPO_ROOT / "wstunnel-main" / "leikwan-bond" / "Cargo.toml").read_text(encoding="utf-8")
        cargo_version = re.search(r'^version\s*=\s*"([^"]+)"', cargo_text, re.MULTILINE)

        self.assertIsNotNone(cargo_version)
        self.assertEqual(cargo_version.group(1), entry["version"])
        self.assertEqual("artifacts/wstunnel/leikwan-pathd_linux_amd64", entry["path"])
        self.assertEqual("/artifacts/wstunnel/leikwan-pathd_linux_amd64", entry["artifact_url"])
        self.assertTrue(artifact_path.is_file())

        import hashlib
        self.assertEqual(hashlib.sha256(artifact_path.read_bytes()).hexdigest(), entry["sha256"])
        self.assertEqual(artifact_path.stat().st_size, entry["size_bytes"])

    def test_slave_agent_artifact_mirror_matches_source_and_manifest(self) -> None:
        source_path = REPO_ROOT / "slave-agent" / "slave_agent.py"
        artifact_path = REPO_ROOT / "artifacts" / "slave-agent.py"
        versions = json.loads((REPO_ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8"))
        source_text = source_path.read_text(encoding="utf-8")
        declared_version = re.search(r'AGENT_VERSION\s*=\s*"([^"]+)"', source_text)

        self.assertIsNotNone(declared_version)
        self.assertEqual(source_path.read_bytes(), artifact_path.read_bytes())
        self.assertEqual(declared_version.group(1), versions["slave_agent"]["version"])


    def test_client_agent_installer_artifact_hash_is_current(self) -> None:
        installer_path = REPO_ROOT / "install" / "install-client-agent.sh"
        versions_path = REPO_ROOT / "artifacts" / "versions.json"

        import hashlib
        versions = json.loads(versions_path.read_text(encoding="utf-8"))
        client_agent_installer = versions["client_agent_installer"]

        text = installer_path.read_text(encoding="utf-8")
        match = re.search(r'INSTALLER_VERSION="([^"]+)"', text)
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1), client_agent_installer["version"])

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
