#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import contextlib
import json
import logging
import ssl
import time
from pathlib import Path
from typing import Any

MAX_HTTP_HEADER_BYTES = 65536
HTTP_HEADER_TIMEOUT_SECONDS = 10


def normalize_path_prefix(path_prefix: str) -> str:
    normalized = str(path_prefix).strip().strip('/')
    if normalized == '':
        raise RuntimeError('path_prefix missing')
    return normalized


def normalized_route_path(path_prefix: str) -> str:
    return '/' + normalize_path_prefix(path_prefix)


def path_matches_prefix(request_path: str, route_path: str) -> bool:
    if request_path == route_path:
        return True
    return request_path.startswith(route_path + '/')


class WssPathMux:
    def __init__(self, *, public_port: int, routes_path: Path, tls_cert_path: str, tls_key_path: str, listen_host: str = '0.0.0.0') -> None:
        self.public_port = public_port
        self.routes_path = routes_path
        self.tls_cert_path = tls_cert_path
        self.tls_key_path = tls_key_path
        self.listen_host = listen_host
        self._routes_mtime_ns = -1
        self._routes: list[dict[str, Any]] = []

    def current_routes(self) -> list[dict[str, Any]]:
        try:
            stat = self.routes_path.stat()
        except FileNotFoundError:
            self._routes_mtime_ns = -1
            self._routes = []
            return self._routes
        if stat.st_mtime_ns == self._routes_mtime_ns:
            return self._routes
        payload = json.loads(self.routes_path.read_text(encoding='utf-8'))
        routes: list[dict[str, Any]] = []
        for entry in payload.get('routes', []):
            if not isinstance(entry, dict):
                continue
            backend_target = str(entry.get('target', '') or '').strip()
            host, _, port_text = backend_target.rpartition(':')
            backend_port = int(entry.get('backend_port', 0) or 0)
            if backend_port > 0:
                backend_host = str(entry.get('backend_host', '') or '').strip() or '127.0.0.1'
            else:
                if host.strip() == '' or port_text.strip() == '':
                    continue
                backend_host = host
                backend_port = int(port_text)
            if backend_host.strip() == '' or backend_port <= 0:
                continue
            routes.append(
                {
                    'path_prefix': normalize_path_prefix(str(entry.get('path_prefix', ''))),
                    'route_path': normalized_route_path(str(entry.get('path_prefix', ''))),
                    'backend_host': backend_host,
                    'backend_port': backend_port,
                }
            )
        routes.sort(key=lambda item: len(str(item['route_path'])), reverse=True)
        self._routes_mtime_ns = stat.st_mtime_ns
        self._routes = routes
        return self._routes

    def resolve_route(self, request_path: str) -> dict[str, Any] | None:
        for route in self.current_routes():
            if path_matches_prefix(request_path, str(route['route_path'])):
                return route
        return None

    async def read_http_head(self, reader: asyncio.StreamReader) -> bytes:
        buffer = bytearray()
        deadline = time.monotonic() + HTTP_HEADER_TIMEOUT_SECONDS
        while b'\r\n\r\n' not in buffer:
            if len(buffer) >= MAX_HTTP_HEADER_BYTES:
                raise RuntimeError('request headers too large')
            timeout = max(0.1, deadline - time.monotonic())
            chunk = await asyncio.wait_for(reader.read(4096), timeout=timeout)
            if not chunk:
                break
            buffer.extend(chunk)
        return bytes(buffer)

    @staticmethod
    def parse_headers(head: bytes) -> tuple[str, dict[str, str]]:
        lines = head.decode('latin-1', errors='ignore').split('\r\n')
        request_line = lines[0] if lines else ''
        headers: dict[str, str] = {}
        for line in lines[1:]:
            if line == '':
                break
            if ':' not in line:
                continue
            key, value = line.split(':', 1)
            headers[key.strip().lower()] = value.strip()
        return request_line, headers

    @staticmethod
    def is_websocket_upgrade(headers: dict[str, str]) -> bool:
        upgrade = headers.get('upgrade', '').lower()
        connection_tokens = [token.strip().lower() for token in headers.get('connection', '').split(',')]
        return upgrade == 'websocket' and 'upgrade' in connection_tokens

    @staticmethod
    async def relay(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            while True:
                chunk = await reader.read(65536)
                if not chunk:
                    break
                writer.write(chunk)
                await writer.drain()
        except Exception:
            pass
        finally:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def proxy_request(self, client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter, route: dict[str, Any], head: bytes) -> None:
        upstream_reader, upstream_writer = await asyncio.open_connection(str(route['backend_host']), int(route['backend_port']))
        upstream_writer.write(head)
        await upstream_writer.drain()
        await asyncio.gather(
            self.relay(client_reader, upstream_writer),
            self.relay(upstream_reader, client_writer),
        )

    async def handle_client(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            head = await self.read_http_head(reader)
            request_line, headers = self.parse_headers(head)
            parts = request_line.split(' ')
            if len(parts) < 2:
                writer.close()
                return
            request_path = parts[1]
            route = self.resolve_route(request_path)
            if route is None or not self.is_websocket_upgrade(headers):
                writer.close()
                return
            await self.proxy_request(reader, writer, route, head)
        except Exception:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def serve(self) -> None:
        ssl_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ssl_context.load_cert_chain(self.tls_cert_path, self.tls_key_path)
        server = await asyncio.start_server(self.handle_client, self.listen_host, self.public_port, ssl=ssl_context)
        async with server:
            await server.serve_forever()


def main() -> int:
    parser = argparse.ArgumentParser(description='Leikwan WSS path mux')
    parser.add_argument('--listen-port', type=int, required=True)
    parser.add_argument('--tls-cert', required=True)
    parser.add_argument('--tls-key', required=True)
    parser.add_argument('--routes-file', required=True)
    parser.add_argument('--listen-host', default='0.0.0.0')
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    mux = WssPathMux(public_port=args.listen_port, routes_path=Path(args.routes_file), tls_cert_path=args.tls_cert, tls_key_path=args.tls_key, listen_host=args.listen_host)
    asyncio.run(mux.serve())
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
