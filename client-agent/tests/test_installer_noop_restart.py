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
            'PROXY_ENV_FILE="$CONFIG_DIR/proxy.env"\n'
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


PROXY_VARIABLES = ("HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy", "NO_PROXY", "no_proxy")


@unittest.skipIf(BASH is None, "POSIX bash (Git for Windows bash on Windows) is not available")
class ProxyEnvPreservationTests(unittest.TestCase):
    """A self-update runs from a transient unit with no proxy env: keep proxy.env."""

    FUNCTIONS = [
        "mark_install_changed",
        "files_identical",
        "install_file_if_changed",
        "backup_existing",
        "append_proxy_env_line",
        "stage_proxy_env",
        "install_proxy_env",
    ]

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self._tmp.name)
        self.config_dir = self.base / "etc"
        self.state_dir = self.base / "state"
        self.stub_dir = self.base / "stubs"
        for path in (self.config_dir, self.state_dir):
            path.mkdir(parents=True, exist_ok=True)
        write_common_stubs(self.stub_dir)
        self.functions = extract_shell_functions(CLIENT_INSTALLER.read_text(encoding="utf-8"), self.FUNCTIONS)
        self.proxy_env = self.config_dir / "proxy.env"

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def run_proxy_step(self, *, mode: str = "auto", url: str = "", env: dict[str, str] | None = None) -> str:
        driver = self.base / "driver.sh"
        driver.write_text(
            "set -euo pipefail\n"
            f'export PATH="$(cd "{posix(self.stub_dir)}" && pwd):$PATH"\n'
            f'CONFIG_DIR="{posix(self.config_dir)}"\n'
            f'STATE_DIR="{posix(self.state_dir)}"\n'
            'PROXY_ENV_FILE="$CONFIG_DIR/proxy.env"\n'
            'PROXY_ENV_STAGED="$CONFIG_DIR/proxy.env.new.$$"\n'
            f'PROXY_MODE="{mode}"\n'
            f'PROXY_URL="{url}"\n'
            "INSTALL_CHANGED=0\n"
            "declare -a INSTALL_CHANGE_REASONS=()\n"
            'ROLLBACK_DIR="$STATE_DIR/.rollback-test"\n'
            'mkdir -p "$ROLLBACK_DIR"\n'
            "declare -a BACKUP_PATHS=()\n"
            "declare -A BACKUP_EXISTS=()\n"
            + self.functions
            + "install_proxy_env\n"
            'echo "CHANGED=$INSTALL_CHANGED"\n',
            encoding="utf-8",
            newline="\n",
        )
        child_env = {key: value for key, value in os.environ.items() if key not in PROXY_VARIABLES and key.upper() not in {"HTTP_PROXY", "HTTPS_PROXY", "NO_PROXY"}}
        child_env.update(env or {})
        completed = subprocess.run([BASH, posix(driver)], capture_output=True, text=True, timeout=60, env=child_env)
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)
        self.assertEqual([], [path.name for path in self.config_dir.iterdir() if ".new." in path.name])
        return completed.stdout

    def test_update_without_proxy_env_preserves_existing_proxy_env(self) -> None:
        original = "HTTPS_PROXY=http://proxy.corp:3128\nNO_PROXY=10.0.0.0/8\n"
        self.proxy_env.write_text(original, encoding="utf-8", newline="\n")
        output = self.run_proxy_step()
        self.assertIn("CHANGED=0", output)
        self.assertEqual(original, self.proxy_env.read_text(encoding="utf-8"))

    def test_no_proxy_alone_does_not_count_as_an_explicit_proxy(self) -> None:
        original = "HTTPS_PROXY=http://proxy.corp:3128\n"
        self.proxy_env.write_text(original, encoding="utf-8", newline="\n")
        self.run_proxy_step(env={"NO_PROXY": "localhost"})
        self.assertEqual(original, self.proxy_env.read_text(encoding="utf-8"))

    def test_explicit_proxy_env_rewrites_proxy_env(self) -> None:
        self.proxy_env.write_text("HTTPS_PROXY=http://old:3128\n", encoding="utf-8", newline="\n")
        output = self.run_proxy_step(env={"HTTPS_PROXY": "http://new:8080"})
        self.assertIn("CHANGED=1", output)
        self.assertEqual("HTTPS_PROXY=http://new:8080\n", self.proxy_env.read_text(encoding="utf-8"))

    def test_proxy_flag_sets_every_proxy_variable(self) -> None:
        output = self.run_proxy_step(mode="set", url="http://flag:3128", env={"no_proxy": "127.0.0.1"})
        self.assertIn("CHANGED=1", output)
        self.assertEqual(
            "HTTP_PROXY=http://flag:3128\nHTTPS_PROXY=http://flag:3128\nhttp_proxy=http://flag:3128\n"
            "https_proxy=http://flag:3128\nno_proxy=127.0.0.1\n",
            self.proxy_env.read_text(encoding="utf-8"),
        )

    def test_clear_proxy_empties_proxy_env(self) -> None:
        self.proxy_env.write_text("HTTPS_PROXY=http://old:3128\n", encoding="utf-8", newline="\n")
        output = self.run_proxy_step(mode="clear")
        self.assertIn("CHANGED=1", output)
        self.assertEqual("", self.proxy_env.read_text(encoding="utf-8"))

    def test_fresh_install_without_proxy_creates_empty_proxy_env(self) -> None:
        self.run_proxy_step()
        self.assertTrue(self.proxy_env.is_file())
        self.assertEqual("", self.proxy_env.read_text(encoding="utf-8"))

    def test_proxy_arguments_are_parsed(self) -> None:
        source = CLIENT_INSTALLER.read_text(encoding="utf-8")
        self.assertIn("--clear-proxy) PROXY_MODE=\"clear\"", source)
        self.assertIn('if [[ -z "$PROXY_URL" ]]; then PROXY_MODE="clear"; else PROXY_MODE="set"; fi', source)


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
