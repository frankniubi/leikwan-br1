#!/usr/bin/env python3
"""Operator-owned external UoT sidecar.

This runtime is intentionally opt-in.  It exposes a small, authenticated UDP
over-TCP endpoint for an Ultra client and does not alter the existing sidecar
or UoT runtimes.

Wire format::

    uint32be frame_length (header + payload)
    b"UOT2" uint8 version uint8 type uint16 flags
    uint128 session_id uint64 sequence uint32 payload_length
    payload

One session owns one connected UDP socket.  A carrier reconnect with the same
session id reuses that socket during the grace period, preserving the
target-visible source port.  DATA and UDP_DATA are independent directions;
the implementation never waits for a response before forwarding the next
datagram.
"""

from __future__ import annotations

import argparse
import asyncio
import base64
from dataclasses import dataclass, field
import hashlib
import hmac
import ipaddress
import json
import logging
import os
import socket
import struct
import time
from typing import Any, Mapping, Sequence


MAGIC = b"UOT2"
VERSION = 1
SESSION_ID_BYTES = 16
MAX_DATAGRAM = 65507
HEADER = struct.Struct("!4sBBH16sQI")
PREFIX = struct.Struct("!I")
HEADER_BYTES = HEADER.size

HELLO = 1
HELLO_ACK = 2
DATA = 3
UDP_DATA = 4
CLOSE = 5
KEEPALIVE = 6

MIN_FRAME_PAYLOAD = 256
DEFAULT_FRAME_PAYLOAD = 1200
DEFAULT_MAX_FRAME_BYTES = HEADER_BYTES + MAX_DATAGRAM
DEFAULT_QUEUE_PACKETS = 256
DEFAULT_KEEPALIVE_SECONDS = 10.0
DEFAULT_IDLE_TIMEOUT_SECONDS = 90.0
DEFAULT_SESSION_GRACE_SECONDS = 30.0
DEFAULT_SESSION_LIMIT = 1024


class ProtocolError(ValueError):
    """Raised for malformed or policy-invalid UoT frames."""


def _json_bytes(value: Mapping[str, Any]) -> bytes:
    return json.dumps(value, separators=(",", ":"), sort_keys=True).encode("utf-8")


