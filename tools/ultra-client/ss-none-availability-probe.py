#!/usr/bin/env python3
"""Long-running Shadowsocks ``none`` availability probe.

The local Ultra listener carries the same address-first TCP wire format as a
Shadowsocks ``none`` server.  Its UDP facade accepts the SOCKS5 UDP envelope
and forwards the inner address-first payload.  The probe therefore needs no
third-party runtime: it exercises the exact local TCP and UDP data paths and
keeps a rolling availability window in a small, secret-free status file.
"""

from __future__ import annotations

import argparse
import collections
import dataclasses
import datetime as _datetime
import ipaddress
import json
import logging
import os
import pathlib
import signal
import socket
import struct
import tempfile
import time
from typing import Any, Callable, Deque, Iterable, Optional


LOG = logging.getLogger("leikwan.ss_none_probe")
MAX_RESPONSE_BYTES = 16 * 1024
DEFAULT_HOST = "127.0.0.1"
DEFAULT_PORT = 10001
DEFAULT_INTERVAL_SECONDS = 30
DEFAULT_TIMEOUT_SECONDS = 12
DEFAULT_WINDOW_SECONDS = 24 * 60 * 60
DEFAULT_TCP_TARGET = "1.1.1.1:80"
DEFAULT_UDP_TARGET = "1.1.1.1:53"


@dataclasses.dataclass(frozen=True)
class ProbeResult:
    ok: bool
    tcp_ok: bool
    udp_ok: bool
    tcp_latency_ms: Optional[int]
    udp_latency_ms: Optional[int]
    error_code: str
    tcp_error_code: str = ""
    udp_error_code: str = ""


@dataclasses.dataclass(frozen=True)
class UDPResponse:
    ok: bool
    error_code: str
    payload: bytes = b""


class ConfigError(ValueError):
    pass


def _integer(value: Any, name: str, minimum: int, maximum: int) -> int:
    if isinstance(value, bool):
        raise ConfigError(f"{name} must be an integer")
    try:
        result = int(value)
    except (TypeError, ValueError) as exc:
        raise ConfigError(f"{name} must be an integer") from exc
    if result < minimum or result > maximum:
        raise ConfigError(f"{name} must be between {minimum} and {maximum}")
    return result


def parse_endpoint(value: Any, name: str) -> tuple[str, int]:
    if not isinstance(value, str) or not value.strip():
        raise ConfigError(f"{name} must be host:port")
    text = value.strip()
    if text.startswith("["):
        end = text.find("]")
        if end <= 1 or end + 2 > len(text) or text[end + 1] != ":":
            raise ConfigError(f"{name} must be host:port")
        host, port_text = text[1:end], text[end + 2 :]
    else:
        if ":" not in text:
            raise ConfigError(f"{name} must be host:port")
        host, port_text = text.rsplit(":", 1)
    if not host or not port_text.isdigit():
        raise ConfigError(f"{name} must be host:port")
    port = _integer(port_text, f"{name} port", 1, 65535)
    return host, port


def encode_address(host: str, port: int) -> bytes:
    """Return a Shadowsocks address header (ATYP/address/port)."""

    if not isinstance(host, str) or not host or "\x00" in host:
        raise ValueError("address host invalid")
    if not isinstance(port, int) or port < 1 or port > 65535:
        raise ValueError("address port invalid")
    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        encoded = host.encode("idna")
        if not encoded or len(encoded) > 255:
            raise ValueError("address host invalid")
        return b"\x03" + bytes([len(encoded)]) + encoded + struct.pack("!H", port)
    if address.version == 4:
        return b"\x01" + address.packed + struct.pack("!H", port)
    return b"\x04" + address.packed + struct.pack("!H", port)


