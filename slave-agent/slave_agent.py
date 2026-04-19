#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import contextlib
import email.utils
import html
import json
import logging
import os
import re
import shutil
import socket
import ssl
import subprocess
import sys
import time
from pathlib import Path
from typing import Any
from urllib import error, parse, request

AGENT_VERSION = "2026.04.17.2"
MAX_HTTP_HEADER_BYTES = 65536
HTTP_HEADER_TIMEOUT_SECONDS = 10
IDENTITY_REFRESH_SECONDS = 300
UPDATE_RETRY_SECONDS = 300
UPDATE_STALE_SECONDS = 900

DEFAULT_CONFIG = {
    "master_url": "",
    "node_id": None,
    "node_token": "",
    "enrollment_token": "",
    "machine_id": "",
    "name": "",
    "region": "unknown",
    "group_key": "default",
    "credential_profile_id": None,
    "public_ip": "",
    "private_ip": "",
    "supports_udp": True,
    "supports_filtering": False,
    "supports_mptcp": False,
    "supports_quic": False,
    "supports_bond_hybrid": True,
    "supports_sidecar_quic": True,
    "bond_protocols": ["tcp"],
    "hybrid_modes": ["quic_ws"],
    "wireguard_listen_port": 0,
    "wireguard_fallback_tcp_port": 0,
    "leg_relay_bin": "/usr/local/bin/leikwan-leg-relay",
    "ingress_bin": "/usr/local/bin/leikwan-ingress",
    "wstunnel_bin": "/usr/local/bin/wstunnel",
    "tls_cert_path": "/etc/wstunnel/ssl/server.crt",
    "tls_key_path": "/etc/wstunnel/ssl/server.key",
    "landing_page_path": "/etc/wstunnel/api-default.html",
    "tls_mode": "self-signed",
    "tls_domain": "",
    "tls_email": "",
    "tls_wildcard": 0,
    "tls_cert_pem": "",
    "tls_key_pem": "",
    "cloudflare_email": "",
    "cloudflare_global_key": "",
    "systemd_dir": "/etc/systemd/system",
    "state_dir": "/var/lib/leikwan-slave",
    "backend_host": "127.0.0.1",
    "backend_port_range_start": 20000,
    "backend_port_range_end": 40000,
    "mux_listen_hosts": ["::", "0.0.0.0"],
    "relay_quic_keepalive_seconds": 5,
    "relay_quic_max_idle_seconds": 45,
    "relay_quic_prefetch_connections": 4,
    "relay_quic_initial_mtu": 1200,
    "relay_quic_min_mtu": 1200,
    "relay_quic_mtu_upper_bound": 1452,
    "relay_quic_mtu_discovery_interval_seconds": 300,
    "relay_quic_mtu_black_hole_cooldown_seconds": 30,
    "relay_quic_mtu_minimum_change": 20,
    "poll_interval_seconds": 5,
    "heartbeat_interval_seconds": 30,
    "usage_interval_seconds": 60,
    "log_level": "INFO",
}


def load_config(path: Path) -> dict[str, Any]:
    data = dict(DEFAULT_CONFIG)
    if path.is_file():
        loaded = json.loads(path.read_text(encoding="utf-8-sig"))
        if isinstance(loaded, dict):
            data.update(loaded)
    return data


