#!/usr/bin/env python3
"""Sidecar-free LeiGod SOCKS5 relay for one published forward."""
from __future__ import annotations

import argparse
import asyncio
import contextlib
import ipaddress
import json
import logging
import math
import os
import pathlib
import signal
import socket
import struct
import time
from dataclasses import dataclass, field
from typing import Any, Callable


VERSION = "2026.08.05.2"
MAX_CANDIDATES = 8
COPY_CHUNK = 256 * 1024
IDLE_TIMEOUT_SECONDS = 300.0
UDP_FLOW_TIMEOUT_SECONDS = 60.0
LBND_MAGIC = b"LBND"
LBND_VERSION = 2
LBND_HELLO_KIND = 1
LBND_HEADER_LEN = 32
LBND_MIN_HELLO_PAYLOAD = 20
LBND_MAX_HELLO_PAYLOAD = 8192
BOND_HEADER_TIMEOUT_SECONDS = 3.0
MAX_TELEMETRY_BUCKETS = 4096


@dataclass(frozen=True)
class DirectionThroughputSample:
    bytes: int = 0
    duration_seconds: float = 0.0
    zero_intervals: int = 0
    qualifies: bool = False
    mbps: float = 0.0


@dataclass(frozen=True)
class ThroughputSample:
    upload: DirectionThroughputSample
    download: DirectionThroughputSample

    @property
    def upload_bytes(self) -> int:
        return self.upload.bytes

    @property
    def download_bytes(self) -> int:
        return self.download.bytes

    @property
    def upload_duration_seconds(self) -> float:
        return self.upload.duration_seconds

    @property
    def download_duration_seconds(self) -> float:
        return self.download.duration_seconds

    @property
    def upload_zero_intervals(self) -> int:
        return self.upload.zero_intervals

    @property
    def download_zero_intervals(self) -> int:
        return self.download.zero_intervals

    @property
    def upload_qualifies(self) -> bool:
        return self.upload.qualifies

    @property
    def download_qualifies(self) -> bool:
        return self.download.qualifies

    @property
    def upload_mbps(self) -> float:
        return self.upload.mbps

    @property
    def download_mbps(self) -> float:
        return self.download.mbps


@dataclass
class _DirectionTelemetry:
    bytes: int = 0
    first_payload_at: float | None = None
    last_payload_at: float | None = None
    buckets: dict[int, int] = field(default_factory=dict)

    def record(self, payload_bytes: int, now: float, interval: float) -> None:
        if payload_bytes <= 0:
            return
        if self.first_payload_at is None:
            self.first_payload_at = now
        self.last_payload_at = now
        bucket = max(0, int((now - self.first_payload_at) / interval))
        self.bytes += payload_bytes
        self.buckets[bucket] = self.buckets.get(bucket, 0) + payload_bytes
        if len(self.buckets) > MAX_TELEMETRY_BUCKETS:
            oldest = min(self.buckets)
            del self.buckets[oldest]

    def finish(self, now: float, min_bytes: int, min_seconds: float) -> DirectionThroughputSample:
        if self.first_payload_at is None:
            return DirectionThroughputSample()
        duration = max(0.0, now - self.first_payload_at)
        first_bucket = min(self.buckets) if self.buckets else 0
        last_bucket = max(self.buckets) if self.buckets else 0
        zero_intervals = max(0, (last_bucket - first_bucket + 1) - len(self.buckets))
        qualifies = self.bytes >= min_bytes and duration >= min_seconds
        mbps = (self.bytes * 8.0 / duration / 1_000_000.0) if duration > 0 else 0.0
        return DirectionThroughputSample(
            bytes=self.bytes,
            duration_seconds=duration,
            zero_intervals=zero_intervals,
            qualifies=qualifies,
            mbps=mbps,
        )


class ConnectionTelemetry:
    def __init__(self, started_at: float | None = None, stall_interval_seconds: float = 1.0) -> None:
        if stall_interval_seconds <= 0:
            raise ValueError("stall interval must be positive")
        self.started_at = time.monotonic() if started_at is None else float(started_at)
        self.stall_interval_seconds = float(stall_interval_seconds)
        self._directions = {
            "upload": _DirectionTelemetry(),
            "download": _DirectionTelemetry(),
        }

    def record(self, direction: str, payload_bytes: int, now: float | None = None) -> None:
        if direction not in self._directions:
            raise ValueError("direction must be upload or download")
        current = time.monotonic() if now is None else float(now)
        self._directions[direction].record(payload_bytes, current, self.stall_interval_seconds)

    def finish(self, now: float | None, min_bytes: int, min_seconds: float) -> ThroughputSample:
        current = time.monotonic() if now is None else float(now)
        return ThroughputSample(
            upload=self._directions["upload"].finish(current, min_bytes, min_seconds),
            download=self._directions["download"].finish(current, min_bytes, min_seconds),
        )


class CandidateConfig:
    def __init__(self, payload: dict[str, Any]) -> None:
        allowed = {"combination_id", "line_id", "leg_route_id", "socks_host", "socks_port", "sort_order"}
        if set(payload) - allowed:
            raise ValueError("candidate contains unsupported keys")
        self.combination_id = int(payload.get("combination_id", 0))
        self.line_id = int(payload.get("line_id", 0))
        self.leg_route_id = int(payload.get("leg_route_id", 0))
        self.socks_host = str(payload.get("socks_host", "")).strip()
        self.socks_port = int(payload.get("socks_port", 0))
        self.sort_order = int(payload.get("sort_order", 100))
        if self.combination_id <= 0 or self.line_id <= 0 or self.line_id > 45535:
            raise ValueError("invalid candidate identity")
        try:
            if not ipaddress.ip_address(self.socks_host).is_loopback:
                raise ValueError("candidate SOCKS host must be loopback")
        except ValueError as exc:
            raise ValueError("candidate SOCKS host must be loopback") from exc
        if self.socks_port < 1 or self.socks_port > 65535:
            raise ValueError("invalid candidate SOCKS port")

    @property
    def key(self) -> tuple[int, int]:
        return self.combination_id, self.line_id


