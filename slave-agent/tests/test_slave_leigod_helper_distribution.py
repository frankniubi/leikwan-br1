from __future__ import annotations

import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class SlaveLeigodHelperDistributionTests(unittest.TestCase):
    def test_slave_installer_downloads_relay_atomically(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")
        self.assertIn(
            'download_manifest_artifact leigod_forward_relay '
            '"$ARTIFACT_BASE/artifacts/leikwan-leigod-forward-relay.py" '
            '"$BIN_DIR/leikwan-leigod-forward-relay.py"',
            source,
        )
        self.assertIn("chmod 0755", source)

    def test_slave_manifest_and_route_publish_relay(self) -> None:
        controller = (REPO_ROOT / "master" / "src" / "Controllers" / "ArtifactController.php").read_text(
            encoding="utf-8"
        )
        bootstrap = (REPO_ROOT / "master" / "src" / "Bootstrap.php").read_text(encoding="utf-8")
        self.assertIn("'leigod_forward_relay'", controller)
        self.assertIn("/artifacts/leikwan-leigod-forward-relay.py", bootstrap)

    def test_slave_update_compares_installer_sha_as_well_as_version(self) -> None:
        source = (REPO_ROOT / "slave-agent" / "slave_agent.py").read_text(encoding="utf-8")
        self.assertIn('desired_installer_sha256 = str(management.get("desired_installer_sha256"', source)
        self.assertIn('self.config.get("installer_sha256"', source)
        self.assertIn("installer_sha256=", source)

        updater = (REPO_ROOT / "install" / "leikwan-slave-update").read_text(encoding="utf-8")
        self.assertIn("EXPECTED_INSTALLER_SHA256", updater)
        self.assertIn('get("slave_installer", {})', updater)
        self.assertIn("downloaded install-slave.sh sha256 mismatch", updater)


if __name__ == "__main__":
    unittest.main()
