import importlib.util
import json
from pathlib import Path
import socket
import struct
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    "uot_pool_sync", Path(__file__).resolve().parents[1] / "sidecar-agent/uot_pool_sync.py")
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


class PoolSyncTests(unittest.TestCase):
    def test_frame_round_trip(self):
        left, right = socket.socketpair()
        with left, right:
            left.sendall(sync.frame(3, b"x" * 16, b"payload"))
            self.assertEqual((3, b"x" * 16, b"payload"), sync.receive(right))

    def test_invalid_frame_rejected(self):
        left, right = socket.socketpair()
        with left, right:
            left.sendall(struct.pack("!I", 1048577))
            with self.assertRaises(ValueError):
                sync.receive(right)

    def test_empty_contract_rejected(self):
        with self.assertRaises(ValueError):
            sync.validate_config({"listen_host": "0.0.0.0", "listen_port": 7443, "forwards": {}})

    @unittest.skipUnless(
        sys.platform.startswith("linux"),
        "atomic_write uses os.fchmod (Linux sidecar only; absent on Windows before Python 3.13)",
    )
    def test_atomic_write(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "config"
            sync.atomic_write(path, b"old")
            sync.atomic_write(path, b"new")
            self.assertEqual(b"new", path.read_bytes())
            self.assertEqual([path], list(Path(directory).iterdir()))

    def test_panel_failure_does_not_restart_or_change_config(self):
        with patch.object(sync, "request", side_effect=TimeoutError), patch.object(sync.subprocess, "run") as run:
            with self.assertRaises(TimeoutError):
                sync.synchronize({})
            run.assert_not_called()

    def test_failed_probe_reports_unhealthy(self):
        config = {"listen_host": "0.0.0.0", "listen_port": 7443,
                  "forwards": {"170": {"token": "test", "targets": []}}}
        encoded = json.dumps(config, separators=(",", ":")).encode()
        digest = sync.hashlib.sha256(encoded).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "config"
            path.write_bytes(encoded)
            with patch.object(sync, "request", side_effect=[{"config": config, "config_hash": digest}, {"ok": True}]) as request, patch.object(sync, "probe", side_effect=TimeoutError):
                with self.assertRaises(TimeoutError):
                    sync.synchronize({"config_path": str(path),
                                      "usage_path": str(Path(directory) / "missing.json")})
                self.assertEqual({"healthy": False, "config_hash": digest, "usage": None},
                                 request.call_args.args[2])

    def heartbeat_usage(self, usage_bytes):
        config = {"listen_host": "0.0.0.0", "listen_port": 7443,
                  "forwards": {"170": {"secret": SECRET, "targets": ["1.1.1.1:53"]}}}
        encoded = json.dumps(config, separators=(",", ":")).encode()
        digest = sync.hashlib.sha256(encoded).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "config"
            path.write_bytes(encoded)
            usage = Path(directory) / "usage.json"
            if usage_bytes is not None:
                usage.write_bytes(usage_bytes)
            with patch.object(sync, "request", side_effect=[{"config": config, "config_hash": digest}, {"ok": True}]) as request, patch.object(sync, "probe"):
                sync.synchronize({"config_path": str(path), "usage_path": str(usage)})
        body = request.call_args.args[2]
        self.assertEqual("heartbeat", request.call_args.args[1])
        self.assertEqual((True, digest), (body["healthy"], body["config_hash"]))
        return body["usage"]

    def test_heartbeat_carries_usage_document(self):
        document = {"version": 1, "instance": "a" * 32, "started_at": "2026-10-21T00:00:00Z",
                    "written_at": "2026-10-21T00:00:10Z",
                    "forwards": {"170": {"to_target_bytes": 10, "from_target_bytes": 20,
                                         "to_target_packets": 1, "from_target_packets": 2,
                                         "rate_limited_drops": 0}}}
        self.assertEqual(document, self.heartbeat_usage(json.dumps(document).encode()))

    def test_heartbeat_usage_is_null_when_absent_or_unreadable(self):
        for raw in (None, b"", b"{not json", b"[1, 2]", b"\xff\xfe"):
            with self.subTest(raw=raw):
                self.assertIsNone(self.heartbeat_usage(raw))

    def test_hmac_probe_token_matches_go_print_token(self):
        # Expected values from tools/uot-sidecar built with go1.26:
        #   uot-sidecar --print-token --secret SECRET --forward-id ID --target TARGET
        for forward, target, token in GO_TOKENS:
            with self.subTest(target=target):
                self.assertEqual(token, sync.hmac_token(SECRET, forward, target))

    def test_normalize_target_matches_go(self):
        for value, expected in (
            ("Example.COM:443", "example.com:443"),
            (" [2001:DB8::1]:8443 ", "2001:db8::1:8443"),
            ("[::FFFF:1.2.3.4]:53", "::ffff:1.2.3.4:53"),
            ("2001:db8:0:0:1:0:0:1:53", "2001:db8::1:0:0:1:53"),
            ("[fe80::1%ETH0]:53", "fe80::1%eth0:53"),
            ("host:+080", "host:80"),
            ("01.2.3.4:80", "01.2.3.4:80"),
        ):
            with self.subTest(value=value):
                self.assertEqual(expected, sync.normalize_target(value))
        for value in ("host:", ":80", "[::1]80", "a b:80", "host:65536", "host:1_0", "nocolon"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                sync.normalize_target(value)

    def test_probe_uses_static_token_else_hmac_for_probe_target(self):
        self.assertEqual("static", sync.probe_token("170", {"token": "static", "secret": SECRET}))
        self.assertEqual(GO_TOKENS[0][2], sync.probe_token("170", {"secret": SECRET}))
        self.assertEqual(GO_TOKENS[0][2], sync.probe_token("170", {"token": "", "secret": SECRET}))

    def test_secret_only_binding_is_valid_and_credentialless_is_not(self):
        base = {"listen_host": "0.0.0.0", "listen_port": 7443}
        sync.validate_config(dict(base, forwards={"170": {"secret": SECRET, "targets": []}}))
        for binding in ({"targets": []}, {"token": "", "secret": ""}, {"secret": 5}):
            with self.subTest(binding=binding), self.assertRaises(ValueError):
                sync.validate_config(dict(base, forwards={"170": binding}))

    def test_probe_hello_carries_hmac_token(self):
        hellos = []

        class Carrier:
            def __init__(self):
                self.replies = []

            def __enter__(self):
                return self

            def __exit__(self, *args):
                return False

            def settimeout(self, value):
                pass

            def sendall(self, data):
                kind, session, body = sync.receive(Feed(data))
                if kind == 1:
                    hellos.append(json.loads(body))
                    self.replies.append(sync.frame(2, session, b'{"ok":true}'))
                elif kind == 3:
                    answer = body[:2] + b"\x81\x80" + body[4:]
                    self.replies.append(sync.frame(4, session, answer))

            def recv(self, count):
                data, self.replies[0] = self.replies[0][:count], self.replies[0][count:]
                if not self.replies[0]:
                    self.replies.pop(0)
                return data

        config = {"listen_port": 7443, "forwards": {"170": {"secret": SECRET, "targets": []}}}
        with patch.object(sync.socket, "create_connection", side_effect=lambda *a: Carrier()):
            sync.probe(config)
        self.assertEqual([{"token": GO_TOKENS[0][2], "forward_id": 170,
                           "target": "1.1.1.1:53", "frame_payload_max": 1200}], hellos)


class Feed:
    def __init__(self, data):
        self.data = data

    def recv(self, count):
        part, self.data = self.data[:count], self.data[count:]
        return part


SECRET = "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"
GO_TOKENS = (
    (170, "1.1.1.1:53", "hmac-sha256:b427ebc10a1ab670dd3f0fb14aabbb6d2c9f79aa208f24d05f69a454b240a325"),
    (158, "Example.COM:443", "hmac-sha256:c92b2e137f62cae4505a09514caf470b9e66332e5f55dc9ed331b6f4cce2c9af"),
    (192, " [2001:DB8::1]:8443 ", "hmac-sha256:6bec27f04e17e1436ba665453707b00e49ec46bc92539d5a05b814e950cc5e1c"),
    (278, "[::FFFF:1.2.3.4]:53", "hmac-sha256:0372104811f5250b6fc2b076ba5df3d05e967393e04d9d9e23bdbf41a2a71115"),
    (300, "2001:db8:0:0:1:0:0:1:53", "hmac-sha256:c3bf31d37b322af3ede5f03342e2608e1b4c9bf71126f14fc7653421aee648f1"),
)


if __name__ == "__main__":
    unittest.main()
