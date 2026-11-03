import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).parents[1] / "verify-macos-managed-load-receipt.py"
FILES = (
    "LeikwanBridge.Mac.dll",
    "LeikwanBridge.Core.dll",
    "LeikwanBridge.Mac.deps.json",
    "LeikwanBridge.Mac.runtimeconfig.json",
)


class ManagedLoadReceiptTests(unittest.TestCase):
    def make_fixture(self, root: Path) -> Path:
        publish = root / "publish"
        publish.mkdir()
        hashes = {}
        for name in FILES:
            path = publish / name
            path.write_bytes(("fixture:" + name).encode())
            hashes[name] = hashlib.sha256(path.read_bytes()).hexdigest()
        receipt = root / "receipt.json"
        receipt.write_text(json.dumps({
            "schema": 1,
            "rid": "osx-arm64",
            "version": "1.0.19",
            "probe_exit_code": 2,
            "files": hashes,
        }))
        return receipt

    def verify(self, root: Path, receipt: Path, *extra: str):
        return subprocess.run([
            sys.executable,
            str(SCRIPT),
            str(root / "publish"),
            str(receipt),
            "--rid", "osx-arm64",
            "--version", "1.0.19",
            *extra,
        ], capture_output=True, text=True)

    def test_accepts_hash_bound_success_receipt(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            receipt = self.make_fixture(root)
            result = self.verify(root, receipt)
            self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_changed_publish_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            receipt = self.make_fixture(root)
            (root / "publish/LeikwanBridge.Core.dll").write_bytes(b"changed")
            result = self.verify(root, receipt)
            self.assertNotEqual(0, result.returncode)
            self.assertIn("hash mismatch", result.stderr)

    def test_rejects_wrong_probe_result(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            receipt = self.make_fixture(root)
            payload = json.loads(receipt.read_text())
            payload["probe_exit_code"] = 134
            receipt.write_text(json.dumps(payload))
            result = self.verify(root, receipt)
            self.assertNotEqual(0, result.returncode)
            self.assertIn("probe exit", result.stderr)


if __name__ == "__main__":
    unittest.main()
