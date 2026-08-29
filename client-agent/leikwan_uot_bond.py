#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import dataclasses
import json
import logging
import socket
import struct
import time
from collections import deque
from pathlib import Path
from typing import Any


FRAME_PREFIX = struct.Struct(">I")
FRAME_HEADER = struct.Struct(">BBHIQI")
MSG_AUTH = 0
MSG_OPEN = 1
MSG_DATA = 2
MSG_CLOSE = 3
MSG_KEEPALIVE = 4
MAX_RECONNECT_BACKOFF_SECONDS = 5.0
LEG_KEEPALIVE_INTERVAL_SECONDS = 2.0
LEG_PROBATION_AFTER_FAILURES = 2
LEG_QUARANTINE_AFTER_FAILURES = 3
LEG_PROBATION_SECONDS = 5.0
LEG_QUARANTINE_SECONDS = 30.0


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
class LegState:
    endpoint_id: int
    weight: int = 1
    active: bool = True
    base_weight: int = 1
    consecutive_failures: int = 0
    suppress_until_ms: int = 0


@dataclasses.dataclass
class LegConfig:
    endpoint_id: int
    host: str
    port: int
    auth_token: str
    weight: int = 1


@dataclasses.dataclass
class RuntimeConfig:
    forward_id: int
    listen_udp: str
    route_key: str
    session_token: str
    reorder_window_packets: int
    reorder_window_bytes: int
    reorder_timeout_ms: int
    legs: list[LegConfig]
    stats_path: str
    session_idle_timeout_ms: int = 120000


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


class WeightedLegSelector:
    def __init__(self, legs: list[LegState]) -> None:
        self.legs = legs
        self.sequence: deque[LegState] = deque()
        self.refresh()

    def refresh(self) -> None:
        self.sequence = deque([leg for leg in self.legs for _ in range(max(0, int(leg.weight or 0)))])

    def pick_leg(self) -> LegState:
        if not self.sequence:
            raise RuntimeError("no legs configured")
        for _ in range(len(self.sequence)):
            leg = self.sequence[0]
            self.sequence.rotate(-1)
            if leg.active:
                return leg
        raise RuntimeError("no active legs available")


class MergeBuffer:
    def __init__(self, packet_limit: int, byte_limit: int, timeout_ms: int) -> None:
        self.packet_limit = packet_limit
        self.byte_limit = byte_limit
        self.timeout_ms = timeout_ms
        self.session_state: dict[int, SessionMergeState] = {}
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

    def push(self, session_id: int, seq: int, payload: bytes) -> list[MergeItem]:
        state = self.session_state.setdefault(session_id, SessionMergeState())
        if seq < state.next_expected_seq:
            return []
        if seq == state.next_expected_seq:
            ready = [MergeItem(session_id=session_id, seq=seq, payload=payload)]
            state.next_expected_seq += 1
            while state.next_expected_seq in state.buffered:
                body = state.buffered.pop(state.next_expected_seq)
                state.buffered_bytes -= len(body)
                ready.append(MergeItem(session_id=session_id, seq=state.next_expected_seq, payload=body))
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
        for session_id, state in self.session_state.items():
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
                released.append(MergeItem(session_id=session_id, seq=state.next_expected_seq, payload=body))
                state.next_expected_seq += 1
            state.gap_started_at = None
        return released

    def total_buffered_bytes(self) -> int:
        return sum(state.buffered_bytes for state in self.session_state.values())


async def open_plain_tcp_leg(host: str, port: int, route_key: str, auth_token: str) -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
    reader, writer = await asyncio.open_connection(host, port)
    writer.write(f"LEIKWAN-INGRESS 1 OPEN {route_key} {auth_token}\n".encode("utf-8"))
    await writer.drain()
    response = await reader.readline()
    if response not in (b"OK\n", b"OK\r\n"):
        raise RuntimeError(f"plain tcp mux open rejected: {response.decode('utf-8', errors='replace').strip() or 'empty response'}")
    return reader, writer


