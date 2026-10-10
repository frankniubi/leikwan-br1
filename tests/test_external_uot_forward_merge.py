import importlib.util
import io
import json
import os
from pathlib import Path
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


if __name__ == "__main__":
    unittest.main()