def decode_address(packet: bytes, offset: int = 0) -> tuple[str, int, int]:
    if offset < 0 or offset >= len(packet):
        raise ValueError("address header missing")
    address_type = packet[offset]
    offset += 1
    if address_type == 1:
        end = offset + 4
        if end + 2 > len(packet):
            raise ValueError("ipv4 address truncated")
        host = str(ipaddress.ip_address(packet[offset:end]))
    elif address_type == 4:
        end = offset + 16
        if end + 2 > len(packet):
            raise ValueError("ipv6 address truncated")
        host = str(ipaddress.ip_address(packet[offset:end]))
    elif address_type == 3:
        if offset >= len(packet):
            raise ValueError("domain length missing")
        length = packet[offset]
        offset += 1
        end = offset + length
        if length == 0 or end + 2 > len(packet):
            raise ValueError("domain address truncated")
        try:
            host = packet[offset:end].decode("idna")
        except UnicodeError as exc:
            raise ValueError("domain address invalid") from exc
    else:
        raise ValueError("address type invalid")
    port_offset = end
    port = struct.unpack("!H", packet[port_offset : port_offset + 2])[0]
    if port < 1:
        raise ValueError("address port invalid")
    return host, port, port_offset + 2


def build_tcp_request(host: str, port: int, payload: bytes) -> bytes:
    return encode_address(host, port) + payload


def build_dns_query(identifier: int) -> bytes:
    identifier &= 0xFFFF
    # example.com, type A, class IN. Recursion desired is deliberately kept
    # small and deterministic so a response can be validated without DNS libs.
    return struct.pack("!HHHHHH", identifier, 0x0100, 1, 0, 0, 0) + (
        b"\x07example\x03com\x00" + struct.pack("!HH", 1, 1)
    )


def build_udp_request(host: str, port: int, payload: bytes) -> bytes:
    # Ultra's local UDP facade accepts the standard SOCKS5 UDP RSV/FRAG
    # prefix, then forwards the remaining SS-none address-first payload.
    return b"\x00\x00\x00" + build_tcp_request(host, port, payload)


def _response_payload_candidates(packet: bytes) -> Iterable[bytes]:
    offsets = [3] if packet.startswith(b"\x00\x00\x00") else []
    offsets.append(0)
    seen: set[int] = set()
    for offset in offsets:
        if offset in seen or offset >= len(packet):
            continue
        seen.add(offset)
        try:
            _, _, payload_offset = decode_address(packet, offset)
        except ValueError:
            continue
        payload = packet[payload_offset:]
        # A relay may wrap a none-target's address-first response once more.
        try:
            _, _, nested_offset = decode_address(payload, 0)
        except ValueError:
            nested_offset = -1
        if nested_offset > 0 and len(payload) - nested_offset >= 12:
            yield payload[nested_offset:]
        yield payload


def decode_udp_response(packet: bytes, identifier: int) -> UDPResponse:
    for payload in _response_payload_candidates(packet):
        if len(payload) < 12:
            continue
        response_id, flags = struct.unpack("!HH", payload[:4])
        if response_id != (identifier & 0xFFFF) or not (flags & 0x8000):
            continue
        return UDPResponse(True, "", payload)
    if not packet:
        return UDPResponse(False, "udp_empty_response")
    return UDPResponse(False, "udp_dns_response_invalid")


def _error_code(prefix: str, exc: BaseException) -> str:
    if isinstance(exc, socket.timeout):
        return f"{prefix}_timeout"
    if isinstance(exc, ConnectionRefusedError):
        return f"{prefix}_connection_refused"
    if isinstance(exc, (ConnectionResetError, BrokenPipeError)):
        return f"{prefix}_connection_reset"
    if isinstance(exc, OSError):
        return f"{prefix}_io_error"
    return f"{prefix}_failed"


