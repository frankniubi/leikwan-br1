#!/usr/bin/env python3
import json
import pathlib
import sys
import tempfile
import unittest


HERE = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

from acceptance import (  # noqa: E402
    compute_jain_fairness,
    evaluate_absolute,
    evaluate_pair,
    load_run,
    main,
    percentile,
    summarize_run,
    validate_topology,
    write_report,
)


PASSING = {
    "duration_seconds": 60,
    "mode": "feedback_drr",
    "pair_id": "normal-64-a",
    "workload": "sessions64",
    "offered_rate_bps": 300_000_000,
    "actual_offered_rate_bps": 300_000_000,
    "useful_rate_bps": 272_000_000,
    "queue_p95_ms": 8.0,
    "queue_p99_ms": 9.0,
    "scheduler_drop_ratio": 0.0009,
    "ingress_channel_full": 0,
    "control_queue_drops": 0,
    "deadline_drops": 0,
    "udp_send_errors": 0,
    "zero_rate_seconds": 0,
    "complete_seconds": 60,
    "jain_fairness": 0.91,
    "memory_bound_violations": 0,
    "receiver_loss_ratio": 0.004,
    "loaded_rtt_p95_ms": 30.0,
    "loaded_rtt_p99_ms": 35.0,
    "latency_drift_ms": 3.0,
    "inner_tcp_retransmissions": 10,
    "direction": "client_to_sidecar",
    "datagram_bytes": 1_200,
    "topology_id": "netns-five-leg",
    "active_legs": 5,
    "feedback_capable_active_legs": 5,
    "legacy_fallback_active_legs": 0,
    "queue_bytes_high_water": 500_000,
    "queue_limit_bytes": 1_000_000,
}


class MetricTests(unittest.TestCase):
    def test_jain_fairness_is_one_for_equal_rates(self):
        self.assertEqual(compute_jain_fairness([10, 10, 10, 10]), 1.0)

    def test_jain_fairness_counts_starved_sessions(self):
        self.assertAlmostEqual(compute_jain_fairness([10, 0]), 0.5)

    def test_percentile_uses_nearest_rank(self):
        values = [1, 2, 3, 4, 100]
        self.assertEqual(percentile(values, 0.50), 3.0)
        self.assertEqual(percentile(values, 0.95), 100.0)
        self.assertEqual(percentile(values, 0.99), 100.0)

    def test_nested_summary_is_normalized(self):
        raw = {
            "duration_seconds": 60,
            "mode": "feedback_drr",
            "pair_id": "normal-64-a",
            "workload": "sessions64",
            "offered_rate_bps": 300_000_000,
            "direction": "client_to_sidecar",
            "datagram_bytes": 1_200,
            "topology_id": "netns-five-leg",
            "active_legs": 5,
            "inner_tcp_retransmissions": 7,
            "control_queue_drops": 0,
            "sender": {"packets_sent": 10_000},
            "receiver": {
                "packets_received": 9_960,
                "useful_bytes": 2_040_000_000,
                "per_session_useful_bytes": [510_000_000] * 4,
                "per_second_useful_bytes": [34_000_000] * 60,
            },
            "latency_ms": [20.0] * 15 + [22.0] * 30 + [25.0] * 15,
            "scheduler": {
                "queue_delay_ms": [2.0] * 95 + [9.0] * 5,
                "admitted_packets": 1_000_000,
                "dropped_packets": 900,
                "ingress_channel_full": 0,
                "deadline_drops": 0,
                "udp_send_errors": 0,
                "memory_bound_violations": 0,
                "queue_bytes_high_water": 500_000,
                "queue_limit_bytes": 1_000_000,
                "feedback_capable_active_legs": 5,
                "legacy_fallback_active_legs": 0,
            },
        }

        run = summarize_run(raw, expected_duration=60)

        self.assertEqual(run["useful_rate_bps"], 272_000_000)
        self.assertAlmostEqual(run["receiver_loss_ratio"], 0.004)
        self.assertEqual(run["queue_p95_ms"], 2.0)
        self.assertEqual(run["queue_p99_ms"], 9.0)
        self.assertEqual(run["loaded_rtt_p95_ms"], 25.0)
        self.assertEqual(run["loaded_rtt_p99_ms"], 25.0)
        self.assertEqual(run["latency_drift_ms"], 5.0)
        self.assertEqual(run["zero_rate_seconds"], 0)
        self.assertEqual(run["complete_seconds"], 60)
        self.assertEqual(run["jain_fairness"], 1.0)
        self.assertAlmostEqual(run["scheduler_drop_ratio"], 0.0009)


