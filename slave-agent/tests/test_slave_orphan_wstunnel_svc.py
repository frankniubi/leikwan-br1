import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


slave_agent_module = load_module("slave_agent_orphan_under_test", "slave-agent/slave_agent.py")


class SlaveOrphanWstunnelSvcTests(unittest.TestCase):
    def make_agent(self):
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        base = pathlib.Path(tmp_dir.name)
        systemd_dir = base / "systemd"
        systemd_dir.mkdir(parents=True, exist_ok=True)
        config_path = base / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(base / "state"),
                    "systemd_dir": str(systemd_dir),
                }
            ),
            encoding="utf-8",
        )
        agent = slave_agent_module.SlaveAgent(config_path)
        return agent, systemd_dir

    @staticmethod
    def _write_unit(systemd_dir: pathlib.Path, name: str, port: int = 20001) -> pathlib.Path:
        path = systemd_dir / f"{name}.service"
        path.write_text(f"[Service]\nExecStart=/usr/local/bin/wstunnel server ws://127.0.0.1:{port}\n", encoding="utf-8")
        return path

    def test_reaps_orphan_wstunnel_svc_unit_without_metadata(self) -> None:
        agent, systemd_dir = self.make_agent()
        # live service: has metadata + unit
        agent.save_service_metadata("wstunnel-svc-1-100", {"service_name": "wstunnel-svc-1-100", "status": "active"})
        live_unit = self._write_unit(systemd_dir, "wstunnel-svc-1-100")
        # orphan: unit file but NO metadata
        orphan_unit = self._write_unit(systemd_dir, "wstunnel-svc-1-999")
        # unrelated unit (different prefix) must never be touched
        relay_unit = self._write_unit(systemd_dir, "leikwan-relay-1-21", port=443)

        # systemctl absent -> pure file-based reap (deterministic, no side effects)
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.cleanup_orphan_wstunnel_svc_units()

        self.assertFalse(orphan_unit.exists(), "orphan wstunnel-svc unit with no metadata must be removed")
        self.assertTrue(live_unit.exists(), "live service unit (with metadata) must be preserved")
        self.assertTrue(relay_unit.exists(), "non-wstunnel-svc units must never be reaped")

    def test_present_guard_skips_when_no_metadata_at_all(self) -> None:
        agent, systemd_dir = self.make_agent()
        orphan_unit = self._write_unit(systemd_dir, "wstunnel-svc-1-999")
        # NO metadata files exist -> the sweep must be a NO-OP (never wipe everything
        # on an empty/transiently-unreadable services dir).
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.cleanup_orphan_wstunnel_svc_units()
        self.assertTrue(orphan_unit.exists(), "must NOT reap when no service metadata is present (present-guard)")

    def test_skips_active_orphan_for_safety(self) -> None:
        agent, systemd_dir = self.make_agent()
        agent.save_service_metadata("wstunnel-svc-1-100", {"service_name": "wstunnel-svc-1-100", "status": "active"})
        orphan_unit = self._write_unit(systemd_dir, "wstunnel-svc-1-999")

        def fake_run_command(command: str, check: bool = True):
            if "is-active" in command:
                return SimpleNamespace(returncode=0, stdout="active\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run_command):
                agent.cleanup_orphan_wstunnel_svc_units()

        self.assertTrue(orphan_unit.exists(), "an ACTIVE unit must never be reaped even if metadata is missing")


if __name__ == "__main__":
    unittest.main()
