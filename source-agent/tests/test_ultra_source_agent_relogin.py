"""A re-login replaces the account session and kills every credential handed out
under the old one, so the source agent does it only when the session itself was
refused. 2026-11-08: three dead backup lines caused 19 re-logins in three hours
and took the working lines down with them."""

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "ultra_source_agent_relogin_under_test", ROOT / "source-agent" / "ultra_source_agent.py"
)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def _raise(exc):
    raise exc


class ClassificationTests(unittest.TestCase):
    def test_stderr_is_classified_without_leaking_secrets(self):
        code, detail, expired = MODULE.classify_acquisition_failure(
            "error: LeiGod error 400007: 当前登录态已过期，请重新登录\n"
        )
        self.assertEqual(("400007", True), (code, expired))
        self.assertIn("400007", detail)

        code, _detail, expired = MODULE.classify_acquisition_failure(
            "error: LeiGod error 400352: 无可用服务器\n".encode("utf-8")
        )
        self.assertEqual(("400352", False), (code, expired))

        self.assertEqual(("", "", False), MODULE.classify_acquisition_failure(""))
        self.assertEqual(("", "", False), MODULE.classify_acquisition_failure(None))

        _code, detail, _expired = MODULE.classify_acquisition_failure("error: line_token=SECRETVALUE123 rejected")
        self.assertNotIn("SECRETVALUE123", detail)

    def test_failure_kinds(self):
        self.assertEqual("line", MODULE.AcquisitionError(1, "x", code="400352").kind)
        self.assertEqual("session", MODULE.AcquisitionError(1, "x", session_expired=True).kind)
        self.assertEqual("unknown", MODULE.AcquisitionError(1).kind)
        # Text without a LeiGod error code is not a verdict on the line: if the
        # session were dead behind it, treating it as one would stop all logins.
        self.assertEqual("unknown", MODULE.AcquisitionError(1, "error: dial tcp: i/o timeout").kind)
        # A timeout or a malformed answer says nothing about the session.
        self.assertEqual("unknown", MODULE.acquisition_failure_kind(RuntimeError("timed out")))

    def test_a_missing_session_file_counts_as_a_refused_session(self):
        code, _detail, expired = MODULE.classify_acquisition_failure("error: no session at /var/lib/x/session.json\n")
        self.assertEqual(("", True), (code, expired))
        self.assertEqual("session", MODULE.AcquisitionError(1, "x", code=code, session_expired=expired).kind)