def save_config(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


def file_sha256(path: Path) -> str:
    import hashlib

    if not path.exists() or not path.is_file():
        return ""
    hasher = hashlib.sha256()
    with path.open("rb") as fh:
        while True:
            chunk = fh.read(1024 * 1024)
            if not chunk:
                break
            hasher.update(chunk)
    return hasher.hexdigest()


def is_global_ipv4(value: str) -> bool:
    parts = value.strip().split(".")
    if len(parts) != 4:
        return False
    try:
        octets = [int(part) for part in parts]
    except ValueError:
        return False
    if any(octet < 0 or octet > 255 for octet in octets):
        return False
    first, second, *_ = octets
    if first == 0 or first == 10 or first == 127:
        return False
    if first == 169 and second == 254:
        return False
    if first == 172 and 16 <= second <= 31:
        return False
    if first == 192 and second == 168:
        return False
    if first >= 224:
        return False
    return True


def first_ipv4_candidate(text: str) -> str:
    match = re.search(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", text)
    if not match:
        return ""
    candidate = match.group(0)
    return candidate if is_global_ipv4(candidate) else ""


def run_command(command: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    logging.debug("run: %s", command)
    result = subprocess.run(command, shell=True, text=True, capture_output=True)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {command}\nstdout={result.stdout}\nstderr={result.stderr}")
    return result


def build_url(base_url: str, path: str, query: dict[str, Any] | None = None) -> str:
    url = base_url.rstrip("/") + path
    if query:
        url += "?" + parse.urlencode(query)
    return url


def http_json(method: str, url: str, token: str | None = None, payload: dict[str, Any] | None = None) -> dict[str, Any]:
    body = None
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": f"Leikwan-Slave-Agent/{AGENT_VERSION}",
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if payload is not None:
        body = json.dumps(payload).encode("utf-8")

    req = request.Request(url, data=body, headers=headers, method=method.upper())
    try:
        with request.urlopen(req, timeout=30) as response:
            content = response.read().decode("utf-8")
    except error.HTTPError as exc:
        content = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"http {exc.code} {url}: {content}") from exc
    except error.URLError as exc:
        raise RuntimeError(f"http request failed {url}: {exc}") from exc

    data = json.loads(content) if content else {}
    if not isinstance(data, dict):
        raise RuntimeError(f"unexpected response from {url}: {content}")
    return data


def http_json_with_headers(method: str, url: str, token: str | None = None, payload: dict[str, Any] | None = None) -> tuple[dict[str, Any], dict[str, str]]:
    body = None
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": f"Leikwan-Slave-Agent/{AGENT_VERSION}",
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"
    if payload is not None:
        body = json.dumps(payload).encode("utf-8")

    req = request.Request(url, data=body, headers=headers, method=method.upper())
    try:
        with request.urlopen(req, timeout=30) as response:
            content = response.read().decode("utf-8")
            response_headers = {key.lower(): value for key, value in response.headers.items()}
    except error.HTTPError as exc:
        content = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"http {exc.code} {url}: {content}") from exc
    except error.URLError as exc:
        raise RuntimeError(f"http request failed {url}: {exc}") from exc

    data = json.loads(content) if content else {}
    if not isinstance(data, dict):
        raise RuntimeError(f"unexpected response from {url}: {content}")
    return data, response_headers


def safe_name(service_id: int, endpoint_id: int) -> str:
    return f"wstunnel-svc-{service_id}-{endpoint_id}"


def mux_service_name(listen_port: int) -> str:
    return f"leikwan-wss-mux-{listen_port}"


def plain_tcp_mux_service_name(listen_port: int) -> str:
    return f"leikwan-tcp-mux-{listen_port}"


def relay_service_name(service_id: int, endpoint_id: int) -> str:
    return f"leikwan-relay-{service_id}-{endpoint_id}"


def relay_client_service_name(service_id: int, endpoint_id: int) -> str:
    return f"leikwan-relay-client-{service_id}-{endpoint_id}"


def normalize_path_prefix(path_prefix: str) -> str:
    normalized = str(path_prefix).strip().strip("/")
    if normalized == "":
        raise RuntimeError("path_prefix missing")
    return normalized


def normalized_route_path(path_prefix: str) -> str:
    return "/" + normalize_path_prefix(path_prefix)


def udp_uot_shadow_service_name(service_name: str) -> str:
    return service_name + "-udp-uot"


def replace_target_port(target: str, new_port: int) -> str:
    host, _, port_text = str(target).rpartition(":")
    if host.strip() == "" or port_text.strip() == "":
        raise RuntimeError(f"invalid target host:port: {target}")
    return f"{host}:{new_port}"


def path_matches_prefix(request_path: str, route_path: str) -> bool:
    if request_path == route_path:
        return True
    return request_path.startswith(route_path + "/")


def shell_arg(value: str) -> str:
    escaped = value.replace("\\", "\\\\").replace('"', '\\"')
    return f'"{escaped}"'


def split_host_port(value: str) -> tuple[str, int]:
    candidate = str(value).strip()
    if candidate.startswith("["):
        match = re.fullmatch(r"\[(.+)\]:(\d+)", candidate)
        if not match:
            raise RuntimeError(f"invalid host:port value: {value}")
        return match.group(1), int(match.group(2))

    host, separator, port_text = candidate.rpartition(":")
    if separator == "" or host.strip() == "" or port_text.strip() == "":
        raise RuntimeError(f"invalid host:port value: {value}")
    return host.strip(), int(port_text.strip())


class WssPathMux:
    def __init__(
        self,
        *,
        public_port: int,
        routes_path: Path,
        tls_cert_path: str,
        tls_key_path: str,
        landing_page_path: str,
        listen_hosts: list[str],
    ) -> None:
        self.public_port = public_port
        self.routes_path = routes_path
        self.tls_cert_path = tls_cert_path
        self.tls_key_path = tls_key_path
        self.landing_page_path = landing_page_path
        self.listen_hosts = listen_hosts
        self._routes_mtime_ns = -1
        self._routes: list[dict[str, Any]] = []

    def current_routes(self) -> list[dict[str, Any]]:
        try:
            stat = self.routes_path.stat()
        except FileNotFoundError:
            if self._routes_mtime_ns != -1:
                logging.info("mux port=%s routes file removed, clearing routes", self.public_port)
            self._routes_mtime_ns = -1
            self._routes = []
            return self._routes

        if stat.st_mtime_ns == self._routes_mtime_ns:
            return self._routes

        try:
            payload = json.loads(self.routes_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            logging.error("mux port=%s invalid routes file %s: %s", self.public_port, self.routes_path, exc)
            return self._routes
        if not isinstance(payload, dict):
            logging.error("mux port=%s routes file must contain an object", self.public_port)
            return self._routes
        routes: list[dict[str, Any]] = []
        for entry in payload.get("routes", []):
            if not isinstance(entry, dict):
                continue
            try:
                backend_port = int(entry.get("backend_port", 0))
                if backend_port <= 0:
                    continue
                routes.append(
                    {
                        "path_prefix": normalize_path_prefix(str(entry.get("path_prefix", ""))),
                        "route_path": normalized_route_path(str(entry.get("path_prefix", ""))),
                        "backend_host": str(entry.get("backend_host", "127.0.0.1")) or "127.0.0.1",
                        "backend_port": backend_port,
                        "service_name": str(entry.get("service_name", "")),
                    }
                )
            except Exception as exc:  # noqa: BLE001
                logging.warning("mux port=%s skipped invalid route entry: %s", self.public_port, exc)
                continue

        routes.sort(key=lambda item: len(str(item["route_path"])), reverse=True)
        self._routes_mtime_ns = stat.st_mtime_ns
        self._routes = routes
        logging.info("mux port=%s loaded %s route(s)", self.public_port, len(routes))
        return self._routes

    def resolve_route(self, request_path: str) -> dict[str, Any] | None:
        for route in self.current_routes():
            if path_matches_prefix(request_path, str(route["route_path"])):
                return route
        return None

    @staticmethod
    async def send_response(
        writer: asyncio.StreamWriter,
        status_code: int,
        reason: str,
        body: str | bytes,
        content_type: str = "text/plain; charset=utf-8",
    ) -> None:
        encoded = body.encode("utf-8") if isinstance(body, str) else body
        writer.write(
            (
                f"HTTP/1.1 {status_code} {reason}\r\n"
                f"Content-Type: {content_type}\r\n"
                f"Content-Length: {len(encoded)}\r\n"
                "Connection: close\r\n"
                "\r\n"
            ).encode("utf-8")
            + encoded
        )
        await writer.drain()
        writer.close()
        with contextlib.suppress(Exception):
            await writer.wait_closed()

    @staticmethod
    async def send_error(writer: asyncio.StreamWriter, status_code: int, reason: str, body: str) -> None:
        await WssPathMux.send_response(writer, status_code, reason, body)

    @staticmethod
    async def relay(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            while True:
                chunk = await reader.read(65536)
                if not chunk:
                    break
                writer.write(chunk)
                await writer.drain()
        except Exception:  # noqa: BLE001
            logging.debug("relay closed", exc_info=True)
        finally:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def read_http_head(self, reader: asyncio.StreamReader) -> bytes:
        buffer = bytearray()
        deadline = time.monotonic() + HTTP_HEADER_TIMEOUT_SECONDS
        while b"\r\n\r\n" not in buffer:
            if len(buffer) >= MAX_HTTP_HEADER_BYTES:
                raise RuntimeError("request headers too large")
            timeout = max(0.1, deadline - time.monotonic())
            chunk = await asyncio.wait_for(reader.read(4096), timeout=timeout)
            if not chunk:
                break
            buffer.extend(chunk)
        return bytes(buffer)

    @staticmethod
    def parse_headers(head: bytes) -> tuple[str, dict[str, str]]:
        lines = head.decode("latin-1", errors="ignore").split("\r\n")
        request_line = lines[0] if lines else ""
        headers: dict[str, str] = {}
        for line in lines[1:]:
            if line == "":
                break
            if ":" not in line:
                continue
            key, value = line.split(":", 1)
            headers[key.strip().lower()] = value.strip()
        return request_line, headers

    @staticmethod
    def is_websocket_upgrade(headers: dict[str, str]) -> bool:
        upgrade = headers.get("upgrade", "").lower()
        connection_tokens = [token.strip().lower() for token in headers.get("connection", "").split(",")]
        return upgrade == "websocket" and "upgrade" in connection_tokens

    @staticmethod
    def render_public_landing_page(host: str) -> str:
        host_html = html.escape(host) if host else "this site"
        return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Welcome</title>
  <style>
    :root {{
      color-scheme: light;
      --bg-a: #f5f7fb;
      --bg-b: #ffffff;
      --text: #122033;
      --muted: #5d6b82;
      --card: rgba(255,255,255,0.92);
      --line: rgba(18,32,51,0.08);
      --accent: #0f766e;
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      min-height: 100vh;
      font-family: Georgia, "Times New Roman", serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(15,118,110,0.08), transparent 36%),
        linear-gradient(160deg, var(--bg-a), var(--bg-b));
      display: grid;
      place-items: center;
      padding: 24px;
    }}
    main {{
      width: min(720px, 100%);
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 20px;
      padding: 40px 32px;
      box-shadow: 0 24px 70px rgba(18,32,51,0.08);
    }}
    h1 {{
      margin: 0 0 12px;
      font-size: clamp(2rem, 4vw, 3.2rem);
      letter-spacing: 0.02em;
    }}
    p {{
      margin: 0 0 14px;
      line-height: 1.7;
      color: var(--muted);
      font-size: 1.02rem;
    }}
    .pill {{
      display: inline-block;
      margin-bottom: 16px;
      padding: 6px 12px;
      border-radius: 999px;
      background: rgba(15,118,110,0.08);
      color: var(--accent);
      font-size: 0.86rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }}
  </style>
</head>
<body>
  <main>
    <div class="pill">Online</div>
    <h1>Welcome</h1>
    <p>{host_html} is online and accepting secure traffic.</p>
    <p>If you expected an application response, please use the correct application path or contact the site administrator.</p>
  </main>
</body>
</html>
"""

    @classmethod
    def load_public_landing_page(cls, path: str, host: str) -> str:
        landing_path = Path(path)
        if landing_path.is_file():
            try:
                return landing_path.read_text(encoding="utf-8")
            except Exception:  # noqa: BLE001
                logging.exception("failed to read landing page file: %s", landing_path)
        return cls.render_public_landing_page(host)

    @classmethod
    def render_not_found_page(cls, host: str) -> str:
        host_html = html.escape(host) if host else "this site"
        return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>404 Not Found</title>
  <style>
    body {{
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 24px;
      background: #f6f7fb;
      color: #172033;
      font-family: Georgia, "Times New Roman", serif;
    }}
    main {{
      width: min(560px, 100%);
      background: #fff;
      border: 1px solid rgba(23,32,51,0.08);
      border-radius: 18px;
      padding: 32px 28px;
      box-shadow: 0 18px 50px rgba(23,32,51,0.08);
    }}
    h1 {{ margin: 0 0 10px; font-size: 2rem; }}
    p {{ margin: 0; line-height: 1.7; color: #5d6b82; }}
  </style>
</head>
<body>
  <main>
    <h1>404 Not Found</h1>
    <p>The requested page is not available on {host_html}.</p>
  </main>
</body>
</html>
"""

    async def handle_ready_probe(self, client_writer: asyncio.StreamWriter, request_path: str) -> None:
        route = self.resolve_route(request_path)
        if route is None:
            await self.send_error(client_writer, 404, "Not Found", "route_not_found")
            return

        try:
            _, backend_writer = await asyncio.open_connection(
                str(route["backend_host"]),
                int(route["backend_port"]),
            )
        except FileNotFoundError as exc:
            logging.error("mux port=%s backend unavailable during ready probe: %s", self.public_port, exc)
            await self.send_error(client_writer, 502, "Bad Gateway", "backend_unavailable")
            return
        except ConnectionRefusedError:
            logging.error("mux port=%s backend connection refused during ready probe", self.public_port)
            await self.send_error(client_writer, 502, "Bad Gateway", "backend_connection_refused")
            return
        except Exception:  # noqa: BLE001
            logging.exception("mux port=%s ready probe failed", self.public_port)
            await self.send_error(client_writer, 500, "Internal Server Error", "ready_probe_failed")
            return

        backend_writer.close()
        with contextlib.suppress(Exception):
            await backend_writer.wait_closed()

        payload = json.dumps(
            {
                "ok": True,
                "public_port": self.public_port,
                "path_prefix": route["path_prefix"],
                "route_path": route["route_path"],
                "service_name": route["service_name"],
                "backend_host": route["backend_host"],
                "backend_port": route["backend_port"],
            },
            ensure_ascii=False,
        )
        await self.send_response(client_writer, 200, "OK", payload, "application/json; charset=utf-8")

    async def handle_client(self, client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter) -> None:
        peer = client_writer.get_extra_info("peername")
        try:
            head = await self.read_http_head(client_reader)
            if not head:
                client_writer.close()
                with contextlib.suppress(Exception):
                    await client_writer.wait_closed()
                return

            request_line, headers = self.parse_headers(head)
            parts = request_line.split(" ")
            if len(parts) < 2:
                await self.send_error(client_writer, 400, "Bad Request", "invalid_request_line")
                return

            method = parts[0].upper()
            target = parts[1]
            path = parse.urlsplit(target).path or "/"
            host = headers.get("host", "")

            if path in {"/", "/index.html"} and method in {"GET", "HEAD"}:
                await self.send_response(
                    client_writer,
                    200,
                    "OK",
                    self.load_public_landing_page(self.landing_page_path, host),
                    "text/html; charset=utf-8",
                )
                return
            if path == "/favicon.ico":
                await self.send_response(client_writer, 204, "No Content", b"", "image/x-icon")
                return
            if path == "/robots.txt":
                await self.send_response(client_writer, 200, "OK", "User-agent: *\nDisallow:\n")
                return
            if path in {"/healthz", "/_leikwan/healthz"}:
                await self.send_error(client_writer, 200, "OK", "ok")
                return
            if path.startswith("/_leikwan/readyz/"):
                probe_path = "/" + path.removeprefix("/_leikwan/readyz/").lstrip("/")
                if probe_path == "/":
                    await self.send_error(client_writer, 400, "Bad Request", "path_prefix_missing")
                    return
                await self.handle_ready_probe(client_writer, probe_path)
                return
            if not self.is_websocket_upgrade(headers):
                await self.send_response(
                    client_writer,
                    404,
                    "Not Found",
                    self.render_not_found_page(host),
                    "text/html; charset=utf-8",
                )
                return

            route = self.resolve_route(path)
            if route is None:
                logging.warning("mux port=%s no route for path=%s peer=%s", self.public_port, path, peer)
                await self.send_error(client_writer, 404, "Not Found", "route_not_found")
                return

            backend_reader, backend_writer = await asyncio.open_connection(
                str(route["backend_host"]),
                int(route["backend_port"]),
            )
            backend_writer.write(head)
            await backend_writer.drain()
            logging.info(
                "mux port=%s path=%s service=%s backend=%s:%s peer=%s",
                self.public_port,
                path,
                route["service_name"],
                route["backend_host"],
                route["backend_port"],
                peer,
            )
            await asyncio.gather(
                self.relay(client_reader, backend_writer),
                self.relay(backend_reader, client_writer),
            )
        except asyncio.TimeoutError:
            await self.send_error(client_writer, 408, "Request Timeout", "header_timeout")
        except RuntimeError as exc:
            if str(exc) == "request headers too large":
                await self.send_error(client_writer, 431, "Request Header Fields Too Large", "headers_too_large")
            else:
                logging.exception("mux port=%s runtime error", self.public_port)
                if not client_writer.is_closing():
                    await self.send_error(client_writer, 500, "Internal Server Error", "mux_runtime_error")
        except FileNotFoundError as exc:
            logging.error("mux port=%s backend unavailable: %s", self.public_port, exc)
            await self.send_error(client_writer, 502, "Bad Gateway", "backend_unavailable")
        except ConnectionRefusedError:
            logging.error("mux port=%s backend connection refused", self.public_port)
            await self.send_error(client_writer, 502, "Bad Gateway", "backend_connection_refused")
        except Exception:  # noqa: BLE001
            logging.exception("mux port=%s unexpected error", self.public_port)
            if not client_writer.is_closing():
                await self.send_error(client_writer, 500, "Internal Server Error", "mux_internal_error")

    async def serve(self) -> None:
        ssl_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ssl_context.load_cert_chain(self.tls_cert_path, self.tls_key_path)

        servers: list[asyncio.AbstractServer] = []
        for host in self.listen_hosts:
            try:
                server = await asyncio.start_server(
                    self.handle_client,
                    host=host,
                    port=self.public_port,
                    ssl=ssl_context,
                    backlog=2048,
                )
                servers.append(server)
                logging.info("mux port=%s listening on %s", self.public_port, host)
            except OSError as exc:
                if servers:
                    logging.warning("mux port=%s could not also bind host=%s: %s", self.public_port, host, exc)
                    continue
                logging.warning("mux port=%s failed to bind host=%s: %s", self.public_port, host, exc)

        if not servers:
            raise RuntimeError(f"mux port={self.public_port} failed to bind any listen host")

        try:
            await asyncio.gather(*(server.serve_forever() for server in servers))
        finally:
            for server in servers:
                server.close()
                with contextlib.suppress(Exception):
                    await server.wait_closed()


class SlaveAgent:
    def __init__(self, config_path: Path):
        self.config_path = config_path
        self.config = load_config(config_path)
        logging.basicConfig(
            level=getattr(logging, str(self.config.get("log_level", "INFO")).upper(), logging.INFO),
            format="%(asctime)s %(levelname)s %(message)s",
        )
        self.state_dir = Path(str(self.config["state_dir"]))
        self.services_dir = self.state_dir / "services"
        self.listeners_dir = self.state_dir / "listeners"
        self.filters_dir = self.state_dir / "filters"
        self.usage_state_path = self.state_dir / "usage_state.json"
        self.pull_state_path = self.state_dir / "management_pull_state.json"
        self.last_identity_refresh_at = 0.0
        self.services_dir.mkdir(parents=True, exist_ok=True)
        self.listeners_dir.mkdir(parents=True, exist_ok=True)
        self.filters_dir.mkdir(parents=True, exist_ok=True)

    def wireguard_dir(self) -> Path:
        path = self.state_dir / "wireguard"
        path.mkdir(parents=True, exist_ok=True)
        return path

    def wireguard_private_key_path(self) -> Path:
        return self.wireguard_dir() / "private.key"

    def wireguard_public_key_path(self) -> Path:
        return self.wireguard_dir() / "public.key"

    def wireguard_listen_port(self) -> int:
        configured = int(self.config.get("wireguard_listen_port", 0) or 0)
        return configured if configured > 0 else 52000 + (int(self.config.get("node_id", 0) or 0) % 1000)

    def wireguard_fallback_tcp_port(self) -> int:
        configured = int(self.config.get("wireguard_fallback_tcp_port", 0) or 0)
        return configured if configured > 0 else self.wireguard_listen_port() + 10000

    @staticmethod
    def running_kernel_supports_mptcp() -> bool:
        return Path("/proc/sys/net/mptcp/enabled").exists()

    def ensure_wireguard_identity(self) -> str:
        private_key_path = self.wireguard_private_key_path()
        public_key_path = self.wireguard_public_key_path()
        if public_key_path.exists():
            try:
                value = public_key_path.read_text(encoding="utf-8").strip()
            except OSError:
                value = ""
            if value != "":
                return value
        if not shutil.which("wg"):
            return ""
        if not private_key_path.exists():
            result = subprocess.run(["wg", "genkey"], text=True, capture_output=True, check=False)
            private_key = result.stdout.strip()
            if result.returncode != 0 or private_key == "":
                return ""
            private_key_path.write_text(private_key + "\n", encoding="utf-8")
            if os.name != "nt":
                private_key_path.chmod(0o600)
        private_key = private_key_path.read_text(encoding="utf-8").strip()
        result = subprocess.run(["wg", "pubkey"], input=private_key + "\n", text=True, capture_output=True, check=False)
        public_key = result.stdout.strip()
        if result.returncode != 0 or public_key == "":
            return ""
        public_key_path.write_text(public_key + "\n", encoding="utf-8")
        if os.name != "nt":
            public_key_path.chmod(0o644)
        return public_key

    def load_pull_state(self) -> dict[str, Any]:
        if not self.pull_state_path.exists():
            return {}
        try:
            payload = json.loads(self.pull_state_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_pull_state(self, payload: dict[str, Any]) -> None:
        existing = self.load_pull_state()
        existing.update(payload)
        self.pull_state_path.write_text(json.dumps(existing, ensure_ascii=False, indent=2), encoding="utf-8")

    @staticmethod
    def detect_time_service() -> str:
        services = [
            ("ntp.service", "ntp"),
            ("ntpd.service", "ntpd"),
            ("chronyd.service", "chronyd"),
            ("systemd-timesyncd.service", "systemd-timesyncd"),
        ]
        if shutil.which("systemctl") is None:
            return ""
        for unit, name in services:
            result = subprocess.run(
                f"systemctl is-active {unit}",
                shell=True,
                text=True,
                capture_output=True,
                check=False,
            )
            if result.stdout.strip() == "active":
                return name
        return ""

    def time_sync_state(self) -> dict[str, Any]:
        if shutil.which("timedatectl") is None:
            return {"synchronized": None, "service": self.detect_time_service(), "status": "unknown"}
        result = subprocess.run(
            "timedatectl show -p NTPSynchronized --value",
            shell=True,
            text=True,
            capture_output=True,
            check=False,
        )
        value = result.stdout.strip().lower()
        synchronized = True if value == "yes" else False if value == "no" else None
        status = "ok" if synchronized is True else "warning" if synchronized is False else "unknown"
        return {
            "synchronized": synchronized,
            "service": self.detect_time_service(),
            "status": status,
        }

    def panel_clock_state_from_headers(self, headers: dict[str, str]) -> dict[str, Any]:
        local_state = self.time_sync_state()
        date_header = str(headers.get("date", "") or "").strip()
        if date_header == "":
            return {
                "status": local_state.get("status", "unknown"),
                "offset_seconds": None,
                "panel_time": "",
                "service": local_state.get("service", ""),
                "synchronized": local_state.get("synchronized"),
            }
        try:
            panel_dt = email.utils.parsedate_to_datetime(date_header)
            offset_seconds = round(time.time() - panel_dt.timestamp(), 3)
        except Exception:  # noqa: BLE001
            offset_seconds = None
            panel_time = date_header
            status = "unknown"
        else:
            panel_time = panel_dt.isoformat()
            absolute_offset = abs(offset_seconds)
            status = "ok" if absolute_offset <= 3 else "warning" if absolute_offset <= 10 else "error"
        return {
            "status": status,
            "offset_seconds": offset_seconds,
            "panel_time": panel_time,
            "service": local_state.get("service", ""),
            "synchronized": local_state.get("synchronized"),
        }

    def register(self) -> None:
        self.ensure_machine_id()
        self.refresh_network_identity(force=True)
        wireguard_public_key = self.ensure_wireguard_identity()
        payload = {
            "enrollment_token": self.config.get("enrollment_token", ""),
            "machine_id": self.config.get("machine_id", ""),
            "name": self.config.get("name") or socket.gethostname(),
            "region": self.config.get("region", "unknown"),
            "group_key": self.config.get("group_key", "default"),
            "credential_profile_id": self.config.get("credential_profile_id"),
            "public_ip": self.config.get("public_ip", ""),
            "private_ip": self.config.get("private_ip", ""),
            "supports_udp": bool(self.config.get("supports_udp", True)),
            "supports_filtering": bool(self.config.get("supports_filtering", False)),
            "supports_mptcp": self.running_kernel_supports_mptcp() or bool(self.config.get("supports_mptcp", False)),
            "supports_quic": bool(self.config.get("supports_quic", False)),
            "supports_bond_hybrid": bool(self.config.get("supports_bond_hybrid", True)),
            "supports_sidecar_quic": bool(self.config.get("supports_sidecar_quic", True)),
            "bond_protocols": list(self.config.get("bond_protocols", ["tcp"])),
            "hybrid_modes": list(self.config.get("hybrid_modes", ["quic_ws"])),
            "wireguard_public_key": wireguard_public_key,
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            "agent_version": AGENT_VERSION,
        }
        result = http_json("POST", build_url(self.config["master_url"], "/api/node/register"), None, payload)
        self.config["node_id"] = result["node_id"]
        self.config["node_token"] = result["api_token"]
        save_config(self.config_path, self.config)
        logging.info("registered node id=%s", result["node_id"])

    def run(self) -> None:
        if not self.config.get("node_token"):
            raise RuntimeError("node_token missing, run register first")

        try:
            self.refresh_network_identity(force=True)
        except Exception:  # noqa: BLE001
            logging.exception("network identity refresh failed")

        try:
            self.provision_inline_tls_assets()
        except Exception:  # noqa: BLE001
            logging.exception("inline tls provisioning failed")

        try:
            self.reconcile_runtime()
        except Exception:  # noqa: BLE001
            logging.exception("runtime reconciliation failed")

        try:
            self.cleanup_orphan_mptcp_underlays()
        except Exception:  # noqa: BLE001
            logging.exception("orphan mptcp underlay cleanup failed")

        next_heartbeat = 0.0
        next_usage = 0.0
        heartbeat_interval = max(5, int(self.config.get("heartbeat_interval_seconds", 30)))
        usage_interval = max(5, int(self.config.get("usage_interval_seconds", 60)))
        poll_interval = max(1, int(self.config.get("poll_interval_seconds", 5)))
        while True:
            now = time.time()
            if now >= next_heartbeat:
                try:
                    self.send_heartbeat()
                except Exception as exc:  # noqa: BLE001
                    logging.warning("heartbeat failed: %s", exc)
                next_heartbeat = now + heartbeat_interval
            if now >= next_usage:
                try:
                    self.report_usage()
                except Exception as exc:  # noqa: BLE001
                    logging.warning("usage report failed: %s", exc)
                next_usage = now + usage_interval
            try:
                self.process_tasks()
            except Exception:  # noqa: BLE001
                logging.exception("task processing loop failed")
            time.sleep(poll_interval)

    async def run_mux(self, public_port: int, routes_file: Path | None = None) -> None:
        mux = WssPathMux(
            public_port=public_port,
            routes_path=routes_file or self.listener_routes_path(public_port),
            tls_cert_path=str(self.config["tls_cert_path"]),
            tls_key_path=str(self.config["tls_key_path"]),
            landing_page_path=str(self.config.get("landing_page_path", "/etc/wstunnel/api-default.html")),
            listen_hosts=self.mux_listen_hosts(),
        )
        await mux.serve()

    def mux_listen_hosts(self) -> list[str]:
        configured = self.config.get("mux_listen_hosts", ["::", "0.0.0.0"])
        if isinstance(configured, str):
            candidates = [configured]
        elif isinstance(configured, list):
            candidates = [str(item) for item in configured]
        else:
            candidates = ["::", "0.0.0.0"]

        hosts: list[str] = []
        for host in candidates:
            normalized = host.strip()
            if normalized and normalized not in hosts:
                hosts.append(normalized)
        return hosts or ["::", "0.0.0.0"]

    def send_heartbeat(self) -> None:
        self.ensure_machine_id()
        self.refresh_network_identity()
        wireguard_public_key = self.ensure_wireguard_identity()
        local_clock = self.time_sync_state()
        payload = {
            "machine_id": self.config.get("machine_id", ""),
            "name": self.config.get("name") or socket.gethostname(),
            "region": self.config.get("region", "unknown"),
            "group_key": self.config.get("group_key", "default"),
            "public_ip": self.config.get("public_ip", ""),
            "private_ip": self.config.get("private_ip", ""),
            "status": "active",
            "current_load": self.active_service_count(),
            "supports_udp": bool(self.config.get("supports_udp", True)),
            "supports_filtering": bool(self.config.get("supports_filtering", False)),
            "supports_mptcp": self.running_kernel_supports_mptcp() or bool(self.config.get("supports_mptcp", False)),
            "supports_quic": bool(self.config.get("supports_quic", False)),
            "supports_bond_hybrid": bool(self.config.get("supports_bond_hybrid", True)),
            "supports_sidecar_quic": bool(self.config.get("supports_sidecar_quic", True)),
            "bond_protocols": list(self.config.get("bond_protocols", ["tcp"])),
            "hybrid_modes": list(self.config.get("hybrid_modes", ["quic_ws"])),
            "wireguard_public_key": wireguard_public_key,
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            "cpu_usage": self.cpu_usage_hint(),
            "memory_usage": self.memory_usage_percent(),
            "disk_usage": self.disk_usage_percent(),
            "agent_version": AGENT_VERSION,
            "clock_sync": local_clock,
        }
        _, response_headers = http_json_with_headers("POST", build_url(self.config["master_url"], "/api/node/heartbeat"), self.config["node_token"], payload)
        now = time.time()
        self.save_pull_state(
            {
                "status": "ok",
                "last_attempt_at": now,
                "last_success_at": now,
                "clock": self.panel_clock_state_from_headers(response_headers),
            }
        )

    def refresh_network_identity(self, force: bool = False) -> None:
        now = time.monotonic()
        if not force and (now - self.last_identity_refresh_at) < IDENTITY_REFRESH_SECONDS:
            return

        current_public_ip = str(self.config.get("public_ip", "") or "").strip()
        current_private_ip = str(self.config.get("private_ip", "") or "").strip()
        needs_public = not is_global_ipv4(current_public_ip)
        needs_private = current_private_ip == ""

        changed = False
        if needs_public:
            detected_public_ip = self.detect_public_ipv4()
            if detected_public_ip and detected_public_ip != current_public_ip:
                self.config["public_ip"] = detected_public_ip
                changed = True

        if needs_private:
            detected_private_ip = self.detect_private_ipv4()
            if detected_private_ip and detected_private_ip != current_private_ip:
                self.config["private_ip"] = detected_private_ip
                changed = True

        self.last_identity_refresh_at = now
        if changed:
            save_config(self.config_path, self.config)
            logging.info(
                "updated network identity public_ip=%s private_ip=%s",
                self.config.get("public_ip", ""),
                self.config.get("private_ip", ""),
            )

    def ensure_machine_id(self) -> str:
        current = str(self.config.get("machine_id", "") or "").strip()
        if current != "":
            return current

        machine_id = self.detect_machine_id()
        if machine_id != "":
            self.config["machine_id"] = machine_id
            save_config(self.config_path, self.config)
        return machine_id

    @staticmethod
    def detect_machine_id() -> str:
        candidates = [
            "/etc/machine-id",
            "/var/lib/dbus/machine-id",
            "/sys/class/dmi/id/product_uuid",
        ]
        for raw_path in candidates:
            path = Path(raw_path)
            try:
                value = path.read_text(encoding="utf-8").strip()
            except Exception:  # noqa: BLE001
                continue
            normalized = re.sub(r"[^A-Za-z0-9._:-]", "", value)
            if normalized != "":
                return normalized[:191]
        hostname = re.sub(r"[^A-Za-z0-9._:-]", "", socket.gethostname().strip())
        return hostname[:191]

    @staticmethod
    def detect_private_ipv4() -> str:
        candidates: list[str] = []
        with contextlib.suppress(Exception):
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.connect(("223.5.5.5", 80))
            candidate = sock.getsockname()[0]
            if candidate:
                candidates.append(candidate)
            sock.close()
        with contextlib.suppress(Exception):
            candidate = socket.gethostbyname(socket.gethostname())
            if candidate:
                candidates.append(candidate)

        for candidate in candidates:
            if candidate and not candidate.startswith("127.") and "." in candidate:
                return candidate
        return ""

    @staticmethod
    def detect_public_ipv4() -> str:
        candidates: list[str] = []
        with contextlib.suppress(Exception):
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.connect(("223.5.5.5", 80))
            candidate = sock.getsockname()[0]
            if is_global_ipv4(candidate):
                candidates.append(candidate)
            sock.close()
        with contextlib.suppress(Exception):
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.connect(("114.114.114.114", 80))
            candidate = sock.getsockname()[0]
            if is_global_ipv4(candidate):
                candidates.append(candidate)
            sock.close()

        for candidate in candidates:
            if is_global_ipv4(candidate):
                return candidate

        probe_urls = [
            "https://ip-query.mcbar.cc",
            "https://4.ipw.cn",
            "https://myip.ipip.net",
            "http://members.3322.org/dyndns/getip",
            "https://ifconfig.me/ip",
            "https://ip.sb",
            "https://ipv4.icanhazip.com",
        ]
        for probe_url in probe_urls:
            try:
                req = request.Request(probe_url, headers={"User-Agent": f"Leikwan-Slave-Agent/{AGENT_VERSION}"})
                with request.urlopen(req, timeout=8) as response:
                    body = response.read().decode("utf-8", errors="ignore")
                candidate = first_ipv4_candidate(body)
                if candidate:
                    return candidate
            except Exception:  # noqa: BLE001
                logging.debug("public ip probe failed: %s", probe_url, exc_info=True)

        return ""

    def active_service_count(self) -> int:
        count = 0
        for metadata in self.load_all_service_metadata():
            if self.metadata_is_active(metadata):
                count += 1
        return count

    def reconcile_runtime(self) -> None:
        touched_ports: set[int] = set()
        self.ensure_nft_table()
        self.cleanup_superseded_forward_services()

        for metadata in self.load_all_service_metadata():
            try:
                service_name = str(metadata.get("service_name", "")).strip()
                if service_name == "":
                    continue

                public_port = int(metadata.get("listen_port", 0) or 0)
                if public_port > 0:
                    touched_ports.add(public_port)

                status = str(metadata.get("status", "active"))
                metadata_changed = "status" not in metadata
                if "status" not in metadata:
                    metadata["status"] = status

                endpoint_id = int(metadata.get("endpoint_id", 0) or 0)
                backend_port = int(metadata.get("backend_port", 0) or 0)
                relay_protocols = [str(item).strip().lower() for item in metadata.get("relay_protocols", []) if str(item).strip() != ""]
                relay_name = str(metadata.get("relay_service_name", relay_service_name(int(metadata.get("service_id", 0) or 0), endpoint_id)))

                if status != "active":
                    if shutil.which("systemctl"):
                        run_command(f"systemctl stop {service_name}.service", check=False)
                        run_command(f"systemctl disable {service_name}.service", check=False)
                        if relay_name:
                            run_command(f"systemctl stop {relay_name}.service", check=False)
                            run_command(f"systemctl disable {relay_name}.service", check=False)
                    if endpoint_id > 0:
                        self.delete_nft_rules(endpoint_id)
                    if metadata_changed:
                        self.write_json(self.metadata_path(service_name), metadata)
                    continue

                target = str(metadata.get("target", "")).strip()
                relay_target = str(metadata.get("relay_target", target)).strip()
                path_prefix = normalize_path_prefix(str(metadata.get("path_prefix", "")))
                if target == "" or public_port <= 0 or endpoint_id <= 0:
                    logging.warning("skipping invalid runtime metadata for %s", service_name)
                    continue

                if backend_port <= 0:
                    backend_port = self.allocate_backend_port(service_name)
                    metadata["backend_port"] = backend_port
                    metadata_changed = True

                unit_body = self.render_backend_unit(service_name, backend_port, target, path_prefix)
                unit_changed = self.write_text_if_changed(self.service_unit_path(service_name), unit_body)
                self.ensure_backend_service(service_name, unit_changed)

                relay_unit_path = self.service_unit_path(relay_name)
                if "quic" in relay_protocols and relay_target != "":
                    relay_body = self.render_quic_relay_unit(relay_name, public_port, relay_target)
                    relay_changed = self.write_text_if_changed(relay_unit_path, relay_body)
                    self.ensure_backend_service(relay_name, relay_changed)
                else:
                    if shutil.which("systemctl") and relay_name:
                        run_command(f"systemctl stop {relay_name}.service", check=False)
                        run_command(f"systemctl disable {relay_name}.service", check=False)
                    if relay_unit_path.exists():
                        relay_unit_path.unlink()
                        if shutil.which("systemctl"):
                            run_command("systemctl daemon-reload", check=False)
                self.delete_nft_rules(endpoint_id)
                self.add_nft_rules(endpoint_id, backend_port)

                if metadata_changed:
                    self.write_json(self.metadata_path(service_name), metadata)
            except Exception:  # noqa: BLE001
                logging.exception("failed to reconcile runtime metadata: %s", metadata)

        for path in self.listeners_dir.glob("routes-*.json"):
            match = re.match(r"routes-(\d+)\.json", path.name)
            if match:
                touched_ports.add(int(match.group(1)))

        for port in sorted(touched_ports):
            self.sync_listener(port)

    def process_tasks(self) -> None:
        try:
            result = http_json(
                "GET",
                build_url(self.config["master_url"], "/api/node/tasks/pull", {"limit": 20}),
                self.config["node_token"],
            )
        except Exception as exc:  # noqa: BLE001
            self.save_pull_state(
                {
                    "status": "error",
                    "last_attempt_at": time.time(),
                    "last_error": str(exc),
                    "agent_version": AGENT_VERSION,
                    "node_id": int(self.config.get("node_id", 0) or 0),
                    "master_url": str(self.config.get("master_url", "")),
                }
            )
            logging.warning("failed to pull tasks: %s", exc)
            return
        management = result.get("management", {})
        try:
            self.apply_management_state(management, schedule_updates=False)
        except Exception:  # noqa: BLE001
            logging.exception("failed to apply node management state")
        task_ids: list[int] = []
        for task in result.get("tasks", []):
            task_id = task["id"]
            task_ids.append(int(task_id))
            try:
                http_json("POST", build_url(self.config["master_url"], f"/api/node/tasks/{task_id}/ack"), self.config["node_token"], {})
            except Exception as exc:  # noqa: BLE001
                logging.warning("failed to ack task %s: %s", task_id, exc)
                continue
            success = True
            result_payload: dict[str, Any] = {}
            error_message = ""
            try:
                result_payload = self.handle_task(task)
            except Exception as exc:  # noqa: BLE001
                success = False
                error_message = str(exc)
                logging.exception("task %s failed", task_id)

            try:
                http_json(
                    "POST",
                    build_url(self.config["master_url"], f"/api/node/tasks/{task_id}/result"),
                    self.config["node_token"],
                    {
                        "success": success,
                        "result": result_payload,
                        "error_message": error_message,
                    },
                )
            except Exception as exc:  # noqa: BLE001
                logging.warning("failed to submit result for task %s: %s", task_id, exc)
        now = time.time()
        self.save_pull_state(
            {
                "status": "ok",
                "last_attempt_at": now,
                "last_success_at": now,
                "last_error": "",
                "agent_version": AGENT_VERSION,
                "node_id": int(self.config.get("node_id", 0) or 0),
                "master_url": str(self.config.get("master_url", "")),
                "tasks_received": len(task_ids),
                "task_ids": task_ids,
                "management_keys": sorted(management.keys()) if isinstance(management, dict) else [],
                "agent_update_request_token": str(management.get("agent_update_request_token", "") or "") if isinstance(management, dict) else "",
            }
        )
        try:
            self.apply_management_state(management, schedule_updates=True)
        except Exception:  # noqa: BLE001
            logging.exception("failed to schedule node management updates")
        self.cleanup_orphan_mptcp_underlays()

    def apply_management_state(self, management: Any, schedule_updates: bool = True) -> None:
        if not isinstance(management, dict):
            return
        self.apply_config_patch(management.get("agent_config_json"))
        explicit_request_token = str(management.get("agent_update_request_token", "") or "")
        auto_request_token = self.auto_update_request_token(management)

        update_state = self.load_update_state()
        runtime_state = self.load_runtime_state()
        completed_request_token = str(update_state.get("last_completed_request_token", "") or "")

        if explicit_request_token != "":
            if completed_request_token == explicit_request_token:
                if runtime_state.get("last_agent_update_request_token") != explicit_request_token:
                    runtime_state["last_agent_update_request_token"] = explicit_request_token
                    self.save_runtime_state(runtime_state)
            elif not self.management_requires_local_update(management):
                now = time.time()
                update_state.update(
                    {
                        "request_token": explicit_request_token,
                        "status": "success",
                        "last_attempt_at": now,
                        "last_completed_request_token": explicit_request_token,
                        "last_completed_at": now,
                        "installed_version": AGENT_VERSION,
                        "last_error": "",
                    }
                )
                self.save_update_state(update_state)
                runtime_state["last_agent_update_request_token"] = explicit_request_token
                self.save_runtime_state(runtime_state)
                explicit_request_token = ""

        if not schedule_updates:
            return

        for update_request_token in [token for token in [explicit_request_token, auto_request_token] if token]:
            if completed_request_token == update_request_token:
                continue

            now = time.time()
            if not self.should_retry_update(update_state, update_request_token, now):
                continue

            update_state.update(
                {
                    "request_token": update_request_token,
                    "status": "scheduled",
                    "last_attempt_at": now,
                    "last_error": "",
                }
            )
            self.save_update_state(update_state)
            try:
                self.request_self_update(update_request_token)
            except Exception as exc:  # noqa: BLE001
                update_state["status"] = "error"
                update_state["last_error"] = str(exc)
                update_state["last_failed_at"] = now
                self.save_update_state(update_state)
                raise
            runtime_state["last_agent_update_request_token"] = update_request_token
            self.save_runtime_state(runtime_state)
            return

    def auto_update_request_token(self, management: Any) -> str:
        if not isinstance(management, dict):
            return ""

        desired_agent_version = str(management.get("desired_agent_version", "") or "").strip()
        desired_agent_sha256 = str(management.get("desired_agent_sha256", "") or "").strip().lower()
        desired_installer_version = str(management.get("desired_installer_version", "") or "").strip()
        mismatches: list[str] = []

        if desired_agent_version and desired_agent_version != AGENT_VERSION:
            mismatches.append(f"agent={desired_agent_version}")

        if desired_agent_sha256:
            local_agent_sha256 = file_sha256(Path(__file__).resolve()).lower()
            if local_agent_sha256 != desired_agent_sha256:
                mismatches.append(f"agent_sha256={desired_agent_sha256}")

        local_installer_version = str(self.config.get("installer_version", "") or "").strip()
        if desired_installer_version and local_installer_version != desired_installer_version:
            mismatches.append(f"installer={desired_installer_version}")

        if mismatches == []:
            return ""

        return "auto-release:" + "|".join(mismatches)

    def management_requires_local_update(self, management: Any) -> bool:
        if not isinstance(management, dict):
            return False
        return self.auto_update_request_token(management) != ""

    def apply_config_patch(self, patch: Any) -> None:
        if not isinstance(patch, dict):
            return

        allowed_keys = {
            "master_url",
            "name",
            "region",
            "group_key",
            "public_ip",
            "private_ip",
            "supports_udp",
            "supports_filtering",
            "supports_mptcp",
            "supports_quic",
            "supports_bond_hybrid",
            "supports_sidecar_quic",
            "bond_protocols",
            "hybrid_modes",
            "tls_mode",
            "tls_domain",
            "tls_email",
            "tls_wildcard",
            "tls_cert_path",
            "tls_key_path",
            "landing_page_path",
            "tls_cert_pem",
            "tls_key_pem",
            "cloudflare_email",
            "cloudflare_global_key",
            "backend_host",
            "backend_port_range_start",
            "backend_port_range_end",
            "mux_listen_hosts",
            "relay_quic_keepalive_seconds",
            "relay_quic_max_idle_seconds",
            "relay_quic_prefetch_connections",
            "relay_quic_initial_mtu",
            "relay_quic_min_mtu",
            "relay_quic_mtu_upper_bound",
            "relay_quic_mtu_discovery_interval_seconds",
            "relay_quic_mtu_black_hole_cooldown_seconds",
            "relay_quic_mtu_minimum_change",
            "poll_interval_seconds",
            "heartbeat_interval_seconds",
            "usage_interval_seconds",
            "log_level",
        }
        changed = False
        changed_keys: set[str] = set()
        for key, value in patch.items():
            if key not in allowed_keys:
                continue
            if value is None:
                if key in DEFAULT_CONFIG:
                    value = DEFAULT_CONFIG[key]
                else:
                    if key not in self.config:
                        continue
                    self.config.pop(key, None)
                    changed = True
                    changed_keys.add(key)
                    continue
            if self.config.get(key) == value:
                continue
            self.config[key] = value
            changed = True
            changed_keys.add(key)

        if not changed:
            return

        save_config(self.config_path, self.config)
        logging.getLogger().setLevel(getattr(logging, str(self.config.get("log_level", "INFO")).upper(), logging.INFO))
        logging.info("applied node config patch keys=%s", ",".join(sorted(changed_keys)))
        tls_runtime_keys = {
            "tls_cert_path",
            "tls_key_path",
            "tls_cert_pem",
            "tls_key_pem",
        }
        if changed_keys & tls_runtime_keys:
            try:
                tls_files_changed = self.provision_inline_tls_assets()
                if tls_files_changed:
                    logging.info("inline tls assets updated, restarting active listener services")
                    self.restart_listener_services()
            except Exception:  # noqa: BLE001
                logging.exception("failed to provision inline tls assets")
        if changed_keys & {"tls_mode", "tls_domain", "tls_email", "tls_wildcard", "tls_cert_path", "tls_key_path", "cloudflare_email", "cloudflare_global_key"}:
            try:
                logging.info("tls provisioning settings changed, requesting local update helper")
                self.request_self_update("tls-config-change")
            except Exception:  # noqa: BLE001
                logging.exception("failed to trigger local update for tls config change")
        self.reconcile_runtime()

    def provision_inline_tls_assets(self) -> bool:
        cert_pem = str(self.config.get("tls_cert_pem", "") or "")
        key_pem = str(self.config.get("tls_key_pem", "") or "")
        if cert_pem == "" and key_pem == "":
            return False
        if cert_pem == "" or key_pem == "":
            raise RuntimeError("inline tls material requires both tls_cert_pem and tls_key_pem")

        cert_path = Path(str(self.config["tls_cert_path"]))
        key_path = Path(str(self.config["tls_key_path"]))
        cert_path.parent.mkdir(parents=True, exist_ok=True)
        key_path.parent.mkdir(parents=True, exist_ok=True)

        cert_body = cert_pem if cert_pem.endswith("\n") else cert_pem + "\n"
        key_body = key_pem if key_pem.endswith("\n") else key_pem + "\n"
        cert_changed = self.write_text_if_changed(cert_path, cert_body)
        key_changed = self.write_text_if_changed(key_path, key_body)
        os.chmod(cert_path, 0o644)
        os.chmod(key_path, 0o600)
        return cert_changed or key_changed

    def listener_ports(self) -> set[int]:
        ports: set[int] = set()
        for metadata in self.load_all_service_metadata():
            listen_port = int(metadata.get("listen_port", 0) or 0)
            if listen_port > 0:
                ports.add(listen_port)
        for path in self.listeners_dir.glob("routes-*.json"):
            match = re.fullmatch(r"routes-(\d+)\.json", path.name)
            if match:
                ports.add(int(match.group(1)))
        return ports

    def restart_listener_services(self) -> None:
        for port in sorted(self.listener_ports()):
            run_command(f"systemctl restart {mux_service_name(port)}.service", check=False)

    def runtime_state_path(self) -> Path:
        return self.state_dir / "runtime_state.json"

    def update_state_path(self) -> Path:
        return self.state_dir / "agent_update_state.json"

    def load_runtime_state(self) -> dict[str, Any]:
        path = self.runtime_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_runtime_state(self, payload: dict[str, Any]) -> None:
        self.write_json(self.runtime_state_path(), payload)

    def load_update_state(self) -> dict[str, Any]:
        path = self.update_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_update_state(self, payload: dict[str, Any]) -> None:
        self.write_json(self.update_state_path(), payload)

    @staticmethod
    def state_timestamp(value: Any) -> float:
        try:
            return float(value)
        except (TypeError, ValueError):
            return 0.0

    def should_retry_update(self, update_state: dict[str, Any], request_token: str, now: float) -> bool:
        if str(update_state.get("last_completed_request_token", "") or "") == request_token:
            return False

        tracked_request_token = str(update_state.get("request_token", "") or "")
        tracked_status = str(update_state.get("status", "") or "")
        last_attempt_at = self.state_timestamp(update_state.get("last_attempt_at"))

        if tracked_request_token != request_token:
            return True

        if tracked_status == "running" and last_attempt_at > 0 and (now - last_attempt_at) < UPDATE_STALE_SECONDS:
            return False

        if last_attempt_at > 0 and (now - last_attempt_at) < UPDATE_RETRY_SECONDS:
            return False

        return True

    def request_self_update(self, request_token: str) -> None:
        helper_path = Path("/usr/local/bin/leikwan-slave-update")
        if not helper_path.exists():
            raise RuntimeError(f"update helper missing: {helper_path}")
        state_path = self.update_state_path()

        if shutil.which("systemd-run"):
            unit_name = f"leikwan-slave-update-{int(time.time())}-{request_token[:8]}"
            subprocess.run(
                [
                    "systemd-run",
                    "--quiet",
                    "--no-block",
                    "--unit",
                    unit_name,
                    "/usr/bin/env",
                    "bash",
                    str(helper_path),
                    "--request-token",
                    request_token,
                    "--state-path",
                    str(state_path),
                ],
                check=True,
            )
        else:
            self.spawn_detached_command(
                [
                    "/usr/bin/env",
                    "bash",
                    str(helper_path),
                    "--request-token",
                    request_token,
                    "--state-path",
                    str(state_path),
                ]
            )
        logging.info("requested slave self-update token=%s", request_token)

    @staticmethod
    def spawn_detached_command(parts: list[str]) -> None:
        subprocess.Popen(parts, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, stdin=subprocess.DEVNULL)  # noqa: S603

    def handle_task(self, task: dict[str, Any]) -> dict[str, Any]:
        action = str(task["action"])
        payload = dict(task.get("payload", {}))
        if action in {"publish", "resume"}:
            return self.publish_task(task, payload)
        if action == "suspend":
            return self.stop_service(task, keep_files=True)
        if action == "destroy":
            return self.destroy_service(task)
        if action == "reload-filter":
            return self.write_filter_marker(task, payload)
        raise RuntimeError(f"unsupported action: {action}")

    def publish_task(self, task: dict[str, Any], payload: dict[str, Any]) -> dict[str, Any]:
        service_name = safe_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_name = relay_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_client_name = relay_client_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        target = str(payload["target"]).strip()
        public_port = int(payload["listen_port"])
        path_prefix = normalize_path_prefix(str(payload["path_prefix"]))
        route_key = normalize_path_prefix(str(payload.get("route_key", payload.get("path_prefix", ""))))
        transport_mode = str(payload.get("transport_mode", "tcp+udp"))
        ingress_protocol = str(payload.get("ingress_protocol", "wss_path_mux") or "wss_path_mux").strip() or "wss_path_mux"
        backend_protocol = str(payload.get("backend_protocol", "wss_backend") or "wss_backend").strip() or "wss_backend"
        ingress_auth_token = str(payload.get("ingress_auth_token", "") or "").strip()
        relay_protocols = [str(item).strip().lower() for item in payload.get("leg_relay_protocols", []) if str(item).strip() != ""]
        mptcp_underlay = payload.get("mptcp_underlay", {})
        if not isinstance(mptcp_underlay, dict):
            mptcp_underlay = {}
        metadata_path = self.metadata_path(service_name)

        self.cleanup_superseded_services_for_forward(int(task["forward_id"]), service_name)

        if mptcp_underlay and mptcp_underlay.get("enabled"):
            metadata = {
                "service_name": service_name,
                "service_id": int(task["service_id"]),
                "forward_id": int(task["forward_id"]),
                "endpoint_id": int(task["endpoint_id"]),
                "listen_port": public_port,
                "backend_port": 0,
                "target": target,
                "path_prefix": path_prefix,
                "route_key": route_key,
                "ingress_protocol": ingress_protocol,
                "backend_protocol": backend_protocol,
                "ingress_auth_token": ingress_auth_token,
                "transport_mode": transport_mode,
                "relay_protocols": [],
                "relay_target": "",
                "relay_service_name": "",
                "relay_client_service_name": "",
                "relay_client_port": 0,
                "mptcp_underlay": mptcp_underlay,
                "filter_profile": str(payload.get("filter_profile", "off")),
                "status": "active",
            }
            self.write_json(metadata_path, metadata)
            self.apply_mptcp_underlay(service_name, mptcp_underlay)
            return {
                "service_name": service_name,
                "unit_path": "",
                "backend_port": 0,
                "public_port": public_port,
                "mux_service": "",
                "relay_service_name": None,
                "relay_client_service_name": None,
            }

        unit_path = self.service_unit_path(service_name)
        relay_unit_path = self.service_unit_path(relay_name)
        relay_client_unit_path = self.service_unit_path(relay_client_name)
        metadata_path = self.metadata_path(service_name)

        existing = self.load_service_metadata(service_name) or {}
        previous_public_port = int(existing.get("listen_port", 0) or 0)
        backend_port = int(existing.get("backend_port", 0) or 0)
        relay_client_port = int(existing.get("relay_client_port", 0) or 0)

        self.ensure_listener_route_available(public_port, path_prefix, service_name)
        if backend_port <= 0:
            backend_port = self.allocate_backend_port(service_name)

        if ingress_protocol == "plain_tcp_mux" or backend_protocol == "tcp_relay":
            unit_body = self.render_plain_tcp_backend_unit(service_name, backend_port, target)
        else:
            unit_body = self.render_backend_unit(service_name, backend_port, target, path_prefix)
        unit_changed = self.write_text_if_changed(unit_path, unit_body)
        self.ensure_backend_service(service_name, unit_changed)

        relay_changed = False
        relay_client_changed = False
        if "quic" in relay_protocols:
            # Keep the WSS backend port reserved while choosing the QUIC
            # relay-client listener so both services never collide locally.
            if relay_client_port <= 0 or relay_client_port == backend_port:
                relay_client_port = self.allocate_backend_port(relay_client_name, {backend_port})
            relay_client_body = self.render_quic_relay_client_unit(relay_client_name, relay_client_port, target)
            relay_client_changed = self.write_text_if_changed(relay_client_unit_path, relay_client_body)
            self.ensure_backend_service(relay_client_name, relay_client_changed)

            relay_body = self.render_quic_relay_unit(relay_name, public_port, f"127.0.0.1:{relay_client_port}")
            relay_changed = self.write_text_if_changed(relay_unit_path, relay_body)
            self.ensure_backend_service(relay_name, relay_changed)
        else:
            if shutil.which("systemctl"):
                run_command(f"systemctl stop {relay_name}.service", check=False)
                run_command(f"systemctl disable {relay_name}.service", check=False)
                run_command(f"systemctl stop {relay_client_name}.service", check=False)
                run_command(f"systemctl disable {relay_client_name}.service", check=False)
            if relay_unit_path.exists():
                relay_unit_path.unlink()
                relay_changed = True
            if relay_client_unit_path.exists():
                relay_client_unit_path.unlink()
                relay_client_changed = True
            relay_client_port = 0
            if (relay_changed or relay_client_changed) and shutil.which("systemctl"):
                run_command("systemctl daemon-reload", check=False)

        self.ensure_nft_table()
        self.delete_nft_rules(int(task["endpoint_id"]))
        self.add_nft_rules(int(task["endpoint_id"]), backend_port)

        metadata = {
            "service_name": service_name,
            "service_id": int(task["service_id"]),
            "forward_id": int(task["forward_id"]),
            "endpoint_id": int(task["endpoint_id"]),
            "listen_port": public_port,
            "backend_port": backend_port,
            "target": target,
            "path_prefix": path_prefix,
            "route_key": route_key,
            "ingress_protocol": ingress_protocol,
            "backend_protocol": backend_protocol,
            "ingress_auth_token": ingress_auth_token,
            "transport_mode": transport_mode,
            "relay_protocols": relay_protocols,
            "relay_target": target,
            "relay_service_name": relay_name if relay_protocols else "",
            "relay_client_service_name": relay_client_name if relay_protocols else "",
            "relay_client_port": relay_client_port if relay_protocols else 0,
            "mptcp_underlay": mptcp_underlay,
            "filter_profile": str(payload.get("filter_profile", "off")),
            "status": "active",
        }
        self.write_json(metadata_path, metadata)

        if self.udp_uot_sidepath_enabled(task, payload):
            settings = self.udp_uot_sidepath_settings()
            shadow_service = udp_uot_shadow_service_name(service_name)
            shadow_backend_port = self.allocate_backend_port(shadow_service)
            shadow_target = replace_target_port(target, int(settings.get("sidecar_port", 8999) or 8999))
            shadow_suffix = str(settings.get("shadow_route_suffix", ".uot") or ".uot")
            shadow_route_key = route_key + shadow_suffix
            shadow_metadata = dict(metadata)
            shadow_metadata.update(
                {
                    "service_name": shadow_service,
                    "unit_path": str(self.service_unit_path(shadow_service)),
                    "backend_port": shadow_backend_port,
                    "target": shadow_target,
                    "path_prefix": shadow_route_key,
                    "route_key": shadow_route_key,
                    "transport_mode": "tcp",
                    "relay_protocols": [],
                    "relay_target": "",
                    "relay_service_name": "",
                    "relay_client_service_name": "",
                    "relay_client_port": 0,
                    "ingress_protocol": "plain_tcp_mux",
                    "backend_protocol": "tcp_relay",
                    "ingress_auth_token": ingress_auth_token,
                }
            )
            shadow_unit = self.render_plain_tcp_backend_unit(shadow_service, shadow_backend_port, shadow_target)
            shadow_unit_changed = self.write_text_if_changed(self.service_unit_path(shadow_service), shadow_unit)
            self.ensure_backend_service(shadow_service, shadow_unit_changed)
            self.save_service_metadata(shadow_service, shadow_metadata)

        if mptcp_underlay:
            self.apply_mptcp_underlay(service_name, mptcp_underlay)
        else:
            self.remove_mptcp_underlay(service_name)

        self.sync_listener(public_port)
        if previous_public_port > 0 and previous_public_port != public_port:
            self.sync_listener(previous_public_port)

        self.write_filter_marker(task, payload)
        return {
            "service_name": service_name,
            "unit_path": str(unit_path),
            "backend_port": backend_port,
            "public_port": public_port,
            "mux_service": plain_tcp_mux_service_name(public_port) if ingress_protocol == "plain_tcp_mux" else mux_service_name(public_port),
            "relay_service_name": relay_name if relay_protocols else None,
            "relay_client_service_name": relay_client_name if relay_protocols else None,
        }

    def stop_service(self, task: dict[str, Any], keep_files: bool) -> dict[str, Any]:
        service_name = safe_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_name = relay_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_client_name = relay_client_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        payload = dict(task.get("payload", {}))
        metadata = self.load_service_metadata(service_name) or {}
        public_port = int(metadata.get("listen_port", payload.get("listen_port", 0)) or 0)
        metadata_path = self.metadata_path(service_name)

        if metadata:
            metadata["status"] = "suspended"
            self.write_json(metadata_path, metadata)
        if public_port > 0:
            self.sync_listener(public_port)

        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command(f"systemctl stop {relay_name}.service", check=False)
            run_command(f"systemctl disable {relay_name}.service", check=False)
            run_command(f"systemctl stop {relay_client_name}.service", check=False)
            run_command(f"systemctl disable {relay_client_name}.service", check=False)
        self.remove_mptcp_underlay(service_name, metadata.get("mptcp_underlay") if isinstance(metadata, dict) else None)

        if not keep_files:
            unit_path = self.service_unit_path(service_name)
            relay_unit_path = self.service_unit_path(relay_name)
            relay_client_unit_path = self.service_unit_path(relay_client_name)
            if unit_path.exists():
                unit_path.unlink()
            if relay_unit_path.exists():
                relay_unit_path.unlink()
            if relay_client_unit_path.exists():
                relay_client_unit_path.unlink()
            if metadata_path.exists():
                metadata_path.unlink()
            if shutil.which("systemctl"):
                run_command("systemctl daemon-reload", check=False)

        return {"service_name": service_name, "stopped": True, "public_port": public_port}

    def destroy_service(self, task: dict[str, Any]) -> dict[str, Any]:
        service_name = safe_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_name = relay_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_client_name = relay_client_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        payload = dict(task.get("payload", {}))
        metadata = self.load_service_metadata(service_name) or {}
        public_port = int(metadata.get("listen_port", payload.get("listen_port", 0)) or 0)
        unit_path = self.service_unit_path(service_name)
        relay_unit_path = self.service_unit_path(relay_name)
        relay_client_unit_path = self.service_unit_path(relay_client_name)
        metadata_path = self.metadata_path(service_name)

        if metadata_path.exists():
            metadata_path.unlink()
        if public_port > 0:
            self.sync_listener(public_port)

        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command(f"systemctl stop {relay_name}.service", check=False)
            run_command(f"systemctl disable {relay_name}.service", check=False)
            run_command(f"systemctl stop {relay_client_name}.service", check=False)
            run_command(f"systemctl disable {relay_client_name}.service", check=False)
        self.remove_mptcp_underlay(service_name, metadata.get("mptcp_underlay") if isinstance(metadata, dict) else None)
        if unit_path.exists():
            unit_path.unlink()
        if relay_unit_path.exists():
            relay_unit_path.unlink()
        if relay_client_unit_path.exists():
            relay_client_unit_path.unlink()
        self.delete_nft_rules(int(task["endpoint_id"]))
        self.delete_filter_marker(service_name)
        if shutil.which("systemctl"):
            run_command("systemctl daemon-reload", check=False)
        return {"service_name": service_name, "deleted": True, "public_port": public_port}

    def write_filter_marker(self, task: dict[str, Any], payload: dict[str, Any]) -> dict[str, Any]:
        self.filters_dir.mkdir(parents=True, exist_ok=True)
        marker_path = self.filters_dir / f"{safe_name(int(task['service_id']), int(task['endpoint_id']))}.json"
        marker_path.write_text(
            json.dumps(
                {
                    "filter_profile": payload.get("filter_profile", "off"),
                    "updated_at": int(time.time()),
                },
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )
        return {"filter_profile": payload.get("filter_profile", "off")}

    def delete_filter_marker(self, service_name: str) -> None:
        marker_path = self.filters_dir / f"{service_name}.json"
        if marker_path.exists():
            marker_path.unlink()

    def service_unit_path(self, service_name: str) -> Path:
        return Path(str(self.config["systemd_dir"])) / f"{service_name}.service"

    def metadata_path(self, service_name: str) -> Path:
        return self.services_dir / f"{service_name}.json"

    def listener_routes_path(self, listen_port: int) -> Path:
        return self.listeners_dir / f"routes-{listen_port}.json"

    def listener_unit_path(self, listen_port: int) -> Path:
        return Path(str(self.config["systemd_dir"])) / f"{mux_service_name(listen_port)}.service"

    def listener_unit_path_for_service(self, service_name: str) -> Path:
        return Path(str(self.config["systemd_dir"])) / f"{service_name}.service"

    def load_service_metadata(self, service_name: str) -> dict[str, Any] | None:
        path = self.metadata_path(service_name)
        if not path.exists():
            return None
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            logging.warning("invalid metadata file: %s", path)
            return None
        return payload if isinstance(payload, dict) else None

    def save_service_metadata(self, service_name: str, metadata: dict[str, Any]) -> None:
        self.write_json(self.metadata_path(service_name), metadata)

    def load_all_service_metadata(self) -> list[dict[str, Any]]:
        items: list[dict[str, Any]] = []
        for path in self.services_dir.glob("*.json"):
            try:
                payload = json.loads(path.read_text(encoding="utf-8"))
            except json.JSONDecodeError:
                logging.warning("invalid metadata file: %s", path)
                continue
            if isinstance(payload, dict):
                items.append(payload)
        return items

    @staticmethod
    def metadata_is_active(metadata: dict[str, Any]) -> bool:
        return str(metadata.get("status", "active")) == "active"

    @staticmethod
    def destroy_task_for_metadata(metadata: dict[str, Any]) -> dict[str, Any]:
        return {
            "service_id": int(metadata.get("service_id", 0) or 0),
            "forward_id": int(metadata.get("forward_id", 0) or 0),
            "endpoint_id": int(metadata.get("endpoint_id", 0) or 0),
            "payload": {
                "listen_port": int(metadata.get("listen_port", 0) or 0),
            },
        }

    def cleanup_superseded_services_for_forward(self, forward_id: int, keep_service_name: str) -> None:
        if forward_id <= 0 or keep_service_name.strip() == "":
            return

        stale_metadata: list[dict[str, Any]] = []
        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            if int(metadata.get("forward_id", 0) or 0) != forward_id:
                continue
            if str(metadata.get("service_name", "")).strip() == keep_service_name:
                continue
            stale_metadata.append(metadata)

        for metadata in stale_metadata:
            service_name = str(metadata.get("service_name", "")).strip()
            if service_name != "":
                logging.info(
                    "removing superseded service for forward_id=%s old_service=%s keep_service=%s",
                    forward_id,
                    service_name,
                    keep_service_name,
                )
            self.destroy_service(self.destroy_task_for_metadata(metadata))

    def cleanup_superseded_forward_services(self) -> None:
        keepers: dict[int, tuple[int, str]] = {}
        candidates: list[dict[str, Any]] = []

        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            forward_id = int(metadata.get("forward_id", 0) or 0)
            endpoint_id = int(metadata.get("endpoint_id", 0) or 0)
            service_name = str(metadata.get("service_name", "")).strip()
            if forward_id <= 0 or endpoint_id <= 0 or service_name == "":
                continue
            candidates.append(metadata)
            current = keepers.get(forward_id)
            if current is None or endpoint_id > current[0]:
                keepers[forward_id] = (endpoint_id, service_name)

        for metadata in candidates:
            forward_id = int(metadata.get("forward_id", 0) or 0)
            service_name = str(metadata.get("service_name", "")).strip()
            keeper = keepers.get(forward_id)
            if keeper is None:
                continue
            if service_name == keeper[1]:
                continue
            logging.info(
                "removing stale duplicate service for forward_id=%s old_service=%s keep_service=%s",
                forward_id,
                service_name,
                keeper[1],
            )
            self.destroy_service(self.destroy_task_for_metadata(metadata))

    def udp_uot_sidepath_settings(self) -> dict[str, Any]:
        raw = self.config.get("udp_uot_sidepath", {})
        return raw if isinstance(raw, dict) else {}

    def udp_uot_sidepath_enabled(self, task: dict[str, Any], payload: dict[str, Any]) -> bool:
        settings = self.udp_uot_sidepath_settings()
        enabled = {
            int(value)
            for value in settings.get("enabled_forward_ids", [])
            if str(value).strip().isdigit()
        }
        forward_id = int(task.get("forward_id", 0) or 0)
        transport_mode = str(payload.get("transport_mode", "tcp+udp") or "tcp+udp").strip().lower()
        ingress_protocol = str(payload.get("ingress_protocol", "") or "").strip().lower()
        return forward_id in enabled and "udp" in transport_mode and ingress_protocol == "plain_tcp_mux"

    def ensure_listener_route_available(self, public_port: int, path_prefix: str, service_name: str) -> None:
        desired_route = normalized_route_path(path_prefix)
        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            if str(metadata.get("service_name", "")) == service_name:
                continue
            if int(metadata.get("listen_port", 0) or 0) != public_port:
                continue
            current_route = normalized_route_path(str(metadata.get("path_prefix", "")))
            if current_route == desired_route:
                raise RuntimeError(
                    f"path_prefix conflict on public_port={public_port}: {path_prefix} already used by {metadata.get('service_name', 'unknown')}"
                )

    def used_backend_ports(self, exclude_service_name: str | None = None) -> set[int]:
        ports: set[int] = set()
        for metadata in self.load_all_service_metadata():
            if exclude_service_name and str(metadata.get("service_name", "")) == exclude_service_name:
                continue
            backend_port = int(metadata.get("backend_port", 0) or 0)
            if backend_port > 0:
                ports.add(backend_port)
            relay_client_port = int(metadata.get("relay_client_port", 0) or 0)
            if relay_client_port > 0:
                ports.add(relay_client_port)
        return ports

    @staticmethod
    def tcp_port_available(port: int) -> bool:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        try:
            sock.bind(("127.0.0.1", port))
        except OSError:
            return False
        finally:
            sock.close()
        return True

    def allocate_backend_port(self, service_name: str, reserved_ports: set[int] | None = None) -> int:
        start = int(self.config.get("backend_port_range_start", 20000))
        end = int(self.config.get("backend_port_range_end", 40000))
        if start <= 0 or end <= 0 or end < start:
            raise RuntimeError("invalid backend port range")

        used = self.used_backend_ports(service_name)
        if reserved_ports:
            used.update(port for port in reserved_ports if port > 0)
        for port in range(start, end + 1):
            if port in used:
                continue
            if self.tcp_port_available(port):
                return port
        raise RuntimeError("no free backend port available")

    def listener_routes(self, listen_port: int) -> list[dict[str, Any]]:
        routes: list[dict[str, Any]] = []
        seen_paths: set[str] = set()
        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            if int(metadata.get("listen_port", 0) or 0) != listen_port:
                continue
            backend_port = int(metadata.get("backend_port", 0) or 0)
            if backend_port <= 0:
                continue
            route_path = normalized_route_path(str(metadata.get("path_prefix", "")))
            if route_path in seen_paths:
                raise RuntimeError(f"duplicate path route detected on port {listen_port}: {route_path}")
            seen_paths.add(route_path)
            routes.append(
                {
                    "path_prefix": normalize_path_prefix(str(metadata.get("path_prefix", ""))),
                    "route_key": normalize_path_prefix(str(metadata.get("route_key", metadata.get("path_prefix", "")))),
                    "backend_host": str(self.config.get("backend_host", "127.0.0.1")),
                    "backend_port": backend_port,
                    "service_name": str(metadata.get("service_name", "")),
                    "ingress_protocol": str(metadata.get("ingress_protocol", "wss_path_mux") or "wss_path_mux"),
                    "backend_protocol": str(metadata.get("backend_protocol", "wss_backend") or "wss_backend"),
                    "ingress_auth_token": str(metadata.get("ingress_auth_token", "") or ""),
                }
            )
        routes.sort(key=lambda item: len(normalized_route_path(str(item["path_prefix"]))), reverse=True)
        return routes

    def sync_listener(self, listen_port: int) -> None:
        if listen_port <= 0:
            return

        routes = self.listener_routes(listen_port)
        routes_path = self.listener_routes_path(listen_port)
        wss_service_name = mux_service_name(listen_port)
        plain_service_name = plain_tcp_mux_service_name(listen_port)

        if not routes:
            if routes_path.exists():
                routes_path.unlink()
            if shutil.which("systemctl"):
                run_command(f"systemctl stop {wss_service_name}.service", check=False)
                run_command(f"systemctl disable {wss_service_name}.service", check=False)
                run_command(f"systemctl stop {plain_service_name}.service", check=False)
                run_command(f"systemctl disable {plain_service_name}.service", check=False)
            for unit_path in [self.listener_unit_path_for_service(wss_service_name), self.listener_unit_path_for_service(plain_service_name)]:
                if unit_path.exists():
                    unit_path.unlink()
            if shutil.which("systemctl"):
                run_command("systemctl daemon-reload", check=False)
            return

        use_plain_tcp_mux = any(str(route.get("ingress_protocol", "")) == "plain_tcp_mux" for route in routes)
        service_name = plain_service_name if use_plain_tcp_mux else wss_service_name
        stale_service_name = wss_service_name if use_plain_tcp_mux else plain_service_name
        unit_path = self.listener_unit_path_for_service(service_name)
        stale_unit_path = self.listener_unit_path_for_service(stale_service_name)

        self.write_json(
            routes_path,
            {
                "listen_port": listen_port,
                "updated_at": int(time.time()),
                "routes": routes,
            },
        )

        unit_body = self.render_plain_tcp_mux_unit(service_name, listen_port, routes_path) if use_plain_tcp_mux else self.render_listener_unit(service_name, listen_port, routes_path)
        unit_changed = self.write_text_if_changed(unit_path, unit_body)
        if shutil.which("systemctl"):
            if stale_unit_path.exists():
                run_command(f"systemctl stop {stale_service_name}.service", check=False)
                run_command(f"systemctl disable {stale_service_name}.service", check=False)
                stale_unit_path.unlink()
            if unit_changed:
                run_command("systemctl daemon-reload")
            run_command(f"systemctl enable {service_name}.service")
            if unit_changed:
                run_command(f"systemctl restart {service_name}.service")
            else:
                run_command(f"systemctl start {service_name}.service", check=False)

    @staticmethod
    def systemd_is_active(service_name: str) -> bool:
        if not shutil.which("systemctl"):
            return False
        return run_command(f"systemctl is-active --quiet {service_name}.service", check=False).returncode == 0

    def ensure_backend_service(self, service_name: str, unit_changed: bool) -> None:
        if not shutil.which("systemctl"):
            return
        if unit_changed:
            run_command("systemctl daemon-reload")
        run_command(f"systemctl enable {service_name}.service")
        is_active = self.systemd_is_active(service_name)
        if unit_changed and is_active:
            run_command(f"systemctl restart {service_name}.service")
        elif not is_active:
            run_command(f"systemctl start {service_name}.service")

    def render_quic_relay_unit(self, service_name: str, listen_port: int, upstream: str) -> str:
        relay_bin = shell_arg(str(self.config.get("leg_relay_bin", "/usr/local/bin/leikwan-leg-relay")))
        keepalive_seconds = max(1, int(self.config.get("relay_quic_keepalive_seconds", 5) or 5))
        max_idle_seconds = max(keepalive_seconds + 5, int(self.config.get("relay_quic_max_idle_seconds", 45) or 45))
        initial_mtu = max(1200, int(self.config.get("relay_quic_initial_mtu", 1200) or 1200))
        min_mtu = min(initial_mtu, max(1200, int(self.config.get("relay_quic_min_mtu", 1200) or 1200)))
        mtu_upper_bound = max(initial_mtu, int(self.config.get("relay_quic_mtu_upper_bound", 1452) or 1452))
        mtu_interval = max(30, int(self.config.get("relay_quic_mtu_discovery_interval_seconds", 300) or 300))
        mtu_black_hole = max(5, int(self.config.get("relay_quic_mtu_black_hole_cooldown_seconds", 30) or 30))
        mtu_minimum_change = max(1, int(self.config.get("relay_quic_mtu_minimum_change", 20) or 20))
        return f"""[Unit]
Description=Leikwan QUIC Relay {service_name}
After=network.target

[Service]
Type=simple
ExecStart={relay_bin} server --transport quic --listen [::]:{listen_port} --upstream {shell_arg(upstream)} --keepalive-seconds {keepalive_seconds} --max-idle-seconds {max_idle_seconds} --initial-mtu {initial_mtu} --min-mtu {min_mtu} --mtu-discovery-upper-bound {mtu_upper_bound} --mtu-discovery-interval-seconds {mtu_interval} --mtu-black-hole-cooldown-seconds {mtu_black_hole} --mtu-minimum-change {mtu_minimum_change}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    def render_quic_relay_client_unit(self, service_name: str, listen_port: int, remote: str) -> str:
        relay_bin = shell_arg(str(self.config.get("leg_relay_bin", "/usr/local/bin/leikwan-leg-relay")))
        remote_host, _ = split_host_port(remote)
        keepalive_seconds = max(1, int(self.config.get("relay_quic_keepalive_seconds", 5) or 5))
        max_idle_seconds = max(keepalive_seconds + 5, int(self.config.get("relay_quic_max_idle_seconds", 45) or 45))
        prefetch_connections = max(0, int(self.config.get("relay_quic_prefetch_connections", 4) or 4))
        initial_mtu = max(1200, int(self.config.get("relay_quic_initial_mtu", 1200) or 1200))
        min_mtu = min(initial_mtu, max(1200, int(self.config.get("relay_quic_min_mtu", 1200) or 1200)))
        mtu_upper_bound = max(initial_mtu, int(self.config.get("relay_quic_mtu_upper_bound", 1452) or 1452))
        mtu_interval = max(30, int(self.config.get("relay_quic_mtu_discovery_interval_seconds", 300) or 300))
        mtu_black_hole = max(5, int(self.config.get("relay_quic_mtu_black_hole_cooldown_seconds", 30) or 30))
        mtu_minimum_change = max(1, int(self.config.get("relay_quic_mtu_minimum_change", 20) or 20))
        return f"""[Unit]
Description=Leikwan QUIC Relay Client {service_name}
After=network.target

[Service]
Type=simple
ExecStart={relay_bin} client --listen 127.0.0.1:{listen_port} --remote {shell_arg(remote)} --server-name {shell_arg(remote_host)} --prefetch-connections {prefetch_connections} --keepalive-seconds {keepalive_seconds} --max-idle-seconds {max_idle_seconds} --initial-mtu {initial_mtu} --min-mtu {min_mtu} --mtu-discovery-upper-bound {mtu_upper_bound} --mtu-discovery-interval-seconds {mtu_interval} --mtu-black-hole-cooldown-seconds {mtu_black_hole} --mtu-minimum-change {mtu_minimum_change}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    def render_backend_unit(self, service_name: str, backend_port: int, target: str, path_prefix: str) -> str:
        return f"""[Unit]
Description=Leikwan Wstunnel Backend {service_name}
After=network.target

[Service]
Type=simple
ExecStart={self.config['wstunnel_bin']} server ws://127.0.0.1:{backend_port} \\
  --restrict-to {shell_arg(target)} \\
  --restrict-http-upgrade-path-prefix {shell_arg(path_prefix)} \\
  --websocket-ping-frequency-sec 5
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    def render_plain_tcp_backend_unit(self, service_name: str, backend_port: int, target: str) -> str:
        ingress_bin = shell_arg(str(self.config.get("ingress_bin", "/usr/local/bin/leikwan-ingress")))
        return f"""[Unit]
Description=Leikwan Plain TCP Backend {service_name}
After=network.target

[Service]
Type=simple
ExecStart={ingress_bin} backend --listen 127.0.0.1:{backend_port} --target {shell_arg(target)}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    def render_plain_tcp_mux_unit(self, service_name: str, listen_port: int, routes_path: Path) -> str:
        ingress_bin = shell_arg(str(self.config.get("ingress_bin", "/usr/local/bin/leikwan-ingress")))
        listen_args = " ".join(
            f"--listen {shell_arg((f'[{host}]' if ':' in host and not host.startswith('[') else host) + f':{listen_port}')}"
            for host in self.mux_listen_hosts()
        )
        return f"""[Unit]
Description=Leikwan Plain TCP Mux {service_name}
After=network.target

[Service]
Type=simple
ExecStart={ingress_bin} mux {listen_args} --routes-file {shell_arg(str(routes_path))}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    def wireguard_interface_path(self, interface_name: str) -> Path:
        return Path("/etc/wireguard") / f"{interface_name}.conf"

    def apply_mptcp_underlay(self, service_name: str, underlay: dict[str, Any]) -> None:
        if not shutil.which("wg") or not shutil.which("wg-quick"):
            return
        self.ensure_wireguard_identity()
        private_key = self.wireguard_private_key_path().read_text(encoding="utf-8").strip()
        interface_name = str(underlay.get("interface_name", service_name.replace("wstunnel-svc-", "lwm")) or service_name).strip()[:15]
        listen_port = max(1, int(underlay.get("listen_port", 0) or 0))
        slave_address = str(underlay.get("slave_address", "") or "").strip()
        client_address = str(underlay.get("client_address", "") or "").strip()
        sidecar_address = str(underlay.get("sidecar_address", "") or "").strip()
        client_public_key = str(underlay.get("client_public_key", "") or "").strip()
        sidecar_public_key = str(underlay.get("sidecar_public_key", "") or "").strip()
        sidecar_endpoint_host = str(underlay.get("sidecar_endpoint_host", "") or "").strip()
        sidecar_endpoint_port = max(0, int(underlay.get("sidecar_endpoint_port", 0) or 0))
        peer_keepalive_seconds = max(0, int(underlay.get("peer_keepalive_seconds", 25) or 25))
        if slave_address == "" or client_address == "" or sidecar_address == "" or client_public_key == "" or sidecar_public_key == "":
            return
        sidecar_endpoint = ""
        if sidecar_endpoint_host != "" and sidecar_endpoint_port > 0:
            sidecar_endpoint = f"Endpoint = {sidecar_endpoint_host}:{sidecar_endpoint_port}\n"
        sidecar_keepalive = f"PersistentKeepalive = {peer_keepalive_seconds}\n" if peer_keepalive_seconds > 0 else ""
        config = (
            "[Interface]\n"
            f"PrivateKey = {private_key}\n"
            f"ListenPort = {listen_port}\n"
            f"Address = {slave_address}/32\n"
            "PostUp = sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true\n"
            "PostUp = sysctl -w net.ipv6.conf.all.forwarding=1 >/dev/null 2>&1 || true\n"
            "\n"
            "[Peer]\n"
            f"PublicKey = {client_public_key}\n"
            f"AllowedIPs = {client_address}/32\n"
            "\n"
            "[Peer]\n"
            f"PublicKey = {sidecar_public_key}\n"
            f"{sidecar_endpoint}"
            f"AllowedIPs = {sidecar_address}/32\n"
            f"{sidecar_keepalive}"
        )
        path = self.wireguard_interface_path(interface_name)
        changed = self.write_text_if_changed(path, config)
        if str(underlay.get("outer_transport", "udp") or "udp").strip().lower() == "tcp":
            self.ensure_uot_server_service(interface_name, int(underlay.get("fallback_tcp_port", 0) or 0), listen_port)
        if shutil.which("systemctl"):
            if changed:
                run_command("systemctl daemon-reload", check=False)
            run_command(f"systemctl enable wg-quick@{interface_name}.service", check=False)
            run_command(f"systemctl restart wg-quick@{interface_name}.service", check=False)

    def remove_mptcp_underlay(self, service_name: str, underlay: dict[str, Any] | None = None) -> None:
        interface_name = self.mptcp_underlay_interface_name(service_name, underlay)
        if shutil.which("systemctl"):
            run_command(f"systemctl stop wg-quick@{interface_name}.service", check=False)
            run_command(f"systemctl disable wg-quick@{interface_name}.service", check=False)
            run_command(f"systemctl reset-failed wg-quick@{interface_name}.service", check=False)
        if shutil.which("wg-quick"):
            run_command(f"wg-quick down {interface_name}", check=False)
        if shutil.which("ip"):
            run_command(f"ip link delete dev {interface_name}", check=False)
        self.remove_uot_server_service(interface_name)
        path = self.wireguard_interface_path(interface_name)
        if path.exists():
            path.unlink()

    @staticmethod
    def mptcp_underlay_interface_name(service_name: str, underlay: dict[str, Any] | None = None) -> str:
        if isinstance(underlay, dict):
            explicit = str(underlay.get("interface_name", "") or "").strip()
            if explicit != "":
                return explicit[:15]
        return service_name.replace("wstunnel-svc-", "lwm")[:15]

    def cleanup_orphan_mptcp_underlays(self) -> None:
        if not shutil.which("wg"):
            return
        active_interfaces: set[str] = set()
        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            underlay = metadata.get("mptcp_underlay", {})
            if not isinstance(underlay, dict) or not underlay.get("enabled"):
                continue
            active_interfaces.add(
                self.mptcp_underlay_interface_name(str(metadata.get("service_name", "") or ""), underlay)
            )

        result = run_command("wg show interfaces", check=False)
        for iface in result.stdout.strip().split():
            if not (iface.startswith("lwf") or iface.startswith("lwm")):
                continue
            if iface in active_interfaces:
                continue
            logging.info("removing orphan mptcp underlay interface: %s", iface)
            if shutil.which("systemctl"):
                run_command(f"systemctl stop wg-quick@{iface}.service", check=False)
                run_command(f"systemctl disable wg-quick@{iface}.service", check=False)
                run_command(f"systemctl reset-failed wg-quick@{iface}.service", check=False)
            if shutil.which("wg-quick"):
                run_command(f"wg-quick down {iface}", check=False)
            if shutil.which("ip"):
                run_command(f"ip link delete dev {iface}", check=False)
            self.remove_uot_server_service(iface)
            path = self.wireguard_interface_path(iface)
            if path.exists():
                path.unlink()

        if shutil.which("systemctl"):
            unit_result = run_command(
                "systemctl list-units 'wg-quick@lwf*' 'wg-quick@lwm*' --type=service --all --no-legend --no-pager",
                check=False,
            )
            for line in unit_result.stdout.splitlines():
                unit_name = line.strip().split()[0] if line.strip() else ""
                match = re.match(r"^wg-quick@([^.]+)\.service$", unit_name)
                if match is None:
                    continue
                iface = match.group(1)
                if iface in active_interfaces:
                    continue
                run_command(f"systemctl stop {unit_name}", check=False)
                run_command(f"systemctl disable {unit_name}", check=False)
                run_command(f"systemctl reset-failed {unit_name}", check=False)

    def ensure_uot_server_service(self, interface_name: str, listen_tcp_port: int, target_udp_port: int) -> None:
        if listen_tcp_port <= 0 or target_udp_port <= 0:
            return
        service_name = f"leikwan-uot-server-{interface_name}"
        unit_path = Path(f"/etc/systemd/system/{service_name}.service")
        body = f"""[Unit]
Description=Leikwan UoT Server {service_name}
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/leikwan-uot.py server --listen-tcp 0.0.0.0:{listen_tcp_port} --target-udp 127.0.0.1:{target_udp_port}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
        changed = self.write_text_if_changed(unit_path, body)
        if shutil.which("systemctl"):
            if changed:
                run_command("systemctl daemon-reload", check=False)
            run_command(f"systemctl enable {service_name}.service", check=False)
            run_command(f"systemctl restart {service_name}.service", check=False)

    def remove_uot_server_service(self, interface_name: str) -> None:
        service_name = f"leikwan-uot-server-{interface_name}"
        unit_path = Path(f"/etc/systemd/system/{service_name}.service")
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command("systemctl daemon-reload", check=False)
        if unit_path.exists():
            unit_path.unlink()

    def render_listener_unit(self, service_name: str, listen_port: int, routes_path: Path) -> str:
        agent_entrypoint = shell_arg(str(Path(__file__).resolve()))
        config_path = shell_arg(str(self.config_path.resolve()))
        routes_file = shell_arg(str(routes_path))
        return f"""[Unit]
Description=Leikwan WSS Path Mux :{listen_port}
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 {agent_entrypoint} --config {config_path} mux --public-port {listen_port} --routes-file {routes_file}
Restart=always
RestartSec=3
WorkingDirectory={self.state_dir}
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    @staticmethod
    def write_text_if_changed(path: Path, content: str) -> bool:
        if path.exists() and path.read_text(encoding="utf-8") == content:
            return False
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = path.with_name(path.name + ".tmp")
        tmp_path.write_text(content, encoding="utf-8")
        tmp_path.replace(path)
        return True

    def write_json(self, path: Path, payload: dict[str, Any]) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = path.with_name(path.name + ".tmp")
        tmp_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
        tmp_path.replace(path)

    def ensure_nft_table(self) -> None:
        if not shutil.which("nft"):
            return
        run_command("nft list table inet leikwan_meter >/dev/null 2>&1 || nft add table inet leikwan_meter", check=False)
        run_command(
            "nft list chain inet leikwan_meter input >/dev/null 2>&1 || nft add chain inet leikwan_meter input '{ type filter hook input priority 0; policy accept; }'",
            check=False,
        )
        run_command(
            "nft list chain inet leikwan_meter output >/dev/null 2>&1 || nft add chain inet leikwan_meter output '{ type filter hook output priority 0; policy accept; }'",
            check=False,
        )

    def add_nft_rules(self, endpoint_id: int, port: int) -> None:
        if not shutil.which("nft"):
            return
        run_command(
            f'nft add rule inet leikwan_meter input tcp dport {port} counter comment "lw_ep_{endpoint_id}_in_tcp"',
            check=False,
        )
        run_command(
            f'nft add rule inet leikwan_meter output tcp sport {port} counter comment "lw_ep_{endpoint_id}_out_tcp"',
            check=False,
        )
        run_command(
            f'nft add rule inet leikwan_meter input udp dport {port} counter comment "lw_ep_{endpoint_id}_in_udp"',
            check=False,
        )
        run_command(
            f'nft add rule inet leikwan_meter output udp sport {port} counter comment "lw_ep_{endpoint_id}_out_udp"',
            check=False,
        )

    def delete_nft_rules(self, endpoint_id: int) -> None:
        if not shutil.which("nft"):
            return
        result = run_command("nft -a list table inet leikwan_meter", check=False)
        current_chain = ""
        for line in result.stdout.splitlines():
            stripped = line.strip()
            chain_match = re.match(r"chain\s+(\w+)", stripped)
            if chain_match:
                current_chain = chain_match.group(1)
                continue
            if f'lw_ep_{endpoint_id}_' not in stripped:
                continue
            handle_match = re.search(r"# handle (\d+)", stripped)
            if handle_match and current_chain:
                handle = handle_match.group(1)
                run_command(f"nft delete rule inet leikwan_meter {current_chain} handle {handle}", check=False)

    def report_usage(self) -> None:
        if not self.config.get("node_token"):
            return
        entries = self.collect_usage_entries()
        if not entries:
            return
        http_json(
            "POST",
            build_url(self.config["master_url"], "/api/node/report-usage"),
            self.config["node_token"],
            {"entries": entries},
        )

    def collect_usage_entries(self) -> list[dict[str, Any]]:
        if not shutil.which("nft"):
            return []

        result = run_command("nft -a list table inet leikwan_meter", check=False)
        if result.returncode != 0:
            return []

        usage_state = {}
        if self.usage_state_path.exists():
            try:
                usage_state = json.loads(self.usage_state_path.read_text(encoding="utf-8"))
            except json.JSONDecodeError:
                usage_state = {}

        current_bytes: dict[str, int] = {}
        for line in result.stdout.splitlines():
            comment_match = re.search(r'comment "([^"]+)"', line)
            bytes_match = re.search(r"bytes (\d+)", line)
            if comment_match and bytes_match:
                current_bytes[comment_match.group(1)] = int(bytes_match.group(1))

        per_endpoint: dict[int, dict[str, int]] = {}
        for comment, total_bytes in current_bytes.items():
            previous = int(usage_state.get(comment, 0))
            delta = total_bytes - previous if total_bytes >= previous else total_bytes
            usage_state[comment] = total_bytes

            endpoint_match = re.match(r"lw_ep_(\d+)_(in|out)_", comment)
            if not endpoint_match:
                continue
            endpoint_id = int(endpoint_match.group(1))
            direction = endpoint_match.group(2)
            bucket = per_endpoint.setdefault(endpoint_id, {"bytes_in": 0, "bytes_out": 0})
            bucket[f"bytes_{direction}"] += max(0, delta)

        self.usage_state_path.write_text(json.dumps(usage_state, ensure_ascii=False, indent=2), encoding="utf-8")

        entries: list[dict[str, Any]] = []
        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            endpoint_id = int(metadata.get("endpoint_id", 0) or 0)
            if endpoint_id <= 0 or endpoint_id not in per_endpoint:
                continue
            counters = per_endpoint[endpoint_id]
            if counters["bytes_in"] == 0 and counters["bytes_out"] == 0:
                continue
            entries.append(
                {
                    "service_id": int(metadata["service_id"]),
                    "forward_id": int(metadata["forward_id"]),
                    "endpoint_id": endpoint_id,
                    "bytes_in": counters["bytes_in"],
                    "bytes_out": counters["bytes_out"],
                }
            )
        return entries

    @staticmethod
    def cpu_usage_hint() -> float:
        try:
            return round(os.getloadavg()[0], 2)
        except OSError:
            return 0.0

    @staticmethod
    def memory_usage_percent() -> float:
        mem_total = 0
        mem_available = 0
        try:
            with open("/proc/meminfo", "r", encoding="utf-8") as handle:
                for line in handle:
                    if line.startswith("MemTotal:"):
                        mem_total = int(line.split()[1])
                    elif line.startswith("MemAvailable:"):
                        mem_available = int(line.split()[1])
            if mem_total > 0:
                used = mem_total - mem_available
                return round(used * 100 / mem_total, 2)
        except FileNotFoundError:
            pass
        return 0.0

    @staticmethod
    def disk_usage_percent() -> float:
        usage = shutil.disk_usage("/")
        return round(usage.used * 100 / usage.total, 2) if usage.total else 0.0

    def check(self) -> dict[str, Any]:
        master_ok = False
        master_error = ""
        master_url = str(self.config.get("master_url", "") or "")
        if master_url != "":
            try:
                response = http_json("GET", build_url(master_url, "/api/health"))
                master_ok = bool(response.get("ok", True))
            except Exception as exc:  # noqa: BLE001
                master_error = str(exc)

        route_files = sorted(path.name for path in self.listeners_dir.glob("routes-*.json"))
        summary = {
            "ok": True,
            "agent_version": AGENT_VERSION,
            "config_path": str(self.config_path),
            "state_dir": str(self.state_dir),
            "master_url": master_url,
            "node_id": int(self.config.get("node_id", 0) or 0),
            "node_token_present": bool(self.config.get("node_token")),
            "service_active": self.systemd_is_active("leikwan-slave-agent"),
            "master_health": {
                "ok": master_ok,
                "error": master_error,
            },
            "pull_state": self.load_pull_state(),
            "active_service_count": self.active_service_count(),
            "listener_route_files": route_files,
            "capabilities": {
                "supports_udp": bool(self.config.get("supports_udp", True)),
                "supports_filtering": bool(self.config.get("supports_filtering", False)),
                "supports_mptcp": bool(self.config.get("supports_mptcp", False)),
                "supports_quic": bool(self.config.get("supports_quic", False)),
                "supports_bond_hybrid": bool(self.config.get("supports_bond_hybrid", True)),
                "supports_sidecar_quic": bool(self.config.get("supports_sidecar_quic", True)),
                "bond_protocols": list(self.config.get("bond_protocols", ["tcp"])),
                "hybrid_modes": list(self.config.get("hybrid_modes", ["quic_ws"])),
            },
        }
        if master_url != "" and not master_ok and master_error != "":
            summary["ok"] = False
        return summary


def main() -> int:
    parser = argparse.ArgumentParser(description="Leikwan slave agent")
    parser.add_argument("--config", default="/etc/leikwan-slave/config.json")
    subparsers = parser.add_subparsers(dest="command", required=True)

    subparsers.add_parser("register")
    subparsers.add_parser("run")
    subparsers.add_parser("version")
    subparsers.add_parser("check")
    mux_parser = subparsers.add_parser("mux")
    mux_parser.add_argument("--public-port", type=int, required=True)
    mux_parser.add_argument("--routes-file", default="")

    args = parser.parse_args()
    agent = SlaveAgent(Path(args.config))
    if args.command == "register":
        agent.register()
        return 0
    if args.command == "run":
        agent.run()
        return 0
    if args.command == "version":
        print(AGENT_VERSION)
        return 0
    if args.command == "check":
        summary = agent.check()
        print(json.dumps(summary, ensure_ascii=False, indent=2))
        return 0 if summary.get("ok", False) else 1
    if args.command == "mux":
        routes_file = Path(args.routes_file) if args.routes_file else None
        try:
            asyncio.run(agent.run_mux(args.public_port, routes_file))
        except KeyboardInterrupt:
            return 0
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())
