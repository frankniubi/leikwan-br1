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
