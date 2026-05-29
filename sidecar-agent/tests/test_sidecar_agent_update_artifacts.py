import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
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


sidecar_agent = load_module("sidecar_agent_update_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarAgentUpdateArtifactTests(unittest.TestCase):
    def test_auto_update_token_includes_wss_mux_sha_mismatch(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({"state_dir": str(tmp / "state"), "disable_auto_update": False}) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)

            token = agent.auto_update_request_token({"desired_wss_mux_sha256": "0" * 64})

            self.assertIn("wss_mux=0000000000000000000000000000000000000000000000000000000000000000", token)

    def test_auto_update_token_includes_sidecar_runtime_sha_mismatch(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({"state_dir": str(tmp / "state"), "disable_auto_update": False}) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)

            token = agent.auto_update_request_token({"desired_sidecar_runtime_sha256": "0" * 64})

            self.assertIn("sidecar_runtime=0000000000000000000000000000000000000000000000000000000000000000", token)

    def test_config_patch_can_unpin_disable_auto_update_before_update_decision(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({"state_dir": str(tmp / "state"), "disable_auto_update": True}) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)

            token = agent.auto_update_request_token(
                {
                    "config_patch": {"disable_auto_update": None},
                    "desired_sidecar_agent_version": "2099.01.01.1",
                }
            )

            self.assertIn("agent=2099.01.01.1", token)

    def test_disable_auto_update_lock_blocks_panel_unpin_and_update_token(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({
                    "state_dir": str(tmp / "state"),
                    "disable_auto_update": True,
                    "disable_auto_update_lock": True,
                }) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)

            agent.apply_config_patch({"disable_auto_update": None})
            token = agent.auto_update_request_token({"desired_sidecar_agent_version": "2099.01.01.1"})

            persisted = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertTrue(persisted["disable_auto_update"])
            self.assertTrue(persisted["disable_auto_update_lock"])
            self.assertEqual("", token)

    def test_apply_management_uses_config_patch_unpin_before_update_gate(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({"state_dir": str(tmp / "state"), "disable_auto_update": True}) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)
            update_tokens: list[str] = []
            agent.request_self_update = update_tokens.append  # type: ignore[method-assign]

            agent.apply_management(
                {
                    "config_patch": {
                        "service_name": "leikwan-bond-sidecar-5",
                        "state_dir": str(tmp / "state"),
                        "runtime_config_path": str(tmp / "runtime.json"),
                        "disable_auto_update": None,
                    },
                    "desired_sidecar_agent_version": "2099.01.01.1",
                    "agent_update_request_token": "manual-unpin-test",
                    "bond_runtime": {"listen_port": 14969},
                }
            )

            self.assertNotIn("disable_auto_update", json.loads(config_path.read_text(encoding="utf-8")))
            self.assertEqual(["manual-unpin-test"], update_tokens)

    def test_apply_management_lock_blocks_explicit_update_request(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({
                    "state_dir": str(tmp / "state"),
                    "disable_auto_update": True,
                    "disable_auto_update_lock": True,
                }) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)
            update_tokens: list[str] = []
            agent.request_self_update = update_tokens.append  # type: ignore[method-assign]

            agent.apply_management(
                {
                    "config_patch": {"disable_auto_update": None},
                    "desired_sidecar_agent_version": "2099.01.01.1",
                    "agent_update_request_token": "manual-unpin-test",
                    "bond_runtime": {"listen_port": 14969},
                }
            )

            self.assertEqual([], update_tokens)

    def test_apply_management_forces_explicit_request_even_without_other_mismatch(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({"state_dir": str(tmp / "state")}) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)
            update_tokens: list[str] = []
            agent.request_self_update = update_tokens.append  # type: ignore[method-assign]

            agent.apply_management(
                {
                    "agent_update_request_token": "manual-force-sidecar-refresh",
                    "bond_runtime": {"listen_port": 14969},
                }
            )

            self.assertEqual(["manual-force-sidecar-refresh"], update_tokens)

    def test_heartbeat_payload_includes_agent_update_state_for_remote_diagnostics(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(json.dumps({"state_dir": str(tmp / "state")}) + "\n", encoding="utf-8")
            agent = sidecar_agent.SidecarAgent(config_path)
            agent.save_update_state(
                {
                    "request_token": "manual-diagnostic",
                    "status": "error",
                    "last_attempt_at": 12345.0,
                    "last_error": "update helper missing",
                    "last_completed_request_token": "older-token",
                }
            )

            payload = agent.build_heartbeat_payload()

            self.assertEqual(
                {
                    "request_token": "manual-diagnostic",
                    "status": "error",
                    "last_attempt_at": 12345.0,
                    "last_error": "update helper missing",
                    "last_completed_request_token": "older-token",
                },
                payload["agent_update_state"],
            )

    def test_heartbeat_payload_includes_agent_sha256_for_precise_update_gating(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(json.dumps({"state_dir": str(tmp / "state")}) + "\n", encoding="utf-8")
            agent = sidecar_agent.SidecarAgent(config_path)

            payload = agent.build_heartbeat_payload()

            self.assertEqual(
                sidecar_agent.file_sha256(pathlib.Path(sidecar_agent.__file__).resolve()),
                payload["agent_sha256"],
            )

    def test_apply_management_keeps_failed_update_launch_throttled_as_running(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            config_path.write_text(
                json.dumps({"state_dir": str(tmp / "state"), "service_name": "leikwan-bond-sidecar-5"}) + "\n",
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)

            def fail_update(_token: str) -> None:
                raise RuntimeError("temporary systemd-run failure")

            with mock.patch.object(sidecar_agent.time, "time", return_value=1779819000.0):
                with mock.patch.object(agent, "request_self_update", side_effect=fail_update):
                    agent.apply_management(
                        {
                            "agent_update_request_token": "manual-sidecar-refresh",
                            "desired_sidecar_agent_version": "2099.01.01.1",
                            "bond_runtime": {"listen_port": 14969},
                        }
                    )

            update_state = agent.load_update_state()
            self.assertEqual(update_state["request_token"], "manual-sidecar-refresh")
            self.assertEqual(update_state["status"], "running")
            self.assertIn("temporary systemd-run failure", update_state["last_error"])

            with mock.patch.object(sidecar_agent.time, "time", return_value=1779819010.0):
                self.assertFalse(agent.should_retry_update(update_state, "manual-sidecar-refresh", 1779819010.0))


if __name__ == "__main__":
    unittest.main()