def _json_object(payload: bytes) -> dict[str, Any]:
    try:
        value = json.loads(payload.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ProtocolError("JSON payload required") from exc
    if not isinstance(value, dict):
        raise ProtocolError("JSON object required")
    return value


def normalize_target(value: str) -> str:
    """Normalize an explicit host:port binding without resolving DNS."""

    text = str(value or "").strip()
    if text.startswith("["):
        end = text.find("]")
        if end < 0 or end + 1 >= len(text) or text[end + 1] != ":":
            raise ValueError("target must be host:port")
        host = text[1:end]
        port_text = text[end + 2 :]
    else:
        host, separator, port_text = text.rpartition(":")
        if not separator:
            raise ValueError("target must be host:port")
    host = host.strip().lower()
    if not host or any(ch.isspace() for ch in host):
        raise ValueError("target host is invalid")
    try:
        port = int(port_text)
    except (TypeError, ValueError) as exc:
        raise ValueError("target port is invalid") from exc
    if not 1 <= port <= 65535:
        raise ValueError("target port is invalid")
    try:
        host = ipaddress.ip_address(host).compressed
    except ValueError:
        pass
    return f"{host}:{port}"


def _target_parts(value: str) -> tuple[str, int]:
    canonical = normalize_target(value)
    host, port_text = canonical.rsplit(":", 1)
    return host, int(port_text)


def _binding_message(forward_id: int, target: str) -> bytes:
    return f"{int(forward_id)}\n{normalize_target(target)}".encode("utf-8")


def make_hmac_token(secret: str | bytes, forward_id: int, target: str) -> str:
    """Return the canonical per-forward token expected in HELLO.token."""

    key = secret.encode("utf-8") if isinstance(secret, str) else bytes(secret)
    digest = hmac.new(key, _binding_message(forward_id, target), hashlib.sha256).hexdigest()
    return f"hmac-sha256:{digest}"


@dataclass(frozen=True)
class ForwardBinding:
    """Authentication and destination policy for one forward."""

    forward_id: int
    secret: bytes = b""
    targets: frozenset[str] = frozenset()
    static_token: str = ""

    def allows_target(self, target: str) -> bool:
        try:
            return normalize_target(target) in self.targets
        except ValueError:
            return False

    def verify(self, token: str, target: str) -> bool:
        if not self.allows_target(target):
            return False
        value = str(token or "").strip()
        if self.static_token and hmac.compare_digest(value, self.static_token):
            return True
        if not self.secret:
            return False
        expected_hex = make_hmac_token(self.secret, self.forward_id, target).removeprefix("hmac-sha256:")
        supplied = value.lower().removeprefix("hmac-sha256:")
        if hmac.compare_digest(supplied, expected_hex):
            return True
        try:
            expected_raw = bytes.fromhex(expected_hex)
            supplied_raw = base64.urlsafe_b64decode(supplied + "=" * ((4 - len(supplied) % 4) % 4))
        except (ValueError, base64.binascii.Error):
            return False
        return hmac.compare_digest(supplied_raw, expected_raw)


@dataclass
class AdaptiveTuner:
    """Small bounded AIMD/EMA controller used for the next frame budget.

    TCP itself provides delivery ordering, so the controller does not invent
    packet retransmission.  It only adapts the maximum complete UDP payload
    accepted for a session and the recommended queue capacity.  The current
    session always keeps the negotiated maximum as its hard upper bound.
    """

    frame_payload_max: int = DEFAULT_FRAME_PAYLOAD
    queue_packets: int = DEFAULT_QUEUE_PACKETS
    min_frame_payload: int = MIN_FRAME_PAYLOAD
    max_frame_payload: int = MAX_DATAGRAM
    min_queue_packets: int = 32
    max_queue_packets: int = 4096
    ewma_rtt_ms: float | None = None
    stable_samples: int = 0
    pressure_events: int = 0
    reconnect_events: int = 0

    def __post_init__(self) -> None:
        self.frame_payload_max = max(self.min_frame_payload, min(self.max_frame_payload, int(self.frame_payload_max)))
        self.queue_packets = max(self.min_queue_packets, min(self.max_queue_packets, int(self.queue_packets)))

    def observe_rtt(self, rtt_ms: float) -> None:
        value = max(0.0, float(rtt_ms))
        self.ewma_rtt_ms = value if self.ewma_rtt_ms is None else (0.8 * self.ewma_rtt_ms + 0.2 * value)
        if self.ewma_rtt_ms <= 250.0:
            self.stable_samples += 1
            if self.stable_samples >= 8:
                self.frame_payload_max = min(self.max_frame_payload, self.frame_payload_max + max(64, self.frame_payload_max // 4))
                self.queue_packets = max(self.min_queue_packets, self.queue_packets - max(1, self.queue_packets // 16))
                self.stable_samples = 0
        else:
            self.stable_samples = 0

    def observe_payload(self, payload_bytes: int) -> None:
        size = max(0, int(payload_bytes))
        if size > self.frame_payload_max:
            self.frame_payload_max = min(self.max_frame_payload, max(size, self.frame_payload_max * 2))

    def observe_pressure(self, dropped: int = 1) -> None:
        count = max(1, int(dropped))
        self.pressure_events += count
        self.stable_samples = 0
        self.frame_payload_max = max(self.min_frame_payload, self.frame_payload_max // 2)
        self.queue_packets = min(self.max_queue_packets, max(self.queue_packets + count, self.queue_packets * 2))

    def observe_reconnect(self) -> None:
        self.reconnect_events += 1
        self.stable_samples = 0
        self.frame_payload_max = max(self.min_frame_payload, self.frame_payload_max // 2)
        self.queue_packets = min(self.max_queue_packets, self.queue_packets * 2)


@dataclass(frozen=True)
class SidecarConfig:
    listen_host: str = "0.0.0.0"
    listen_port: int = 0
    forwards: Mapping[int, ForwardBinding] = field(default_factory=dict)
    max_frame_bytes: int = DEFAULT_MAX_FRAME_BYTES
    frame_payload_max: int = MAX_DATAGRAM
    queue_packets: int = DEFAULT_QUEUE_PACKETS
    keepalive_seconds: float = DEFAULT_KEEPALIVE_SECONDS
    idle_timeout_seconds: float = DEFAULT_IDLE_TIMEOUT_SECONDS
    session_grace_seconds: float = DEFAULT_SESSION_GRACE_SECONDS
    session_limit: int = DEFAULT_SESSION_LIMIT
    udp_connect_timeout_seconds: float = 5.0

    def __post_init__(self) -> None:
        if self.max_frame_bytes < HEADER_BYTES + MIN_FRAME_PAYLOAD:
            raise ValueError("max_frame_bytes is too small")
        if not 1 <= self.frame_payload_max <= MAX_DATAGRAM:
            raise ValueError("frame_payload_max is invalid")
        if self.max_frame_bytes < HEADER_BYTES + self.frame_payload_max:
            raise ValueError("max_frame_bytes must include frame_payload_max")
        if self.queue_packets < 1 or self.session_limit < 1:
            raise ValueError("queue and session limits must be positive")
        if self.keepalive_seconds <= 0 or self.idle_timeout_seconds <= 0 or self.session_grace_seconds <= 0:
            raise ValueError("timeouts must be positive")


@dataclass(frozen=True)
class Frame:
    msg_type: int
    session_id: bytes
    seq: int = 0
    payload: bytes = b""
    flags: int = 0
    version: int = VERSION

    def body(self) -> bytes:
        if self.version != VERSION:
            raise ProtocolError("unsupported frame version")
        if self.msg_type not in {HELLO, HELLO_ACK, DATA, UDP_DATA, CLOSE, KEEPALIVE}:
            raise ProtocolError("unknown frame type")
        if len(self.session_id) != SESSION_ID_BYTES:
            raise ProtocolError("session id must be 16 bytes")
        if not 0 <= self.flags <= 0xFFFF or not 0 <= self.seq <= 0xFFFFFFFFFFFFFFFF:
            raise ProtocolError("frame integer out of range")
        if len(self.payload) > 0xFFFFFFFF:
            raise ProtocolError("payload too large")
        return HEADER.pack(MAGIC, self.version, self.msg_type, self.flags, self.session_id, self.seq, len(self.payload)) + self.payload

    def wire(self, max_frame_bytes: int = DEFAULT_MAX_FRAME_BYTES) -> bytes:
        body = self.body()
        if len(body) > max_frame_bytes:
            raise ProtocolError("frame exceeds configured maximum")
        return PREFIX.pack(len(body)) + body

    @classmethod
    def from_body(cls, body: bytes) -> "Frame":
        if len(body) < HEADER_BYTES:
            raise ProtocolError("truncated frame header")
        magic, version, msg_type, flags, session_id, seq, payload_len = HEADER.unpack(body[:HEADER_BYTES])
        if magic != MAGIC or version != VERSION:
            raise ProtocolError("invalid frame magic or version")
        payload = body[HEADER_BYTES:]
        if payload_len != len(payload):
            raise ProtocolError("payload length mismatch")
        if msg_type not in {HELLO, HELLO_ACK, DATA, UDP_DATA, CLOSE, KEEPALIVE}:
            raise ProtocolError("unknown frame type")
        return cls(msg_type=msg_type, session_id=session_id, seq=seq, payload=payload, flags=flags, version=version)


async def read_frame(reader: asyncio.StreamReader, max_frame_bytes: int) -> Frame:
    prefix = await reader.readexactly(PREFIX.size)
    length = PREFIX.unpack(prefix)[0]
    if length < HEADER_BYTES or length > max_frame_bytes:
        raise ProtocolError("invalid frame length")
    return Frame.from_body(await reader.readexactly(length))


async def write_frame(writer: asyncio.StreamWriter, frame: Frame, max_frame_bytes: int) -> None:
    writer.write(frame.wire(max_frame_bytes))
    await writer.drain()


def _hello_payload(frame: Frame) -> dict[str, Any]:
    if frame.msg_type != HELLO:
        raise ProtocolError("HELLO required")
    value = _json_object(frame.payload)
    token = str(value.get("token", "") or "").strip()
    target = str(value.get("target", "") or "").strip()
    if not token or not target:
        raise ProtocolError("HELLO token and target are required")
    try:
        forward_id = int(value["forward_id"])
    except (KeyError, TypeError, ValueError) as exc:
        raise ProtocolError("HELLO forward_id is required") from exc
    desired = value.get("frame_payload_max", DEFAULT_FRAME_PAYLOAD)
    try:
        desired_max = int(desired)
    except (TypeError, ValueError) as exc:
        raise ProtocolError("HELLO frame_payload_max is invalid") from exc
    if desired_max < MIN_FRAME_PAYLOAD or desired_max > MAX_DATAGRAM:
        raise ProtocolError("HELLO frame_payload_max is out of range")
    return {"token": token, "target": normalize_target(target), "forward_id": forward_id, "frame_payload_max": desired_max}


class UoTSession:
    def __init__(self, server: "ExternalUoTServer", session_id: bytes, binding: ForwardBinding, target: str, frame_payload_max: int) -> None:
        self.server = server
        self.session_id = session_id
        self.binding = binding
        self.target = target
        self.frame_payload_max = frame_payload_max
        self.tuner = AdaptiveTuner(frame_payload_max=frame_payload_max, queue_packets=server.config.queue_packets, max_frame_payload=server.config.frame_payload_max)
        self.outbound: asyncio.Queue[Frame] = asyncio.Queue(maxsize=server.config.queue_packets)
        self.udp_sock: socket.socket | None = None
        self.writer: asyncio.StreamWriter | None = None
        self.last_seen = time.monotonic()
        self.detached_at: float | None = None
        self.closed = False
        self.seq = 0
        self.last_client_seq = 0
        self.stats = {"udp_in": 0, "udp_out": 0, "queue_drops": 0, "oversize_drops": 0, "reattaches": 0}
        self._udp_task: asyncio.Task[None] | None = None

    async def open(self) -> None:
        host, port = _target_parts(self.target)
        loop = asyncio.get_running_loop()
        infos = await asyncio.wait_for(loop.getaddrinfo(host, port, type=socket.SOCK_DGRAM), self.server.config.udp_connect_timeout_seconds)
        if not infos:
            raise OSError("target resolution returned no address")
        family, socktype, proto, _canonname, sockaddr = infos[0]
        sock = socket.socket(family, socktype, proto)
        sock.setblocking(False)
        try:
            await asyncio.wait_for(loop.sock_connect(sock, sockaddr), self.server.config.udp_connect_timeout_seconds)
        except BaseException:
            sock.close()
            raise
        self.udp_sock = sock
        self._udp_task = asyncio.create_task(self._udp_reader(), name=f"uot-udp-{self.session_id.hex()[:8]}")

    def attach(self, writer: asyncio.StreamWriter) -> bool:
        reattached = self.writer is not None or self.detached_at is not None
        old = self.writer
        self.writer = writer
        self.detached_at = None
        self.last_seen = time.monotonic()
        if reattached:
            self.stats["reattaches"] += 1
            self.tuner.observe_reconnect()
        if old is not None and old is not writer:
            old.close()
        return reattached

    def detach(self, writer: asyncio.StreamWriter) -> None:
        if self.writer is writer:
            self.writer = None
            self.detached_at = time.monotonic()
            self.last_seen = self.detached_at

    def touch(self) -> None:
        self.last_seen = time.monotonic()

    def enqueue_udp(self, payload: bytes) -> bool:
        if self.closed or len(payload) > self.frame_payload_max:
            self.stats["oversize_drops"] += 1
            self.tuner.observe_pressure()
            return False
        self.tuner.observe_payload(len(payload))
        self.seq += 1
        frame = Frame(UDP_DATA, self.session_id, seq=self.seq, payload=payload)
        try:
            self.outbound.put_nowait(frame)
        except asyncio.QueueFull:
            self.stats["queue_drops"] += 1
            self.tuner.observe_pressure()
            return False
        self.stats["udp_in"] += 1
        return True

    async def send_to_udp(self, payload: bytes) -> bool:
        if self.closed or self.udp_sock is None:
            return False
        if len(payload) > self.frame_payload_max:
            self.stats["oversize_drops"] += 1
            self.tuner.observe_pressure()
            return False
        try:
            await asyncio.get_running_loop().sock_sendall(self.udp_sock, payload)
        except (OSError, asyncio.CancelledError):
            raise
        self.stats["udp_out"] += 1
        self.tuner.observe_payload(len(payload))
        self.touch()
        return True

    async def _udp_reader(self) -> None:
        assert self.udp_sock is not None
        loop = asyncio.get_running_loop()
        try:
            while not self.closed:
                payload = await loop.sock_recv(self.udp_sock, MAX_DATAGRAM)
                if payload:
                    self.enqueue_udp(payload)
        except asyncio.CancelledError:
            raise
        except OSError as exc:
            if not self.closed:
                logging.warning("external UoT UDP reader stopped sid=%s: %s", self.session_id.hex(), exc)

    async def close(self) -> None:
        if self.closed:
            return
        self.closed = True
        if self._udp_task is not None:
            self._udp_task.cancel()
            await asyncio.gather(self._udp_task, return_exceptions=True)
            self._udp_task = None
        if self.udp_sock is not None:
            self.udp_sock.close()
            self.udp_sock = None
        if self.writer is not None:
            self.writer.close()
            await self.writer.wait_closed()
            self.writer = None


class SessionRegistry:
    def __init__(self, server: "ExternalUoTServer") -> None:
        self.server = server
        self.sessions: dict[bytes, UoTSession] = {}

    async def announce(self, session_id: bytes, binding: ForwardBinding, target: str, requested_max: int) -> tuple[UoTSession, bool]:
        if len(session_id) != SESSION_ID_BYTES:
            raise ProtocolError("session id must be 16 bytes")
        session = self.sessions.get(session_id)
        if session is not None and not session.closed:
            if session.binding.forward_id != binding.forward_id or session.target != target:
                raise ProtocolError("session binding mismatch")
            return session, True
        if len(self.sessions) >= self.server.config.session_limit:
            raise ProtocolError("session limit reached")
        negotiated = min(requested_max, self.server.config.frame_payload_max)
        if negotiated < MIN_FRAME_PAYLOAD:
            raise ProtocolError("no usable frame payload size")
        session = UoTSession(self.server, session_id, binding, target, negotiated)
        await session.open()
        self.sessions[session_id] = session
        return session, False

    async def reap(self) -> None:
        now = time.monotonic()
        doomed = [sid for sid, session in self.sessions.items() if session.closed or (session.writer is None and session.detached_at is not None and now - session.detached_at >= self.server.config.session_grace_seconds)]
        for sid in doomed:
            session = self.sessions.pop(sid, None)
            if session is not None:
                await session.close()

    async def close_all(self) -> None:
        sessions = list(self.sessions.values())
        self.sessions.clear()
        await asyncio.gather(*(session.close() for session in sessions), return_exceptions=True)


class ExternalUoTServer:
    def __init__(self, config: SidecarConfig) -> None:
        self.config = config
        self.registry = SessionRegistry(self)
        self.server: asyncio.AbstractServer | None = None
        self._reaper_task: asyncio.Task[None] | None = None

    async def start(self) -> None:
        self.server = await asyncio.start_server(self._handle, self.config.listen_host, self.config.listen_port)
        self._reaper_task = asyncio.create_task(self._reaper(), name="external-uot-reaper")

    @property
    def address(self) -> tuple[str, int]:
        if self.server is None or not self.server.sockets:
            raise RuntimeError("server is not started")
        host, port = self.server.sockets[0].getsockname()[:2]
        return str(host), int(port)

    async def _reaper(self) -> None:
        interval = min(1.0, max(0.05, self.config.session_grace_seconds / 4))
        try:
            while True:
                await asyncio.sleep(interval)
                await self.registry.reap()
        except asyncio.CancelledError:
            raise

    def _binding_for(self, forward_id: int) -> ForwardBinding:
        binding = self.config.forwards.get(forward_id)
        if binding is None:
            raise ProtocolError("forward is not allowed")
        return binding

    def _ack(self, session_id: bytes, ok: bool, session: str = "", frame_payload_max: int | None = None, **extra: Any) -> Frame:
        payload: dict[str, Any] = {"ok": bool(ok), "frame_payload_max": (frame_payload_max if frame_payload_max is not None else (self.config.frame_payload_max if ok else 0)), "queue_packets": self.config.queue_packets}
        if session:
            payload["session"] = session
        payload.update(extra)
        return Frame(HELLO_ACK, session_id, payload=_json_bytes(payload))

    async def _handle(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        session: UoTSession | None = None
        current_writer = writer
        error_session_id = b"\x00" * SESSION_ID_BYTES
        try:
            hello = await read_frame(reader, self.config.max_frame_bytes)
            error_session_id = hello.session_id
            data = _hello_payload(hello)
            binding = self._binding_for(data["forward_id"])
            if not binding.verify(data["token"], data["target"]):
                raise ProtocolError("authentication or target binding failed")
            session, reattached = await self.registry.announce(hello.session_id, binding, data["target"], data["frame_payload_max"])
            session.attach(writer)
            await write_frame(
                writer,
                self._ack(hello.session_id, True, "OKR" if reattached else "OKN", frame_payload_max=session.frame_payload_max),
                self.config.max_frame_bytes,
            )
            reader_task = asyncio.create_task(self._carrier_reader(reader, session), name=f"uot-read-{hello.session_id.hex()[:8]}")
            writer_task = asyncio.create_task(self._carrier_writer(writer, session), name=f"uot-write-{hello.session_id.hex()[:8]}")
            done, pending = await asyncio.wait({reader_task, writer_task}, return_when=asyncio.FIRST_COMPLETED)
            for task in pending:
                task.cancel()
            await asyncio.gather(*pending, return_exceptions=True)
            for task in done:
                await task
        except (asyncio.IncompleteReadError, ConnectionError, OSError):
            pass
        except (ProtocolError, ValueError) as exc:
            logging.info("external UoT connection rejected: %s", exc)
            try:
                await write_frame(current_writer, self._ack(error_session_id, False, error=str(exc)), self.config.max_frame_bytes)
            except (ConnectionError, OSError, ProtocolError):
                pass
        finally:
            if session is not None:
                session.detach(writer)
            writer.close()
            try:
                await writer.wait_closed()
            except (ConnectionError, OSError):
                pass

    async def _carrier_reader(self, reader: asyncio.StreamReader, session: UoTSession) -> None:
        while True:
            try:
                frame = await asyncio.wait_for(read_frame(reader, self.config.max_frame_bytes), self.config.idle_timeout_seconds)
            except asyncio.TimeoutError as exc:
                raise ProtocolError("carrier idle timeout") from exc
            if frame.session_id != session.session_id:
                raise ProtocolError("session id mismatch")
            session.touch()
            if frame.msg_type == DATA:
                if frame.seq <= session.last_client_seq:
                    # A carrier write can fail after the relay accepted the
                    # frame. The client retries the same sequence on reattach;
                    # suppress that replay before it reaches the UDP target.
                    continue
                session.last_client_seq = frame.seq
                if len(frame.payload) > session.frame_payload_max:
                    session.stats["oversize_drops"] += 1
                    session.tuner.observe_pressure()
                    continue
                await session.send_to_udp(frame.payload)
            elif frame.msg_type == KEEPALIVE:
                continue
            elif frame.msg_type == CLOSE:
                return
            elif frame.msg_type in {HELLO, HELLO_ACK, UDP_DATA}:
                raise ProtocolError("unexpected frame type")

    async def _carrier_writer(self, writer: asyncio.StreamWriter, session: UoTSession) -> None:
        while not session.closed and session.writer is writer:
            try:
                frame = await asyncio.wait_for(session.outbound.get(), self.config.keepalive_seconds)
            except asyncio.TimeoutError:
                if session.writer is not writer:
                    return
                session.seq += 1
                frame = Frame(KEEPALIVE, session.session_id, seq=session.seq)
            if session.writer is not writer:
                return
            await write_frame(writer, frame, self.config.max_frame_bytes)

    async def close(self) -> None:
        if self.server is not None:
            self.server.close()
            await self.server.wait_closed()
            self.server = None
        if self._reaper_task is not None:
            self._reaper_task.cancel()
            await asyncio.gather(self._reaper_task, return_exceptions=True)
            self._reaper_task = None
        await self.registry.close_all()


def _parse_targets(value: Any) -> frozenset[str]:
    values = [value] if isinstance(value, str) else value
    if not isinstance(values, Sequence) or isinstance(values, (bytes, bytearray)):
        raise ValueError("forward targets must be a string or list")
    return frozenset(normalize_target(str(item)) for item in values)


def load_config(path: str | os.PathLike[str]) -> SidecarConfig:
    raw = json.loads(open(path, "r", encoding="utf-8").read())
    forwards_raw = raw.get("forwards", raw.get("forward_bindings", {}))
    if not isinstance(forwards_raw, Mapping):
        raise ValueError("forwards must be an object")
    forwards: dict[int, ForwardBinding] = {}
    for raw_id, raw_binding in forwards_raw.items():
        forward_id = int(raw_id)
        if isinstance(raw_binding, str):
            entry: Mapping[str, Any] = {"secret": raw_binding, "targets": []}
        elif isinstance(raw_binding, Mapping):
            entry = raw_binding
        else:
            raise ValueError(f"invalid binding for forward {forward_id}")
        targets = _parse_targets(entry.get("targets", entry.get("target", [])))
        secret = str(entry.get("secret", entry.get("hmac_secret", "")) or "").encode("utf-8")
        static_token = str(entry.get("token", "") or "").strip()
        forwards[forward_id] = ForwardBinding(forward_id, secret=secret, targets=targets, static_token=static_token)
    return SidecarConfig(
        listen_host=str(raw.get("listen_host", "0.0.0.0") or "0.0.0.0"),
        listen_port=int(raw.get("listen_port", 0) or 0),
        forwards=forwards,
        max_frame_bytes=int(raw.get("max_frame_bytes", DEFAULT_MAX_FRAME_BYTES) or DEFAULT_MAX_FRAME_BYTES),
        frame_payload_max=int(raw.get("frame_payload_max", MAX_DATAGRAM) or MAX_DATAGRAM),
        queue_packets=int(raw.get("queue_packets", DEFAULT_QUEUE_PACKETS) or DEFAULT_QUEUE_PACKETS),
        keepalive_seconds=float(raw.get("keepalive_seconds", DEFAULT_KEEPALIVE_SECONDS) or DEFAULT_KEEPALIVE_SECONDS),
        idle_timeout_seconds=float(raw.get("idle_timeout_seconds", DEFAULT_IDLE_TIMEOUT_SECONDS) or DEFAULT_IDLE_TIMEOUT_SECONDS),
        session_grace_seconds=float(raw.get("session_grace_seconds", DEFAULT_SESSION_GRACE_SECONDS) or DEFAULT_SESSION_GRACE_SECONDS),
        session_limit=int(raw.get("session_limit", DEFAULT_SESSION_LIMIT) or DEFAULT_SESSION_LIMIT),
        udp_connect_timeout_seconds=float(raw.get("udp_connect_timeout_seconds", 5.0) or 5.0),
    )


async def run(config: SidecarConfig) -> None:
    server = ExternalUoTServer(config)
    await server.start()
    logging.info("external UoT listening on %s:%s", *server.address)
    try:
        await asyncio.Event().wait()
    finally:
        await server.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Operator-owned external UoT sidecar")
    parser.add_argument("--config", required=True)
    parser.add_argument("--log-level", default="INFO")
    args = parser.parse_args()
    logging.basicConfig(level=getattr(logging, str(args.log_level).upper(), logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
    asyncio.run(run(load_config(args.config)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
