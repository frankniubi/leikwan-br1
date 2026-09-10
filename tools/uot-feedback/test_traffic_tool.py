#!/usr/bin/env python3
import json
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import unittest


HERE = pathlib.Path(__file__).resolve().parent
SOURCE = HERE / "traffic.rs"
RUSTC = shutil.which("rustc") or "/root/.cargo/bin/rustc"


class TrafficToolTests(unittest.TestCase):
    def test_loopback_load_reports_delivery_and_probe_rtt(self):
        self.assertTrue(SOURCE.is_file(), f"missing traffic source: {SOURCE}")
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            binary = root / "uot-traffic"
            compile_result = subprocess.run(
                [RUSTC, "--edition", "2021", "-O", str(SOURCE), "-o", str(binary)],
                capture_output=True,
                text=True,
            )
            self.assertEqual(compile_result.returncode, 0, compile_result.stderr)

            with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as probe:
                probe.bind(("127.0.0.1", 0))
                port = probe.getsockname()[1]

            receiver_output = root / "receiver.json"
            sender_output = root / "sender.json"
            receiver = subprocess.Popen(
                [
                    str(binary),
                    "receiver",
                    "--bind",
                    f"127.0.0.1:{port}",
                    "--duration-seconds",
                    "1",
                    "--grace-seconds",
                    "1",
                    "--sessions",
                    "4",
                    "--output",
                    str(receiver_output),
                ],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
            time.sleep(0.1)
            sender = subprocess.run(
                [
                    str(binary),
                    "sender",
                    "--target",
                    f"127.0.0.1:{port}",
                    "--duration-seconds",
                    "1",
                    "--sessions",
                    "4",
                    "--rate-bps",
                    "8000000",
                    "--packet-bytes",
                    "1200",
                    "--probe-every-ms",
                    "50",
                    "--output",
                    str(sender_output),
                ],
                capture_output=True,
                text=True,
                timeout=10,
            )
            receiver_stdout, receiver_stderr = receiver.communicate(timeout=10)
            self.assertEqual(sender.returncode, 0, sender.stderr)
            self.assertEqual(receiver.returncode, 0, receiver_stderr or receiver_stdout)

            sent = json.loads(sender_output.read_text(encoding="utf-8"))
            received = json.loads(receiver_output.read_text(encoding="utf-8"))
            self.assertEqual(sent["sessions"], 4)
            self.assertEqual(sent["packet_bytes"], 1200)
            self.assertGreater(sent["packets_sent"], 700)
            self.assertEqual(sent["send_errors"], 0)
            self.assertGreater(len(sent["probe_rtt_ms"]), 5)
            self.assertGreater(received["unique_packets"], sent["packets_sent"] * 0.95)
            self.assertEqual(received["echo_errors"], 0)
            self.assertEqual(len(received["per_session_packets"]), 4)


if __name__ == "__main__":
    unittest.main()
