import importlib.util
import hashlib
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("ultra_source_agent_under_test", ROOT / "source-agent" / "ultra_source_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class UltraSourceAgentTests(unittest.TestCase):
    def test_inventory_merges_index_and_toml_without_secret_fields(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            config = lines / "453.toml"
            config.write_text(
                "\n".join(
                    [
                        "[acc]",
                        "game_id = 6262",
                        "area_id = 11796",
                        "line_type = 8",
                        "[private]",
                        'account_password = "must-not-survive"',
                        'session_token = "must-not-survive-either"',
                    ]
                ),
                encoding="utf-8",
            )
            index = lines / "index.json"
            index.write_text(
                json.dumps(
                    {
                        "lines": [
                            {
                                "line_id": 453,
                                "title": "Shanghai to Japan",
                                "family_key": "Shanghai to Japan BGP",
                                "speed_ip": "203.0.113.20",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            expected_hash = hashlib.sha256(config.read_bytes()).hexdigest()
            inventory = MODULE.build_inventory(lines, index, max_lines=256)

        self.assertTrue(inventory["complete"])
        self.assertEqual(64, len(inventory["inventory_fingerprint"]))
        self.assertEqual(1, len(inventory["lines"]))
        line = inventory["lines"][0]
        self.assertEqual(453, line["line_id"])
        self.assertEqual("Shanghai to Japan", line["title"])
        self.assertEqual(6262, line["game_id"])
        self.assertEqual(11796, line["area_id"])
        self.assertEqual(8, line["line_type"])
        self.assertEqual("203.0.113.20", line["discovery_ip"])
        self.assertEqual(expected_hash, line["config_sha256"])
        encoded = json.dumps(inventory).lower()
        self.assertNotIn("account_password", encoded)
        self.assertNotIn("session_token", encoded)
        self.assertNotIn("must-not-survive", encoded)

    def test_inventory_is_sorted_and_bounded(self):
        with tempfile.TemporaryDirectory() as directory:
            lines = Path(directory)
            for line_id in range(300, 0, -1):
                (lines / f"{line_id}.toml").write_text(f"game_id = {line_id}\n", encoding="utf-8")
            inventory = MODULE.build_inventory(lines, lines / "missing-index.json", max_lines=256)
        self.assertEqual(256, len(inventory["lines"]))
        self.assertEqual(list(range(1, 257)), [line["line_id"] for line in inventory["lines"]])

    def test_inventory_due_on_startup_change_and_thirty_minute_refresh(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "inventory-state.json"
            self.assertTrue(MODULE.inventory_due(state, "a" * 64, now=1000, refresh_seconds=1800))
            MODULE.write_inventory_state(state, "a" * 64, uploaded_at=1000)
            self.assertFalse(MODULE.inventory_due(state, "a" * 64, now=2799, refresh_seconds=1800))
            self.assertTrue(MODULE.inventory_due(state, "a" * 64, now=2800, refresh_seconds=1800))
            self.assertTrue(MODULE.inventory_due(state, "b" * 64, now=1001, refresh_seconds=1800))
            if os.name != "nt":
                self.assertEqual(0o600, state.stat().st_mode & 0o777)

    def test_agent_cycle_uploads_inventory_once_before_polling_jobs(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            (lines / "453.toml").write_text("[acc]\nline_id = 453\n", encoding="utf-8")
            (lines / "index.json").write_text(
                json.dumps({"lines": [{"line_id": 453, "title": "Shanghai to Japan"}]}),
                encoding="utf-8",
            )
            config = root / "agent.json"
            config.write_text(
                json.dumps(
                    {
                        "panel_url": "https://panel.example",
                        "source_id": "source-a",
                        "source_secret": "source-secret",
                        "lines_dir": str(lines),
                        "inventory_path": str(lines / "index.json"),
                        "state_dir": str(root / "state"),
                    }
                ),
                encoding="utf-8",
            )
            calls = []

            def fake_request(_base_url, _source_id, _source_secret, method, path, payload, _timeout):
                calls.append((method, path, payload))
                if path == "/api/ultra/source/inventory":
                    return {"ok": True, "accepted": 1}
                return {"ok": True, "line_ids": []}

            with mock.patch.object(MODULE, "request_json", side_effect=fake_request):
                agent = MODULE.SourceAgent(config)
                self.assertEqual(0, agent.run_cycle())
                self.assertEqual(0, agent.run_cycle())

            self.assertEqual(
                [
                    ("POST", "/api/ultra/source/inventory"),
                    ("GET", "/api/ultra/source/jobs"),
                    ("GET", "/api/ultra/source/jobs"),
                ],
                [(method, path) for method, path, _payload in calls],
            )
            state = json.loads((root / "state" / "inventory-state.json").read_text(encoding="utf-8"))
            self.assertEqual(64, len(state["inventory_fingerprint"]))

    def test_signed_headers_bind_method_path_nonce_and_body(self):
        headers = MODULE.signed_headers(
            "source-a",
            "source-secret",
            "POST",
            "/api/ultra/source/materials",
            b'{"a":1}',
            timestamp=100,
            nonce="nonce-a",
        )
        self.assertEqual("source-a", headers["X-Ultra-Source-Id"])
        self.assertEqual("100", headers["X-Ultra-Timestamp"])
        self.assertEqual("nonce-a", headers["X-Ultra-Nonce"])
        self.assertEqual(64, len(headers["X-Ultra-Signature"]))

    def test_material_parser_keeps_only_short_lived_line_fields(self):
        parsed = MODULE.normalize_acquisition(
            453,
            {
                "data": {
                    "line_user": "line-user",
                    "line_token": "1234567890123456",
                    "pc_port": 5438,
                    "fail_over_list": [{"speed_ip": "203.0.113.20", "pc_port": 5440}],
                    "spare_list": ["203.0.113.21"],
                    "account_password": "must-not-survive",
                }
            },
            now=1000,
            lifetime_seconds=1200,
        )
        encoded = json.dumps(parsed).lower()
        self.assertEqual(453, parsed["line_id"])
        self.assertEqual(2, len(parsed["relays"]))
        self.assertNotIn("account_password", encoded)
        self.assertNotIn("session", encoded)

    def test_acquisition_is_sequential_and_uses_no_shell(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            line_file = root / "453.toml"
            line_file.write_text("line_id = 453\n", encoding="utf-8")
            output = root / "result.json"
            completed = mock.Mock(returncode=0)

            def fake_run(command, **kwargs):
                output.write_text(
                    json.dumps({"data": {"line_user": "u", "line_token": "1234567890123456", "pc_port": 5438, "spare_list": ["203.0.113.20"]}}),
                    encoding="utf-8",
                )
                self.assertFalse(kwargs.get("shell", False))
                self.assertEqual("startacc", command[1])
                self.assertEqual(["--config", str(line_file), "--save", str(output)], command[2:])
                return completed

            with mock.patch.object(MODULE.subprocess, "run", side_effect=fake_run):
                material = MODULE.acquire_line(
                    453,
                    Path("/opt/ultra-source/runtime/line-client"),
                    root,
                    output,
                    timeout=10,
                    lifetime_seconds=1200,
                    now=1000,
                )
            self.assertEqual(453, material["line_id"])

    def test_acquisition_can_bind_a_dedicated_session_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            line_file = root / "453.toml"
            line_file.write_text("line_id = 453\n", encoding="utf-8")
            output = root / "result.json"
            session = root / "session.json"

            def fake_run(command, **kwargs):
                output.write_text(
                    json.dumps({"data": {"line_user": "u", "line_token": "1234567890123456", "pc_port": 5438, "spare_list": ["203.0.113.20"]}}),
                    encoding="utf-8",
                )
                self.assertEqual(
                    ["--config", str(line_file), "--session", str(session), "--save", str(output)],
                    command[2:],
                )
                return mock.Mock(returncode=0)

            with mock.patch.object(MODULE.subprocess, "run", side_effect=fake_run):
                material = MODULE.acquire_line(
                    453,
                    Path("/opt/ultra-source/runtime/line-client"),
                    root,
                    output,
                    session_path=session,
                    timeout=10,
                    lifetime_seconds=1200,
                    now=1000,
                )
            self.assertEqual(453, material["line_id"])

    def test_service_has_no_listener_or_traffic_role(self):
        service = (ROOT / "source-agent" / "ultra-source-agent.service").read_text(encoding="utf-8").lower()
        source = (ROOT / "source-agent" / "ultra_source_agent.py").read_text(encoding="utf-8").lower()
        for forbidden in ("listenstream", "listendatagram", "18443", "gateway", "socks"):
            self.assertNotIn(forbidden, service)
        self.assertNotIn("socket.socket", source)

    def test_job_request_retries_are_bounded(self):
        with mock.patch.object(
            MODULE,
            "request_json",
            side_effect=[RuntimeError("a"), RuntimeError("b"), {"ok": True}],
        ) as request:
            with mock.patch.object(MODULE.time, "sleep") as sleep:
                result = MODULE.request_json_with_retries(
                    "https://panel.example",
                    "source-a",
                    "source-secret",
                    "GET",
                    "/api/ultra/source/jobs",
                    None,
                    1,
                    attempts=3,
                )
        self.assertEqual({"ok": True}, result)
        self.assertEqual(3, request.call_count)
        self.assertEqual([0.5, 1.5], [call.args[0] for call in sleep.call_args_list])


if __name__ == "__main__":
    unittest.main()
