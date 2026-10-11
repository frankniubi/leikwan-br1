"""Regression tests for the frozen agent's ~6 minute self-update/restart loop.

Three defects combined into a loop:
1. In PyInstaller one-file mode ``__file__`` lives in ``/tmp/_MEIxxxx``, so the
   helper hashes compared against the panel's desired hashes were always ""
   and an auto-release update was requested forever.
2. A never-cleared manual update token and an auto-release token shared one
   ``last_completed_request_token`` field, so each completion made the other
   token look pending again.
3. An update that "succeeded" without converging was relaunched every retry
   window instead of backing off.
"""

from __future__ import annotations

import hashlib
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
UPDATE_HELPER = REPO_ROOT / "install" / "client-agent-update.sh"


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_update_convergence_under_test", "client-agent/client_agent.py")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def update_helper_state_writer_source() -> str:
    """The Python body of client-agent-update.sh's write_update_state()."""
    source = UPDATE_HELPER.read_text(encoding="utf-8")
    function_start = source.index("write_update_state() {")
    body_start = source.index("<<'PY'\n", function_start) + len("<<'PY'\n")
    body_end = source.index("\nPY\n", body_start)
    return source[body_start:body_end] + "\n"


WRITER_CODE = compile(update_helper_state_writer_source(), str(UPDATE_HELPER) + ":write_update_state", "exec")


def run_update_helper_state_writer(state_path: pathlib.Path, token: str, status: str, config_path: pathlib.Path, error: str = "") -> None:
    argv = ["write_update_state", str(state_path), token, status, error, str(config_path)]
    with mock.patch.object(sys, "argv", argv):
        exec(WRITER_CODE, {"__name__": "__main__"})  # noqa: S102 - trusted repo source under test


class Clock:
    def __init__(self, start: float) -> None:
        self.now = float(start)

    def __call__(self) -> float:
        return self.now


class AgentFixture:
    def __init__(self, tmp_dir: str) -> None:
        self.base = pathlib.Path(tmp_dir)
        self.config_path = self.base / "config.json"
        self.state_dir = self.base / "state"
        self.config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "client_id": 123,
                    "agent_token": "token",
                    "state_dir": str(self.state_dir),
                    "client_agent_bin": str(self.base / "missing-agent.py"),
                }
            ),
            encoding="utf-8",
        )
        self.agent = client_agent_module.ClientAgent(self.config_path)

    @property
    def state_path(self) -> pathlib.Path:
        return self.agent.update_state_path()

    def state(self) -> dict:
        return json.loads(self.state_path.read_text(encoding="utf-8"))


# A desired hash nothing on disk can ever match: the auto-release token is
# computed again after every "successful" update.
NEVER_CONVERGES = {"desired_install_forward_runtime_sha256": "0" * 64}
AUTO_TOKEN = "auto-release:install_forward_runtime=" + "0" * 64


