#!/usr/bin/env python3
"""BR1 tcp-accel slave bridge helper.

This helper intentionally contains only the SOCKS5 bridge required for the
pilot tcp-accel topology:

    client -> slave bridge listener -> LeiGod SOCKS5 line -> sidecar tcp-accel server

It does not render or manage BR1 forwarding rules and it must not be used as a
client-to-sidecar direct connector.
"""
from __future__ import annotations

import argparse
import asyncio
import contextlib
import ipaddress
import json
import logging
import socket
import struct
import sys
import time
from pathlib import Path
from typing import Any

SOCKS_VERSION = 5
SOCKS_NO_AUTH = 0
SOCKS_REPLY_SUCCEEDED = 0
DEFAULT_CONNECT_TIMEOUT_SECONDS = 10.0
DEFAULT_TCP_USER_TIMEOUT_MS = 30_000
DEFAULT_HALF_CLOSE_DRAIN_TIMEOUT_MS = 2_000
SLAVE_LISTEN_PORT_MIN = 7000
SLAVE_LISTEN_PORT_MAX = 9000
MAX_BRIDGE_LISTENERS = 64


class BridgeConfigError(ValueError):
    """Invalid bridge config or failed SOCKS5 handshake."""


def parse_host_port(value: str, *, allow_zero: bool = False) -> tuple[str, int]:
    text = str(value or "").strip()
    host, sep, port_text = text.rpartition(":")
    if not sep or not host:
        raise BridgeConfigError(f"expected host:port, got {value!r}")
    if host.startswith("[") and host.endswith("]"):
        host = host[1:-1]
    if not host:
        raise BridgeConfigError(f"expected host:port, got {value!r}")
    try:
        port = int(port_text)
    except ValueError as exc:
        raise BridgeConfigError(f"invalid port in {value!r}") from exc
    minimum = 0 if allow_zero else 1
    if not minimum <= port <= 65535:
        raise BridgeConfigError(f"port out of range in {value!r}")
    return host, port


def _validate_number(
    raw: dict[str, Any],
    key: str,
    default: int | float,
    *,
    minimum: int | float,
    maximum: int | float,
    integer: bool = False,
) -> int | float:
    value = raw.get(key, default)
    if isinstance(value, bool):
        raise BridgeConfigError(f"bridge field {key} must be numeric")
    try:
        parsed = int(value) if integer else float(value)
    except (TypeError, ValueError) as exc:
        raise BridgeConfigError(f"bridge field {key} must be numeric") from exc
    if not minimum <= parsed <= maximum:
        raise BridgeConfigError(f"bridge field {key} must be {minimum}..{maximum}")
    return parsed