def probe_tcp(
    host: str,
    port: int,
    target_host: str,
    target_port: int,
    timeout_seconds: int,
) -> tuple[bool, Optional[int], str, int]:
    started = time.monotonic()
    request = build_tcp_request(
        target_host,
        target_port,
        b"GET /cdn-cgi/trace HTTP/1.1\r\n"
        b"Host: one.one.one.one\r\n"
        b"Connection: close\r\n\r\n",
    )
    try:
        with socket.create_connection((host, port), timeout=timeout_seconds) as connection:
            connection.settimeout(timeout_seconds)
            connection.sendall(request)
            data = bytearray()
            while len(data) < MAX_RESPONSE_BYTES:
                chunk = connection.recv(4096)
                if not chunk:
                    break
                data.extend(chunk)
                if data.startswith(b"HTTP/1."):
                    latency = max(0, int((time.monotonic() - started) * 1000))
                    return True, latency, "", len(data)
            latency = max(0, int((time.monotonic() - started) * 1000))
            return False, latency, "tcp_http_response_invalid", len(data)
    except (OSError, ValueError) as exc:
        return False, None, _error_code("tcp", exc), 0


def probe_udp(
    host: str,
    port: int,
    target_host: str,
    target_port: int,
    timeout_seconds: int,
) -> tuple[bool, Optional[int], str, int]:
    started = time.monotonic()
    identifier = int.from_bytes(os.urandom(2), "big")
    request = build_udp_request(target_host, target_port, build_dns_query(identifier))
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as connection:
            connection.settimeout(timeout_seconds)
            connection.sendto(request, (host, port))
            packet, _ = connection.recvfrom(4096)
        latency = max(0, int((time.monotonic() - started) * 1000))
        response = decode_udp_response(packet, identifier)
        return response.ok, latency, response.error_code, len(packet)
    except (OSError, ValueError) as exc:
        return False, None, _error_code("udp", exc), 0


def probe_once(config: dict[str, Any]) -> ProbeResult:
    tcp_host, tcp_port = parse_endpoint(config["tcp_target"], "tcp_target")
    udp_host, udp_port = parse_endpoint(config["udp_target"], "udp_target")
    tcp_ok, tcp_latency, tcp_error, _ = probe_tcp(
        config["host"], config["port"], tcp_host, tcp_port, config["timeout_seconds"]
    )
    udp_ok, udp_latency, udp_error, _ = probe_udp(
        config["host"], config["port"], udp_host, udp_port, config["timeout_seconds"]
    )
    errors = [error for error in (tcp_error, udp_error) if error]
    return ProbeResult(
        tcp_ok and udp_ok,
        tcp_ok,
        udp_ok,
        tcp_latency,
        udp_latency,
        "+".join(errors),
        tcp_error,
        udp_error,
    )