class GateTests(unittest.TestCase):
    def test_absolute_gates_pass(self):
        self.assertEqual(evaluate_absolute(PASSING), [])

    def test_each_boundary_failure_is_named(self):
        failures = {
            "duration_seconds": 59,
            "offered_rate_bps": 299_999_999,
            "actual_offered_rate_bps": 296_999_999,
            "useful_rate_bps": 269_999_999,
            "queue_p99_ms": 10.01,
            "scheduler_drop_ratio": 0.00101,
            "ingress_channel_full": 1,
            "control_queue_drops": 1,
            "deadline_drops": 1,
            "udp_send_errors": 1,
            "zero_rate_seconds": 1,
            "complete_seconds": 59,
            "jain_fairness": 0.899,
            "memory_bound_violations": 1,
            "latency_drift_ms": 10.01,
            "feedback_capable_active_legs": 4,
            "legacy_fallback_active_legs": 1,
            "queue_bytes_high_water": 1_000_001,
        }
        for key, value in failures.items():
            with self.subTest(key=key):
                case = PASSING | {key: value}
                expected = "queue_bound" if key == "queue_bytes_high_water" else key
                self.assertIn(expected, evaluate_absolute(case))

    def test_feedback_must_not_regress_paired_legacy(self):
        legacy = PASSING | {
            "mode": "legacy",
            "receiver_loss_ratio": 0.003,
            "loaded_rtt_p95_ms": 19.0,
            "loaded_rtt_p99_ms": 20.0,
            "inner_tcp_retransmissions": 9,
        }
        failures = evaluate_pair(PASSING, legacy)
        self.assertEqual(
            failures,
            [
                "receiver_loss_ratio",
                "loaded_rtt_p95_ms",
                "loaded_rtt_p99_ms",
                "inner_tcp_retransmissions",
            ],
        )

    def test_pair_identity_mismatch_is_rejected(self):
        legacy = PASSING | {"mode": "legacy", "direction": "sidecar_to_client"}
        with self.assertRaisesRegex(ValueError, "paired runs do not share workload identity"):
            evaluate_pair(PASSING, legacy)