async def write_leg_frame(writer: asyncio.StreamWriter, frame: DataFrame) -> None:
    encoded = encode_frame(frame)
    writer.write(FRAME_PREFIX.pack(len(encoded)))
    writer.write(encoded)
    await writer.drain()


def parse_host_port(value: str) -> tuple[str, int]:
    host, separator, port_text = value.rpartition(":")
    if separator == "" or host.strip() == "" or port_text.strip() == "":
        raise ValueError(f"invalid host:port {value!r}")
    return host.strip(), int(port_text.strip())


def build_leg_auth_payload(leg_config: LegConfig, session_token: str) -> bytes:
    return json.dumps(
        {
            "token": session_token,
            "endpoint_id": int(leg_config.endpoint_id),
            "weight": max(1, int(leg_config.weight or 1)),
        },
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")


def load_config(path: str) -> RuntimeConfig:
    raw = json.loads(Path(path).read_text(encoding="utf-8"))
    scheduler_mode = str(raw.get("scheduler_mode", "legacy") or "legacy").strip().lower()
    if scheduler_mode != "legacy":
        raise RuntimeError("feedback_drr requires the native Rust UoT runtime")
    forward_id = int(raw["forward_id"])
    stats_path = str(raw.get("stats_path") or f"/run/leikwan/uot-bond-forward-{forward_id}.stats.json")
    legs = [
        LegConfig(
            endpoint_id=int(leg["endpoint_id"]),
            host=str(leg["host"]),
            port=int(leg["port"]),
            auth_token=str(leg["auth_token"]),
            weight=int(leg.get("weight", 1) or 1),
        )
        for leg in raw.get("legs", [])
    ]
    return RuntimeConfig(
        forward_id=forward_id,
        listen_udp=str(raw["listen_udp"]),
        route_key=str(raw["route_key"]),
        session_token=str(raw["session_token"]),
        reorder_window_packets=int(raw.get("reorder_window_packets", 4096) or 4096),
        reorder_window_bytes=int(raw.get("reorder_window_bytes", 16777216) or 16777216),
        reorder_timeout_ms=int(raw.get("reorder_timeout_ms", 25) or 25),
        legs=legs,
        stats_path=stats_path,
        session_idle_timeout_ms=int(raw.get("session_idle_timeout_ms", 120000) or 120000),
    )


class Runtime:
    def __init__(self, config: RuntimeConfig) -> None:
        self.config = config
        self.leg_states: list[LegState] = [
            LegState(
                endpoint_id=leg.endpoint_id,
                weight=max(1, leg.weight),
                active=False,
                base_weight=max(1, leg.weight),
            )
            for leg in config.legs
        ]
        self.leg_by_id = {leg.endpoint_id: leg for leg in config.legs}
        self.selector = WeightedLegSelector(self.leg_states)
        self.merge = MergeBuffer(config.reorder_window_packets, config.reorder_window_bytes, config.reorder_timeout_ms)
        self.inbound_queue: asyncio.Queue[DataFrame] = asyncio.Queue()
        self.per_leg_queues: dict[int, asyncio.Queue[DataFrame]] = {leg.endpoint_id: asyncio.Queue(maxsize=4096) for leg in config.legs}
        self.transport: asyncio.DatagramTransport | None = None
        self.last_peer: tuple[str, int] | None = None
        self.peer_to_session: dict[tuple[str, int], int] = {}
        self.session_to_peer: dict[int, tuple[str, int]] = {}
        self.session_next_seq: dict[int, int] = {}
        self.session_last_seen_ms: dict[int, int] = {}
        self.next_session_id = 1
        self.log_samples: dict[str, int] = {}
        self.stats: dict[str, int] = {
            "live_legs": 0,
            "sessions_active": 0,
            "datagrams_in": 0,
            "datagrams_out": 0,
            "datagrams_dropped_gap": 0,
            "reorder_buffer_bytes": 0,
        }

    def penalize_leg(self, leg_state: LegState, now_ms: int | None = None) -> None:
        now_ms = int(time.time() * 1000) if now_ms is None else now_ms
        leg_state.consecutive_failures += 1
        if leg_state.consecutive_failures >= LEG_QUARANTINE_AFTER_FAILURES:
            leg_state.weight = 0
            leg_state.suppress_until_ms = now_ms + int(LEG_QUARANTINE_SECONDS * 1000)
        elif leg_state.consecutive_failures >= LEG_PROBATION_AFTER_FAILURES:
            leg_state.weight = max(1, leg_state.base_weight // 4)
            leg_state.suppress_until_ms = now_ms + int(LEG_PROBATION_SECONDS * 1000)
        else:
            leg_state.weight = max(1, leg_state.base_weight // 2)
            leg_state.suppress_until_ms = 0
        self.selector.refresh()

    def restore_leg(self, leg_state: LegState) -> None:
        leg_state.consecutive_failures = 0
        leg_state.suppress_until_ms = 0
        leg_state.weight = leg_state.base_weight
        self.selector.refresh()

    def mark_leg_ready(self, leg_state: LegState) -> None:
        self.restore_leg(leg_state)
        if not leg_state.active:
            leg_state.active = True
            self.stats["live_legs"] = sum(1 for item in self.leg_states if item.active)
        self.selector.refresh()

    def sampled_log(self, key: str, level: int, message: str, *args: Any) -> None:
        count = self.log_samples.get(key, 0) + 1
        self.log_samples[key] = count
        if count <= 3 or count % 100 == 0:
            logging.log(level, message, *args)

    def alloc_session(self, peer: tuple[str, int]) -> int:
        existing = self.peer_to_session.get(peer)
        if existing is not None:
            self.session_last_seen_ms[existing] = int(time.time() * 1000)
            return existing
        session_id = self.next_session_id
        self.next_session_id += 1
        self.peer_to_session[peer] = session_id
        self.session_to_peer[session_id] = peer
        self.session_next_seq[session_id] = 1
        self.session_last_seen_ms[session_id] = int(time.time() * 1000)
        self.stats["sessions_active"] = len(self.session_to_peer)
        return session_id

    def close_session(self, session_id: int) -> bool:
        peer = self.session_to_peer.pop(session_id, None)
        if peer is None:
            return False
        self.peer_to_session.pop(peer, None)
        self.session_next_seq.pop(session_id, None)
        self.session_last_seen_ms.pop(session_id, None)
        self.merge.session_state.pop(session_id, None)
        self.stats["sessions_active"] = len(self.session_to_peer)
        return True

    def cleanup_idle_sessions(self, now_ms: int | None = None, idle_timeout_ms: int | None = None) -> int:
        now_ms = int(time.time() * 1000) if now_ms is None else now_ms
        idle_timeout_ms = self.config.session_idle_timeout_ms if idle_timeout_ms is None else idle_timeout_ms
        stale = [
            session_id
            for session_id, seen_ms in self.session_last_seen_ms.items()
            if now_ms - seen_ms >= idle_timeout_ms
        ]
        for session_id in stale:
            self.close_session(session_id)
        return len(stale)

    async def enqueue_outbound(self, payload: bytes, peer: tuple[str, int]) -> None:
        self.stats["datagrams_in"] += 1
        session_id = self.alloc_session(peer)
        seq = self.session_next_seq[session_id]
        self.session_next_seq[session_id] = seq + 1
        frame = DataFrame(version=1, msg_type=MSG_DATA, flags=0, session_id=session_id, seq=seq, payload=payload)
        try:
            leg = self.selector.pick_leg()
        except RuntimeError:
            self.stats["datagrams_dropped_gap"] += 1
            return
        queue = self.per_leg_queues[leg.endpoint_id]
        try:
            queue.put_nowait(frame)
        except asyncio.QueueFull:
            self.stats["datagrams_dropped_gap"] += 1

    async def leg_loop(self, leg_config: LegConfig, leg_state: LegState) -> None:
        backoff = 0.25
        queue = self.per_leg_queues[leg_config.endpoint_id]
        while True:
            writer: asyncio.StreamWriter | None = None
            try:
                now_ms = int(time.time() * 1000)
                if leg_state.suppress_until_ms > now_ms:
                    await asyncio.sleep((leg_state.suppress_until_ms - now_ms) / 1000.0)
                reader, writer = await open_plain_tcp_leg(
                    leg_config.host, leg_config.port, self.config.route_key, leg_config.auth_token
                )
                await write_leg_frame(
                    writer,
                    DataFrame(
                        version=1,
                        msg_type=MSG_AUTH,
                        flags=0,
                        session_id=0,
                        seq=0,
                        payload=build_leg_auth_payload(leg_config, self.config.session_token),
                    ),
                )
                backoff = 0.25

                writer_task = asyncio.create_task(self.leg_writer_loop(writer, queue))
                reader_task = asyncio.create_task(self.leg_reader_loop(reader, leg_config.endpoint_id))
                keepalive_task = asyncio.create_task(self.leg_keepalive_loop(queue))
                done, pending = await asyncio.wait({writer_task, reader_task, keepalive_task}, return_when=asyncio.FIRST_COMPLETED)
                for task in pending:
                    task.cancel()
                if pending:
                    await asyncio.gather(*pending, return_exceptions=True)
                for task in done:
                    task.result()
            except asyncio.CancelledError:
                raise
            except Exception as exc:
                self.penalize_leg(leg_state)
                self.sampled_log("leg-down", logging.WARNING, "udp-uot leg down endpoint=%s error=%s", leg_config.endpoint_id, exc)
            finally:
                if leg_state.active:
                    leg_state.active = False
                    self.stats["live_legs"] = sum(1 for item in self.leg_states if item.active)
                if writer is not None:
                    writer.close()
                    try:
                        await writer.wait_closed()
                    except Exception:
                        pass
            await asyncio.sleep(backoff)
            backoff = min(MAX_RECONNECT_BACKOFF_SECONDS, backoff * 2.0)

    async def leg_writer_loop(self, writer: asyncio.StreamWriter, queue: asyncio.Queue[DataFrame]) -> None:
        while True:
            frame = await queue.get()
            await write_leg_frame(writer, frame)

    async def leg_keepalive_loop(self, queue: asyncio.Queue[DataFrame], interval_seconds: float = LEG_KEEPALIVE_INTERVAL_SECONDS) -> None:
        while True:
            await asyncio.sleep(interval_seconds)
            try:
                queue.put_nowait(
                    DataFrame(
                        version=1,
                        msg_type=MSG_KEEPALIVE,
                        flags=0,
                        session_id=0,
                        seq=0,
                        payload=b"",
                    )
                )
            except asyncio.QueueFull:
                self.sampled_log("keepalive-drop", logging.WARNING, "udp-uot keepalive drop queue full")

    async def leg_reader_loop(self, reader: asyncio.StreamReader, endpoint_id: int) -> None:
        ready_marked = False
        while True:
            header = await reader.readexactly(FRAME_PREFIX.size)
            frame_len = FRAME_PREFIX.unpack(header)[0]
            encoded = await reader.readexactly(frame_len)
            frame = decode_frame(encoded)
            if not ready_marked:
                leg_state = next((item for item in self.leg_states if item.endpoint_id == endpoint_id), None)
                if leg_state is not None:
                    was_active = leg_state.active
                    self.mark_leg_ready(leg_state)
                    if not was_active and leg_state.active:
                        self.sampled_log("leg-up", logging.INFO, "udp-uot leg up endpoint=%s", endpoint_id)
                ready_marked = True
            await self.inbound_queue.put(frame)

    def emit_udp(self, payload: bytes, session_id: int) -> None:
        if self.transport is None:
            return
        peer = self.session_to_peer.get(session_id)
        if peer is None:
            self.sampled_log("unknown-session", logging.WARNING, "udp-uot drop unknown session_id=%s", session_id)
            return
        self.session_last_seen_ms[session_id] = int(time.time() * 1000)
        self.transport.sendto(payload, peer)
        self.stats["datagrams_out"] += 1

    async def inbound_dispatch_loop(self) -> None:
        flush_interval = max(1, self.config.reorder_timeout_ms // 2)
        timeout = flush_interval / 1000.0
        while True:
            frame: DataFrame | None = None
            try:
                frame = await asyncio.wait_for(self.inbound_queue.get(), timeout=timeout)
            except asyncio.TimeoutError:
                pass
            if frame is not None:
                if frame.msg_type == MSG_DATA:
                    if frame.session_id not in self.session_to_peer:
                        self.sampled_log(
                            "unknown-session",
                            logging.WARNING,
                            "udp-uot drop unknown session_id=%s",
                            frame.session_id,
                        )
                    else:
                        self.session_last_seen_ms[frame.session_id] = int(time.time() * 1000)
                        for item in self.merge.push(frame.session_id, frame.seq, frame.payload):
                            self.emit_udp(item.payload, item.session_id)
                elif frame.msg_type == MSG_CLOSE:
                    self.close_session(frame.session_id)
                elif frame.msg_type == MSG_KEEPALIVE and frame.session_id in self.session_to_peer:
                    self.session_last_seen_ms[frame.session_id] = int(time.time() * 1000)
            skipped = self.merge.flush_timeouts()
            if self.merge.skipped_gap_packets > 0:
                self.stats["datagrams_dropped_gap"] += self.merge.skipped_gap_packets
                self.sampled_log(
                    "reorder-skip",
                    logging.WARNING,
                    "udp-uot reorder skip dropped=%s",
                    self.merge.skipped_gap_packets,
                )
            for item in skipped:
                self.emit_udp(item.payload, item.session_id)
            removed = self.cleanup_idle_sessions()
            if removed > 0:
                self.sampled_log("session-cleanup", logging.INFO, "udp-uot cleaned idle sessions=%s", removed)
            self.stats["reorder_buffer_bytes"] = self.merge.total_buffered_bytes()

    async def stats_writer_loop(self) -> None:
        stats_path = Path(self.config.stats_path)
        while True:
            stats_path.parent.mkdir(parents=True, exist_ok=True)
            stats_path.write_text(json.dumps(self.stats, ensure_ascii=True, sort_keys=True), encoding="utf-8")
            await asyncio.sleep(1.0)

    async def run(self) -> None:
        loop = asyncio.get_running_loop()

        runtime = self

        class UdpIngress(asyncio.DatagramProtocol):
            def connection_made(self, transport: asyncio.BaseTransport) -> None:
                runtime.transport = transport  # type: ignore[assignment]

            def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
                runtime.last_peer = addr
                asyncio.create_task(runtime.enqueue_outbound(data, addr))

        host, port = parse_host_port(self.config.listen_udp)
        transport, _protocol = await loop.create_datagram_endpoint(lambda: UdpIngress(), local_addr=(host, port), family=socket.AF_INET)
        self.transport = transport  # type: ignore[assignment]
        leg_tasks = [
            asyncio.create_task(self.leg_loop(self.leg_by_id[state.endpoint_id], state))
            for state in self.leg_states
        ]
        dispatch_task = asyncio.create_task(self.inbound_dispatch_loop())
        stats_task = asyncio.create_task(self.stats_writer_loop())
        try:
            await asyncio.gather(*leg_tasks, dispatch_task, stats_task)
        finally:
            transport.close()
            for task in leg_tasks:
                task.cancel()
            dispatch_task.cancel()
            stats_task.cancel()
            await asyncio.gather(*leg_tasks, dispatch_task, stats_task, return_exceptions=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Leikwan striped UDP UoT runtime")
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
