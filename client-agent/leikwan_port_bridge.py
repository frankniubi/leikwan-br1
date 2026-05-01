#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import contextlib
import json
import logging
import pathlib
import socket
import time
from dataclasses import dataclass
from typing import Any

MPTCP_PROTOCOL = getattr(socket, "IPPROTO_MPTCP", 262)


def load_json(path: pathlib.Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(payload, dict):
        raise RuntimeError(f"config must be an object: {path}")
    return payload


@dataclass
class UdpAssociation:
    transport: asyncio.DatagramTransport
    last_seen_at: float


class RemoteUdpProtocol(asyncio.DatagramProtocol):
    def __init__(self, owner: "UdpBridge", client_addr: tuple[str, int]) -> None:
        self.owner = owner
        self.client_addr = client_addr

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        if self.owner.transport is not None:
            self.owner.transport.sendto(data, self.client_addr)

    def connection_lost(self, exc: Exception | None) -> None:
        self.owner.associations.pop(self.client_addr, None)


class UdpBridge(asyncio.DatagramProtocol):
    def __init__(self, target_host: str, target_port: int) -> None:
        self.target_host = target_host
        self.target_port = target_port
        self.transport: asyncio.DatagramTransport | None = None
        self.associations: dict[tuple[str, int], UdpAssociation] = {}

    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.transport = transport  # type: ignore[assignment]

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        asyncio.create_task(self.forward_to_target(data, addr))

    async def forward_to_target(self, data: bytes, addr: tuple[str, int]) -> None:
        assoc = self.associations.get(addr)
        if assoc is None:
            loop = asyncio.get_running_loop()
            transport, _ = await loop.create_datagram_endpoint(
                lambda: RemoteUdpProtocol(self, addr),
                remote_addr=(self.target_host, self.target_port),
            )
            assoc = UdpAssociation(transport=transport, last_seen_at=time.time())
            self.associations[addr] = assoc
        assoc.last_seen_at = time.time()
        assoc.transport.sendto(data)

    async def reap(self, idle_timeout: float = 30.0) -> None:
        while True:
            await asyncio.sleep(5)
            now = time.time()
            expired = [
                addr for addr, assoc in self.associations.items()
                if now - assoc.last_seen_at > idle_timeout
            ]
            for addr in expired:
                assoc = self.associations.pop(addr, None)
                if assoc is not None:
                    assoc.transport.close()


async def pipe(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    while True:
        try:
            chunk = await reader.read(256 * 1024)
        except (BrokenPipeError, ConnectionResetError):
            break
        if not chunk:
            break
        try:
            writer.write(chunk)
            await writer.drain()
        except (BrokenPipeError, ConnectionResetError):
            break
    with contextlib.suppress(Exception):
        if writer.can_write_eof():
            writer.write_eof()


async def close_writer(writer: asyncio.StreamWriter) -> None:
    with contextlib.suppress(Exception):
        writer.close()
        await writer.wait_closed()


def _create_listen_socket(host: str, port: int) -> socket.socket:
    """Create a listening socket, preferring MPTCP so that inbound MP_CAPABLE
    handshakes are accepted and the MPTCP session is preserved across the bridge."""
    family = socket.AF_INET6 if ":" in host else socket.AF_INET
    for proto in (MPTCP_PROTOCOL, socket.IPPROTO_TCP):
        try:
            sock = socket.socket(family, socket.SOCK_STREAM, proto)
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            sock.bind((host, port))
            sock.listen(socket.SOMAXCONN)
            sock.setblocking(False)
            label = "MPTCP" if proto == MPTCP_PROTOCOL else "TCP"
            logging.info("port-bridge listening on %s:%d (%s)", host, port, label)
            return sock
        except Exception:
            continue
    raise RuntimeError(f"unable to bind listener on {host}:{port}")


async def _open_mptcp_connection(host: str, port: int) -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
    """Open an outbound stream connection, preferring MPTCP so that the full
    MPTCP session is forwarded into the target network namespace."""
    loop = asyncio.get_running_loop()
    family = socket.AF_INET
    for proto in (MPTCP_PROTOCOL, socket.IPPROTO_TCP):
        sock: socket.socket | None = None
        try:
            sock = socket.socket(family, socket.SOCK_STREAM, proto)
            sock.setblocking(False)
            await loop.sock_connect(sock, (host, port))
            return await asyncio.open_connection(sock=sock)
        except Exception:
            if sock is not None:
                sock.close()
            continue
    raise RuntimeError(f"unable to connect to {host}:{port}")


async def run_tcp_bridge(listen_port: int, target_host: str, target_port: int) -> asyncio.AbstractServer:
    async def handler(client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter) -> None:
        remote_reader, remote_writer = await _open_mptcp_connection(target_host, target_port)
        try:
            await asyncio.gather(
                pipe(client_reader, remote_writer),
                pipe(remote_reader, client_writer),
            )
        finally:
            await asyncio.gather(
                close_writer(remote_writer),
                close_writer(client_writer),
            )

    sock = _create_listen_socket("0.0.0.0", listen_port)
    return await asyncio.start_server(handler, sock=sock)


async def main_async(config: dict[str, Any]) -> int:
    logging.basicConfig(
        level=getattr(logging, str(config.get("log_level", "INFO")).upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    listen_port = int(config.get("listen_port", 0) or 0)
    if listen_port <= 0:
        raise RuntimeError("listen_port missing")
    transport = str(config.get("bridge_transport", config.get("transport", "tcp+udp")) or "tcp+udp").strip().lower()
    netns = config.get("netns", {})
    if not isinstance(netns, dict) or not netns.get("enabled"):
        raise RuntimeError("netns mode is required for the local bridge")
    target_host = str(netns.get("namespace_address", "") or "").strip()
    if target_host == "":
        raise RuntimeError("netns.namespace_address missing")

    tcp_server = None
    udp_transport = None
    udp_protocol = None
    if transport in {"tcp", "tcp+udp"}:
        tcp_server = await run_tcp_bridge(listen_port, target_host, listen_port)
    if transport in {"udp", "tcp+udp"}:
        loop = asyncio.get_running_loop()
        udp_protocol = UdpBridge(target_host, listen_port)
        udp_transport, _ = await loop.create_datagram_endpoint(
            lambda: udp_protocol,
            local_addr=("0.0.0.0", listen_port),
        )
        asyncio.create_task(udp_protocol.reap())

    if tcp_server is None and udp_transport is None:
        raise RuntimeError("transport must include tcp and/or udp")

    try:
        await asyncio.Future()
    finally:
        if tcp_server is not None:
            tcp_server.close()
            await tcp_server.wait_closed()
        if udp_transport is not None:
            udp_transport.close()
        if udp_protocol is not None:
            for assoc in list(udp_protocol.associations.values()):
                assoc.transport.close()
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Leikwan host-to-netns local port bridge")
    parser.add_argument("--config", required=True)
    args = parser.parse_args()
    config = load_json(pathlib.Path(args.config))
    return asyncio.run(main_async(config))


if __name__ == "__main__":
    raise SystemExit(main())
