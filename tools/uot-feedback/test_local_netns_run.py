#!/usr/bin/env python3
import base64
import hashlib
import hmac
import json
import pathlib
import sys
import unittest


HERE = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

from local_netns_run import build_evidence, session_token  # noqa: E402


class LocalNetnsEvidenceTests(unittest.TestCase):
    def test_session_token_is_signed_and_targets_udp(self):
        token = session_token("local-secret", "127.0.0.1", 9900, 3)
        payload_b64, signature_b64 = token.split(".", 1)
        payload = base64.urlsafe_b64decode(payload_b64 + "==")
        signature = base64.urlsafe_b64decode(signature_b64 + "==")
        self.assertTrue(
            hmac.compare_digest(
                signature,
                hmac.new(b"local-secret", payload, hashlib.sha256).digest(),
            )
        )
        claims = json.loads(payload)
        self.assertEqual(claims["target_protocol"], "udp")
        self.assertEqual(claims["target_port"], 9900)
        self.assertEqual(claims["forward_id"], 3)

    def test_build_evidence_uses_measured_loss_fairness_and_runtime_drops(self):
        sender = {
            "packets_sent": 1_000,
            "packet_bytes": 1_200,
            "send_errors": 2,
            "probe_rtt_ms": [1.0, 2.0, 3.0, 4.0] * 10,
        }
        receiver = {
            "unique_packets": 995,
            "window_unique_packets": 990,
            "per_session_packets": [498, 497],
            "per_second_useful_bytes": [198_000] * 60,
        }
        scheduler = {
            "scheduler_drop_ratio": 0.0005,
            "ingress_channel_full": 0,
            "scheduler_queue": {"p95_ms": 2, "p99_ms": 4},
            "feedback_capable_active_legs": 5,
            "legacy_fallback_active_legs": 0,
            "queue_bytes_high_water": 400_000,
            "drops": {"drop_queue_deadline": {"packets": 0}},
            "udp_errors": {},
        }
        client = {
            "live_legs": 5,
            "control_queue_drops": 0,
            "udp_egress_errors": 0,
            "scheduler": scheduler,
        }
        sidecar = {
            "live_legs": 5,
            "control_queue_drops": 0,
            "udp_egress_errors": 0,
            "scheduler": scheduler,
        }

        evidence = build_evidence(
            mode="feedback_drr",
            sender=sender,
            receiver=receiver,
            client_stats=client,
            sidecar_stats=sidecar,
            retransmissions=2,
            duration_seconds=60,
            offered_rate_bps=300_000_000,
            sessions=2,
            packet_bytes=1_200,
        )

        self.assertAlmostEqual(evidence["receiver_loss_ratio"], 0.005)
        self.assertGreater(evidence["jain_fairness"], 0.999)
        self.assertEqual(evidence["loaded_rtt_p95_ms"], 4.0)
        self.assertEqual(evidence["queue_p99_ms"], 4.0)
        self.assertEqual(evidence["feedback_capable_active_legs"], 5)
        self.assertEqual(evidence["ingress_channel_full"], 0)
        self.assertEqual(evidence["control_queue_drops"], 0)
        self.assertEqual(evidence["udp_send_errors"], 2)
        self.assertEqual(evidence["inner_tcp_retransmissions"], 2)


if __name__ == "__main__":
    unittest.main()
