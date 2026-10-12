import datetime
import json
import os
import pathlib
import re
import subprocess
import tempfile
import time
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
SETUP_EXE = REPO_ROOT / "artifacts" / "windows" / "leikwan-bridge-setup.exe"
INNO_SCRIPT = REPO_ROOT / "scripts" / "windows-client-setup.iss"
INSTALL_HELPER = REPO_ROOT / "scripts" / "windows-client-install-helper.ps1"
INSTALLER_SOURCES = [INNO_SCRIPT, INSTALL_HELPER]
INSTALLED_HELPER_RELATIVE = pathlib.Path(".installer") / "windows-client-install-helper.ps1"
SETUP_BUILDER = REPO_ROOT / "scripts" / "build-windows-client-setup.ps1"
MAC_RULE_SET_DIR = (
    REPO_ROOT / "LeikwanBridge.Desktop" / "src" / "LeikwanBridge.Mac" / "Resources" / "RuleSets"
)
CHINA_RULE_SETS = ("geoip-cn.srs", "geosite-cn.srs")


class WindowsClientChinaRuleSetPackagingTests(unittest.TestCase):
    """The native "bypass China" rule-sets ship next to CN.conf."""

    def test_repo_root_rule_sets_are_sing_box_binary_and_match_mac_bundle(self) -> None:
        for name in CHINA_RULE_SETS:
            root_copy = REPO_ROOT / name
            self.assertTrue(root_copy.is_file(), f"missing bundled rule-set: {root_copy}")
            payload = root_copy.read_bytes()
            self.assertEqual(payload[:3], b"SRS", f"{name} is not a sing-box binary rule-set")
            self.assertEqual(
                payload,
                (MAC_RULE_SET_DIR / name).read_bytes(),
                f"{name}: Windows (repo root) and macOS snapshots diverged",
            )

    def test_setup_builder_stages_rule_sets_into_bin_next_to_cn_conf(self) -> None:
        source = SETUP_BUILDER.read_text(encoding="utf-8")
        self.assertIn('Join-Path $appBinStageDir "CN.conf"', source)
        for name in CHINA_RULE_SETS:
            self.assertIn(f'Join-Path $appBinStageDir "{name}"', source)

    def test_helper_fallback_uninstall_list_covers_rule_sets(self) -> None:
        source = INSTALL_HELPER.read_text(encoding="utf-8")
        for name in CHINA_RULE_SETS:
            self.assertIn('"bin\\' + name + '"', source)


