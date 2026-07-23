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


sidecar_agent_module = load_module(
    "sidecar_agent_dual_runtime_under_test",
    "sidecar-agent/sidecar_agent.py",
)


class SidecarDualRuntimeRegressionTests(unittest.TestCase):
    def test_apply_management_writes_bond_and_mptcp_runtime_units(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "point-4-state"),
                        "service_name": "leikwan-bond-sidecar-4",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "bond-runtime.json"),
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            writes: dict[str, str] = {}

            def fake_write(path: pathlib.Path, content: str, mode=None):  # noqa: ANN001
                writes[str(path).replace("\\", "/")] = content
                return True

            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "systemd_available", return_value=True):
                    with mock.patch.object(agent, "systemd_is_active", return_value=True):
                        with mock.patch.object(sidecar_agent_module, "write_text_if_changed", side_effect=fake_write):
                            with mock.patch.object(sidecar_agent_module.subprocess, "run") as run_mock:
                                agent.apply_management(
                                {
                                    "bond_runtime": {
                                        "bind_host": "0.0.0.0",
                                        "listen_port": 9000,
                                        "token_secret": "bond-secret",
                                    },
                                    "mptcp_runtime": {
                                        "bind_host": "0.0.0.0",
                                        "listen_port": 14969,
                                        "token_secret": "mptcp-secret",
                                        "underlays": [
                                            {
                                                "interface_name": "lw-sc-4-0",
                                                "listen_port": 14985,
                                                "legs": [
                                                    {
                                                        "endpoint_id": 179,
                                                        "peer_public_key": "peer-key",
                                                        "peer_public_host": "8.163.90.132",
                                                        "peer_udp_port": 9975,
                                                        "local_address": "10.203.107.179",
                                                        "peer_address": "10.203.107.178",
                                                        "mptcp_remote_address": "10.203.107.177",
                                                    }
                                                ],
                                            }
                                        ],
                                        "mptcp_policy": {
                                            "endpoint_id_base": 31,
                                            "endpoints": [
                                                {
                                                    "address": "10.203.107.179",
                                                    "dev": "lw-sc-4-0",
                                                    "signal": True,
                                                }
                                            ],
                                        },
                                    },
                                }
                            )

            bond_runtime_path = str(pathlib.Path(tmp_dir) / "bond-runtime.json").replace("\\", "/")
            mptcp_runtime_path = str(pathlib.Path(tmp_dir) / "mptcp-runtime.json").replace("\\", "/")
            bond_unit_path = "/etc/systemd/system/leikwan-bond-sidecar-4.service"
            mptcp_unit_path = "/etc/systemd/system/leikwan-mptcp-sidecar-4.service"

            self.assertIn(bond_runtime_path, writes)
            self.assertIn(mptcp_runtime_path, writes)
            self.assertIn(bond_unit_path, writes)
            self.assertIn(mptcp_unit_path, writes)
            self.assertIn("leikwan-bond", writes[str(pathlib.Path(tmp_dir) / "point-4-state" / "start-sidecar.sh").replace("\\", "/")])
            self.assertIn("leikwan-mptcp-relay", writes[str(pathlib.Path(tmp_dir) / "point-4-state" / "start-mptcp-sidecar.sh").replace("\\", "/")])

            bond_runtime = json.loads(writes[bond_runtime_path])
            mptcp_runtime = json.loads(writes[mptcp_runtime_path])
            self.assertEqual(bond_runtime["listen_port"], 9000)
            self.assertEqual(mptcp_runtime["listen_port"], 14969)
            self.assertIn("underlays", mptcp_runtime)
            self.assertNotIn("underlays", bond_runtime)

            commands = [" ".join(call.args[0]) if isinstance(call.args[0], list) else str(call.args[0]) for call in run_mock.call_args_list]
            self.assertTrue(any("leikwan-bond-sidecar-4.service" in command for command in commands))
            self.assertTrue(any("leikwan-mptcp-sidecar-4.service" in command for command in commands))

    def test_sidecar_agent_creates_udp_uot_runtime_when_enabled(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            state_dir = pathlib.Path(tmp_dir) / "point-4-state"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                        "service_name": "leikwan-bond-sidecar-4",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "bond-runtime.json"),
                        "udp_uot_sidepath": {
                            "enabled_forward_ids": [26],
                            "sidecar_port": 8999,
                            "reorder_window_packets": 4096,
                            "reorder_window_bytes": 16777216,
                            "reorder_timeout_ms": 25,
                        },
                    }
                ),
                encoding="utf-8",
            )
            agent = sidecar_agent_module.SidecarAgent(config_path)
            writes: dict[str, str] = {}
            management = {
                "bond_runtime": {
                    "bind_host": "0.0.0.0",
                    "listen_port": 9000,
                    "token_secret": "secret-123",
                }
            }

            def fake_write(path: pathlib.Path, content: str, mode=None):  # noqa: ANN001
                writes[str(path).replace("\\", "/")] = content
                return True

            with mock.patch.object(agent, "ensure_relay_binary") as relay_mock:
                with mock.patch.object(agent, "systemd_available", return_value=True):
                    with mock.patch.object(agent, "systemd_is_active", return_value=True):
                        with mock.patch.object(sidecar_agent_module, "write_text_if_changed", side_effect=fake_write):
                            with mock.patch.object(sidecar_agent_module.subprocess, "run") as run_mock:
                                agent.apply_management_config(management, {})

            relay_mock.assert_not_called()
            runtime_path = str(state_dir / "udp-uot-runtime.json").replace("\\", "/")
            start_script_path = str(state_dir / "start-sidecar-udp-uot.sh").replace("\\", "/")
            unit_path = "/etc/systemd/system/leikwan-bond-sidecar-4-udp-uot.service"
            self.assertIn(runtime_path, writes)
            self.assertIn(start_script_path, writes)
            self.assertIn(unit_path, writes)
            self.assertIn("leikwan-udp-uot-merge", writes[start_script_path])
            self.assertNotIn("/usr/bin/python3 /usr/local/bin/leikwan-udp-uot-merge.py", writes[start_script_path])
            self.assertIn("ExecStart=/bin/bash", writes[unit_path])
            commands = [" ".join(call.args[0]) if isinstance(call.args[0], list) else str(call.args[0]) for call in run_mock.call_args_list]
            self.assertTrue(any("leikwan-bond-sidecar-4-udp-uot.service" in command for command in commands))

            payload = json.loads(writes[runtime_path])
            self.assertEqual(payload["listen_port"], 8999)
            self.assertEqual(payload["token_secret"], "secret-123")
            self.assertEqual(payload["allowed_forward_ids"], [26])


if __name__ == "__main__":
    unittest.main()