class FrozenHelperPathTests(unittest.TestCase):
    def test_frozen_agent_hashes_installed_helpers_not_the_extraction_dir(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            base = pathlib.Path(tmp_dir)
            bin_dir = base / "bin"
            mei_dir = base / "_MEI12345"
            bin_dir.mkdir()
            mei_dir.mkdir()
            executable = bin_dir / "leikwan-client-agent"
            executable.write_bytes(b"frozen-agent-bytes")
            install_forward_runtime = bin_dir / "install_forward_runtime.py"
            install_forward_runtime.write_bytes(b"# installed renderer\n")
            watchdog = bin_dir / "cfwg_latency_watchdog.py"
            watchdog.write_bytes(b"# installed watchdog\n")
            wstunnel = bin_dir / "wstunnel"
            wstunnel.write_bytes(b"wstunnel-bytes")

            config_path = base / "config.json"
            config_path.write_text(
                json.dumps(
                    {
                        "master_url": "https://panel.example.com",
                        "client_id": 7,
                        "agent_token": "token",
                        "state_dir": str(base / "state"),
                        "wstunnel_bin": str(wstunnel),
                        "runtime_binary_mode": "frozen",
                    }
                ),
                encoding="utf-8",
            )

            desired = {
                "desired_agent_version": client_agent_module.AGENT_VERSION,
                "desired_agent_sha256": sha256_bytes(executable.read_bytes()),
                "desired_install_forward_runtime_sha256": sha256_bytes(install_forward_runtime.read_bytes()),
                "desired_cfwg_watchdog_sha256": sha256_bytes(watchdog.read_bytes()),
                "desired_wstunnel_sha256": sha256_bytes(wstunnel.read_bytes()),
            }

            with mock.patch.object(sys, "frozen", True, create=True), \
                 mock.patch.object(sys, "executable", str(executable)), \
                 mock.patch.object(client_agent_module, "__file__", str(mei_dir / "client_agent.py")):
                agent = client_agent_module.ClientAgent(config_path)

                self.assertEqual(
                    install_forward_runtime.resolve(),
                    client_agent_module.helper_artifact_path("install_forward_runtime.py").resolve(),
                )
                self.assertEqual("", agent.auto_update_request_token(desired))

                hashes = agent.helper_artifact_hashes()
                self.assertEqual(desired["desired_install_forward_runtime_sha256"], hashes["install_forward_runtime_sha256"])
                self.assertEqual(desired["desired_cfwg_watchdog_sha256"], hashes["cfwg_watchdog_sha256"])
                self.assertEqual(desired["desired_wstunnel_sha256"], hashes["wstunnel_sha256"])
                self.assertEqual(
                    desired["desired_install_forward_runtime_sha256"],
                    agent.semantic_agent_config()["install_forward_runtime_sha256"],
                )

    def test_python_distribution_keeps_source_adjacent_helpers(self) -> None:
        with mock.patch.object(sys, "frozen", False, create=True):
            path = client_agent_module.helper_artifact_path("install_forward_runtime.py")
        self.assertEqual(pathlib.Path(client_agent_module.__file__).with_name("install_forward_runtime.py"), path)


class UpdateTokenLedgerTests(unittest.TestCase):
    def run_cycles(self, fixture: AgentFixture, meta: dict, clock: Clock, cycles: int, step: float) -> list[tuple[float, str]]:
        requested: list[tuple[float, str]] = []

        def fake_request_self_update(request_token: str, artifact_base: str | None = None) -> None:
            requested.append((clock.now, request_token))
            # The transient update unit runs the helper, which records running
            # and then success (the installer found everything current).
            run_update_helper_state_writer(fixture.state_path, request_token, "running", fixture.config_path)
            run_update_helper_state_writer(fixture.state_path, request_token, "success", fixture.config_path)

        with mock.patch.object(fixture.agent, "request_self_update", side_effect=fake_request_self_update):
            for _ in range(cycles):
                fixture.agent.apply_management_state(meta)
                clock.now += step
        return requested

    def test_manual_and_auto_tokens_never_alternate(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            fixture = AgentFixture(tmp_dir)
            clock = Clock(1_800_000_000.0)
            meta = dict(NEVER_CONVERGES, agent_update_request_token="manual-token-1")
            with mock.patch("time.time", side_effect=clock):
                requested = self.run_cycles(fixture, meta, clock, cycles=6, step=400.0)
                summary = fixture.agent.update_convergence_summary()

            tokens = [token for _, token in requested]
            # manual once, auto once, then only backed-off retries of auto.
            self.assertEqual(["manual-token-1", AUTO_TOKEN, AUTO_TOKEN], tokens)
            self.assertEqual(1, tokens.count("manual-token-1"))
            times = [at for at, _ in requested]
            self.assertEqual([400.0, 800.0], [b - a for a, b in zip(times, times[1:])])

            state = fixture.state()
            ledger = state["tokens"]
            self.assertEqual("success", ledger["manual-token-1"]["status"])
            self.assertEqual(1, ledger["manual-token-1"]["attempts"])
            self.assertEqual("converge_failed", ledger[AUTO_TOKEN]["status"])
            self.assertEqual(2, ledger[AUTO_TOKEN]["converge_failures"])
            self.assertEqual(2, ledger[AUTO_TOKEN]["attempts"])
            self.assertEqual("converge_failed", summary["status"])
            self.assertEqual(AUTO_TOKEN, summary["tokens"][0]["token"])
            self.assertEqual(2, summary["tokens"][0]["converge_failures"])

    def test_non_converging_auto_update_backs_off_exponentially_and_caps(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            fixture = AgentFixture(tmp_dir)
            clock = Clock(1_800_000_000.0)
            requested: list[str] = []

            def fake_request_self_update(request_token: str, artifact_base: str | None = None) -> None:
                requested.append(request_token)
                run_update_helper_state_writer(fixture.state_path, request_token, "running", fixture.config_path)
                run_update_helper_state_writer(fixture.state_path, request_token, "success", fixture.config_path)

            backoffs: list[float] = []
            with mock.patch("time.time", side_effect=clock), \
                 mock.patch.object(fixture.agent, "request_self_update", side_effect=fake_request_self_update), \
                 mock.patch.object(client_agent_module.logging, "warning") as warning:
                for round_index in range(12):
                    fixture.agent.apply_management_state(dict(NEVER_CONVERGES))
                    self.assertEqual(round_index + 1, len(requested))
                    clock.now += 1.0
                    fixture.agent.apply_management_state(dict(NEVER_CONVERGES))
                    entry = fixture.state()["tokens"][AUTO_TOKEN]
                    self.assertEqual("converge_failed", entry["status"])
                    backoffs.append(entry["backoff_until"] - clock.now)
                    # Nothing is relaunched inside the backoff window.
                    clock.now = entry["backoff_until"] - 1.0
                    fixture.agent.apply_management_state(dict(NEVER_CONVERGES))
                    self.assertEqual(round_index + 1, len(requested))
                    clock.now = entry["backoff_until"]

            expected = [300.0 * (2 ** index) for index in range(9)] + [86400.0, 86400.0, 86400.0]
            self.assertEqual(expected, backoffs)
            converge_warnings = [call for call in warning.call_args_list if "did not converge" in str(call.args[0])]
            self.assertEqual(1, len(converge_warnings))

    def test_backoff_helper_doubles_and_caps(self) -> None:
        backoff = client_agent_module.ClientAgent.update_converge_backoff_seconds
        self.assertEqual([300.0, 600.0, 1200.0, 2400.0], [backoff(n) for n in range(1, 5)])
        self.assertEqual(86400.0, backoff(10))
        self.assertEqual(86400.0, backoff(10_000))

    def test_legacy_state_file_does_not_trigger_a_new_update(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            fixture = AgentFixture(tmp_dir)
            fixture.state_dir.mkdir(parents=True, exist_ok=True)
            fixture.state_path.write_text(
                json.dumps(
                    {
                        "request_token": "manual-token-1",
                        "status": "success",
                        "last_attempt_at": 1_700_000_000.0,
                        "last_completed_request_token": "manual-token-1",
                        "last_completed_at": 1_700_000_000.0,
                    }
                ),
                encoding="utf-8",
            )
            with mock.patch.object(fixture.agent, "request_self_update") as update_mock, \
                 mock.patch.object(fixture.agent, "auto_update_request_token", return_value=""):
                fixture.agent.apply_management_state({"agent_update_request_token": "manual-token-1"})
            update_mock.assert_not_called()

            # The same holds for a legacy-completed auto token: seen again, it
            # is a convergence failure, not a fresh update.
            fixture.state_path.write_text(
                json.dumps(
                    {
                        "request_token": AUTO_TOKEN,
                        "status": "success",
                        "last_attempt_at": 1_700_000_000.0,
                        "last_completed_request_token": AUTO_TOKEN,
                        "last_completed_at": 1_700_000_000.0,
                    }
                ),
                encoding="utf-8",
            )
            with mock.patch.object(fixture.agent, "request_self_update") as update_mock:
                fixture.agent.apply_management_state(dict(NEVER_CONVERGES))
            update_mock.assert_not_called()
            self.assertEqual("converge_failed", fixture.state()["tokens"][AUTO_TOKEN]["status"])

    def test_success_written_by_pre_ledger_helper_is_observed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            fixture = AgentFixture(tmp_dir)
            clock = Clock(1_800_000_000.0)

            def old_helper_success(request_token: str, artifact_base: str | None = None) -> None:
                state = fixture.state()
                state.update(
                    {
                        "request_token": request_token,
                        "status": "success",
                        "last_attempt_at": clock.now,
                        "last_completed_request_token": request_token,
                        "last_completed_at": clock.now,
                    }
                )
                fixture.state_path.write_text(json.dumps(state), encoding="utf-8")

            with mock.patch("time.time", side_effect=clock), \
                 mock.patch.object(fixture.agent, "request_self_update", side_effect=old_helper_success) as update_mock:
                fixture.agent.apply_management_state(dict(NEVER_CONVERGES))
                clock.now += 400.0
                fixture.agent.apply_management_state(dict(NEVER_CONVERGES))
            self.assertEqual(1, update_mock.call_count)
            self.assertEqual("converge_failed", fixture.state()["tokens"][AUTO_TOKEN]["status"])

    def test_agent_ledger_is_bounded(self) -> None:
        ledger = {f"token-{index}": {"status": "success", "completed_at": float(index)} for index in range(40)}
        client_agent_module.ClientAgent.prune_update_ledger(ledger, {"token-0"})
        self.assertEqual(client_agent_module.UPDATE_LEDGER_MAX_ENTRIES, len(ledger))
        self.assertIn("token-0", ledger)
        self.assertIn("token-39", ledger)
        self.assertNotIn("token-1", ledger)


class UpdateHelperLedgerTests(unittest.TestCase):
    def test_helper_keeps_every_token_outcome_and_reads_legacy_state(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            base = pathlib.Path(tmp_dir)
            config_path = base / "config.json"
            config_path.write_text(json.dumps({"client_agent_bin": str(base / "missing-agent.py")}), encoding="utf-8")
            state_path = base / "agent_update_state.json"
            state_path.write_text(
                json.dumps({"last_completed_request_token": "manual-token-1", "last_completed_at": 10.0}),
                encoding="utf-8",
            )

            run_update_helper_state_writer(state_path, AUTO_TOKEN, "running", config_path)
            run_update_helper_state_writer(state_path, AUTO_TOKEN, "success", config_path)
            state = json.loads(state_path.read_text(encoding="utf-8"))

            self.assertEqual("success", state["tokens"]["manual-token-1"]["status"])
            self.assertEqual("success", state["tokens"][AUTO_TOKEN]["status"])
            self.assertEqual(1, state["tokens"][AUTO_TOKEN]["attempts"])
            # Legacy readers still see the most recent completion.
            self.assertEqual(AUTO_TOKEN, state["last_completed_request_token"])
            self.assertEqual(state["last_completed_at"], state["tokens"][AUTO_TOKEN]["completed_at"])

            run_update_helper_state_writer(state_path, "manual-token-2", "error", config_path, error="boom")
            state = json.loads(state_path.read_text(encoding="utf-8"))
            self.assertEqual("error", state["tokens"]["manual-token-2"]["status"])
            self.assertEqual("boom", state["tokens"]["manual-token-2"]["last_error"])
            self.assertEqual(AUTO_TOKEN, state["last_completed_request_token"])

    def test_helper_ledger_is_bounded(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            base = pathlib.Path(tmp_dir)
            config_path = base / "config.json"
            config_path.write_text("{}", encoding="utf-8")
            state_path = base / "agent_update_state.json"
            clock = Clock(1_800_000_000.0)
            with mock.patch("time.time", side_effect=clock):
                for index in range(24):
                    clock.now += 1.0
                    run_update_helper_state_writer(state_path, f"token-{index}", "success", config_path)
            ledger = json.loads(state_path.read_text(encoding="utf-8"))["tokens"]
            self.assertEqual(16, len(ledger))
            self.assertIn("token-23", ledger)
            self.assertNotIn("token-0", ledger)


if __name__ == "__main__":
    unittest.main()
