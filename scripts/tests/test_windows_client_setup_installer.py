import hashlib
import json
import os
import pathlib
import subprocess
import tempfile
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
SETUP_EXE = REPO_ROOT / "artifacts" / "windows" / "leikwan-bridge-setup.exe"
WINDIR = pathlib.Path(os.environ.get("WINDIR", r"C:\Windows"))
EXPAND_EXE = WINDIR / "System32" / "expand.exe"
POWERSHELL_EXE = WINDIR / "System32" / "WindowsPowerShell" / "v1.0" / "powershell.exe"


@unittest.skipUnless(os.name == "nt", "Windows-only installer test")
class WindowsClientSetupInstallerTests(unittest.TestCase):
    def extract_setup(self, temp_dir: str) -> pathlib.Path:
        setup_bytes = SETUP_EXE.read_bytes()
        cabinet_offset = setup_bytes.find(b"MSCF")
        self.assertGreaterEqual(cabinet_offset, 0, "IExpress cabinet payload missing from setup exe")

        cabinet_path = pathlib.Path(temp_dir) / "setup.cab"
        cabinet_path.write_bytes(setup_bytes[cabinet_offset:])

        extract_dir = pathlib.Path(temp_dir) / "extract"
        extract_dir.mkdir()

        result = subprocess.run(
            [str(EXPAND_EXE), str(cabinet_path), "-F:*", str(extract_dir)],
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, msg=result.stdout + "\n" + result.stderr)
        return extract_dir

    @staticmethod
    def sha256(path: pathlib.Path) -> str:
        return hashlib.sha256(path.read_bytes()).hexdigest().upper()

    def test_setup_installs_payload_and_creates_desktop_shortcut(self) -> None:
        self.assertTrue(SETUP_EXE.is_file(), f"missing setup exe: {SETUP_EXE}")

        with tempfile.TemporaryDirectory(prefix="leikwan-setup-test-") as temp_dir:
            extract_dir = self.extract_setup(temp_dir)
            localappdata = pathlib.Path(temp_dir) / "localappdata"
            desktop_dir = pathlib.Path(temp_dir) / "desktop"
            localappdata.mkdir()
            desktop_dir.mkdir()

            env = os.environ.copy()
            env["LOCALAPPDATA"] = str(localappdata)
            env["LEIKWAN_INSTALL_DIR"] = str(localappdata / "LeiKwan Bridge")
            env["LEIKWAN_DESKTOP_DIR"] = str(desktop_dir)

            install = subprocess.run(
                [
                    str(POWERSHELL_EXE),
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(extract_dir / "install.ps1"),
                ],
                capture_output=True,
                text=True,
                env=env,
            )

            self.assertEqual(
                install.returncode,
                0,
                msg="installer failed\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )

            install_dir = localappdata / "LeiKwan Bridge"
            bin_dir = install_dir / "bin"
            self.assertTrue((install_dir / "leikwan-client-win.exe").is_file())
            self.assertTrue((install_dir / "LeikwanBridge.App.dll").is_file())
            self.assertTrue((install_dir / "LeikwanBridge.Core.dll").is_file())
            self.assertTrue((install_dir / "LeikwanBridge.App.deps.json").is_file())
            self.assertTrue((install_dir / "LeikwanBridge.App.runtimeconfig.json").is_file())
            self.assertTrue((install_dir / "CommunityToolkit.Mvvm.dll").is_file())
            self.assertTrue((install_dir / "Serilog.dll").is_file())
            self.assertTrue((install_dir / "Serilog.Sinks.File.dll").is_file())
            self.assertTrue((install_dir / "wstunnel.exe").is_file())
            self.assertTrue((install_dir / "leikwan-bond.exe").is_file())
            self.assertTrue((install_dir / "leikwan-ingress.exe").is_file())
            self.assertTrue((install_dir / "leikwan-uot-bond.exe").is_file())
            self.assertTrue((install_dir / "leikwan-mptcp-relay.exe").is_file())
            self.assertTrue((bin_dir / "wstunnel.exe").is_file())
            self.assertTrue((bin_dir / "leikwan-bond.exe").is_file())
            self.assertTrue((bin_dir / "leikwan-ingress.exe").is_file())
            self.assertTrue((bin_dir / "leikwan-uot-bond.exe").is_file())
            self.assertTrue((bin_dir / "leikwan-mptcp-relay.exe").is_file())

            manifest_path = install_dir / "install-manifest.json"
            self.assertTrue(manifest_path.is_file())
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            self.assertEqual(manifest["source"], "leikwan-bridge-setup.exe")
            self.assertEqual(manifest["app_sha256"], self.sha256(install_dir / "leikwan-client-win.exe"))
            self.assertEqual(manifest["bond_sha256"], self.sha256(bin_dir / "leikwan-bond.exe"))
            self.assertEqual(manifest["ingress_sha256"], self.sha256(bin_dir / "leikwan-ingress.exe"))
            self.assertEqual(manifest["uot_bond_sha256"], self.sha256(bin_dir / "leikwan-uot-bond.exe"))
            self.assertEqual(manifest["mptcp_relay_sha256"], self.sha256(bin_dir / "leikwan-mptcp-relay.exe"))
            self.assertEqual(manifest["wstunnel_sha256"], self.sha256(bin_dir / "wstunnel.exe"))

            self.assertTrue((desktop_dir / "LeiKwan Bridge.lnk").is_file())


if __name__ == "__main__":
    unittest.main()
