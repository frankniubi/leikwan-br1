import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

spec = importlib.util.spec_from_file_location(
    "external_uot_forward_merge",
    Path(__file__).resolve().parents[1] / "sidecar-agent/external_uot_forward_merge.py")
merge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(merge)

BASE = {
    "listen_host": "0.0.0.0",
    "listen_port": 7443,
    "queue_packets": 256,
    "forwards": {
        "158": {"token": "old-hk", "targets": ["141.11.91.62:30522"]},
        "192": {"token": "tw", "targets": ["78.105.182.229:2525"]},
    },
}


LINUX_ONLY_WRITE = (
    "the sidecar merge writes its config with os.fchmod "
    "(Linux sidecar only; absent on Windows before Python 3.13)"
)


class ForwardMergeTests(unittest.TestCase):
    def run_main(self, document, base=None):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "uot.json"
            config.write_text(json.dumps(base or BASE))
            reloads = []
            out = io.StringIO()
            code = merge.main(io.BytesIO(json.dumps(document).encode()), out, config,
                              lambda: reloads.append(True))
            result = json.loads(out.getvalue())
            written = json.loads(config.read_text())
            mode = os.stat(config).st_mode & 0o777
            last_good = (Path(str(config) + ".last-good").read_text()
                         if Path(str(config) + ".last-good").exists() else None)
        return code, result, written, reloads, mode, last_good

    @unittest.skipUnless(sys.platform.startswith("linux"), LINUX_ONLY_WRITE)
    def test_adds_missing_forward_keeps_unrendered_and_knobs(self):
        document = {"listen_port": 9999, "forwards": {
            "192": {"token": "tw", "targets": ["78.105.182.229:2525"]},
            "278": {"token": "tw", "targets": ["example.test:2525"]},
        }}
        code, result, written, reloads, mode, last_good = self.run_main(document)
        self.assertEqual(0, code)
        self.assertEqual([278], result["added"])
        self.assertEqual([], result["updated"])
        self.assertIn("158", written["forwards"])
        self.assertEqual(7443, written["listen_port"])
        self.assertEqual(["example.test:2525"], written["forwards"]["278"]["targets"])
        self.assertEqual([True], reloads)
        if os.name == "posix":
            self.assertEqual(0o600, mode)
        self.assertEqual(BASE, json.loads(last_good))

    @unittest.skipUnless(sys.platform.startswith("linux"), LINUX_ONLY_WRITE)
    def test_updates_changed_token_or_target(self):
        document = {"forwards": {"192": {"token": "tw2", "targets": ["78.105.182.229:2526"]}}}
        _, result, written, reloads, _, _ = self.run_main(document)
        self.assertEqual([192], result["updated"])
        self.assertEqual("tw2", written["forwards"]["192"]["token"])
        self.assertEqual([True], reloads)

    def test_unchanged_document_does_not_write_or_reload(self):
        document = {"forwards": {"192": {"token": "tw", "targets": ["78.105.182.229:2525"]}}}
        _, result, written, reloads, _, last_good = self.run_main(document)
        self.assertFalse(result["changed"])
        self.assertEqual([], reloads)
        self.assertIsNone(last_good)
        self.assertEqual(BASE, written)

    def test_allow_any_entry_is_refused(self):
        document = {"forwards": {"300": {"token": "x", "targets": []}}}
        with self.assertRaises(ValueError):
            self.run_main(document)

    def test_bad_ids_tokens_and_targets_are_refused(self):
        for forwards in (
            {"../1": {"token": "x", "targets": ["a.test:1"]}},
            {"5": {"token": "has space", "targets": ["a.test:1"]}},
            {"5": {"token": "x", "targets": ["a.test"]}},
            {"5": {"token": "x", "targets": ["a.test:70000"]}},
            {"5": {"token": "", "targets": ["a.test:1"]}},
        ):
            with self.subTest(forwards=forwards), self.assertRaises(ValueError):
                self.run_main({"forwards": forwards})

    def test_missing_forwards_map_is_refused(self):
        with self.assertRaises(ValueError):
            self.run_main({"listen_port": 1})

    def test_secret_only_entry_is_accepted_and_reduced_to_contract_fields(self):
        desired = merge.desired_forwards({"forwards": {"300": {
            "secret": SECRET, "targets": ["a.test:1"], "rate_limit_mbps": 100,
            "note": "dropped", "hmac_secret": "dropped"}}})
        self.assertEqual({"300": {"secret": SECRET, "targets": ["a.test:1"],
                                  "rate_limit_mbps": 100}}, desired)
        both = merge.desired_forwards({"forwards": {"300": {
            "secret": SECRET, "token": "tw", "targets": ["a.test:1"]}}})
        self.assertEqual({"token": "tw", "secret": SECRET, "targets": ["a.test:1"]}, both["300"])

    def test_bad_secrets_are_refused(self):
        for entry in (
            {"targets": ["a.test:1"]},
            {"secret": "", "targets": ["a.test:1"]},
            {"secret": "has space", "targets": ["a.test:1"]},
            {"secret": "x" * 8193, "targets": ["a.test:1"]},
            {"secret": 5, "targets": ["a.test:1"]},
            {"secret": SECRET, "token": "", "targets": ["a.test:1"]},
            {"secret": SECRET, "targets": []},
        ):
            with self.subTest(entry=entry), self.assertRaises(ValueError):
                merge.desired_forwards({"forwards": {"5": entry}})

    def test_rate_limit_passthrough_and_validation(self):
        for value in (0, 1, 100, 2.5, 100000):
            with self.subTest(value=value):
                desired = merge.desired_forwards({"forwards": {"5": {
                    "secret": SECRET, "targets": ["a.test:1"], "rate_limit_mbps": value}}})
                self.assertEqual(value, desired["5"]["rate_limit_mbps"])
                self.assertIs(type(value), type(desired["5"]["rate_limit_mbps"]))
        for value in (True, False, -1, -0.5, 100001, float("nan"), float("inf"), "100", None):
            with self.subTest(value=value), self.assertRaises(ValueError):
                merge.desired_forwards({"forwards": {"5": {
                    "secret": SECRET, "targets": ["a.test:1"], "rate_limit_mbps": value}}})
        with self.assertRaises(ValueError):  # JSON NaN on the wire
            self.run_main_raw(b'{"forwards": {"5": {"secret": "s", "targets": ["a.test:1"], '
                              b'"rate_limit_mbps": NaN}}}')

    def test_update_replaces_entry_so_withdrawn_fields_do_not_linger(self):
        current = {"forwards": {"192": {"token": "tw", "secret": SECRET,
                                        "targets": ["78.105.182.229:2525"], "rate_limit_mbps": 50}}}
        desired = merge.desired_forwards({"forwards": {"192": {
            "secret": SECRET, "targets": ["78.105.182.229:2525"]}}})
        merged, added, updated, removed = merge.merge(current, desired)
        self.assertEqual(([], [192], []), (added, updated, removed))
        self.assertEqual({"secret": SECRET, "targets": ["78.105.182.229:2525"]},
                         merged["forwards"]["192"])
        self.assertIn("rate_limit_mbps", current["forwards"]["192"])  # input not mutated

    def test_rate_limit_change_is_an_update(self):
        current = {"forwards": {"192": {"secret": SECRET, "targets": ["a.test:1"]}}}
        desired = merge.desired_forwards({"forwards": {"192": {
            "secret": SECRET, "targets": ["a.test:1"], "rate_limit_mbps": 20}}})
        self.assertEqual([192], merge.merge(current, desired)[2])

    def test_remove_deletes_listed_ignores_unknown_and_keeps_unlisted(self):
        document = {"forwards": {"278": {"secret": SECRET, "targets": ["a.test:1"]}},
                    "remove": [158, "999"]}
        desired = merge.desired_forwards(document)
        remove = merge.removed_forwards(document, desired)
        merged, added, updated, removed = merge.merge(BASE, desired, remove)
        self.assertEqual(([278], [], [158]), (added, updated, removed))
        self.assertEqual({"192", "278"}, set(merged["forwards"]))
        self.assertEqual(BASE["forwards"]["192"], merged["forwards"]["192"])
        self.assertEqual(7443, merged["listen_port"])
        self.assertIn("158", BASE["forwards"])

    @unittest.skipUnless(sys.platform.startswith("linux"), LINUX_ONLY_WRITE)
    def test_remove_only_document_writes_and_reloads(self):
        code, result, written, reloads, _, last_good = self.run_main(
            {"forwards": {}, "remove": [158]})
        self.assertEqual(0, code)
        self.assertTrue(result["changed"])
        self.assertEqual(1, result["removed"])
        self.assertEqual(["192"], list(written["forwards"]))
        self.assertEqual([True], reloads)
        self.assertEqual(BASE, json.loads(last_good))

    def test_removing_only_unknown_ids_is_not_a_change(self):
        _, result, written, reloads, _, last_good = self.run_main(
            {"forwards": {}, "remove": [999, "1000"]})
        self.assertFalse(result["changed"])
        self.assertEqual(0, result["removed"])
        self.assertEqual([], reloads)
        self.assertIsNone(last_good)
        self.assertEqual(BASE, written)

    def test_rendered_and_removed_id_is_refused(self):
        for remove in ([192], ["192"]):
            with self.subTest(remove=remove), self.assertRaises(ValueError):
                self.run_main({"forwards": {"192": {"token": "tw", "targets": ["78.105.182.229:2525"]}},
                               "remove": remove})

    def test_bad_remove_is_refused(self):
        for remove in ("158", {"158": True}, [True], [0], ["../1"], [1.5], [None], ["01"]):
            with self.subTest(remove=remove), self.assertRaises(ValueError):
                self.run_main({"forwards": {}, "remove": remove})

    def run_usage(self, content, command="usage"):
        with tempfile.TemporaryDirectory() as directory:
            usage = Path(directory) / "usage.json"
            if content is not None:
                usage.write_bytes(content)
            out = io.StringIO()
            code = merge.main(ExplodingStdin(), out, Path(directory) / "missing-config.json",
                              lambda: self.fail("usage mode must not reload"),
                              {"SSH_ORIGINAL_COMMAND": command}, usage)
        return code, out.getvalue()

    def test_usage_mode_prints_usage_file_verbatim(self):
        raw = b'{"version":1,"instance":"' + b"a" * 32 + b'","forwards":{"158":{"to_target_bytes":7}}}\n'
        for command in ("usage", "  usage\n"):
            with self.subTest(command=command):
                self.assertEqual((0, raw.decode()), self.run_usage(raw, command))

    def test_usage_mode_prints_empty_object_when_missing_or_invalid(self):
        for content in (None, b"", b"{broken", b"[1]", b"\xff\xfe"):
            with self.subTest(content=content):
                self.assertEqual((0, "{}\n"), self.run_usage(content))

    def test_other_commands_keep_merge_behaviour(self):
        document = {"forwards": {"192": {"token": "tw", "targets": ["78.105.182.229:2525"]}}}
        for command in ("", "merge", "usage please", "cat /etc/shadow"):
            with self.subTest(command=command):
                with tempfile.TemporaryDirectory() as directory:
                    config = Path(directory) / "uot.json"
                    config.write_text(json.dumps(BASE))
                    out = io.StringIO()
                    merge.main(io.BytesIO(json.dumps(document).encode()), out, config,
                               lambda: None, {"SSH_ORIGINAL_COMMAND": command},
                               Path(directory) / "usage.json")
                self.assertTrue(json.loads(out.getvalue())["ok"])

    def run_main_raw(self, raw):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "uot.json"
            config.write_text(json.dumps(BASE))
            return merge.main(io.BytesIO(raw), io.StringIO(), config, lambda: None, {})


class ExplodingStdin:
    def read(self, *args):
        raise AssertionError("usage mode must not read stdin")


SECRET = "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"


if __name__ == "__main__":
    unittest.main()
