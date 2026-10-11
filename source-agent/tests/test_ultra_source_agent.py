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
    def test_linelist_parser_uses_output_title_segments_without_city_table(self):
        valid, records = MODULE.parse_linelist_output(
            "\n".join(
                [
                    "os_type=3 game_id=6262 area_id=11796 lines=3",
                    "  line_id=460 area_id=11796 line_type=8 title=广州-日本-BGP-腾讯云【主机】 speed_ip=42.240.152.200 pc_port=5438",
                    "  line_id=453 area_id=11796 line_type=8 title=上海-日本-BGP-层峰【主机】 speed_ip=106.75.231.207 pc_port=5438",
                    "  line_id=347 area_id=3757 line_type=0 title=TCP-游戏下载 speed_ip=120.232.77.32 pc_port=5438",
                ]
            )
        )

        self.assertTrue(valid)
        self.assertEqual("上海", records[453]["catalog_ingress"])
        self.assertEqual("日本", records[453]["catalog_destination"])
        self.assertEqual("TCP", records[347]["catalog_ingress"])
        self.assertEqual("游戏下载", records[347]["catalog_destination"])
        encoded = json.dumps(records, ensure_ascii=False)
        self.assertNotIn("pc_port", encoded)
        self.assertNotIn("5438", encoded)

    def test_linelist_parser_rejects_output_without_a_valid_summary(self):
        valid, records = MODULE.parse_linelist_output(
            "line_id=453 area_id=11796 line_type=8 title=上海-日本 speed_ip=203.0.113.20 pc_port=5438"
        )
        self.assertFalse(valid)
        self.assertEqual({}, records)

    def test_catalog_refresh_groups_queries_and_preserves_only_failed_pair_cache(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            for line_id, game_id, area_id in [(453, 6262, 11796), (460, 6262, 11796), (455, 6262, 11800)]:
                (lines / f"{line_id}.toml").write_text(
                    "\n".join(
                        [
                            "[acc]",
                            f"game_id = {game_id}",
                            f"area_id = {area_id}",
                            "line_type = 8",
                            f"line_id = {line_id}",
                        ]
                    ),
                    encoding="utf-8",
                )
            cache = root / "catalog-cache.json"
            cache.write_text(
                json.dumps(
                    {
                        "schema_version": 1,
                        "records": {
                            "453": {"line_id": 453, "title": "old-a", "catalog_ingress": "old", "catalog_destination": "old", "discovery_ip": "203.0.113.1"},
                            "460": {"line_id": 460, "title": "old-omitted", "catalog_ingress": "old", "catalog_destination": "old", "discovery_ip": "203.0.113.2"},
                            "455": {"line_id": 455, "title": "old-b", "catalog_ingress": "old", "catalog_destination": "old", "discovery_ip": "203.0.113.3"},
                        },
                        "pairs": {},
                    }
                ),
                encoding="utf-8",
            )
            commands = []

            def fake_run(command, **kwargs):
                commands.append((command, kwargs))
                config = Path(command[command.index("--config") + 1])
                if config.name == "453.toml":
                    return mock.Mock(
                        returncode=0,
                        stdout="\n".join(
                            [
                                "os_type=3 game_id=6262 area_id=11796 lines=1",
                                "  line_id=453 area_id=11796 line_type=8 title=上海-日本-BGP speed_ip=203.0.113.20 pc_port=5438",
                            ]
                        ),
                    )
                return mock.Mock(returncode=1, stdout="temporary failure")

            with mock.patch.object(MODULE.subprocess, "run", side_effect=fake_run):
                result = MODULE.refresh_catalog_cache(
                    lines,
                    Path("/runtime/line-client"),
                    Path("/state/session.json"),
                    cache,
                    now=10_000,
                    refresh_seconds=1800,
                    retry_seconds=300,
                    max_queries=8,
                    timeout=9,
                )

            saved = json.loads(cache.read_text(encoding="utf-8"))
            self.assertEqual({"attempted": 2, "succeeded": 1, "changed": 1}, result)
            self.assertEqual(2, len(commands), "three local lines must collapse to two game/area queries")
            self.assertEqual(["linelist", "--config"], commands[0][0][1:3])
            self.assertIn("--session", commands[0][0])
            self.assertFalse(commands[0][1].get("shell", False))
            self.assertEqual(9, commands[0][1]["timeout"])
            self.assertEqual("上海", saved["records"]["453"]["catalog_ingress"])
            self.assertNotIn("460", saved["records"], "a successful current result must retire an omitted line record")
            self.assertEqual("old-b", saved["records"]["455"]["title"], "a failed pair must preserve its prior safe metadata")
            if os.name != "nt":
                self.assertEqual(0o600, cache.stat().st_mode & 0o777)

    def test_catalog_refresh_is_bounded_and_defers_fresh_or_recently_failed_pairs(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            for offset in range(5):
                line_id = 100 + offset
                (lines / f"{line_id}.toml").write_text(
                    f"[acc]\ngame_id = {1000 + offset}\narea_id = {2000 + offset}\nline_type = 8\nline_id = {line_id}\n",
                    encoding="utf-8",
                )
            cache = root / "catalog-cache.json"
            cache.write_text(
                json.dumps(
                    {
                        "schema_version": 1,
                        "records": {},
                        "pairs": {
                            "1000:2000": {"last_attempt_at": 9900, "last_success_at": 9900},
                            "1001:2001": {"last_attempt_at": 9900, "last_success_at": 0},
                        },
                    }
                ),
                encoding="utf-8",
            )

            output = "os_type=3 game_id=1 area_id=1 lines=0"
            with mock.patch.object(MODULE.subprocess, "run", return_value=mock.Mock(returncode=0, stdout=output)) as run:
                result = MODULE.refresh_catalog_cache(
                    lines,
                    Path("/runtime/line-client"),
                    None,
                    cache,
                    now=10_000,
                    refresh_seconds=1800,
                    retry_seconds=300,
                    max_queries=2,
                    timeout=5,
                )

            self.assertEqual(2, run.call_count)
            self.assertEqual(2, result["attempted"])
            queried_configs = [Path(call.args[0][call.args[0].index("--config") + 1]).name for call in run.call_args_list]
            self.assertEqual(["102.toml", "103.toml"], queried_configs)
            for call in run.call_args_list:
                self.assertNotIn("--session", call.args[0])

    def test_inventory_prefers_cached_linelist_topology_over_partial_index(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            config = lines / "453.toml"
            config.write_text("[acc]\ngame_id = 6262\narea_id = 11796\nline_type = 8\nline_id = 453\n", encoding="utf-8")
            index = lines / "index.json"
            index.write_text(json.dumps({"lines": [{"line_id": 453, "title": "stale-title", "speed_ip": "203.0.113.1"}]}), encoding="utf-8")
            cache = root / "catalog-cache.json"
            cache.write_text(
                json.dumps(
                    {
                        "schema_version": 1,
                        "records": {
                            "453": {
                                "line_id": 453,
                                "title": "上海-日本-BGP-层峰【主机】",
                                "catalog_ingress": "上海",
                                "catalog_destination": "日本",
                                "discovery_ip": "203.0.113.20",
                            }
                        },
                        "pairs": {},
                    },
                    ensure_ascii=False,
                ),
                encoding="utf-8",
            )

            inventory = MODULE.build_inventory(lines, index, catalog_path=cache, max_lines=256)

        line = inventory["lines"][0]
        self.assertEqual("上海-日本-BGP-层峰【主机】", line["title"])
        self.assertEqual("上海", line["catalog_ingress"])
        self.assertEqual("日本", line["catalog_destination"])
        self.assertEqual("203.0.113.20", line["discovery_ip"])
        encoded = json.dumps(inventory, ensure_ascii=False).lower()
        self.assertNotIn("pc_port", encoded)
        self.assertNotIn("credential", encoded)

    def test_inventory_keeps_local_config_when_catalog_omits_line(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            for line_id in (453, 460):
                (lines / f"{line_id}.toml").write_text(
                    f"[acc]\ngame_id = 6262\narea_id = 11796\nline_type = 8\nline_id = {line_id}\n",
                    encoding="utf-8",
                )
            cache = root / "catalog-cache.json"
            cache.write_text(
                json.dumps(
                    {
                        "schema_version": 1,
                        "records": {
                            "453": {
                                "line_id": 453,
                                "title": "上海-日本-BGP",
                                "catalog_ingress": "上海",
                                "catalog_destination": "日本",
                                "discovery_ip": "203.0.113.20",
                            }
                        },
                        "pairs": {"6262:11796": {"last_attempt_at": 10_000, "last_success_at": 10_000}},
                    },
                    ensure_ascii=False,
                ),
                encoding="utf-8",
            )

            inventory = MODULE.build_inventory(lines, lines / "missing-index.json", catalog_path=cache, max_lines=256)

        self.assertTrue(inventory["complete"])
        self.assertEqual([453, 460], [line["line_id"] for line in inventory["lines"]])

    def test_inventory_keeps_local_configs_until_every_catalog_pair_has_succeeded(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            for line_id, area_id in ((453, 11796), (455, 11800)):
                (lines / f"{line_id}.toml").write_text(
                    f"[acc]\ngame_id = 6262\narea_id = {area_id}\nline_type = 8\nline_id = {line_id}\n",
                    encoding="utf-8",
                )
            cache = root / "catalog-cache.json"
            cache.write_text(
                json.dumps(
                    {
                        "schema_version": 1,
                        "records": {"453": {"line_id": 453, "title": "上海-日本"}},
                        "pairs": {"6262:11796": {"last_attempt_at": 10_000, "last_success_at": 10_000}},
                    },
                    ensure_ascii=False,
                ),
                encoding="utf-8",
            )

            inventory = MODULE.build_inventory(lines, lines / "missing-index.json", catalog_path=cache, max_lines=256)

        self.assertEqual([453, 455], [line["line_id"] for line in inventory["lines"]])

    def test_inventory_unions_index_and_audit_manifest_without_catalog_filtering(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            (lines / "453.toml").write_text(
                "[acc]\ngame_id = 6262\narea_id = 11796\nline_type = 8\nline_id = 453\n",
                encoding="utf-8",
            )
            index = lines / "index.json"
            index.write_text(
                json.dumps(
                    {
                        "lines": [
                            {"line_id": 453, "title": "local line", "speed_ip": "203.0.113.1"},
                            {"line_id": 460, "title": "index-only line", "game_id": 6262, "area_id": 11796},
                        ]
                    }
                ),
                encoding="utf-8",
            )
            audit = root / "line-audit.json"
            audit.write_text(
                json.dumps(
                    {
                        "defaults": {
                            "access_class": "paid",
                            "native_udp_state": "verified",
                            "route_class": "direct",
                            "pricing_class": "monthly",
                            "pricing_evidence": "default-plan",
                        },
                        "audit": {
                            "timestamp": "2026-08-30T00:00:00Z",
                            "sha256": "b" * 64,
                        },
                        "lines": [
                            {
                                "line_id": 119,
                                "game_id": 6262,
                                "area_id": 11796,
                                "line_type": 8,
                                "access_class": "paid",
                                "native_udp_state": "verified",
                                "route_class": "direct",
                                "pricing_class": "monthly",
                                "pricing_evidence": "plan-119",
                                "audit_at": "2026-08-31T00:00:00Z",
                                "audit_hash": "a" * 64,
                                "account_password": "must-not-survive",
                            },
                            {"line_id": 120},
                        ]
                    }
                ),
                encoding="utf-8",
            )
            catalog = root / "catalog-cache.json"
            catalog.write_text(
                json.dumps(
                    {
                        "schema_version": 1,
                        "records": {"453": {"line_id": 453, "title": "catalog line"}},
                        "pairs": {"6262:11796": {"last_success_at": 10_000}},
                    }
                ),
                encoding="utf-8",
            )

            inventory = MODULE.build_inventory(
                lines,
                index,
                catalog_path=catalog,
                audit_manifest_path=audit,
                max_lines=256,
            )

        self.assertEqual([119, 120, 453, 460], [line["line_id"] for line in inventory["lines"]])
        self.assertTrue(inventory["complete"])
        self.assertEqual(3, inventory["schema_version"])
        audit_line = inventory["lines"][0]
        self.assertEqual("paid", audit_line["access_class"])
        self.assertEqual("verified", audit_line["native_udp_state"])
        self.assertEqual("direct", audit_line["route_class"])
        self.assertEqual("monthly", audit_line["pricing_class"])
        self.assertEqual("plan-119", audit_line["pricing_evidence"])
        self.assertEqual("2026-08-31T00:00:00Z", audit_line["audit_at"])
        self.assertEqual("a" * 64, audit_line["audit_hash"])
        self.assertNotIn("account_password", json.dumps(inventory).lower())
        self.assertEqual("paid", inventory["lines"][1]["access_class"])
        self.assertEqual("2026-08-30T00:00:00Z", inventory["lines"][1]["audit_at"])
        self.assertEqual("b" * 64, inventory["lines"][1]["audit_hash"])
        self.assertEqual("unknown", inventory["lines"][2]["access_class"])
        self.assertEqual("unknown", inventory["lines"][2]["audit_hash"])

        with tempfile.TemporaryDirectory() as single_directory:
            single = Path(single_directory) / "single-audit.json"
            single.write_text(
                json.dumps(
                    {
                        "line": {"line_id": 118, "title": "nested line"},
                        "audit": {"native_udp_state": "verified", "sha256": "c" * 64},
                    }
                ),
                encoding="utf-8",
            )
            single_records = MODULE._line_audit_manifest(single)
        self.assertEqual([118], list(single_records))
        self.assertEqual("verified", single_records[118]["native_udp_state"])
        self.assertEqual("c" * 64, single_records[118]["sha256"])

    def test_inventory_119_lines_is_complete_and_has_schema_version(self):
        with tempfile.TemporaryDirectory() as directory:
            lines = Path(directory)
            for line_id in range(119, 0, -1):
                (lines / f"{line_id}.toml").write_text(f"line_id = {line_id}\n", encoding="utf-8")
            inventory = MODULE.build_inventory(lines, lines / "missing-index.json", max_lines=256)

        self.assertEqual(119, len(inventory["lines"]))
        self.assertTrue(inventory["complete"])
        self.assertEqual(list(range(1, 120)), [line["line_id"] for line in inventory["lines"]])
        self.assertEqual(3, inventory["schema_version"])
        self.assertNotEqual("2026.08.24.3", inventory["agent_version"])

    def test_inventory_fingerprint_covers_lines_beyond_payload_limit(self):
        with tempfile.TemporaryDirectory() as directory:
            lines = Path(directory)
            for line_id in range(1, 258):
                (lines / f"{line_id}.toml").write_text(f"line_id = {line_id}\n", encoding="utf-8")
            first = MODULE.build_inventory(lines, lines / "missing-index.json", max_lines=256)
            (lines / "257.toml").write_text("line_id = 257\nline_type = 9\n", encoding="utf-8")
            second = MODULE.build_inventory(lines, lines / "missing-index.json", max_lines=256)

        self.assertFalse(first["complete"])
        self.assertFalse(second["complete"])
        self.assertEqual(first["lines"], second["lines"])
        self.assertNotEqual(first["inventory_fingerprint"], second["inventory_fingerprint"])

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

    def test_inventory_carries_carrier_metadata_without_ports_or_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            index = lines / "index.json"
            index.write_text(
                json.dumps(
                    {
                        "lines": [
                            {
                                "line_id": 327,
                                "title": "上海-韩国-电信单线",
                                "game_id": 6262,
                                "area_id": 11797,
                                "line_type": 8,
                                "in_line_type": 3,
                                "isp": "电信",
                                "provider": "华为云",
                                "speed_ip": "203.0.113.21",
                                "pc_port": 5438,
                                "account_token": "must-not-survive",
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )

            inventory = MODULE.build_inventory(lines, index, max_lines=256)

        line = inventory["lines"][0]
        self.assertEqual("电信", line["isp"])
        self.assertEqual("华为云", line["provider"])
        self.assertEqual(3, line["in_line_type"])
        encoded = json.dumps(inventory, ensure_ascii=False).lower()
        self.assertNotIn("pc_port", encoded)
        self.assertNotIn("5438", encoded)
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
                    ("GET", "/api/ultra/source/jobs?reviews_capacity=1"),
                    ("GET", "/api/ultra/source/jobs?reviews_capacity=1"),
                ],
                [(method, path) for method, path, _payload in calls],
            )
            state = json.loads((root / "state" / "inventory-state.json").read_text(encoding="utf-8"))
            self.assertEqual(64, len(state["inventory_fingerprint"]))

    def test_agent_polling_and_material_jobs_run_before_catalog_refresh(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            (lines / "453.toml").write_text(
                "[acc]\ngame_id = 6262\narea_id = 11796\nline_type = 8\nline_id = 453\n",
                encoding="utf-8",
            )
            (lines / "index.json").write_text(json.dumps({"lines": []}), encoding="utf-8")
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
            events = []

            def fake_request(_base_url, _source_id, _source_secret, _method, path, _payload, _timeout):
                events.append(path)
                if path == "/api/ultra/source/inventory":
                    return {"ok": True, "accepted": 1}
                return {"ok": True, "line_ids": []}

            def fake_catalog(*_args, **_kwargs):
                events.append("catalog")
                return {"attempted": 0, "succeeded": 0, "changed": 0}

            with mock.patch.object(MODULE, "request_json", side_effect=fake_request):
                with mock.patch.object(MODULE, "refresh_catalog_cache", side_effect=fake_catalog):
                    MODULE.SourceAgent(config).run_cycle()

            self.assertEqual(
                ["/api/ultra/source/inventory", "/api/ultra/source/jobs?reviews_capacity=1", "catalog"],
                events,
            )

    def test_agent_reauthenticates_and_retries_partial_line_acquisition_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            for line_id in (1, 2):
                (lines / f"{line_id}.toml").write_text(f"line_id = {line_id}\n", encoding="utf-8")
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
            acquisition_calls = []

            def fake_acquire(line_id, *_args, **_kwargs):
                acquisition_calls.append(line_id)
                if line_id == 2 and acquisition_calls.count(2) == 1:
                    raise RuntimeError("expired session")
                return {"line_id": line_id, "relays": [{"ip": "203.0.113.20", "port": 5438}]}

            with mock.patch.object(MODULE, "build_inventory", return_value={"inventory_fingerprint": "a" * 64, "lines": []}):
                with mock.patch.object(MODULE, "inventory_due", return_value=False):
                    with mock.patch.object(
                        MODULE,
                        "request_json_with_retries",
                        return_value={"ok": True, "upload_ticket": "ticket", "line_ids": [1, 2]},
                    ):
                        with mock.patch.object(MODULE, "acquire_line", side_effect=fake_acquire):
                            with mock.patch.object(
                                MODULE,
                                "request_json",
                                return_value={"ok": True, "accepted_line_ids": [1, 2]},
                            ) as request:
                                with mock.patch.object(MODULE.SourceAgent, "reauthenticate", return_value=True) as reauth:
                                    with mock.patch.object(MODULE.SourceAgent, "refresh_catalog"):
                                        agent = MODULE.SourceAgent(config)
                                        self.assertEqual(2, agent.run_cycle())

            self.assertEqual([1, 2, 2], acquisition_calls)
            reauth.assert_called_once_with()
            self.assertEqual("/api/ultra/source/materials", request.call_args.args[4])

    def _uploader_agent(self, root):
        lines = root / "lines"
        lines.mkdir(exist_ok=True)
        config = root / "agent.json"
        config.write_text(json.dumps({
            "panel_url": "https://panel.example", "source_id": "source-a", "source_secret": "source-secret",
            "binary_path": "/runtime/line-client", "lines_dir": str(lines),
            "inventory_path": str(lines / "index.json"), "state_dir": str(root / "state"),
        }), encoding="utf-8")
        return MODULE.SourceAgent(config)

    def test_each_line_is_uploaded_right_after_it_is_acquired(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._uploader_agent(Path(directory))
            events = []

            def fake_acquire(line_id, *_args, **_kwargs):
                events.append(("acquire", line_id))
                return {"line_id": line_id}

            def fake_request(*args, **_kwargs):
                body = args[5]
                ids = [m["line_id"] for m in body["materials"]]
                events.append(("upload", tuple(ids)))
                return {"ok": True, "accepted_line_ids": ids}

            with mock.patch.object(MODULE, "build_inventory", return_value={"inventory_fingerprint": "a" * 64, "lines": []}),                     mock.patch.object(MODULE, "inventory_due", return_value=False),                     mock.patch.object(MODULE, "request_json_with_retries", return_value={"ok": True, "upload_ticket": "t", "line_ids": [1, 2, 3]}),                     mock.patch.object(MODULE, "acquire_line", side_effect=fake_acquire),                     mock.patch.object(MODULE, "request_json", side_effect=fake_request),                     mock.patch.object(MODULE.SourceAgent, "refresh_catalog"):
                self.assertEqual(3, agent.run_cycle())
            self.assertEqual(
                [("acquire", 1), ("upload", (1,)), ("acquire", 2), ("upload", (2,)), ("acquire", 3), ("upload", (3,))],
                events,
            )

    def test_single_use_ticket_panel_falls_back_to_one_batch_under_a_fresh_ticket(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._uploader_agent(Path(directory))
            tickets = []

            def fake_request(*args, **_kwargs):
                body = args[5]
                tickets.append((body["upload_ticket"], [m["line_id"] for m in body["materials"]]))
                if body["upload_ticket"] == "t1" and len(tickets) > 1:
                    raise RuntimeError("control request failed with HTTP 409: replayed")
                return {"ok": True, "accepted_line_ids": [m["line_id"] for m in body["materials"]]}

            jobs = [{"ok": True, "upload_ticket": "t1", "line_ids": [1, 2, 3]}, {"ok": True, "upload_ticket": "t2", "line_ids": [2, 3]}]
            with mock.patch.object(MODULE, "build_inventory", return_value={"inventory_fingerprint": "a" * 64, "lines": []}),                     mock.patch.object(MODULE, "inventory_due", return_value=False),                     mock.patch.object(MODULE, "request_json_with_retries", side_effect=jobs),                     mock.patch.object(MODULE, "acquire_line", side_effect=lambda line_id, *a, **k: {"line_id": line_id}),                     mock.patch.object(MODULE, "request_json", side_effect=fake_request),                     mock.patch.object(MODULE.SourceAgent, "refresh_catalog"):
                self.assertEqual(3, agent.run_cycle())
            self.assertEqual([("t1", [1]), ("t1", [2]), ("t2", [2, 3])], tickets)

    def test_review_material_is_uploaded_before_the_probe_runs(self):
        with tempfile.TemporaryDirectory() as directory:
            agent = self._uploader_agent(Path(directory))
            order = []
            uploader = MODULE.MaterialUploader(agent, "t")
            with mock.patch.object(MODULE, "acquire_line", return_value={"line_id": 453, "relays": []}),                     mock.patch.object(MODULE, "request_json", side_effect=lambda *a, **k: order.append("upload") or {"ok": True, "accepted_line_ids": [453]}),                     mock.patch.object(Path, "is_file", return_value=True):
                agent.run_review(41, 453, 600, advertised=[], target="198.51.100.1:443", uploader=uploader)
            self.assertEqual(["upload"], order)
            self.assertEqual({453}, uploader.accepted)

    def test_agent_hardens_existing_session_and_same_path_reauthentication(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            lines = root / "lines"
            lines.mkdir()
            session = root / "session.json"
            session.write_text("session", encoding="utf-8")
            binary = root / "line-client"
            binary.write_bytes(b"binary")
            config = root / "agent.json"
            config.write_text(
                json.dumps(
                    {
                        "panel_url": "https://panel.example",
                        "source_id": "source-a",
                        "source_secret": "source-secret",
                        "binary_path": str(binary),
                        "lines_dir": str(lines),
                        "inventory_path": str(lines / "index.json"),
                        "state_dir": str(root / "state"),
                        "session_path": str(session),
                        "login_mobile": "15500000000",
                    }
                ),
                encoding="utf-8",
            )

            with mock.patch.object(MODULE.os, "chmod") as chmod:
                with mock.patch.object(MODULE.subprocess, "run", return_value=mock.Mock(returncode=0)):
                    agent = MODULE.SourceAgent(config)
                    self.assertTrue(agent.reauthenticate())

            self.assertGreaterEqual(chmod.call_count, 2)
            chmod.assert_called_with(session, 0o600)

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

class UltraSourceSignatureTests(unittest.TestCase):
    """The panel signs the request path without its query string.

    A parameter added to a signed URL used to change the canonical request and
    the source answered 401 to every poll, so this is pinned from both sides:
    the same signature with and without the query, and against the canonical
    request the panel builds.
    """

    def _headers(self, path: str) -> dict:
        return MODULE.signed_headers(
            "source-a", "secret", "GET", path, b"", timestamp=1_900_000_000, nonce="ab" * 16
        )

    def test_query_string_does_not_change_the_signature(self):
        self.assertEqual(
            self._headers("/api/ultra/source/jobs")["X-Ultra-Signature"],
            self._headers("/api/ultra/source/jobs?reviews_capacity=1")["X-Ultra-Signature"],
        )

    def test_signature_matches_the_canonical_request_the_panel_builds(self):
        import hmac
        nonce = "ab" * 16
        headers = self._headers("/api/ultra/source/jobs?reviews_capacity=1")
        canonical = "\n".join(
            ["GET", "/api/ultra/source/jobs", "source-a", "1900000000", nonce, hashlib.sha256(b"").hexdigest()]
        )
        self.assertEqual(
            hmac.new(b"secret", canonical.encode("utf-8"), hashlib.sha256).hexdigest(),
            headers["X-Ultra-Signature"],
        )

    def test_review_capacity_is_advertised_in_the_jobs_request(self):
        source = (ROOT / "source-agent" / "ultra_source_agent.py").read_text(encoding="utf-8")
        self.assertIn("reviews_capacity=", source, "the panel hands out no review to an agent that does not ask")
        self.assertEqual("2026.09.24.1", MODULE.AGENT_VERSION)


class UltraSourceAgentReviewTests(unittest.TestCase):
    """The review probe must be pointed at an advertised endpoint and a target."""

    TARGET = "45.143.235.221:10809"
    ADVERTISED = ["1.117.238.119", "121.5.240.89"]

    def _agent(self, root):
        lines = root / "lines"
        lines.mkdir(exist_ok=True)
        script = root / "ultra_line_review.py"
        script.write_text("# review probe\n", encoding="utf-8")
        config = root / "agent.json"
        config.write_text(
            json.dumps(
                {
                    "panel_url": "https://panel.example",
                    "source_id": "source-a",
                    "source_secret": "source-secret",
                    "binary_path": str(root / "line-client"),
                    "lines_dir": str(lines),
                    "inventory_path": str(lines / "index.json"),
                    "state_dir": str(root / "state"),
                    "review_script": str(script),
                }
            ),
            encoding="utf-8",
        )
        return MODULE.SourceAgent(config)

    @staticmethod
    def _material(relays):
        return {"line_id": 453, "user": "line-user", "credential": "1234567890123456", "relays": relays}

    def test_review_probes_an_advertised_relay_not_the_first_acquired_one(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            agent = self._agent(root)
            material = self._material(
                [
                    {"ip": "203.0.113.20", "port": 5438},
                    {"ip": "1.117.238.119", "port": 5440},
                ]
            )
            probe_result = {
                "passed": True,
                "relay": "1.117.238.119:5440",
                "target": self.TARGET,
                "proof": {"endpoint": "1.117.238.119:5440", "local_path_only": False, "end_to_end_ok": 5},
            }
            completed = mock.Mock(returncode=0, stdout=json.dumps(probe_result), stderr="")
            with mock.patch.object(MODULE, "acquire_line", return_value=material):
                with mock.patch.object(MODULE.subprocess, "run", return_value=completed) as run:
                    payload = agent.run_review(41, 453, 600, advertised=self.ADVERTISED, target=self.TARGET)

            command = run.call_args.args[0]
            self.assertIn("1.117.238.119:5440", command, "the advertised IP must be paired with the material port")
            self.assertNotIn("203.0.113.20:5438", command, "the agent must not probe its own first relay")
            self.assertEqual(self.TARGET, command[command.index("--target") + 1])
            self.assertEqual(
                "1.117.238.119,121.5.240.89",
                command[command.index("--advertised") + 1],
                "the probe has to be told the mandate it may prove against",
            )
            self.assertTrue(payload["passed"])
            self.assertEqual(probe_result, payload["detail"])

    def test_review_without_an_advertised_relay_reports_no_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            agent = self._agent(root)
            material = self._material([{"ip": "203.0.113.20", "port": 5438}])
            with mock.patch.object(MODULE, "acquire_line", return_value=material):
                with mock.patch.object(MODULE.subprocess, "run") as run:
                    payload = agent.run_review(41, 453, 600, advertised=["9.9.9.9"], target=self.TARGET)

            run.assert_not_called()
            self.assertFalse(payload["passed"])
            self.assertIn("no advertised relay", payload["note"])
            self.assertEqual(0, payload["detail"]["proof"]["end_to_end_ok"])
            self.assertTrue(payload["detail"]["proof"]["local_path_only"])
            self.assertTrue(payload["detail"]["proof"]["reason"])

    def test_review_without_a_panel_mandate_still_runs_the_material_relay(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            agent = self._agent(root)
            material = self._material([{"ip": "203.0.113.20", "port": 5438}])
            completed = mock.Mock(returncode=1, stdout=json.dumps({"passed": False, "last_error": "nope"}), stderr="")
            with mock.patch.object(MODULE, "acquire_line", return_value=material):
                with mock.patch.object(MODULE.subprocess, "run", return_value=completed) as run:
                    agent.run_review(41, 453, 600, advertised=None, target=self.TARGET)

            command = run.call_args.args[0]
            self.assertIn("203.0.113.20:5438", command)
            self.assertNotIn("--advertised", command)

    def test_review_reports_a_probe_without_json_as_non_discriminating(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            agent = self._agent(root)
            material = self._material([{"ip": "1.117.238.119", "port": 5438}])
            completed = mock.Mock(
                returncode=2,
                stdout="",
                stderr="usage: probe\nprobe: error: unrecognized arguments: --target",
            )
            with mock.patch.object(MODULE, "acquire_line", return_value=material):
                with mock.patch.object(MODULE.subprocess, "run", return_value=completed):
                    payload = agent.run_review(41, 453, 600, advertised=self.ADVERTISED, target=self.TARGET)

            self.assertFalse(payload["passed"])
            self.assertIn("no result (exit 2)", payload["note"])
            self.assertIn("unrecognized arguments", payload["note"])
            self.assertEqual(0, payload["detail"]["end_to_end_ok"])
            self.assertTrue(payload["detail"]["local_path_only"])

    def test_review_without_a_target_sends_an_empty_target(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            agent = self._agent(root)
            material = self._material([{"ip": "1.117.238.119", "port": 5438}])
            completed = mock.Mock(returncode=1, stdout=json.dumps({"passed": False}), stderr="")
            with mock.patch.object(MODULE, "acquire_line", return_value=material):
                with mock.patch.object(MODULE.subprocess, "run", return_value=completed) as run:
                    agent.run_review(41, 453, 600, advertised=self.ADVERTISED, target="")

            command = run.call_args.args[0]
            self.assertEqual("", command[command.index("--target") + 1])

    def test_process_reviews_hands_down_the_panel_payload_fields(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            agent = self._agent(root)
            calls = []

            def fake_run_review(review_id, line_id, seconds, advertised=None, target="", uploader=None):
                calls.append((review_id, line_id, seconds, advertised, target))
                return {"review_id": review_id, "passed": False}

            with mock.patch.object(MODULE.SourceAgent, "run_review", side_effect=fake_run_review):
                with mock.patch.object(MODULE, "request_json", return_value={"ok": True}):
                    completed = agent.process_reviews(
                        [
                            {
                                "review_id": 41,
                                "line_id": 453,
                                "protocol": "tcp+udp",
                                "seconds": 600,
                                "relays": self.ADVERTISED,
                                "target": self.TARGET,
                            }
                        ]
                    )

            self.assertEqual(1, completed)
            self.assertEqual([(41, 453, 600, self.ADVERTISED, self.TARGET)], calls)

    def test_select_review_relay_pairs_advertised_ip_with_material_port(self):
        relay, reason = MODULE.select_review_relay(
            [{"ip": "203.0.113.20", "port": 5438}, {"ip": "1.117.238.119", "port": 5440}],
            self.ADVERTISED,
        )
        self.assertEqual({"ip": "1.117.238.119", "port": 5440}, relay)
        self.assertEqual("", reason)

    def test_select_review_relay_refuses_an_unadvertised_endpoint(self):
        relay, reason = MODULE.select_review_relay([{"ip": "203.0.113.20", "port": 5438}], self.ADVERTISED)
        self.assertIsNone(relay)
        self.assertIn("no advertised relay", reason)

    def test_select_review_relay_skips_unusable_material_entries(self):
        relay, _reason = MODULE.select_review_relay(
            [{"ip": "", "port": 5438}, {"ip": "1.117.238.119", "port": 0}, "junk"],
            self.ADVERTISED,
        )
        self.assertIsNone(relay)

    def test_advertised_relay_ips_validates_and_deduplicates(self):
        self.assertEqual(
            ["1.117.238.119", "121.5.240.89"],
            MODULE.advertised_relay_ips(["1.117.238.119", " 121.5.240.89 ", "1.117.238.119", "", None]),
        )
        self.assertEqual(["1.117.238.119"], MODULE.advertised_relay_ips("1.117.238.119"))
        self.assertEqual([], MODULE.advertised_relay_ips(None))

