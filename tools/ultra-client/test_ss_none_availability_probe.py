import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import unittest


MODULE_PATH = pathlib.Path(__file__).with_name("ss-none-availability-probe.py")
SPEC = importlib.util.spec_from_file_location("ss_none_availability_probe", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class ShadowsocksNoneAvailabilityProbeTests(unittest.TestCase):
    def test_none_tcp_frame_is_address_first_and_password_is_not_on_wire(self):
        frame = MODULE.build_tcp_request(
            "1.1.1.1", 80, b"GET /cdn-cgi/trace HTTP/1.1\r\nHost: one.one.one.one\r\n\r\n"
        )

        self.assertEqual(frame[:1], b"\x01")
        self.assertEqual(frame[1:5], b"\x01\x01\x01\x01")
        self.assertEqual(frame[5:7], b"\x00\x50")
        self.assertNotIn(b"laufrank", frame)

    def test_udp_frame_uses_local_socks_envelope_and_decodes_nested_response(self):
        query = MODULE.build_dns_query(0x1234)
        frame = MODULE.build_udp_request("1.1.1.1", 53, query)
        self.assertEqual(frame[:4], b"\x00\x00\x00\x01")
        self.assertEqual(frame[4:8], b"\x01\x01\x01\x01")
        self.assertEqual(frame[8:10], b"\x00\x35")

        response = b"\x00\x00\x00" + MODULE.encode_address("1.1.1.1", 53) + query[:2] + b"\x80\x00" + b"\x00" * 8
        payload = MODULE.decode_udp_response(response, 0x1234)
        self.assertTrue(payload.ok)
        self.assertEqual(payload.error_code, "")

    def test_window_rolls_and_reports_state_transition_without_secret(self):
        with tempfile.TemporaryDirectory() as directory:
            state_path = os.path.join(directory, "status.json")
            events_path = os.path.join(directory, "events.jsonl")
            monitor = MODULE.AvailabilityMonitor(
                host="127.0.0.1",
                port=10001,
                password="laufrank",
                method="none",
                interval_seconds=30,
                timeout_seconds=3,
                window_seconds=60,
                state_path=state_path,
                events_path=events_path,
                now=lambda: 100.0,
            )

            down = MODULE.ProbeResult(False, False, False, 0, 0, "tcp_connect_failed")
            monitor.record(down)
            state = json.loads(pathlib.Path(state_path).read_text())
            self.assertEqual(state["state"], "down")
            self.assertEqual(state["consecutive_failures"], 1)
            self.assertEqual(state["availability_percent"], 0.0)
            self.assertNotIn("laufrank", pathlib.Path(events_path).read_text())

            monitor.now = lambda: 101.0
            up = MODULE.ProbeResult(True, True, True, 12, 8, "")
            monitor.record(up)
            state = json.loads(pathlib.Path(state_path).read_text())
            self.assertEqual(state["state"], "up")
            self.assertEqual(state["consecutive_failures"], 0)
            self.assertEqual(state["availability_percent"], 50.0)
            self.assertEqual(state["tcp_success_count"], 1)
            self.assertEqual(state["udp_success_count"], 1)

            monitor.now = lambda: 200.0
            monitor.record(up)
            state = json.loads(pathlib.Path(state_path).read_text())
            self.assertEqual(state["sample_count"], 1)
            self.assertEqual(state["availability_percent"], 100.0)

    def test_config_requires_password_but_redacts_it_from_public_view(self):
        config = MODULE.load_config_dict(
            {
                "host": "127.0.0.1",
                "port": 10001,
                "password": "laufrank",
                "method": "none",
            }
        )
        self.assertEqual(config["password"], "laufrank")
        public = MODULE.public_config(config)
        self.assertTrue(public["credential_configured"])
        self.assertNotIn("password", public)


if __name__ == "__main__":
    unittest.main()