class PolicyConfig:
    def __init__(self, payload: dict[str, Any], *, mode: str = "", transport: str = "") -> None:
        allowed = {
            "probe_interval_seconds",
            "connect_timeout_seconds",
            "failure_threshold",
            "recovery_success_threshold",
            "switch_improvement_ms",
            "minimum_hold_seconds",
            "selection_signal",
            "throughput_min_bytes",
            "throughput_min_seconds",
            "throughput_ewma_alpha",
            "throughput_explore_every_connections",
            "throughput_sample_ttl_seconds",
            "throughput_stall_interval_seconds",
        }
        if set(payload) - allowed:
            raise ValueError("policy contains unsupported keys")
        self.probe_interval_seconds = float(payload.get("probe_interval_seconds", 15))
        self.connect_timeout_seconds = float(payload.get("connect_timeout_seconds", 2))
        self.failure_threshold = int(payload.get("failure_threshold", 2))
        self.recovery_success_threshold = int(payload.get("recovery_success_threshold", 2))
        self.switch_improvement_ms = float(payload.get("switch_improvement_ms", 15))
        self.minimum_hold_seconds = float(payload.get("minimum_hold_seconds", 30))
        self.selection_signal = str(payload.get("selection_signal", "connect_latency") or "").strip().lower()
        self.throughput_min_bytes = int(payload.get("throughput_min_bytes", 1_048_576))
        self.throughput_min_seconds = float(payload.get("throughput_min_seconds", 2.0))
        self.throughput_ewma_alpha = float(payload.get("throughput_ewma_alpha", 0.25))
        self.throughput_explore_every_connections = int(payload.get("throughput_explore_every_connections", 20))
        self.throughput_sample_ttl_seconds = float(payload.get("throughput_sample_ttl_seconds", 1800))
        self.throughput_stall_interval_seconds = float(payload.get("throughput_stall_interval_seconds", 1.0))
        if self.probe_interval_seconds <= 0 or self.connect_timeout_seconds <= 0:
            raise ValueError("probe and connect timeouts must be positive")
        if self.failure_threshold < 1 or self.recovery_success_threshold < 1:
            raise ValueError("health thresholds must be positive")
        if self.switch_improvement_ms < 0 or self.minimum_hold_seconds < 0:
            raise ValueError("switch policy values cannot be negative")
        if self.selection_signal not in {"connect_latency", "throughput_ewma"}:
            raise ValueError("unsupported race selection signal")
        if self.throughput_min_bytes <= 0:
            raise ValueError("throughput minimum bytes must be positive")
        if self.throughput_min_seconds <= 0:
            raise ValueError("throughput minimum seconds must be positive")
        if not 0 < self.throughput_ewma_alpha <= 1:
            raise ValueError("throughput EWMA alpha must be in (0,1]")
        if not 2 <= self.throughput_explore_every_connections <= 10000:
            raise ValueError("throughput exploration interval must be between 2 and 10000")
        if not 30 <= self.throughput_sample_ttl_seconds <= 86400:
            raise ValueError("throughput sample TTL must be between 30 and 86400 seconds")
        if self.throughput_stall_interval_seconds <= 0:
            raise ValueError("throughput stall interval must be positive")
        if self.selection_signal == "throughput_ewma" and (mode != "race" or "tcp" not in transport):
            raise ValueError("throughput EWMA selection requires race mode with TCP transport")


class RelayConfig:
    def __init__(self, payload: dict[str, Any]) -> None:
        allowed = {
            "mode",
            "placement",
            "listen_host",
            "listen_port",
            "target_host",
            "target_port",
            "transport",
            "candidates",
            "policy",
        }
        if set(payload) - allowed:
            raise ValueError("config contains unsupported keys")
        self.mode = str(payload.get("mode", "")).strip().lower()
        self.placement = str(payload.get("placement", "") or "").strip().lower()
        self.listen_host = str(payload.get("listen_host", "")).strip()
        self.listen_port = int(payload.get("listen_port", 0))
        self.target_host = str(payload.get("target_host", "")).strip()
        self.target_port = int(payload.get("target_port", 0))
        self.transport = str(payload.get("transport", "")).strip().lower()
        if self.mode not in {"single", "race", "bond"}:
            raise ValueError("mode must be single, race, or bond")
        if self.placement not in {"", "linux_hybrid"}:
            raise ValueError("invalid relay placement")
        if self.placement == "linux_hybrid" and self.mode != "bond":
            raise ValueError("linux_hybrid placement requires bond mode")
        try:
            if not ipaddress.ip_address(self.listen_host).is_loopback:
                raise ValueError("listen_host must be loopback")
        except ValueError as exc:
            raise ValueError("listen_host must be loopback") from exc
        if self.listen_port < 1 or self.listen_port > 65535:
            raise ValueError("invalid listen_port")
        if not self.target_host or any(character.isspace() for character in self.target_host):
            raise ValueError("invalid target_host")
        if self.target_port < 1 or self.target_port > 65535:
            raise ValueError("invalid target_port")
        if self.transport not in {"tcp", "udp", "tcp+udp"}:
            raise ValueError("invalid transport")
        if self.mode == "bond" and self.transport != "tcp":
            raise ValueError("bond mode requires TCP carrier transport")
        raw_candidates = payload.get("candidates")
        if not isinstance(raw_candidates, list) or not raw_candidates or len(raw_candidates) > MAX_CANDIDATES:
            raise ValueError("candidates must contain between one and eight rows")
        self.candidates = [CandidateConfig(row) for row in raw_candidates if isinstance(row, dict)]
        if len(self.candidates) != len(raw_candidates):
            raise ValueError("candidate rows must be objects")
        if self.mode == "bond" and self.placement != "linux_hybrid" and len(self.candidates) < 2:
            raise ValueError("bond mode requires between two and eight candidates")
        keys = [candidate.key for candidate in self.candidates]
        line_ids = [candidate.line_id for candidate in self.candidates]
        socks_endpoints = [(candidate.socks_host, candidate.socks_port) for candidate in self.candidates]
        if self.mode != "bond" and (
            len(set(keys)) != len(keys)
            or len(set(line_ids)) != len(line_ids)
            or len(set(socks_endpoints)) != len(socks_endpoints)
        ):
            raise ValueError("duplicate candidates are not allowed")
        if self.mode == "bond":
            leg_route_ids = [candidate.leg_route_id for candidate in self.candidates]
            if any(leg_route_id <= 0 for leg_route_id in leg_route_ids):
                raise ValueError("bond candidates require positive leg_route_id")
            if len(set(leg_route_ids)) != len(leg_route_ids):
                raise ValueError("duplicate bond leg routes are not allowed")
        self.candidates.sort(key=lambda row: (row.sort_order, row.combination_id, row.line_id))
        raw_policy = payload.get("policy", {})
        if not isinstance(raw_policy, dict):
            raise ValueError("policy must be an object")
        self.policy = PolicyConfig(raw_policy, mode=self.mode, transport=self.transport)


