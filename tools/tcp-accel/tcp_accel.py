#!/usr/bin/env python3
"""
Small split-TCP accelerator for controlled single-flow tests.

It terminates one local TCP connection, stripes its byte stream over multiple
long-haul TCP lanes, then reassembles chunks in order on the peer side before
connecting to the real target.  The reverse direction uses the same lanes, so
protocols such as iperf3 control + reverse data can pass through one accelerated
connection.
"""

from __future__ import annotations

import argparse
import asyncio
import contextlib
import json
import logging
import ipaddress
import secrets
import socket
import struct
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Awaitable, Callable, Iterable, Protocol


LOG = logging.getLogger("tcp_accel")

TYPE_DATA = 1
TYPE_EOF = 2
TYPE_ACK = 3
HEADER = struct.Struct("!BQI")
ACK_PAYLOAD = struct.Struct("!I")
MAX_FRAME_BYTES = 16 * 1024 * 1024
DEFAULT_CHUNK_SIZE = 64 * 1024
DEFAULT_LANES = 4
DEFAULT_LANE_QUEUE_FRAMES = 16


@dataclass(frozen=True)
class ClientForward:
    listen: tuple[str, int]
    target: tuple[str, int]
    name: str = ""


@dataclass(frozen=True)
class Socks5Listener:
    listen: tuple[str, int]
    name: str = "socks5"


@dataclass(frozen=True)
class ClientConfig:
    remotes: list[tuple[str, int]]
    forwards: list[ClientForward]
    socks5: list[Socks5Listener]
    lanes: int = DEFAULT_LANES
    minimum_lanes: int | None = None
    join_timeout_ms: int = 1200
    chunk_size: int = DEFAULT_CHUNK_SIZE
    lane_queue_frames: int = DEFAULT_LANE_QUEUE_FRAMES
    tcp_user_timeout_ms: int | None = None
    token: str | None = None


class WritableSink(Protocol):
    def write(self, payload: bytes) -> None: ...

    async def drain(self) -> None: ...

    def close(self) -> None: ...


def parse_host_port(value: str, *, allow_zero: bool = False) -> tuple[str, int]:
    host, sep, port_text = value.rpartition(":")
    if not sep or not host:
        raise argparse.ArgumentTypeError(f"expected host:port, got {value!r}")
    if host.startswith("[") and host.endswith("]"):
        host = host[1:-1]
    if not host:
        raise argparse.ArgumentTypeError(f"expected host:port, got {value!r}")
    try:
        port = int(port_text)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(f"invalid port in {value!r}") from exc
    minimum = 0 if allow_zero else 1
    if not minimum <= port <= 65535:
        raise argparse.ArgumentTypeError(f"port out of range in {value!r}")
    return host, port


def _load_json_config(raw_or_path: dict | str | Path) -> dict:
    if isinstance(raw_or_path, dict):
        return raw_or_path
    path = Path(raw_or_path)
    with path.open("r", encoding="utf-8-sig") as fh:
        loaded = json.load(fh)
    if not isinstance(loaded, dict):
        raise ValueError("config must be a JSON object")
    return loaded


def _parse_host_port_field(raw: object, key: str, *, allow_zero: bool = False) -> tuple[str, int]:
    if not isinstance(raw, str):
        raise ValueError(f"{key} must be host:port")
    try:
        return parse_host_port(raw, allow_zero=allow_zero)
    except argparse.ArgumentTypeError as exc:
        raise ValueError(str(exc)) from exc


def _config_int(raw: dict, key: str, default: int | None, minimum: int, maximum: int) -> int | None:
    value = raw.get(key, default)
    if value is None:
        return None
    if isinstance(value, bool):
        raise ValueError(f"{key} must be an integer")
    try:
        parsed = int(value)
    except (TypeError, ValueError) as exc:
        raise ValueError(f"{key} must be an integer") from exc
    if not minimum <= parsed <= maximum:
        raise ValueError(f"{key} must be {minimum}..{maximum}")
    return parsed


