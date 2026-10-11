import pathlib
import shutil
import subprocess
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def _artifact_source_selection(source: str) -> str:
    start = source.index("PANEL_ARTIFACT_SOURCES=(")
    end = source.index('_VERSIONS_JSON="$(cat "$_VERSIONS_PATH")"')
    return source[start:end]


def _run_bash(script: str) -> subprocess.CompletedProcess:
    # Bytes on stdin: text mode would turn LF into CRLF on Windows.
    completed = subprocess.run(["bash", "-s"], input=script.encode("utf-8"), capture_output=True, check=False)
    return subprocess.CompletedProcess(
        completed.args, completed.returncode, completed.stdout.decode("utf-8"), completed.stderr.decode("utf-8")
    )


class ClientAgentInstallerHelperTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which("bash"), "bash is required")
    def test_unreachable_cloudflare_falls_back_to_the_aliyun_artifact_source(self) -> None:
        # A mainland-only host cannot reach Cloudflare; the Aliyun CDN serves
        # the same artifacts and becomes the download and persisted base.
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        script = "\n".join([
            "set -euo pipefail",
            "die() { echo \"die: $*\" >&2; exit 1; }",
            "leikwan_protected_download() {",
            "  case \"$1\" in https://v3.leikwanhost.com/*) echo 'connect timed out' >&2; return 1 ;; esac",
            "  echo '{}' > \"$2\"",
            "}",
            'STATE_DIR="$(mktemp -d)"',
            "DOWNLOAD_BASE=https://v3.leikwanhost.com",
            "ARTIFACT_BASE=https://v3.leikwanhost.com",
            _artifact_source_selection(source),
            "echo \"download=$DOWNLOAD_BASE artifact=$ARTIFACT_BASE\"",
        ])
        completed = _run_bash(script)
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("artifact source https://v3.leikwanhost.com is unreachable: connect timed out", completed.stderr)
        self.assertIn(
            "download=https://api-leikwanbridge.nbwly.com artifact=https://api-leikwanbridge.nbwly.com",
            completed.stdout,
        )

    def _select_with_stub(self, stub_body: str) -> subprocess.CompletedProcess:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        script = "\n".join([
            "set -euo pipefail",
            "die() { echo \"die: $*\" >&2; exit 1; }",
            "leikwan_protected_download() {",
            stub_body,
            "}",
            'STATE_DIR="$(mktemp -d)"',
            "DOWNLOAD_BASE=https://v3.leikwanhost.com",
            "ARTIFACT_BASE=https://v3.leikwanhost.com",
            "start=$(date +%s)",
            _artifact_source_selection(source),
            "echo \"download=$DOWNLOAD_BASE elapsed=$(( $(date +%s) - start ))\"",
            'echo "leftovers=$(ls "$STATE_DIR" | grep -v "^versions.json.new$" | wc -l)"',
        ])
        return _run_bash(script)

    @unittest.skipUnless(shutil.which("bash"), "bash is required")
    def test_unconnectable_cloudflare_switches_as_soon_as_its_probe_fails(self) -> None:
        # Probes connect to the first address only, with a 3 s limit, so a
        # blackholed Cloudflare fails after about 3 s instead of 8 s per address.
        completed = self._select_with_stub(
            "  case \"$1\" in https://v3.leikwanhost.com/*)\n"
            "    [[ \"$LEIKWAN_SOURCE_PROBE\" == 1 && \"$LEIKWAN_CONNECT_TIMEOUT_SECONDS\" == 3 ]]"
            " || { echo 'probe limits missing' >&2; sleep 20 >/dev/null 2>&1 </dev/null; }\n"
            "    sleep 1; echo 'timed out' >&2; return 1 ;;\n"
            "  esac\n"
            "  echo '{}' > \"$2\""
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("download=https://api-leikwanbridge.nbwly.com", completed.stdout)
        elapsed = int(completed.stdout.split("elapsed=")[1].split()[0])
        self.assertLessEqual(elapsed, 3, completed.stdout)
        self.assertIn("artifact source https://v3.leikwanhost.com is unreachable: timed out", completed.stderr)
        self.assertNotIn("probe limits missing", completed.stderr)

    @unittest.skipUnless(shutil.which("bash"), "bash is required")
    def test_silent_cloudflare_is_abandoned_after_the_grace_period(self) -> None:
        # A stalled source that never finishes is dropped after 10 s.
        completed = self._select_with_stub(
            "  case \"$1\" in https://v3.leikwanhost.com/*) sleep 30 >/dev/null 2>&1 </dev/null; return 1 ;; esac\n"
            "  echo '{}' > \"$2\""
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("download=https://api-leikwanbridge.nbwly.com", completed.stdout)
        elapsed = int(completed.stdout.split("elapsed=")[1].split()[0])
        self.assertLessEqual(elapsed, 12, completed.stdout)
        self.assertIn("artifact source https://v3.leikwanhost.com did not answer within 10s", completed.stderr)
        self.assertIn("leftovers=0", completed.stdout)

    @unittest.skipUnless(shutil.which("bash"), "bash is required")
    def test_answering_cloudflare_is_kept_even_when_aliyun_is_faster(self) -> None:
        # Connected but slow: the whole transfer runs past 3 s, which used to
        # push a healthy host onto Aliyun.
        completed = self._select_with_stub(
            "  case \"$1\" in https://v3.leikwanhost.com/*) sleep 4 ;; esac\n"
            "  echo '{}' > \"$2\""
        )
        self.assertEqual(0, completed.returncode, completed.stderr)
        self.assertIn("download=https://v3.leikwanhost.com", completed.stdout)
        self.assertEqual("", completed.stderr)
        self.assertIn("leftovers=0", completed.stdout)

    @unittest.skipUnless(shutil.which("bash"), "bash is required")
    def test_every_source_down_fails_with_the_sources_tried(self) -> None:
        completed = self._select_with_stub("  echo 'connect timed out' >&2; return 1")
        self.assertNotEqual(0, completed.returncode)
        self.assertIn(
            "die: no artifact source answered (tried: https://v3.leikwanhost.com https://api-leikwanbridge.nbwly.com )",
            completed.stderr,
        )

    @unittest.skipUnless(shutil.which("bash"), "bash is required")
    def test_custom_artifact_source_has_no_fallback(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        script = "\n".join([
            "set -euo pipefail",
            "die() { echo \"die: $*\" >&2; exit 1; }",
            "leikwan_protected_download() { echo \"fetch $1\" >&2; return 1; }",
            'STATE_DIR="$(mktemp -d)"',
            "DOWNLOAD_BASE=http://127.0.0.1:8080",
            "ARTIFACT_BASE=https://v3.leikwanhost.com",
            _artifact_source_selection(source),
        ])
        completed = _run_bash(script)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("fetch http://127.0.0.1:8080/artifacts/versions.json", completed.stderr)
        self.assertNotIn("fetch https://", completed.stderr)
        self.assertIn("die: no artifact source answered (tried: http://127.0.0.1:8080 )", completed.stderr)

    def test_panel_connections_fail_fast_so_the_other_source_is_tried(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        self.assertIn('CONNECT_TIMEOUT_SECONDS = float(os.environ.get("LEIKWAN_CONNECT_TIMEOUT_SECONDS", "8"))', source)
        self.assertIn("addresses = addresses[:1]", source)
        self.assertIn('export LEIKWAN_SOURCE_PROBE=1 LEIKWAN_CONNECT_TIMEOUT_SECONDS="$ARTIFACT_SOURCE_CONNECT_SECONDS"', source)
        self.assertNotIn("socket.create_connection((self.resolved_ip", source)
        self.assertIn('"curl", "--noproxy", "*", "--connect-timeout", str(int(CONNECT_TIMEOUT_SECONDS))', source)

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

    def test_installer_defaults_cloudflare_primary_with_aliyun_mirror_and_watchdog(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        self.assertIn('"panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"]', source)
        self.assertIn('MASTER_URL="https://v3.leikwanhost.com"', source)
        self.assertIn('REQUESTED_MASTER_URL="$MASTER_URL"', source)
        # A lease batch may legitimately spend up to 70 s on the runtime control
        # socket, so 180 s means "wedged", not "busy". PyInstaller one-file
        # sends sd_notify from its extracted child and therefore needs the
        # runtime-specific NotifyAccess policy rendered by the installer.
        self.assertIn("WatchdogSec=180", source)
        self.assertIn('AGENT_NOTIFY_ACCESS="all"', source)
        self.assertIn('AGENT_NOTIFY_ACCESS="main"', source)
        self.assertIn("NotifyAccess=$AGENT_NOTIFY_ACCESS", source)

    def test_installer_downloads_cfwg_latency_watchdog_module(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_manifest_artifact cfwg_latency_watchdog "$DOWNLOAD_BASE/artifacts/cfwg-latency-watchdog.py" "$BIN_DIR/cfwg_latency_watchdog.py"',
            source,
        )

    def test_installer_downloads_bond_binary(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_manifest_artifact leikwan_bond_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-bond_linux_amd64" "$BIN_DIR/leikwan-bond"',
            source,
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
            'leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh" "$TEMP_INSTALLER"',
            source,
        )

    def test_update_helper_refreshes_itself_after_reinstall(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn('LEIKWAN_SKIP_UPDATE_HELPER=1 "$TEMP_INSTALLER"', source)
        self.assertIn(
            'leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/client-agent-update.sh" "$TEMP_UPDATE_HELPER"',
            source,
        )
        self.assertIn('mv "$TEMP_UPDATE_HELPER" /usr/local/bin/leikwan-client-agent-update', source)

    def test_installer_ships_the_tcp_accel_helper_but_no_mptcp_payloads(self) -> None:
        # Client-side split-TCP (install_forward_runtime.render_tcp_accel_client_unit)
        # renders leikwan-client-<id>-tcp-accel units that exec this helper, so the
        # client-agent role must install it from the manifest. The MPTCP client and
        # relay payloads really are retired from this role.
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_manifest_artifact tcp_accel_installer "$DOWNLOAD_BASE/artifacts/install-tcp-accel.sh" "$BIN_DIR/install-tcp-accel.sh"',
            source,
        )
        self.assertIn('"$BIN_DIR/install-tcp-accel.sh" client --artifact-base "$DOWNLOAD_BASE" --bin-dir "$BIN_DIR"', source)
        self.assertNotIn("mptcp_client_runtime", source)
        self.assertNotIn("mptcp-relay", source)
        self.assertIn('if [[ "$required" != "1" && "$AUTO_INSTALL_MPTCP_KERNEL" != "1" ]]; then', source)

    def test_update_helper_preserves_forward_services(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")
        body = source[source.index('write_update_state "running"'):]
        self.assertNotIn('systemctl stop', body)
        self.assertNotIn('systemctl list-units', body)

    def test_update_helper_preflights_before_install(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")
        self.assertLess(source.index('agent preflight mismatch'), source.index('LEIKWAN_SKIP_UPDATE_HELPER=1'))
        self.assertIn("agent version differs from manifest", source)
        self.assertIn("agent hash differs from manifest", source)

    def test_update_helper_serializes_all_request_tokens(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")
        self.assertIn('flock -n 9', source)
        self.assertIn('agent-update.lock', source)

    def test_update_helper_cleanup_preserves_preexisting_runtime(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")
        self.assertIn("restore_previous_runtime_state", source)
        self.assertIn("trap cleanup_update EXIT", source)
        self.assertIn('mktemp -d /tmp/leikwan-client-update.XXXXXX', source)

    def test_installer_bootstrap_covers_all_commands_used_before_download(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        for command in ("dpkg-query", "apt-cache", "timedatectl", "sleep", "seq", "journalctl", "sysctl", "reboot"):
            self.assertIn(command, source)
        self.assertIn('chmod 0755 "$path"', source)
        self.assertIn("ensure_private_dir", source)

    def test_legacy_installer_checks_generated_units_and_cleans_temp_config(self) -> None:
        source = (REPO_ROOT / "install" / "install-client.sh").read_text(encoding="utf-8")

        self.assertIn("cleanup_legacy_install", source)
        self.assertIn("trap cleanup_legacy_install EXIT", source)
        self.assertIn("extra_units", source)
        self.assertIn("check=True", source)
        self.assertIn("systemctl is-active --quiet", source)
        self.assertIn("--data-urlencode", source)

    def test_installer_ensures_time_sync(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("ensure_time_sync()", source)
        self.assertIn("timedatectl show -p NTPSynchronized --value", source)
        self.assertIn("systemd-timesyncd", source)
        self.assertIn("ntpsec", source)
        self.assertIn("chrony", source)
        self.assertIn("systemctl enable --now", source)
        self.assertIn("TIME_SYNC_TIMEOUT_SECONDS", source)
        self.assertNotIn("apt-get install -y ntp\n", source)
        self.assertIn("ensure_time_sync", source)

    def test_installer_bootstraps_clean_debian_dependencies_before_downloads(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("bootstrap_dependencies()", source)
        for package in ("bash", "curl", "ca-certificates", "python3", "coreutils", "grep", "sed", "mawk", "procps", "iproute2", "nftables"):
            self.assertIn(package, source)
        self.assertIn("bootstrap_dependencies", source)
        self.assertIn("unsupported architecture", source)
        self.assertIn('uname -m', source)

    def test_installer_does_not_pull_a_recommended_kernel_for_wireguard_tools(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn("apt-get install -y --no-install-recommends wireguard-tools", source)
        self.assertNotIn("apt-get install -y wireguard-tools", source)

    def test_installer_downloads_and_configures_wstunnel(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_manifest_artifact wstunnel_linux_amd64 "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"',
            source,
        )
        self.assertIn('"wstunnel_bin": "$BIN_DIR/wstunnel"', source)

    def test_installer_has_strict_hash_gate_permissions_rollback_and_health_check(self) -> None:
        source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")

        self.assertIn('expected_sha256="$(_vers_sha256', source)
        self.assertIn("missing manifest sha256", source)
        self.assertIn("umask 077", source)
        self.assertIn('install -d -m 0700 "$CONFIG_DIR" "$STATE_DIR"', source)
        self.assertIn("rollback_install", source)
        self.assertIn("systemctl is-active", source)
        self.assertIn("check --live", source)

    def test_legacy_forward_installer_downloads_bond_runtimes_for_fresh_hosts(self) -> None:
        source = (REPO_ROOT / "install" / "install-client.sh").read_text(encoding="utf-8")

        # The installer resolves every runtime key/path from an arch matrix so
        # amd64 and arm64 hosts share one code path.
        for key, path, binary in (
            ("leikwan_ingress_linux_amd64", "artifacts/wstunnel/leikwan-ingress_linux_amd64", "leikwan-ingress"),
            ("leikwan_bond_linux_amd64", "artifacts/wstunnel/leikwan-bond_linux_amd64", "leikwan-bond"),
            ("wstunnel_linux_amd64", "artifacts/wstunnel/wstunnel_linux_amd64", "wstunnel"),
        ):
            with self.subTest(key=key):
                self.assertIn('KEY="%s"' % key, source)
                self.assertIn('PATH="%s"' % path, source)
                self.assertIn('"$ARTIFACT_BASE/$%s_PATH" "$BIN_DIR/%s"' % (
                    {"leikwan_ingress_linux_amd64": "INGRESS_RUNTIME",
                     "leikwan_bond_linux_amd64": "BOND_RUNTIME",
                     "wstunnel_linux_amd64": "WSTUNNEL_RUNTIME"}[key], binary), source)

    def test_update_helper_verifies_installer_against_manifest_before_execution(self) -> None:
        source = (REPO_ROOT / "install" / "client-agent-update.sh").read_text(encoding="utf-8")

        self.assertIn("client_agent_installer", source)
        self.assertIn("client_agent_update", source)
        self.assertIn("sha256sum -c", source)
        self.assertIn("installer sha256 mismatch", source)
        self.assertIn("update helper sha256 mismatch", source)


if __name__ == "__main__":
    unittest.main()
