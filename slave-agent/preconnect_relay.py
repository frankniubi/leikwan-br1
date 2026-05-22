#!/usr/bin/env python3
"""Leikwan Slave Pre-Connect MPTCP Relay.

This relay runs on the slave node and pre-establishes MPTCP connections to the
sidecar. When a client connects (plain TCP through the WG tunnel), the relay
opens a fresh MPTCP stream to the sidecar, performs the handshake on behalf of
the client, and then relays bidirectionally.

This moves the "hard" MPTCP connection setup to the slave, which:
- Already has the WG underlay running and health-checked
- Can retry/reconnect to sidecar without involving the client
- Makes client-side debugging trivial (client only needs plain TCP)

Usage:
    python3 preconnect_relay.py --config /path/to/config.json

Config JSON keys:
    listen_host         - Address to bind (slave WG address, e.g. 10.203.201.134)
    listen_port         - Port to listen on for client connections
    sidecar_host        - Sidecar WG address (e.g. 10.203.201.135)
    sidecar_port        - Sidecar MPTCP listener port
    tcp_session_token   - Session token for TCP handshake
    udp_session_token   - Session token for UDP handshake (optional)
    forward_id          - Forward ID for the handshake
    transport           - "tcp", "udp", or "tcp+udp"
    health_port         - Port for health check endpoint (listen_port + 1 default)
    max_connections     - Maximum concurrent relayed connections (default 256)
    connect_timeout_s   - Timeout for MPTCP connect to sidecar (default 5)
    tcp_congestion      - TCP congestion control algorithm (default "bbr")
    socket_buffer_bytes - Socket buffer size (default 8MB)
    log_level           - Logging level (default INFO)
"""
from __future__ import annotations

import argparse
import asyncio
import contextlib
import json
import logging
import os
import pathlib
import socket
import struct
import sys
import time
from typing import Any

MPTCP_PROTOCOL = getattr(socket, "IPPROTO_MPTCP", 262)
DEFAULT_SOCKET_BUFFER_BYTES = 8 * 1024 * 1024
RELAY_PIPE_CHUNK = 256 * 1024
HEALTH_CHECK_INTERVAL_S = 10
VERSION = "2026.05.22.1"


def load_config(path: pathlib.Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(payload, dict):
        raise RuntimeError(f"config must be a JSON object: {path}")
    return payload


def tune_socket(sock: socket.socket, config: dict[str, Any]) -> None:
    buf_size = int(config.get("socket_buffer_bytes", DEFAULT_SOCKET_BUFFER_BYTES) or DEFAULT_SOCKET_BUFFER_BYTES)
    with contextlib.suppress(Exception):
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, buf_size)
    with contextlib.suppress(Exception):
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, buf_size)
    with contextlib.suppress(Exception):
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    cc = str(config.get("tcp_congestion", "bbr") or "bbr").strip()
    if cc and hasattr(socket, "TCP_CONGESTION"):
        with contextlib.suppress(Exception):
            sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_CONGESTION, cc.encode("ascii"))


async def connect_mptcp_to_sidecar(config: dict[str, Any]) -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
    """Open an MPTCP (or TCP fallback) connection to the sidecar."""
    sidecar_host = str(config.get("sidecar_host", "")).strip()
    sidecar_port = int(config.get("sidecar_port", 0))
    connect_timeout = float(config.get("connect_timeout_s", 5) or 5)

    if not sidecar_host or sidecar_port <= 0:
        raise RuntimeError("sidecar_host/sidecar_port not configured")

    loop = asyncio.get_running_loop()
    infos = await loop.getaddrinfo(sidecar_host, sidecar_port, type=socket.SOCK_STREAM)

    last_error: Exception | None = None
    protocols = [MPTCP_PROTOCOL, socket.IPPROTO_TCP]

    for family, socktype, _proto, _canonname, sockaddr in infos:
        for protocol in protocols:
            sock: socket.socket | None = None
            try:
                sock = socket.socket(family, socktype, protocol)
                tune_socket(sock, config)
                sock.settimeout(connect_timeout)
                await asyncio.to_thread(sock.connect, sockaddr)
                sock.setblocking(False)
                reader, writer = await asyncio.open_connection(sock=sock)
                proto_name = "MPTCP" if protocol == MPTCP_PROTOCOL else "TCP-fallback"
                logging.info("connected to sidecar %s:%s via %s", sidecar_host, sidecar_port, proto_name)
                return reader, writer
            except Exception as exc:
                last_error = exc
                if sock is not None:
                    with contextlib.suppress(Exception):
                        sock.close()

    raise RuntimeError(f"failed to connect to sidecar {sidecar_host}:{sidecar_port}: {last_error}")


async def send_handshake(writer: asyncio.StreamWriter, config: dict[str, Any], protocol: str = "tcp") -> None:
    """Send the session handshake to sidecar on behalf of the client."""
    token_key = "tcp_session_token" if protocol == "tcp" else "udp_session_token"
    payload = {
        "protocol": protocol,
        "session_token": str(config.get(token_key, "") or ""),
        "forward_id": int(config.get("forward_id", 0) or 0),
        "slave_preconnect": True,
    }
    encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    writer.write(struct.pack(">I", len(encoded)))
    writer.write(encoded)
    await writer.drain()
    logging.debug("sent handshake (%d bytes) for forward_id=%s", len(encoded), config.get("forward_id"))