def load_client_config(raw_or_path: dict | str | Path) -> ClientConfig:
    raw = _load_json_config(raw_or_path)
    remotes_raw = raw.get("remotes")
    if not isinstance(remotes_raw, list) or not remotes_raw:
        raise ValueError("client config requires non-empty remotes")
    remotes = [_parse_host_port_field(item, "remote") for item in remotes_raw]

    token = _load_token(raw.get("token"), raw.get("token_file"))
    lanes = _config_int(raw, "lanes", DEFAULT_LANES, 1, 64)
    minimum_lanes = _config_int(raw, "minimum_lanes", None, 1, int(lanes))
    join_timeout_ms = _config_int(raw, "join_timeout_ms", 1200, 0, 30_000)
    chunk_size = _config_int(raw, "chunk_size", DEFAULT_CHUNK_SIZE, 1, MAX_FRAME_BYTES)
    lane_queue_frames = _config_int(raw, "lane_queue_frames", DEFAULT_LANE_QUEUE_FRAMES, 1, 1024)
    tcp_user_timeout_ms = _config_int(raw, "tcp_user_timeout_ms", None, 1, 3_600_000)

    forwards: list[ClientForward] = []
    forwards_raw = raw.get("forwards", [])
    if forwards_raw is None:
        forwards_raw = []
    if not isinstance(forwards_raw, list):
        raise ValueError("forwards must be a list")
    for index, item in enumerate(forwards_raw):
        if not isinstance(item, dict):
            raise ValueError(f"forwards[{index}] must be an object")
        forwards.append(
            ClientForward(
                listen=_parse_host_port_field(item.get("listen"), f"forwards[{index}].listen", allow_zero=True),
                target=_parse_host_port_field(item.get("target"), f"forwards[{index}].target"),
                name=str(item.get("name", "")),
            )
        )

    socks5: list[Socks5Listener] = []
    socks_raw = raw.get("socks5", [])
    if isinstance(socks_raw, str):
        socks_raw = [{"listen": socks_raw}]
    if socks_raw is None:
        socks_raw = []
    if not isinstance(socks_raw, list):
        raise ValueError("socks5 must be a list or host:port string")
    for index, item in enumerate(socks_raw):
        if not isinstance(item, dict):
            raise ValueError(f"socks5[{index}] must be an object")
        socks5.append(
            Socks5Listener(
                listen=_parse_host_port_field(item.get("listen"), f"socks5[{index}].listen", allow_zero=True),
                name=str(item.get("name", "socks5")),
            )
        )

    if not forwards and not socks5:
        raise ValueError("client config requires forwards or socks5")
    return ClientConfig(
        remotes=remotes,
        forwards=forwards,
        socks5=socks5,
        lanes=int(lanes),
        minimum_lanes=minimum_lanes,
        join_timeout_ms=int(join_timeout_ms),
        chunk_size=int(chunk_size),
        lane_queue_frames=int(lane_queue_frames),
        tcp_user_timeout_ms=tcp_user_timeout_ms,
        token=token,
    )


def _set_socket_options(
    writer: asyncio.StreamWriter,
    *,
    tcp_user_timeout_ms: int | None = None,
) -> None:
    sock = writer.get_extra_info("socket")
    if sock is None:
        return
    with contextlib.suppress(OSError):
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    with contextlib.suppress(OSError):
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
    tcp_user_timeout = getattr(socket, "TCP_USER_TIMEOUT", None)
    if tcp_user_timeout is not None and tcp_user_timeout_ms:
        with contextlib.suppress(OSError):
            sock.setsockopt(socket.IPPROTO_TCP, tcp_user_timeout, int(tcp_user_timeout_ms))


def _supports_write_eof(sink: WritableSink) -> bool:
    can_write_eof = getattr(sink, "can_write_eof", None)
    if callable(can_write_eof):
        with contextlib.suppress(Exception):
            return bool(can_write_eof())
    return False


def _half_close_or_close(sink: WritableSink) -> None:
    write_eof = getattr(sink, "write_eof", None)
    if callable(write_eof) and _supports_write_eof(sink):
        with contextlib.suppress(OSError, RuntimeError):
            write_eof()
            return
    sink.close()


@dataclass
class PendingChunk:
    payload: bytes
    ack: Callable[[], Awaitable[None]]


class OrderedReassembler:
    """Write chunk-indexed DATA frames to a sink only after gaps are filled."""

    def __init__(self, sink: WritableSink) -> None:
        self._sink = sink
        self._next_seq = 0
        self._pending: dict[int, PendingChunk] = {}
        self._eof_seq: int | None = None
        self._closed = False

    @property
    def closed(self) -> bool:
        return self._closed

    @property
    def pending_chunks(self) -> int:
        return len(self._pending)

    async def feed_data(self, seq: int, payload: bytes, ack: Callable[[], Awaitable[None]] | None = None) -> None:
        if self._closed:
            return
        if seq < self._next_seq:
            return
        previous = self._pending.get(seq)
        if previous is not None:
            if previous.payload != payload:
                raise ValueError(f"conflicting duplicate DATA frame for seq={seq}")
            return
        async def noop_ack() -> None:
            return None

        self._pending[seq] = PendingChunk(payload=payload, ack=ack or noop_ack)
        await self._flush()

    async def feed_eof(self, seq: int) -> None:
        if self._closed:
            return
        if self._eof_seq is None or seq < self._eof_seq:
            self._eof_seq = seq
        await self._flush()

    async def _flush(self) -> None:
        while self._next_seq in self._pending:
            chunk = self._pending.pop(self._next_seq)
            self._sink.write(chunk.payload)
            await self._sink.drain()
            await chunk.ack()
            self._next_seq += 1
        if self._eof_seq == self._next_seq:
            _half_close_or_close(self._sink)
            self._closed = True


@dataclass
class Lane:
    lane_id: int
    reader: asyncio.StreamReader
    writer: asyncio.StreamWriter
    lock: asyncio.Lock = field(default_factory=asyncio.Lock)
    bytes_sent: int = 0
    bytes_received: int = 0
    frames_sent: int = 0
    frames_received: int = 0

    async def write_frame(self, frame_type: int, seq: int, payload: bytes = b"") -> None:
        if len(payload) > MAX_FRAME_BYTES:
            raise ValueError(f"frame too large: {len(payload)}")
        async with self.lock:
            self.writer.write(HEADER.pack(frame_type, seq, len(payload)))
            if payload:
                self.writer.write(payload)
            await self.writer.drain()
            self.frames_sent += 1
            self.bytes_sent += len(payload)

    async def read_frame(self) -> tuple[int, int, bytes]:
        header = await self.reader.readexactly(HEADER.size)
        frame_type, seq, length = HEADER.unpack(header)
        if frame_type not in (TYPE_DATA, TYPE_EOF, TYPE_ACK):
            raise ValueError(f"unknown frame type {frame_type}")
        if length > MAX_FRAME_BYTES:
            raise ValueError(f"frame too large: {length}")
        if frame_type == TYPE_EOF and length != 0:
            raise ValueError("EOF frame must not carry payload")
        if frame_type == TYPE_ACK and length != ACK_PAYLOAD.size:
            raise ValueError("ACK frame must carry a 4-byte payload length")
        payload = await self.reader.readexactly(length) if length else b""
        self.frames_received += 1
        self.bytes_received += len(payload)
        return frame_type, seq, payload

    def close(self) -> None:
        self.writer.close()

    async def wait_closed(self) -> None:
        with contextlib.suppress(Exception):
            await self.writer.wait_closed()


