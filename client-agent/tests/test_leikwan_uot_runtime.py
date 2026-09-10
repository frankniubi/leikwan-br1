import asyncio
import importlib.util
import pathlib
import socket
import sys
import unittest
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


leikwan_uot = load_module("leikwan_uot_runtime_under_test", "client-agent/leikwan_uot.py")


class LeikwanUotRuntimeTests(unittest.TestCase):
    def test_udp_capture_drops_datagram_when_queue_is_full_without_crashing(self) -> None:
        queue: asyncio.Queue[bytes] = asyncio.Queue(maxsize=1)
        queue.put_nowait(b"first")
        last_peer: list[tuple[str, int] | None] = [None]
        capture = leikwan_uot.UdpCapture(queue, last_peer)

        capture.datagram_received(b"second", ("127.0.0.1", 53000))

        self.assertEqual(1, queue.qsize())
        self.assertEqual(b"first", queue.get_nowait())
        self.assertIsNone(last_peer[0])

    def test_runtime_queues_are_bounded_to_prevent_uot_memory_growth(self) -> None:
        source = (REPO_ROOT / "client-agent" / "leikwan_uot.py").read_text(encoding="utf-8")

        self.assertIn("MAX_QUEUE_DATAGRAMS", source)
        self.assertIn("asyncio.Queue(maxsize=MAX_QUEUE_DATAGRAMS)", source)
        self.assertLessEqual(leikwan_uot.MAX_QUEUE_DATAGRAMS, 512)

    def test_server_enables_reuse_port_for_parallel_uot_and_wg_tcp_fallback(self) -> None:
        async def fake_start_server(handler, host=None, port=None, reuse_port=None):
            class FakeServer:
                async def __aenter__(self):
                    return self

                async def __aexit__(self, exc_type, exc, tb):
                    return False

                async def serve_forever(self):
                    return None

            self.assertTrue(reuse_port)
            return FakeServer()

        with mock.patch.object(leikwan_uot.os, "name", "posix"):
            with mock.patch.object(socket, "SO_REUSEPORT", 15, create=True):
                with mock.patch.object(leikwan_uot.asyncio, "start_server", side_effect=fake_start_server):
                    asyncio.run(leikwan_uot.run_server(("0.0.0.0", 7329), ("127.0.0.1", 7329)))


if __name__ == "__main__":
    unittest.main()