class ParserTests(unittest.TestCase):
    def test_run_and_summary_evidence_are_merged(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            run_path = root / "run.json"
            summary_path = root / "summary.json"
            manifest_path = root / "manifest.json"
            run_path.write_text(json.dumps(PASSING), encoding="utf-8")
            summary_path.write_text(json.dumps({"useful_rate_bps": 275_000_000}), encoding="utf-8")
            manifest_path.write_text(
                json.dumps({"evidence": {"run": "run.json", "summary": "summary.json"}}),
                encoding="utf-8",
            )

            loaded = load_run(manifest_path, expected_duration=60)

            self.assertEqual(loaded["useful_rate_bps"], 275_000_000)
            self.assertEqual(loaded["pair_id"], PASSING["pair_id"])

    def test_duplicate_evidence_paths_are_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            evidence_path = root / "run.json"
            manifest_path = root / "manifest.json"
            evidence_path.write_text(json.dumps(PASSING), encoding="utf-8")
            manifest_path.write_text(
                json.dumps({"evidence": {"run": "run.json", "summary": "./run.json"}}),
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "duplicate evidence path"):
                load_run(manifest_path, expected_duration=60)

    def test_write_report_wraps_run_records_with_aggregate_verdict(self):
        with tempfile.TemporaryDirectory() as temp:
            output = pathlib.Path(temp) / "verdict.json"
            write_report([{"mode": "feedback_drr", "failures": []}], output)
            report = json.loads(output.read_text(encoding="utf-8"))
            self.assertTrue(report["all_passed"])
            self.assertEqual(len(report["runs"]), 1)

    def test_non_finite_values_are_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            path = pathlib.Path(temp) / "run.json"
            path.write_text(json.dumps(PASSING | {"queue_p99_ms": float("nan")}), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "non-finite value"):
                load_run(path, expected_duration=60)

    def test_missing_measured_metric_is_rejected_instead_of_defaulting_to_zero(self):
        with tempfile.TemporaryDirectory() as temp:
            path = pathlib.Path(temp) / "run.json"
            incomplete = dict(PASSING)
            del incomplete["queue_p99_ms"]
            path.write_text(json.dumps(incomplete), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "missing manifest keys: queue_p99_ms"):
                load_run(path, expected_duration=60)

    def test_null_measured_metric_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            path = pathlib.Path(temp) / "run.json"
            path.write_text(json.dumps(PASSING | {"queue_p99_ms": None}), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "metric must be a finite number: queue_p99_ms"):
                load_run(path, expected_duration=60)


class TopologyTests(unittest.TestCase):
    def test_local_manifest_preserves_hop_order_ports_and_20013(self):
        topology = json.loads((HERE / "manifests" / "local-netns.json").read_text(encoding="utf-8"))
        validate_topology(topology)
        self.assertEqual(topology["hop_order"], ["client", "slave", "sidecar"])
        self.assertIn(20013, topology["reserved_ports"])
        self.assertTrue(all(7000 <= leg["ingress_port"] <= 9000 for leg in topology["legs"]))
        self.assertNotIn(20013, {leg["ingress_port"] for leg in topology["legs"]})

    def test_synthetic_cli_writes_non_formal_verdict(self):
        with tempfile.TemporaryDirectory() as temp:
            output = pathlib.Path(temp)
            status = main(
                [
                    "synthetic",
                    "--manifest",
                    str(HERE / "manifests" / "local-netns.json"),
                    "--output-dir",
                    str(output),
                    "--duration-seconds",
                    "60",
                    "--seed",
                    "1234",
                ]
            )
            self.assertEqual(status, 0)
            verdict = json.loads((output / "verdict.json").read_text(encoding="utf-8"))
            self.assertTrue(verdict["all_passed"])
            self.assertFalse(verdict["formal_eligible"])
            self.assertEqual(verdict["duration_seconds"], 60)
            self.assertEqual(verdict["seed"], 1234)

    def test_formal_cli_writes_paired_verdict(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            feedback_path = root / "feedback.json"
            legacy_path = root / "legacy.json"
            feedback_path.write_text(json.dumps(PASSING), encoding="utf-8")
            legacy = PASSING | {
                "mode": "legacy",
                "useful_rate_bps": 271_000_000,
                "receiver_loss_ratio": 0.004,
                "loaded_rtt_p95_ms": 29.0,
                "loaded_rtt_p99_ms": 35.0,
                "inner_tcp_retransmissions": 11,
                "feedback_capable_active_legs": 0,
                "legacy_fallback_active_legs": 5,
            }
            legacy_path.write_text(json.dumps(legacy), encoding="utf-8")

            status = main(
                [
                    "evaluate",
                    "--manifest",
                    str(feedback_path),
                    "--legacy",
                    str(legacy_path),
                    "--output-dir",
                    str(root),
                    "--duration-seconds",
                    "60",
                ]
            )

            self.assertEqual(status, 0)
            verdict = json.loads((root / "verdict.json").read_text(encoding="utf-8"))
            self.assertTrue(verdict["all_passed"])
            self.assertTrue(verdict["formal_eligible"])

    def test_synthetic_same_seed_is_byte_for_byte_deterministic(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            first = root / "first"
            second = root / "second"
            manifest = HERE / "manifests" / "local-netns.json"
            self.assertEqual(
                main(
                    [
                        "synthetic",
                        "--manifest",
                        str(manifest),
                        "--output-dir",
                        str(first),
                        "--seed",
                        "77",
                    ]
                ),
                0,
            )
            self.assertEqual(
                main(
                    [
                        "synthetic",
                        "--manifest",
                        str(manifest),
                        "--output-dir",
                        str(second),
                        "--seed",
                        "77",
                    ]
                ),
                0,
            )
            self.assertEqual(
                (first / "verdict.json").read_bytes(),
                (second / "verdict.json").read_bytes(),
            )


if __name__ == "__main__":
    unittest.main()
