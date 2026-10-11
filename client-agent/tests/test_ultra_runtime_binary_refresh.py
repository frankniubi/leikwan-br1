import importlib.util
import os
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "client_agent_ultra_runtime_binary_refresh",
    ROOT / "client-agent" / "client_agent.py",
)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class UltraRuntimeBinaryRefreshTests(unittest.TestCase):
    def make_agent(self, root: pathlib.Path):
        agent = object.__new__(MODULE.ClientAgent)
        agent.config = {"ultra_bin": str(root / "ultra-client")}
        agent.state_dir = root / "state"
        agent.state_dir.mkdir()
        (root / "ultra-client").write_bytes(b"current-runtime")
        return agent

    def test_current_runtime_inode_does_not_force_restart(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self.make_agent(pathlib.Path(directory))
            agent.migrate_legacy_ultra_override = mock.Mock(return_value=False)
            agent.systemd_is_active = mock.Mock(return_value=True)
            agent.systemd_main_pid = mock.Mock(return_value=4242)
            with mock.patch.object(MODULE.os.path, "samefile", return_value=True) as samefile:
                self.assertFalse(agent.ultra_runtime_requires_refresh(170))
            samefile.assert_called_once_with(pathlib.Path("/proc/4242/exe"), agent.ultra_binary_path())

    def test_deleted_or_replaced_runtime_inode_forces_restart(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self.make_agent(pathlib.Path(directory))
            agent.migrate_legacy_ultra_override = mock.Mock(return_value=False)
            agent.systemd_is_active = mock.Mock(return_value=True)
            agent.systemd_main_pid = mock.Mock(return_value=4242)
            with mock.patch.object(MODULE.os.path, "samefile", return_value=False):
                self.assertTrue(agent.ultra_runtime_requires_refresh(170))
            self.assertTrue(
                agent.should_force_runtime_restart(
                    {"forward_id": 170, "backend_mode": "ultra"},
                    set(),
                )
            )

    def test_unconvergeable_runtime_forces_one_restart_per_installed_binary(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            agent = self.make_agent(root)
            agent.migrate_legacy_ultra_override = mock.Mock(return_value=False)
            agent.systemd_is_active = mock.Mock(return_value=True)
            agent.systemd_main_pid = mock.Mock(return_value=4242)
            with mock.patch.object(MODULE.os.path, "samefile", return_value=False):
                # A unit pinned to another binary never converges: after one
                # restart produced a new PID, stop forcing until a different
                # runtime is installed. Without a new PID the restart is retried.
                self.assertTrue(agent.ultra_runtime_requires_refresh(170))
                self.assertTrue(agent.ultra_runtime_requires_refresh(170))
                agent.systemd_main_pid.return_value = 5151
                self.assertFalse(agent.ultra_runtime_requires_refresh(170))
                self.assertFalse(agent.ultra_runtime_requires_refresh(170))
                self.assertTrue(agent.ultra_runtime_requires_refresh(171))
                (root / "ultra-client").unlink()
                (root / "ultra-client").write_bytes(b"next-runtime-release")
                os.utime(root / "ultra-client", ns=(1, 1))
                self.assertTrue(agent.ultra_runtime_requires_refresh(170))

    def test_exact_legacy_extuot_override_is_quarantined(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            agent = self.make_agent(root)
            override = root / "20-extuot.conf"
            override.write_text(
                "[Service]\n"
                "ExecStart=\n"
                "ExecStart=/usr/local/bin/ultra-client-extuot --config /etc/ultra/forward-170.json\n",
                encoding="utf-8",
            )
            agent.legacy_ultra_override_path = mock.Mock(return_value=override)

            self.assertTrue(agent.migrate_legacy_ultra_override(170))
            self.assertFalse(override.exists())
            backups = list((agent.state_dir / "legacy-systemd-overrides").glob("*.conf"))
            self.assertEqual(1, len(backups))
            self.assertIn("ultra-client-extuot", backups[0].read_text(encoding="utf-8"))
            if os.name == "posix":
                self.assertEqual(0o600, backups[0].stat().st_mode & 0o777)

    def test_unknown_override_is_preserved(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            agent = self.make_agent(root)
            override = root / "20-extuot.conf"
            override.write_text(
                "[Service]\nExecStart=\nExecStart=/operator/custom-runtime --config /tmp/custom.json\n",
                encoding="utf-8",
            )
            agent.legacy_ultra_override_path = mock.Mock(return_value=override)

            self.assertFalse(agent.migrate_legacy_ultra_override(170))
            self.assertTrue(override.exists())

    def test_explicit_refresh_still_wins_after_live_process_probe(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self.make_agent(pathlib.Path(directory))
            agent.ultra_runtime_requires_refresh = mock.Mock(return_value=False)
            self.assertTrue(
                agent.should_force_runtime_restart(
                    {"forward_id": 170, "backend_mode": "ultra"},
                    {170},
                )
            )
            agent.ultra_runtime_requires_refresh.assert_called_once_with(170)


if __name__ == "__main__":
    unittest.main()