def load_config_dict(raw: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(raw, dict):
        raise ConfigError("configuration must be a JSON object")
    host = raw.get("host", DEFAULT_HOST)
    if not isinstance(host, str) or not host.strip() or "\x00" in host:
        raise ConfigError("host is invalid")
    password = raw.get("password")
    if not isinstance(password, str) or not password:
        raise ConfigError("password is required")
    if "\r" in password or "\n" in password:
        raise ConfigError("password must not contain CR/LF")
    method = str(raw.get("method", "none")).strip().lower()
    if method != "none":
        raise ConfigError("only method none is supported")
    state_path = str(raw.get("state_path", "./ss-none-probe.status.json"))
    events_path = str(raw.get("events_path", "./ss-none-probe.events.jsonl"))
    if not state_path or not events_path:
        raise ConfigError("state_path and events_path are required")
    _integer(raw.get("port", DEFAULT_PORT), "port", 1, 65535)
    interval = _integer(
        raw.get("interval_seconds", DEFAULT_INTERVAL_SECONDS),
        "interval_seconds",
        1,
        3600,
    )
    timeout = _integer(
        raw.get("timeout_seconds", DEFAULT_TIMEOUT_SECONDS), "timeout_seconds", 1, 300
    )
    window = _integer(
        raw.get("window_seconds", DEFAULT_WINDOW_SECONDS), "window_seconds", 60, 7 * 86400
    )
    tcp_target = raw.get("tcp_target", DEFAULT_TCP_TARGET)
    udp_target = raw.get("udp_target", DEFAULT_UDP_TARGET)
    parse_endpoint(tcp_target, "tcp_target")
    parse_endpoint(udp_target, "udp_target")
    label = raw.get("label", "")
    if not isinstance(label, str):
        raise ConfigError("label must be a string")
    return {
        "host": host.strip(),
        "port": _integer(raw.get("port", DEFAULT_PORT), "port", 1, 65535),
        "password": password,
        "method": method,
        "interval_seconds": interval,
        "timeout_seconds": timeout,
        "window_seconds": window,
        "state_path": state_path,
        "events_path": events_path,
        "tcp_target": str(tcp_target).strip(),
        "udp_target": str(udp_target).strip(),
        "label": label.strip(),
    }


def load_config_file(path: str) -> dict[str, Any]:
    try:
        with open(path, "r", encoding="utf-8") as handle:
            raw = json.load(handle)
    except (OSError, json.JSONDecodeError) as exc:
        raise ConfigError(f"configuration unavailable: {type(exc).__name__}") from exc
    return load_config_dict(raw)


def public_config(config: dict[str, Any]) -> dict[str, Any]:
    return {
        "host": config["host"],
        "port": config["port"],
        "method": config["method"],
        "transport": "tcp+udp",
        "credential_configured": bool(config.get("password")),
        "interval_seconds": config["interval_seconds"],
        "timeout_seconds": config["timeout_seconds"],
        "window_seconds": config["window_seconds"],
        "tcp_target": config["tcp_target"],
        "udp_target": config["udp_target"],
        "label": config["label"],
    }


def _utc(value: float) -> str:
    return (
        _datetime.datetime.fromtimestamp(value, _datetime.timezone.utc)
        .isoformat(timespec="seconds")
        .replace("+00:00", "Z")
    )


def _atomic_json(path: str, payload: dict[str, Any]) -> None:
    destination = pathlib.Path(path)
    destination.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(
        prefix=f".{destination.name}.", suffix=".tmp", dir=str(destination.parent)
    )
    try:
        os.fchmod(descriptor, 0o600)
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            descriptor = -1
            json.dump(payload, handle, ensure_ascii=True, sort_keys=True, separators=(",", ":"))
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, destination)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


