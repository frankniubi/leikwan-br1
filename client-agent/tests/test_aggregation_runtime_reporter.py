import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_client_agent_module():
    path = REPO_ROOT / "client-agent" / "client_agent.py"
    spec = importlib.util.spec_from_file_location("client_agent_aggregation_runtime_reporter_under_test", path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


client_agent = load_client_agent_module()


class AggregationRuntimeReporterTests(unittest.TestCase):
    def test_check_reports_update_applied_and_runtime_capability_without_live_pull(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            root = pathlib.Path(tmp_dir)
            state_dir = root / "state"
            state_dir.mkdir(parents=True)
            config_path = root / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 47,
                        "agent_token": "token",
                        "state_dir": str(state_dir),
                    }
                ),
                encoding="utf-8",
            )
            (state_dir / "agent_update_state.json").write_text(
                json.dumps({"status": "success", "last_download_bytes": 1234}),
                encoding="utf-8",
            )
            (state_dir / "applied.json").write_text(
                json.dumps(
                    {
                        "forward_id": 47,
                        "backend_mode": "leigod_mode",
                        "client_mode": "bond",
                        "runtime_preference": "single",
                        "bond_ingress_mode": "plain_tcp_mux",
                        "active_family": "standalone_bond",
                        "desired_generation": 8,
                        "desired_leg_set_hash": "a" * 64,
                    }
                ),
                encoding="utf-8",
            )

            agent = client_agent.ClientAgent(config_path)
            with mock.patch.object(agent, "systemd_is_active", return_value=True), \
                mock.patch.object(agent, "ensure_wireguard_identity", return_value="pub"), \
                mock.patch.object(agent, "wireguard_listen_port", return_value=51820), \
                mock.patch.object(agent, "wireguard_fallback_tcp_port", return_value=0), \
                mock.patch.object(agent, "bond_binary_path", return_value=root / "leikwan-bond"), \
                mock.patch.object(agent, "binary_available", return_value=True), \
                mock.patch.object(agent, "mptcp_client_path", return_value=root / "leikwan-mptcp-client"), \
                mock.patch.object(agent, "mptcp_capability", return_value={"supported": False, "diagnostic_only": True}):
                summary = agent.check(live=False)

        self.assertTrue(summary["ok"])
        self.assertEqual(summary["agent_update_state"]["last_download_bytes"], 1234)
        self.assertEqual(summary["update_state"]["status"], "success")
        self.assertEqual(summary["applied"]["active_family"], "standalone_bond")
        self.assertEqual(summary["runtime"]["mptcp_capability"], {"supported": False, "diagnostic_only": True})
        self.assertTrue(summary["runtime"]["bond_bin_present"])
        self.assertFalse(summary["runtime"]["mptcp_client_bin_present"])
        self.assertNotIn("live_pull", summary)


if __name__ == "__main__":
    unittest.main()
