#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import base64
import dataclasses
import hashlib
import hmac
import json
import logging
import pathlib
import socket
import struct
import time
from collections import deque
from typing import Any


FRAME_PREFIX = struct.Struct(">I")
FRAME_HEADER = struct.Struct(">BBHIQI")
MSG_AUTH = 0
MSG_OPEN = 1
MSG_DATA = 2
MSG_CLOSE = 3
MSG_KEEPALIVE = 4


@dataclasses.dataclass
class DataFrame:
    version: int
    msg_type: int
    flags: int
    session_id: int
    seq: int
    payload: bytes


@dataclasses.dataclass
class MergeItem:
    session_key: object
    session_id: int
    seq: int
    payload: bytes


@dataclasses.dataclass
class SessionMergeState:
    next_expected_seq: int = 1
    buffered: dict[int, bytes] = dataclasses.field(default_factory=dict)
    gap_started_at: int | None = None
    buffered_bytes: int = 0


@dataclasses.dataclass
class RuntimeConfig:
    listen_host: str
    listen_port: int
    token_secret: str
    allowed_forward_ids: list[int]
    reorder_window_packets: int
    reorder_window_bytes: int
    reorder_timeout_ms: int
    stats_path: str
    session_idle_timeout_ms: int = 120000


@dataclasses.dataclass
class SessionState:
    session_key: object
    session_id: int
    scope_key: object
    forward_id: int
    target_host: str
    target_port: int
    udp_sock: socket.socket
    upstream_reader_task: asyncio.Task[None]
    reverse_seq: int = 1
    last_seen_ms: int = 0


@dataclasses.dataclass
class LegState:
    leg_id: int
    scope_key: object
    writer: asyncio.StreamWriter
    claims: dict[str, Any]
    write_lock: asyncio.Lock = dataclasses.field(default_factory=asyncio.Lock)


def encode_frame(frame: DataFrame) -> bytes:
    payload = bytes(frame.payload)
    header = FRAME_HEADER.pack(frame.version, frame.msg_type, frame.flags, frame.session_id, frame.seq, len(payload))
    return header + payload


def decode_frame(payload: bytes) -> DataFrame:
    if len(payload) < FRAME_HEADER.size:
        raise ValueError("frame too short")
    version, msg_type, flags, session_id, seq, payload_len = FRAME_HEADER.unpack(payload[: FRAME_HEADER.size])
    if len(payload) < FRAME_HEADER.size + payload_len:
        raise ValueError("truncated frame payload")
    if len(payload) != FRAME_HEADER.size + payload_len:
        raise ValueError("frame has trailing bytes")
    body = payload[FRAME_HEADER.size : FRAME_HEADER.size + payload_len]
    return DataFrame(version=version, msg_type=msg_type, flags=flags, session_id=session_id, seq=seq, payload=body)


def decode_urlsafe(value: str) -> bytes:
    padding = "=" * ((4 - len(value) % 4) % 4)
    return base64.urlsafe_b64decode(value + padding)


def verify_session_token(token: str, secret: str, now: int) -> dict[str, Any]:
    payload_b64, signature_b64 = token.split(".", 1)
    payload_bytes = decode_urlsafe(payload_b64)
    expected_sig = hmac.new(secret.encode("utf-8"), payload_bytes, hashlib.sha256).digest()
    actual_sig = decode_urlsafe(signature_b64)
    if not hmac.compare_digest(expected_sig, actual_sig):
        raise RuntimeError("token signature verification failed")
    payload = json.loads(payload_bytes.decode("utf-8"))
    expires_at = int(payload.get("expires_at", 0) or 0)
    if expires_at > 0 and now > expires_at:
        raise RuntimeError("session token expired")
    return payload


def build_test_token(payload: dict[str, Any], secret: str) -> str:
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    payload_b64 = base64.urlsafe_b64encode(body).rstrip(b"=").decode("utf-8")
    sig = hmac.new(secret.encode("utf-8"), body, hashlib.sha256).digest()
    sig_b64 = base64.urlsafe_b64encode(sig).rstrip(b"=").decode("utf-8")
    return payload_b64 + "." + sig_b64


def build_scope_key(claims: dict[str, Any], token: str = "") -> tuple[int, str, int, str]:
    forward_id = int(claims.get("forward_id", 0) or 0)
    target_host = str(claims.get("target_host", "") or "").strip()
    target_port = int(claims.get("target_port", 0) or 0)
    token_fingerprint = hashlib.sha256(token.encode("utf-8")).hexdigest()[:16] if token != "" else ""
    return (forward_id, target_host, target_port, token_fingerprint)