class AvailabilityMonitor:
    def __init__(
        self,
        host: str,
        port: int,
        password: str,
        method: str,
        interval_seconds: int,
        timeout_seconds: int,
        window_seconds: int,
        state_path: str,
        events_path: str,
        now: Callable[[], float] = time.time,
        label: str = "",
    ) -> None:
        if not password:
            raise ConfigError("password is required")
        if method.lower() != "none":
            raise ConfigError("only method none is supported")
        self.host = host
        self.port = port
        self.method = "none"
        self.interval_seconds = interval_seconds
        self.timeout_seconds = timeout_seconds
        self.window_seconds = window_seconds
        self.state_path = state_path
        self.events_path = events_path
        self.label = label
        self.now = now
        self.samples: Deque[dict[str, Any]] = collections.deque()
        self.state = "starting"
        self.lifecycle = "starting"
        self.started_at = self.now()
        self.next_summary_at = self.started_at
        self._load_events()
        self._prune(self.now())
        self._write_status(self.started_at)

    def _load_events(self) -> None:
        try:
            with open(self.events_path, "r", encoding="utf-8") as handle:
                for line in handle:
                    try:
                        event = json.loads(line)
                    except json.JSONDecodeError:
                        continue
                    if isinstance(event, dict) and isinstance(event.get("timestamp"), (int, float)):
                        self.samples.append(event)
        except FileNotFoundError:
            return
        except OSError:
            LOG.warning("ss_none_probe event history unavailable")

    def _prune(self, timestamp: float) -> None:
        cutoff = timestamp - self.window_seconds
        while self.samples and float(self.samples[0].get("timestamp", 0)) < cutoff:
            self.samples.popleft()

    def _append_event(self, event: dict[str, Any]) -> None:
        destination = pathlib.Path(self.events_path)
        destination.parent.mkdir(parents=True, exist_ok=True)
        with open(destination, "a", encoding="utf-8") as handle:
            os.chmod(destination, 0o600)
            handle.write(json.dumps(event, ensure_ascii=True, sort_keys=True, separators=(",", ":")))
            handle.write("\n")

    def _write_status(self, timestamp: float) -> None:
        self._prune(timestamp)
        count = len(self.samples)
        successes = sum(1 for sample in self.samples if sample.get("ok"))
        tcp_successes = sum(1 for sample in self.samples if sample.get("tcp_ok"))
        udp_successes = sum(1 for sample in self.samples if sample.get("udp_ok"))
        failures = count - successes
        consecutive_failures = 0
        for sample in reversed(self.samples):
            if sample.get("ok"):
                break
            consecutive_failures += 1
        last_success = next((sample for sample in reversed(self.samples) if sample.get("ok")), None)
        last_failure = next((sample for sample in reversed(self.samples) if not sample.get("ok")), None)
        last = self.samples[-1] if self.samples else None
        state = {
            "schema_version": 1,
            "label": self.label,
            "state": self.state,
            "lifecycle": self.lifecycle,
            "method": self.method,
            "transport": "tcp+udp",
            "host": self.host,
            "port": self.port,
            "credential_configured": True,
            "window_seconds": self.window_seconds,
            "window_start": _utc(timestamp - self.window_seconds),
            "started_at": _utc(self.started_at),
            "updated_at": _utc(timestamp),
            "sample_count": count,
            "success_count": successes,
            "failure_count": failures,
            "tcp_success_count": tcp_successes,
            "udp_success_count": udp_successes,
            "availability_percent": round((successes * 100.0 / count), 3) if count else 0.0,
            "consecutive_failures": consecutive_failures,
            "last_success_at": _utc(float(last_success["timestamp"])) if last_success else "",
            "last_failure_at": _utc(float(last_failure["timestamp"])) if last_failure else "",
            "last_latency_ms": last.get("latency_ms") if last else None,
            "last_error": last.get("error_code", "") if last else "",
            "last_tcp_error": last.get("tcp_error_code", "") if last else "",
            "last_udp_error": last.get("udp_error_code", "") if last else "",
        }
        _atomic_json(self.state_path, state)

    def _log_summary(self, timestamp: float, immediate: bool = False) -> None:
        count = len(self.samples)
        successes = sum(1 for sample in self.samples if sample.get("ok"))
        availability = round((successes * 100.0 / count), 3) if count else 0.0
        failures = 0
        for sample in reversed(self.samples):
            if sample.get("ok"):
                break
            failures += 1
        prefix = "ALERT " if immediate and self.state == "down" else ""
        LOG.info(
            "%sss_none_probe state=%s host=%s port=%d samples=%d success=%d failure=%d "
            "availability_percent=%.3f consecutive_failures=%d last_error=%s",
            prefix,
            self.state.upper(),
            self.host,
            self.port,
            count,
            successes,
            count - successes,
            availability,
            failures,
            self.samples[-1].get("error_code", "") if self.samples else "",
        )
        self.next_summary_at = timestamp + 300

    def record(self, result: ProbeResult) -> None:
        timestamp = self.now()
        self._prune(timestamp)
        event = {
            "timestamp": timestamp,
            "at": _utc(timestamp),
            "ok": result.ok,
            "tcp_ok": result.tcp_ok,
            "udp_ok": result.udp_ok,
            "tcp_latency_ms": result.tcp_latency_ms,
            "udp_latency_ms": result.udp_latency_ms,
            "latency_ms": max(
                [latency for latency in (result.tcp_latency_ms, result.udp_latency_ms) if latency is not None],
                default=None,
            ),
            "error_code": result.error_code,
            "tcp_error_code": result.tcp_error_code,
            "udp_error_code": result.udp_error_code,
        }
        previous = self.state
        self.samples.append(event)
        self.state = "up" if result.ok else "down"
        self.lifecycle = "running"
        self._append_event(event)
        self._write_status(timestamp)
        if previous != self.state:
            self._log_summary(timestamp, immediate=self.state == "down")
        elif timestamp >= self.next_summary_at:
            self._log_summary(timestamp)

    def stop(self) -> None:
        self.lifecycle = "stopped"
        self._write_status(self.now())
        LOG.info("ss_none_probe stopped host=%s port=%d", self.host, self.port)

    def run(
        self,
        config: dict[str, Any],
        stop_event: Optional[Any] = None,
        probe: Callable[[dict[str, Any]], ProbeResult] = probe_once,
    ) -> None:
        event = stop_event or __import__("threading").Event()
        self.lifecycle = "running"
        self._write_status(self.now())
        LOG.info(
            "ss_none_probe started host=%s port=%d method=none transport=tcp+udp "
            "interval_seconds=%d window_seconds=%d",
            self.host,
            self.port,
            self.interval_seconds,
            self.window_seconds,
        )
        try:
            while not event.is_set():
                try:
                    self.record(probe(config))
                except Exception as exc:  # keep the watchdog alive on probe bugs
                    LOG.exception("ss_none_probe internal probe error type=%s", type(exc).__name__)
                    self.record(ProbeResult(False, False, False, None, None, "probe_internal_error"))
                event.wait(self.interval_seconds)
        finally:
            self.stop()


