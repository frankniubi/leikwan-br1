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


client_agent_module = load_module(
    "client_agent_health_grace_under_test",
    "client-agent/client_agent.py",
)


class ClientAgentMptcpHealthGraceTests(unittest.TestCase):
    def test_load_bond_leg_reputation_snapshots_reads_forward_json_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                    }
                ),
                encoding="utf-8",
            )
            (snapshot_dir / "bond-leg-health-forward-26.json").write_text(
                json.dumps({"forward_id": 26, "legs": [{"host": "8.163.90.235"}]}),
                encoding="utf-8",
            )
            (snapshot_dir / "bond-leg-health-forward-99.json").write_text(
                json.dumps({"forward_id": 99, "legs": [{"host": "8.163.90.132"}]}),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                snapshots = agent.load_bond_leg_reputation_snapshots()

        self.assertEqual([item.get("forward_id") for item in snapshots], [26, 99])

    def test_mptcp_setup_in_progress_skips_health_restart(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            forward = {
                "forward_id": 25,
                "service_status": "active",
                "mode": "mptcp",
                "listen_port": 11023,
                "mptcp": {
                    "sidecar_host": "10.203.107.179",
                    "sidecar_port": 9000,
                },
            }

            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "mptcp_setup_in_progress", return_value=True):
                    with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                        with mock.patch.object(agent, "local_listener_present", side_effect=AssertionError("should skip probe while setup is in progress")):
                            with mock.patch.object(agent, "remote_tcp_probe_any", side_effect=AssertionError("should skip probe while setup is in progress")):
                                agent.ensure_runtime_health([forward])

        restart_mock.assert_not_called()

    def test_auto_update_request_token_can_be_disabled_for_live_runtime_experiments(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "installer_version": "2026.04.19.1",
                        "disable_auto_update": True,
                    }
                ),
                encoding="utf-8",
            )
            agent = client_agent_module.ClientAgent(config_path)

            token = agent.auto_update_request_token(
                {
                    "desired_agent_version": "2099.01.01.1",
                    "desired_agent_sha256": "0" * 64,
                    "desired_installer_version": "2099.01.01.1",
                }
            )

        self.assertEqual("", token)

    def test_apply_management_state_ignores_stale_explicit_update_when_versions_match(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 123,
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "installer_version": "2026.04.19.1",
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent_module.ClientAgent(config_path)
            desired_sha256 = client_agent_module.file_sha256(REPO_ROOT / "client-agent" / "client_agent.py")

            with mock.patch.object(agent, "request_self_update") as update_mock:
                agent.apply_management_state(
                    {
                        "agent_update_request_token": "stale-explicit-token",
                        "desired_agent_version": client_agent_module.AGENT_VERSION,
                        "desired_agent_sha256": desired_sha256,
                        "desired_installer_version": "2026.04.19.1",
                    }
                )

            update_mock.assert_not_called()
            update_state = agent.load_update_state()
            self.assertEqual(update_state.get("last_completed_request_token"), "stale-explicit-token")
            self.assertEqual(update_state.get("status"), "success")


if __name__ == "__main__":
    unittest.main()
