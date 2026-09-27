"""Behavioural tests: a no-op update must not restart the client agent.

install-client-agent.sh used to rewrite config.json and the unit file and run
daemon-reload + restart + a live health check on every run, even when every
artifact was already current, so each (non-converging) update restarted the
agent. install-tcp-accel.sh ran apt-get on every run.

These tests execute the real shell code with stub ``systemctl``/``apt-get``/
``curl`` commands on PATH. They need a POSIX bash: on Windows that is Git for
Windows' bash (never the WSL ``System32\\bash.exe``); when none is found the
tests are skipped.
"""

from __future__ import annotations

import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
import unittest.mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
CLIENT_INSTALLER = REPO_ROOT / "install" / "install-client-agent.sh"
TCP_ACCEL_INSTALLER = REPO_ROOT / "install" / "install-tcp-accel.sh"


def find_posix_bash() -> str | None:
    candidates: list[pathlib.Path] = []
    if os.name == "nt":
        git = shutil.which("git")
        if git:
            git_root = pathlib.Path(git).resolve().parents[1]
            candidates += [git_root / "bin" / "bash.exe", git_root / "usr" / "bin" / "bash.exe"]
        candidates.append(pathlib.Path(r"C:\Program Files\Git\bin\bash.exe"))
    else:
        found = shutil.which("bash")
        if found:
            candidates.append(pathlib.Path(found))
    for candidate in candidates:
        if candidate.is_file():
            return str(candidate)
    return None


BASH = find_posix_bash()


def posix(path: pathlib.Path) -> str:
    return path.as_posix()


def extract_shell_functions(source: str, names: list[str]) -> str:
    """Return the top-level definitions of ``names`` (heredoc-aware)."""
    lines = source.splitlines(keepends=True)
    heredoc = re.compile(r"<<-?\s*['\"]?([A-Za-z_][A-Za-z0-9_]*)['\"]?")
    found: dict[str, str] = {}
    index = 0
    while index < len(lines):
        match = re.match(r"^([A-Za-z_][A-Za-z0-9_]*)\(\) \{\s*$", lines[index])
        if not match or match.group(1) not in names:
            index += 1
            continue
        name = match.group(1)
        body = [lines[index]]
        index += 1
        terminator = None
        while index < len(lines):
            line = lines[index]
            body.append(line)
            index += 1
            if terminator is not None:
                if line.rstrip("\n") == terminator:
                    terminator = None
                continue
            tag = heredoc.search(line)
            if tag:
                terminator = tag.group(1)
                continue
            if line.rstrip("\n") == "}":
                break
        found[name] = "".join(body)
    missing = [name for name in names if name not in found]
    if missing:
        raise AssertionError(f"shell functions not found: {missing}")
    return "".join(found[name] for name in names)


def write_executable(path: pathlib.Path, text: str) -> None:
    path.write_text(text, encoding="utf-8", newline="\n")
    path.chmod(0o755)


def write_common_stubs(stub_dir: pathlib.Path) -> None:
    stub_dir.mkdir(parents=True, exist_ok=True)
    write_executable(stub_dir / "python3", f'#!/usr/bin/env bash\nexec "{posix(pathlib.Path(sys.executable))}" "$@"\n')
    write_executable(
        stub_dir / "systemctl",
        "#!/usr/bin/env bash\n"
        'echo "$*" >> "$STUB_LOG_DIR/systemctl.log"\n'
        'case "$1" in\n'
        '  is-active) [[ -f "$STUB_LOG_DIR/active" ]] ;;\n'
        '  is-enabled) [[ -f "$STUB_LOG_DIR/enabled" ]] ;;\n'
        '  restart|start) : > "$STUB_LOG_DIR/active" ;;\n'
        '  enable) : > "$STUB_LOG_DIR/enabled" ;;\n'
        "  *) exit 0 ;;\n"
        "esac\n",
    )
    write_executable(stub_dir / "apt-get", '#!/usr/bin/env bash\necho "$*" >> "$STUB_LOG_DIR/apt-get.log"\n')
    if os.name == "nt":
        # Git for Windows' coreutils `install -m` cannot chmod NTFS paths
        # ("Permission denied"); modes are irrelevant to these tests.
        write_executable(
            stub_dir / "install",
            "#!/usr/bin/env bash\n"
            "dirs=0; args=()\n"
            'while [[ $# -gt 0 ]]; do\n'
            '  case "$1" in\n'
            "    -d) dirs=1; shift ;;\n"
            "    -m) shift 2 ;;\n"
            '    *) args+=("$1"); shift ;;\n'
            "  esac\n"
            "done\n"
            'if [[ "$dirs" == "1" ]]; then mkdir -p "${args[@]}"; else cp "${args[@]}"; fi\n',
        )


