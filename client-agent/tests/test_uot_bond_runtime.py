import asyncio
import importlib.util
import pathlib
import struct
import sys
import tempfile
import json
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
    def test_feedback_scheduler_requires_native_runtime_before_socket_setup(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / "runtime.json"
            config_path.write_text(
                json.dumps({"scheduler_mode": "feedback_drr"}),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                RuntimeError,
                "feedback_drr requires the native Rust UoT runtime",
            ):
                uot_bond.load_config(str(config_path))

    def test_build_leg_auth_payload_encodes_token_endpoint_and_weight(self) -> None:
        leg = uot_bond.LegConfig(
            endpoint_id=184,
            host="8.163.90.132",
            port=7001,
            auth_token="token-1",
            weight=8,
        )
        payload = uot_bond.build_leg_auth_payload(leg, "udp-token")
        decoded = uot_bond.json.loads(payload.decode("utf-8"))
        self.assertEqual(decoded["token"], "udp-token")
        self.assertEqual(decoded["endpoint_id"], 184)
        self.assertEqual(decoded["weight"], 8)

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
                writer.write(b"OK\n")
                await writer.drain()
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

    def test_open_plain_tcp_leg_consumes_mux_ok_before_reading_binary_frames(self) -> None:
        async def scenario() -> None:
            seen = {}

            async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
                seen["line"] = (await reader.readline()).decode("utf-8").strip()
                frame = uot_bond.DataFrame(
                    version=1,
                    msg_type=uot_bond.MSG_DATA,
                    flags=0,
                    session_id=7,
                    seq=1,
                    payload=b"payload-from-sidecar",
                )
                encoded = uot_bond.encode_frame(frame)
                writer.write(b"OK\n")
                writer.write(struct.pack(">I", len(encoded)))
                writer.write(encoded)
                await writer.drain()
                writer.close()
                await writer.wait_closed()

            server = await asyncio.start_server(handler, "127.0.0.1", 0)
            port = server.sockets[0].getsockname()[1]
            try:
                reader, writer = await uot_bond.open_plain_tcp_leg("127.0.0.1", port, "rk.uot", "token-123")
                header = await reader.readexactly(4)
                frame_len = struct.unpack(">I", header)[0]
                payload = await reader.readexactly(frame_len)
                writer.close()
                await writer.wait_closed()
            finally:
                server.close()
                await server.wait_closed()

            self.assertEqual(seen["line"], "LEIKWAN-INGRESS 1 OPEN rk.uot token-123")
            decoded = uot_bond.decode_frame(payload)
            self.assertEqual(decoded.msg_type, uot_bond.MSG_DATA)
            self.assertEqual(decoded.payload, b"payload-from-sidecar")

        asyncio.run(scenario())

    def test_decode_frame_rejects_trailing_bytes(self) -> None:
        frame = uot_bond.DataFrame(
            version=1,
            msg_type=uot_bond.MSG_DATA,
            flags=0,
            session_id=5,
            seq=9,
            payload=b"abc",
        )
        encoded = uot_bond.encode_frame(frame)
        with self.assertRaises(ValueError):
            uot_bond.decode_frame(encoded + b"trailing")

    def test_weighted_round_robin_honors_weight_and_skips_inactive(self) -> None:
        selector = uot_bond.WeightedLegSelector(
            [
                uot_bond.LegState(endpoint_id=184, weight=2, active=True),
                uot_bond.LegState(endpoint_id=185, weight=1, active=False),
                uot_bond.LegState(endpoint_id=186, weight=1, active=True),
            ]
        )
        picks = [selector.pick_leg().endpoint_id for _ in range(6)]
        self.assertEqual(picks, [184, 184, 186, 184, 184, 186])

    def test_penalize_leg_quarantines_after_three_failures(self) -> None:
        config = uot_bond.RuntimeConfig(
            forward_id=26,
            listen_udp="127.0.0.1:26026",
            route_key="rk",
            session_token="st",
            reorder_window_packets=16,
            reorder_window_bytes=4096,
            reorder_timeout_ms=10,
            legs=[uot_bond.LegConfig(endpoint_id=184, host="8.163.90.132", port=7001, auth_token="t1", weight=8)],
            stats_path="NUL",
        )
        runtime = uot_bond.Runtime(config)
        leg = runtime.leg_states[0]
        runtime.penalize_leg(leg, now_ms=1000)
        self.assertGreaterEqual(leg.weight, 1)
        runtime.penalize_leg(leg, now_ms=1100)
        self.assertGreaterEqual(leg.suppress_until_ms, 1100)
        runtime.penalize_leg(leg, now_ms=1200)
        self.assertEqual(leg.weight, 0)
        self.assertGreater(leg.suppress_until_ms, 1200)

    def test_mark_leg_ready_restores_weight_and_marks_active(self) -> None:
        config = uot_bond.RuntimeConfig(
            forward_id=26,
            listen_udp="127.0.0.1:26026",
            route_key="rk",
            session_token="st",
            reorder_window_packets=16,
            reorder_window_bytes=4096,
            reorder_timeout_ms=10,
            legs=[uot_bond.LegConfig(endpoint_id=184, host="8.163.90.132", port=7001, auth_token="t1", weight=8)],
            stats_path="NUL",
        )
        runtime = uot_bond.Runtime(config)
        leg = runtime.leg_states[0]
        runtime.penalize_leg(leg, now_ms=1000)
        self.assertFalse(leg.active)
        self.assertLess(leg.weight, leg.base_weight)
        runtime.mark_leg_ready(leg)
        self.assertTrue(leg.active)
        self.assertEqual(leg.weight, leg.base_weight)

    def test_merge_buffer_packet_limit_evicts_newest_buffered_packet(self) -> None:
        buffer = uot_bond.MergeBuffer(packet_limit=2, byte_limit=0, timeout_ms=1)
        self.assertEqual(buffer.push(8, 3, b"three"), [])
        self.assertEqual(buffer.push(8, 4, b"four"), [])
        self.assertEqual(buffer.push(8, 5, b"five"), [])
        self.assertEqual(sorted(buffer.session_state[8].buffered.keys()), [3, 4])

    def test_merge_buffer_byte_limit_evicts_newest_buffered_packet(self) -> None:
        buffer = uot_bond.MergeBuffer(packet_limit=0, byte_limit=7, timeout_ms=1)
        self.assertEqual(buffer.push(9, 2, b"1111"), [])
        self.assertEqual(buffer.push(9, 3, b"2222"), [])
        self.assertEqual(sorted(buffer.session_state[9].buffered.keys()), [2])

    def test_emit_udp_drops_unknown_session_without_last_peer_fallback(self) -> None:
        config = uot_bond.RuntimeConfig(
            forward_id=26,
            listen_udp="127.0.0.1:26026",
            route_key="rk",
            session_token="st",
            reorder_window_packets=16,
            reorder_window_bytes=4096,
            reorder_timeout_ms=10,
            legs=[],
            stats_path="NUL",
        )
        runtime = uot_bond.Runtime(config)

        class FakeTransport:
            def __init__(self) -> None:
                self.sent: list[tuple[bytes, tuple[str, int]]] = []

            def sendto(self, data: bytes, addr: tuple[str, int]) -> None:
                self.sent.append((data, addr))

        fake_transport = FakeTransport()
        runtime.transport = fake_transport
        runtime.last_peer = ("127.0.0.1", 49000)
        runtime.emit_udp(b"payload", session_id=999)
        self.assertEqual(fake_transport.sent, [])

    def test_cleanup_idle_sessions_removes_expired_sessions(self) -> None:
        config = uot_bond.RuntimeConfig(
            forward_id=26,
            listen_udp="127.0.0.1:26026",
            route_key="rk",
            session_token="st",
            reorder_window_packets=16,
            reorder_window_bytes=4096,
            reorder_timeout_ms=10,
            legs=[],
            stats_path="NUL",
        )
        runtime = uot_bond.Runtime(config)
        sid = runtime.alloc_session(("127.0.0.1", 30000))
        runtime.session_last_seen_ms[sid] = 1000
        removed = runtime.cleanup_idle_sessions(now_ms=3000, idle_timeout_ms=1000)
        self.assertEqual(removed, 1)
        self.assertEqual(runtime.stats["sessions_active"], 0)
        self.assertEqual(runtime.session_to_peer, {})

    def test_leg_keepalive_loop_enqueues_keepalive_frames(self) -> None:
        async def scenario() -> None:
            config = uot_bond.RuntimeConfig(
                forward_id=26,
                listen_udp="127.0.0.1:26026",
                route_key="rk",
                session_token="st",
                reorder_window_packets=16,
                reorder_window_bytes=4096,
                reorder_timeout_ms=10,
                legs=[],
                stats_path="NUL",
            )
            runtime = uot_bond.Runtime(config)
            queue: asyncio.Queue[uot_bond.DataFrame] = asyncio.Queue(maxsize=8)
            task = asyncio.create_task(runtime.leg_keepalive_loop(queue, interval_seconds=0.01))
            try:
                frame = await asyncio.wait_for(queue.get(), timeout=0.2)
            finally:
                task.cancel()
                await asyncio.gather(task, return_exceptions=True)
            self.assertEqual(frame.msg_type, uot_bond.MSG_KEEPALIVE)
            self.assertEqual(frame.session_id, 0)
            self.assertEqual(frame.payload, b"")

        asyncio.run(scenario())