def build_session_key(scope_key: object, session_id: int) -> tuple[object, int]:
    return (scope_key, int(session_id))


class MergeBuffer:
    def __init__(self, packet_limit: int, byte_limit: int, timeout_ms: int) -> None:
        self.packet_limit = packet_limit
        self.byte_limit = byte_limit
        self.timeout_ms = timeout_ms
        self.session_state: dict[object, SessionMergeState] = {}
        self.skipped_gap_packets = 0

    def _enforce_limits(self, state: SessionMergeState) -> None:
        if self.packet_limit <= 0 and self.byte_limit <= 0:
            return
        while state.buffered:
            if self.packet_limit > 0 and len(state.buffered) > self.packet_limit:
                seq = max(state.buffered)
            elif self.byte_limit > 0 and state.buffered_bytes > self.byte_limit:
                seq = max(state.buffered)
            else:
                break
            dropped = state.buffered.pop(seq)
            state.buffered_bytes -= len(dropped)

    def push(self, session_key: object, seq: int, payload: bytes, session_id: int | None = None) -> list[MergeItem]:
        state = self.session_state.setdefault(session_key, SessionMergeState())
        merge_session_id = session_id if session_id is not None else int(session_key) if isinstance(session_key, int) else 0
        if seq < state.next_expected_seq:
            return []
        if seq == state.next_expected_seq:
            ready = [MergeItem(session_key=session_key, session_id=merge_session_id, seq=seq, payload=payload)]
            state.next_expected_seq += 1
            while state.next_expected_seq in state.buffered:
                body = state.buffered.pop(state.next_expected_seq)
                state.buffered_bytes -= len(body)
                ready.append(MergeItem(session_key=session_key, session_id=merge_session_id, seq=state.next_expected_seq, payload=body))
                state.next_expected_seq += 1
            state.gap_started_at = None
            return ready
        if seq not in state.buffered:
            state.buffered[seq] = payload
            state.buffered_bytes += len(payload)
            if state.gap_started_at is None:
                state.gap_started_at = int(time.time() * 1000)
            self._enforce_limits(state)
        return []

    def flush_timeouts(self, now_ms: int | None = None) -> list[MergeItem]:
        now_ms = int(time.time() * 1000) if now_ms is None else now_ms
        released: list[MergeItem] = []
        self.skipped_gap_packets = 0
        for session_key, state in self.session_state.items():
            if state.gap_started_at is None:
                continue
            if now_ms - state.gap_started_at < self.timeout_ms:
                continue
            if not state.buffered:
                state.gap_started_at = None
                continue
            old_expected = state.next_expected_seq
            state.next_expected_seq = min(state.buffered)
            if state.next_expected_seq > old_expected:
                self.skipped_gap_packets += state.next_expected_seq - old_expected
            while state.next_expected_seq in state.buffered:
                body = state.buffered.pop(state.next_expected_seq)
                state.buffered_bytes -= len(body)
                merge_session_id = session_key if isinstance(session_key, int) else int(session_key[1]) if isinstance(session_key, tuple) and len(session_key) > 1 else 0
                released.append(MergeItem(session_key=session_key, session_id=merge_session_id, seq=state.next_expected_seq, payload=body))
                state.next_expected_seq += 1
            state.gap_started_at = None
        return released

    def total_buffered_bytes(self) -> int:
        return sum(state.buffered_bytes for state in self.session_state.values())


async def read_leg_frame(reader: asyncio.StreamReader) -> DataFrame:
    header = await reader.readexactly(FRAME_PREFIX.size)
    frame_len = FRAME_PREFIX.unpack(header)[0]
    encoded = await reader.readexactly(frame_len)
    return decode_frame(encoded)


async def write_leg_frame(writer: asyncio.StreamWriter, frame: DataFrame) -> None:
    encoded = encode_frame(frame)
    writer.write(FRAME_PREFIX.pack(len(encoded)))
    writer.write(encoded)
    await writer.drain()


