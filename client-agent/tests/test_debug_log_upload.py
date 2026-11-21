import base64
import contextlib
import importlib.util
import io
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import uuid
from unittest import mock


ROOT = pathlib.Path(__file__).resolve().parents[2]
AGENT_PATH = ROOT / "client-agent" / "client_agent.py"


def load_module():
    spec = importlib.util.spec_from_file_location("client_agent_debug_upload", AGENT_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


MODULE = load_module()
PANEL = "https://panel.example.test"
COLLECTION = {
    "uuid": "123e4567-e89b-42d3-a456-426614174000",
    "status": "active",
    "expires_at": "2999-01-01T00:00:00Z",
    "supports_upload_id": True,
}


def http_error(status, code="failure"):
    return urllib.error.HTTPError(
        PANEL + "/api/client/debug/log", status, "error", {},
        io.BytesIO(json.dumps({"ok": False, "error": code}).encode()),
    )


class DebugLogUploadTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = pathlib.Path(self.directory.name)
        self.log = self.root / "client.log"
        self.log.write_text("password=hidden\n" + "original log line\n" * 20, encoding="utf-8")
        self.config = {
            "master_url": PANEL, "agent_token": "agent-token", "state_dir": str(self.root),
            "debug_log_paths": [str(self.log)],
        }
        self.metadata = {"debug_collection": dict(COLLECTION)}
        self.addCleanup(mock.patch.stopall)
        mock.patch.object(MODULE, "debug_journal_bytes", return_value=b"").start()
        mock.patch.object(MODULE, "DEBUG_LOG_CHUNK_BYTES", 64).start()
        mock.patch.object(MODULE, "DEBUG_LOG_BYTE_LIMIT", 1024).start()
        mock.patch.object(MODULE.time, "time", return_value=1000).start()

    def upload(self, post=None, **kwargs):
        return MODULE.upload_debug_collection(
            kwargs.pop("master", PANEL), kwargs.pop("token", "agent-token"),
            kwargs.pop("metadata", self.metadata), self.root,
            kwargs.pop("config", self.config), post=post or (lambda payload: {"ok": True}),
            device_id=kwargs.pop("device_id", "linux-current-device"), **kwargs,
        )

    def state(self):
        return json.loads((self.root / "debug-upload-state.json").read_text(encoding="utf-8"))

    def agent(self):
        agent = MODULE.ClientAgent.__new__(MODULE.ClientAgent)
        agent.state_dir = self.root
        agent.config = dict(self.config)
        agent.device_id = lambda: "linux-current-device"
        return agent

    def test_upload_includes_agent_and_forward_runtime_journals(self):
        seen_units = []
        def fake_journal(units=("leikwan-client-agent.service",)):
            seen_units.append(tuple(units))
            return ("journal for " + ",".join(units) + chr(10)).encode()
        calls = []
        with mock.patch.object(MODULE, "debug_log_paths", return_value=[]), mock.patch.object(
            MODULE, "debug_journal_bytes", side_effect=fake_journal,
        ):
            result = self.upload(lambda payload: calls.append(payload) or {"ok": True})
        self.assertEqual("complete", result["status"])
        self.assertIn(("leikwan-client-agent.service",), seen_units)
        self.assertIn(("ultra-forward-*.service", "leikwan-client-*.service"), seen_units)
        sources = {item["source"] for item in calls}
        self.assertIn("journalctl-leikwan-client-agent.log", sources)
        self.assertIn("journalctl-forward-runtimes.log", sources)

    def test_debug_journal_passes_every_unit_to_journalctl(self):
        captured = {}

        class Result:
            returncode = 0
            stdout = b"ok"

        def fake_run(args, **kwargs):
            captured["args"] = args
            return Result()

        original_run, original_which = MODULE.subprocess.run, MODULE.shutil.which
        MODULE.subprocess.run = fake_run
        MODULE.shutil.which = lambda name: "/usr/bin/journalctl"
        try:
            self.assertEqual(b"ok", load_module().debug_journal_bytes(("ultra-forward-*.service", "leikwan-client-*.service")))
        finally:
            MODULE.subprocess.run, MODULE.shutil.which = original_run, original_which
        args = captured["args"]
        self.assertEqual(["-u", "ultra-forward-*.service", "-u", "leikwan-client-*.service"], args[1:5])

    def test_redaction_removes_credentials_in_text_and_json(self):
        source = (
            'Authorization: Bearer abc\npassword=secret token: xyz\n'
            '{"agent_token":"json-token", "api_key":"api-secret", '
            '"password":"escaped\\\"secret", "Authorization":"Bearer json-auth"}\n'
            'url=https://example.test/?token=url-secret&x=1\nline=healthy'
        )
        output = MODULE.redact_debug_text(source)
        for secret in ("abc", "secret", "xyz", "json-token", "json-auth"):
            self.assertNotIn(secret, output)
        self.assertIn("line=healthy", output)
        self.assertIn("&x=1", output)

    def test_snapshot_is_durable_bounded_and_repeated_success_is_a_noop(self):
        self.log.write_text("password=hidden\n" + "x" * 3000, encoding="utf-8")
        calls = []
        real_fsync = MODULE.os.fsync
        real_replace = MODULE.os.replace
        with mock.patch.object(MODULE.os, "fsync", wraps=real_fsync) as fsync, mock.patch.object(
            MODULE.os, "replace", wraps=real_replace,
        ) as replace:
            def post(payload):
                calls.append(dict(payload))
                state = self.state()
                self.assertEqual(payload["sequence"], state["next_sequence"])
                self.assertGreaterEqual(fsync.call_count, 2)
                self.assertGreaterEqual(replace.call_count, 2)
                snapshot = self.root / f"debug-upload-{payload['upload_id']}.bin"
                self.assertTrue(snapshot.is_file())
                self.assertNotIn(b"hidden", snapshot.read_bytes())
                self.assertEqual(4, uuid.UUID(payload["upload_id"]).version)
                return {"ok": True}
            result = self.upload(post)
        self.assertEqual("complete", result["status"])
        self.assertLessEqual(sum(len(base64.b64decode(p["chunk_base64"])) for p in calls), 1024)
        self.assertEqual(list(range(len(calls))), [p["sequence"] for p in calls])
        self.assertEqual(len(calls), self.state()["next_sequence"])
        self.assertNotIn("agent-token", (self.root / "debug-upload-state.json").read_text())
        if os.name != "nt":
            self.assertEqual(0o600, (self.root / "debug-upload-state.json").stat().st_mode & 0o777)
            self.assertEqual(0o600, next(self.root.glob("debug-upload-*.bin")).stat().st_mode & 0o777)
        self.log.write_text("changed after success", encoding="utf-8")
        with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("read after completion")):
            repeated = self.upload(lambda payload: self.fail("completed upload sent another request"))
        self.assertEqual("complete", repeated["status"])
        self.assertEqual(0, repeated["chunks"])
        self.assertEqual(result["upload_id"], repeated["upload_id"])

    def test_lost_ack_and_restart_replay_exact_bytes_despite_mutated_logs(self):
        calls = []
        accepted = {}

        def lost_ack(payload):
            calls.append(dict(payload))
            accepted[payload["sequence"]] = dict(payload)
            if payload["sequence"] == 1:
                raise TimeoutError("ACK lost after server accepted chunk")
            return {"ok": True}

        self.assertEqual("retry_later", self.upload(lost_ack)["status"])
        initial = self.state()
        self.assertEqual(1, initial["next_sequence"])
        self.log.write_text("password=new-secret\ncompletely different log", encoding="utf-8")
        (self.root / "added.log").write_text("new log file", encoding="utf-8")
        self.assertEqual("retry_later", self.upload(lambda p: self.fail("backoff ignored"))["status"])
        restarted = load_module()
        resumed_calls = []

        def acknowledge(payload):
            resumed_calls.append(dict(payload))
            if payload["sequence"] in accepted:
                self.assertEqual(accepted[payload["sequence"]], payload)
            return {"ok": True, "duplicate": payload["sequence"] in accepted}

        with mock.patch.object(restarted.time, "time", return_value=initial["retry_at"] + 1), mock.patch.object(
            restarted, "DEBUG_LOG_CHUNK_BYTES", 64,
        ), mock.patch.object(restarted, "DEBUG_LOG_BYTE_LIMIT", 1024), mock.patch.object(
            restarted, "debug_log_paths", side_effect=AssertionError("reread mutable logs"),
        ):
            result = restarted.upload_debug_collection(
                PANEL, "agent-token", self.metadata, self.root, self.config,
                post=acknowledge, device_id="linux-current-device",
            )
        self.assertEqual("complete", result["status"])
        self.assertEqual(calls[-1], resumed_calls[0])
        self.assertEqual(initial["upload_id"], result["upload_id"])
        self.assertEqual("complete", self.state()["status"])

    def test_terminal_responses_persist_and_suppress_future_uploads(self):
        for status, code in ((410, "debug_collection_expired"), (404, "debug_collection_not_found"),
                             (409, "debug_chunk_replay"), (413, "debug_chunk_too_large"),
                             (422, "debug_upload_invalid")):
            with self.subTest(status=status):
                self.metadata["debug_collection"]["uuid"] = str(uuid.uuid4())
                failure = mock.Mock(side_effect=http_error(status, code))
                result = self.upload(failure)
                self.assertEqual("terminal", result["status"])
                self.assertEqual(1, failure.call_count)
                self.assertEqual(code, self.state()["error"])
                with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("terminal logs reread")):
                    self.assertEqual("terminal", self.upload(lambda p: self.fail("terminal retried"))["status"])
                # The manual entry point also respects the terminal record.
                with mock.patch.object(MODULE, "start_debug_collection", side_effect=AssertionError("terminal reopened")):
                    self.assertEqual("terminal", self.agent().upload_logs_now()["status"])

    def test_legacy_completed_upgrade_preserves_completion_and_allows_new_manual_capture(self):
        legacy = {
            "collection_uuid": COLLECTION["uuid"], "next_sequence": 3,
            "files": {str(self.log): {"sha256": "a" * 64, "bytes": 180}}, "completed": True,
        }
        state_path = self.root / "debug-upload-state.json"
        state_path.write_text(json.dumps(legacy), encoding="utf-8")
        with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("legacy completed recollected")):
            for _ in range(2):
                result = self.upload(lambda p: self.fail("legacy completed replayed"))
                self.assertEqual("complete", result["status"])
        self.assertEqual(legacy, self.state())
        self.assertEqual(legacy, json.loads((self.root / "debug-upload-legacy-state.json").read_text()))
        self.assertEqual([], list(self.root.glob("debug-upload-*.bin")))
        fresh = {**COLLECTION, "uuid": str(uuid.uuid4())}
        with mock.patch.object(MODULE, "start_debug_collection", return_value={
            "debug_collection": fresh, "reference_code": fresh["uuid"][:8],
        }) as start, mock.patch.object(MODULE, "http_json_with_headers", return_value=({"ok": True}, {})):
            self.assertTrue(self.agent().upload_logs_now()["ok"])
        start.assert_called_once()
        self.assertEqual(fresh["uuid"], self.state()["collection_uuid"])
        self.assertEqual(1, self.state()["schema"])
        self.assertEqual(legacy, json.loads((self.root / "debug-upload-legacy-state.json").read_text()))

    def test_legacy_pending_upgrade_uses_fresh_id_zero_cursor_and_immutable_retry(self):
        for completed in (None, False):
            with self.subTest(completed=completed):
                legacy = {
                    "collection_uuid": COLLECTION["uuid"], "next_sequence": 7,
                    "files": {str(self.log): {"sha256": "b" * 64, "bytes": 300}},
                }
                if completed is not None:
                    legacy["completed"] = completed
                (self.root / "debug-upload-state.json").write_text(json.dumps(legacy), encoding="utf-8")
                self.log.write_text("fresh upgrade snapshot\n" * 10, encoding="utf-8")
                calls = []
                def lost_ack(payload):
                    calls.append(dict(payload))
                    raise TimeoutError("lost upgrade ACK")
                first = self.upload(lost_ack)
                self.assertEqual("retry_later", first["status"])
                self.assertEqual(0, calls[0]["sequence"])
                self.assertEqual(4, uuid.UUID(first["upload_id"]).version)
                self.assertEqual(1, self.state()["schema"])
                self.assertEqual(legacy, json.loads((self.root / "debug-upload-legacy-state.json").read_text()))
                self.log.write_text("changed after migration", encoding="utf-8")
                resumed = []
                with mock.patch.object(MODULE.time, "time", return_value=self.state()["retry_at"] + 1), mock.patch.object(
                    MODULE, "debug_log_paths", side_effect=AssertionError("legacy retry recollected"),
                ):
                    final = self.upload(lambda p: resumed.append(dict(p)) or {"ok": True})
                self.assertEqual("complete", final["status"])
                self.assertEqual(first["upload_id"], final["upload_id"])
                self.assertEqual(calls[0], resumed[0])

    def test_manual_upgrade_of_legacy_pending_archives_before_start(self):
        legacy = {"collection_uuid": COLLECTION["uuid"], "next_sequence": 2, "files": {}}
        (self.root / "debug-upload-state.json").write_text(json.dumps(legacy), encoding="utf-8")
        fresh = {**COLLECTION, "uuid": str(uuid.uuid4())}
        def start(*args, **kwargs):
            self.assertEqual(legacy, self.state())
            self.assertEqual(legacy, json.loads((self.root / "debug-upload-legacy-state.json").read_text()))
            return {"debug_collection": fresh, "reference_code": fresh["uuid"][:8]}
        with mock.patch.object(MODULE, "start_debug_collection", side_effect=start) as started, mock.patch.object(
            MODULE, "http_json_with_headers", return_value=({"ok": True}, {}),
        ) as post:
            result = self.agent().upload_logs_now()
        self.assertTrue(result["ok"])
        started.assert_called_once()
        self.assertEqual(0, post.call_args_list[0].args[3]["sequence"])
        self.assertEqual(fresh["uuid"], self.state()["collection_uuid"])
        self.assertEqual(legacy, json.loads((self.root / "debug-upload-legacy-state.json").read_text()))

    def test_legacy_upgrade_requires_upload_id_capability(self):
        for completed in (False, True):
            with self.subTest(completed=completed):
                legacy = {
                    "collection_uuid": COLLECTION["uuid"], "next_sequence": 2,
                    "files": {}, "completed": completed,
                }
                (self.root / "debug-upload-state.json").write_text(json.dumps(legacy), encoding="utf-8")
                self.metadata["debug_collection"]["supports_upload_id"] = False
                with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("unsupported legacy recaptured")):
                    self.assertEqual("unsupported_upload_protocol", self.upload(lambda p: self.fail("legacy uploaded without capability"))["status"])
                self.assertEqual(legacy, self.state())
                self.assertFalse((self.root / "debug-upload-legacy-state.json").exists())

    def test_malformed_legacy_manifest_still_fails_closed(self):
        valid = {"collection_uuid": COLLECTION["uuid"], "next_sequence": 2, "files": {}}
        for damaged in (
            {**valid, "next_sequence": -1}, {**valid, "next_sequence": True},
            {**valid, "completed": "true"}, {**valid, "files": []},
            {**valid, "files": {str(self.log): {"sha256": "not-a-digest", "bytes": 1}}},
            {**valid, "files": {str(self.log): {"sha256": "a" * 64, "bytes": True}}},
            {**valid, "files": {str(self.log): {"sha256": "a" * 64, "bytes": -1}}},
            {**valid, "schema": 1}, {**valid, "upload_id": str(uuid.uuid4())},
            {**valid, "unknown": "not-produced-by-legacy"},
        ):
            with self.subTest(manifest=damaged):
                (self.root / "debug-upload-state.json").write_text(json.dumps(damaged), encoding="utf-8")
                with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("corrupt legacy recaptured")), mock.patch.object(
                    MODULE, "start_debug_collection", side_effect=AssertionError("corrupt legacy reopened"),
                ):
                    with self.assertRaises(ValueError):
                        self.upload(lambda p: self.fail("corrupt legacy sent"))
                    with self.assertRaises(ValueError):
                        self.agent().upload_logs_now()
                self.assertEqual(damaged, self.state())
                self.assertFalse((self.root / "debug-upload-legacy-state.json").exists())

    def test_expired_terminal_and_paused_permit_fresh_manual_capture_only(self):
        for status in (410, 401, 403):
            for passive_first in (False, True):
                with self.subTest(status=status, passive_first=passive_first):
                    self.metadata["debug_collection"].update(
                        uuid=str(uuid.uuid4()), expires_at=MODULE.format_rfc3339(1100),
                    )
                    old = self.upload(mock.Mock(side_effect=http_error(status)))
                    self.assertEqual("terminal" if status == 410 else "paused", old["status"])
                    fresh = {**COLLECTION, "uuid": str(uuid.uuid4())}
                    self.log.write_text("fresh manual capture after expiry", encoding="utf-8")
                    calls = []
                    with mock.patch.object(MODULE.time, "time", return_value=2000):
                        if passive_first:
                            with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("expired passive recaptured")):
                                self.assertEqual("expired", self.upload(lambda p: self.fail("expired passive sent"))["status"])
                            self.assertEqual(old["upload_id"], self.state()["upload_id"])
                        with mock.patch.object(MODULE, "start_debug_collection", return_value={
                            "debug_collection": fresh, "reference_code": fresh["uuid"][:8],
                        }) as start, mock.patch.object(MODULE, "http_json_with_headers", side_effect=lambda method, url, token, payload, **kw: (
                            calls.append(dict(payload)) or {"ok": True}, {},
                        )):
                            result = self.agent().upload_logs_now()
                        start.assert_called_once()
                        self.assertTrue(result["ok"])
                        self.assertNotEqual(old["upload_id"], result["upload_id"])
                        self.assertEqual(fresh["uuid"], calls[0]["collection_uuid"])
                        self.assertEqual(0, calls[0]["sequence"])
                        self.assertEqual(b"fresh manual capture after expiry", base64.b64decode(calls[0]["chunk_base64"]))
                        final_state = self.state()
                        self.assertEqual("expired", self.upload(lambda p: self.fail("stale expired passive sent"))["status"])
                        self.assertEqual(final_state, self.state())

    def test_http_helper_wrapped_errors_are_classified_without_changing_transport(self):
        with mock.patch.object(MODULE, "_urlopen_for_request", side_effect=http_error(410, "debug_collection_expired")):
            result = MODULE.upload_debug_collection(
                PANEL, "agent-token", self.metadata, self.root, self.config, device_id="linux-current-device",
            )
        self.assertEqual("terminal", result["status"])
        self.assertEqual("debug_collection_expired", self.state()["error"])

    def test_retryable_errors_have_persisted_backoff_and_same_upload(self):
        for failure in (http_error(404, "unknown_not_found"), http_error(409, "unknown_conflict"),
                        http_error(408), http_error(429), http_error(500), http_error(503),
                        urllib.error.URLError("network down")):
            with self.subTest(failure=str(failure)):
                self.metadata["debug_collection"]["uuid"] = str(uuid.uuid4())
                rejected = mock.Mock(side_effect=failure)
                result = self.upload(rejected)
                state = self.state()
                self.assertEqual("retry_later", result["status"])
                self.assertEqual(1, rejected.call_count)
                self.assertGreater(state["retry_at"], 1000)
                self.assertEqual(0, state["next_sequence"])
                self.upload(lambda p: self.fail("backoff ignored"))
                with mock.patch.object(MODULE.time, "time", return_value=state["retry_at"] + 1):
                    recovered = self.upload()
                self.assertEqual("complete", recovered["status"])
                self.assertEqual(result["upload_id"], recovered["upload_id"])

    def test_auth_errors_pause_ordinary_calls_until_explicit_resume_or_binding_change(self):
        for status in (401, 403):
            with self.subTest(status=status):
                self.metadata["debug_collection"]["uuid"] = str(uuid.uuid4())
                result = self.upload(mock.Mock(side_effect=http_error(status)))
                self.assertEqual("paused", result["status"])
                with mock.patch.object(MODULE.time, "time", return_value=100000):
                    self.assertEqual("paused", self.upload(lambda p: self.fail("auth pause ignored"))["status"])
                changed = self.upload(token="new-account-token")
                self.assertEqual("complete", changed["status"])
                self.assertNotEqual(result["upload_id"], changed["upload_id"])

    def test_manual_auth_resume_reuses_snapshot_id_cursor_and_bytes(self):
        for status in (401, 403):
            with self.subTest(status=status):
                self.metadata["debug_collection"]["uuid"] = str(uuid.uuid4())
                self.log.write_text("original immutable logs\n" * 20, encoding="utf-8")
                rejected = []
                def reject(payload):
                    rejected.append(dict(payload))
                    if payload["sequence"] == 1:
                        raise http_error(status)
                    return {"ok": True}
                paused = self.upload(reject)
                self.assertEqual("paused", paused["status"])
                self.assertEqual(1, self.state()["next_sequence"])
                self.log.write_text("mutated after auth pause", encoding="utf-8")
                resumed = []
                def accept(method, url, token, payload, *, extra_headers):
                    resumed.append(dict(payload))
                    self.assertEqual("agent-token", token)
                    self.assertEqual("linux-current-device", extra_headers["x-device-id"])
                    return {"ok": True}, {}
                with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("auth resume recaptured")), mock.patch.object(
                    MODULE, "start_debug_collection", side_effect=AssertionError("auth resume started new collection"),
                ), mock.patch.object(MODULE, "http_json_with_headers", side_effect=accept):
                    result = self.agent().upload_logs_now()
                self.assertTrue(result["ok"])
                self.assertEqual(paused["upload_id"], result["upload_id"])
                self.assertEqual(rejected[-1], resumed[0])
                self.assertEqual("complete", self.state()["status"])

    def test_fresh_authenticated_pull_resumes_same_binding_auth_pause(self):
        class StopAfterScheduling(Exception):
            pass

        # Drive the real sync_once -> scheduler -> worker wiring, stopping just
        # after scheduling so no unrelated runtime/heartbeat work is exercised.
        for status in (401, 403):
            with self.subTest(status=status):
                self.metadata["debug_collection"]["uuid"] = str(uuid.uuid4())
                self.log.write_text("fresh-pull immutable original logs\n" * 10, encoding="utf-8")
                rejected = []
                def reject(payload):
                    rejected.append(dict(payload))
                    raise http_error(status)
                paused = self.upload(reject)
                self.log.write_text("changed after auth pause", encoding="utf-8")
                self.upload(lambda p: self.fail("ordinary paused call sent"))
                agent = self.agent()
                agent.missing_forward_observations = {}
                resumed = []
                def accept(method, url, token, payload, *, extra_headers):
                    resumed.append(dict(payload))
                    return {"ok": True}, {}

                def run_worker_inline(**kwargs):
                    # Preserve real scheduler arguments, including the explicit
                    # resume permission, without leaving background test work.
                    target, args = kwargs["target"], kwargs["args"]
                    worker_kwargs = kwargs.get("kwargs", {})
                    thread = mock.Mock()
                    thread.start.side_effect = lambda: target(*args, **worker_kwargs)
                    thread.is_alive.return_value = False
                    return thread

                with contextlib.ExitStack() as stack:
                    stack.enter_context(mock.patch.object(agent, "pull_client_configs", return_value=(
                        {"forwards": [], "client": dict(self.metadata)}, {}, PANEL,
                    )))
                    stack.enter_context(mock.patch.object(MODULE, "resolve_ultra_leases", return_value=[]))
                    for name, value in (
                        ("ultra_selection_mode", "auto"), ("lease_device_key", None),
                        ("apply_management_state", None), ("consume_local_refresh_requests", set()),
                        ("load_applied", {}), ("write_applied_state", None), ("reconcile_global_mptcp_state", None),
                    ):
                        stack.enter_context(mock.patch.object(agent, name, return_value=value))
                    stack.enter_context(mock.patch.object(agent, "run_diagnostics_if_requested", side_effect=StopAfterScheduling))
                    stack.enter_context(mock.patch.object(MODULE.threading, "Thread", side_effect=run_worker_inline))
                    stack.enter_context(mock.patch.object(MODULE, "http_json_with_headers", side_effect=accept))
                    stack.enter_context(mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("fresh pull recaptured")))
                    with self.assertRaises(StopAfterScheduling):
                        agent.sync_once()
                self.assertGreater(len(resumed), 0)
                self.assertEqual(rejected[0], resumed[0])
                self.assertEqual(paused["upload_id"], self.state()["upload_id"])
                self.assertEqual("complete", self.state()["status"])

    def test_explicit_auth_probe_repauses_on_401_or_403_without_retry_loop(self):
        for status in (401, 403):
            with self.subTest(status=status):
                self.metadata["debug_collection"]["uuid"] = str(uuid.uuid4())
                paused = self.upload(mock.Mock(side_effect=http_error(status)))
                for trigger in ("manual", "fresh_pull"):
                    failure = mock.Mock(side_effect=http_error(status))
                    with mock.patch.object(MODULE, "http_json_with_headers", failure), mock.patch.object(
                        MODULE, "start_debug_collection", side_effect=AssertionError("auth retry reopened collection"),
                    ):
                        if trigger == "manual":
                            self.assertEqual("paused", self.agent().upload_logs_now()["status"])
                        else:
                            worker = self.agent()
                            worker._run_debug_log_upload(PANEL, "agent-token", self.metadata, resume_auth=True)
                    self.assertEqual(1, failure.call_count)
                    self.assertEqual("paused", self.state()["status"])
                    self.assertEqual(paused["upload_id"], self.state()["upload_id"])
                    self.assertEqual("paused", self.upload(lambda p: self.fail("paused probe looped"))["status"])

    def test_missing_capability_fails_closed_and_passive_worker_skips(self):
        for capability in (None, False, "true", 1):
            with self.subTest(capability=capability):
                self.metadata["debug_collection"]["supports_upload_id"] = capability
                with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("unsupported logs read")):
                    result = self.upload(lambda p: self.fail("unsupported uploaded"))
                self.assertEqual("unsupported_upload_protocol", result["status"])
                with mock.patch.object(MODULE.threading, "Thread") as thread:
                    self.agent().schedule_debug_log_upload(PANEL, "agent-token", self.metadata)
                    thread.assert_not_called()
                self.assertFalse((self.root / "debug-upload-state.json").exists())

    def test_inactive_or_expired_does_not_collect(self):
        with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("inactive logs read")):
            self.assertEqual("not_requested", self.upload(metadata={})["status"])
            self.metadata["debug_collection"]["status"] = "closed"
            self.assertEqual("inactive", self.upload()["status"])
            self.metadata["debug_collection"].update(status="active", expires_at="1970-01-01T00:00:01Z")
            self.assertEqual("expired", self.upload()["status"])

    def test_account_panel_and_device_changes_never_reuse_old_snapshot(self):
        first = self.upload(mock.Mock(side_effect=TimeoutError()))
        old_snapshot = next(self.root.glob("debug-upload-*.bin"))
        for change in ({"token": "another-account"}, {"device_id": "linux-new-device"},
                       {"config": {**self.config, "master_url": "https://new-panel.example.test"}}):
            with self.subTest(change=change):
                self.log.write_text("fresh identity logs", encoding="utf-8")
                calls = []
                result = self.upload(lambda p: calls.append(p) or {"ok": True}, **change)
                self.assertEqual("complete", result["status"])
                self.assertNotEqual(first["upload_id"], result["upload_id"])
                self.assertNotIn(b"original", b"".join(base64.b64decode(p["chunk_base64"]) for p in calls))
        self.assertFalse(old_snapshot.exists())
        self.assertEqual(1, len(list(self.root.glob("debug-upload-*.bin"))))

    def test_configured_panel_identity_survives_mirror_selection(self):
        first = self.upload()
        second = self.upload(master="https://mirror.example.test", post=lambda p: self.fail("mirror reset upload"))
        self.assertEqual(first["upload_id"], second["upload_id"])

    def test_missing_or_corrupt_snapshot_is_terminal_never_rebuilt(self):
        for damage in ("delete", "mutate"):
            with self.subTest(damage=damage):
                self.metadata["debug_collection"]["uuid"] = str(uuid.uuid4())
                original = self.upload(mock.Mock(side_effect=TimeoutError()))
                snapshot = self.root / f"debug-upload-{original['upload_id']}.bin"
                if damage == "delete":
                    snapshot.unlink()
                else:
                    snapshot.write_bytes(b"damaged")
                with mock.patch.object(MODULE.time, "time", return_value=2000), mock.patch.object(
                    MODULE, "debug_log_paths", side_effect=AssertionError("corruption recollected"),
                ):
                    result = self.upload(lambda p: self.fail("corrupt snapshot sent"))
                self.assertEqual("terminal", result["status"])
                self.assertEqual(original["upload_id"], result["upload_id"])

    def test_manual_successful_repeated_calls_capture_fresh_isolated_uploads(self):
        agent = self.agent()
        calls = []
        second_collection = {**COLLECTION, "uuid": str(uuid.uuid4())}
        starts = mock.Mock(side_effect=[
            {"debug_collection": dict(COLLECTION), "reference_code": "123e4567"},
            {"debug_collection": second_collection, "reference_code": second_collection["uuid"][:8]},
        ])
        def post(method, url, token, payload, extra_headers):
            calls.append(payload)
            self.assertEqual("linux-current-device", extra_headers["x-device-id"])
            return {"ok": True}, {}
        with mock.patch.object(MODULE, "start_debug_collection", starts), mock.patch.object(
            MODULE, "http_json_with_headers", side_effect=post,
        ):
            first = agent.upload_logs_now()
            self.log.write_text("mutated", encoding="utf-8")
            second = self.agent().upload_logs_now()
        self.assertTrue(first["ok"])
        self.assertTrue(second["ok"])
        self.assertEqual(2, starts.call_count)
        self.assertNotEqual(first["upload_id"], second["upload_id"])
        self.assertEqual(1, second["chunks"])
        self.assertEqual(b"mutated", base64.b64decode(calls[-1]["chunk_base64"]))
        self.assertEqual(0, calls[-1]["sequence"])

    def test_manual_lost_ack_resumes_without_another_start(self):
        starts = mock.Mock(return_value={"debug_collection": dict(COLLECTION), "reference_code": "123e4567"})
        calls = []
        def post(method, url, token, payload, extra_headers):
            calls.append(dict(payload))
            if len(calls) == 1:
                raise TimeoutError("lost ACK")
            return {"ok": True}, {}
        with mock.patch.object(MODULE, "start_debug_collection", starts), mock.patch.object(
            MODULE, "http_json_with_headers", side_effect=post,
        ):
            first = self.agent().upload_logs_now()
            self.log.write_text("changed while restarting command", encoding="utf-8")
            with mock.patch.object(MODULE.time, "time", return_value=2000):
                second = self.agent().upload_logs_now()
        self.assertEqual("retry_later", first["status"])
        self.assertTrue(second["ok"])
        self.assertEqual(1, starts.call_count)
        self.assertEqual(first["upload_id"], second["upload_id"])
        self.assertEqual(calls[0], calls[1])

    def test_passive_freezes_device_and_endpoint_across_all_chunks(self):
        agent = self.agent()
        headers_seen = []
        def post(method, url, token, payload, extra_headers):
            headers_seen.append(dict(extra_headers))
            self.assertEqual(PANEL + "/api/client/debug/log", url)
            self.assertEqual("agent-token", token)
            agent.config["master_url"] = "https://changed-panel.example.test"
            agent.config["agent_token"] = "changed-token"
            agent.device_id = lambda: "changed-device"
            return {"ok": True}, {}
        with mock.patch.object(MODULE, "http_json_with_headers", side_effect=post):
            agent._run_debug_log_upload(PANEL, "agent-token", self.metadata)
        self.assertGreater(len(headers_seen), 1)
        self.assertTrue(all(headers["x-device-id"] == "linux-current-device" for headers in headers_seen))
        self.assertEqual("complete", self.state()["status"])

    def test_stale_passive_job_skips_after_account_switch(self):
        agent = self.agent()
        agent.config["agent_token"] = "changed-token"
        with mock.patch.object(MODULE, "upload_debug_collection") as upload:
            agent._run_debug_log_upload(PANEL, "agent-token", self.metadata)
        upload.assert_not_called()

    def test_stale_passive_job_skips_after_panel_switch_with_same_token(self):
        agent = self.agent()
        agent.config["master_url"] = "https://new-panel.example.test"
        with mock.patch.object(MODULE, "upload_debug_collection") as upload:
            agent._run_debug_log_upload(PANEL, "agent-token", self.metadata)
        upload.assert_not_called()

    def test_corrupt_manifest_never_replaces_snapshot_or_sends(self):
        self.upload(mock.Mock(side_effect=TimeoutError("lost ACK")))
        state_path = self.root / "debug-upload-state.json"
        snapshots = {path.name: path.read_bytes() for path in self.root.glob("debug-upload-*.bin")}
        for damaged in (b"{", b"[]", b"null", b"{}", b'{"schema":1}', b"\xff"):
            with self.subTest(manifest=damaged):
                state_path.write_bytes(damaged)
                with mock.patch.object(MODULE, "debug_log_paths", side_effect=AssertionError("recollected after corruption")), mock.patch.object(
                    MODULE, "start_debug_collection", side_effect=AssertionError("reopened after corruption"),
                ):
                    with self.assertRaises((ValueError, UnicodeDecodeError)):
                        self.upload(lambda p: self.fail("corrupt manifest uploaded"))
                    with self.assertRaises((ValueError, UnicodeDecodeError)):
                        self.agent().upload_logs_now()
                self.assertEqual(damaged, state_path.read_bytes())
                self.assertEqual(snapshots, {path.name: path.read_bytes() for path in self.root.glob("debug-upload-*.bin")})

    def test_unreadable_manifest_never_replaces_snapshot_or_sends(self):
        self.upload(mock.Mock(side_effect=TimeoutError("lost ACK")))
        state_path = self.root / "debug-upload-state.json"
        original_manifest = state_path.read_bytes()
        original_open = pathlib.Path.open
        def open_path(path, *args, **kwargs):
            if path == state_path:
                raise PermissionError("manifest access denied")
            return original_open(path, *args, **kwargs)
        with mock.patch.object(pathlib.Path, "open", open_path), mock.patch.object(
            MODULE, "debug_log_paths", side_effect=AssertionError("recollected unreadable manifest"),
        ), mock.patch.object(MODULE, "start_debug_collection", side_effect=AssertionError("reopened unreadable manifest")):
            with self.assertRaises(PermissionError):
                self.upload(lambda p: self.fail("unreadable manifest uploaded"))
            with self.assertRaises(PermissionError):
                self.agent().upload_logs_now()
        self.assertEqual(original_manifest, state_path.read_bytes())

    def test_persistence_failure_sends_nothing_and_releases_lock(self):
        with mock.patch.object(MODULE.os, "fsync", side_effect=OSError("disk full")):
            with self.assertRaises(OSError):
                self.upload(lambda p: self.fail("sent before durable snapshot"))
        self.assertFalse((self.root / "debug-upload-state.json").exists())
        self.assertEqual("complete", self.upload()["status"])

    def test_ack_cursor_persistence_failure_replays_same_payload(self):
        saved = MODULE._persist_debug_upload
        calls = []
        def persist(directory, state):
            if state["next_sequence"] == 1:
                raise OSError("cursor fsync failed")
            saved(directory, state)
        with mock.patch.object(MODULE, "_persist_debug_upload", side_effect=persist):
            with self.assertRaises(OSError):
                self.upload(lambda payload: calls.append(dict(payload)) or {"ok": True})
        self.assertEqual(0, self.state()["next_sequence"])
        self.log.write_text("mutated after ACK", encoding="utf-8")
        result = self.upload(lambda payload: calls.append(dict(payload)) or {"ok": True})
        self.assertEqual("complete", result["status"])
        self.assertEqual(calls[0], calls[1])

    def test_another_process_blocks_passive_and_manual_before_start(self):
        script = r'''
import importlib.util, json, pathlib, sys, time
spec = importlib.util.spec_from_file_location("debug_lock_child", sys.argv[1])
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
root = pathlib.Path(sys.argv[2])
m.debug_journal_bytes = lambda *units: b""
def post(payload):
    (root / "ready").write_text("ready")
    deadline = time.monotonic() + 15
    while not (root / "release").exists():
        if time.monotonic() > deadline:
            raise TimeoutError("test release timed out")
        time.sleep(0.01)
    return {"ok": True}
result = m.upload_debug_collection(sys.argv[3], "agent-token", json.loads(sys.argv[4]), root,
    {"master_url": sys.argv[3], "debug_log_paths": [str(root / "client.log")]},
    post=post, device_id="linux-current-device")
print(json.dumps(result))
'''
        child = subprocess.Popen(
            [sys.executable, "-c", script, str(AGENT_PATH), str(self.root), PANEL, json.dumps(self.metadata)],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        try:
            deadline = time.monotonic() + 10
            while not (self.root / "ready").exists():
                if child.poll() is not None or time.monotonic() > deadline:
                    stdout, stderr = child.communicate(timeout=2)
                    self.fail(f"lock child did not start: {stdout} {stderr}")
                time.sleep(0.01)
            self.assertEqual("busy", self.upload(lambda p: self.fail("concurrent passive sent"))["status"])
            with mock.patch.object(MODULE, "start_debug_collection", side_effect=AssertionError("concurrent start")):
                self.assertEqual("busy", self.agent().upload_logs_now()["status"])
            (self.root / "release").write_text("release", encoding="utf-8")
            stdout, stderr = child.communicate(timeout=10)
            self.assertEqual(0, child.returncode, stderr)
            self.assertEqual("complete", json.loads(stdout)["status"])
            self.assertEqual("complete", self.upload(lambda p: self.fail("lock release reset state"))["status"])
        finally:
            if child.poll() is None:
                child.kill()
                child.communicate(timeout=5)

    def test_manual_start_itself_holds_the_shared_lock(self):
        def start(*args, **kwargs):
            self.assertEqual("busy", self.upload(lambda p: self.fail("upload entered during start"))["status"])
            self.assertEqual("busy", self.agent().upload_logs_now()["status"])
            return {"debug_collection": dict(COLLECTION), "reference_code": "123e4567"}
        with mock.patch.object(MODULE, "start_debug_collection", side_effect=start), mock.patch.object(
            MODULE, "http_json_with_headers", return_value=({"ok": True}, {}),
        ):
            self.assertTrue(self.agent().upload_logs_now()["ok"])

    def test_start_debug_collection_returns_reference_code(self):
        started = MODULE.start_debug_collection(
            PANEL, "agent-token", self.root,
            post=lambda: {"ok": True, "debug_collection": dict(COLLECTION)},
        )
        self.assertEqual("123e4567", started["reference_code"])


if __name__ == "__main__":
    unittest.main()