def validate_bridge_config(raw: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(raw, dict):
        raise BridgeConfigError("bridge config must be an object")
    remote = str(raw.get("remote", "")).strip()
    parse_host_port(remote)

    listeners = raw.get("listeners")
    if not isinstance(listeners, list) or not listeners:
        raise BridgeConfigError("bridge config requires listeners")
    if len(listeners) > MAX_BRIDGE_LISTENERS:
        raise BridgeConfigError(f"bridge supports at most {MAX_BRIDGE_LISTENERS} listeners")

    normalized: list[dict[str, Any]] = []
    seen_listeners: set[str] = set()
    for item in listeners:
        if not isinstance(item, dict):
            raise BridgeConfigError("bridge listener must be an object")
        listen = str(item.get("listen", "")).strip()
        socks = str(item.get("socks", "")).strip()
        listen_host, listen_port = parse_host_port(listen)
        parse_host_port(socks)
        if not SLAVE_LISTEN_PORT_MIN <= listen_port <= SLAVE_LISTEN_PORT_MAX:
            raise BridgeConfigError(
                f"bridge listener port {listen_port} must be within "
                f"{SLAVE_LISTEN_PORT_MIN}-{SLAVE_LISTEN_PORT_MAX}"
            )
        line_id = int(item.get("line_id", 0) or 0)
        if line_id <= 0:
            raise BridgeConfigError("bridge listener line_id must be positive")
        listener_key = f"{listen_host}:{listen_port}"
        if listener_key in seen_listeners:
            raise BridgeConfigError("bridge listener ports must be distinct")
        seen_listeners.add(listener_key)
        normalized.append({"listen": listener_key, "socks": socks, "line_id": line_id})

    return {
        "remote": remote,
        "connect_timeout_seconds": _validate_number(
            raw,
            "connect_timeout_seconds",
            DEFAULT_CONNECT_TIMEOUT_SECONDS,
            minimum=0.2,
            maximum=300.0,
        ),
        "tcp_user_timeout_ms": _validate_number(
            raw,
            "tcp_user_timeout_ms",
            DEFAULT_TCP_USER_TIMEOUT_MS,
            minimum=1,
            maximum=3_600_000,
            integer=True,
        ),
        "half_close_drain_timeout_ms": _validate_number(
            raw,
            "half_close_drain_timeout_ms",
            DEFAULT_HALF_CLOSE_DRAIN_TIMEOUT_MS,
            minimum=0,
            maximum=60_000,
            integer=True,
        ),
        "listeners": normalized,
    }


def encode_socks5_connect(host: str, port: int) -> bytes:
    host_text = str(host or "").strip()
    if host_text.startswith("[") and host_text.endswith("]"):
        host_text = host_text[1:-1]
    if not host_text or not 1 <= int(port) <= 65535:
        raise BridgeConfigError("invalid SOCKS5 CONNECT target")
    try:
        address = ipaddress.ip_address(host_text)
    except ValueError:
        try:
            encoded_host = host_text.encode("idna")
        except UnicodeError as exc:
            raise BridgeConfigError("invalid SOCKS5 domain") from exc
        if not encoded_host or len(encoded_host) > 255:
            raise BridgeConfigError("invalid SOCKS5 domain length")
        return b"\x05\x01\x00\x03" + bytes([len(encoded_host)]) + encoded_host + struct.pack("!H", int(port))
    if address.version == 4:
        return b"\x05\x01\x00\x01" + address.packed + struct.pack("!H", int(port))
    return b"\x05\x01\x00\x04" + address.packed + struct.pack("!H", int(port))


async def _read_socks_address(reader: asyncio.StreamReader, atyp: int) -> None:
    if atyp == 1:
        await reader.readexactly(4)
    elif atyp == 3:
        length = (await reader.readexactly(1))[0]
        await reader.readexactly(length)
    elif atyp == 4:
        await reader.readexactly(16)
    else:
        raise BridgeConfigError(f"unsupported SOCKS5 reply address type {atyp}")
    await reader.readexactly(2)


def _set_tcp_options(writer: asyncio.StreamWriter, *, tcp_user_timeout_ms: int | None) -> None:
    sock = writer.get_extra_info("socket")
    if sock is None:
        return
    with contextlib.suppress(OSError):
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    if tcp_user_timeout_ms:
        tcp_user_timeout = getattr(socket, "TCP_USER_TIMEOUT", None)
        if tcp_user_timeout is not None:
            with contextlib.suppress(OSError):
                sock.setsockopt(socket.IPPROTO_TCP, tcp_user_timeout, int(tcp_user_timeout_ms))


async def socks5_connect(
    socks_addr: str,
    remote_addr: str,
    *,
    timeout_seconds: float = DEFAULT_CONNECT_TIMEOUT_SECONDS,
    tcp_user_timeout_ms: int = DEFAULT_TCP_USER_TIMEOUT_MS,
) -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
    socks_host, socks_port = parse_host_port(socks_addr)
    remote_host, remote_port = parse_host_port(remote_addr)
    reader, writer = await asyncio.wait_for(
        asyncio.open_connection(socks_host, socks_port), timeout=max(0.2, float(timeout_seconds))
    )
    _set_tcp_options(writer, tcp_user_timeout_ms=tcp_user_timeout_ms)
    try:
        writer.write(b"\x05\x01\x00")
        await writer.drain()
        version, method = await reader.readexactly(2)
        if version != SOCKS_VERSION or method != SOCKS_NO_AUTH:
            raise BridgeConfigError("LeiGod SOCKS5 did not accept no-auth mode")
        writer.write(encode_socks5_connect(remote_host, remote_port))
        await writer.drain()
        reply = await reader.readexactly(4)
        if reply[0] != SOCKS_VERSION or reply[1] != SOCKS_REPLY_SUCCEEDED:
            raise BridgeConfigError(f"LeiGod SOCKS5 CONNECT rejected with code {reply[1]}")
        await _read_socks_address(reader, reply[3])
        return reader, writer
    except Exception:
        writer.close()
        with contextlib.suppress(Exception):
            await writer.wait_closed()
        raise


async def relay_streams_with_timeout(
    client_reader: asyncio.StreamReader,
    client_writer: asyncio.StreamWriter,
    upstream_reader: asyncio.StreamReader,
    upstream_writer: asyncio.StreamWriter,
    *,
    half_close_drain_timeout_ms: int = DEFAULT_HALF_CLOSE_DRAIN_TIMEOUT_MS,
) -> dict[str, int | str]:
    counters = {"client_to_remote": 0, "remote_to_client": 0}

    async def pump(src: asyncio.StreamReader, dst: asyncio.StreamWriter, key: str) -> None:
        try:
            while True:
                data = await src.read(65536)
                if not data:
                    break
                counters[key] += len(data)
                dst.write(data)
                await dst.drain()
        finally:
            if dst.can_write_eof():
                with contextlib.suppress(OSError):
                    dst.write_eof()
            else:
                dst.close()

    tasks = [
        asyncio.create_task(pump(client_reader, upstream_writer, "client_to_remote")),
        asyncio.create_task(pump(upstream_reader, client_writer, "remote_to_client")),
    ]
    done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
    if pending and half_close_drain_timeout_ms > 0:
        done2, pending = await asyncio.wait(pending, timeout=half_close_drain_timeout_ms / 1000)
        done |= done2
    for task in pending:
        task.cancel()
    await asyncio.gather(*tasks, return_exceptions=True)
    return {
        "client_to_remote": counters["client_to_remote"],
        "remote_to_client": counters["remote_to_client"],
        "close_reason": "eof" if not pending else "timeout",
    }


async def handle_bridge_connection(
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    socks_addr: str,
    remote_addr: str,
    *,
    line_id: int,
    timeout_seconds: float,
    tcp_user_timeout_ms: int,
    half_close_drain_timeout_ms: int,
) -> None:
    upstream_writer: asyncio.StreamWriter | None = None
    started = time.monotonic()
    relay_stats: dict[str, int | str] = {
        "client_to_remote": 0,
        "remote_to_client": 0,
        "close_reason": "connect_failed",
    }
    try:
        _set_tcp_options(writer, tcp_user_timeout_ms=tcp_user_timeout_ms)
        upstream_reader, upstream_writer = await socks5_connect(
            socks_addr,
            remote_addr,
            timeout_seconds=timeout_seconds,
            tcp_user_timeout_ms=tcp_user_timeout_ms,
        )
        relay_stats = await relay_streams_with_timeout(
            reader,
            writer,
            upstream_reader,
            upstream_writer,
            half_close_drain_timeout_ms=half_close_drain_timeout_ms,
        )
    except (ConnectionError, OSError, asyncio.IncompleteReadError, asyncio.TimeoutError, BridgeConfigError) as exc:
        relay_stats["close_reason"] = type(exc).__name__
        logging.warning(
            "tcp-accel bridge failed line=%s socks=%s remote=%s error=%s",
            line_id,
            socks_addr,
            remote_addr,
            exc,
        )
    finally:
        writer.close()
        with contextlib.suppress(Exception):
            await writer.wait_closed()
        if upstream_writer is not None:
            upstream_writer.close()
            with contextlib.suppress(Exception):
                await upstream_writer.wait_closed()
        logging.info(
            "tcp-accel bridge closed line=%s socks=%s remote=%s duration_ms=%s "
            "client_to_remote_bytes=%s remote_to_client_bytes=%s close_reason=%s",
            line_id,
            socks_addr,
            remote_addr,
            round((time.monotonic() - started) * 1000),
            relay_stats["client_to_remote"],
            relay_stats["remote_to_client"],
            relay_stats["close_reason"],
        )


async def start_bridge_servers(config: dict[str, Any]) -> list[asyncio.AbstractServer]:
    validated = validate_bridge_config(config)
    remote = validated["remote"]
    timeout_seconds = float(validated["connect_timeout_seconds"])
    tcp_user_timeout_ms = int(validated["tcp_user_timeout_ms"])
    half_close_drain_timeout_ms = int(validated["half_close_drain_timeout_ms"])
    servers: list[asyncio.AbstractServer] = []
    for item in validated["listeners"]:
        host, port = parse_host_port(item["listen"])
        server = await asyncio.start_server(
            lambda reader, writer, socks=item["socks"], line_id=item["line_id"]: handle_bridge_connection(
                reader,
                writer,
                socks,
                remote,
                line_id=line_id,
                timeout_seconds=timeout_seconds,
                tcp_user_timeout_ms=tcp_user_timeout_ms,
                half_close_drain_timeout_ms=half_close_drain_timeout_ms,
            ),
            host,
            port,
        )
        servers.append(server)
    return servers


async def serve_many_forever(servers: list[asyncio.AbstractServer]) -> None:
    sockets: list[str] = []
    for server in servers:
        sockets.extend(f"{sock.getsockname()[0]}:{sock.getsockname()[1]}" for sock in (server.sockets or []))
    logging.info("tcp-accel bridge listening on %s", ", ".join(sockets))
    try:
        await asyncio.gather(*(server.serve_forever() for server in servers))
    finally:
        for server in servers:
            server.close()
        await asyncio.gather(*(server.wait_closed() for server in servers), return_exceptions=True)


async def run_bridge_from_config(raw: dict[str, Any]) -> None:
    servers = await start_bridge_servers(raw)
    await serve_many_forever(servers)


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="BR1 tcp-accel SOCKS5 slave bridge")
    parser.add_argument("--log-level", default="INFO", choices=["DEBUG", "INFO", "WARNING", "ERROR"])
    sub = parser.add_subparsers(dest="command", required=True)
    bridge = sub.add_parser("bridge", help="run bridge listeners from JSON config")
    bridge.add_argument("--config", required=True)
    validate = sub.add_parser("bridge-validate", help="validate a bridge config without opening listeners")
    validate.add_argument("--config", required=True)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_arg_parser().parse_args(argv)
    logging.basicConfig(level=getattr(logging, args.log_level), format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    try:
        raw = json.loads(Path(args.config).read_text(encoding="utf-8"))
        if args.command == "bridge-validate":
            validate_bridge_config(raw)
            print("ok")
            return 0
        if args.command == "bridge":
            asyncio.run(run_bridge_from_config(raw))
            return 0
    except KeyboardInterrupt:
        return 130
    except (OSError, json.JSONDecodeError, BridgeConfigError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    return 2


if __name__ == "__main__":
    sys.exit(main())