class CandidateState:
    def __init__(self, candidate: CandidateConfig) -> None:
        self.candidate = candidate
        self.leg_route_ids: list[int] = []
        self.add_leg_route(candidate.leg_route_id)
        self.tcp_healthy = False
        self.udp_healthy = False
        self.latency_ms: float | None = None
        self.tcp_consecutive_failures = 0
        self.udp_consecutive_failures = 0
        self.tcp_recovery_successes = 0
        self.udp_recovery_successes = 0
        self.tcp_quarantined = False
        self.udp_quarantined = False
        self.last_error = ""
        self.bond_accepted = 0
        self.bond_active = 0
        self.bond_connect_successes = 0
        self.bond_connect_failures = 0
        self.bond_bytes_up = 0
        self.bond_bytes_down = 0
        self.bond_last_error = ""
        self.upload_ewma_mbps: float | None = None
        self.download_ewma_mbps: float | None = None
        self.upload_stall_ewma: float | None = None
        self.download_stall_ewma: float | None = None
        self.qualifying_samples = 0
        self.sample_updated_at: float | None = None
        self.sampling_in_flight = False

    def add_leg_route(self, leg_route_id: int) -> None:
        if leg_route_id > 0 and leg_route_id not in self.leg_route_ids:
            self.leg_route_ids.append(leg_route_id)

    def record_bond_error(self, error: Exception) -> None:
        self.bond_last_error = str(error)[:500]

    def bond_snapshot(self) -> dict[str, Any]:
        return {
            "accepted": self.bond_accepted,
            "active": self.bond_active,
            "connect_successes": self.bond_connect_successes,
            "connect_failures": self.bond_connect_failures,
            "bytes_up": self.bond_bytes_up,
            "bytes_down": self.bond_bytes_down,
            "last_error": self.bond_last_error,
        }

    def snapshot(self) -> dict[str, Any]:
        return {
            "combination_id": self.candidate.combination_id,
            "line_id": self.candidate.line_id,
            "leg_route_id": self.candidate.leg_route_id,
            "leg_route_ids": list(self.leg_route_ids),
            "tcp_healthy": self.tcp_healthy,
            "udp_healthy": self.udp_healthy,
            "latency_ms": self.latency_ms,
            "consecutive_failures": self.tcp_consecutive_failures,
            "recovery_successes": self.tcp_recovery_successes,
            "quarantined": self.tcp_quarantined,
            "tcp_consecutive_failures": self.tcp_consecutive_failures,
            "udp_consecutive_failures": self.udp_consecutive_failures,
            "tcp_recovery_successes": self.tcp_recovery_successes,
            "udp_recovery_successes": self.udp_recovery_successes,
            "tcp_quarantined": self.tcp_quarantined,
            "udp_quarantined": self.udp_quarantined,
            "last_error": self.last_error,
            "bond_route": self.bond_snapshot(),
            "throughput": {
                "upload_ewma_mbps": self.upload_ewma_mbps,
                "download_ewma_mbps": self.download_ewma_mbps,
                "upload_stall_ewma": self.upload_stall_ewma,
                "download_stall_ewma": self.download_stall_ewma,
                "qualifying_samples": self.qualifying_samples,
                "sample_updated_at": self.sample_updated_at,
                "sampling_in_flight": self.sampling_in_flight,
            },
        }


def parse_config(payload: Any) -> RelayConfig:
    if not isinstance(payload, dict):
        raise ValueError("config must be a JSON object")
    return RelayConfig(payload)


def load_config(path: pathlib.Path) -> RelayConfig:
    return parse_config(json.loads(path.read_text(encoding="utf-8-sig")))


def encode_socks_address(host: str, port: int) -> bytes:
    try:
        return b"\x01" + socket.inet_pton(socket.AF_INET, host) + struct.pack(">H", port)
    except OSError:
        pass
    try:
        return b"\x04" + socket.inet_pton(socket.AF_INET6, host) + struct.pack(">H", port)
    except OSError:
        encoded = host.encode("idna")
        if not encoded or len(encoded) > 255:
            raise ValueError("SOCKS hostname is invalid")
        return b"\x03" + bytes([len(encoded)]) + encoded + struct.pack(">H", port)


async def read_socks_address(reader: asyncio.StreamReader, atyp: int) -> tuple[str, int]:
    if atyp == 1:
        host = socket.inet_ntop(socket.AF_INET, await reader.readexactly(4))
    elif atyp == 4:
        host = socket.inet_ntop(socket.AF_INET6, await reader.readexactly(16))
    elif atyp == 3:
        length = (await reader.readexactly(1))[0]
        host = (await reader.readexactly(length)).decode("idna")
    else:
        raise RuntimeError("invalid SOCKS address type")
    port = struct.unpack(">H", await reader.readexactly(2))[0]
    return host, port