def sha256_file(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_log(path: pathlib.Path) -> list[str]:
    return path.read_text(encoding="utf-8").splitlines() if path.exists() else []


@unittest.skipIf(BASH is None, "POSIX bash (Git for Windows bash on Windows) is not available")
class ClientAgentInstallerNoopRestartTests(unittest.TestCase):
    FUNCTIONS = [
        "mark_install_changed",
        "files_identical",
        "install_file_if_changed",
        "backup_existing",
        "remove_legacy_proxy_env",
        "check_binary_current",
        "download_manifest_artifact",
        "validate_agent_config",
        "agent_config_current",
        "install_agent_config",
        "render_agent_unit",
        "install_agent_unit",
        "verify_agent_service",
        "finalize_agent_service",
    ]

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self._tmp.name)
        self.stub_dir = self.base / "stubs"
        self.log_dir = self.base / "logs"
        self.bin_dir = self.base / "bin"
        self.config_dir = self.base / "etc"
        self.state_dir = self.base / "state"
        self.unit_path = self.base / "units" / "leikwan-client-agent.service"
        self.artifacts = self.base / "artifacts"
        for path in (self.log_dir, self.bin_dir, self.config_dir, self.state_dir, self.unit_path.parent, self.artifacts):
            path.mkdir(parents=True, exist_ok=True)
        write_common_stubs(self.stub_dir)
        write_executable(
            self.bin_dir / "leikwan-client-agent",
            '#!/usr/bin/env bash\necho \'{"ok": true, "runtime": {"wstunnel_bin_present": true}}\'\n',
        )
        (self.artifacts / "install-forward-runtime.py").write_text("# renderer v1\n", encoding="utf-8", newline="\n")
        self.functions = extract_shell_functions(CLIENT_INSTALLER.read_text(encoding="utf-8"), self.FUNCTIONS)
        self.staged_config = {
            "master_url": "https://v3.leikwanhost.com",
            "panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"],
            "artifact_base_url": "https://v3.leikwanhost.com",
            "client_id": 5,
            "agent_token": "agent-token",
            "state_dir": posix(self.state_dir),
            "log_level": "INFO",
            "installer_version": "2026.09.24.1",
            "installer_sha256": "a" * 64,
            "runtime_binary_mode": "frozen",
            "client_agent_bin": posix(self.bin_dir / "leikwan-client-agent"),
        }

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def run_install(self, *, force: bool = False, config: dict | None = None) -> str:
        staged = self.base / "staged-config.json"
        staged.write_text(json.dumps(config or self.staged_config, indent=2) + "\n", encoding="utf-8", newline="\n")
        expected_sha = sha256_file(self.artifacts / "install-forward-runtime.py")
        driver = self.base / "driver.sh"
        driver.write_text(
            "set -euo pipefail\n"
            f'export PATH="$(cd "{posix(self.stub_dir)}" && pwd):$PATH"\n'
            f'export STUB_LOG_DIR="{posix(self.log_dir)}"\n'
            f'BIN_DIR="{posix(self.bin_dir)}"\n'
            f'CONFIG_DIR="{posix(self.config_dir)}"\n'
            f'STATE_DIR="{posix(self.state_dir)}"\n'
            'SERVICE_NAME="leikwan-client-agent"\n'
            'AGENT_RUNTIME_MODE="frozen"\n'
            f'AGENT_BINARY_PATH="{posix(self.bin_dir / "leikwan-client-agent")}"\n'
            'LEGACY_PROXY_ENV_FILE="$CONFIG_DIR/proxy.env"\n'
            f'FORCE_RESTART="{1 if force else 0}"\n'
            "INSTALL_CHANGED=0\n"
            "UNIT_CHANGED=0\n"
            "declare -a INSTALL_CHANGE_REASONS=()\n"
            'ROLLBACK_DIR="$STATE_DIR/.rollback-test"\n'
            'install -d -m 0700 "$ROLLBACK_DIR"\n'
            "declare -a BACKUP_PATHS=()\n"
            "declare -A BACKUP_EXISTS=()\n"
            'die() { echo "die: $*" >&2; exit 1; }\n'
            f'_vers_sha256() {{ echo "{expected_sha}"; }}\n'
            "leikwan_protected_download() {\n"
            '  echo "$1" >> "$STUB_LOG_DIR/download.log"\n'
            f'  cp "{posix(self.artifacts)}/$(basename "$1")" "$2"\n'
            "}\n"
            + self.functions
            + "remove_legacy_proxy_env\n"
            + 'download_manifest_artifact install_forward_runtime "https://v3.leikwanhost.com/artifacts/install-forward-runtime.py" "$BIN_DIR/install_forward_runtime.py"\n'
            f'cp "{posix(staged)}" "$CONFIG_DIR/config.json.new"\n'
            'install_agent_config "$CONFIG_DIR/config.json.new"\n'
            f'install_agent_unit "{posix(self.unit_path)}"\n'
            "finalize_agent_service\n"
            'echo "RESTARTED=$AGENT_SERVICE_RESTARTED"\n',
            encoding="utf-8",
            newline="\n",
        )
        for log_name in ("systemctl.log", "download.log"):
            (self.log_dir / log_name).unlink(missing_ok=True)
        completed = subprocess.run([BASH, posix(driver)], capture_output=True, text=True, timeout=120)
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)
        return completed.stdout

    def systemctl_calls(self) -> list[str]:
        return read_log(self.log_dir / "systemctl.log")

    def test_noop_update_does_not_reload_or_restart_the_agent(self) -> None:
        first = self.run_install()
        self.assertIn("RESTARTED=1", first)
        calls = self.systemctl_calls()
        self.assertIn("daemon-reload", calls)
        self.assertIn("restart leikwan-client-agent.service", calls)
        self.assertTrue((self.state_dir / "install-health.json").is_file())

        # The running agent rewrites control-plane-managed keys from the
        # panel (and drops the trailing newline); that is not a change.
        config_path = self.config_dir / "config.json"
        live_config = json.loads(config_path.read_text(encoding="utf-8"))
        live_config.pop("panel_mirror_urls")
        live_config["log_level"] = "DEBUG"
        live_config["artifact_base_url"] = "https://mirror.example.com"
        live_config["udp_uot_sidepath"] = {"enabled": True}
        config_path.write_text(json.dumps(live_config, ensure_ascii=False, indent=2), encoding="utf-8", newline="\n")
        live_bytes = config_path.read_bytes()
        unit_bytes = self.unit_path.read_bytes()

        second = self.run_install()
        self.assertIn("RESTARTED=0", second)
        calls = self.systemctl_calls()
        self.assertFalse([call for call in calls if call.startswith(("restart", "daemon-reload", "start", "stop"))], calls)
        self.assertEqual([], read_log(self.log_dir / "download.log"))
        self.assertEqual(live_bytes, config_path.read_bytes())
        self.assertEqual(unit_bytes, self.unit_path.read_bytes())
        self.assertFalse((self.config_dir / "config.json.new").exists())

    def test_force_restart_restarts_even_when_current(self) -> None:
        self.run_install()
        output = self.run_install(force=True)
        self.assertIn("RESTARTED=1", output)
        calls = self.systemctl_calls()
        self.assertIn("daemon-reload", calls)
        self.assertIn("restart leikwan-client-agent.service", calls)

    def test_changed_artifact_restarts_without_daemon_reload(self) -> None:
        self.run_install()
        (self.artifacts / "install-forward-runtime.py").write_text("# renderer v2\n", encoding="utf-8", newline="\n")
        output = self.run_install()
        self.assertIn("RESTARTED=1", output)
        calls = self.systemctl_calls()
        self.assertIn("restart leikwan-client-agent.service", calls)
        self.assertNotIn("daemon-reload", calls)
        self.assertEqual(1, len(read_log(self.log_dir / "download.log")))

    def test_installer_owned_config_change_restarts(self) -> None:
        self.run_install()
        changed = dict(self.staged_config, agent_token="rotated-token")
        output = self.run_install(config=changed)
        self.assertIn("RESTARTED=1", output)
        self.assertIn("restart leikwan-client-agent.service", self.systemctl_calls())
        self.assertEqual("rotated-token", json.loads((self.config_dir / "config.json").read_text(encoding="utf-8"))["agent_token"])

    def test_inactive_service_is_started_even_when_current(self) -> None:
        self.run_install()
        (self.log_dir / "active").unlink()
        output = self.run_install()
        self.assertIn("RESTARTED=1", output)
        self.assertNotIn("daemon-reload", self.systemctl_calls())

    def test_rendered_unit_has_no_proxy_environment_file(self) -> None:
        self.run_install()
        unit = self.unit_path.read_text(encoding="utf-8")
        self.assertNotIn("EnvironmentFile", unit)
        self.assertNotIn("proxy", unit.lower())
        self.assertFalse((self.config_dir / "proxy.env").exists())

    def test_upgrade_from_proxy_install_removes_proxy_env_and_restarts_once(self) -> None:
        self.run_install()
        # Recreate what the retired --proxy installer left behind: proxy.env,
        # a stale staged copy and the unit's EnvironmentFile line.
        current_unit = self.unit_path.read_text(encoding="utf-8")
        legacy_unit = current_unit.replace(
            "Environment=PYTHONUNBUFFERED=1\n",
            f"Environment=PYTHONUNBUFFERED=1\nEnvironmentFile=-{posix(self.config_dir)}/proxy.env\n",
        )
        self.assertNotEqual(current_unit, legacy_unit)
        self.unit_path.write_text(legacy_unit, encoding="utf-8", newline="\n")
        proxy_env = self.config_dir / "proxy.env"
        proxy_env.write_text("HTTPS_PROXY=http://proxy.corp:3128\n", encoding="utf-8", newline="\n")
        staged = self.config_dir / "proxy.env.new.4242"
        staged.write_text("HTTPS_PROXY=http://proxy.corp:3128\n", encoding="utf-8", newline="\n")

        upgraded = self.run_install()
        self.assertIn("RESTARTED=1", upgraded)
        self.assertIn("removed retired proxy.env", upgraded)
        calls = self.systemctl_calls()
        self.assertEqual(1, calls.count("daemon-reload"), calls)
        self.assertEqual(1, calls.count("restart leikwan-client-agent.service"), calls)
        self.assertFalse(proxy_env.exists())
        self.assertFalse(staged.exists())
        self.assertEqual(current_unit, self.unit_path.read_text(encoding="utf-8"))
        self.assertNotIn("EnvironmentFile", self.unit_path.read_text(encoding="utf-8"))

        second = self.run_install()
        self.assertIn("RESTARTED=0", second)
        self.assertNotIn("removed retired proxy.env", second)
        calls = self.systemctl_calls()
        self.assertFalse([call for call in calls if call.startswith(("restart", "daemon-reload", "start", "stop"))], calls)

    def test_leftover_proxy_env_alone_restarts_without_daemon_reload(self) -> None:
        self.run_install()
        proxy_env = self.config_dir / "proxy.env"
        proxy_env.write_text("", encoding="utf-8")
        output = self.run_install()
        self.assertIn("RESTARTED=1", output)
        calls = self.systemctl_calls()
        self.assertIn("restart leikwan-client-agent.service", calls)
        self.assertNotIn("daemon-reload", calls)
        self.assertFalse(proxy_env.exists())


