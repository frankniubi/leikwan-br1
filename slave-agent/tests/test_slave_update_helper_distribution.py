import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class SlaveUpdateHelperDistributionTests(unittest.TestCase):
    def test_slave_installer_downloads_update_helper(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_atomic "$MASTER_URL/artifacts/slave-agent-update.sh" "$BIN_DIR/leikwan-slave-update"',
            source,
        )

    def test_slave_installer_downloads_wstunnel_from_panel_artifacts(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_atomic "$MASTER_URL/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"',
            source,
        )
        self.assertNotIn("github.com/erebe/wstunnel/releases/download", source)

    def test_slave_installer_uses_atomic_downloads_for_runtime_binaries(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn("download_atomic()", source)
        for snippet in [
            'download_atomic "$MASTER_URL/artifacts/slave-agent.py" "$BIN_DIR/leikwan-slave-agent.py"',
            'download_atomic "$MASTER_URL/artifacts/slave-agent-update.sh" "$BIN_DIR/leikwan-slave-update"',
            'download_atomic "$MASTER_URL/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"',
            'download_atomic "$MASTER_URL/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"',
            'download_atomic "$MASTER_URL/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"',
            'download_atomic "$MASTER_URL/artifacts/leikwan-wg-watchdog.sh" "$BIN_DIR/leikwan-wg-watchdog.sh"',
        ]:
            self.assertIn(snippet, source)


if __name__ == "__main__":
    unittest.main()
