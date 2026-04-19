import asyncio
import importlib.util
import pathlib
import struct
import sys
import tempfile
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


uot_bond = load_module("leikwan_uot_bond_under_test", "client-agent/leikwan_uot_bond.py")


class UotBondRuntimeTests(unittest.TestCase):
    def test_encode_and_decode_data_frame_round_trip(self) -> None:
        frame = uot_bond.DataFrame(
            version=1,
            msg_type=uot_bond.MSG_DATA,
            flags=0,
            session_id=7,
            seq=11,
            payload=b"hello",
        )
        encoded = uot_bond.encode_frame(frame)
        decoded = uot_bond.decode_frame(encoded)
        self.assertEqual(decoded.session_id, 7)
        self.assertEqual(decoded.seq, 11)
        self.assertEqual(decoded.payload, b"hello")

    def test_merge_buffer_releases_in_order_and_skips_gap_after_timeout(self) -> None:
        buffer = uot_bond.MergeBuffer(packet_limit=16, byte_limit=4096, timeout_ms=1)
        self.assertEqual(buffer.push(4, 2, b"two"), [])
        ready = buffer.push(4, 1, b"one")
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in ready], [(4, 1, b"one"), (4, 2, b"two")])
        self.assertEqual(buffer.push(4, 4, b"four"), [])
        buffer.session_state[4].gap_started_at -= 1
        skipped = buffer.flush_timeouts(now_ms=buffer.session_state[4].gap_started_at + 10)
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in skipped], [(4, 4, b"four")])

    def test_weighted_round_robin_cycles_live_legs(self) -> None:
        selector = uot_bond.WeightedLegSelector(
            [
                uot_bond.LegState(endpoint_id=184, weight=1),
                uot_bond.LegState(endpoint_id=185, weight=1),
                uot_bond.LegState(endpoint_id=186, weight=1),
            ]
        )
        picks = [selector.pick_leg().endpoint_id for _ in range(6)]
        self.assertEqual(picks, [184, 185, 186, 184, 185, 186])

    def test_plain_tcp_mux_open_line_is_written_before_binary_frames(self) -> None:
        async def scenario() -> None:
            seen = {}

            async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
                seen["line"] = (await reader.readline()).decode("utf-8").strip()
                header = await reader.readexactly(4)
                frame_len = struct.unpack(">I", header)[0]
                seen["first_frame"] = await reader.readexactly(frame_len)
                writer.close()
                await writer.wait_closed()

            server = await asyncio.start_server(handler, "127.0.0.1", 0)
            port = server.sockets[0].getsockname()[1]
            try:
                reader, writer = await uot_bond.open_plain_tcp_leg("127.0.0.1", port, "rk.uot", "token-123")
                await uot_bond.write_leg_frame(
                    writer,
                    uot_bond.DataFrame(
                        version=1,
                        msg_type=uot_bond.MSG_AUTH,
                        flags=0,
                        session_id=0,
                        seq=0,
                        payload=b"udp-token",
                    ),
                )
                writer.close()
                await writer.wait_closed()
            finally:
                server.close()
                await server.wait_closed()

            self.assertEqual(seen["line"], "LEIKWAN-INGRESS 1 OPEN rk.uot token-123")
            decoded = uot_bond.decode_frame(seen["first_frame"])
            self.assertEqual(decoded.msg_type, uot_bond.MSG_AUTH)
            self.assertEqual(decoded.payload, b"udp-token")

        asyncio.run(scenario())