RETIRED_PROXY_MESSAGE = "--proxy is no longer supported; the agent reaches the panel directly"
CLIENT_UPDATE_HELPER = REPO_ROOT / "install" / "client-agent-update.sh"


def load_download_helper(script: pathlib.Path) -> dict:
    """Load the Python body of a script's leikwan_protected_download()."""
    source = script.read_text(encoding="utf-8")
    start = source.index("leikwan_protected_download() {")
    body_start = source.index("<<'PY'\n", start) + len("<<'PY'\n")
    body_end = source.index("\nPY\n", body_start)
    namespace: dict = {"__name__": "leikwan_protected_download_under_test"}
    exec(compile(source[body_start:body_end], str(script), "exec"), namespace)  # noqa: S102
    return namespace


class RetiredInstallerProxyTests(unittest.TestCase):
    """The installer proxy (--proxy / proxy.env) is retired: panel traffic is direct."""

    def run_installer_arguments(self, *args: str) -> subprocess.CompletedProcess:
        return subprocess.run(
            [BASH, posix(CLIENT_INSTALLER), *args],
            capture_output=True,
            text=True,
            timeout=60,
        )

    @unittest.skipIf(BASH is None, "POSIX bash (Git for Windows bash on Windows) is not available")
    def test_proxy_flag_is_accepted_ignored_and_its_value_consumed(self) -> None:
        # Without --master the installer stops at the usage check, after the
        # whole argument list was parsed. An unconsumed value would instead be
        # reported as an unknown argument.
        completed = self.run_installer_arguments("--proxy", "http://proxy.corp:3128", "--clear-proxy")
        self.assertEqual(1, completed.returncode, completed.stdout + completed.stderr)
        self.assertEqual([RETIRED_PROXY_MESSAGE, RETIRED_PROXY_MESSAGE], completed.stdout.splitlines())
        self.assertNotIn("unknown argument", completed.stderr)
        self.assertIn("usage: install-client-agent.sh", completed.stderr)

    @unittest.skipIf(BASH is None, "POSIX bash (Git for Windows bash on Windows) is not available")
    def test_empty_or_trailing_proxy_flag_is_also_ignored(self) -> None:
        for args in (("--proxy", ""), ("--proxy",)):
            with self.subTest(args=args):
                completed = self.run_installer_arguments(*args)
                self.assertEqual(1, completed.returncode, completed.stdout + completed.stderr)
                self.assertEqual([RETIRED_PROXY_MESSAGE], completed.stdout.splitlines())
                self.assertNotIn("unknown argument", completed.stderr)
                self.assertIn("usage: install-client-agent.sh", completed.stderr)

    def test_installer_no_longer_persists_or_exports_a_proxy(self) -> None:
        source = CLIENT_INSTALLER.read_text(encoding="utf-8")
        for retired in (
            "PROXY_MODE",
            "PROXY_URL",
            "stage_proxy_env",
            "install_proxy_env",
            "EnvironmentFile=",
            "export HTTP_PROXY",
            "export HTTPS_PROXY",
            '--proxy "$',
        ):
            self.assertNotIn(retired, source)
        # Only the legacy path used for the one-time cleanup may remain.
        self.assertIsNone(re.search(r"PROXY_ENV_(FILE|STAGED)", source))
        self.assertIn('LEGACY_PROXY_ENV_FILE="$CONFIG_DIR/proxy.env"', source)
        self.assertIn('for staged in "$LEGACY_PROXY_ENV_FILE".new.*; do', source)
        self.assertIn("\nremove_legacy_proxy_env\n", source)

    def test_update_helper_does_not_load_proxy_env(self) -> None:
        source = CLIENT_UPDATE_HELPER.read_text(encoding="utf-8")
        self.assertNotIn("load_agent_proxy_env", source)
        self.assertNotIn("proxy_file", source)
        self.assertNotIn('"--proxy"', source)
        self.assertIn("unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy ALL_PROXY all_proxy", source)
        self.assertLess(
            source.index("unset HTTP_PROXY HTTPS_PROXY"),
            source.index('leikwan_protected_download "${ARTIFACT_BASE%/}/artifacts/install-client-agent.sh"'),
        )

    def test_curl_fallback_uses_noproxy_even_with_proxy_env(self) -> None:
        proxy_env = {
            "HTTP_PROXY": "http://127.0.0.1:9",
            "HTTPS_PROXY": "http://127.0.0.1:9",
            "http_proxy": "http://127.0.0.1:9",
            "https_proxy": "http://127.0.0.1:9",
            "ALL_PROXY": "socks5://127.0.0.1:9",
        }
        for script in (CLIENT_INSTALLER, CLIENT_UPDATE_HELPER):
            with self.subTest(script=script.name):
                helper = load_download_helper(script)
                with tempfile.TemporaryDirectory() as tmp, \
                     unittest.mock.patch.dict(os.environ, proxy_env), \
                     unittest.mock.patch.object(helper["subprocess"], "run") as run_mock, \
                     unittest.mock.patch.object(sys, "argv", ["-", "http://artifacts.test/artifacts/versions.json", str(pathlib.Path(tmp) / "out")]):
                    helper["main"]()
                    helper["curl_download"]("http://artifacts.test/next", None)
                self.assertEqual(2, run_mock.call_count)
                for call in run_mock.call_args_list:
                    command = call.args[0]
                    self.assertEqual("curl", command[0])
                    self.assertEqual(["--noproxy", "*"], command[1:3])
                    self.assertNotIn("--proxy", command)
                    self.assertNotIn("-x", command)


