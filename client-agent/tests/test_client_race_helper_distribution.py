from __future__ import annotations

import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class ClientRaceHelperDistributionTests(unittest.TestCase):
    def test_client_installer_downloads_both_helpers_atomically(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        for remote, local in [
            ("leikwan-tcp-race.py", "leikwan-tcp-race.py"),
            ("leikwan-udp-fanout.py", "leikwan-udp-fanout.py"),
        ]:
            self.assertIn(
                f'download_manifest_artifact {"tcp_race_runtime" if "tcp" in remote else "udp_fanout_runtime"} '
                f'"$ARTIFACT_BASE/artifacts/{remote}" "$BIN_DIR/{local}"',
                source,
            )

    def test_client_manifest_and_routes_publish_both_helpers(self) -> None:
        controller = (REPO_ROOT / "master" / "src" / "Controllers" / "ArtifactController.php").read_text(
            encoding="utf-8"
        )
        bootstrap = (REPO_ROOT / "master" / "src" / "Bootstrap.php").read_text(encoding="utf-8")
        for key in ["tcp_race_runtime", "udp_fanout_runtime"]:
            self.assertIn(f"'{key}'", controller)
        for route in ["/artifacts/leikwan-tcp-race.py", "/artifacts/leikwan-udp-fanout.py"]:
            self.assertIn(route, bootstrap)


if __name__ == "__main__":
    unittest.main()
