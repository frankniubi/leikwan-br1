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
    "sidecar_agent_update_helper_under_test",
    "sidecar-agent/sidecar_agent.py",
)


class SidecarAgentUpdateHelperTests(unittest.TestCase):
    def test_sidecar_installer_deploys_update_helper(self) -> None:
        source = (REPO_ROOT / "install" / "install-bond-sidecar.sh").read_text(encoding="utf-8")

        self.assertIn("download_atomic()", source)
        self.assertIn(
            'download_atomic "$ARTIFACT_BASE/artifacts/sidecar-agent-update.sh" "$BIN_DIR/leikwan-sidecar-update"',
            source,
        )
        self.assertIn('"installer_version": "$INSTALLER_VERSION"', source)

    def test_sidecar_installer_downloads_panel_hosted_wstunnel_binary(self) -> None:
        source = (REPO_ROOT / "install" / "install-bond-sidecar.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"',
            source,
        )
        self.assertNotIn("github.com/erebe/wstunnel", source)

    def test_versions_manifest_contains_wstunnel_linux_artifact(self) -> None:
        versions = json.loads((REPO_ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8"))

        self.assertIn("wstunnel_linux_amd64", versions)
        self.assertEqual(
            versions["wstunnel_linux_amd64"]["path"],
            "artifacts/wstunnel/wstunnel_linux_amd64",
        )


    def test_client_manifest_contains_mptcp_relay_artifact(self) -> None:
        artifact_controller = (REPO_ROOT / "master" / "src" / "Controllers" / "ArtifactController.php").read_text(encoding="utf-8")
        versions = json.loads((REPO_ROOT / "artifacts" / "versions.json").read_text(encoding="utf-8"))

        self.assertIn("leikwan_mptcp_relay_linux_amd64", versions)
        self.assertIn("'leikwan_mptcp_relay_linux_amd64'", artifact_controller)


    def test_sidecar_update_helper_uses_artifact_base_when_present(self) -> None:
        source = (REPO_ROOT / "install" / "sidecar-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn('ARTIFACT_BASE=$(python3 - "${CONFIG_PATH}"', source)
        self.assertIn('${ARTIFACT_BASE%/}/artifacts/install-bond-sidecar.sh', source)
        self.assertIn('--artifact-base "${ARTIFACT_BASE}"', source)

    def test_sidecar_update_helper_stops_runtime_and_agent_services(self) -> None:
        source = (REPO_ROOT / "install" / "sidecar-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn('AGENT_SERVICE_NAME="${SERVICE_NAME}-agent"', source)
        self.assertIn('systemctl stop "${SERVICE_NAME}.service"', source)
        self.assertIn('systemctl stop "${MPTCP_SERVICE_NAME}.service"', source)
        self.assertIn('systemctl stop "${AGENT_SERVICE_NAME}.service"', source)


    def test_sidecar_agent_config_patch_accepts_artifact_base_url(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "service_name": "leikwan-bond-sidecar-5",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                    }
                ),
                encoding="utf-8",
            )
            agent = sidecar_agent_module.SidecarAgent(config_path)

            agent.apply_config_patch({"artifact_base_url": "https://artifacts.example.com"})

            saved = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertEqual("https://artifacts.example.com", saved["artifact_base_url"])

    def test_sidecar_agent_requests_self_update_for_bond_sha_mismatch(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "service_name": "leikwan-bond-sidecar-4",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                        "installer_version": "2026.04.12.1",
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            desired_agent_sha = sidecar_agent_module.file_sha256((REPO_ROOT / "sidecar-agent" / "sidecar_agent.py").resolve())

            with mock.patch.object(agent, "ensure_relay_binary"):
                with mock.patch.object(agent, "request_self_update") as update_mock:
                    with mock.patch.object(agent, "systemd_is_active", return_value=True):
                        with mock.patch.object(sidecar_agent_module.subprocess, "run"):
                            with mock.patch.object(sidecar_agent_module, "write_text_if_changed", return_value=False):
                                agent.apply_management(
                                    {
                                        "desired_bond_sha256": "deadbeef",
                                        "desired_sidecar_agent_sha256": desired_agent_sha,
                                        "desired_installer_version": "2026.04.12.1",
                                        "bond_runtime": {
                                            "bind_host": "0.0.0.0",
                                            "listen_port": 9000,
                                            "token_secret": "bond-secret",
                                        },
                                    }
                                )

            update_mock.assert_called_once()

    def test_sidecar_installer_ensures_time_sync(self) -> None:
        source = (REPO_ROOT / "install" / "install-bond-sidecar.sh").read_text(encoding="utf-8")

        self.assertIn("ensure_time_sync()", source)
        self.assertIn("timedatectl show -p NTPSynchronized --value", source)
        self.assertIn("apt-get install -y ntp", source)

    def test_sidecar_heartbeat_persists_clock_state(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "agent_token": "token",
                        "state_dir": str(pathlib.Path(tmp_dir) / "state"),
                        "service_name": "leikwan-bond-sidecar-4",
                        "runtime_config_path": str(pathlib.Path(tmp_dir) / "runtime.json"),
                        "installer_version": "2026.04.12.1",
                    }
                ),
                encoding="utf-8",
            )

            agent = sidecar_agent_module.SidecarAgent(config_path)
            with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
                with mock.patch.object(agent, "apply_management"):
                    with mock.patch.object(
                        agent,
                        "time_sync_state",
                        return_value={"synchronized": True, "service": "ntp", "status": "ok"},
                    ):
                        with mock.patch.object(
                            sidecar_agent_module,
                            "http_json_with_headers",
                            return_value=({"management": {}}, {"date": "Sat, 18 Apr 2026 14:00:00 GMT"}),
                        ):
                            with mock.patch.object(sidecar_agent_module.time, "time", return_value=1776520800.0):
                                agent.heartbeat_once()

            state = json.loads(agent.pull_state_path.read_text(encoding="utf-8"))
            self.assertIn("clock", state)
            self.assertEqual(state["clock"]["status"], "ok")


if __name__ == "__main__":
    unittest.main()
