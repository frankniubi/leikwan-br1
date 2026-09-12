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

    def test_collection_preserves_bounded_udp_probe_quality(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            status = valid_status(3)
            status["active_udp"].update(
                {"p95_rtt_ms": 31, "jitter_ms": 4, "loss_percent": 5, "probe_sent": 20, "probe_received": 19}
            )
            (root / "forward-3.status.json").write_text(json.dumps(status), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual(
            {"p95_rtt_ms": 31, "jitter_ms": 4, "loss_percent": 5, "probe_sent": 20, "probe_received": 19},
            {key: statuses[0]["active_udp"][key] for key in ("p95_rtt_ms", "jitter_ms", "loss_percent", "probe_sent", "probe_received")},
        )

    def test_collection_preserves_valid_candidate_hash_on_selection(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            status = valid_status(3)
            status["latest_tcp"]["candidate_key_hash"] = "d" * 64
            status["latest_tcp"]["candidate_key_hash_bad"] = "not-a-hash"
            (root / "forward-3.status.json").write_text(json.dumps(status), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual("d" * 64, statuses[0]["latest_tcp"]["candidate_key_hash"])
        self.assertNotIn("candidate_key_hash_bad", statuses[0]["latest_tcp"])

    def test_collection_preserves_only_classified_candidate_feedback(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            status = valid_status(3)
            status["candidate_feedback"] = [
                {
                    "candidate_key_hash": "b" * 64,
                    "lease_hash": "e" * 64,
                    "protocol": "tcp",
                    "outcome": "failure",
                    "reason": "relay_connect_timeout",
                    "evidence_id": "evidence-1",
                    "observed_at": "2030-01-01T00:00:03Z",
                },
                {
                    "candidate_key_hash": "c" * 64,
                    "protocol": "tcp",
                    "outcome": "failure",
                    "reason": "process_stderr",
                    "evidence_id": "stderr-1",
                },
            ]
            (root / "forward-3.status.json").write_text(json.dumps(status), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual(1, len(statuses[0]["candidate_feedback"]))
        self.assertEqual("b" * 64, statuses[0]["candidate_feedback"][0]["candidate_key_hash"])
        self.assertEqual("e" * 64, statuses[0]["candidate_feedback"][0]["lease_hash"])
        collected = MODULE.collect_ultra_candidate_feedback(statuses)
        self.assertEqual(3, collected[0]["forward_id"])
        expected = [dict(statuses[0]["candidate_feedback"][0], forward_id=3)]
        self.assertEqual(
            expected,
            collected,
        )

    def test_collection_passes_measured_ceiling_and_build_attestation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            status = valid_status(3)
            status["udp_payload_ceiling"] = 1440
            status["udp_oversize_drops"] = 7
            status["runtime_attestation"] = {
                "fingerprint": "a1b2c3d4",
                "self_sha256": "f" * 64,
                "debugger": False,
                "instrumented": True,
                "unexpected": "must-not-survive",
            }
            (root / "forward-3.status.json").write_text(json.dumps(status), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual(1440, statuses[0]["udp_payload_ceiling"])
        self.assertEqual(7, statuses[0]["udp_oversize_drops"])
        self.assertEqual(
            {
                "fingerprint": "a1b2c3d4",
                "self_sha256": "f" * 64,
                "debugger": False,
                "instrumented": True,
            },
            statuses[0]["runtime_attestation"],
        )

    def test_collection_accepts_development_attestation_fingerprint(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            status = valid_status(3)
            status["runtime_attestation"] = {
                "fingerprint": "development",
                "self_sha256": "0" * 64,
                "debugger": True,
                "instrumented": False,
            }
            (root / "forward-3.status.json").write_text(json.dumps(status), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual("development", statuses[0]["runtime_attestation"]["fingerprint"])
        self.assertTrue(statuses[0]["runtime_attestation"]["debugger"])

    def test_collection_omits_absent_and_zero_runtime_measurements(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            absent = valid_status(1)
            zeroed = valid_status(2)
            zeroed["udp_payload_ceiling"] = 0
            zeroed["udp_oversize_drops"] = 0
            (root / "forward-1.status.json").write_text(json.dumps(absent), encoding="utf-8")
            (root / "forward-2.status.json").write_text(json.dumps(zeroed), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual(2, len(statuses))
        for status in statuses:
            self.assertNotIn("udp_payload_ceiling", status)
            self.assertNotIn("udp_oversize_drops", status)
            self.assertNotIn("runtime_attestation", status)

    def test_collection_drops_malformed_measurements_and_attestation(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            status = valid_status(3)
            status["udp_payload_ceiling"] = "not-a-number"
            status["udp_oversize_drops"] = -4
            status["runtime_attestation"] = {
                "fingerprint": "a1b2c3d4",
                "self_sha256": "too-short",
                "debugger": False,
                "instrumented": False,
            }
            (root / "forward-3.status.json").write_text(json.dumps(status), encoding="utf-8")
            invalid_attestation = valid_status(4)
            invalid_attestation["runtime_attestation"] = {
                "fingerprint": "nrt1.nbwly.com",
                "self_sha256": "f" * 64,
                "debugger": "false",
                "instrumented": False,
            }
            (root / "forward-4.status.json").write_text(json.dumps(invalid_attestation), encoding="utf-8")
            statuses = MODULE.collect_ultra_status(root, expected_uid=getattr(os, "getuid", lambda: 0)())
        self.assertEqual([3, 4], [status["forward_id"] for status in statuses])
        for status in statuses:
            self.assertNotIn("udp_payload_ceiling", status)
            self.assertNotIn("udp_oversize_drops", status)
            self.assertNotIn("runtime_attestation", status)

    def test_existing_heartbeat_contains_one_status_array(self):
        source = (ROOT / "client-agent" / "client_agent.py").read_text(encoding="utf-8")
        heartbeat = source[source.index('f"{master}/api/client/heartbeat"') :]
        heartbeat = heartbeat[: heartbeat.index("now = time.time()")]
        self.assertEqual(1, heartbeat.count('"ultra_status"'))
        self.assertIn('"ultra_candidate_feedback"', heartbeat)
        self.assertIn("collect_ultra_status", heartbeat)


if __name__ == "__main__":
    unittest.main()
