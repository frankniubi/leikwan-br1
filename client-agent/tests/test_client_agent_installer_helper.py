import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class ClientAgentInstallerHelperTests(unittest.TestCase):
    def test_installer_deploys_update_helper(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("leikwan-client-agent-update", source)
        self.assertIn("download_atomic \"$ARTIFACT_BASE/artifacts/client-agent-update.sh\"", source)


    def test_update_helper_uses_artifact_base_when_present(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn('ARTIFACT_BASE=$(python3 - "${CONFIG_PATH}"', source)
        self.assertIn('${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh', source)
        self.assertIn('--artifact-base "${ARTIFACT_BASE}"', source)

    def test_update_helper_stops_running_agent_before_reinstall(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn("SERVICE_NAME=\"leikwan-client-agent\"", source)
        self.assertIn("systemctl stop \"${SERVICE_NAME}.service\"", source)
        self.assertIn("systemctl start \"${SERVICE_NAME}.service\"", source)
        self.assertIn("LEIKWAN_SKIP_UPDATE_HELPER=1", source)

    def test_installer_skips_replacing_running_update_helper(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("download_atomic()", source)
        self.assertIn("download_atomic \"$ARTIFACT_BASE/artifacts/client-agent-update.sh\" \"$BIN_DIR/leikwan-client-agent-update\"", source)
        self.assertIn("if [[ \"${LEIKWAN_SKIP_UPDATE_HELPER:-0}\" != \"1\" ]]; then", source)

    def test_installer_downloads_cfwg_latency_watchdog_module(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_atomic "$ARTIFACT_BASE/artifacts/cfwg-latency-watchdog.py" "$BIN_DIR/cfwg_latency_watchdog.py"',
            source,
        )

    def test_installer_downloads_bond_binary(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            "download_atomic \"$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64\" \"$BIN_DIR/leikwan-bond\"",
            source,
        )


    def test_installer_downloads_mptcp_relay_binary(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64" "$STATE_DIR/leikwan-mptcp-relay"',
            source,
        )

    def test_update_helper_stops_forward_services_before_reinstall(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn("systemctl list-units 'leikwan-client-*.service'", source)
        self.assertIn("systemctl stop \"$unit\"", source)

    def test_update_helper_does_not_stop_its_own_transient_update_unit(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn('UPDATE_UNIT_PREFIX="leikwan-client-agent-update"', source)
        self.assertIn('grep -v "^${UPDATE_UNIT_PREFIX}"', source)

    def test_update_helper_handles_systemctl_marker_rows(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn('if (unit !~ /^[[:alnum:]_.@-]+\\.service$/', source)
        self.assertIn('sub(/^[^[:alnum:]_.@-]+/, "", unit)', source)
        self.assertIn("grep -E '^leikwan-client-[^[:space:]]+\\.service$'", source)

    def test_installer_ensures_time_sync(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("ensure_time_sync()", source)
        self.assertIn("timedatectl show -p NTPSynchronized --value", source)
        self.assertIn("apt-get install -y ntp", source)
        self.assertIn("ensure_time_sync", source)

    def test_legacy_forward_installer_downloads_bond_runtimes_for_fresh_hosts(self) -> None:
        source = (REPO_ROOT / "install" / "install-client.sh").read_text(encoding="utf-8")

        self.assertIn(
            "curl -fsSL \"$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64\" -o \"$BIN_DIR/leikwan-ingress\"",
            source,
        )
        self.assertIn(
            "curl -fsSL \"$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64\" -o \"$BIN_DIR/leikwan-bond\"",
            source,
        )


if __name__ == "__main__":
    unittest.main()