class WindowsClientSetupInstallerScriptTests(unittest.TestCase):
    def setUp(self) -> None:
        self.assertTrue(INNO_SCRIPT.is_file(), f"missing Inno Setup script: {INNO_SCRIPT}")
        self.inno_source = INNO_SCRIPT.read_text(encoding="utf-8")

    @staticmethod
    def directory_contains_real_install(candidate_dir: pathlib.Path) -> bool:
        return candidate_dir.is_dir() and (
            (candidate_dir / "leikwan-client-win.exe").is_file()
            or (candidate_dir / "install-state.json").is_file()
        )

    def detect_existing_install_outside_selected_dir(
        self,
        selected_dir: pathlib.Path,
        *candidates: pathlib.Path,
    ) -> pathlib.Path | None:
        normalized_selected = selected_dir.resolve()
        for candidate in candidates:
            if self.directory_contains_real_install(candidate) and candidate.resolve() != normalized_selected:
                return candidate.resolve()
        return None

    @staticmethod
    def resolve_effective_install_dir(
        selected_dir: pathlib.Path,
        existing_install_dir: pathlib.Path | None,
        *,
        migrate_selected: bool,
    ) -> pathlib.Path:
        if existing_install_dir is not None and not migrate_selected:
            return existing_install_dir.resolve()
        return selected_dir.resolve()

    def test_inno_script_detects_real_existing_install_roots(self) -> None:
        self.assertIn("Result := ExpandConstant('{localappdata}\\LeiKwan Bridge');", self.inno_source)
        self.assertIn("Result := ExpandConstant('{localappdata}\\Programs\\LeiKwan Bridge');", self.inno_source)
        self.assertIn("Result := ExpandConstant('{autopf}\\LeiKwan Bridge');", self.inno_source)

        match = re.search(
            r"function DirectoryContainsRealInstall\(.*?\)\s*:\s*Boolean;\s*begin(?P<body>.*?)end;",
            self.inno_source,
            re.DOTALL,
        )
        self.assertIsNotNone(match, "expected DirectoryContainsRealInstall helper in windows-client-setup.iss")
        body = match.group("body")
        self.assertIn("{#MyAppExeName}", body)
        self.assertIn("install-state.json", body)
        self.assertNotIn("updates", body)

    def test_inno_script_decline_migration_retargets_existing_install(self) -> None:
        self.assertIn("MigrateRadio.Checked := True;", self.inno_source)
        self.assertIn("if ExistingInstallDetected and (not MigrateRadio.Checked) then", self.inno_source)
        self.assertIn("WizardForm.DirEdit.Text := ExistingInstallDir;", self.inno_source)
        self.assertIn("%ProgramData%\\Leikwan\\ClientAgent", self.inno_source)

    def test_inno_script_routes_uninstall_cleanup_through_helper(self) -> None:
        self.assertIn("[UninstallRun]", self.inno_source)
        self.assertIn("windows-client-install-helper.ps1", self.inno_source)
        self.assertIn("-Mode Uninstall", self.inno_source)
        self.assertIn(".installer", self.inno_source)

    def test_inno_script_parses_silent_update_contract(self) -> None:
        self.assertIn("SilentUpdateRequested", self.inno_source)
        self.assertIn("SilentUpdateInstallDir", self.inno_source)
        self.assertIn("SilentUpdateInstallMode", self.inno_source)
        self.assertIn("SilentUpdateDesktopShortcut", self.inno_source)
        self.assertIn("GetUpdateRequestedArgValue", self.inno_source)
        self.assertIn("-UpdateRequested", self.inno_source)
        self.assertIn("ValidateSilentUpdateParameters", self.inno_source)
        self.assertIn("ValidateSilentUpdateExistingInstallState", self.inno_source)
        self.assertIn("function InitializeSetup", self.inno_source)
        self.assertIn("ValidateSilentUpdateExistingInstallState;", self.inno_source)
        self.assertIn("LEIKWANUPDATE", self.inno_source)
        self.assertIn("LEIKWANINSTALLDIR", self.inno_source)
        self.assertIn("LEIKWANINSTALLMODE", self.inno_source)
        self.assertIn("LEIKWANDESKTOPSHORTCUT", self.inno_source)

    def test_inno_script_declares_supported_windows_install_matrix(self) -> None:
        self.assertIn("MinVersion=10.0.17763", self.inno_source)
        self.assertIn("ArchitecturesAllowed=x64compatible", self.inno_source)
        self.assertIn("ArchitecturesInstallIn64BitMode=x64compatible", self.inno_source)

    def test_helper_wraps_payload_replacement_in_transactional_rollback(self) -> None:
        helper_source = INSTALL_HELPER.read_text(encoding="utf-8")
        self.assertIn("New-InstallSnapshot", helper_source)
        self.assertIn("Restore-InstallSnapshot", helper_source)
        self.assertIn("$snapshotRoot", helper_source)
        self.assertIn("try {", helper_source)
        self.assertIn("catch {", helper_source)

    def test_directory_contains_real_install_ignores_updates_only_cache(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-migration-detect-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            updates_only = temp_path / "LeiKwan Bridge"
            (updates_only / "updates").mkdir(parents=True)
            self.assertFalse(self.directory_contains_real_install(updates_only))

            legacy_install = temp_path / "LeiKwan Bridge Legacy"
            legacy_install.mkdir(parents=True)
            (legacy_install / "install-state.json").write_text("{}", encoding="utf-8")
            self.assertTrue(self.directory_contains_real_install(legacy_install))

    def test_declining_migration_retargets_existing_install_in_place(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-migration-retarget-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            selected_dir = temp_path / "selected-target"
            legacy_install = temp_path / "LeiKwan Bridge"
            updates_only = legacy_install / "updates"
            updates_only.mkdir(parents=True)
            (legacy_install / "leikwan-client-win.exe").write_bytes(b"legacy-app")

            existing_install = self.detect_existing_install_outside_selected_dir(
                selected_dir,
                updates_only,
                legacy_install,
            )
            self.assertEqual(existing_install, legacy_install.resolve())

            migrate_target = self.resolve_effective_install_dir(
                selected_dir,
                existing_install,
                migrate_selected=True,
            )
            keep_existing_target = self.resolve_effective_install_dir(
                selected_dir,
                existing_install,
                migrate_selected=False,
            )

            self.assertEqual(migrate_target, selected_dir.resolve())
            self.assertEqual(keep_existing_target, legacy_install.resolve())


@unittest.skipUnless(os.name == "nt", "Windows-only helper test")
class WindowsClientInstallHelperTests(unittest.TestCase):
    def make_env(self, desktop_dir: pathlib.Path, common_desktop_dir: pathlib.Path | None = None) -> dict[str, str]:
        env = os.environ.copy()
        env["LEIKWAN_DESKTOP_DIR"] = str(desktop_dir)
        if common_desktop_dir is not None:
            env["LEIKWAN_COMMON_DESKTOP_DIR"] = str(common_desktop_dir)
        return env

    def write_install_state(
        self,
        install_dir: pathlib.Path,
        *,
        declared_install_dir: pathlib.Path,
        install_mode: str = "just-for-me",
        desktop_shortcut: bool = True,
    ) -> None:
        install_dir.mkdir(parents=True, exist_ok=True)
        payload = {
            "install_mode": install_mode,
            "install_dir": str(declared_install_dir),
            "desktop_shortcut": desktop_shortcut,
            "display_name": "LeiKwan Bridge | 利群會聚",
            "installed_version": "0.1.15.0",
            "installed_at": "2026-05-30T12:34:56Z",
        }
        (install_dir / "install-state.json").write_text(
            json.dumps(payload, indent=2),
            encoding="utf-8",
        )

    def run_helper(self, env: dict[str, str], *arguments: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [
                "powershell.exe",
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                str(INSTALL_HELPER),
                *arguments,
            ],
            capture_output=True,
            text=True,
            errors="replace",
            env=env,
            timeout=40,
        )

    def create_staged_payload(self, stage_dir: pathlib.Path) -> None:
        app_dir = stage_dir / "app"
        bin_dir = app_dir / "bin"
        bin_dir.mkdir(parents=True)

        (app_dir / "leikwan-client-win.exe").write_bytes(b"app")
        (app_dir / "LeikwanBridge.App.dll").write_bytes(b"dll")
        (app_dir / "appsettings.json").write_text("{}", encoding="utf-8")

        for name in (
            "wstunnel.exe",
            "leikwan-bond.exe",
            "leikwan-ingress.exe",
            "leikwan-uot-bond.exe",
            "leikwan-mptcp-relay.exe",
            "ultra-client.exe",
            "sing-box.exe",
            "Redirector.bin",
            "netfilter2.sys",
            "nfapi.dll",
            "CN.conf",
            "geoip-cn.srs",
            "geosite-cn.srs",
        ):
            (bin_dir / name).write_bytes(name.encode("utf-8"))

    def wait_for_absence(self, path: pathlib.Path, *, timeout_seconds: float = 5.0) -> None:
        deadline = time.monotonic() + timeout_seconds
        while path.exists() and time.monotonic() < deadline:
            time.sleep(0.1)

    def test_helper_removes_existing_desktop_shortcut_when_option_disabled(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-helper-shortcut-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "install-root"
            stage_dir = temp_path / "stage"
            desktop_dir = temp_path / "desktop"
            self.create_staged_payload(stage_dir)
            env = self.make_env(desktop_dir)

            first_install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(stage_dir),
                "-InstallMode",
                "just-for-me",
                "-DesktopShortcut",
                "1",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.15.0",
            )
            self.assertEqual(
                first_install.returncode,
                0,
                msg="helper install failed\nSTDOUT:\n"
                + first_install.stdout
                + "\nSTDERR:\n"
                + first_install.stderr,
            )
            self.assertTrue((desktop_dir / "LeiKwan Bridge.lnk").is_file())

            second_install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(stage_dir),
                "-InstallMode",
                "just-for-me",
                "-DesktopShortcut",
                "0",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.16.0",
            )
            self.assertEqual(
                second_install.returncode,
                0,
                msg="helper reinstall failed\nSTDOUT:\n"
                + second_install.stdout
                + "\nSTDERR:\n"
                + second_install.stderr,
            )
            self.assertFalse((desktop_dir / "LeiKwan Bridge.lnk").exists())

    def test_helper_reinstall_removes_stale_installer_owned_files(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-helper-reinstall-stale-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "install-root"
            first_stage_dir = temp_path / "stage-first"
            second_stage_dir = temp_path / "stage-second"
            desktop_dir = temp_path / "desktop"
            self.create_staged_payload(first_stage_dir)
            self.create_staged_payload(second_stage_dir)
            obsolete_file = second_stage_dir / "app" / "Obsolete.Dependency.dll"
            obsolete_file.write_bytes(b"obsolete")
            env = self.make_env(desktop_dir)

            first_install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(second_stage_dir),
                "-InstallMode",
                "just-for-me",
                "-DesktopShortcut",
                "0",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.15.0",
            )
            self.assertEqual(first_install.returncode, 0, msg=first_install.stdout + "\n" + first_install.stderr)
            self.assertTrue((install_dir / "Obsolete.Dependency.dll").is_file())
            old_backup = install_dir / "backup-legacy"
            (old_backup / "old-client.exe").parent.mkdir(parents=True)
            (old_backup / "old-client.exe").write_bytes(b"old")

            obsolete_file.unlink()
            second_install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(second_stage_dir),
                "-InstallMode",
                "just-for-me",
                "-DesktopShortcut",
                "0",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.16.0",
            )
            self.assertEqual(second_install.returncode, 0, msg=second_install.stdout + "\n" + second_install.stderr)
            self.assertFalse((install_dir / "Obsolete.Dependency.dll").exists())
            manifest = json.loads((install_dir / "install-manifest.json").read_text(encoding="utf-8"))
            self.assertEqual(manifest["backup_dir"], "")
            self.assertFalse(any(install_dir.glob("backup-*")))

    def test_helper_source_does_not_create_client_backup_directories(self) -> None:
        helper_source = INSTALL_HELPER.read_text(encoding="utf-8")
        self.assertNotIn("function Backup-ExistingInstall", helper_source)
        self.assertNotIn("Backup-ExistingInstall -TargetDir", helper_source)

    def test_helper_all_users_shortcut_uses_common_desktop(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-helper-common-desktop-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "install-root"
            stage_dir = temp_path / "stage"
            desktop_dir = temp_path / "desktop"
            common_desktop_dir = temp_path / "common-desktop"
            self.create_staged_payload(stage_dir)
            env = self.make_env(desktop_dir, common_desktop_dir)

            install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(stage_dir),
                "-InstallMode",
                "all-users",
                "-DesktopShortcut",
                "1",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.15.0",
            )
            self.assertEqual(
                install.returncode,
                0,
                msg="helper install failed\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )
            self.assertTrue((common_desktop_dir / "LeiKwan Bridge.lnk").is_file())
            self.assertFalse((desktop_dir / "LeiKwan Bridge.lnk").exists())

            uninstall = subprocess.run(
                [
                    "powershell.exe",
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(install_dir / INSTALLED_HELPER_RELATIVE),
                    "-Mode",
                    "Uninstall",
                    "-InstallDir",
                    str(install_dir),
                ],
                capture_output=True,
                text=True,
                errors="replace",
                env=env,
                timeout=40,
            )
            self.assertEqual(
                uninstall.returncode,
                0,
                msg="helper uninstall failed\nSTDOUT:\n"
                + uninstall.stdout
                + "\nSTDERR:\n"
                + uninstall.stderr,
            )
            self.assertFalse((common_desktop_dir / "LeiKwan Bridge.lnk").exists())

    def test_helper_rejects_update_when_install_state_options_mismatch(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-helper-update-mismatch-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "install-root"
            stage_dir = temp_path / "stage"
            desktop_dir = temp_path / "desktop"
            self.create_staged_payload(stage_dir)
            env = self.make_env(desktop_dir)
            self.write_install_state(
                install_dir,
                declared_install_dir=install_dir,
                install_mode="all-users",
                desktop_shortcut=False,
            )

            install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(stage_dir),
                "-InstallMode",
                "just-for-me",
                "-DesktopShortcut",
                "1",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.16.0",
                "-UpdateRequested",
                "1",
            )
            self.assertNotEqual(
                install.returncode,
                0,
                msg="helper unexpectedly accepted an update with conflicting install-state options\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )
            combined_output = (install.stdout + "\n" + install.stderr).lower()
            self.assertIn("install-state", combined_output)
            self.assertIn("mismatch", combined_output)

    def test_helper_uninstall_ignores_manifest_entries_outside_install_root(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-helper-uninstall-outside-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "LeiKwan Bridge"
            stage_dir = temp_path / "stage"
            desktop_dir = temp_path / "desktop"
            outside_file = temp_path / "outside.txt"
            self.create_staged_payload(stage_dir)
            env = self.make_env(desktop_dir)

            install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(stage_dir),
                "-InstallMode",
                "just-for-me",
                "-DesktopShortcut",
                "0",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.15.0",
            )
            self.assertEqual(
                install.returncode,
                0,
                msg="helper install failed\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )

            outside_file.write_text("do not delete", encoding="utf-8")
            manifest_path = install_dir / "install-manifest.json"
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            manifest.setdefault("installed_files", []).append("..\\outside.txt")
            manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")

            uninstall = subprocess.run(
                [
                    "powershell.exe",
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(install_dir / INSTALLED_HELPER_RELATIVE),
                    "-Mode",
                    "Uninstall",
                    "-InstallDir",
                    str(install_dir),
                ],
                capture_output=True,
                text=True,
                errors="replace",
                env=env,
                timeout=40,
            )
            self.assertEqual(
                uninstall.returncode,
                0,
                msg="helper uninstall failed\nSTDOUT:\n"
                + uninstall.stdout
                + "\nSTDERR:\n"
                + uninstall.stderr,
            )
            self.assertTrue(outside_file.is_file())

    def test_helper_uninstall_removes_installer_owned_artifacts_and_preserves_runtime_data(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-helper-uninstall-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "LeiKwan Bridge"
            stage_dir = temp_path / "stage"
            desktop_dir = temp_path / "desktop"
            programdata_dir = temp_path / "ProgramData" / "Leikwan" / "ClientAgent"
            self.create_staged_payload(stage_dir)
            env = self.make_env(desktop_dir)

            install = self.run_helper(
                env,
                "-InstallDir",
                str(install_dir),
                "-StageDir",
                str(stage_dir),
                "-InstallMode",
                "just-for-me",
                "-DesktopShortcut",
                "1",
                "-DisplayName",
                "LeiKwan Bridge | 利群會聚",
                "-AppVersion",
                "0.1.15.0",
            )
            self.assertEqual(
                install.returncode,
                0,
                msg="helper install failed\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )
            self.assertTrue((desktop_dir / "LeiKwan Bridge.lnk").is_file())
            for name in CHINA_RULE_SETS:
                self.assertTrue((install_dir / "bin" / name).is_file())

            updates_dir = install_dir / "updates"
            updates_dir.mkdir(parents=True)
            (updates_dir / "cached-installer.exe").write_bytes(b"cached")
            (install_dir / "user-runtime.txt").write_text("keep me", encoding="utf-8")
            programdata_dir.mkdir(parents=True)
            (programdata_dir / "agent.json").write_text("{}", encoding="utf-8")

            installed_helper = install_dir / INSTALLED_HELPER_RELATIVE
            self.assertTrue(installed_helper.is_file())

            uninstall = subprocess.run(
                [
                    "powershell.exe",
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(installed_helper),
                    "-Mode",
                    "Uninstall",
                    "-InstallDir",
                    str(install_dir),
                ],
                capture_output=True,
                text=True,
                errors="replace",
                env=env,
                timeout=40,
            )
            self.assertEqual(
                uninstall.returncode,
                0,
                msg="helper uninstall failed\nSTDOUT:\n"
                + uninstall.stdout
                + "\nSTDERR:\n"
                + uninstall.stderr,
            )

            self.wait_for_absence(install_dir / INSTALLED_HELPER_RELATIVE.parent)
            self.assertFalse((install_dir / "leikwan-client-win.exe").exists())
            self.assertFalse((install_dir / "LeikwanBridge.App.dll").exists())
            self.assertFalse((install_dir / "appsettings.json").exists())
            self.assertFalse((install_dir / "bin" / "wstunnel.exe").exists())
            for name in CHINA_RULE_SETS:
                self.assertFalse((install_dir / "bin" / name).exists())
            self.assertFalse((install_dir / "install-manifest.json").exists())
            self.assertFalse((install_dir / "install-state.json").exists())
            self.assertFalse((desktop_dir / "LeiKwan Bridge.lnk").exists())
            self.assertTrue((updates_dir / "cached-installer.exe").exists())
            self.assertTrue((install_dir / "user-runtime.txt").is_file())
            self.assertTrue(programdata_dir.is_dir())


@unittest.skipUnless(os.name == "nt", "Windows-only installer test")
class WindowsClientSetupInstallerTests(unittest.TestCase):
    def make_env(self, desktop_dir: pathlib.Path) -> dict[str, str]:
        env = os.environ.copy()
        env.pop("LEIKWAN_INSTALL_DIR", None)
        env["LEIKWAN_DESKTOP_DIR"] = str(desktop_dir)
        return env

    def require_fresh_setup_exe(self) -> None:
        self.assertTrue(SETUP_EXE.is_file(), f"missing setup exe: {SETUP_EXE}")

        setup_mtime = SETUP_EXE.stat().st_mtime
        stale_inputs = [
            path for path in INSTALLER_SOURCES if path.is_file() and path.stat().st_mtime > setup_mtime
        ]
        if stale_inputs:
            stale_list = ", ".join(str(path.relative_to(REPO_ROOT)) for path in stale_inputs)
            self.skipTest(
                "setup exe is older than installer sources; rebuild artifacts/windows/leikwan-bridge-setup.exe "
                f"before running integration assertions ({stale_list})"
            )

    def run_setup(self, env: dict[str, str], *arguments: str) -> subprocess.CompletedProcess[str]:
        self.require_fresh_setup_exe()
        try:
            return subprocess.run(
                [str(SETUP_EXE), *arguments],
                capture_output=True,
                text=True,
                errors="replace",
                env=env,
                # The self-contained native Windows package is larger than the
                # legacy test installer; allow extraction on slower disks.
                timeout=180,
            )
        except subprocess.TimeoutExpired as exc:
            stdout = exc.stdout or ""
            stderr = exc.stderr or ""
            self.fail(
                "setup exe did not complete under Inno-style silent arguments; "
                "current package likely still behaves like the legacy IExpress installer\n"
                f"ARGS: {list(arguments)}\n"
                f"STDOUT:\n{stdout}\n"
                f"STDERR:\n{stderr}"
            )

    def write_install_state(
        self,
        install_dir: pathlib.Path,
        *,
        declared_install_dir: pathlib.Path,
        install_mode: str = "just-for-me",
        desktop_shortcut: bool = True,
    ) -> None:
        install_dir.mkdir(parents=True, exist_ok=True)
        payload = {
            "install_mode": install_mode,
            "install_dir": str(declared_install_dir),
            "desktop_shortcut": desktop_shortcut,
            "display_name": "LeiKwan Bridge | 利群會聚",
            "installed_version": "0.1.15.0",
            "installed_at": "2026-05-30T12:34:56Z",
        }
        (install_dir / "install-state.json").write_text(
            json.dumps(payload, indent=2),
            encoding="utf-8",
        )

    def assert_install_state(
        self,
        install_dir: pathlib.Path,
        *,
        expected_install_mode: str,
        expected_desktop_shortcut: bool,
    ) -> None:
        state = json.loads((install_dir / "install-state.json").read_text(encoding="utf-8"))
        self.assertEqual(state["install_mode"], expected_install_mode)
        self.assertEqual(pathlib.Path(state["install_dir"]).resolve(), install_dir.resolve())
        self.assertEqual(state["desktop_shortcut"], expected_desktop_shortcut)
        self.assertEqual(state["display_name"], "LeiKwan Bridge | 利群會聚")
        self.assertRegex(state["installed_version"], r"^\d+\.\d+\.\d+\.\d+$")
        installed_at = datetime.datetime.fromisoformat(state["installed_at"].replace("Z", "+00:00"))
        self.assertIsNotNone(installed_at.tzinfo)

    def test_setup_installs_payload_in_just_for_me_mode_without_desktop_shortcut(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-setup-just-for-me-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "install-root"
            desktop_dir = temp_path / "desktop"
            env = self.make_env(desktop_dir)

            install = self.run_setup(
                env,
                "/VERYSILENT",
                "/SUPPRESSMSGBOXES",
                "/NORESTART",
                "/CURRENTUSER",
                f"/DIR={install_dir}",
                "/TASKS=",
            )

            self.assertEqual(
                install.returncode,
                0,
                msg="installer failed\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )
            self.assertTrue((install_dir / "leikwan-client-win.exe").is_file())
            self.assertTrue((install_dir / "install-manifest.json").is_file())
            self.assertTrue((install_dir / "install-state.json").is_file())
            self.assert_install_state(
                install_dir,
                expected_install_mode="just-for-me",
                expected_desktop_shortcut=False,
            )
            self.assertFalse((desktop_dir / "LeiKwan Bridge.lnk").exists())

    def test_setup_installs_payload_with_desktop_shortcut_when_task_enabled(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-setup-desktop-task-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "install-root"
            desktop_dir = temp_path / "desktop"
            env = self.make_env(desktop_dir)

            install = self.run_setup(
                env,
                "/VERYSILENT",
                "/SUPPRESSMSGBOXES",
                "/NORESTART",
                "/CURRENTUSER",
                f"/DIR={install_dir}",
                "/TASKS=desktopicon",
            )

            self.assertEqual(
                install.returncode,
                0,
                msg="installer failed\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )
            self.assertTrue((install_dir / "leikwan-client-win.exe").is_file())
            self.assertTrue((install_dir / "install-manifest.json").is_file())
            self.assertTrue((install_dir / "install-state.json").is_file())
            self.assert_install_state(
                install_dir,
                expected_install_mode="just-for-me",
                expected_desktop_shortcut=True,
            )
            self.assertTrue((desktop_dir / "LeiKwan Bridge.lnk").is_file())

    def test_setup_rejects_silent_update_when_install_state_mismatches_target(self) -> None:
        with tempfile.TemporaryDirectory(prefix="leikwan-setup-update-mismatch-") as temp_dir:
            temp_path = pathlib.Path(temp_dir)
            install_dir = temp_path / "install-root"
            desktop_dir = temp_path / "desktop"
            env = self.make_env(desktop_dir)
            self.write_install_state(
                install_dir,
                declared_install_dir=temp_path / "different-install-root",
                desktop_shortcut=True,
            )

            install = self.run_setup(
                env,
                "/VERYSILENT",
                "/SUPPRESSMSGBOXES",
                "/NORESTART",
                "/CURRENTUSER",
                "/LEIKWANUPDATE=1",
                f"/LEIKWANINSTALLDIR={install_dir}",
                "/LEIKWANINSTALLMODE=just-for-me",
                "/LEIKWANDESKTOPSHORTCUT=1",
            )

            self.assertNotEqual(
                install.returncode,
                0,
                msg="installer unexpectedly accepted a silent update target mismatch\nSTDOUT:\n"
                + install.stdout
                + "\nSTDERR:\n"
                + install.stderr,
            )
            self.assertFalse((install_dir / "leikwan-client-win.exe").exists())


if __name__ == "__main__":
    unittest.main()
