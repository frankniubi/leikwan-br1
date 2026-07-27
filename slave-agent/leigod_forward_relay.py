#!/usr/bin/env python3
"""Sidecar-free LeiGod SOCKS5 relay for one published forward."""
from __future__ import annotations

import argparse
import asyncio
import contextlib
import ipaddress
import json
import logging
import os
import pathlib
import signal
import socket
import struct
import time
from typing import Any


VERSION = "2026.07.27.1"
MAX_CANDIDATES = 8
COPY_CHUNK = 256 * 1024
IDLE_TIMEOUT_SECONDS = 300.0
UDP_FLOW_TIMEOUT_SECONDS = 60.0


class CandidateConfig:
    def __init__(self, payload: dict[str, Any]) -> None:
        allowed = {"combination_id", "line_id", "socks_host", "socks_port", "sort_order"}
        if set(payload) - allowed:
            raise ValueError("candidate contains unsupported keys")
        self.combination_id = int(payload.get("combination_id", 0))
        self.line_id = int(payload.get("line_id", 0))
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
    def __init__(self, payload: dict[str, Any]) -> None:
        allowed = {
            "probe_interval_seconds",
            "connect_timeout_seconds",
            "failure_threshold",
            "recovery_success_threshold",
            "switch_improvement_ms",
            "minimum_hold_seconds",
        }
        if set(payload) - allowed:
            raise ValueError("policy contains unsupported keys")
        self.probe_interval_seconds = float(payload.get("probe_interval_seconds", 15))
        self.connect_timeout_seconds = float(payload.get("connect_timeout_seconds", 2))
        self.failure_threshold = int(payload.get("failure_threshold", 2))
        self.recovery_success_threshold = int(payload.get("recovery_success_threshold", 2))
        self.switch_improvement_ms = float(payload.get("switch_improvement_ms", 15))
        self.minimum_hold_seconds = float(payload.get("minimum_hold_seconds", 30))
        if self.probe_interval_seconds <= 0 or self.connect_timeout_seconds <= 0:
            raise ValueError("probe and connect timeouts must be positive")
        if self.failure_threshold < 1 or self.recovery_success_threshold < 1:
            raise ValueError("health thresholds must be positive")
        if self.switch_improvement_ms < 0 or self.minimum_hold_seconds < 0:
            raise ValueError("switch policy values cannot be negative")