def load_config(path: str) -> RuntimeConfig:
    raw = json.loads(pathlib.Path(path).read_text(encoding="utf-8"))
    return RuntimeConfig(
        listen_host=str(raw.get("listen_host", "0.0.0.0") or "0.0.0.0"),
        listen_port=int(raw.get("listen_port", 0) or 0),
        token_secret=str(raw.get("token_secret", "") or ""),
        allowed_forward_ids=[int(value) for value in raw.get("allowed_forward_ids", [])],
        reorder_window_packets=int(raw.get("reorder_window_packets", 4096) or 4096),
        reorder_window_bytes=int(raw.get("reorder_window_bytes", 16777216) or 16777216),
        reorder_timeout_ms=int(raw.get("reorder_timeout_ms", 25) or 25),
        stats_path=str(raw.get("stats_path") or "/run/leikwan/udp-uot-runtime-stats.json"),
        session_idle_timeout_ms=int(raw.get("session_idle_timeout_ms", 120000) or 120000),
    )


class Runtime:
    def __init__(self, config: RuntimeConfig) -> None:
        self.config = config
        self.merge = MergeBuffer(config.reorder_window_packets, config.reorder_window_bytes, config.reorder_timeout_ms)
        self.active_legs: dict[int, LegState] = {}
        self.scope_leg_order: dict[object, deque[int]] = {}
        self.scope_claims: dict[object, dict[str, Any]] = {}
        self.sessions: dict[object, SessionState] = {}
        self.inbound_queue: asyncio.Queue[tuple[int, DataFrame]] = asyncio.Queue()
        self.next_leg_id = 1
        self.log_samples: dict[str, int] = {}
        self.stats: dict[str, int] = {
            "live_legs": 0,
            "sessions_active": 0,
            "datagrams_in": 0,
            "datagrams_out": 0,
            "datagrams_dropped_gap": 0,
            "datagrams_dropped_no_leg": 0,
            "bytes_in": 0,
            "bytes_out": 0,
            "reorder_buffer_bytes": 0,
        }

    def sampled_log(self, key: str, level: int, message: str, *args: Any) -> None:
        count = self.log_samples.get(key, 0) + 1
        self.log_samples[key] = count
        if count <= 3 or count % 100 == 0:
            logging.log(level, message, *args)

    def _register_leg(self, leg_state: LegState) -> None:
        self.active_legs[leg_state.leg_id] = leg_state
        leg_order = self.scope_leg_order.setdefault(leg_state.scope_key, deque())
        if leg_state.leg_id not in leg_order:
            leg_order.append(leg_state.leg_id)
        self.scope_claims[leg_state.scope_key] = dict(leg_state.claims)
        self.stats["live_legs"] = len(self.active_legs)

    def _remove_leg(self, leg_id: int) -> None:
        leg_state = self.active_legs.pop(leg_id, None)
        if leg_state is not None:
            leg_order = self.scope_leg_order.get(leg_state.scope_key)
            if leg_order is not None:
                self.scope_leg_order[leg_state.scope_key] = deque(item for item in leg_order if item != leg_id)
                if not self.scope_leg_order[leg_state.scope_key]:
                    self.scope_leg_order.pop(leg_state.scope_key, None)
                    self.scope_claims.pop(leg_state.scope_key, None)
        self.stats["live_legs"] = len(self.active_legs)

    def pick_leg(self, scope_key: object) -> LegState | None:
        leg_order = self.scope_leg_order.get(scope_key)
        if not leg_order:
            return None
        for _ in range(len(leg_order)):
            leg_id = leg_order[0]
            leg_order.rotate(-1)
            leg_state = self.active_legs.get(leg_id)
            if leg_state is not None:
                return leg_state
        return None

    @staticmethod
    def validate_claims(claims: dict[str, Any], allowed_forward_ids: list[int]) -> None:
        if str(claims.get("target_protocol", "") or "").strip().lower() != "udp":
            raise RuntimeError("protocol mismatch")
        target_host = str(claims.get("target_host", "") or "").strip()
        target_port = int(claims.get("target_port", 0) or 0)
        forward_id = int(claims.get("forward_id", 0) or 0)
        if target_host == "" or target_port <= 0:
            raise RuntimeError("missing udp target")
        if allowed_forward_ids and forward_id not in allowed_forward_ids:
            raise RuntimeError(f"forward {forward_id} is not allowed")

    async def send_frame_on_leg(self, leg_state: LegState, frame: DataFrame) -> None:
        async with leg_state.write_lock:
            await write_leg_frame(leg_state.writer, frame)

    async def open_session(self, session_key: object, session_id: int, scope_key: object, claims: dict[str, Any]) -> SessionState:
        existing = self.sessions.get(session_key)
        if existing is not None:
            existing.last_seen_ms = int(time.time() * 1000)
            return existing

        target_host = str(claims.get("target_host", "") or "").strip()
        target_port = int(claims.get("target_port", 0) or 0)
        forward_id = int(claims.get("forward_id", 0) or 0)
        infos = await asyncio.get_running_loop().getaddrinfo(target_host, target_port, type=socket.SOCK_DGRAM)
        family, _socktype, _proto, _canonname, sockaddr = infos[0]
        udp_sock = socket.socket(family, socket.SOCK_DGRAM)
        udp_sock.setblocking(False)
        await asyncio.get_running_loop().sock_connect(udp_sock, sockaddr)
        session = SessionState(
            session_key=session_key,
            session_id=session_id,
            scope_key=scope_key,
            forward_id=forward_id,
            target_host=target_host,
            target_port=target_port,
            udp_sock=udp_sock,
            upstream_reader_task=asyncio.create_task(self.upstream_reader_loop(session_key, udp_sock)),
            last_seen_ms=int(time.time() * 1000),
        )
        self.sessions[session_key] = session
        self.stats["sessions_active"] = len(self.sessions)
        self.sampled_log(
            "session-opened",
            logging.INFO,
            "udp-uot session opened session_id=%s forward_id=%s target=%s:%s",
            session_id,
            forward_id,
            target_host,
            target_port,
        )
        return session

    async def close_session(self, session_key: object) -> None:
        session = self.sessions.pop(session_key, None)
        self.merge.session_state.pop(session_key, None)
        self.stats["sessions_active"] = len(self.sessions)
        if session is None:
            return
        session.upstream_reader_task.cancel()
        try:
            await session.upstream_reader_task
        except asyncio.CancelledError:
            pass
        except Exception:
            pass
        session.udp_sock.close()

    async def emit_to_target(self, item: MergeItem, claims: dict[str, Any]) -> None:
        scope_key = item.session_key[0] if isinstance(item.session_key, tuple) and len(item.session_key) > 0 else build_scope_key(claims)
        session = await self.open_session(item.session_key, item.session_id, scope_key, claims)
        session.last_seen_ms = int(time.time() * 1000)
        await asyncio.get_running_loop().sock_sendall(session.udp_sock, item.payload)
        self.stats["datagrams_in"] += 1
        self.stats["bytes_in"] += len(item.payload)

    async def upstream_reader_loop(self, session_key: object, udp_sock: socket.socket) -> None:
        loop = asyncio.get_running_loop()
        try:
            while True:
                payload = await loop.sock_recv(udp_sock, 65535)
                if not payload:
                    continue
                session = self.sessions.get(session_key)
                if session is None:
                    return
                session.last_seen_ms = int(time.time() * 1000)
                leg_state = self.pick_leg(session.scope_key)
                if leg_state is None:
                    self.stats["datagrams_dropped_no_leg"] += 1
                    continue
                frame = DataFrame(
                    version=1,
                    msg_type=MSG_DATA,
                    flags=0,
                    session_id=session.session_id,
                    seq=session.reverse_seq,
                    payload=payload,
                )
                session.reverse_seq += 1
                await self.send_frame_on_leg(leg_state, frame)
                self.stats["datagrams_out"] += 1
                self.stats["bytes_out"] += len(payload)
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            session_id = self.sessions.get(session_key).session_id if session_key in self.sessions else 0
            self.sampled_log("upstream-reader", logging.WARNING, "udp-uot upstream reader ended session_id=%s error=%s", session_id, exc)

    async def dispatch_loop(self) -> None:
        flush_interval_ms = max(1, self.config.reorder_timeout_ms // 2)
        timeout = flush_interval_ms / 1000.0
        while True:
            inbound: tuple[int, DataFrame] | None = None
            try:
                inbound = await asyncio.wait_for(self.inbound_queue.get(), timeout=timeout)
            except asyncio.TimeoutError:
                pass

            if inbound is not None:
                leg_id, frame = inbound
                leg_state = self.active_legs.get(leg_id)
                if leg_state is not None:
                    if frame.msg_type == MSG_DATA:
                        session_key = build_session_key(leg_state.scope_key, frame.session_id)
                        for item in self.merge.push(session_key, frame.seq, frame.payload, session_id=frame.session_id):
                            await self.emit_to_target(item, leg_state.claims)
                    elif frame.msg_type == MSG_CLOSE:
                        await self.close_session(build_session_key(leg_state.scope_key, frame.session_id))
                    elif frame.msg_type == MSG_KEEPALIVE:
                        session = self.sessions.get(build_session_key(leg_state.scope_key, frame.session_id))
                        if session is not None:
                            session.last_seen_ms = int(time.time() * 1000)

            skipped = self.merge.flush_timeouts()
            if self.merge.skipped_gap_packets > 0:
                self.stats["datagrams_dropped_gap"] += self.merge.skipped_gap_packets
                self.sampled_log(
                    "gap-timeout",
                    logging.WARNING,
                    "udp-uot gap timeout dropped=%s",
                    self.merge.skipped_gap_packets,
                )
            for item in skipped:
                claims = self.claims_for_session(item.session_key)
                if claims is None:
                    continue
                await self.emit_to_target(item, claims)

            removed = await self.cleanup_idle_sessions()
            if removed > 0:
                self.sampled_log("session-cleanup", logging.INFO, "udp-uot cleaned idle sessions=%s", removed)
            self.stats["reorder_buffer_bytes"] = self.merge.total_buffered_bytes()

    def claims_for_session(self, session_key: object) -> dict[str, Any] | None:
        session = self.sessions.get(session_key)
        if session is not None:
            return {
                "target_host": session.target_host,
                "target_port": session.target_port,
                "target_protocol": "udp",
                "forward_id": session.forward_id,
            }
        scope_key = session_key[0] if isinstance(session_key, tuple) and len(session_key) > 0 else None
        return self.scope_claims.get(scope_key)

    async def cleanup_idle_sessions(self, now_ms: int | None = None) -> int:
        now_ms = int(time.time() * 1000) if now_ms is None else now_ms
        stale = [
            session_key
            for session_key, session in self.sessions.items()
            if now_ms - session.last_seen_ms >= self.config.session_idle_timeout_ms
        ]
        for session_key in stale:
            await self.close_session(session_key)
        return len(stale)

    async def stats_writer_loop(self) -> None:
        stats_path = pathlib.Path(self.config.stats_path)
        while True:
            payload = dict(self.stats)
            payload["updated_at"] = int(time.time())
            stats_path.parent.mkdir(parents=True, exist_ok=True)
            stats_path.write_text(json.dumps(payload, ensure_ascii=True, sort_keys=True), encoding="utf-8")
            await asyncio.sleep(1.0)

    async def handle_leg(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        leg_id = self.next_leg_id
        self.next_leg_id += 1
        registered = False
        try:
            first_frame = await read_leg_frame(reader)
            if first_frame.msg_type != MSG_AUTH:
                raise RuntimeError("auth frame required before data")
            token = first_frame.payload.decode("utf-8")
            claims = verify_session_token(token, self.config.token_secret, int(time.time()))
            self.validate_claims(claims, self.config.allowed_forward_ids)
            scope_key = build_scope_key(claims, token)
            leg_state = LegState(leg_id=leg_id, scope_key=scope_key, writer=writer, claims=claims)
            self._register_leg(leg_state)
            registered = True
            while True:
                frame = await read_leg_frame(reader)
                if frame.msg_type == MSG_AUTH:
                    raise RuntimeError("auth frame must be first binary frame")
                await self.inbound_queue.put((leg_id, frame))
        except asyncio.IncompleteReadError:
            pass
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            self.sampled_log("leg-error", logging.WARNING, "udp-uot leg error leg_id=%s error=%s", leg_id, exc)
        finally:
            if registered:
                self._remove_leg(leg_id)
                self.sampled_log("leg-disconnected", logging.INFO, "udp-uot leg disconnected leg_id=%s", leg_id)
            writer.close()
            try:
                await writer.wait_closed()
            except Exception:
                pass

    async def run(self) -> None:
        server = await asyncio.start_server(self.handle_leg, self.config.listen_host, self.config.listen_port)
        dispatch_task = asyncio.create_task(self.dispatch_loop())
        stats_task = asyncio.create_task(self.stats_writer_loop())
        try:
            async with server:
                await server.serve_forever()
        finally:
            dispatch_task.cancel()
            stats_task.cancel()
            for session_id in list(self.sessions):
                await self.close_session(session_id)
            await asyncio.gather(dispatch_task, stats_task, return_exceptions=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Leikwan UDP UoT merge runtime")
    parser.add_argument("--config", required=True)
    parser.add_argument("--log-level", default="INFO")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    logging.basicConfig(
        level=getattr(logging, str(args.log_level).upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    config = load_config(args.config)
    asyncio.run(Runtime(config).run())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
