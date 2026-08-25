import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_ultra_status", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def valid_status(forward_id: int) -> dict:
    return {
        "forward_id": forward_id,
        "state": "ready",
        "detected_ingress_zone": "shanghai",
        "location_source": "cache",
        "latest_tcp": {
            "selection_ref": f"tcp-entry-reference-{forward_id:04d}-abcdefghijklmnop",
            "rtt_ms": 18,
            "selected_at": "2030-01-01T00:00:00Z",
            "credential": "must-not-survive",
        },
        "active_udp": {
            "selection_ref": f"udp-entry-reference-{forward_id:04d}-abcdefghijklmnop",
            "rtt_ms": 20,
            "selected_at": "2030-01-01T00:00:01Z",
        },
        "updated_at": "2030-01-01T00:00:02Z",
        "error_code": "",
        "token": "must-not-survive",
    }


class UltraStatusCollectionTests(unittest.TestCase):
    def test_collection_is_bounded_and_strips_unknown_fields(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for forward_id in range(1, 36):
                (root / f"forward-{forward_id}.status.json").write_text(
                    json.dumps(valid_status(forward_id)), encoding="utf-8"
                )
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual(32, len(statuses))
        encoded = json.dumps(statuses).lower()
        self.assertNotIn("credential", encoded)
        self.assertNotIn("token", encoded)
        self.assertEqual(
            {"selection_ref", "rtt_ms", "selected_at"},
            set(statuses[0]["latest_tcp"]),
        )

    def test_collection_rejects_symlink_oversize_and_invalid_selection(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / "outside.json"
            target.write_text(json.dumps(valid_status(99)), encoding="utf-8")
            link = root / "forward-1.status.json"
            try:
                link.symlink_to(target)
            except (OSError, NotImplementedError):
                pass
            (root / "forward-2.status.json").write_text("x" * (64 * 1024 + 1), encoding="utf-8")
            invalid = valid_status(3)
            invalid["latest_tcp"]["selection_ref"] = "invalid"
            (root / "forward-3.status.json").write_text(json.dumps(invalid), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual(1, len(statuses))
        self.assertEqual(3, statuses[0]["forward_id"])
        self.assertIsNone(statuses[0]["latest_tcp"])

    def test_existing_heartbeat_contains_one_status_array(self):
        source = (ROOT / "client-agent" / "client_agent.py").read_text(encoding="utf-8")
        heartbeat = source[source.index('f"{master}/api/client/heartbeat"') :]
        heartbeat = heartbeat[: heartbeat.index("now = time.time()")]
        self.assertEqual(1, heartbeat.count('"ultra_status"'))
        self.assertIn("collect_ultra_status", heartbeat)


if __name__ == "__main__":
    unittest.main()