class RelayConfig:
    def __init__(self, payload: dict[str, Any]) -> None:
        allowed = {
            "mode",
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
        self.listen_host = str(payload.get("listen_host", "")).strip()
        self.listen_port = int(payload.get("listen_port", 0))
        self.target_host = str(payload.get("target_host", "")).strip()
        self.target_port = int(payload.get("target_port", 0))
        self.transport = str(payload.get("transport", "")).strip().lower()
        if self.mode not in {"single", "race"}:
            raise ValueError("mode must be single or race")
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
        raw_candidates = payload.get("candidates")
        if not isinstance(raw_candidates, list) or not raw_candidates or len(raw_candidates) > MAX_CANDIDATES:
            raise ValueError("candidates must contain between one and eight rows")
        self.candidates = [CandidateConfig(row) for row in raw_candidates if isinstance(row, dict)]
        if len(self.candidates) != len(raw_candidates):
            raise ValueError("candidate rows must be objects")
        keys = [candidate.key for candidate in self.candidates]
        line_ids = [candidate.line_id for candidate in self.candidates]
        socks_endpoints = [(candidate.socks_host, candidate.socks_port) for candidate in self.candidates]
        if len(set(keys)) != len(keys) or len(set(line_ids)) != len(line_ids) or len(set(socks_endpoints)) != len(socks_endpoints):
            raise ValueError("duplicate candidates are not allowed")
        self.candidates.sort(key=lambda row: (row.sort_order, row.combination_id, row.line_id))
        raw_policy = payload.get("policy", {})
        if not isinstance(raw_policy, dict):
            raise ValueError("policy must be an object")
        self.policy = PolicyConfig(raw_policy)


class CandidateState:
    def __init__(self, candidate: CandidateConfig) -> None:
        self.candidate = candidate
        self.tcp_healthy = False
        self.udp_healthy = False
        self.latency_ms: float | None = None
        self.consecutive_failures = 0
        self.recovery_successes = 0
        self.quarantined = False
        self.last_error = ""

    def snapshot(self) -> dict[str, Any]:
        return {
            "combination_id": self.candidate.combination_id,
            "line_id": self.candidate.line_id,
            "tcp_healthy": self.tcp_healthy,
            "udp_healthy": self.udp_healthy,
            "latency_ms": self.latency_ms,
            "consecutive_failures": self.consecutive_failures,
            "recovery_successes": self.recovery_successes,
            "quarantined": self.quarantined,
            "last_error": self.last_error,
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
        self.states = {candidate.line_id: CandidateState(candidate) for candidate in config.candidates}
        self.selected_tcp_line_id: int | None = config.candidates[0].line_id if "tcp" in config.transport else None
        self.selected_udp_line_id: int | None = None
        self.last_switch_at = time.monotonic()
        self._probe_lock = asyncio.Lock()

    def selected_line_id(self, protocol: str = "tcp") -> int | None:
        return self.selected_udp_line_id if protocol == "udp" else self.selected_tcp_line_id

    def state(self, line_id: int) -> CandidateState:
        return self.states[line_id]

    def _record_success(self, state: CandidateState, latency_ms: float, protocol: str) -> None:
        if protocol == "udp":
            state.udp_healthy = True
        else:
            state.tcp_healthy = True
            state.latency_ms = latency_ms
        state.consecutive_failures = 0
        state.last_error = ""
        if state.quarantined:
            state.recovery_successes += 1
            if state.recovery_successes >= self.config.policy.recovery_success_threshold:
                state.quarantined = False
                state.recovery_successes = 0
        else:
            state.recovery_successes = 0

    def _record_failure(self, state: CandidateState, error: Exception, protocol: str) -> None:
        if protocol == "udp":
            state.udp_healthy = False
        else:
            state.tcp_healthy = False
        state.consecutive_failures += 1
        state.recovery_successes = 0
        state.last_error = str(error)[:500]
        if state.consecutive_failures >= self.config.policy.failure_threshold:
            state.quarantined = True

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

    def _reselect_tcp(self) -> None:
        available = [state for state in self.states.values() if state.tcp_healthy and not state.quarantined]
        if not available:
            return
        current = self.states.get(self.selected_tcp_line_id or -1)
        if self.config.mode == "single":
            if current is None or current.quarantined:
                self._switch_tcp(min(available, key=lambda state: (state.candidate.sort_order, state.candidate.line_id)))
            return
        best = min(
            available,
            key=lambda state: (
                state.latency_ms if state.latency_ms is not None else float("inf"),
                state.candidate.sort_order,
                state.candidate.line_id,
            ),
        )
        if current is None or current.quarantined or not current.tcp_healthy:
            self._switch_tcp(best)
            return
        if best.candidate.line_id == current.candidate.line_id:
            return
        held_for = time.monotonic() - self.last_switch_at
        improvement = (current.latency_ms or float("inf")) - (best.latency_ms or float("inf"))
        if held_for >= self.config.policy.minimum_hold_seconds and improvement >= self.config.policy.switch_improvement_ms:
            self._switch_tcp(best)

    def _switch_tcp(self, state: CandidateState) -> None:
        if self.selected_tcp_line_id != state.candidate.line_id:
            self.selected_tcp_line_id = state.candidate.line_id
            self.last_switch_at = time.monotonic()

    async def connect_for_client(self) -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
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
            return reader, writer
        except Exception as exc:
            self._record_failure(selected, exc, "tcp")
            self._reselect_tcp()
            replacement = self.states.get(self.selected_tcp_line_id or -1)
            if replacement is None or replacement.candidate.line_id == selected.candidate.line_id:
                raise
            reader, writer, latency = await open_socks_connect(
                replacement.candidate,
                self.config.target_host,
                self.config.target_port,
                self.config.policy.connect_timeout_seconds,
            )
            self._record_success(replacement, latency, "tcp")
            return reader, writer

    async def udp_round_trip(self, payload: bytes, preferred_line_id: int | None = None) -> tuple[int, bytes]:
        ordered: list[CandidateState] = []
        for line_id in [preferred_line_id, self.selected_udp_line_id]:
            state = self.states.get(line_id or -1)
            if state is not None and state not in ordered and not state.quarantined:
                ordered.append(state)
        ordered.extend(
            state
            for state in sorted(self.states.values(), key=lambda item: (item.candidate.sort_order, item.candidate.line_id))
            if state not in ordered and not state.quarantined
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
                if self.selected_udp_line_id is None or self.states[self.selected_udp_line_id].quarantined:
                    self.selected_udp_line_id = state.candidate.line_id
                return state.candidate.line_id, response
            except Exception as exc:
                last_error = exc
                self._record_failure(state, exc, "udp")
                if self.selected_udp_line_id == state.candidate.line_id and state.quarantined:
                    self.selected_udp_line_id = None
        raise RuntimeError(f"no UDP candidate returned a target reply: {last_error}")


async def pipe_stream(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    try:
        while True:
            data = await asyncio.wait_for(reader.read(COPY_CHUNK), timeout=IDLE_TIMEOUT_SECONDS)
            if not data:
                break
            writer.write(data)
            await writer.drain()
    except (asyncio.TimeoutError, ConnectionError, asyncio.IncompleteReadError):
        pass
    finally:
        with contextlib.suppress(Exception):
            if writer.can_write_eof():
                writer.write_eof()


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
        if "tcp" in config.transport:
            await selector.probe_once()
        self.config = config
        self.selector = selector
        self._udp_flows.clear()
        self._write_state()

    def selected_line_id(self, protocol: str = "tcp") -> int | None:
        return self.selector.selected_line_id(protocol)

    def candidate_state(self, line_id: int) -> dict[str, Any]:
        return self.selector.state(line_id).snapshot()

    async def handle_tcp(self, client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter) -> None:
        upstream_writer: asyncio.StreamWriter | None = None
        try:
            upstream_reader, upstream_writer = await self.selector.connect_for_client()
            self._write_state()
            await asyncio.gather(
                pipe_stream(client_reader, upstream_writer),
                pipe_stream(upstream_reader, client_writer),
            )
        except Exception as exc:
            logging.warning("TCP relay connection failed: %s", exc)
            self._write_state()
        finally:
            if upstream_writer is not None:
                upstream_writer.close()
                with contextlib.suppress(Exception):
                    await upstream_writer.wait_closed()
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

    def _write_state(self) -> None:
        if self.state_file is None:
            return
        payload = {
            "version": VERSION,
            "mode": self.config.mode,
            "selected_tcp_line_id": self.selected_line_id("tcp"),
            "selected_udp_line_id": self.selected_line_id("udp"),
            "updated_at": int(time.time()),
            "candidates": [state.snapshot() for state in self.selector.states.values()],
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
