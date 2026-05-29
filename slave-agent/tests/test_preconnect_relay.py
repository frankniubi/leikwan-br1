import asyncio
import importlib.util
import json
import pathlib
import struct
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "slave-agent" / "preconnect_relay.py"
spec = importlib.util.spec_from_file_location("preconnect_relay", MODULE_PATH)
preconnect_relay = importlib.util.module_from_spec(spec)
assert spec is not None and spec.loader is not None
spec.loader.exec_module(preconnect_relay)


class MemoryWriter:
    def __init__(self) -> None:
        self.data = bytearray()

    def write(self, payload: bytes) -> None:
        self.data.extend(payload)

    async def drain(self) -> None:
        return None

    def can_write_eof(self) -> bool:
        return False

    def get_extra_info(self, _name: str):
        return None


async def reader_with(payload: bytes) -> asyncio.StreamReader:
    reader = asyncio.StreamReader()
    reader.feed_data(payload)
    reader.feed_eof()
    return reader


class PreConnectRelayHandshakeTests(unittest.TestCase):
    def test_bond_client_handshake_is_forwarded_unchanged(self) -> None:
        handshake = {
            "protocol": "tcp",
            "session_token": "client-token",
            "forward_id": 64,
            "bond_session_id": 1234,
            "leg_priority": 10,
            "leg_direct": False,
        }
        body = json.dumps(handshake, separators=(",", ":")).encode("utf-8")
        frame = struct.pack(">I", len(body)) + body

        async def run_case():
            reader = await reader_with(frame + b"payload")
            writer = MemoryWriter()
            initial = await preconnect_relay.maybe_forward_client_handshake(
                reader,
                writer,
                {"forward_id": 64, "tcp_session_token": "relay-token"},
            )
            remaining = await reader.read()
            return initial, bytes(writer.data), remaining

        initial, sent, remaining = asyncio.run(run_case())

        self.assertEqual(b"", initial)
        self.assertEqual(frame, sent)
        self.assertEqual(b"payload", remaining)

    def test_raw_client_payload_gets_relay_handshake_and_prefix_preserved(self) -> None:
        raw_prefix = b"GET "

        async def run_case():
            reader = await reader_with(raw_prefix + b"/ HTTP/1.1\r\n")
            writer = MemoryWriter()
            initial = await preconnect_relay.maybe_forward_client_handshake(
                reader,
                writer,
                {"forward_id": 64, "tcp_session_token": "relay-token"},
            )
            return initial, bytes(writer.data)

        initial, sent = asyncio.run(run_case())

        self.assertEqual(raw_prefix, initial)
        handshake_len = struct.unpack(">I", sent[:4])[0]
        handshake = json.loads(sent[4:4 + handshake_len].decode("utf-8"))
        self.assertEqual("tcp", handshake["protocol"])
        self.assertEqual("relay-token", handshake["session_token"])
        self.assertEqual(64, handshake["forward_id"])

    def test_short_raw_client_payload_is_not_dropped(self) -> None:
        async def run_case():
            reader = await reader_with(b"OK")
            writer = MemoryWriter()
            initial = await preconnect_relay.maybe_forward_client_handshake(
                reader,
                writer,
                {"forward_id": 64, "tcp_session_token": "relay-token"},
            )
            return initial, bytes(writer.data)

        initial, sent = asyncio.run(run_case())

        self.assertEqual(b"OK", initial)
        handshake_len = struct.unpack(">I", sent[:4])[0]
        handshake = json.loads(sent[4:4 + handshake_len].decode("utf-8"))
        self.assertEqual("relay-token", handshake["session_token"])


if __name__ == "__main__":
    unittest.main()