async def socks_greeting(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    writer.write(b"\x05\x01\x00")
    await writer.drain()
    if await reader.readexactly(2) != b"\x05\x00":
        raise RuntimeError("SOCKS server rejected no-auth negotiation")


async def socks_command_reply(reader: asyncio.StreamReader) -> tuple[str, int]:
    version, reply, _reserved, atyp = await reader.readexactly(4)
    if version != 5 or reply != 0:
        raise RuntimeError(f"SOCKS command failed with reply {reply}")
    return await read_socks_address(reader, atyp)


async def open_socks_connect(
    candidate: CandidateConfig,
    target_host: str,
    target_port: int,
    timeout: float,
) -> tuple[asyncio.StreamReader, asyncio.StreamWriter, float]:
    started = time.monotonic()

    async def connect() -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
        reader, writer = await asyncio.open_connection(candidate.socks_host, candidate.socks_port)
        try:
            await socks_greeting(reader, writer)
            writer.write(b"\x05\x01\x00" + encode_socks_address(target_host, target_port))
            await writer.drain()
            await socks_command_reply(reader)
            return reader, writer
        except Exception:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()
            raise

    reader, writer = await asyncio.wait_for(connect(), timeout=timeout)
    return reader, writer, (time.monotonic() - started) * 1000.0


def decode_udp_packet(data: bytes) -> tuple[str, int, bytes]:
    if len(data) < 4 or data[:2] != b"\x00\x00" or data[2] != 0:
        raise RuntimeError("invalid SOCKS UDP frame")
    atyp = data[3]
    offset = 4
    if atyp == 1:
        if len(data) < offset + 6:
            raise RuntimeError("short SOCKS UDP IPv4 frame")
        host = socket.inet_ntop(socket.AF_INET, data[offset : offset + 4])
        offset += 4
    elif atyp == 4:
        if len(data) < offset + 18:
            raise RuntimeError("short SOCKS UDP IPv6 frame")
        host = socket.inet_ntop(socket.AF_INET6, data[offset : offset + 16])
        offset += 16
    elif atyp == 3:
        if len(data) <= offset:
            raise RuntimeError("short SOCKS UDP hostname frame")
        length = data[offset]
        offset += 1
        if len(data) < offset + length + 2:
            raise RuntimeError("short SOCKS UDP hostname frame")
        host = data[offset : offset + length].decode("idna")
        offset += length
    else:
        raise RuntimeError("invalid SOCKS UDP address type")
    port = struct.unpack(">H", data[offset : offset + 2])[0]
    return host, port, data[offset + 2 :]


class QueueDatagramProtocol(asyncio.DatagramProtocol):
    def __init__(self) -> None:
        self.queue: asyncio.Queue[tuple[bytes, tuple[str, int]]] = asyncio.Queue(maxsize=1)

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        if self.queue.empty():
            self.queue.put_nowait((data, addr))


async def socks_udp_round_trip(
    candidate: CandidateConfig,
    target_host: str,
    target_port: int,
    payload: bytes,
    timeout: float,
) -> bytes:
    async def exchange() -> bytes:
        control_reader, control_writer = await asyncio.open_connection(candidate.socks_host, candidate.socks_port)
        transport: asyncio.DatagramTransport | None = None
        try:
            await socks_greeting(control_reader, control_writer)
            control_writer.write(b"\x05\x03\x00\x01\x00\x00\x00\x00\x00\x00")
            await control_writer.drain()
            relay_host, relay_port = await socks_command_reply(control_reader)
            if relay_host in {"0.0.0.0", "::"}:
                relay_host = candidate.socks_host
            loop = asyncio.get_running_loop()
            protocol = QueueDatagramProtocol()
            transport, _ = await loop.create_datagram_endpoint(
                lambda: protocol,
                remote_addr=(relay_host, relay_port),
            )
            frame = b"\x00\x00\x00" + encode_socks_address(target_host, target_port) + payload
            transport.sendto(frame)
            response, _ = await protocol.queue.get()
            _host, response_port, response_payload = decode_udp_packet(response)
            if response_port != target_port or not response_payload:
                raise RuntimeError("SOCKS UDP response did not come from the target")
            return response_payload
        finally:
            if transport is not None:
                transport.close()
            control_writer.close()
            with contextlib.suppress(Exception):
                await control_writer.wait_closed()

    return await asyncio.wait_for(exchange(), timeout=timeout)


class CandidateSelector:
    def __init__(self, config: RelayConfig) -> None:
        self.config = config
        self.states: dict[int, CandidateState] = {}
        for candidate in config.candidates:
            state = self.states.setdefault(candidate.line_id, CandidateState(candidate))
            state.add_leg_route(candidate.leg_route_id)
        self.selected_tcp_line_id: int | None = config.candidates[0].line_id if "tcp" in config.transport else None
        self.selected_udp_line_id: int | None = None
        self.last_switch_at = time.monotonic()
        self.connection_count = 0
        self._probe_lock = asyncio.Lock()

    def selected_line_id(self, protocol: str = "tcp") -> int | None:
        return self.selected_udp_line_id if protocol == "udp" else self.selected_tcp_line_id

    def state(self, line_id: int) -> CandidateState:
        return self.states[line_id]

    def _record_success(self, state: CandidateState, latency_ms: float, protocol: str) -> None:
        if protocol == "udp":
            state.udp_healthy = True
            state.udp_consecutive_failures = 0
            if state.udp_quarantined:
                state.udp_recovery_successes += 1
                if state.udp_recovery_successes >= self.config.policy.recovery_success_threshold:
                    state.udp_quarantined = False
                    state.udp_recovery_successes = 0
            else:
                state.udp_recovery_successes = 0
        else:
            state.tcp_healthy = True
            state.latency_ms = latency_ms
            state.tcp_consecutive_failures = 0
            if state.tcp_quarantined:
                state.tcp_recovery_successes += 1
                if state.tcp_recovery_successes >= self.config.policy.recovery_success_threshold:
                    state.tcp_quarantined = False
                    state.tcp_recovery_successes = 0
            else:
                state.tcp_recovery_successes = 0
        state.last_error = ""

    def _record_failure(self, state: CandidateState, error: Exception, protocol: str) -> None:
        if protocol == "udp":
            state.udp_healthy = False
            state.udp_consecutive_failures += 1
            state.udp_recovery_successes = 0
            if state.udp_consecutive_failures >= self.config.policy.failure_threshold:
                state.udp_quarantined = True
        else:
            state.tcp_healthy = False
            state.tcp_consecutive_failures += 1
            state.tcp_recovery_successes = 0
            if state.tcp_consecutive_failures >= self.config.policy.failure_threshold:
                state.tcp_quarantined = True
        state.last_error = str(error)[:500]

    def _expire_throughput_state(self, state: CandidateState, now: float) -> None:
        updated = state.sample_updated_at
        if updated is None or now - updated <= self.config.policy.throughput_sample_ttl_seconds:
            return
        state.upload_ewma_mbps = None
        state.download_ewma_mbps = None
        state.upload_stall_ewma = None
        state.download_stall_ewma = None
        state.qualifying_samples = 0
        state.sample_updated_at = None

    def throughput_score(self, state: CandidateState, now: float | None = None) -> float | None:
        current = time.monotonic() if now is None else float(now)
        self._expire_throughput_state(state, current)
        directional = [
            (state.upload_ewma_mbps, state.upload_stall_ewma),
            (state.download_ewma_mbps, state.download_stall_ewma),
        ]
        valid = [(value, stall) for value, stall in directional if value is not None and value > 0]
        if not valid:
            return None
        score = min(value for value, _stall in valid)
        stall_penalty = max((stall or 0.0) for _value, stall in valid)
        score *= max(0.0, 1.0 - min(1.0, stall_penalty))
        if len(valid) == 1:
            score *= 0.5
        return score

    def update_throughput_sample(
        self,
        line_id: int,
        sample: ThroughputSample,
        now: float | None = None,
    ) -> None:
        state = self.state(line_id)
        alpha = self.config.policy.throughput_ewma_alpha
        for direction, direction_sample in (("upload", sample.upload), ("download", sample.download)):
            if not direction_sample.qualifies:
                continue
            value_name = f"{direction}_ewma_mbps"
            stall_name = f"{direction}_stall_ewma"
            previous_value = getattr(state, value_name)
            previous_stall = getattr(state, stall_name)
            stall_ratio = direction_sample.zero_intervals / max(1.0, direction_sample.duration_seconds)
            setattr(
                state,
                value_name,
                direction_sample.mbps if previous_value is None else alpha * direction_sample.mbps + (1 - alpha) * previous_value,
            )
            setattr(
                state,
                stall_name,
                stall_ratio if previous_stall is None else alpha * stall_ratio + (1 - alpha) * previous_stall,
            )
        if sample.upload_qualifies or sample.download_qualifies:
            state.qualifying_samples += 1
            state.sample_updated_at = time.monotonic() if now is None else float(now)
        state.sampling_in_flight = False

    def restore_throughput_from_selector(self, source: "CandidateSelector") -> None:
        """Transfer only compatible, still-fresh in-memory samples during reload."""
        if self.config.policy.selection_signal != "throughput_ewma":
            return
        if source.config.mode != self.config.mode or source.config.target_host != self.config.target_host:
            return
        if source.config.target_port != self.config.target_port:
            return
        now = time.monotonic()
        for state in self.states.values():
            source_state = source.states.get(state.candidate.line_id)
            if source_state is None or source_state.candidate.key != state.candidate.key:
                continue
            if source_state.candidate.leg_route_id != state.candidate.leg_route_id:
                continue
            source._expire_throughput_state(source_state, now)
            updated = source_state.sample_updated_at
            if updated is None or now - updated > self.config.policy.throughput_sample_ttl_seconds:
                continue
            state.upload_ewma_mbps = source_state.upload_ewma_mbps
            state.download_ewma_mbps = source_state.download_ewma_mbps
            state.upload_stall_ewma = source_state.upload_stall_ewma
            state.download_stall_ewma = source_state.download_stall_ewma
            state.qualifying_samples = source_state.qualifying_samples
            state.sample_updated_at = updated
            state.sampling_in_flight = False

    def reserve_tcp_candidate(self, now: float | None = None) -> CandidateState | None:
        if self.config.policy.selection_signal != "throughput_ewma":
            return self.states.get(self.selected_tcp_line_id or -1)
        current = time.monotonic() if now is None else float(now)
        candidates = []
        for state in self.states.values():
            self._expire_throughput_state(state, current)
            if state.tcp_quarantined or not state.tcp_healthy or state.sampling_in_flight:
                continue
            candidates.append(state)
        if not candidates:
            return None
        unsampled = [state for state in candidates if state.sample_updated_at is None]
        if unsampled:
            chosen = min(unsampled, key=lambda state: (state.candidate.sort_order, state.candidate.line_id))
        elif (self.connection_count + 1) % self.config.policy.throughput_explore_every_connections == 0:
            chosen = min(
                candidates,
                key=lambda state: (
                    state.sample_updated_at if state.sample_updated_at is not None else float("-inf"),
                    state.candidate.sort_order,
                    state.candidate.line_id,
                ),
            )
        else:
            chosen = max(
                candidates,
                key=lambda state: (
                    self.throughput_score(state, current) if self.throughput_score(state, current) is not None else float("-inf"),
                    -state.candidate.sort_order,
                    -state.candidate.line_id,
                ),
            )
        chosen.sampling_in_flight = True
        self.connection_count += 1
        return chosen

    def release_tcp_candidate(self, state: CandidateState | None, qualifying: bool, now: float | None = None) -> None:
        if state is None:
            return
        state.sampling_in_flight = False
        if not qualifying and state.sample_updated_at is None:
            return
        if self.config.policy.selection_signal == "throughput_ewma":
            self._reselect_tcp(now=now)

    async def probe_once(self) -> None:
        async with self._probe_lock:
            async def probe(state: CandidateState) -> None:
                writer: asyncio.StreamWriter | None = None
                try:
                    _reader, writer, latency = await open_socks_connect(
                        state.candidate,
                        self.config.target_host,
                        self.config.target_port,
                        self.config.policy.connect_timeout_seconds,
                    )
                    self._record_success(state, latency, "tcp")
                except Exception as exc:
                    self._record_failure(state, exc, "tcp")
                finally:
                    if writer is not None:
                        writer.close()
                        with contextlib.suppress(Exception):
                            await writer.wait_closed()

            await asyncio.gather(*(probe(state) for state in self.states.values()))
            self._reselect_tcp()

    def _reselect_tcp(self, now: float | None = None) -> None:
        current_time = time.monotonic() if now is None else float(now)
        available = [state for state in self.states.values() if state.tcp_healthy and not state.tcp_quarantined]
        if not available:
            return
        if self.config.mode == "race" and self.config.policy.selection_signal == "throughput_ewma":
            scored = [
                (self.throughput_score(state, current_time), state)
                for state in available
            ]
            scored = [(score, state) for score, state in scored if score is not None]
            if scored:
                best = max(
                    scored,
                    key=lambda item: (item[0], -item[1].candidate.sort_order, -item[1].candidate.line_id),
                )[1]
                self._switch_tcp(best)
                return
        current = self.states.get(self.selected_tcp_line_id or -1)
        if self.config.mode == "single":
            best = min(available, key=lambda state: (state.candidate.sort_order, state.candidate.line_id))
            if current is None or current.tcp_quarantined:
                self._switch_tcp(best)
                return
            if best.candidate.line_id == current.candidate.line_id:
                return
            current_order = (current.candidate.sort_order, current.candidate.line_id)
            best_order = (best.candidate.sort_order, best.candidate.line_id)
            if best_order < current_order and current_time - self.last_switch_at >= self.config.policy.minimum_hold_seconds:
                self._switch_tcp(best)
            return
        best = min(
            available,
            key=lambda state: (
                state.latency_ms if state.latency_ms is not None else float("inf"),
                state.candidate.sort_order,
                state.candidate.line_id,
            ),
        )
        if current is None or current.tcp_quarantined or not current.tcp_healthy:
            self._switch_tcp(best)
            return
        if best.candidate.line_id == current.candidate.line_id:
            return
        held_for = current_time - self.last_switch_at
        improvement = (current.latency_ms or float("inf")) - (best.latency_ms or float("inf"))
        if held_for >= self.config.policy.minimum_hold_seconds and improvement >= self.config.policy.switch_improvement_ms:
            self._switch_tcp(best)

    def _switch_tcp(self, state: CandidateState) -> None:
        if self.selected_tcp_line_id != state.candidate.line_id:
            self.selected_tcp_line_id = state.candidate.line_id
            self.last_switch_at = time.monotonic()

    async def connect_for_client(self) -> tuple[int, asyncio.StreamReader, asyncio.StreamWriter]:
        selected = self.reserve_tcp_candidate()
        if selected is None:
            selected = self.states.get(self.selected_tcp_line_id or -1)
        if selected is None:
            raise RuntimeError("no selected TCP candidate")
        try:
            reader, writer, latency = await open_socks_connect(
                selected.candidate,
                self.config.target_host,
                self.config.target_port,
                self.config.policy.connect_timeout_seconds,
            )
            self._record_success(selected, latency, "tcp")
            return selected.candidate.line_id, reader, writer
        except Exception as exc:
            self._record_failure(selected, exc, "tcp")
            self.release_tcp_candidate(selected, qualifying=False)
            self._reselect_tcp()
            replacement = self.reserve_tcp_candidate()
            if replacement is None:
                replacement = self.states.get(self.selected_tcp_line_id or -1)
            if replacement is None or replacement.candidate.line_id == selected.candidate.line_id:
                self.release_tcp_candidate(replacement, qualifying=False)
                raise
            try:
                reader, writer, latency = await open_socks_connect(
                    replacement.candidate,
                    self.config.target_host,
                    self.config.target_port,
                    self.config.policy.connect_timeout_seconds,
                )
                self._record_success(replacement, latency, "tcp")
                return replacement.candidate.line_id, reader, writer
            except Exception as replacement_exc:
                self._record_failure(replacement, replacement_exc, "tcp")
                self.release_tcp_candidate(replacement, qualifying=False)
                raise

    async def udp_round_trip(self, payload: bytes, preferred_line_id: int | None = None) -> tuple[int, bytes]:
        ordered: list[CandidateState] = []
        for line_id in [preferred_line_id, self.selected_udp_line_id]:
            state = self.states.get(line_id or -1)
            if state is not None and state not in ordered and not state.udp_quarantined:
                ordered.append(state)
        ordered.extend(
            state
            for state in sorted(self.states.values(), key=lambda item: (item.candidate.sort_order, item.candidate.line_id))
            if state not in ordered and not state.udp_quarantined
        )
        last_error: Exception | None = None
        for state in ordered:
            started = time.monotonic()
            try:
                response = await socks_udp_round_trip(
                    state.candidate,
                    self.config.target_host,
                    self.config.target_port,
                    payload,
                    self.config.policy.connect_timeout_seconds,
                )
                self._record_success(state, (time.monotonic() - started) * 1000.0, "udp")
                if self.selected_udp_line_id is None or self.states[self.selected_udp_line_id].udp_quarantined:
                    self.selected_udp_line_id = state.candidate.line_id
                return state.candidate.line_id, response
            except Exception as exc:
                last_error = exc
                self._record_failure(state, exc, "udp")
                if self.selected_udp_line_id == state.candidate.line_id and state.udp_quarantined:
                    self.selected_udp_line_id = None
        raise RuntimeError(f"no UDP candidate returned a target reply: {last_error}")


async def pipe_stream(
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    on_bytes: Callable[[int], None] | None = None,
) -> None:
    try:
        while True:
            data = await asyncio.wait_for(reader.read(COPY_CHUNK), timeout=IDLE_TIMEOUT_SECONDS)
            if not data:
                break
            if on_bytes is not None:
                on_bytes(len(data))
            writer.write(data)
            await writer.drain()
    except (asyncio.TimeoutError, ConnectionError, asyncio.IncompleteReadError):
        pass
    finally:
        with contextlib.suppress(Exception):
            if writer.can_write_eof():
                writer.write_eof()


async def read_bond_hello_route_id(reader: asyncio.StreamReader) -> tuple[int, bytes]:
    header = await asyncio.wait_for(reader.readexactly(LBND_HEADER_LEN), timeout=BOND_HEADER_TIMEOUT_SECONDS)
    if header[:4] != LBND_MAGIC or header[4] != LBND_VERSION or header[5] != LBND_HELLO_KIND:
        raise RuntimeError("invalid LBND hello header")
    leg_route_id = struct.unpack(">I", header[24:28])[0]
    payload_len = struct.unpack(">I", header[28:32])[0]
    if leg_route_id == 0:
        raise RuntimeError("LBND hello leg route is missing")
    if not LBND_MIN_HELLO_PAYLOAD <= payload_len <= LBND_MAX_HELLO_PAYLOAD:
        raise RuntimeError("LBND hello payload length is invalid")
    return leg_route_id, header


class RelayUdpProtocol(asyncio.DatagramProtocol):
    def __init__(self, runtime: "RelayRuntime") -> None:
        self.runtime = runtime

    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.runtime._udp_transport = transport  # type: ignore[assignment]

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        asyncio.create_task(self.runtime.handle_udp(data, addr))


class RelayRuntime:
    def __init__(self, config: RelayConfig, state_file: pathlib.Path | None = None) -> None:
        self.config = config
        self.selector = CandidateSelector(config)
        self.state_file = state_file
        self._tcp_server: asyncio.AbstractServer | None = None
        self._udp_transport: asyncio.DatagramTransport | None = None
        self._probe_task: asyncio.Task[None] | None = None
        self._udp_flows: dict[tuple[str, int], tuple[int, float]] = {}
        self._bond_candidates_by_route = {
            candidate.leg_route_id: candidate
            for candidate in config.candidates
            if candidate.leg_route_id > 0
        }
        self._restore_state()

    @property
    def tcp_address(self) -> tuple[str, int]:
        if self._tcp_server is None:
            raise RuntimeError("TCP relay is not running")
        host, port = self._tcp_server.sockets[0].getsockname()[:2]
        return str(host), int(port)

    @property
    def udp_address(self) -> tuple[str, int]:
        if self._udp_transport is None:
            raise RuntimeError("UDP relay is not running")
        host, port = self._udp_transport.get_extra_info("sockname")[:2]
        return str(host), int(port)

    async def start(self) -> None:
        if "tcp" in self.config.transport:
            if self.config.mode != "bond":
                await self.selector.probe_once()
            self._tcp_server = await asyncio.start_server(
                self.handle_tcp,
                self.config.listen_host,
                self.config.listen_port,
            )
        if "udp" in self.config.transport:
            loop = asyncio.get_running_loop()
            await loop.create_datagram_endpoint(
                lambda: RelayUdpProtocol(self),
                local_addr=(self.config.listen_host, self.config.listen_port),
            )
        if self.config.mode != "bond":
            self._probe_task = asyncio.create_task(self.probe_loop())
        self._write_state()

    async def close(self) -> None:
        if self._probe_task is not None:
            self._probe_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await self._probe_task
        if self._tcp_server is not None:
            self._tcp_server.close()
            await self._tcp_server.wait_closed()
        if self._udp_transport is not None:
            self._udp_transport.close()

    async def probe_loop(self) -> None:
        while True:
            await asyncio.sleep(self.config.policy.probe_interval_seconds)
            if "tcp" in self.config.transport:
                await self.probe_once()

    async def probe_once(self) -> None:
        await self.selector.probe_once()
        self._write_state()

    async def reload_payload(self, payload: dict[str, Any]) -> None:
        config = parse_config(payload)
        if (config.listen_host, config.listen_port, config.transport) != (
            self.config.listen_host,
            self.config.listen_port,
            self.config.transport,
        ):
            raise ValueError("reload cannot change listener identity or transport")
        selector = CandidateSelector(config)
        selector.restore_throughput_from_selector(self.selector)
        if "tcp" in config.transport and config.mode != "bond":
            await selector.probe_once()
        self.config = config
        self.selector = selector
        self._bond_candidates_by_route = {
            candidate.leg_route_id: candidate
            for candidate in config.candidates
            if candidate.leg_route_id > 0
        }
        self._udp_flows.clear()
        self._write_state()

    def selected_line_id(self, protocol: str = "tcp") -> int | None:
        return self.selector.selected_line_id(protocol)

    def candidate_state(self, line_id: int) -> dict[str, Any]:
        return self.selector.state(line_id).snapshot()

    async def handle_tcp(self, client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter) -> None:
        if self.config.mode == "bond":
            await self.handle_bond_tcp(client_reader, client_writer)
            return
        upstream_writer: asyncio.StreamWriter | None = None
        selected_line_id: int | None = None
        selected_state: CandidateState | None = None
        telemetry: ConnectionTelemetry | None = None
        try:
            selected_line_id, upstream_reader, upstream_writer = await self.selector.connect_for_client()
            selected_state = self.selector.state(selected_line_id)
            if self.config.policy.selection_signal == "throughput_ewma":
                telemetry = ConnectionTelemetry(
                    stall_interval_seconds=self.config.policy.throughput_stall_interval_seconds,
                )

            def record_upload(payload_bytes: int) -> None:
                if telemetry is not None:
                    telemetry.record("upload", payload_bytes)

            def record_download(payload_bytes: int) -> None:
                if telemetry is not None:
                    telemetry.record("download", payload_bytes)

            self._write_state()
            await asyncio.gather(
                pipe_stream(client_reader, upstream_writer, record_upload),
                pipe_stream(upstream_reader, client_writer, record_download),
            )
        except Exception as exc:
            logging.warning("TCP relay connection failed: %s", exc)
        finally:
            if telemetry is not None and selected_line_id is not None:
                sample = telemetry.finish(
                    now=None,
                    min_bytes=self.config.policy.throughput_min_bytes,
                    min_seconds=self.config.policy.throughput_min_seconds,
                )
                self.selector.update_throughput_sample(selected_line_id, sample)
                self.selector.release_tcp_candidate(
                    selected_state,
                    qualifying=sample.upload_qualifies or sample.download_qualifies,
                )
            self._write_state()
            if upstream_writer is not None:
                upstream_writer.close()
                with contextlib.suppress(Exception):
                    await upstream_writer.wait_closed()
            client_writer.close()
            with contextlib.suppress(Exception):
                await client_writer.wait_closed()

    async def handle_bond_tcp(
        self,
        client_reader: asyncio.StreamReader,
        client_writer: asyncio.StreamWriter,
    ) -> None:
        upstream_writer: asyncio.StreamWriter | None = None
        candidate_state: CandidateState | None = None
        bond_active = False
        try:
            leg_route_id, hello_header = await read_bond_hello_route_id(client_reader)
            candidate = self._bond_candidates_by_route.get(leg_route_id)
            if candidate is None:
                raise RuntimeError(f"unknown LBND leg route {leg_route_id}")
            candidate_state = self.selector.state(candidate.line_id)
            candidate_state.bond_accepted += 1
            self._write_state()
            try:
                upstream_reader, upstream_writer, latency = await open_socks_connect(
                    candidate,
                    self.config.target_host,
                    self.config.target_port,
                    self.config.policy.connect_timeout_seconds,
                )
            except Exception as exc:
                self.selector._record_failure(candidate_state, exc, "tcp")
                candidate_state.bond_connect_failures += 1
                candidate_state.record_bond_error(exc)
                raise
            self.selector._record_success(candidate_state, latency, "tcp")
            candidate_state.bond_connect_successes += 1
            candidate_state.bond_active += 1
            bond_active = True
            candidate_state.bond_last_error = ""
            upstream_writer.write(hello_header)
            await upstream_writer.drain()
            self._write_state()
            await asyncio.gather(
                pipe_stream(
                    client_reader,
                    upstream_writer,
                    lambda count: setattr(candidate_state, "bond_bytes_up", candidate_state.bond_bytes_up + count),
                ),
                pipe_stream(
                    upstream_reader,
                    client_writer,
                    lambda count: setattr(candidate_state, "bond_bytes_down", candidate_state.bond_bytes_down + count),
                ),
            )
        except Exception as exc:
            logging.warning("bond relay connection failed: %s", exc)
            self._write_state()
        finally:
            if upstream_writer is not None:
                upstream_writer.close()
                with contextlib.suppress(Exception):
                    await upstream_writer.wait_closed()
            if bond_active and candidate_state is not None:
                candidate_state.bond_active = max(0, candidate_state.bond_active - 1)
                self._write_state()
            client_writer.close()
            with contextlib.suppress(Exception):
                await client_writer.wait_closed()

    async def handle_udp(self, payload: bytes, client_addr: tuple[str, int]) -> None:
        now = time.monotonic()
        flow = self._udp_flows.get(client_addr)
        preferred = flow[0] if flow is not None and flow[1] > now else None
        try:
            line_id, response = await self.selector.udp_round_trip(payload, preferred)
            self._udp_flows[client_addr] = (line_id, now + UDP_FLOW_TIMEOUT_SECONDS)
            if self._udp_transport is not None:
                self._udp_transport.sendto(response, client_addr)
        except Exception as exc:
            logging.warning("UDP relay datagram failed: %s", exc)
        finally:
            self._write_state()

    @property
    def _throughput_state_enabled(self) -> bool:
        return self.config.mode == "race" and self.config.policy.selection_signal == "throughput_ewma"

    @staticmethod
    def _finite_number(value: Any) -> float | None:
        if isinstance(value, bool):
            return None
        try:
            number = float(value)
        except (TypeError, ValueError):
            return None
        return number if math.isfinite(number) else None

    def _state_candidate_snapshot(self, state: CandidateState) -> dict[str, Any]:
        self.selector._expire_throughput_state(state, time.monotonic())
        snapshot = state.snapshot()
        if not self._throughput_state_enabled:
            return snapshot
        throughput = snapshot["throughput"]
        # Persist wall-clock timestamps; the selector itself uses monotonic time.
        updated = state.sample_updated_at
        throughput["sample_updated_at"] = (
            time.time() - (time.monotonic() - updated) if updated is not None else None
        )
        throughput["sampling_in_flight"] = False
        return snapshot

    def _restore_state(self) -> None:
        if not self._throughput_state_enabled or self.state_file is None:
            return
        try:
            payload = json.loads(self.state_file.read_text(encoding="utf-8"))
        except (OSError, UnicodeError, ValueError, TypeError):
            return
        if not isinstance(payload, dict):
            return
        scope = payload.get("scope")
        if not isinstance(scope, dict):
            return
        try:
            scope_port = int(scope.get("target_port", 0) or 0)
        except (TypeError, ValueError):
            return
        if (
            str(scope.get("mode", "")).strip().lower() != self.config.mode
            or str(scope.get("target_host", "")) != self.config.target_host
            or scope_port != self.config.target_port
        ):
            return
        rows = payload.get("candidates")
        if not isinstance(rows, list):
            return
        now_wall = time.time()
        now_mono = time.monotonic()
        ttl = self.config.policy.throughput_sample_ttl_seconds
        for row in rows:
            if not isinstance(row, dict):
                continue
            try:
                line_id = int(row.get("line_id", 0) or 0)
                combination_id = int(row.get("combination_id", 0) or 0)
                leg_route_id = int(row.get("leg_route_id", 0) or 0)
            except (TypeError, ValueError):
                continue
            state = self.selector.states.get(line_id)
            if (
                state is None
                or state.candidate.combination_id != combination_id
                or state.candidate.leg_route_id != leg_route_id
            ):
                continue
            throughput = row.get("throughput")
            if not isinstance(throughput, dict):
                continue
            updated_wall = self._finite_number(throughput.get("sample_updated_at"))
            if updated_wall is None:
                continue
            age = now_wall - updated_wall
            if age < 0 or age > ttl:
                continue
            values: dict[str, float | None] = {}
            valid_value = False
            malformed = False
            for direction in ("upload", "download"):
                raw_value = throughput.get(f"{direction}_ewma_mbps")
                raw_stall = throughput.get(f"{direction}_stall_ewma")
                value = self._finite_number(raw_value) if raw_value is not None else None
                stall = self._finite_number(raw_stall) if raw_stall is not None else None
                if value is not None and value <= 0:
                    malformed = True
                if stall is not None and (stall < 0 or stall > 100):
                    malformed = True
                if raw_value is not None and value is None:
                    malformed = True
                if raw_stall is not None and stall is None:
                    malformed = True
                values[f"{direction}_ewma_mbps"] = value
                values[f"{direction}_stall_ewma"] = stall
                valid_value = valid_value or value is not None
            try:
                qualifying_samples = int(throughput.get("qualifying_samples", 0) or 0)
            except (TypeError, ValueError):
                malformed = True
                qualifying_samples = 0
            if qualifying_samples < 1 or qualifying_samples > 1_000_000:
                malformed = True
            if malformed or not valid_value:
                continue
            state.upload_ewma_mbps = values["upload_ewma_mbps"]
            state.download_ewma_mbps = values["download_ewma_mbps"]
            state.upload_stall_ewma = values["upload_stall_ewma"]
            state.download_stall_ewma = values["download_stall_ewma"]
            state.qualifying_samples = qualifying_samples
            state.sample_updated_at = now_mono - age
            state.sampling_in_flight = False

    def _write_state(self) -> None:
        if self.state_file is None:
            return
        payload = {
            "version": VERSION,
            "mode": self.config.mode,
            "selected_tcp_line_id": self.selected_line_id("tcp"),
            "selected_udp_line_id": self.selected_line_id("udp"),
            "updated_at": int(time.time()),
            "candidates": [self._state_candidate_snapshot(state) for state in self.selector.states.values()],
        }
        if self._throughput_state_enabled:
            payload["scope"] = {
                "mode": self.config.mode,
                "target_host": self.config.target_host,
                "target_port": self.config.target_port,
            }
        self.state_file.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.state_file.with_name(self.state_file.name + f".tmp.{os.getpid()}")
        temporary.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
        os.replace(temporary, self.state_file)


async def run_runtime(config_path: pathlib.Path, state_file: pathlib.Path | None) -> None:
    runtime = RelayRuntime(load_config(config_path), state_file=state_file)
    await runtime.start()
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()

    def request_reload() -> None:
        async def reload_config() -> None:
            try:
                await runtime.reload_payload(json.loads(config_path.read_text(encoding="utf-8-sig")))
                logging.info("reloaded %s", config_path)
            except Exception:
                logging.exception("config reload failed; retaining current generation")

        asyncio.create_task(reload_config())

    for sig, callback in ((signal.SIGTERM, stop.set), (signal.SIGINT, stop.set), (signal.SIGHUP, request_reload)):
        with contextlib.suppress(NotImplementedError, RuntimeError):
            loop.add_signal_handler(sig, callback)
    try:
        await stop.wait()
    finally:
        await runtime.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True, type=pathlib.Path)
    parser.add_argument("--state-file", type=pathlib.Path)
    parser.add_argument("--log-level", default="INFO")
    parser.add_argument("--version", action="version", version=VERSION)
    args = parser.parse_args()
    logging.basicConfig(
        level=getattr(logging, str(args.log_level).upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    try:
        asyncio.run(run_runtime(args.config, args.state_file))
    except (ValueError, OSError, json.JSONDecodeError) as exc:
        logging.error("relay startup failed: %s", exc)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
