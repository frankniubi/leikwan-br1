import asyncio
import importlib.util
import pathlib
import sys
import time
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

    def test_verify_session_token_rejects_invalid_signature(self) -> None:
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
        with self.assertRaises(RuntimeError):
            merge_runtime.verify_session_token(token, "wrong-secret", int(time.time()))

    def test_verify_session_token_rejects_expired_token(self) -> None:
        token = merge_runtime.build_test_token(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
                "expires_at": int(time.time()) - 1,
            },
            "secret-123",
        )
        with self.assertRaises(RuntimeError):
            merge_runtime.verify_session_token(token, "secret-123", int(time.time()))

    def test_merge_buffer_releases_in_order_and_drops_duplicates(self) -> None:
        buffer = merge_runtime.MergeBuffer(packet_limit=8, byte_limit=4096, timeout_ms=25)
        self.assertEqual(buffer.push(9, 2, b"two"), [])
        ready = buffer.push(9, 1, b"one")
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in ready], [(9, 1, b"one"), (9, 2, b"two")])
        duplicate = buffer.push(9, 1, b"dup")
        self.assertEqual(duplicate, [])

    def test_merge_buffer_releases_gap_after_timeout(self) -> None:
        buffer = merge_runtime.MergeBuffer(packet_limit=8, byte_limit=4096, timeout_ms=5)
        self.assertEqual(buffer.push(9, 3, b"three"), [])
        state = buffer.session_state[9]
        self.assertIsNotNone(state.gap_started_at)
        released = buffer.flush_timeouts(now_ms=state.gap_started_at + 10)
        self.assertEqual([(item.session_id, item.seq, item.payload) for item in released], [(9, 3, b"three")])

    def test_runtime_scopes_leg_selection_by_authenticated_route(self) -> None:
        config = merge_runtime.RuntimeConfig(
            listen_host="127.0.0.1",
            listen_port=8999,
            token_secret="secret-123",
            allowed_forward_ids=[26, 27],
            reorder_window_packets=8,
            reorder_window_bytes=4096,
            reorder_timeout_ms=25,
            stats_path="NUL",
        )
        runtime = merge_runtime.Runtime(config)
        scope_a = merge_runtime.build_scope_key(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
            }
        )
        scope_b = merge_runtime.build_scope_key(
            {
                "target_host": "203.0.113.18",
                "target_port": 5301,
                "target_protocol": "udp",
                "forward_id": 27,
            }
        )
        runtime._register_leg(
            merge_runtime.LegState(leg_id=1, scope_key=scope_a, writer=mock.Mock(), claims={"forward_id": 26})
        )
        runtime._register_leg(
            merge_runtime.LegState(leg_id=2, scope_key=scope_b, writer=mock.Mock(), claims={"forward_id": 27})
        )

        self.assertEqual(runtime.pick_leg(scope_a).leg_id, 1)
        self.assertEqual(runtime.pick_leg(scope_b).leg_id, 2)
        self.assertIsNone(runtime.pick_leg(("missing",)))

    def test_runtime_session_keys_include_scope_and_session_id(self) -> None:
        scope_a = merge_runtime.build_scope_key(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
            }
        )
        scope_b = merge_runtime.build_scope_key(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 27,
            }
        )
        self.assertNotEqual(
            merge_runtime.build_session_key(scope_a, 9),
            merge_runtime.build_session_key(scope_b, 9),
        )

    def test_runtime_scope_uses_token_fingerprint_for_identical_route_claims(self) -> None:
        claims = {
            "target_host": "193.227.109.82",
            "target_port": 5201,
            "target_protocol": "udp",
            "forward_id": 26,
        }
        token_a = merge_runtime.build_test_token(
            {
                **claims,
                "expires_at": int(time.time()) + 60,
                "nonce": "token-a",
            },
            "secret-123",
        )
        token_b = merge_runtime.build_test_token(
            {
                **claims,
                "expires_at": int(time.time()) + 60,
                "nonce": "token-b",
            },
            "secret-123",
        )
        scope_a = merge_runtime.build_scope_key(claims, token_a)
        scope_b = merge_runtime.build_scope_key(claims, token_b)
        self.assertNotEqual(scope_a, scope_b)
        self.assertNotEqual(
            merge_runtime.build_session_key(scope_a, 9),
            merge_runtime.build_session_key(scope_b, 9),
        )

        config = merge_runtime.RuntimeConfig(
            listen_host="127.0.0.1",
            listen_port=8999,
            token_secret="secret-123",
            allowed_forward_ids=[26],
            reorder_window_packets=8,
            reorder_window_bytes=4096,
            reorder_timeout_ms=25,
            stats_path="NUL",
        )
        runtime = merge_runtime.Runtime(config)
        runtime._register_leg(
            merge_runtime.LegState(leg_id=1, scope_key=scope_a, writer=mock.Mock(), claims=dict(claims))
        )
        runtime._register_leg(
            merge_runtime.LegState(leg_id=2, scope_key=scope_b, writer=mock.Mock(), claims=dict(claims))
        )

        self.assertEqual(runtime.pick_leg(scope_a).leg_id, 1)
        self.assertEqual(runtime.pick_leg(scope_a).leg_id, 1)
        self.assertEqual(runtime.pick_leg(scope_b).leg_id, 2)
        self.assertEqual(runtime.pick_leg(scope_b).leg_id, 2)

    def test_reverse_egress_uses_token_scoped_session_context(self) -> None:
        class FakeSocket:
            def __init__(self, name: str) -> None:
                self.name = name
                self.closed = False

            def setblocking(self, _blocking: bool) -> None:
                return None

            def close(self) -> None:
                self.closed = True

        class CompletedTask:
            def cancel(self) -> None:
                return None

            def __await__(self):  # noqa: ANN204
                if False:
                    yield None
                return None

        class FakeLoop:
            def __init__(self) -> None:
                self.sock_sent: list[tuple[str, bytes]] = []
                self.recv_payloads: dict[FakeSocket, list[object]] = {}

            async def getaddrinfo(self, host: str, port: int, type: int):  # noqa: A002
                return [(merge_runtime.socket.AF_INET, type, 0, "", (host, port))]

            async def sock_connect(self, _sock: FakeSocket, _sockaddr: tuple[str, int]) -> None:
                return None

            async def sock_sendall(self, sock: FakeSocket, payload: bytes) -> None:
                self.sock_sent.append((sock.name, payload))

            async def sock_recv(self, sock: FakeSocket, _size: int) -> bytes:
                next_item = self.recv_payloads[sock].pop(0)
                if isinstance(next_item, Exception):
                    raise next_item
                return next_item

        async def scenario() -> None:
            claims = {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
            }
            token_a = merge_runtime.build_test_token(
                {
                    **claims,
                    "expires_at": int(time.time()) + 60,
                    "nonce": "token-a",
                },
                "secret-123",
            )
            token_b = merge_runtime.build_test_token(
                {
                    **claims,
                    "expires_at": int(time.time()) + 60,
                    "nonce": "token-b",
                },
                "secret-123",
            )
            scope_a = merge_runtime.build_scope_key(claims, token_a)
            scope_b = merge_runtime.build_scope_key(claims, token_b)
            session_key_a = merge_runtime.build_session_key(scope_a, 9)
            session_key_b = merge_runtime.build_session_key(scope_b, 9)

            config = merge_runtime.RuntimeConfig(
                listen_host="127.0.0.1",
                listen_port=8999,
                token_secret="secret-123",
                allowed_forward_ids=[26],
                reorder_window_packets=8,
                reorder_window_bytes=4096,
                reorder_timeout_ms=25,
                stats_path="NUL",
            )
            runtime = merge_runtime.Runtime(config)
            runtime._register_leg(
                merge_runtime.LegState(leg_id=1, scope_key=scope_a, writer=mock.Mock(), claims=dict(claims))
            )
            runtime._register_leg(
                merge_runtime.LegState(leg_id=2, scope_key=scope_b, writer=mock.Mock(), claims=dict(claims))
            )

            fake_loop = FakeLoop()
            fake_sockets = [FakeSocket("sock-a"), FakeSocket("sock-b")]

            def fake_socket(*_args, **_kwargs):  # noqa: ANN001
                return fake_sockets.pop(0)

            def fake_create_task(coro):  # noqa: ANN001
                coro.close()
                return CompletedTask()

            with mock.patch.object(merge_runtime.socket, "socket", side_effect=fake_socket):
                with mock.patch.object(merge_runtime.asyncio, "get_running_loop", return_value=fake_loop):
                    with mock.patch.object(merge_runtime.asyncio, "create_task", side_effect=fake_create_task):
                        await runtime.emit_to_target(
                            merge_runtime.MergeItem(session_key=session_key_a, session_id=9, seq=1, payload=b"client-a"),
                            dict(claims),
                        )
                        await runtime.emit_to_target(
                            merge_runtime.MergeItem(session_key=session_key_b, session_id=9, seq=1, payload=b"client-b"),
                            dict(claims),
                        )

                        self.assertIn(session_key_a, runtime.sessions)
                        self.assertIn(session_key_b, runtime.sessions)
                        self.assertEqual(runtime.sessions[session_key_a].scope_key, scope_a)
                        self.assertEqual(runtime.sessions[session_key_b].scope_key, scope_b)
                        self.assertNotEqual(runtime.sessions[session_key_a].session_key, runtime.sessions[session_key_b].session_key)

                        fake_loop.recv_payloads[runtime.sessions[session_key_a].udp_sock] = [b"reply-a", RuntimeError("stop-a")]
                        fake_loop.recv_payloads[runtime.sessions[session_key_b].udp_sock] = [b"reply-b", RuntimeError("stop-b")]

                        sent_frames: list[tuple[int, int, bytes]] = []

                        async def fake_send_frame_on_leg(leg_state, frame):  # noqa: ANN001
                            sent_frames.append((leg_state.leg_id, frame.session_id, frame.payload))

                        with mock.patch.object(runtime, "send_frame_on_leg", side_effect=fake_send_frame_on_leg):
                            with mock.patch.object(runtime, "sampled_log"):
                                await runtime.upstream_reader_loop(session_key_a, runtime.sessions[session_key_a].udp_sock)
                                await runtime.upstream_reader_loop(session_key_b, runtime.sessions[session_key_b].udp_sock)

            self.assertEqual(fake_loop.sock_sent, [("sock-a", b"client-a"), ("sock-b", b"client-b")])
            self.assertEqual(sent_frames, [(1, 9, b"reply-a"), (2, 9, b"reply-b")])

        asyncio.run(scenario())

    def test_cleanup_idle_sessions_removes_scoped_sessions(self) -> None:
        class CompletedTask:
            def cancel(self) -> None:
                return None

            def __await__(self):  # noqa: ANN204
                if False:
                    yield None
                return None

        config = merge_runtime.RuntimeConfig(
            listen_host="127.0.0.1",
            listen_port=8999,
            token_secret="secret-123",
            allowed_forward_ids=[26],
            reorder_window_packets=8,
            reorder_window_bytes=4096,
            reorder_timeout_ms=25,
            stats_path="NUL",
            session_idle_timeout_ms=50,
        )
        runtime = merge_runtime.Runtime(config)
        scope = merge_runtime.build_scope_key(
            {
                "target_host": "193.227.109.82",
                "target_port": 5201,
                "target_protocol": "udp",
                "forward_id": 26,
            }
        )
        session_key = merge_runtime.build_session_key(scope, 9)
        udp_sock = mock.Mock()
        runtime.sessions[session_key] = merge_runtime.SessionState(
            session_key=session_key,
            session_id=9,
            scope_key=scope,
            forward_id=26,
            target_host="193.227.109.82",
            target_port=5201,
            udp_sock=udp_sock,
            upstream_reader_task=CompletedTask(),
            last_seen_ms=1000,
        )
        runtime.stats["sessions_active"] = 1

        removed = asyncio.run(runtime.cleanup_idle_sessions(now_ms=1100))
        self.assertEqual(removed, 1)
        self.assertEqual(runtime.stats["sessions_active"], 0)
        udp_sock.close.assert_called_once()


if __name__ == "__main__":
    unittest.main()