@unittest.skipIf(BASH is None, "POSIX bash (Git for Windows bash on Windows) is not available")
class TcpAccelInstallerNoopTests(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self._tmp.name)
        self.stub_dir = self.base / "stubs"
        self.log_dir = self.base / "logs"
        self.served = self.base / "served"
        for path in (self.log_dir, self.served):
            path.mkdir(parents=True, exist_ok=True)
        write_common_stubs(self.stub_dir)
        write_executable(self.stub_dir / "id", '#!/usr/bin/env bash\n[[ "${1:-}" == "-u" ]] && echo 0 || echo "uid=0(root)"\n')
        for name in ("ip", "ss"):
            write_executable(self.stub_dir / name, "#!/usr/bin/env bash\nexit 0\n")
        runtime = REPO_ROOT / "tools" / "tcp-accel" / "tcp_accel.py"
        shutil.copyfile(runtime, self.served / "leikwan-tcp-accel.py")
        (self.served / "versions.json").write_text(
            json.dumps({"tcp_accel_runtime": {"sha256": sha256_file(runtime)}}), encoding="utf-8"
        )
        write_executable(
            self.stub_dir / "curl",
            "#!/usr/bin/env bash\n"
            'out=""; url=""\n'
            'while [[ $# -gt 0 ]]; do\n'
            '  case "$1" in\n'
            '    -o) out="$2"; shift 2 ;;\n'
            '    --max-time) shift 2 ;;\n'
            '    -*) shift ;;\n'
            '    *) url="$1"; shift ;;\n'
            "  esac\n"
            "done\n"
            'echo "$url" >> "$STUB_LOG_DIR/curl.log"\n'
            f'src="{posix(self.served)}/$(basename "$url")"\n'
            'if [[ -n "$out" ]]; then cp "$src" "$out"; else cat "$src"; fi\n',
        )
        self.ca_bundle = self.base / "ca-certificates.crt"
        self.ca_bundle.write_text("test bundle\n", encoding="utf-8")

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def run_installer(self, *, ca_bundle: pathlib.Path | None = None) -> None:
        for log_name in ("systemctl.log", "apt-get.log", "curl.log"):
            (self.log_dir / log_name).unlink(missing_ok=True)
        driver = self.base / "driver.sh"
        driver.write_text(
            "set -euo pipefail\n"
            f'export PATH="$(cd "{posix(self.stub_dir)}" && pwd):$PATH"\n'
            f'export STUB_LOG_DIR="{posix(self.log_dir)}"\n'
            f'export LEIKWAN_SYSTEMD_UNIT_DIR="{posix(self.base / "units")}"\n'
            f'export LEIKWAN_CA_BUNDLE="{posix(ca_bundle or self.ca_bundle)}"\n'
            f'bash "{posix(TCP_ACCEL_INSTALLER)}" client --artifact-base http://artifacts.test'
            f' --bin-dir "{posix(self.base / "bin")}" --install-root "{posix(self.base / "opt")}"'
            f' --config-dir "{posix(self.base / "etc")}"\n',
            encoding="utf-8",
            newline="\n",
        )
        completed = subprocess.run([BASH, posix(driver)], capture_output=True, text=True, timeout=120)
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)
        self.assertIn("TCP_ACCEL_INSTALL_OK mode=client", completed.stdout)

    def test_current_install_skips_apt_download_and_daemon_reload(self) -> None:
        self.run_installer()
        self.assertEqual([], read_log(self.log_dir / "apt-get.log"))
        self.assertIn("daemon-reload", read_log(self.log_dir / "systemctl.log"))
        unit = self.base / "units" / "leigod-tcp-accel-client@.service"
        self.assertTrue(unit.is_file())
        unit_bytes = unit.read_bytes()

        self.run_installer()
        self.assertEqual([], read_log(self.log_dir / "apt-get.log"))
        self.assertEqual([], read_log(self.log_dir / "systemctl.log"))
        self.assertEqual(["http://artifacts.test/artifacts/versions.json"], read_log(self.log_dir / "curl.log"))
        self.assertEqual(unit_bytes, unit.read_bytes())
        leftovers = [path.name for path in (self.base / "units").iterdir() if ".new." in path.name]
        self.assertEqual([], leftovers)

    def test_missing_dependency_still_bootstraps_with_apt(self) -> None:
        self.run_installer(ca_bundle=self.base / "missing-ca.crt")
        apt_calls = read_log(self.log_dir / "apt-get.log")
        self.assertIn("update", apt_calls)
        self.assertIn("install -y python3 curl ca-certificates coreutils iproute2", apt_calls)


if __name__ == "__main__":
    unittest.main()