@dataclass
class OutgoingFrame:
    frame_type: int
    seq: int
    payload: bytes = b""


class TunnelEndpoint:
    """Bidirectional local stream <-> striped lane tunnel."""

    def __init__(
        self,
        *,
        session_id: str,
        local_reader: asyncio.StreamReader,
        local_writer: asyncio.StreamWriter,
        lanes: list[Lane],
        chunk_size: int,
        label: str,
        lane_queue_frames: int = DEFAULT_LANE_QUEUE_FRAMES,
    ) -> None:
        if not lanes:
            raise ValueError("at least one lane is required")
        if chunk_size <= 0 or chunk_size > MAX_FRAME_BYTES:
            raise ValueError(f"chunk_size must be 1..{MAX_FRAME_BYTES}")
        self.session_id = session_id
        self.local_reader = local_reader
        self.local_writer = local_writer
        self.lanes = lanes
        self.chunk_size = chunk_size
        self.lane_queue_frames = lane_queue_frames
        self.label = label
        self._incoming_done = asyncio.Event()
        self._outgoing_done = asyncio.Event()
        self._send_seq = 0
        self._lane_queues = [asyncio.Queue[OutgoingFrame](maxsize=lane_queue_frames) for _ in lanes]
        self._lane_queued_bytes = [0 for _ in lanes]
        self._lane_inflight_bytes = [0 for _ in lanes]
        self._lane_enqueued_frames = [0 for _ in lanes]
        self._lane_window_bytes = max(chunk_size, lane_queue_frames * chunk_size)
        self._window_condition = asyncio.Condition()
        self._start = time.monotonic()

    async def run(self) -> None:
        _set_socket_options(self.local_writer)
        reassembler = OrderedReassembler(self.local_writer)
        tasks = [
            *[
                asyncio.create_task(
                    self._lane_sender(index, lane),
                    name=f"{self.label}:lane_{lane.lane_id}_sender",
                )
                for index, lane in enumerate(self.lanes)
            ],
            asyncio.create_task(self._local_to_lanes(), name=f"{self.label}:local_to_lanes"),
            *[
                asyncio.create_task(
                    self._lane_to_local(index, lane, reassembler),
                    name=f"{self.label}:lane_{lane.lane_id}_to_local",
                )
                for index, lane in enumerate(self.lanes)
            ],
        ]
        active_tasks = set(tasks)
        try:
            while active_tasks:
                if self._incoming_done.is_set() and self._outgoing_done.is_set():
                    return
                done, _ = await asyncio.wait(active_tasks, timeout=0.1, return_when=asyncio.FIRST_EXCEPTION)
                for task in done:
                    active_tasks.discard(task)
                    exc = task.exception()
                    if exc is not None:
                        raise exc
        except (asyncio.IncompleteReadError, ConnectionError, OSError) as exc:
            LOG.debug("%s session %s ended by connection close: %s", self.label, self.session_id, exc)
        finally:
            for task in tasks:
                task.cancel()
            await asyncio.gather(*tasks, return_exceptions=True)
            self.local_writer.close()
            with contextlib.suppress(Exception):
                await self.local_writer.wait_closed()
            for lane in self.lanes:
                lane.close()
            await asyncio.gather(*(lane.wait_closed() for lane in self.lanes), return_exceptions=True)
            elapsed = max(time.monotonic() - self._start, 0.001)
            sent = sum(lane.bytes_sent for lane in self.lanes)
            received = sum(lane.bytes_received for lane in self.lanes)
            LOG.info(
                "%s session %s closed after %.3fs sent=%dB received=%dB rate_sent=%.2fMbps rate_received=%.2fMbps",
                self.label,
                self.session_id,
                elapsed,
                sent,
                received,
                sent * 8 / elapsed / 1_000_000,
                received * 8 / elapsed / 1_000_000,
            )

    async def _local_to_lanes(self) -> None:
        try:
            while True:
                payload = await self.local_reader.read(self.chunk_size)
                if not payload:
                    eof_seq = self._send_seq
                    await asyncio.gather(
                        *[
                            self._enqueue_frame(index, OutgoingFrame(TYPE_EOF, eof_seq))
                            for index in range(len(self.lanes))
                        ]
                    )
                    await asyncio.gather(*(queue.join() for queue in self._lane_queues))
                    self._outgoing_done.set()
                    return
                seq = self._send_seq
                self._send_seq += 1
                await self._enqueue_data_frame(OutgoingFrame(TYPE_DATA, seq, payload))
        except Exception:
            self._outgoing_done.set()
            raise

    def _select_send_lane(self) -> int:
        return min(
            range(len(self.lanes)),
            key=lambda index: (
                self._lane_inflight_bytes[index] + self._lane_queued_bytes[index],
                self._lane_inflight_bytes[index],
                self._lane_queued_bytes[index],
                self._lane_queues[index].qsize(),
                self._lane_enqueued_frames[index],
            ),
        )

    async def _reserve_data_lane(self, payload_len: int) -> int:
        async with self._window_condition:
            while True:
                lane_index = self._select_send_lane()
                projected = (
                    self._lane_inflight_bytes[lane_index]
                    + self._lane_queued_bytes[lane_index]
                    + payload_len
                )
                if projected <= self._lane_window_bytes:
                    self._lane_queued_bytes[lane_index] += payload_len
                    self._lane_enqueued_frames[lane_index] += 1
                    return lane_index
                await self._window_condition.wait()

    async def _release_data_reservation(self, lane_index: int, payload_len: int) -> None:
        async with self._window_condition:
            self._lane_queued_bytes[lane_index] = max(0, self._lane_queued_bytes[lane_index] - payload_len)
            self._window_condition.notify_all()

    async def _enqueue_data_frame(self, frame: OutgoingFrame) -> None:
        payload_len = len(frame.payload)
        lane_index = await self._reserve_data_lane(payload_len)
        try:
            await self._lane_queues[lane_index].put(frame)
        except Exception:
            await self._release_data_reservation(lane_index, payload_len)
            raise

    async def _enqueue_frame(self, lane_index: int, frame: OutgoingFrame) -> None:
        self._lane_enqueued_frames[lane_index] += 1
        try:
            await self._lane_queues[lane_index].put(frame)
        except Exception:
            raise

    async def _lane_sender(self, lane_index: int, lane: Lane) -> None:
        queue = self._lane_queues[lane_index]
        while True:
            frame = await queue.get()
            wrote = False
            try:
                if frame.frame_type == TYPE_DATA:
                    async with self._window_condition:
                        self._lane_queued_bytes[lane_index] = max(
                            0,
                            self._lane_queued_bytes[lane_index] - len(frame.payload),
                        )
                        self._lane_inflight_bytes[lane_index] += len(frame.payload)
                        self._window_condition.notify_all()
                await lane.write_frame(frame.frame_type, frame.seq, frame.payload)
                wrote = True
            finally:
                if frame.frame_type == TYPE_DATA and not wrote:
                    async with self._window_condition:
                        self._lane_inflight_bytes[lane_index] = max(
                            0,
                            self._lane_inflight_bytes[lane_index] - len(frame.payload),
                        )
                        self._window_condition.notify_all()
                queue.task_done()

    async def _mark_acked(self, lane_index: int, payload_len: int) -> None:
        async with self._window_condition:
            self._lane_inflight_bytes[lane_index] = max(0, self._lane_inflight_bytes[lane_index] - payload_len)
            self._window_condition.notify_all()

    async def _lane_to_local(self, lane_index: int, lane: Lane, reassembler: OrderedReassembler) -> None:
        while True:
            frame_type, seq, payload = await lane.read_frame()
            if frame_type == TYPE_DATA:
                async def ack_current(length: int = len(payload), ack_seq: int = seq, ack_lane: Lane = lane) -> None:
                    await ack_lane.write_frame(TYPE_ACK, ack_seq, ACK_PAYLOAD.pack(length))

                await reassembler.feed_data(seq, payload, ack_current)
                if reassembler.closed:
                    self._incoming_done.set()
                    return
            elif frame_type == TYPE_EOF:
                await reassembler.feed_eof(seq)
                if reassembler.closed:
                    self._incoming_done.set()
                return
            elif frame_type == TYPE_ACK:
                await self._mark_acked(lane_index, ACK_PAYLOAD.unpack(payload)[0])