async def pipe(
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    label: str,
) -> int:
    """Relay data from reader to writer. Returns bytes forwarded."""
    total = 0
    try:
        while True:
            chunk = await reader.read(RELAY_PIPE_CHUNK)
            if not chunk:
                break
            writer.write(chunk)
            total += len(chunk)
            await writer.drain()
    except (ConnectionResetError, BrokenPipeError, asyncio.IncompleteReadError):
        pass
    except Exception as exc:
        logging.debug("%s relay error: %s", label, exc)
    # Half-close
    with contextlib.suppress(Exception):
        if writer.can_write_eof():
            writer.write_eof()
        else:
            sock = writer.get_extra_info("socket")
            if sock is not None:
                sock.shutdown(socket.SHUT_WR)
    return total




class PreConnectRelay:
    """TCP server that accepts client connections and relays to sidecar via MPTCP."""

    def __init__(self, config: dict[str, Any]) -> None:
        self.config = config
        self.transport = str(config.get("transport", "tcp") or "tcp").strip().lower()
        self.listen_host = str(config.get("listen_host", "0.0.0.0")).strip()
        self.listen_port = int(config.get("listen_port", 0))
        self.uot_listen_host = str(config.get("uot_listen_host", self.listen_host) or self.listen_host).strip()
        configured_uot_port = int(config.get("uot_listen_port", 0) or 0)
        self.uot_listen_port = configured_uot_port if configured_uot_port > 0 else (self.listen_port + 1 if self.transport_includes_udp() else 0)
        default_health_port = (self.uot_listen_port + 1) if self.transport_includes_udp() and self.uot_listen_port > 0 else (self.listen_port + 1)
        self.health_host = str(config.get("health_host", self.listen_host) or self.listen_host).strip()
        self.health_port = int(config.get("health_port", 0) or 0) or default_health_port
        self.max_connections = int(config.get("max_connections", 256) or 256)
        self.active_connections = 0
        self.total_connections = 0
        self.total_bytes_in = 0
        self.total_bytes_out = 0
        self.last_sidecar_check_ok = False
        self.last_sidecar_check_at = 0.0
        self.started_at = time.time()

    async def handle_client(self, client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter) -> None:
        """Handle a single TCP client connection."""
        await self.handle_stream_client(client_reader, client_writer, "tcp")

    async def handle_stream_client(
        self,
        client_reader: asyncio.StreamReader,
        client_writer: asyncio.StreamWriter,
        protocol: str,
    ) -> None:
        """Relay one length-preserved stream to sidecar with a protocol handshake."""
        peer = client_writer.get_extra_info("peername")
        conn_id = self.total_connections
        self.total_connections += 1
        self.active_connections += 1

        try:
            if self.active_connections > self.max_connections:
                logging.warning("relay: rejecting %s connection from %s (limit %d reached)", protocol, peer, self.max_connections)
                client_writer.close()
                await client_writer.wait_closed()
                return

            logging.info("relay conn #%d: accepted %s from %s", conn_id, protocol, peer)

            try:
                sidecar_reader, sidecar_writer = await connect_mptcp_to_sidecar(self.config)
            except Exception as exc:
                logging.error("relay conn #%d: sidecar connect failed: %s", conn_id, exc)
                client_writer.close()
                with contextlib.suppress(Exception):
                    await client_writer.wait_closed()
                return

            try:
                await send_handshake(sidecar_writer, self.config, protocol)
            except Exception as exc:
                logging.error("relay conn #%d: handshake failed: %s", conn_id, exc)
                sidecar_writer.close()
                client_writer.close()
                return

            results = await asyncio.gather(
                pipe(client_reader, sidecar_writer, f"conn#{conn_id} client->sidecar"),
                pipe(sidecar_reader, client_writer, f"conn#{conn_id} sidecar->client"),
                return_exceptions=True,
            )

            bytes_out = results[0] if isinstance(results[0], int) else 0
            bytes_in = results[1] if isinstance(results[1], int) else 0
            self.total_bytes_out += bytes_out
            self.total_bytes_in += bytes_in

            logging.info("relay conn #%d: done protocol=%s (in=%d out=%d)", conn_id, protocol, bytes_in, bytes_out)

        except Exception as exc:
            logging.error("relay conn #%d: unexpected error: %s", conn_id, exc)
        finally:
            self.active_connections -= 1
            with contextlib.suppress(Exception):
                client_writer.close()
                await client_writer.wait_closed()

    def transport_includes_udp(self) -> bool:
        return self.transport in {"udp", "tcp+udp"}

    async def start_uot_listener(self) -> asyncio.AbstractServer:
        return await asyncio.start_server(
            lambda reader, writer: self.handle_stream_client(reader, writer, "udp"),
            host=self.uot_listen_host,
            port=self.uot_listen_port,
        )

    async def health_check_loop(self) -> None:
        """Periodically verify sidecar reachability."""
        while True:
            try:
                reader, writer = await connect_mptcp_to_sidecar(self.config)
                # Send a minimal handshake to verify the sidecar accepts.
                probe_protocol = "tcp" if self.transport in {"tcp", "tcp+udp"} else "udp"
                await send_handshake(writer, self.config, probe_protocol)
                # Close the probe connection
                writer.close()
                with contextlib.suppress(Exception):
                    await writer.wait_closed()
                self.last_sidecar_check_ok = True
            except Exception as exc:
                logging.warning("health check: sidecar unreachable: %s", exc)
                self.last_sidecar_check_ok = False
            self.last_sidecar_check_at = time.time()
            await asyncio.sleep(HEALTH_CHECK_INTERVAL_S)

    async def handle_health_request(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        """Simple HTTP health endpoint."""
        try:
            # Read HTTP request line (we don't care about the full request)
            await asyncio.wait_for(reader.readline(), timeout=5.0)

            status = "ok" if self.last_sidecar_check_ok else "degraded"
            body = json.dumps({
                "status": status,
                "version": VERSION,
                "forward_id": int(self.config.get("forward_id", 0) or 0),
                "sidecar_host": str(self.config.get("sidecar_host", "")),
                "sidecar_port": int(self.config.get("sidecar_port", 0)),
                "transport": self.transport,
                "tcp_listen_port": self.listen_port,
                "uot_listen_port": self.uot_listen_port,
                "last_check_ok": self.last_sidecar_check_ok,
                "last_check_at": self.last_sidecar_check_at,
                "active_connections": self.active_connections,
                "total_connections": self.total_connections,
                "total_bytes_in": self.total_bytes_in,
                "total_bytes_out": self.total_bytes_out,
                "uptime_seconds": int(time.time() - self.started_at),
            }, indent=2)

            http_status = 200 if self.last_sidecar_check_ok else 503
            response = (
                f"HTTP/1.1 {http_status} {'OK' if http_status == 200 else 'Service Unavailable'}\r\n"
                f"Content-Type: application/json\r\n"
                f"Content-Length: {len(body)}\r\n"
                f"Connection: close\r\n"
                f"\r\n"
                f"{body}"
            )
            writer.write(response.encode("utf-8"))
            await writer.drain()
        except Exception:
            pass
        finally:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def run(self) -> None:
        """Start the relay server."""
        logging.info(
            "preconnect relay v%s starting: listen=%s:%d sidecar=%s:%d forward_id=%d",
            VERSION,
            self.listen_host,
            self.listen_port,
            self.config.get("sidecar_host", ""),
            self.config.get("sidecar_port", 0),
            self.config.get("forward_id", 0),
        )

        # Start health check loop
        asyncio.create_task(self.health_check_loop())

        # Start health HTTP endpoint
        health_server = await asyncio.start_server(
            self.handle_health_request,
            host=self.health_host,
            port=self.health_port,
        )
        logging.info("health endpoint on %s:%d", self.health_host, self.health_port)

        # Start main TCP relay server if the forward carries TCP.
        relay_server = None
        if self.transport in {"tcp", "tcp+udp"}:
            relay_server = await asyncio.start_server(
                self.handle_client,
                host=self.listen_host,
                port=self.listen_port,
            )
            logging.info("tcp relay listening on %s:%d", self.listen_host, self.listen_port)

        # UDP is carried as UoT: client UDP datagrams are length-framed over a
        # plain TCP stream to this listener, then relayed to sidecar over MPTCP.
        uot_server = None
        if self.transport_includes_udp():
            if self.uot_listen_port <= 0:
                raise RuntimeError("transport includes udp but uot_listen_port is not configured")
            uot_server = await self.start_uot_listener()
            logging.info("uot tcp relay listening on %s:%d", self.uot_listen_host, self.uot_listen_port)

        if relay_server is None and uot_server is None:
            raise RuntimeError("transport must include tcp and/or udp")

        tasks = [health_server.serve_forever()]
        if relay_server is not None:
            tasks.append(relay_server.serve_forever())
        if uot_server is not None:
            tasks.append(uot_server.serve_forever())

        try:
            await asyncio.gather(*tasks)
        finally:
            if relay_server is not None:
                relay_server.close()
            if uot_server is not None:
                uot_server.close()
            health_server.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Leikwan Slave Pre-Connect MPTCP Relay")
    parser.add_argument("--config", required=True, help="Path to config JSON")
    args = parser.parse_args()

    config = load_config(pathlib.Path(args.config))

    log_level = str(config.get("log_level", "INFO")).upper()
    logging.basicConfig(
        level=getattr(logging, log_level, logging.INFO),
        format="%(asctime)s %(levelname)s [preconnect-relay] %(message)s",
    )

    try:
        asyncio.run(PreConnectRelay(config).run())
    except KeyboardInterrupt:
        logging.info("shutting down")
    except Exception as exc:
        logging.error("fatal: %s", exc)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
