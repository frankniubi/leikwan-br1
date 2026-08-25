import json
import pathlib
import tempfile
import unittest
from unittest import mock

try:
    from test_client_agent_mptcp_health_grace import load_module
except ModuleNotFoundError:  # dotted unittest invocation from repo root
    import importlib

    load_module = importlib.import_module(
        "client-agent.tests.test_client_agent_mptcp_health_grace"
    ).load_module


client_agent_module = load_module(
    "client_agent_cf_mode_removed_under_test",
    "client-agent/client_agent.py",
)
install_runtime_module = load_module(
    "install_forward_runtime_cf_mode_removed_under_test",
    "client-agent/install_forward_runtime.py",
)


def cf_forward() -> dict:
    return {
        "forward_id": 47,
        "service_status": "active",
        "backend_mode": "cf_mode",
        "listen_port": 11035,
        "mode": "bond",
        "transport": "tcp",
        "target": "127.0.0.1:5201",
        "endpoints": [
            {"endpoint_id": 317, "host": "198.51.100.17", "port": 7000},
        ],
    }


class CfModeClientRemovalTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
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
        return client_agent_module.ClientAgent(config_path)

    def test_renderer_rejects_cf_mode_without_fallback(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "cf_mode_unsupported"):
            install_runtime_module.render_forward_runtime(cf_forward(), {})

    def test_sync_stops_existing_cf_mode_before_candidate_selection(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.applied_path.write_text(
                json.dumps({"47": {"forward_id": 47, "units": []}}),
                encoding="utf-8",
            )

            with mock.patch.object(
                client_agent_module,
                "http_json_with_headers",
                return_value=({"forwards": [cf_forward()], "client": {"id": 123}}, {}),
            ):
                with mock.patch.object(client_agent_module, "http_json", return_value={}):
                    with mock.patch.object(agent, "apply_management_state"):
                        with mock.patch.object(agent, "stop_forward") as stop_mock:
                            with mock.patch.object(agent, "select_forward_candidates") as select_mock:
                                with mock.patch.object(agent, "apply_forward") as apply_mock:
                                    with mock.patch.object(agent, "reconcile_global_mptcp_state"):
                                        with mock.patch.object(agent, "run_diagnostics_if_requested"):
                                            with mock.patch.object(
                                                agent,
                                                "collect_usage_totals",
                                                return_value={"bytes_in": 0, "bytes_out": 0},
                                            ):
                                                with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                                                    with mock.patch.object(agent, "time_sync_state", return_value={"status": "ok"}):
                                                        agent.sync_once()

            self.assertIn(mock.call(47, delete_files=True), stop_mock.call_args_list)
            select_mock.assert_not_called()
            apply_mock.assert_not_called()
            self.assertEqual([], agent.latest_forwards)
            state = agent.load_pull_state()
            self.assertEqual([47], state["unsupported_forward_ids"])


if __name__ == "__main__":
    unittest.main()
