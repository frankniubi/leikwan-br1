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
                    sync.synchronize({"config_path": str(path)})
                self.assertEqual({"healthy": False, "config_hash": digest}, request.call_args.args[2])


if __name__ == "__main__":
    unittest.main()
