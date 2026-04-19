import importlib.util
import pathlib
import sys
import tempfile
import time
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


merge_runtime = load_module("udp_uot_merge_under_test", "sidecar-agent/leikwan_udp_uot_merge.py")


class UdpUotMergeRuntimeTests(unittest.TestCase):
    def test_verify_session_token_recovers_forward_target(self) -> None:
        token = merge_runtime.build_test_token(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
                "expires_at": int(time.time()) + 60,
            },
            "secret-123",
        )
        claims = merge_runtime.verify_session_token(token, "secret-123", int(time.time()))
        self.assertEqual(claims["target_host"], "193.227.109.82")
        self.assertEqual(claims["target_port"], 5201)
        self.assertEqual(claims["forward_id"], 26)

    def test_merge_buffer_releases_in_order_and_drops_duplicates(self) -> None:
        buffer = merge_runtime.MergeBuffer(packet_limit=8, byte_limit=4096, timeout_ms=25)
        self.assertEqual(buffer.push(9, 2, b"two"), [])
        ready = buffer.push(9, 1, b"one")
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in ready], [(9, 1, b"one"), (9, 2, b"two")])
        duplicate = buffer.push(9, 1, b"dup")
        self.assertEqual(duplicate, [])


if __name__ == "__main__":
    unittest.main()
