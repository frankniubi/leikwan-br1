import hashlib
import json
import os
import pathlib
import subprocess
import tempfile
import textwrap
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

    def test_relaunch_argument_round_trip_preserves_trailing_separators(self) -> None:
        install_override = r"C:\Program Files\LeiKwan Bridge\\"
        desktop_override = r"C:\Users\foo\Desktop\\"

        with tempfile.TemporaryDirectory(prefix="leikwan-setup-relaunch-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            args_json = temp_path / "args.json"
            child_ps1 = temp_path / "child.ps1"
            child_ps1.write_text(
                textwrap.dedent(
                    """
                    param(
                        [switch]$Elevated
                    )
                    $payload = [ordered]@{
                        Elevated = [bool]$Elevated
                        InstallDirOverride = $env:LEIKWAN_RELAUNCH_INSTALL_DIR
                        DesktopDirOverride = $env:LEIKWAN_RELAUNCH_DESKTOP_DIR
                    }
                    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
                    [System.IO.File]::WriteAllText(
                        $env:LEIKWAN_ARGS_JSON,
                        ($payload | ConvertTo-Json -Depth 4),
                        $utf8NoBom
                    )
                    """
                ).strip()
                + "\n",
                encoding="utf-8",
            )

            harness_ps1 = temp_path / "harness.ps1"
            harness_ps1.write_text(
                textwrap.dedent(
                    """
                    param(
                        [string]$ChildScript,
                        [string]$InstallDir,
                        [string]$DesktopDir
                    )

                    $env:LEIKWAN_RELAUNCH_INSTALL_DIR = $InstallDir
                    $env:LEIKWAN_RELAUNCH_DESKTOP_DIR = $DesktopDir

                    $psiArgs = @(
                        "-NoProfile",
                        "-ExecutionPolicy", "Bypass",
                        "-File", $ChildScript,
                        "-Elevated"
                    )

                    $process = Start-Process -FilePath "powershell.exe" -ArgumentList $psiArgs -Wait -PassThru
                    exit $process.ExitCode
                    """
                ).strip()
                + "\n",
                encoding="utf-8",
            )

            env = os.environ.copy()
            env["LEIKWAN_ARGS_JSON"] = str(args_json)

            run = subprocess.run(
                [
                    str(POWERSHELL_EXE),
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(harness_ps1),
                    "-ChildScript",
                    str(child_ps1),
                    "-InstallDir",
                    install_override,
                    "-DesktopDir",
                    desktop_override,
                ],
                capture_output=True,
                text=True,
                env=env,
            )

            self.assertEqual(
                run.returncode,
                0,
                msg="relaunch harness failed\nSTDOUT:\n" + run.stdout + "\nSTDERR:\n" + run.stderr,
            )
            self.assertTrue(args_json.is_file(), "child argument capture missing")
            parsed = json.loads(args_json.read_text(encoding="utf-8"))
            self.assertTrue(parsed["Elevated"])
            self.assertEqual(parsed["InstallDirOverride"], install_override)
            self.assertEqual(parsed["DesktopDirOverride"], desktop_override)

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

            script_text = (extract_dir / "install.ps1").read_text(encoding="utf-8")
            self.assertIn("-Verb RunAs", script_text)
            self.assertIn("-Elevated", script_text)
            self.assertIn("WindowsPrincipal", script_text)
            self.assertIn("IsInRole", script_text)
            self.assertIn("if ($Elevated)", script_text)
            self.assertIn("$PSCommandPath", script_text)
            self.assertIn("installer relaunched with -Elevated", script_text)
            self.assertIn("Test-RequiresElevation -TargetDir $resolvedInstallDir -and -not (Test-IsAdministrator)", script_text)
            self.assertIn('"-File", $PSCommandPath', script_text)
            self.assertIn("if ($Elevated) {", script_text)
            self.assertIn("installer relaunched with -Elevated but still lacks administrator rights", script_text)
            self.assertIn("[string]$InstallDirOverride", script_text)
            self.assertIn("[string]$DesktopDirOverride", script_text)
            self.assertIn("$resolvedInstallDir", script_text)
            self.assertIn("$resolvedDesktopDir", script_text)
            self.assertIn("LEIKWAN_RELAUNCH_INSTALL_DIR", script_text)
            self.assertIn("LEIKWAN_RELAUNCH_DESKTOP_DIR", script_text)

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
