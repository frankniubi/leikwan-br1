from __future__ import annotations

import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class TcpAccelInstallerDistributionTests(unittest.TestCase):
    def test_tcp_accel_installer_is_self_contained_for_debian12_roles(self) -> None:
        source = (REPO_ROOT / "install" / "install-tcp-accel.sh").read_text(encoding="utf-8")

        self.assertIn("Usage:", source)
        self.assertIn("client|slave|sidecar|all", source)
        self.assertIn("apt-get install -y python3 curl ca-certificates coreutils iproute2", source)
        self.assertIn("leigod-tcp-accel-client@.service", source)
        self.assertIn("leigod-tcp-accel-bridge@.service", source)
        self.assertIn("leigod-tcp-accel-server.service", source)
        self.assertIn("/opt/leikwan/tcp-accel/tcp_accel.py", source)
        self.assertIn("/opt/leikwan/tcp-accel/leigod_tcp_accel_bridge.py", source)

        forbidden = ["pip install", "cargo build", "go build", "rustup", "make "]
        for snippet in forbidden:
            self.assertNotIn(snippet, source)

        self.assertIn("missing manifest sha256", source)
        self.assertLess(
            source.rfind("ensure_debian12_dependencies"),
            source.index('VERSIONS_JSON=')
        )

    def test_role_installers_download_and_run_tcp_accel_installer(self) -> None:
        expectations = [
            (
                "install-slave.sh",
                'download_manifest_artifact tcp_accel_installer "$ARTIFACT_BASE/artifacts/install-tcp-accel.sh" "$BIN_DIR/install-tcp-accel.sh"',
                '"$BIN_DIR/install-tcp-accel.sh" slave --artifact-base "$ARTIFACT_BASE" --bin-dir "$BIN_DIR"',
            ),
            (
                "install-bond-sidecar.sh",
                'download_verified_artifact tcp_accel_installer "$ARTIFACT_BASE/artifacts/install-tcp-accel.sh" "$BIN_DIR/install-tcp-accel.sh"',
                '"$BIN_DIR/install-tcp-accel.sh" sidecar --artifact-base "$ARTIFACT_BASE" --bin-dir "$BIN_DIR"',
            ),
        ]
        for filename, download_snippet, exec_snippet in expectations:
            with self.subTest(filename=filename):
                source = (REPO_ROOT / "install" / filename).read_text(encoding="utf-8")
                self.assertIn(download_snippet, source)
                self.assertIn(exec_snippet, source)

        client_agent = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        self.assertNotIn("tcp_accel_installer", client_agent)
        self.assertNotIn('install-tcp-accel.sh" client', client_agent)

    def test_repo_local_tcp_accel_installer_uses_br1_bridge_helper(self) -> None:
        source = (REPO_ROOT / "tools" / "tcp-accel" / "install_debian12.sh").read_text(encoding="utf-8")

        self.assertIn("slave-agent/leigod_tcp_accel_bridge.py", source)
        self.assertIn("leigod_tcp_accel_bridge.py bridge-validate", source)
        self.assertNotIn("bridge mode skipped", source)


if __name__ == "__main__":
    unittest.main()