@dataclass
class PendingSession:
    session_id: str
    lanes_expected: int
    minimum_lanes: int
    join_timeout_ms: int
    target_host: str
    target_port: int
    chunk_size: int
    lane_queue_frames: int
    lanes: list[Lane] = field(default_factory=list)
    started: bool = False
    created_at: float = field(default_factory=time.monotonic)
    start_task: asyncio.Task | None = None


class AccelServerState:
    def __init__(
        self,
        *,
        target_host: str | None,
        target_port: int | None,
        tcp_user_timeout_ms: int | None = None,
        token: str | None = None,
    ) -> None:
        self.target_host = target_host
        self.target_port = target_port
        self.tcp_user_timeout_ms = tcp_user_timeout_ms
        self.token = token
        self.pending: dict[str, PendingSession] = {}
        self.started_sessions: set[str] = set()
        self._lock = asyncio.Lock()

    async def handle_lane(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        _set_socket_options(writer, tcp_user_timeout_ms=self.tcp_user_timeout_ms)
        try:
            raw = await asyncio.wait_for(reader.readline(), timeout=10)
            try:
                hello = json.loads(raw.decode("utf-8"))
            except Exception as exc:
                LOG.warning("rejecting lane bad hello prefix=%r bytes=%d error=%s", raw[:32], len(raw), exc)
                raise ValueError("bad hello") from exc
            if self.token is not None and not secrets.compare_digest(str(hello.get("token", "")), self.token):
                raise ValueError("bad token")
            session_id = str(hello["session"])
            lane_id = int(hello["lane"])
            lanes_expected = int(hello["lanes"])
            minimum_lanes = int(hello.get("minimum_lanes", lanes_expected))
            join_timeout_ms = int(hello.get("join_timeout_ms", 1200))
            chunk_size = int(hello.get("chunk_size", DEFAULT_CHUNK_SIZE))
            lane_queue_frames = int(hello.get("lane_queue_frames", DEFAULT_LANE_QUEUE_FRAMES))
            target_host = self.target_host or str(hello["target_host"])
            target_port = self.target_port or int(hello["target_port"])
            if lanes_expected < 1 or lanes_expected > 64:
                raise ValueError("lanes must be 1..64")
            if minimum_lanes < 1 or minimum_lanes > lanes_expected:
                raise ValueError("minimum_lanes must be 1..lanes")
            if join_timeout_ms < 0 or join_timeout_ms > 30_000:
                raise ValueError("join_timeout_ms must be 0..30000")
            if not 1 <= chunk_size <= MAX_FRAME_BYTES:
                raise ValueError(f"chunk_size must be 1..{MAX_FRAME_BYTES}")
            if not 1 <= lane_queue_frames <= 1024:
                raise ValueError("lane_queue_frames must be 1..1024")
            lane = Lane(lane_id=lane_id, reader=reader, writer=writer)

            should_start: PendingSession | None = None
            async with self._lock:
                pending = self.pending.get(session_id)
                if pending is None:
                    if session_id in self.started_sessions:
                        raise ValueError(f"session already started: {session_id}")
                    pending = PendingSession(
                        session_id=session_id,
                        lanes_expected=lanes_expected,
                        minimum_lanes=minimum_lanes,
                        join_timeout_ms=join_timeout_ms,
                        target_host=target_host,
                        target_port=target_port,
                        chunk_size=chunk_size,
                        lane_queue_frames=lane_queue_frames,
                    )
                    self.pending[session_id] = pending
                    pending.start_task = asyncio.create_task(
                        self._start_after_timeout(session_id, join_timeout_ms / 1000),
                        name=f"server-session-timeout:{session_id}",
                    )
                if pending.started:
                    raise ValueError(f"session already started: {session_id}")
                if pending.lanes_expected != lanes_expected:
                    raise ValueError("inconsistent lane count")
                if pending.minimum_lanes != minimum_lanes:
                    raise ValueError("inconsistent minimum lane count")
                if pending.target_host != target_host or pending.target_port != target_port:
                    raise ValueError("inconsistent target")
                if pending.chunk_size != chunk_size or pending.lane_queue_frames != lane_queue_frames:
                    raise ValueError("inconsistent chunk or queue settings")
                if any(existing.lane_id == lane_id for existing in pending.lanes):
                    raise ValueError(f"duplicate lane {lane_id}")
                pending.lanes.append(lane)
                writer.write(b"OK\n")
                await writer.drain()
                if len(pending.lanes) == pending.lanes_expected:
                    pending.started = True
                    self.pending.pop(session_id, None)
                    self.started_sessions.add(session_id)
                    pending.lanes.sort(key=lambda item: item.lane_id)
                    if pending.start_task is not None:
                        pending.start_task.cancel()
                    should_start = pending

            if should_start is not None:
                asyncio.create_task(self._run_ready_session(should_start), name=f"server-session:{session_id}")
        except Exception as exc:
            LOG.warning("rejecting lane connection: %s", exc)
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def _start_after_timeout(self, session_id: str, delay: float) -> None:
        await asyncio.sleep(delay)
        should_start: PendingSession | None = None
        async with self._lock:
            pending = self.pending.get(session_id)
            if pending is not None and not pending.started and len(pending.lanes) >= pending.minimum_lanes:
                pending.started = True
                self.pending.pop(session_id, None)
                self.started_sessions.add(session_id)
                pending.lanes.sort(key=lambda item: item.lane_id)
                should_start = pending
        if should_start is not None:
            LOG.info(
                "server session %s starting with %d/%d lanes after join timeout",
                should_start.session_id,
                len(should_start.lanes),
                should_start.lanes_expected,
            )
            asyncio.create_task(self._run_ready_session(should_start), name=f"server-session:{session_id}")

    async def _run_ready_session(self, pending: PendingSession) -> None:
        LOG.info(
            "server session %s starting lanes=%d target=%s:%d",
            pending.session_id,
            len(pending.lanes),
            pending.target_host,
            pending.target_port,
        )
        target_reader: asyncio.StreamReader | None = None
        target_writer: asyncio.StreamWriter | None = None
        try:
            target_reader, target_writer = await asyncio.open_connection(pending.target_host, pending.target_port)
            _set_socket_options(target_writer, tcp_user_timeout_ms=self.tcp_user_timeout_ms)
            endpoint = TunnelEndpoint(
                session_id=pending.session_id,
                local_reader=target_reader,
                local_writer=target_writer,
                lanes=pending.lanes,
                chunk_size=pending.chunk_size,
                label="server",
                lane_queue_frames=pending.lane_queue_frames,
            )
            await endpoint.run()
        except Exception as exc:
            LOG.warning("server session %s failed: %s", pending.session_id, exc)
            for lane in pending.lanes:
                lane.close()
            if target_writer is not None:
                target_writer.close()


async def start_accel_server(
    *,
    listen_host: str,
    listen_port: int,
    target_host: str | None = None,
    target_port: int | None = None,
    tcp_user_timeout_ms: int | None = None,
    token: str | None = None,
) -> asyncio.AbstractServer:
    state = AccelServerState(
        target_host=target_host,
        target_port=target_port,
        tcp_user_timeout_ms=tcp_user_timeout_ms,
        token=token,
    )
    return await asyncio.start_server(state.handle_lane, listen_host, listen_port)


async def _connect_lane(
    *,
    session_id: str,
    lane_id: int,
    lanes: int,
    minimum_lanes: int,
    join_timeout_ms: int,
    remote: tuple[str, int],
    target_host: str,
    target_port: int,
    chunk_size: int,
    lane_queue_frames: int,
    tcp_user_timeout_ms: int | None,
    token: str | None,
) -> Lane:
    reader, writer = await asyncio.open_connection(remote[0], remote[1])
    _set_socket_options(writer, tcp_user_timeout_ms=tcp_user_timeout_ms)
    hello = {
        "version": 1,
        "session": session_id,
        "lane": lane_id,
        "lanes": lanes,
        "minimum_lanes": minimum_lanes,
        "join_timeout_ms": join_timeout_ms,
        "target_host": target_host,
        "target_port": target_port,
        "chunk_size": chunk_size,
        "lane_queue_frames": lane_queue_frames,
    }
    if token is not None:
        hello["token"] = token
    writer.write((json.dumps(hello, separators=(",", ":")) + "\n").encode("utf-8"))
    await writer.drain()
    ack = await asyncio.wait_for(reader.readline(), timeout=max(1.0, join_timeout_ms / 1000 + 1.0))
    if ack != b"OK\n":
        writer.close()
        with contextlib.suppress(Exception):
            await writer.wait_closed()
        raise ConnectionError(f"lane {lane_id} did not receive hello ack")
    return Lane(lane_id=lane_id, reader=reader, writer=writer)


def _round_robin_remotes(remotes: Iterable[tuple[str, int]], count: int) -> list[tuple[str, int]]:
    items = list(remotes)
    if not items:
        raise ValueError("at least one remote is required")
    return [items[index % len(items)] for index in range(count)]


async def run_accel_client_session(
    *,
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    target_host: str,
    target_port: int,
    lane_remotes: list[tuple[str, int]],
    lanes: int,
    minimum_lanes: int,
    join_timeout_ms: int,
    chunk_size: int,
    lane_queue_frames: int,
    tcp_user_timeout_ms: int | None,
    token: str | None,
    label: str = "client",
) -> None:
    _set_socket_options(writer, tcp_user_timeout_ms=tcp_user_timeout_ms)
    session_id = secrets.token_hex(16)
    started = time.monotonic()
    lane_objs: list[Lane] = []
    try:
        lane_results = await asyncio.gather(
            *[
                _connect_lane(
                    session_id=session_id,
                    lane_id=lane_id,
                    lanes=lanes,
                    minimum_lanes=minimum_lanes,
                    join_timeout_ms=join_timeout_ms,
                    remote=lane_remotes[lane_id],
                    target_host=target_host,
                    target_port=target_port,
                    chunk_size=chunk_size,
                    lane_queue_frames=lane_queue_frames,
                    tcp_user_timeout_ms=tcp_user_timeout_ms,
                    token=token,
                )
                for lane_id in range(lanes)
            ],
            return_exceptions=True,
        )
        lane_objs = [item for item in lane_results if isinstance(item, Lane)]
        for item in lane_results:
            if isinstance(item, Exception):
                LOG.warning("%s session %s dropped candidate lane: %s", label, session_id, item)
        if len(lane_objs) < minimum_lanes:
            raise ConnectionError(f"only {len(lane_objs)}/{lanes} lanes connected, minimum={minimum_lanes}")
        LOG.info(
            "%s session %s starting lanes=%d/%d target=%s:%d connect_ms=%.1f",
            label,
            session_id,
            len(lane_objs),
            lanes,
            target_host,
            target_port,
            (time.monotonic() - started) * 1000,
        )
        endpoint = TunnelEndpoint(
            session_id=session_id,
            local_reader=reader,
            local_writer=writer,
            lanes=lane_objs,
            chunk_size=chunk_size,
            label=label,
            lane_queue_frames=lane_queue_frames,
        )
        await endpoint.run()
    except Exception as exc:
        LOG.warning("%s session %s failed: %s", label, session_id, exc)
        for lane in lane_objs:
            lane.close()
        writer.close()
        with contextlib.suppress(Exception):
            await writer.wait_closed()


async def start_accel_client(
    *,
    listen_host: str,
    listen_port: int,
    remote_host: str,
    remote_port: int,
    target_host: str,
    target_port: int,
    lanes: int = DEFAULT_LANES,
    minimum_lanes: int | None = None,
    join_timeout_ms: int = 1200,
    chunk_size: int = DEFAULT_CHUNK_SIZE,
    lane_queue_frames: int = DEFAULT_LANE_QUEUE_FRAMES,
    tcp_user_timeout_ms: int | None = None,
    remotes: list[tuple[str, int]] | None = None,
    token: str | None = None,
) -> asyncio.AbstractServer:
    if lanes < 1 or lanes > 64:
        raise ValueError("lanes must be 1..64")
    if minimum_lanes is None:
        minimum_lanes = lanes
    if minimum_lanes < 1 or minimum_lanes > lanes:
        raise ValueError("minimum_lanes must be 1..lanes")
    if join_timeout_ms < 0 or join_timeout_ms > 30_000:
        raise ValueError("join_timeout_ms must be 0..30000")
    if not 1 <= chunk_size <= MAX_FRAME_BYTES:
        raise ValueError(f"chunk_size must be 1..{MAX_FRAME_BYTES}")
    lane_remotes = _round_robin_remotes(remotes or [(remote_host, remote_port)], lanes)

    async def handle_local(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        await run_accel_client_session(
            reader=reader,
            writer=writer,
            target_host=target_host,
            target_port=target_port,
            lane_remotes=lane_remotes,
            lanes=lanes,
            minimum_lanes=minimum_lanes,
            join_timeout_ms=join_timeout_ms,
            chunk_size=chunk_size,
            lane_queue_frames=lane_queue_frames,
            tcp_user_timeout_ms=tcp_user_timeout_ms,
            token=token,
        )

    return await asyncio.start_server(handle_local, listen_host, listen_port)


async def _read_socks5_target(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> tuple[str, int] | None:
    try:
        header = await reader.readexactly(2)
        if header[0] != 5:
            return None
        methods = await reader.readexactly(header[1])
        if 0 not in methods:
            writer.write(b"\x05\xff")
            await writer.drain()
            return None
        writer.write(b"\x05\x00")
        await writer.drain()

        request = await reader.readexactly(4)
        version, command, _reserved, atyp = request
        if version != 5 or command != 1:
            writer.write(b"\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00")
            await writer.drain()
            return None
        if atyp == 1:
            host = str(ipaddress.ip_address(await reader.readexactly(4)))
        elif atyp == 3:
            length = (await reader.readexactly(1))[0]
            host = (await reader.readexactly(length)).decode("idna")
        elif atyp == 4:
            host = str(ipaddress.ip_address(await reader.readexactly(16)))
        else:
            writer.write(b"\x05\x08\x00\x01\x00\x00\x00\x00\x00\x00")
            await writer.drain()
            return None
        port = int.from_bytes(await reader.readexactly(2), "big")
        if not 1 <= port <= 65535:
            writer.write(b"\x05\x04\x00\x01\x00\x00\x00\x00\x00\x00")
            await writer.drain()
            return None
        writer.write(b"\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00")
        await writer.drain()
        return host, port
    except (asyncio.IncompleteReadError, OSError, UnicodeError):
        return None


async def start_socks5_client(
    *,
    listen_host: str,
    listen_port: int,
    remotes: list[tuple[str, int]],
    lanes: int = DEFAULT_LANES,
    minimum_lanes: int | None = None,
    join_timeout_ms: int = 1200,
    chunk_size: int = DEFAULT_CHUNK_SIZE,
    lane_queue_frames: int = DEFAULT_LANE_QUEUE_FRAMES,
    tcp_user_timeout_ms: int | None = None,
    token: str | None = None,
) -> asyncio.AbstractServer:
    if lanes < 1 or lanes > 64:
        raise ValueError("lanes must be 1..64")
    if minimum_lanes is None:
        minimum_lanes = lanes
    if minimum_lanes < 1 or minimum_lanes > lanes:
        raise ValueError("minimum_lanes must be 1..lanes")
    lane_remotes = _round_robin_remotes(remotes, lanes)

    async def handle_socks(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        target = await _read_socks5_target(reader, writer)
        if target is None:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()
            return
        await run_accel_client_session(
            reader=reader,
            writer=writer,
            target_host=target[0],
            target_port=target[1],
            lane_remotes=lane_remotes,
            lanes=lanes,
            minimum_lanes=minimum_lanes,
            join_timeout_ms=join_timeout_ms,
            chunk_size=chunk_size,
            lane_queue_frames=lane_queue_frames,
            tcp_user_timeout_ms=tcp_user_timeout_ms,
            token=token,
            label="socks5-client",
        )

    return await asyncio.start_server(handle_socks, listen_host, listen_port)


async def start_client_from_config(config: ClientConfig) -> list[asyncio.AbstractServer]:
    servers: list[asyncio.AbstractServer] = []
    try:
        for forward in config.forwards:
            servers.append(
                await start_accel_client(
                    listen_host=forward.listen[0],
                    listen_port=forward.listen[1],
                    remote_host=config.remotes[0][0],
                    remote_port=config.remotes[0][1],
                    target_host=forward.target[0],
                    target_port=forward.target[1],
                    lanes=config.lanes,
                    minimum_lanes=config.minimum_lanes,
                    join_timeout_ms=config.join_timeout_ms,
                    chunk_size=config.chunk_size,
                    lane_queue_frames=config.lane_queue_frames,
                    tcp_user_timeout_ms=config.tcp_user_timeout_ms,
                    remotes=config.remotes,
                    token=config.token,
                )
            )
        for socks in config.socks5:
            servers.append(
                await start_socks5_client(
                    listen_host=socks.listen[0],
                    listen_port=socks.listen[1],
                    remotes=config.remotes,
                    lanes=config.lanes,
                    minimum_lanes=config.minimum_lanes,
                    join_timeout_ms=config.join_timeout_ms,
                    chunk_size=config.chunk_size,
                    lane_queue_frames=config.lane_queue_frames,
                    tcp_user_timeout_ms=config.tcp_user_timeout_ms,
                    token=config.token,
                )
            )
    except Exception:
        for server in servers:
            server.close()
            with contextlib.suppress(Exception):
                await server.wait_closed()
        raise
    return servers


async def _serve_forever(server: asyncio.AbstractServer) -> None:
    sockets = ", ".join(f"{sock.getsockname()[0]}:{sock.getsockname()[1]}" for sock in (server.sockets or []))
    LOG.info("listening on %s", sockets)
    async with server:
        await server.serve_forever()


async def _serve_many_forever(servers: list[asyncio.AbstractServer]) -> None:
    sockets = []
    for server in servers:
        sockets.extend(f"{sock.getsockname()[0]}:{sock.getsockname()[1]}" for sock in (server.sockets or []))
    LOG.info("listening on %s", ", ".join(sockets))
    try:
        await asyncio.gather(*(server.serve_forever() for server in servers))
    finally:
        for server in servers:
            server.close()
        await asyncio.gather(*(server.wait_closed() for server in servers), return_exceptions=True)


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="split-TCP multi-lane accelerator")
    parser.add_argument("--log-level", default="INFO", choices=["DEBUG", "INFO", "WARNING", "ERROR"])
    sub = parser.add_subparsers(dest="mode", required=True)

    server = sub.add_parser("server", help="accept accelerator lane connections")
    server.add_argument("--listen", required=True, type=parse_host_port, help="lane listener host:port")
    server.add_argument("--target", type=parse_host_port, help="optional fixed target host:port")
    server.add_argument("--tcp-user-timeout-ms", type=int, default=None)
    server.add_argument("--token", help="optional shared lane authentication token")
    server.add_argument("--token-file", help="read shared lane authentication token from file")

    client = sub.add_parser("client", help="listen for local TCP and stripe over lanes")
    client.add_argument("--listen", required=True, type=parse_host_port, help="local TCP listener host:port")
    client.add_argument("--remote", required=True, action="append", type=parse_host_port, help="accelerator server host:port; repeat for per-line remotes")
    client.add_argument("--target", required=True, type=parse_host_port, help="real target host:port")
    client.add_argument("--lanes", type=int, default=DEFAULT_LANES)
    client.add_argument("--minimum-lanes", type=int, default=None)
    client.add_argument("--join-timeout-ms", type=int, default=1200)
    client.add_argument("--chunk-size", type=int, default=DEFAULT_CHUNK_SIZE)
    client.add_argument("--lane-queue-frames", type=int, default=DEFAULT_LANE_QUEUE_FRAMES)
    client.add_argument("--tcp-user-timeout-ms", type=int, default=None)
    client.add_argument("--token", help="optional shared lane authentication token")
    client.add_argument("--token-file", help="read shared lane authentication token from file")

    client_config = sub.add_parser("client-config", help="start multiple forwards and SOCKS5 listeners from JSON config")
    client_config.add_argument("--config", required=True, help="client JSON config path")

    check_client_config = sub.add_parser("check-client-config", help="validate a client JSON config and print a summary")
    check_client_config.add_argument("--config", required=True, help="client JSON config path")
    return parser


def _load_token(token: str | None, token_file: str | None) -> str | None:
    if token and token_file:
        raise ValueError("use either --token or --token-file, not both")
    if token_file:
        with open(token_file, "r", encoding="utf-8") as fh:
            loaded = fh.read().strip()
        if not loaded:
            raise ValueError("token file is empty")
        return loaded
    return token


async def async_main(argv: list[str]) -> int:
    parser = build_arg_parser()
    args = parser.parse_args(argv)
    logging.basicConfig(
        level=getattr(logging, args.log_level),
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    if args.mode == "server":
        token = _load_token(args.token, args.token_file)
        target_host = target_port = None
        if args.target:
            target_host, target_port = args.target
        server = await start_accel_server(
            listen_host=args.listen[0],
            listen_port=args.listen[1],
            target_host=target_host,
            target_port=target_port,
            tcp_user_timeout_ms=args.tcp_user_timeout_ms,
            token=token,
        )
        await _serve_forever(server)
    elif args.mode == "client":
        token = _load_token(args.token, args.token_file)
        server = await start_accel_client(
            listen_host=args.listen[0],
            listen_port=args.listen[1],
            remote_host=args.remote[0][0],
            remote_port=args.remote[0][1],
            target_host=args.target[0],
            target_port=args.target[1],
            lanes=args.lanes,
            minimum_lanes=args.minimum_lanes,
            join_timeout_ms=args.join_timeout_ms,
            chunk_size=args.chunk_size,
            lane_queue_frames=args.lane_queue_frames,
            tcp_user_timeout_ms=args.tcp_user_timeout_ms,
            remotes=args.remote,
            token=token,
        )
        await _serve_forever(server)
    elif args.mode == "client-config":
        config = load_client_config(args.config)
        servers = await start_client_from_config(config)
        await _serve_many_forever(servers)
    elif args.mode == "check-client-config":
        config = load_client_config(args.config)
        print(
            json.dumps(
                {
                    "ok": True,
                    "remotes": [f"{host}:{port}" for host, port in config.remotes],
                    "lanes": config.lanes,
                    "minimum_lanes": config.minimum_lanes,
                    "forwards": [
                        {
                            "name": forward.name,
                            "listen": f"{forward.listen[0]}:{forward.listen[1]}",
                            "target": f"{forward.target[0]}:{forward.target[1]}",
                        }
                        for forward in config.forwards
                    ],
                    "socks5": [
                        {"name": socks.name, "listen": f"{socks.listen[0]}:{socks.listen[1]}"}
                        for socks in config.socks5
                    ],
                },
                ensure_ascii=False,
            )
        )
    return 0


def main() -> int:
    try:
        return asyncio.run(async_main(sys.argv[1:]))
    except KeyboardInterrupt:
        return 130
    except (OSError, ValueError, argparse.ArgumentTypeError, json.JSONDecodeError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
