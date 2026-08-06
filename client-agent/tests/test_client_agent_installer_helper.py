import hashlib
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_client_agent_module():
    path = REPO_ROOT / "client-agent" / "client_agent.py"
    spec = importlib.util.spec_from_file_location("client_agent_installer_helper_under_test", path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


client_agent = load_client_agent_module()


class ClientAgentInstallerHelperTests(unittest.TestCase):
    def make_agent_with_pathd(self, content: bytes | None):
        temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(temp_dir.cleanup)
        root = pathlib.Path(temp_dir.name)
        pathd_bin = root / "bin" / "leikwan-pathd"
        if content is not None:
            pathd_bin.parent.mkdir(parents=True)
            pathd_bin.write_bytes(content)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.test",
                    "agent_token": "agent-token",
                    "client_id": 20,
                    "state_dir": str(root / "state"),
                    "pathd_bin": str(pathd_bin),
                }
            ),
            encoding="utf-8",
        )
        return client_agent.ClientAgent(config_path)

    @staticmethod
    def pathd_metadata(content: bytes) -> dict[str, str]:
        return {
            "desired_pathd_version": "0.2.0",
            "desired_pathd_sha256": hashlib.sha256(content).hexdigest(),
        }

    def test_auto_update_requests_installer_when_pathd_is_missing(self) -> None:
        expected_content = b"current-pathd"
        agent = self.make_agent_with_pathd(None)

        token = agent.auto_update_request_token(self.pathd_metadata(expected_content))

        self.assertIn("pathd=0.2.0", token)
        self.assertIn(hashlib.sha256(expected_content).hexdigest(), token)

    def test_auto_update_requests_installer_when_pathd_is_stale(self) -> None:
        expected_content = b"current-pathd"
        agent = self.make_agent_with_pathd(b"stale-pathd")

        token = agent.auto_update_request_token(self.pathd_metadata(expected_content))

        self.assertIn("pathd=0.2.0", token)
        self.assertIn(hashlib.sha256(expected_content).hexdigest(), token)

    def test_auto_update_skips_matching_pathd(self) -> None:
        expected_content = b"current-pathd"
        agent = self.make_agent_with_pathd(expected_content)

        self.assertEqual("", agent.auto_update_request_token(self.pathd_metadata(expected_content)))

    def test_auto_update_remains_compatible_without_pathd_metadata(self) -> None:
        agent = self.make_agent_with_pathd(None)

        self.assertEqual("", agent.auto_update_request_token({}))

    def test_installer_deploys_update_helper(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("leikwan-client-agent-update", source)
        self.assertIn("download_atomic \"$ARTIFACT_BASE/artifacts/client-agent-update.sh\"", source)
        self.assertIn("leikwan_protected_download", source)


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

    def test_installer_downloads_and_pins_pathd_binary(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_manifest_artifact leikwan_pathd_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-pathd_linux_amd64" "$BIN_DIR/leikwan-pathd"',
            source,
        )
        self.assertIn(
            'download_manifest_artifact leigod_linux_amd64 "$ARTIFACT_BASE/artifacts/leigod/leigod-linux-amd64" "$BIN_DIR/leigod-linux-amd64"',
            source,
        )
        self.assertIn("missing or invalid artifact sha256", source)
        self.assertIn('chmod 0755 "$BIN_DIR/leikwan-pathd"', source)
        self.assertIn('"pathd_bin": "/usr/local/bin/leikwan-pathd"', source)
        self.assertIn('"leigod_bin": "/usr/local/bin/leigod-linux-amd64"', source)

        helper = source[source.index("download_manifest_artifact() {") : source.index("_VERSIONS_PATH=")]
        self.assertIn('tmp_path="${path}.new"', helper)
        self.assertIn('check_binary_current "$tmp_path" "$expected_sha256"', helper)
        self.assertLess(
            helper.index('check_binary_current "$tmp_path" "$expected_sha256"'),
            helper.index('mv "$tmp_path" "$path"'),
        )

    def test_installer_uses_protected_downloader_for_artifact_urls(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("leikwan_protected_download()", source)
        self.assertIn("python3 - \"$url\" \"$path\" <<'PY'", source)
        self.assertIn("is_protected_leikwan_url", source)
        self.assertIn("STRICT_DOH_PROVIDERS", source)
        self.assertNotIn("curl -fsSL \"$url\" -o \"$path\"", source)
        self.assertNotIn("curl -fsSL \"$url\" -o \"$tmp_path\"", source)
        self.assertIn("leikwan_protected_download \"$url\" \"$path\"", source)
        self.assertIn("leikwan_protected_download \"$url\" \"$tmp_path\"", source)

    def test_update_helper_uses_protected_downloader_for_installer_artifact(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn("leikwan_protected_download()", source)
        self.assertIn("python3 - \"$url\" \"$path\" <<'PY'", source)
        self.assertIn("is_protected_leikwan_url", source)
        self.assertIn("STRICT_DOH_PROVIDERS", source)
        self.assertNotIn("curl --connect-timeout 15 --max-time 300 --retry 3 --retry-delay 2 -fsSL", source)
        self.assertIn(
            'leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh" /tmp/leikwan-install-client-agent.sh',
            source,
        )

    def test_update_helper_refreshes_itself_after_reinstall(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn("LEIKWAN_SKIP_UPDATE_HELPER=1 /tmp/leikwan-install-client-agent.sh", source)
        self.assertIn(
            'leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/client-agent-update.sh" /tmp/leikwan-client-agent-update.sh',
            source,
        )
        self.assertIn("mv /tmp/leikwan-client-agent-update.sh /usr/local/bin/leikwan-client-agent-update", source)

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
