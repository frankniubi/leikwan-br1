import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
WATCHDOG_SCRIPT = REPO_ROOT / "artifacts" / "leikwan-wg-watchdog.sh"

PEER = "abcdefghijklmnopqrstuvwxyz012345678901234567="


def _bash() -> str | None:
    return shutil.which("bash")


def _write_lf(path: pathlib.Path, text: str) -> None:
    """Write *text* with literal LF line endings.

    The harness may run on Windows where Python text mode would rewrite ``\\n``
    to ``\\r\\n``; a stray ``\\r`` would contaminate the last field parsed by the
    shell (e.g. ``1300\\r`` fails the ``^[0-9]+$`` numeric guard), so fixtures and
    stub scripts must be byte-exact LF.
    """
    path.write_bytes(text.encode("utf-8"))


class SlaveUpdateHelperDistributionTests(unittest.TestCase):

    def test_slave_update_helper_uses_artifact_base_when_present(self) -> None:
        source = (REPO_ROOT / "install" / "leikwan-slave-update").read_text(encoding="utf-8")

        self.assertIn("ARTIFACT_BASE=$(python3 - <<'PY' \"${CONFIG_PATH}\"", source)
        self.assertIn('${ARTIFACT_BASE%/}/artifacts/install-slave.sh', source)
        self.assertIn('--artifact-base "${ARTIFACT_BASE}"', source)

    def test_slave_installer_accepts_artifact_base_and_persists_it(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn('ARTIFACT_BASE=""', source)
        self.assertIn('--artifact-base) ARTIFACT_BASE="$2"; shift 2 ;;', source)
        self.assertIn('download_atomic "$ARTIFACT_BASE/artifacts/slave-agent.py" "$BIN_DIR/leikwan-slave-agent.py"', source)
        self.assertIn('"artifact_base_url": artifact_base_url,', source)

    def test_slave_installer_downloads_update_helper(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_atomic "$ARTIFACT_BASE/artifacts/slave-agent-update.sh" "$BIN_DIR/leikwan-slave-update"',
            source,
        )

    def test_slave_installer_downloads_wstunnel_from_panel_artifacts(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn(
            'download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"',
            source,
        )
        self.assertNotIn("github.com/erebe/wstunnel/releases/download", source)

    def test_slave_installer_uses_atomic_downloads_for_runtime_binaries(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn("download_atomic()", source)
        for snippet in [
            'download_atomic "$ARTIFACT_BASE/artifacts/slave-agent.py" "$BIN_DIR/leikwan-slave-agent.py"',
            'download_atomic "$ARTIFACT_BASE/artifacts/slave-agent-update.sh" "$BIN_DIR/leikwan-slave-update"',
            'download_atomic "$ARTIFACT_BASE/artifacts/leikwan-uot.py" "$BIN_DIR/leikwan-uot.py"',
            'download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/wstunnel_linux_amd64" "$BIN_DIR/wstunnel"',
            'download_atomic "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"',
            'download_atomic "$ARTIFACT_BASE/artifacts/leikwan-wg-watchdog.sh" "$BIN_DIR/leikwan-wg-watchdog.sh"',
        ]:
            self.assertIn(snippet, source)

    def test_slave_update_helper_tracks_failure_count_for_circuit_breaker(self) -> None:
        source = (REPO_ROOT / "install" / "leikwan-slave-update").read_text(encoding="utf-8")

        self.assertIn('same_request = str(existing.get("request_token", "")) == request_token', source)
        self.assertIn('previous_failure_count = int(existing.get("failure_count", 0) or 0) if same_request else 0', source)
        self.assertIn('payload["failure_count"] = previous_failure_count + 1', source)
        self.assertIn('payload["failure_count"] = 0', source)


@unittest.skipUnless(_bash() and not os.name == "nt" or _bash(), "bash interpreter required")
class WireGuardWatchdogBehaviorTests(unittest.TestCase):
    """Behavioral harness for artifacts/leikwan-wg-watchdog.sh.

    The script is driven through bash with wg/systemctl/logger stubbed onto PATH
    in a private temp directory. Each ``poll`` re-invokes the script (mirroring
    the 60s systemd timer) so the per-interface transfer state persists between
    polls exactly as it does in production. We then assert on the real
    side-effects: whether the systemctl restart stub was invoked, and what the
    restart ledger recorded -- never on the script's source text.
    """

    def setUp(self) -> None:
        if not _bash():
            self.skipTest("bash interpreter required")
        self.tmp = pathlib.Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, ignore_errors=True)
        self.bin_dir = self.tmp / "bin"
        self.bin_dir.mkdir()
        self.fixtures = self.tmp / "fixtures"
        self.fixtures.mkdir()
        self.state_dir = self.tmp / "state"
        self.ledger_dir = self.tmp / "ledger"
        self.restart_log = self.tmp / "systemctl-restarts.log"
        self._write_stubs()

    def _write_stubs(self) -> None:
        # wg stub: dispatches on subcommand. `latest-handshakes` injects a live
        # epoch so the handshake is always FRESH (age ~= 0 < threshold), forcing
        # the frozen-rx code path rather than the stale-handshake path. Every
        # other subcommand reads a fixture file under $WG_FIXTURE_DIR.
        wg = self.bin_dir / "wg"
        _write_lf(
            wg,
            "#!/usr/bin/env bash\n"
            "set -euo pipefail\n"
            "case \"$1\" in\n"
            "  show)\n"
            "    iface=\"${2:-}\"\n"
            "    sub=\"${3:-}\"\n"
            "    if [[ \"$iface\" == interfaces ]]; then\n"
            "      cat \"$WG_FIXTURE_DIR/interfaces\" 2>/dev/null || true\n"
            "      exit 0\n"
            "    fi\n"
            "    case \"$sub\" in\n"
            "      latest-handshakes)\n"
            "        epoch=$(date +%s)\n"
            "        while IFS=$'\\t' read -r peer _; do\n"
            "          [[ -z \"$peer\" ]] && continue\n"
            "          printf '%s\\t%s\\n' \"$peer\" \"$epoch\"\n"
            "        done < \"$WG_FIXTURE_DIR/${iface}.peers\"\n"
            "        ;;\n"
            "      transfer)\n"
            "        cat \"$WG_FIXTURE_DIR/${iface}.transfer\" 2>/dev/null || true\n"
            "        ;;\n"
            "      persistent-keepalive)\n"
            "        cat \"$WG_FIXTURE_DIR/${iface}.keepalive\" 2>/dev/null || true\n"
            "        ;;\n"
            "      *) ;;\n"
            "    esac\n"
            "    ;;\n"
            "esac\n",
        )
        systemctl = self.bin_dir / "systemctl"
        _write_lf(
            systemctl,
            "#!/usr/bin/env bash\n"
            'printf "%s\\n" "$*" >> "$SYSTEMCTL_RESTART_LOG"\n'
            "exit 0\n",
        )
        logger = self.bin_dir / "logger"
        _write_lf(logger, "#!/usr/bin/env bash\nexit 0\n")
        for stub in (wg, systemctl, logger):
            stub.chmod(0o755)

    def _set_iface(self, iface: str, keepalive: str) -> None:
        _write_lf(self.fixtures / "interfaces", f"{iface}\n")
        _write_lf(self.fixtures / f"{iface}.peers", f"{PEER}\t0\n")
        _write_lf(self.fixtures / f"{iface}.keepalive", f"{PEER}\t{keepalive}\n")

    def _set_transfer(self, iface: str, rx: int, tx: int) -> None:
        _write_lf(self.fixtures / f"{iface}.transfer", f"{PEER}\t{rx}\t{tx}\n")

    def _poll(self, extra_env: dict | None = None) -> subprocess.CompletedProcess:
        env = dict(os.environ)
        env["WG_FIXTURE_DIR"] = str(self.fixtures)
        env["SYSTEMCTL_RESTART_LOG"] = str(self.restart_log)
        env["LEIKWAN_WG_WATCHDOG_STATE_DIR"] = str(self.state_dir)
        env["LEIKWAN_RESTART_LEDGER_DIR"] = str(self.ledger_dir)
        env["WG_STUB_BIN"] = str(self.bin_dir)
        env["WG_WATCHDOG_SCRIPT"] = str(WATCHDOG_SCRIPT)
        if extra_env:
            env.update(extra_env)
        # We may be driving an msys/cygwin bash from a Windows-native Python, so
        # the parent PATH separator (';') is not what bash expects (':'). Build
        # PATH inside bash itself: convert the stub bin dir to a POSIX path with
        # cygpath when available (msys), otherwise use it verbatim (real Linux).
        preamble = (
            'stub="$WG_STUB_BIN"\n'
            'if command -v cygpath >/dev/null 2>&1; then\n'
            '  stub="$(cygpath -u "$WG_STUB_BIN")"\n'
            '  export WG_FIXTURE_DIR="$(cygpath -u "$WG_FIXTURE_DIR")"\n'
            '  export SYSTEMCTL_RESTART_LOG="$(cygpath -u "$SYSTEMCTL_RESTART_LOG")"\n'
            '  export LEIKWAN_WG_WATCHDOG_STATE_DIR="$(cygpath -u "$LEIKWAN_WG_WATCHDOG_STATE_DIR")"\n'
            '  export LEIKWAN_RESTART_LEDGER_DIR="$(cygpath -u "$LEIKWAN_RESTART_LEDGER_DIR")"\n'
            'fi\n'
            'export PATH="$stub:$PATH"\n'
            'script="$WG_WATCHDOG_SCRIPT"\n'
            'if command -v cygpath >/dev/null 2>&1; then script="$(cygpath -u "$WG_WATCHDOG_SCRIPT")"; fi\n'
            'exec bash "$script"\n'
        )
        return subprocess.run(
            [_bash(), "-c", preamble],
            env=env,
            capture_output=True,
            text=True,
        )

    def _restart_count(self, iface: str) -> int:
        if not self.restart_log.exists():
            return 0
        target = f"restart wg-quick@{iface}.service"
        return sum(1 for line in self.restart_log.read_text(encoding="utf-8").splitlines() if target in line)

    def _ledger_lines(self, iface: str) -> list[str]:
        path = self.ledger_dir / f"{iface}.log"
        if not path.exists():
            return []
        return [ln for ln in path.read_text(encoding="utf-8").splitlines() if ln.strip()]

    def test_frozen_rx_with_tx_delta_and_no_keepalive_restarts_after_three_polls(self) -> None:
        iface = "lw-cfwg-47"
        self._set_iface(iface, keepalive="off")
        # rx frozen at 100; tx advances every poll (active send). Need rx to be
        # unchanged across THREE consecutive comparisons -> 4 polls total.
        self._set_transfer(iface, rx=100, tx=1000)
        self._poll()  # poll 1: establishes baseline, no prev -> streak 0
        self.assertEqual(0, self._restart_count(iface), "poll1 must not restart")
        self._set_transfer(iface, rx=100, tx=1100)
        self._poll()  # poll 2: streak 1
        self.assertEqual(0, self._restart_count(iface), "poll2 streak1 must not restart")
        self._set_transfer(iface, rx=100, tx=1200)
        self._poll()  # poll 3: streak 2
        self.assertEqual(0, self._restart_count(iface), "poll3 streak2 must not restart (N=3)")
        self._set_transfer(iface, rx=100, tx=1300)
        self._poll()  # poll 4: streak 3 -> restart
        self.assertEqual(1, self._restart_count(iface), "poll4 streak3 must restart")
        self.assertEqual(1, len(self._ledger_lines(iface)), "restart must append exactly one ledger line")
        epoch_str, _, token = self._ledger_lines(iface)[0].partition(" ")
        self.assertTrue(epoch_str.isdigit(), f"ledger epoch not integer: {epoch_str!r}")
        self.assertEqual("handshake-watchdog", token)

    def test_persistent_keepalive_peer_with_frozen_rx_never_restarts(self) -> None:
        iface = "lw-cfwg-48"
        self._set_iface(iface, keepalive="25")  # 25s keepalive configured
        tx = 1000
        for _ in range(6):
            self._set_transfer(iface, rx=100, tx=tx)
            tx += 100  # tx advancing, rx frozen
            self._poll()
        self.assertEqual(0, self._restart_count(iface), "keepalive peer must be excluded from frozen-rx gate")
        self.assertEqual([], self._ledger_lines(iface))

    def test_flat_tx_with_frozen_rx_never_restarts(self) -> None:
        iface = "lw-cfwg-49"
        self._set_iface(iface, keepalive="off")
        # Both rx and tx flat -> tunnel idle, not one-way dead. tx delta is 0.
        for _ in range(6):
            self._set_transfer(iface, rx=100, tx=1000)
            self._poll()
        self.assertEqual(0, self._restart_count(iface), "flat tx (no delta) must not restart")
        self.assertEqual([], self._ledger_lines(iface))

    def test_circuit_breaker_suppresses_restart_when_ledger_at_max(self) -> None:
        iface = "lw-cfwg-50"
        self._set_iface(iface, keepalive="off")
        # Pre-seed the ledger with MAX recent entries so the breaker is open.
        self.ledger_dir.mkdir(parents=True, exist_ok=True)
        now = int(subprocess.run([_bash(), "-c", "date +%s"], capture_output=True, text=True).stdout.strip())
        _write_lf(
            self.ledger_dir / f"{iface}.log",
            "".join(f"{now} handshake-watchdog\n" for _ in range(3)),
        )
        # Drive a frozen-rx + tx-delta fault to completion (would restart absent breaker).
        rx = 100
        tx = 1000
        for _ in range(4):
            self._set_transfer(iface, rx=rx, tx=tx)
            tx += 100
            self._poll()
        self.assertEqual(0, self._restart_count(iface), "breaker open must suppress restart")
        # No new ledger line appended while suppressed.
        self.assertEqual(3, len(self._ledger_lines(iface)), "suppressed restart must append nothing")


if __name__ == "__main__":
    unittest.main()
