import json
import os
import pathlib
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
import sidecar_agent  # noqa: E402


class ExternalUoTHealthTests(unittest.TestCase):
    def make_agent(self, root: pathlib.Path) -> sidecar_agent.SidecarAgent:
        config = root / "agent.json"
        config.write_text(json.dumps({"state_dir": str(root / "state")}) + "\n", encoding="utf-8")
        return sidecar_agent.SidecarAgent(config)

    def test_missing_install_is_reported_without_systemd_probe(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            root = pathlib.Path(tmp_dir)
            agent = self.make_agent(root)
            with mock.patch.dict(os.environ, {"LEIKWAN_EXTERNAL_UOT_CONFIG": str(root / "missing.json")}, clear=False):
                with mock.patch.object(sidecar_agent.subprocess, "run") as run:
                    status = agent.external_uot_status()
            self.assertEqual("not_installed", status["state"])
            self.assertFalse(status["required"])
            self.assertFalse(status["healthy"])
            run.assert_not_called()

    def test_healthy_forwarded_install_is_reported_with_hash_and_listener(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            root = pathlib.Path(tmp_dir)
            agent = self.make_agent(root)
            config = root / "external-uot.json"
            config.write_text(
                json.dumps({"listen_port": 7443, "forwards": {"170": {"targets": []}}}) + "\n",
                encoding="utf-8",
            )
            binary = root / "leikwan-external-uot-go"
            binary.write_bytes(b"sidecar-binary")

            def fake_which(command: str):
                return f"/usr/bin/{command}"

            def fake_run(args, **kwargs):
                args = list(args)
                if args[0] == str(binary) and args[1] == "--version":
                    return SimpleNamespace(returncode=0, stdout="2026.09.17.2.95535c6\n", stderr="")
                if args[0] == str(binary) and args[1] == "--check-config":
                    return SimpleNamespace(returncode=0, stdout="", stderr="")
                if args[:2] == ["systemctl", "is-active"]:
                    return SimpleNamespace(returncode=0, stdout="active\n", stderr="")
                if args[:2] == ["systemctl", "is-enabled"]:
                    return SimpleNamespace(returncode=0, stdout="enabled\n", stderr="")
                if args[:2] == ["ss", "-lntH"]:
                    return SimpleNamespace(returncode=0, stdout="LISTEN 0 100 0.0.0.0:7443 0.0.0.0:*\n", stderr="")
                raise AssertionError(args)

            with mock.patch.dict(
                os.environ,
                {
                    "LEIKWAN_EXTERNAL_UOT_CONFIG": str(config),
                    "LEIKWAN_EXTERNAL_UOT_BINARY": str(binary),
                },
                clear=False,
            ):
                with mock.patch.object(sidecar_agent.shutil, "which", side_effect=fake_which):
                    with mock.patch.object(sidecar_agent.subprocess, "run", side_effect=fake_run):
                        status = agent.external_uot_status()

            self.assertTrue(status["required"])
            self.assertTrue(status["healthy"])
            self.assertEqual("online", status["state"])
            self.assertEqual(1, status["forward_count"])
            self.assertEqual("2026.09.17.2.95535c6", status["binary_version"])
            self.assertTrue(status["listening"])

if __name__ == "__main__":
    unittest.main()