def _build_config(arguments: argparse.Namespace) -> dict[str, Any]:
    if arguments.config:
        config = load_config_file(arguments.config)
    else:
        config = load_config_dict(
            {
                "host": arguments.host,
                "port": arguments.port,
                "password": arguments.password,
                "method": arguments.method,
                "interval_seconds": arguments.interval_seconds,
                "timeout_seconds": arguments.timeout_seconds,
                "window_seconds": arguments.window_seconds,
                "state_path": arguments.state_path,
                "events_path": arguments.events_path,
                "tcp_target": arguments.tcp_target,
                "udp_target": arguments.udp_target,
                "label": arguments.label,
            }
        )
    return config


def main(argv: Optional[list[str]] = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", help="private JSON configuration file")
    parser.add_argument("--once", action="store_true", help="run one TCP+UDP probe and exit")
    parser.add_argument("--host", default=DEFAULT_HOST)
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    parser.add_argument("--password")
    parser.add_argument("--method", default="none")
    parser.add_argument("--interval-seconds", type=int, default=DEFAULT_INTERVAL_SECONDS)
    parser.add_argument("--timeout-seconds", type=int, default=DEFAULT_TIMEOUT_SECONDS)
    parser.add_argument("--window-seconds", type=int, default=DEFAULT_WINDOW_SECONDS)
    parser.add_argument("--state-path", default="./ss-none-probe.status.json")
    parser.add_argument("--events-path", default="./ss-none-probe.events.jsonl")
    parser.add_argument("--tcp-target", default=DEFAULT_TCP_TARGET)
    parser.add_argument("--udp-target", default=DEFAULT_UDP_TARGET)
    parser.add_argument("--label", default="")
    arguments = parser.parse_args(argv)
    try:
        config = _build_config(arguments)
    except ConfigError as exc:
        parser.error(str(exc))
        return 2

    if arguments.once:
        result = probe_once(config)
        print(
            json.dumps(
                {
                    "ok": result.ok,
                    "tcp_ok": result.tcp_ok,
                    "udp_ok": result.udp_ok,
                    "tcp_latency_ms": result.tcp_latency_ms,
                    "udp_latency_ms": result.udp_latency_ms,
                    "error_code": result.error_code,
                },
                sort_keys=True,
            )
        )
        return 0 if result.ok else 1

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%Y-%m-%dT%H:%M:%SZ",
    )
    stop_event = __import__("threading").Event()
    for signal_number in (signal.SIGINT, signal.SIGTERM):
        signal.signal(signal_number, lambda *_args: stop_event.set())
    monitor = AvailabilityMonitor(
        config["host"],
        config["port"],
        config["password"],
        config["method"],
        config["interval_seconds"],
        config["timeout_seconds"],
        config["window_seconds"],
        config["state_path"],
        config["events_path"],
        label=config["label"],
    )
    monitor.run(config, stop_event=stop_event)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