class ReloginPolicyTests(unittest.TestCase):
    def _agent(self, root):
        lines = root / "lines"
        lines.mkdir(exist_ok=True)
        config = root / "agent.json"
        config.write_text(
            json.dumps(
                {
                    "panel_url": "https://panel.example",
                    "source_id": "source-a",
                    "source_secret": "source-secret",
                    "binary_path": "/runtime/line-client",
                    "lines_dir": str(lines),
                    "inventory_path": str(lines / "index.json"),
                    "state_dir": str(root / "state"),
                }
            ),
            encoding="utf-8",
        )
        return MODULE.SourceAgent(config)

    def _cycle(self, agent, line_ids, acquire, *, reauth=True):
        """One cycle. Returns (result or the exception it raised, jobs paths, reauthenticate mock)."""
        paths = []

        def jobs(base, source_id, secret, method, path, payload, timeout, **kwargs):
            paths.append(path)
            return {"ok": True, "upload_ticket": "t", "line_ids": list(line_ids)}

        with mock.patch.object(MODULE, "build_inventory", return_value={"inventory_fingerprint": "a" * 64, "lines": []}), \
                mock.patch.object(MODULE, "inventory_due", return_value=False), \
                mock.patch.object(MODULE, "request_json_with_retries", side_effect=jobs), \
                mock.patch.object(MODULE, "acquire_line", side_effect=acquire), \
                mock.patch.object(MODULE, "request_json", return_value={"ok": True, "accepted_line_ids": []}), \
                mock.patch.object(MODULE.SourceAgent, "reauthenticate", return_value=reauth) as reauth_mock, \
                mock.patch.object(MODULE.SourceAgent, "refresh_catalog"):
            try:
                outcome = agent.run_cycle()
            except Exception as exc:  # noqa: BLE001
                outcome = exc
        return outcome, paths, reauth_mock

    @staticmethod
    def _material(line_id):
        return {"line_id": line_id, "relays": [{"ip": "203.0.113.20", "port": 5438}]}

    @staticmethod
    def _line_error(line_id):
        return MODULE.AcquisitionError(line_id, "error: LeiGod error 400352: no server", code="400352")

    @staticmethod
    def _session_error(line_id):
        return MODULE.AcquisitionError(
            line_id, "error: LeiGod error 400007: session expired", code="400007", session_expired=True
        )

    def test_a_dead_line_does_not_trigger_a_relogin_and_working_lines_are_kept(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))

            def acquire(line_id, *_a, **_k):
                if line_id == 2:
                    raise self._line_error(2)
                return self._material(line_id)

            outcome, _paths, reauth = self._cycle(agent, [1, 2, 3], acquire)
            self.assertNotIsInstance(outcome, Exception)
            reauth.assert_not_called()
            self.assertEqual({2}, set(agent._line_failures))

    def test_lines_acquired_together_do_not_all_expire_together(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            lifetimes = []

            def acquire(line_id, *_a, lifetime_seconds, **_k):
                lifetimes.append(lifetime_seconds)
                return self._material(line_id)

            outcome, _paths, _reauth = self._cycle(agent, list(range(1, 9)), acquire)
            self.assertNotIsInstance(outcome, Exception)
            self.assertEqual(8, len(lifetimes))
            # Never longer than configured, never below the floor, and not all identical.
            self.assertTrue(all(300 <= value <= agent.material_lifetime for value in lifetimes), lifetimes)
            self.assertGreater(len(set(lifetimes)), 1, lifetimes)
            floor = int(agent.material_lifetime * (1.0 - MODULE.MATERIAL_LIFETIME_JITTER))
            self.assertTrue(all(value >= floor for value in lifetimes), lifetimes)

    def test_a_refused_session_triggers_one_relogin_and_a_retry(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            calls = []

            def acquire(line_id, *_a, **_k):
                calls.append(line_id)
                if line_id == 2 and calls.count(2) == 1:
                    raise self._session_error(2)
                return self._material(line_id)

            outcome, _paths, reauth = self._cycle(agent, [1, 2], acquire)
            self.assertNotIsInstance(outcome, Exception)
            reauth.assert_called_once_with()
            self.assertEqual([1, 2, 2], calls)
            self.assertEqual({}, agent._line_failures)

    def test_a_cycle_where_every_line_fails_on_its_own_is_not_a_failed_cycle(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            outcome, _paths, reauth = self._cycle(
                agent, [8, 9], lambda line_id, *_a, **_k: _raise(self._line_error(line_id))
            )
            self.assertEqual(0, outcome)
            reauth.assert_not_called()
            self.assertEqual({8, 9}, set(agent._line_failures))

    def test_failed_lines_back_off_and_are_named_to_the_panel(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            calls = []

            def acquire(line_id, *_a, **_k):
                calls.append(line_id)
                if line_id in (2, 3):
                    raise self._line_error(line_id)
                return self._material(line_id)

            _outcome, first_paths, _reauth = self._cycle(agent, [1, 2, 3], acquire)
            self.assertNotIn("skip=", first_paths[0])
            calls.clear()
            # An older panel ignores skip and still lists the dead lines: they
            # must not be acquired again while they are backing off.
            _outcome, second_paths, _reauth = self._cycle(agent, [1, 2, 3], acquire)
            self.assertIn("&skip=2,3", second_paths[0])
            self.assertEqual([1], calls)

    def test_backoff_grows_is_capped_and_a_success_clears_it(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            steps = MODULE.LINE_BACKOFF_SECONDS
            with mock.patch.object(MODULE.time, "time", return_value=1000.0):
                agent._note_line_failure(5, RuntimeError("x"))
                self.assertEqual((1, 1000.0 + steps[0]), agent._line_failures[5])
                agent._note_line_failure(5, RuntimeError("x"))
                self.assertEqual((2, 1000.0 + steps[1]), agent._line_failures[5])
                self.assertEqual([5], agent._lines_in_backoff())
                for _ in range(20):
                    agent._note_line_failure(5, RuntimeError("x"))
                self.assertEqual(1000.0 + steps[-1], agent._line_failures[5][1])
            self.assertEqual([], agent._lines_in_backoff(now=1000.0 + steps[-1] + 1))
            agent._note_line_success(5)
            self.assertNotIn(5, agent._line_failures)

    def test_unexplained_failures_only_relogin_after_a_streak(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            relogins = 0
            streak = MODULE.BLIND_CYCLES_BEFORE_RELOGIN
            for cycle in range(1, streak + 1):
                agent._line_failures.clear()
                outcome, _paths, reauth = self._cycle(
                    agent, [1, 2], lambda line_id, *_a, **_k: _raise(RuntimeError("timed out"))
                )
                self.assertIsInstance(outcome, RuntimeError)
                relogins += reauth.call_count
                self.assertEqual(1 if cycle >= streak else 0, relogins, f"cycle {cycle}")

    def test_a_dead_session_without_an_error_code_still_ends_in_a_relogin(self):
        # line-client reports something with no LeiGod code: every line is
        # "unknown", nothing is acquired, and after the streak the agent logs in.
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            relogins = 0
            for _ in range(MODULE.BLIND_CYCLES_BEFORE_RELOGIN):
                agent._line_failures.clear()
                _outcome, _paths, reauth = self._cycle(
                    agent,
                    [1, 2],
                    lambda line_id, *_a, **_k: _raise(MODULE.AcquisitionError(line_id, "error: dial tcp: i/o timeout")),
                )
                relogins += reauth.call_count
            self.assertEqual(1, relogins)

    def test_acquire_line_reports_what_line_client_said(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "668.toml").write_text("line_id = 668\n", encoding="utf-8")
            seen = {}

            def line_refused(command, **kwargs):
                seen.update(kwargs)
                return mock.Mock(returncode=1, stderr=b"error: LeiGod error 400352: no server\n")

            with mock.patch.object(MODULE.subprocess, "run", side_effect=line_refused):
                with self.assertRaises(MODULE.AcquisitionError) as caught:
                    MODULE.acquire_line(
                        668, Path("/runtime/line-client"), root, root / "o.json", timeout=5, lifetime_seconds=1800
                    )
            self.assertEqual(MODULE.subprocess.PIPE, seen["stderr"])
            self.assertEqual("400352", caught.exception.code)
            self.assertFalse(caught.exception.session_expired)
            self.assertIn("line acquisition 668 failed", str(caught.exception))

            def session_refused(command, **kwargs):
                return mock.Mock(returncode=1, stderr="error: LeiGod error 400007: 当前登录态已过期，请重新登录\n")

            with mock.patch.object(MODULE.subprocess, "run", side_effect=session_refused):
                with self.assertRaises(MODULE.AcquisitionError) as caught:
                    MODULE.acquire_line(
                        668, Path("/runtime/line-client"), root, root / "o.json", timeout=5, lifetime_seconds=1800
                    )
            self.assertTrue(caught.exception.session_expired)

    def test_a_missing_line_file_is_not_a_session_problem(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(MODULE.AcquisitionError) as caught:
                MODULE.acquire_line(
                    9, Path("/runtime/line-client"), root, root / "o.json", timeout=5, lifetime_seconds=1800
                )
            self.assertFalse(caught.exception.session_expired)
            self.assertEqual("line", caught.exception.kind)

    def test_a_streak_is_broken_by_any_success(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            streak = MODULE.BLIND_CYCLES_BEFORE_RELOGIN
            for _ in range(streak - 1):
                agent._line_failures.clear()
                self._cycle(agent, [1], lambda line_id, *_a, **_k: _raise(RuntimeError("timed out")))
            self.assertEqual(streak - 1, agent._blind_cycles)
            # Line 1 is backing off after the failures above; without this the
            # cycle would have nothing due and return before acquiring anything.
            agent._line_failures.clear()
            self._cycle(agent, [1], lambda line_id, *_a, **_k: self._material(line_id))
            self.assertEqual(0, agent._blind_cycles)

    def test_session_refused_but_login_unavailable_fails_the_cycle_without_punishing_the_lines(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._agent(Path(directory))
            outcome, _paths, reauth = self._cycle(
                agent,
                [1, 2],
                lambda line_id, *_a, **_k: _raise(self._session_error(line_id)),
                reauth=False,
            )
            self.assertIsInstance(outcome, RuntimeError)
            reauth.assert_called_once_with()
            # The lines are fine, the session is not: no backoff for them.
            self.assertEqual({}, agent._line_failures)


if __name__ == "__main__":
    unittest.main()
