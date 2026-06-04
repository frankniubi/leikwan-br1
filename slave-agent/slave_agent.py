#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import contextlib
import email.utils
import errno
import html
import ipaddress
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

AGENT_VERSION = "2026.06.05.2"
MAX_HTTP_HEADER_BYTES = 65536
HTTP_HEADER_TIMEOUT_SECONDS = 10
IDENTITY_REFRESH_SECONDS = 300
UPDATE_RETRY_SECONDS = 300
UPDATE_STALE_SECONDS = 900
COMMAND_TIMEOUT_SECONDS = 30

# Shared restart ledger (cross-language byte contract). The on-disk format is
# one append-only file per interface at <dir>/<iface>.log, where each restart
# appends a single line: the integer epoch-seconds, a space, the source token,
# terminated by a newline. The same format is written/read by
# leikwan-wg-watchdog.sh and cfwg_latency_watchdog.py.
RESTART_LEDGER_DIR = Path("/run/leikwan/restart-ledger")
RESTART_LEDGER_WINDOW_SECONDS = 600
RESTART_LEDGER_MAX = 3


def _restart_ledger_window() -> int:
    try:
        value = int(os.environ.get("LEIKWAN_RESTART_LEDGER_WINDOW", "") or RESTART_LEDGER_WINDOW_SECONDS)
    except (TypeError, ValueError):
        return RESTART_LEDGER_WINDOW_SECONDS
    return value if value > 0 else RESTART_LEDGER_WINDOW_SECONDS


def _restart_ledger_max() -> int:
    try:
        value = int(os.environ.get("LEIKWAN_RESTART_LEDGER_MAX", "") or RESTART_LEDGER_MAX)
    except (TypeError, ValueError):
        return RESTART_LEDGER_MAX
    return value if value > 0 else RESTART_LEDGER_MAX


def restart_ledger_recent_count(interface_name: str, now: float) -> int:
    """Count ledger entries for <interface_name> within the breaker window.

    Best-effort: any read/parse error counts as 0 (fail-open) so a missing or
    corrupt ledger never blocks a legitimate restart, and never raises.
    """
    iface = str(interface_name or "").strip()
    if iface == "":
        return 0
    window = _restart_ledger_window()
    cutoff = float(now) - float(window)
    path = RESTART_LEDGER_DIR / f"{iface}.log"
    count = 0
    try:
        with path.open("r", encoding="utf-8") as handle:
            for line in handle:
                line = line.strip()
                if line == "":
                    continue
                head = line.split(" ", 1)[0]
                try:
                    epoch = int(head)
                except (TypeError, ValueError):
                    continue
                if epoch >= cutoff:
                    count += 1
    except (OSError, ValueError):
        return 0
    return count


def restart_ledger_append(interface_name: str, source_token: str, now: float) -> None:
    """Atomically append one ledger line for a performed restart.

    Tolerates the directory being absent or unwritable -> fail-open (the
    restart still happens, we just fail to record it). Never raises.
    """
    iface = str(interface_name or "").strip()
    token = str(source_token or "").strip()
    if iface == "" or token == "":
        return
    try:
        RESTART_LEDGER_DIR.mkdir(parents=True, exist_ok=True)
        try:
            os.chmod(RESTART_LEDGER_DIR, 0o755)
        except OSError:
            pass
        path = RESTART_LEDGER_DIR / f"{iface}.log"
        line = f"{int(now)} {token}\n"
        fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o644)
        try:
            os.write(fd, line.encode("utf-8"))
        finally:
            os.close(fd)
    except OSError:
        return


def restart_circuit_breaker_allow(interface_name: str, source_token: str, now: float | None = None) -> bool:
    """Circuit breaker gate evaluated BEFORE restarting <interface_name>.

    Returns True and records one ledger line if the restart is permitted.
    Returns False (and records nothing) when the breaker is open: the count of
    restarts within the window has reached the max. On suppression a WARN is
    logged. The caller is responsible for performing the actual restart only
    when this returns True.
    """
    moment = time.time() if now is None else float(now)
    recent = restart_ledger_recent_count(interface_name, moment)
    if recent >= _restart_ledger_max():
        logging.warning(
            "circuit breaker open for %s: %s restarts in %ss; backing off",
            interface_name,
            recent,
            _restart_ledger_window(),
        )
        return False
    restart_ledger_append(interface_name, source_token, moment)
    return True

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
    "gost_bin": "/usr/local/bin/gost",
    "lguot_bin": "/usr/local/bin/lguot",
    "leigod_bin": "/usr/local/bin/leigod-linux-amd64",
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
    "wireguard_config_dir": "/etc/wireguard",
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


def command_timeout_seconds() -> int:
    raw = str(os.environ.get("LEIKWAN_COMMAND_TIMEOUT_SECONDS", str(COMMAND_TIMEOUT_SECONDS)) or str(COMMAND_TIMEOUT_SECONDS))
    try:
        value = int(raw)
    except ValueError:
        value = COMMAND_TIMEOUT_SECONDS
    return max(1, value)


def _timeout_text(value: str | bytes | None) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    return str(value)


def run_command(command: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    logging.debug("run: %s", command)
    timeout_seconds = command_timeout_seconds()
    try:
        result = subprocess.run(command, shell=True, text=True, capture_output=True, timeout=timeout_seconds)
    except subprocess.TimeoutExpired as exc:
        stdout = _timeout_text(exc.stdout or exc.output)
        stderr = _timeout_text(exc.stderr)
        if stderr:
            stderr += "\n"
        stderr += f"command timed out after {timeout_seconds}s: {command}"
        result = subprocess.CompletedProcess(command, 124, stdout=stdout, stderr=stderr)
    if check and result.returncode != 0:
        raise RuntimeError(f"command failed: {command}\nstdout={result.stdout}\nstderr={result.stderr}")
    return result


def truthy(value: object) -> bool:
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def is_transient_systemd_error(detail: str) -> bool:
    text = str(detail or "").lower()
    return any(item in text for item in (
        "transport endpoint is not connected",
        "connection timed out",
        "command timed out after",
        "timed out",
        "failed to connect to bus",
        "system has not been booted with systemd",
    ))



def wireguard_peers_match_config(interface_name: str, expected_public_keys: list[str]) -> bool:
    expected = {str(item or "").strip() for item in expected_public_keys if str(item or "").strip() != ""}
    if interface_name == "" or not expected:
        return False
    result = run_command(f"wg show {interface_name} peers", check=False)
    if int(getattr(result, "returncode", 0) or 0) != 0:
        return False
    actual = {item.strip() for item in str(getattr(result, "stdout", "") or "").split() if item.strip() != ""}
    return expected.issubset(actual)

def run_systemctl_tolerating_transient(command: str) -> bool:
    result = run_command(command, check=False)
    if int(getattr(result, "returncode", 0) or 0) == 0:
        return True
    detail = str(getattr(result, "stderr", "") or getattr(result, "stdout", "") or "")
    if is_transient_systemd_error(detail):
        logging.warning("transient systemd failure ignored for %s: %s", command, detail.strip())
        return False
    raise RuntimeError(
        f"command failed: {command}\n"
        f"stdout={getattr(result, 'stdout', '')}\n"
        f"stderr={getattr(result, 'stderr', '')}"
    )


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


def safe_service_basename(service_name: str) -> str:
    value = str(service_name or "").strip()
    return re.sub(r"[^A-Za-z0-9_.-]+", "-", value)[:120] or "selector"


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


def udp_uot_base_service_name(service_name: str) -> str:
    suffix = "-udp-uot"
    value = str(service_name).strip()
    if value.endswith(suffix):
        return value[: -len(suffix)]
    return value


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


class SidecarUplinkSelectorProxy:
    def __init__(self, agent: "SlaveAgent", service_name: str, selector: dict[str, Any]) -> None:
        self.agent = agent
        self.service_name = service_name
        self.selector = selector
        self.selector_port = int(selector.get("selector_port", 0) or 0)
        self.state_path = agent.sidecar_uplink_selector_state_path(service_name)

    async def relay(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            while True:
                chunk = await reader.read(65536)
                if not chunk:
                    break
                writer.write(chunk)
                await writer.drain()
        except Exception:  # noqa: BLE001
            logging.debug("sidecar selector relay closed", exc_info=True)
        finally:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def refresh_loop(self) -> None:
        interval = max(1.0, float(self.selector.get("probe_interval_seconds", 3) or 3))
        while True:
            try:
                previous = self.agent.load_sidecar_uplink_selector_state(self.service_name)
                state = self.agent.evaluate_sidecar_uplink_selector(self.selector, previous)
                self.agent.save_sidecar_uplink_selector_state(self.service_name, state)
            except Exception:  # noqa: BLE001
                logging.exception("sidecar selector %s health refresh failed", self.service_name)
            await asyncio.sleep(interval)

    async def handle_client(self, client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter) -> None:
        state = self.agent.load_sidecar_uplink_selector_state(self.service_name)
        if not isinstance(state, dict) or str(state.get("active_target_host", "") or "") == "":
            state = self.agent.evaluate_sidecar_uplink_selector(self.selector, state if isinstance(state, dict) else {})
            self.agent.save_sidecar_uplink_selector_state(self.service_name, state)
        target_host = str(state.get("active_target_host", "") or "").strip()
        target_port = int(state.get("active_target_port", 0) or 0)
        if target_host == "" or target_port <= 0:
            client_writer.close()
            with contextlib.suppress(Exception):
                await client_writer.wait_closed()
            return
        try:
            target_reader, target_writer = await asyncio.open_connection(target_host, target_port)
        except Exception:  # noqa: BLE001
            logging.exception("sidecar selector %s failed to connect active target %s:%s", self.service_name, target_host, target_port)
            # Force a synchronous re-evaluation and retry once. This handles the
            # case where the active lane died between probe intervals.
            state = self.agent.evaluate_sidecar_uplink_selector(self.selector, state)
            self.agent.save_sidecar_uplink_selector_state(self.service_name, state)
            target_host = str(state.get("active_target_host", "") or "").strip()
            target_port = int(state.get("active_target_port", 0) or 0)
            try:
                target_reader, target_writer = await asyncio.open_connection(target_host, target_port)
            except Exception:  # noqa: BLE001
                client_writer.close()
                with contextlib.suppress(Exception):
                    await client_writer.wait_closed()
                return
        await asyncio.gather(
            self.relay(client_reader, target_writer),
            self.relay(target_reader, client_writer),
        )

    async def serve(self) -> None:
        if self.selector_port <= 0:
            raise RuntimeError("sidecar selector missing selector_port")
        initial_state = self.agent.evaluate_sidecar_uplink_selector(
            self.selector,
            self.agent.load_sidecar_uplink_selector_state(self.service_name),
        )
        self.agent.save_sidecar_uplink_selector_state(self.service_name, initial_state)
        try:
            server = await asyncio.start_server(
                self.handle_client,
                host="127.0.0.1",
                port=self.selector_port,
                backlog=1024,
            )
        except OSError as exc:
            if getattr(exc, "errno", None) == errno.EADDRINUSE:
                logging.error("sidecar selector %s port 127.0.0.1:%s already in use; exiting cleanly", self.service_name, self.selector_port)
                return
            raise
        logging.info("sidecar selector %s listening on 127.0.0.1:%s", self.service_name, self.selector_port)
        refresh_task = asyncio.create_task(self.refresh_loop())
        try:
            await server.serve_forever()
        finally:
            refresh_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await refresh_task
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
        self._static_uplink_restart_at: dict[str, float] = {}
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

    def latency_policy_state_path(self) -> Path:
        return self.state_dir / "forward-chain-latency-state.json"

    def load_latency_policy_state(self) -> dict[str, Any]:
        path = self.latency_policy_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_latency_policy_state(self, payload: dict[str, Any]) -> None:
        self.write_json(self.latency_policy_state_path(), payload)

    def cf_location_state_path(self) -> Path:
        return self.state_dir / "cf-location-best-ip.json"

    def load_cf_location_state(self) -> dict[str, Any]:
        path = self.cf_location_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_cf_location_state(self, payload: dict[str, Any]) -> None:
        self.write_json(self.cf_location_state_path(), payload)

    @staticmethod
    def tcp_ping(host: str, port: int, timeout: float = 1.0) -> float | None:
        started = time.time()
        try:
            with socket.create_connection((host, port), timeout=timeout):
                return round((time.time() - started) * 1000, 1)
        except Exception:
            return None

    def probe_cf_websocket_upgrade(
        self,
        ip: str,
        port: int,
        sni_host: str,
        path_prefix: str,
        timeout: float = 1.0,
    ) -> tuple[bool, float | None, str | None]:
        request_path = "/" + normalize_path_prefix(path_prefix)
        host_header = sni_host.strip() or ip
        server_name = sni_host.strip() or host_header
        started = time.time()
        try:
            with socket.create_connection((ip, port), timeout=timeout) as raw_sock:
                context = ssl.create_default_context()
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                with context.wrap_socket(raw_sock, server_hostname=server_name) as tls_sock:
                    tls_sock.settimeout(timeout)
                    request_bytes = (
                        f"GET {request_path} HTTP/1.1\r\n"
                        f"Host: {host_header}\r\n"
                        "Upgrade: websocket\r\n"
                        "Connection: Upgrade\r\n"
                        "Sec-WebSocket-Key: dGVzdC1sZWlrd2FuLXByb2Jl\r\n"
                        "Sec-WebSocket-Version: 13\r\n\r\n"
                    ).encode("utf-8")
                    tls_sock.sendall(request_bytes)
                    response_head = tls_sock.recv(4096).decode("latin-1", errors="ignore")
            status_line = response_head.split("\r\n", 1)[0]
            if " 101 " not in status_line:
                return False, None, status_line or "websocket_upgrade_failed"
            return True, round((time.time() - started) * 1000, 1), None
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

    def probe_cf_websocket_path(
        self,
        ip: str,
        port: int,
        sni_host: str,
        path_prefix: str,
        probe_target: str = "",
        timeout: float = 1.0,
        settle_seconds: float = 0.9,
    ) -> tuple[bool, float | None, str | None]:
        effective_target = str(probe_target or "").strip()
        if effective_target == "":
            return self.probe_cf_websocket_upgrade(ip, port, sni_host, path_prefix, timeout=timeout)

        wstunnel_bin = str(self.config.get("wstunnel_bin", "/usr/local/bin/wstunnel") or "/usr/local/bin/wstunnel").strip() or "/usr/local/bin/wstunnel"
        if not Path(wstunnel_bin).exists():
            return self.probe_cf_websocket_upgrade(ip, port, sni_host, path_prefix, timeout=timeout)

        normalized_path = normalize_path_prefix(path_prefix)
        host_header = sni_host.strip() or ip
        server_name = sni_host.strip() or host_header
        started = time.time()
        local_port = 0
        with contextlib.closing(socket.socket(socket.AF_INET, socket.SOCK_STREAM)) as port_sock:
            port_sock.bind(("127.0.0.1", 0))
            local_port = int(port_sock.getsockname()[1])

        command = [
            wstunnel_bin,
            "client",
            "-L",
            f"tcp://127.0.0.1:{local_port}:{effective_target}",
            "--tls-sni-override",
            server_name,
            "--http-headers",
            f"Host: {host_header}",
            "--http-upgrade-path-prefix",
            normalized_path,
            f"wss://{ip}:{port}",
        ]

        process = subprocess.Popen(
            command,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
        )

        stderr_output = ""

        def stop_and_collect() -> str:
            nonlocal stderr_output
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=1.0)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=1.0)
            if process.stderr is not None:
                try:
                    stderr_output += process.stderr.read() or ""
                except Exception:  # noqa: BLE001
                    pass
            return stderr_output

        def failure_from_output(output: str) -> str:
            lower = output.lower()
            markers = [
                "failed to do websocket handshake",
                "invalid status code",
                "websocket handshake",
                "http status",
                " 520",
                " 400",
            ]
            if any(marker in lower for marker in markers):
                lines = [line.strip() for line in output.splitlines() if line.strip() != ""]
                return lines[-1] if lines else "websocket_handshake_failed"
            return ""

        try:
            deadline = started + max(0.5, timeout)
            last_error = "probe_timeout"
            while time.time() < deadline:
                if process.poll() is not None:
                    output = stop_and_collect()
                    return False, None, failure_from_output(output) or f"wstunnel_exit_{process.returncode}"
                try:
                    with socket.create_connection(("127.0.0.1", local_port), timeout=0.2) as probe_sock:
                        probe_sock.settimeout(0.2)
                        settle_deadline = time.time() + max(0.1, min(3.0, settle_seconds))
                        while time.time() < settle_deadline:
                            if process.poll() is not None:
                                output = stop_and_collect()
                                return False, None, failure_from_output(output) or f"wstunnel_exit_{process.returncode}"
                            time.sleep(0.05)
                        output = stop_and_collect()
                        failure = failure_from_output(output)
                        if failure != "":
                            return False, None, failure
                        return True, round((time.time() - started) * 1000, 1), None
                except Exception as exc:  # noqa: BLE001
                    last_error = str(exc)
                    time.sleep(0.05)
            output = stop_and_collect()
            return False, None, failure_from_output(output) or last_error
        finally:
            if process.poll() is None:
                stop_and_collect()

    def expand_cf_candidates(self, cidrs: list[str], max_candidates: int) -> list[str]:
        candidate_iters: list[Any] = []
        for raw in cidrs:
            value = str(raw or "").strip()
            if value == "":
                continue
            try:
                network = ipaddress.ip_network(value, strict=False)
            except ValueError:
                continue
            if network.version != 4:
                continue
            if network.prefixlen == 32:
                candidate_iters.append(iter([str(network.network_address)]))
            else:
                candidate_iters.append((str(host) for host in network.hosts()))

        candidates: list[str] = []
        seen: set[str] = set()
        active_iters = candidate_iters
        while active_iters and len(candidates) < max_candidates:
            next_iters: list[Any] = []
            progressed = False
            for iterator in active_iters:
                try:
                    ip = next(iterator)
                except StopIteration:
                    continue
                progressed = True
                if ip not in seen:
                    seen.add(ip)
                    candidates.append(ip)
                    if len(candidates) >= max_candidates:
                        break
                next_iters.append(iterator)
            if not progressed:
                break
            active_iters = next_iters
        return candidates[:max_candidates]

    def probe_cf_candidates(self, candidates: list[str], cf_mode: dict[str, Any]) -> list[dict[str, Any]]:
        port = int(cf_mode.get("dial_port", 443) or 443)
        sni_host = str(cf_mode.get("sni_host", "") or "")
        path_prefix = str(cf_mode.get("path_prefix", "") or "")
        probe_target = str(cf_mode.get("probe_target", "") or "")
        strict_probe_target = bool(probe_target) and not bool(cf_mode.get("allow_probe_target_failures", False))
        default_samples = 3 if strict_probe_target else (1 if probe_target != "" else 2)
        sample_count = max(1, min(5, int(cf_mode.get("probe_samples", default_samples) or default_samples)))
        min_success_samples = max(1, min(sample_count, int(cf_mode.get("min_success_samples", default_samples if strict_probe_target else 1) or (default_samples if strict_probe_target else 1))))
        results: list[dict[str, Any]] = []

        for ip in candidates:
            latencies: list[float] = []
            failures = 0
            last_error = ""
            for _ in range(sample_count):
                connect_latency_ms = self.tcp_ping(ip, port) if probe_target != "" else None
                ok, latency_ms, error = self.probe_cf_websocket_path(ip, port, sni_host, path_prefix, probe_target)
                if ok:
                    effective_latency = connect_latency_ms if connect_latency_ms is not None else latency_ms
                    if effective_latency is not None:
                        latencies.append(effective_latency)
                    else:
                        failures += 1
                        last_error = "latency_unavailable"
                else:
                    failures += 1
                    last_error = error or "probe_failed"
                    if strict_probe_target:
                        # A CF edge that intermittently returns 400/520 or closes the
                        # routed websocket path can still look excellent by TCP
                        # connect time.  For bond/mux backends, one bad path sample is
                        # enough to cause invalid frame/hello failures, so reject it
                        # for this selection round and try the next edge.
                        break
            if latencies and len(latencies) >= min_success_samples and not (strict_probe_target and failures > 0):
                avg_latency = round(sum(latencies) / len(latencies), 1)
                jitter = round(max(latencies) - min(latencies), 1) if len(latencies) > 1 else 0.0
                results.append(
                    {
                        "ip": ip,
                        "ok": True,
                        "latency_ms": avg_latency,
                        "jitter_ms": jitter,
                        "failures": failures,
                        "error": last_error,
                    }
                )
            else:
                results.append(
                    {
                        "ip": ip,
                        "ok": False,
                        "latency_ms": 999999.0,
                        "jitter_ms": 999999.0,
                        "failures": failures,
                        "error": last_error or "probe_failed",
                    }
                )

        results.sort(key=lambda item: (not item["ok"], item["latency_ms"], item["jitter_ms"], item["failures"], item["ip"]))
        return results

    @staticmethod
    def candidate_on_cooldown(candidate_stats: dict[str, Any], ip: str, now: float) -> bool:
        item = candidate_stats.get(ip, {}) if isinstance(candidate_stats.get(ip), dict) else {}
        return float(item.get("cooldown_until", 0) or 0) > now

    def select_cf_location_ip(self, cf_mode: dict[str, Any]) -> dict[str, Any]:
        group_id = int(cf_mode.get("location_group_id", 0) or 0)
        state = self.load_cf_location_state()
        cache_key = str(group_id)
        cached = state.get(cache_key, {}) if isinstance(state.get(cache_key), dict) else {}
        now = time.time()
        ttl = max(30, int(cf_mode.get("cache_ttl_seconds", 120) or 120))
        validate_current = bool(cf_mode.get("validate_current", False))
        probe_target = str(cf_mode.get("probe_target", "") or "").strip()
        port = int(cf_mode.get("dial_port", 443) or 443)
        sni_host = str(cf_mode.get("sni_host", "") or "")
        path_prefix = str(cf_mode.get("path_prefix", "") or "")
        cached_selected_ip = str(cached.get("selected_ip", "") or "").strip()
        if cached and (now - float(cached.get("last_probe_at", 0) or 0)) < ttl and cached_selected_ip != "":
            if probe_target != "":
                ok, latency_ms, error = self.probe_cf_websocket_path(cached_selected_ip, port, sni_host, path_prefix, probe_target)
                if ok:
                    if latency_ms is not None:
                        cached["last_latency_ms"] = latency_ms
                    cached["last_probe_at"] = now
                    state[cache_key] = cached
                    self.save_cf_location_state(state)
                    return cached
                candidate_stats = cached.get("candidate_stats", {}) if isinstance(cached.get("candidate_stats"), dict) else {}
                stats = candidate_stats.setdefault(cached_selected_ip, {})
                stats["last_probe_at"] = now
                stats["consecutive_failures"] = int(stats.get("consecutive_failures", 0) or 0) + 1
                stats["cooldown_until"] = now + max(60, ttl)
                stats["last_error"] = error or "probe_failed"
                cached["candidate_stats"] = candidate_stats
                cached["last_probe_at"] = 0.0
                cached["consecutive_failures"] = int(cached.get("consecutive_failures", 0) or 0) + 1
                cached["cooldown_until"] = stats["cooldown_until"]
                state[cache_key] = cached
                self.save_cf_location_state(state)
            elif validate_current:
                ok, latency_ms, error = self.probe_cf_websocket_path(cached_selected_ip, port, sni_host, path_prefix, probe_target)
                if ok:
                    if latency_ms is not None:
                        cached["last_latency_ms"] = latency_ms
                    cached["last_probe_at"] = now
                    state[cache_key] = cached
                    self.save_cf_location_state(state)
                    return cached
                candidate_stats = cached.get("candidate_stats", {}) if isinstance(cached.get("candidate_stats"), dict) else {}
                stats = candidate_stats.setdefault(cached_selected_ip, {})
                stats["last_probe_at"] = now
                stats["consecutive_failures"] = int(stats.get("consecutive_failures", 0) or 0) + 1
                stats["cooldown_until"] = now + max(60, ttl)
                stats["last_error"] = error or "probe_failed"
                cached["candidate_stats"] = candidate_stats
                cached["last_probe_at"] = 0.0
                cached["consecutive_failures"] = int(cached.get("consecutive_failures", 0) or 0) + 1
                cached["cooldown_until"] = stats["cooldown_until"]
                state[cache_key] = cached
                self.save_cf_location_state(state)
            else:
                return cached

        candidate_limit = max(1, min(32, int(cf_mode.get("max_candidates", 8) or 8)))
        improvement_ms = max(0, int(cf_mode.get("switch_improvement_ms", 15) or 15))
        cooldown_seconds = max(60, ttl)
        candidate_stats = cached.get("candidate_stats", {}) if isinstance(cached.get("candidate_stats"), dict) else {}

        candidates = self.expand_cf_candidates(list(cf_mode.get("cidrs", [])), candidate_limit)
        filtered = [ip for ip in candidates if not self.candidate_on_cooldown(candidate_stats, ip, now)]
        filtered = filtered[:candidate_limit]
        results = self.probe_cf_candidates(filtered, cf_mode)

        successes = [item for item in results if item["ok"]]
        if not successes:
            if cached and str(cached.get("selected_ip", "")) != "":
                cached["last_probe_at"] = now
                state[cache_key] = cached
                self.save_cf_location_state(state)
                return cached
            raise RuntimeError("no reachable CF candidates")

        for item in results:
            stats = candidate_stats.setdefault(item["ip"], {})
            if item["ok"]:
                stats["last_latency_ms"] = item["latency_ms"]
                stats["last_jitter_ms"] = item["jitter_ms"]
                stats["last_probe_at"] = now
                stats["consecutive_failures"] = 0
                stats["cooldown_until"] = 0
            else:
                stats["last_probe_at"] = now
                stats["consecutive_failures"] = int(stats.get("consecutive_failures", 0) or 0) + 1
                stats["cooldown_until"] = now + cooldown_seconds
                stats["last_error"] = item["error"]

        best = successes[0]
        selected = best
        current_ip = str(cached.get("selected_ip", "") or "")
        current_success = next((item for item in successes if item["ip"] == current_ip), None)
        policy = cf_mode.get("forward_chain_latency_policy", {}) if isinstance(cf_mode.get("forward_chain_latency_policy", {}), dict) else {}
        desired_latency = policy.get("desired_latency_ms")
        desired_latency_ms = float(desired_latency) if desired_latency not in (None, "") else None
        if current_success is not None and current_ip != str(best["ip"]):
            current_latency = float(current_success["latency_ms"] or 0)
            improvement = current_latency - float(best["latency_ms"] or 0)
            if desired_latency_ms is not None and current_latency <= desired_latency_ms:
                selected = current_success
            elif improvement < improvement_ms:
                selected = current_success

        state[cache_key] = {
            "location_group_id": group_id,
            "selected_ip": selected["ip"],
            "last_latency_ms": selected["latency_ms"],
            "last_jitter_ms": selected["jitter_ms"],
            "last_probe_at": now,
            "backup_ips": [item["ip"] for item in successes if item["ip"] != selected["ip"]][:3],
            "consecutive_failures": int(candidate_stats.get(selected["ip"], {}).get("consecutive_failures", 0) or 0),
            "cooldown_until": float(candidate_stats.get(selected["ip"], {}).get("cooldown_until", 0) or 0),
            "candidate_stats": candidate_stats,
        }
        self.save_cf_location_state(state)
        return state[cache_key]

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

        heartbeat_interval = max(5, int(self.config.get("heartbeat_interval_seconds", 30)))
        usage_interval = max(5, int(self.config.get("usage_interval_seconds", 60)))
        poll_interval = max(1, int(self.config.get("poll_interval_seconds", 5)))
        next_heartbeat = 0.0
        try:
            self.send_heartbeat()
            next_heartbeat = time.time() + heartbeat_interval
        except Exception as exc:  # noqa: BLE001
            logging.warning("startup heartbeat failed: %s", exc)

        try:
            self.reconcile_runtime()
        except Exception:  # noqa: BLE001
            logging.exception("runtime reconciliation failed")

        try:
            self.cleanup_orphan_mptcp_underlays()
        except Exception:  # noqa: BLE001
            logging.exception("orphan mptcp underlay cleanup failed")

        next_usage = 0.0
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

    def build_donation_self_check_payload(self) -> list[dict[str, Any]]:
        # donation_self_check_loop
        checks: list[dict[str, Any]] = []
        for metadata in self.load_all_service_metadata():
            if not isinstance(metadata, dict):
                continue
            policy = metadata.get("donation_policy")
            if not isinstance(policy, dict):
                continue
            combination_id = int(policy.get("forward_combination_id", 0) or 0)
            desired_latency = int(policy.get("desired_latency_ms", 0) or 0)
            if combination_id <= 0 or desired_latency <= 0:
                continue
            latency_ms = self.probe_donation_chain_latency_ms(metadata)
            open_ports = self.probe_donation_open_ports(metadata)
            listen_port = int(metadata.get("listen_port", 0) or 0)
            item = {
                "forward_combination_id": combination_id,
                "forward_id": int(metadata.get("forward_id", 0) or 0),
                "endpoint_id": int(metadata.get("endpoint_id", 0) or 0),
                "donor_client_id": int(policy.get("donor_client_id", 0) or 0),
                "desktop_client_session_id": int(policy.get("desktop_client_session_id", 0) or 0),
                "device_id": str(policy.get("device_id", "") or ""),
                "measured_chain_latency_ms": latency_ms,
                "measure_window_seconds": int(policy.get("donation_qualify_window_seconds", 60) or 60),
                "open_ports": open_ports,
                "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }
            public_host = str(self.config.get("public_ip", "") or metadata.get("public_host", "") or "").strip()
            if public_host != "":
                item["public_host"] = public_host
            if listen_port > 0:
                item["public_port"] = listen_port
            checks.append(item)
        return checks

    def probe_donation_chain_latency_ms(self, metadata: dict[str, Any]) -> float | None:
        cf_mode = metadata.get("cf_mode") if isinstance(metadata.get("cf_mode"), dict) else {}
        if isinstance(cf_mode, dict) and cf_mode:
            host = str(cf_mode.get("dial_connect_ip", "") or cf_mode.get("dial_host", "") or "").strip()
            port = int(cf_mode.get("dial_port", 0) or 0)
            sni = str(cf_mode.get("sni_host", "") or "").strip()
            path_prefix = str(cf_mode.get("path_prefix", "") or metadata.get("path_prefix", "") or "").strip()
            target = str(cf_mode.get("probe_target", "") or "").strip()
            if host != "" and port > 0:
                ok, latency_ms, _error = self.probe_cf_websocket_path(host, port, sni, path_prefix, target, timeout=1.0)
                if ok and latency_ms is not None:
                    return latency_ms
                ping_ms = self.tcp_ping(host, port, timeout=1.0)
                if ping_ms is not None:
                    return ping_ms
        target = str(metadata.get("target", "") or "").strip()
        host = ""
        port = 0
        if ":" in target:
            host_part, port_part = target.rsplit(":", 1)
            host = host_part.strip().strip("[]")
            try:
                port = int(port_part)
            except ValueError:
                port = 0
        if host != "" and port > 0:
            return self.tcp_ping(host, port, timeout=1.0)
        return None

    def probe_donation_open_ports(self, metadata: dict[str, Any]) -> list[dict[str, Any]]:
        listen_port = int(metadata.get("listen_port", 0) or 0)
        transport_mode = str(metadata.get("transport_mode", "tcp") or "tcp").lower()
        results: list[dict[str, Any]] = []
        if listen_port <= 0:
            return results
        tcp_ok = self.tcp_ping("127.0.0.1", listen_port, timeout=0.5) is not None
        results.append({"protocol": "tcp", "port": listen_port, "ok": tcp_ok})
        if "udp" in transport_mode:
            results.append({"protocol": "udp", "port": listen_port, "ok": True})
        return results

    def build_heartbeat_payload(self) -> dict[str, Any]:
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
        payload["cf_location_stats"] = list(self.load_cf_location_state().values())
        payload["forward_chain_remediation"] = [
            {
                "chain_key": str(chain_key),
                "recommended_action": str(entry.get("recommended_action", "") or ""),
                "action": str(entry.get("action", "") or ""),
                "selected_candidate": str(entry.get("selected_candidate", "") or ""),
                "best_candidate": str(entry.get("best_candidate", "") or ""),
                "best_latency_ms": entry.get("best_latency_ms"),
                "spread_ms": entry.get("spread_ms"),
                "acceptable": bool(entry.get("acceptable", True)),
            }
            for chain_key, entry in self.load_latency_policy_state().items()
            if isinstance(entry, dict)
        ]
        donation_self_check = self.build_donation_self_check_payload()
        if donation_self_check:
            payload["donation_self_check"] = donation_self_check
        return payload

    def send_heartbeat(self) -> None:
        payload = self.build_heartbeat_payload()
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
        self.cleanup_orphan_wstunnel_svc_units()

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
                ingress_protocol = str(metadata.get("ingress_protocol", "wss_path_mux") or "wss_path_mux").strip() or "wss_path_mux"
                backend_protocol = str(metadata.get("backend_protocol", "") or "").strip()
                if backend_protocol == "":
                    backend_protocol = "tcp_relay" if ingress_protocol == "plain_tcp_mux" else "wss_backend"
                    metadata["backend_protocol"] = backend_protocol
                    metadata_changed = True
                backend_mode = str(metadata.get("backend_mode", "current_mode") or "current_mode").strip()
                transport_mode = str(metadata.get("transport_mode", "tcp+udp") or "tcp+udp")
                cf_mode = metadata.get("cf_mode", {})
                if not isinstance(cf_mode, dict):
                    cf_mode = {}
                sidecar_uplink_selector = metadata.get("sidecar_uplink_selector", {})
                if isinstance(sidecar_uplink_selector, dict) and sidecar_uplink_selector.get("enabled"):
                    self.ensure_sidecar_uplink_selector(service_name, sidecar_uplink_selector)
                else:
                    self.remove_sidecar_uplink_selector(service_name)
                if target == "" or public_port <= 0 or endpoint_id <= 0:
                    logging.warning("skipping invalid runtime metadata for %s", service_name)
                    continue

                if self.backend_port_needs_reallocation(service_name, backend_port):
                    old_backend_port = backend_port
                    backend_port = self.allocate_backend_port(service_name, self.listener_ports())
                    metadata["backend_port"] = backend_port
                    metadata_changed = True
                    if old_backend_port > 0:
                        logging.warning(
                            "service %s backend_port changed from %s to %s during reconciliation",
                            service_name,
                            old_backend_port,
                            backend_port,
                        )

                if self.is_udp_uot_shadow_metadata(metadata) or backend_protocol == "tcp_relay":
                    unit_body = self.render_plain_tcp_backend_unit(service_name, backend_port, target)
                    expected_exec = "leikwan-ingress backend"
                elif backend_mode == "cf_mode" and cf_mode != {}:
                    selected_ip = str(metadata.get("selected_cf_ip", "") or "").strip()
                    try:
                        selection_mode = dict(cf_mode)
                        selection_mode["probe_target"] = target
                        selection_mode["validate_current"] = True
                        selection = self.select_cf_location_ip(selection_mode)
                        refreshed_ip = str(selection["selected_ip"])
                        if refreshed_ip != "" and refreshed_ip != selected_ip:
                            selected_ip = refreshed_ip
                            metadata["selected_cf_ip"] = selected_ip
                            metadata_changed = True
                    except Exception:  # noqa: BLE001
                        if selected_ip == "":
                            logging.exception("failed to refresh cf selected ip for %s during reconciliation", service_name)
                            selected_ip = str(cf_mode.get("dial_host", "") or "").strip()
                    unit_body = self.render_cf_wss_backend_unit(
                        service_name=service_name,
                        backend_port=backend_port,
                        target=target,
                        selected_ip=selected_ip,
                        dial_host=str(cf_mode.get("dial_host", "") or ""),
                        remote_port=int(cf_mode.get("dial_port", 443) or 443),
                        sni_host=str(cf_mode.get("sni_host", "") or ""),
                        path_prefix=str(cf_mode.get("path_prefix", path_prefix) or path_prefix),
                        transport_mode="tcp" if "udp" in transport_mode.lower() and "tcp" in transport_mode.lower() else transport_mode,
                    )
                    expected_exec = "wstunnel client"
                else:
                    unit_body = self.render_backend_unit(service_name, backend_port, target, path_prefix)
                    expected_exec = "wstunnel server"
                unit_changed = self.write_text_if_changed(self.service_unit_path(service_name), unit_body)
                self.ensure_backend_service(service_name, unit_changed, expected_exec_substring=expected_exec)

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
        # BUG-1: only run the authoritative orphan sweep when the static-uplink
        # section was actually present in this successfully-fetched payload. An
        # absent section -> NO-OP sweep (preserve live lanes).
        sidecar_section_present = isinstance(management, dict) and "sidecar_static_uplinks" in management
        self.cleanup_orphan_sidecar_uplinks(section_present=sidecar_section_present)
        self.cleanup_orphan_mptcp_underlays()

    def apply_leigod_session(self, session_json: Any) -> None:
        """Write the per-slave LeiGod account session, pushed by the panel via management state.

        The LeiGod login needs mobile + Tencent captcha + SMS, so the agent can never mint a session
        itself -- the panel delivers the account-bound blob here. Written to <leigod_root>/session.json
        mode 0600 (the leigod@<line> accelerator reads it). Never logged.
        """
        if not isinstance(session_json, str) or session_json.strip() == "":
            return
        leigod_root = Path(str(self.config.get("leigod_root", "/opt/leigod") or "/opt/leigod"))
        try:
            leigod_root.mkdir(parents=True, exist_ok=True)
            session_path = leigod_root / "session.json"
            changed = self.write_text_if_changed(session_path, session_json)
            try:
                os.chmod(session_path, 0o600)
            except OSError:
                pass
            if changed:
                logging.info("leigod session.json updated (%d bytes)", len(session_json))
        except OSError as exc:  # noqa: BLE001
            logging.warning("failed to write leigod session.json: %s", exc)

    def apply_management_state(self, management: Any, schedule_updates: bool = True) -> None:
        if not isinstance(management, dict):
            return
        self.apply_config_patch(management.get("agent_config_json"))
        self.apply_leigod_session(management.get("leigod_session_json"))
        # BUG-1: only treat the static-uplink feed as authoritative when the
        # section was actually present in this management payload. A payload
        # that simply omitted the key must NOT be read as "tear everything
        # down" -- that would wipe live lanes whenever the panel/heartbeat
        # response happens to leave the section out.
        sidecar_section_present = "sidecar_static_uplinks" in management
        self.apply_static_sidecar_uplinks(
            management.get("sidecar_static_uplinks", []),
            section_present=sidecar_section_present,
        )
        explicit_request_token = str(management.get("agent_update_request_token", "") or "")
        auto_request_token = self.auto_update_request_token(management)
        if bool(self.config.get("disable_auto_update", False)):
            explicit_request_token = ""
            auto_request_token = ""

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

            # BUG-5: failure_count is a per-token circuit-breaker counter. When a
            # brand-new update_request_token arrives, the prior token's failures
            # must NOT carry over -- otherwise a previously-tripped breaker would
            # immediately suppress a healthy new release. Reset to 0 before we
            # start tracking the new token.
            tracked_request_token = str(update_state.get("request_token", "") or "")
            scheduled_update: dict[str, Any] = {
                "request_token": update_request_token,
                "status": "scheduled",
                "last_attempt_at": now,
                "last_error": "",
            }
            if tracked_request_token != update_request_token:
                scheduled_update["failure_count"] = 0
            update_state.update(scheduled_update)
            self.save_update_state(update_state)
            try:
                self.request_self_update(update_request_token)
            except Exception as exc:  # noqa: BLE001
                try:
                    failure_count = int(update_state.get("failure_count", 0) or 0)
                except (TypeError, ValueError):
                    failure_count = 0
                update_state["status"] = "error"
                update_state["last_error"] = str(exc)
                update_state["last_failed_at"] = now
                update_state["failure_count"] = failure_count + 1
                self.save_update_state(update_state)
                raise
            runtime_state["last_agent_update_request_token"] = update_request_token
            self.save_runtime_state(runtime_state)
            return

    def static_sidecar_uplink_state_path(self) -> Path:
        return self.state_dir / "sidecar_static_uplinks.json"

    def sidecar_uplink_selector_state_path(self, service_name: str) -> Path:
        return self.state_dir / "sidecar-uplink-selectors" / f"{safe_service_basename(service_name)}.json"

    def load_sidecar_uplink_selector_state(self, service_name: str) -> dict[str, Any]:
        path = self.sidecar_uplink_selector_state_path(service_name)
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_sidecar_uplink_selector_state(self, service_name: str, payload: dict[str, Any]) -> None:
        self.write_json(self.sidecar_uplink_selector_state_path(service_name), payload)

    def node_health_events_path(self) -> Path:
        return self.state_dir / "node_health_events.json"

    def record_health_event(self, kind: str, detail: dict[str, Any] | None = None) -> dict[str, Any]:
        """Record a node-health/alarm event so sustained degradation is visible
        outside per-selector state JSON. Best-effort and never raises -- a
        health channel must not be able to crash the reconcile loop.
        """
        event: dict[str, Any] = {
            "kind": str(kind or ""),
            "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(time.time())),
            "epoch": int(time.time()),
        }
        if isinstance(detail, dict):
            for key, value in detail.items():
                event[str(key)] = value
        try:
            path = self.node_health_events_path()
            existing: list[Any] = []
            if path.exists():
                try:
                    loaded = json.loads(path.read_text(encoding="utf-8"))
                    if isinstance(loaded, dict):
                        loaded = loaded.get("events", [])
                    if isinstance(loaded, list):
                        existing = loaded
                except (OSError, json.JSONDecodeError):
                    existing = []
            existing.append(event)
            # Keep the channel bounded so it never grows without limit.
            existing = existing[-200:]
            self.write_json(path, {"events": existing})
        except OSError:
            pass
        return event

    def load_static_sidecar_uplink_state(self) -> dict[str, Any]:
        path = self.static_sidecar_uplink_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_static_sidecar_uplink_state(self, payload: dict[str, Any]) -> None:
        self.write_json(self.static_sidecar_uplink_state_path(), payload)

    def apply_static_sidecar_uplinks(self, raw_uplinks: Any, section_present: bool = True) -> None:
        # BUG-1: when the uplink section was absent this cycle (or the fetch
        # failed and a defaulted-empty value was passed), do NOT reconcile and
        # do NOT overwrite the saved authoritative state with {} -- that would
        # tear down live lanes merely because the section was omitted. An
        # explicitly-present empty list, by contrast, means "teardown intended"
        # and is handled as authoritative below.
        if not section_present:
            return
        if not isinstance(raw_uplinks, list):
            return
        active: set[str] = set()
        next_state: dict[str, Any] = {"interfaces": {}}
        for raw in raw_uplinks:
            if not isinstance(raw, dict):
                continue
            uplink = dict(raw)
            interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
            if interface_name == "" or not uplink.get("enabled", False):
                continue
            active.add(interface_name)
            self.apply_sidecar_uplink(interface_name, uplink)
            self.watch_static_sidecar_uplink(uplink)
            next_state["interfaces"][interface_name] = {
                "interface_name": interface_name,
                "forward_combination_id": int(uplink.get("forward_combination_id", 0) or 0),
                "sidecar_point_id": int(uplink.get("sidecar_point_id", 0) or 0),
                "node_id": int(uplink.get("node_id", 0) or 0),
                "lane": str(uplink.get("lane", "") or ""),
            }
        previous = self.load_static_sidecar_uplink_state()
        previous_interfaces = previous.get("interfaces", {}) if isinstance(previous.get("interfaces", {}), dict) else {}
        for iface, info in list(previous_interfaces.items()):
            interface_name = str((info if isinstance(info, dict) else {}).get("interface_name", iface) or "").strip()[:15]
            if interface_name != "" and interface_name not in active:
                self.remove_sidecar_uplink(interface_name, {"interface_name": interface_name})
        self.prune_sidecar_uplink_selectors(active)
        if active or previous_interfaces:
            self.save_static_sidecar_uplink_state(next_state)

    def prune_sidecar_uplink_selectors(self, active_interfaces: set[str]) -> None:
        selector_dir = self.state_dir / "sidecar-uplink-selectors"
        if not selector_dir.exists():
            return
        for config_path in selector_dir.glob("*.config.json"):
            service_name = config_path.name.removesuffix(".config.json")
            try:
                selector = json.loads(config_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            if not isinstance(selector, dict):
                continue
            candidates = selector.get("candidates", [])
            if not isinstance(candidates, list):
                candidates = []
            pruned = [
                candidate
                for candidate in candidates
                if isinstance(candidate, dict)
                and str(candidate.get("interface_name", "") or candidate.get("ping_interface", "") or "").strip()[:15] in active_interfaces
            ]
            if len(pruned) == len(candidates):
                continue
            if not pruned:
                logging.warning("removing sidecar selector %s because all candidate interfaces are stale", service_name)
                self.remove_sidecar_uplink_selector(service_name)
                continue
            updated = dict(selector)
            updated["candidates"] = pruned
            logging.warning("pruned stale sidecar selector candidates for %s: %s -> %s", service_name, len(candidates), len(pruned))
            self.ensure_sidecar_uplink_selector(service_name, updated)

    def watch_static_sidecar_uplink(self, uplink: dict[str, Any]) -> None:
        watchdog = uplink.get("watchdog", {})
        if not isinstance(watchdog, dict) or not bool(watchdog.get("enabled", False)):
            return
        interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
        if interface_name == "":
            return
        stale_seconds = max(10, int(watchdog.get("handshake_stale_seconds", 90) or 90))
        cooldown = max(10, int(watchdog.get("restart_cooldown_seconds", 30) or 30))
        now = time.time()
        result = run_command(f"wg show {interface_name} latest-handshakes", check=False)
        fresh = False
        try:
            returncode = int(getattr(result, "returncode", 1))
        except (TypeError, ValueError):
            returncode = 1
        saw_handshake_state = returncode != 0
        if returncode == 0:
            for line in str(getattr(result, "stdout", "") or "").splitlines():
                parts = line.split()
                if len(parts) >= 2:
                    try:
                        timestamp = float(parts[-1])
                    except ValueError:
                        continue
                    if timestamp > 0:
                        saw_handshake_state = True
                        if now - timestamp <= stale_seconds:
                            fresh = True
                            break
        if fresh:
            return
        if not saw_handshake_state:
            return
        if now - self._static_uplink_restart_at.get(interface_name, 0.0) < cooldown:
            return
        # C.7a: consult the shared restart ledger circuit breaker before
        # restarting. If too many restarts have occurred within the window the
        # breaker is open -- suppress the restart and surface a health event.
        if not restart_circuit_breaker_allow(interface_name, "static-uplink-watch", now=now):
            self.record_health_event(
                "restart_breaker_open",
                {"interface_name": interface_name, "source": "static-uplink-watch"},
            )
            return
        self._static_uplink_restart_at[interface_name] = now
        if shutil.which("systemctl"):
            run_command(f"systemctl restart wg-quick@{interface_name}.service", check=False)

    @staticmethod
    def parse_ping_summary(output: str) -> dict[str, Any]:
        loss = 100.0
        loss_match = re.search(r"(\d+(?:\.\d+)?)%\s*packet loss", str(output or ""))
        if loss_match:
            loss = float(loss_match.group(1))
        latency_ms: float | None = None
        mdev_ms: float | None = None
        match = re.search(r"=\s*([0-9.]+)/([0-9.]+)/([0-9.]+)/([0-9.]+)", str(output or ""))
        if match:
            latency_ms = float(match.group(2))
            mdev_ms = float(match.group(4))
        return {
            "loss_percent": loss,
            "latency_ms": latency_ms,
            "mdev_ms": mdev_ms,
        }

    def probe_sidecar_selector_candidate(self, candidate: dict[str, Any], selector: dict[str, Any]) -> dict[str, Any]:
        lane = str(candidate.get("lane", "") or "").strip()
        interface_name = str(candidate.get("ping_interface", "") or candidate.get("interface_name", "") or "").strip()[:15]
        ping_address = str(candidate.get("ping_address", "") or candidate.get("peer_address", "") or candidate.get("sidecar_address", "") or "").strip()
        sidecar_address = str(candidate.get("sidecar_address", "") or ping_address).strip()
        sidecar_port = int(candidate.get("sidecar_port", 0) or selector.get("sidecar_service_port", 0) or 0)
        if not self.sidecar_selector_candidate_interface_ready(interface_name):
            return {
                "lane": lane,
                "forward_combination_id": int(candidate.get("forward_combination_id", 0) or 0),
                "interface_name": interface_name,
                "ping_address": ping_address,
                "sidecar_address": sidecar_address,
                "sidecar_port": sidecar_port,
                "healthy": False,
                "latency_ms": None,
                "mdev_ms": None,
                "loss_percent": 100.0,
                "score": 999999.0,
                "probed_at": time.time(),
                "error": "interface_missing",
                "restartable": False,
            }
        ping_count = max(1, min(10, int(selector.get("ping_count", 3) or 3)))
        ping_timeout = max(1, min(5, int(selector.get("ping_timeout_seconds", 1) or 1)))
        result = run_command(
            f"ping -c {ping_count} -W {ping_timeout} -I {shell_arg(interface_name)} {shell_arg(ping_address)}",
            check=False,
        )
        parsed = self.parse_ping_summary(str(getattr(result, "stdout", "") or "") + "\n" + str(getattr(result, "stderr", "") or ""))
        healthy = (
            int(getattr(result, "returncode", 0) or 0) == 0
            and parsed["latency_ms"] is not None
            and float(parsed["loss_percent"]) <= float(selector.get("max_loss_percent", 0) or 0)
        )
        latency = parsed["latency_ms"]
        mdev = parsed["mdev_ms"]
        score = 999999.0
        if healthy and latency is not None:
            score = float(latency) + (float(mdev or 0.0) * 2.0)
        return {
            "lane": lane,
            "forward_combination_id": int(candidate.get("forward_combination_id", 0) or 0),
            "interface_name": interface_name,
            "ping_address": ping_address,
            "sidecar_address": sidecar_address,
            "sidecar_port": sidecar_port,
            "healthy": healthy,
            "latency_ms": latency,
            "mdev_ms": mdev,
            "loss_percent": float(parsed["loss_percent"]),
            "score": round(score, 3),
            "probed_at": time.time(),
            "restartable": True,
        }

    def sidecar_selector_candidate_interface_ready(self, interface_name: str) -> bool:
        interface_name = str(interface_name or "").strip()[:15]
        if interface_name == "":
            return False
        if not self.wireguard_interface_path(interface_name).exists():
            return False
        if not shutil.which("wg"):
            return False
        result = run_command("wg show interfaces", check=False)
        if int(getattr(result, "returncode", 0) or 0) != 0:
            return False
        interfaces = set(str(getattr(result, "stdout", "") or "").split())
        return interface_name in interfaces

    def restart_sidecar_selector_lane(self, lane_state: dict[str, Any], now: float | None = None) -> bool:
        interface_name = str(lane_state.get("interface_name", "") or "").strip()[:15]
        if interface_name == "":
            return False
        # C.7a: consult the shared restart ledger circuit breaker first. If the
        # breaker is open, suppress the restart and record an alarm/health event.
        if not restart_circuit_breaker_allow(interface_name, "selector-lane", now=now):
            self.record_health_event(
                "restart_breaker_open",
                {"interface_name": interface_name, "source": "selector-lane"},
            )
            return False
        if shutil.which("systemctl"):
            run_command(f"systemctl restart leikwan-wgcf-{interface_name}.service", check=False)
            run_command(f"systemctl restart wg-quick@{interface_name}.service", check=False)
        return True

    def evaluate_sidecar_uplink_selector(self, selector: dict[str, Any], previous_state: dict[str, Any] | None = None) -> dict[str, Any]:
        previous = previous_state if isinstance(previous_state, dict) else {}
        candidates = selector.get("candidates", [])
        if not isinstance(candidates, list):
            candidates = []
        samples = [
            self.probe_sidecar_selector_candidate(candidate, selector)
            for candidate in candidates
            if isinstance(candidate, dict)
        ]
        healthy = [sample for sample in samples if bool(sample.get("healthy", False))]
        healthy.sort(key=lambda item: (float(item.get("score", 999999.0) or 999999.0), float(item.get("latency_ms", 999999.0) or 999999.0), str(item.get("lane", ""))))
        active_lane = str(previous.get("active_lane", "") or "").strip()
        if active_lane == "" and samples:
            active_lane = str(samples[0].get("lane", "") or "").strip()
        active_sample = next((item for item in samples if str(item.get("lane", "") or "") == active_lane), None)
        best_sample = healthy[0] if healthy else None
        selected = active_sample if isinstance(active_sample, dict) else best_sample
        reason = "keep_active"
        # C.7c: a single-lane forward is monitor-only. The panel marks it with
        # single_lane_monitor=true (and it is also implied by a single
        # candidate). In monitor mode we still PROBE and record latency/loss,
        # but we never switch lanes or issue a restart -- there is nothing to
        # fail over to, so flapping a single lane only hurts.
        monitor_only = bool(selector.get("single_lane_monitor", False)) or len(samples) <= 1
        switch_improvement = float(selector.get("switch_improvement_ms", 3) or 3)
        max_active_avg = float(selector.get("max_active_avg_ms", 0) or 0)
        active_bad = (
            active_sample is None
            or not bool(active_sample.get("healthy", False))
            or (max_active_avg > 0 and active_sample.get("latency_ms") is not None and float(active_sample.get("latency_ms", 0) or 0) > max_active_avg)
        )
        if not monitor_only and best_sample is not None:
            if active_sample is None or not bool(active_sample.get("healthy", False)):
                selected = best_sample
                reason = "active_unhealthy"
            elif str(best_sample.get("lane", "") or "") != active_lane:
                active_score = float(active_sample.get("score", 999999.0) or 999999.0)
                best_score = float(best_sample.get("score", 999999.0) or 999999.0)
                if active_bad or (active_score - best_score) >= switch_improvement:
                    selected = best_sample
                    reason = "latency_switch"
        if best_sample is None and samples:
            selected = active_sample if isinstance(active_sample, dict) else samples[0]
            reason = "all_unhealthy"

        now = time.time()
        last_restart_at = previous.get("last_restart_at", {})
        if not isinstance(last_restart_at, dict):
            last_restart_at = {}
        restart_cooldown = max(10, int(selector.get("restart_cooldown_seconds", 60) or 60))
        degraded_lanes = []
        for sample in samples:
            lane = str(sample.get("lane", "") or "").strip()
            if lane == "":
                continue
            degraded = not bool(sample.get("healthy", False))
            if not bool(sample.get("restartable", True)):
                continue
            if sample.get("latency_ms") is not None and max_active_avg > 0 and float(sample.get("latency_ms", 0) or 0) > max_active_avg:
                degraded = True
            if not degraded:
                continue
            # C.7c: monitor-only / single-lane forwards never restart.
            if monitor_only:
                continue
            if reason == "all_unhealthy" or len(samples) <= 1:
                continue
            if now - float(last_restart_at.get(lane, 0.0) or 0.0) < restart_cooldown:
                continue
            # C.7a: restart only if the shared breaker allows it. A suppressed
            # restart does not advance the cooldown or count as a restarted lane.
            if not self.restart_sidecar_selector_lane(sample, now=now):
                continue
            last_restart_at[lane] = now
            degraded_lanes.append(lane)

        selected = selected or {}
        active_target_host = str(selected.get("sidecar_address", "") or selected.get("ping_address", "") or "").strip()
        active_target_port = int(selected.get("sidecar_port", 0) or selector.get("sidecar_service_port", 0) or 0)
        state: dict[str, Any] = {
            "active_lane": str(selected.get("lane", "") or ""),
            "active_interface": str(selected.get("interface_name", "") or ""),
            # Forward Route racing: which basis (forward_combination) is currently live. Surfaced in the
            # heartbeat so the panel can persist forwards.active_combination_id for admin observability.
            "active_forward_combination_id": int(selected.get("forward_combination_id", 0) or 0),
            "active_target_host": active_target_host,
            "active_target_port": active_target_port,
            "switch_reason": reason,
            "samples": samples,
            "last_restart_at": last_restart_at,
            "restarted_lanes": degraded_lanes,
            "updated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(now)),
        }
        # C.7g: when every lane is unhealthy, the state write alone is easy to
        # miss. Log a WARN and stamp a health-event marker so sustained
        # all-unhealthy is visible outside the per-selector state JSON. We keep
        # distinguishing interface_missing from ping-failed in the per-lane
        # samples (probe_sidecar_selector_candidate sets error=interface_missing
        # only when the interface is absent).
        if reason == "all_unhealthy" and samples:
            service_name = str(selector.get("service_name", "") or "")
            interface_missing = sum(1 for s in samples if str(s.get("error", "") or "") == "interface_missing")
            ping_failed = sum(
                1 for s in samples
                if not bool(s.get("healthy", False)) and str(s.get("error", "") or "") != "interface_missing"
            )
            logging.warning(
                "sidecar selector %s all lanes unhealthy: %s lane(s), interface_missing=%s ping_failed=%s",
                service_name or "(unnamed)",
                len(samples),
                interface_missing,
                ping_failed,
            )
            health_event = {
                "kind": "all_unhealthy",
                "service_name": service_name,
                "lane_count": len(samples),
                "interface_missing": interface_missing,
                "ping_failed": ping_failed,
                "at": state["updated_at"],
            }
            state["health_event"] = health_event
            self.record_health_event("all_unhealthy", health_event)
        return state

    def render_sidecar_uplink_selector_unit(self, service_name: str, selector: dict[str, Any]) -> str:
        agent_entrypoint = shell_arg(str(Path(__file__).resolve()))
        config_path = shell_arg(str(self.config_path))
        selector_file = shell_arg(str(self.sidecar_uplink_selector_config_path(service_name)))
        return f"""[Unit]
Description=Leikwan Static Sidecar WG Lane Selector {service_name}
After=network.target

[Service]
# AgentVersion={AGENT_VERSION}
Type=simple
ExecStart=/usr/bin/python3 {agent_entrypoint} --config {config_path} sidecar-selector --service-name {shell_arg(service_name)} --selector-file {selector_file}
Restart=on-failure
RestartSec=5
StartLimitIntervalSec=300
StartLimitBurst=5
WorkingDirectory={self.state_dir}
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    def sidecar_uplink_selector_config_path(self, service_name: str) -> Path:
        return self.state_dir / "sidecar-uplink-selectors" / f"{safe_service_basename(service_name)}.config.json"

    def ensure_sidecar_uplink_selector(self, service_name: str, selector: dict[str, Any]) -> None:
        if not isinstance(selector, dict) or not bool(selector.get("enabled", False)):
            self.remove_sidecar_uplink_selector(service_name)
            return
        selector_port = int(selector.get("selector_port", 0) or 0)
        if selector_port <= 0:
            self.remove_sidecar_uplink_selector(service_name)
            return
        config_path = self.sidecar_uplink_selector_config_path(service_name)
        selector_config_body = json.dumps(selector, ensure_ascii=False, indent=2)
        config_changed = True
        if config_path.exists():
            try:
                config_changed = config_path.read_text(encoding="utf-8") != selector_config_body
            except OSError:
                config_changed = True
        self.write_json(config_path, selector)
        unit_path = self.service_unit_path(f"{service_name}-sidecar-selector")
        unit_changed = self.write_text_if_changed(unit_path, self.render_sidecar_uplink_selector_unit(service_name, selector))
        if shutil.which("systemctl"):
            if unit_changed:
                run_command("systemctl daemon-reload", check=False)
            run_command(f"systemctl enable {service_name}-sidecar-selector.service", check=False)
            if unit_changed or config_changed:
                run_command(f"systemctl restart {service_name}-sidecar-selector.service", check=False)
            else:
                run_command(f"systemctl start {service_name}-sidecar-selector.service", check=False)

    def remove_sidecar_uplink_selector(self, service_name: str) -> None:
        selector_service = f"{service_name}-sidecar-selector"
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {selector_service}.service", check=False)
            run_command(f"systemctl disable {selector_service}.service", check=False)
            run_command(f"systemctl reset-failed {selector_service}.service", check=False)
            run_command("systemctl daemon-reload", check=False)
        for path in (self.service_unit_path(selector_service), self.sidecar_uplink_selector_config_path(service_name), self.sidecar_uplink_selector_state_path(service_name)):
            if path.exists():
                path.unlink()

    def auto_update_request_token(self, management: Any) -> str:
        if not isinstance(management, dict):
            return ""
        if bool(self.config.get("disable_auto_update", False)):
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
            "artifact_base_url",
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
            "udp_uot_sidepath",
            "forward_chain_remediation",
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
        last_error = str(update_state.get("last_error", "") or "")
        try:
            failure_count = int(update_state.get("failure_count", 0) or 0)
        except (TypeError, ValueError):
            failure_count = 0
        max_failures = max(1, int(self.config.get("update_max_failures", 3) or 3))

        if tracked_request_token == request_token and tracked_status == "error" and last_error != "" and failure_count >= max_failures:
            logging.error(
                "slave self-update circuit breaker open for token=%s failures=%s/%s last_error=%s",
                request_token,
                failure_count,
                max_failures,
                last_error,
            )
            return False

        if tracked_status == "running" and last_attempt_at > 0 and (now - last_attempt_at) < UPDATE_STALE_SECONDS:
            return False

        if tracked_request_token != request_token:
            return True

        if last_attempt_at > 0 and (now - last_attempt_at) < UPDATE_RETRY_SECONDS:
            return False

        return True

    def reset_failed_update_units(self) -> None:
        if shutil.which("systemctl"):
            run_command("systemctl reset-failed 'leikwan-slave-update-*'", check=False)

    def request_self_update(self, request_token: str) -> None:
        helper_path = Path("/usr/local/bin/leikwan-slave-update")
        if not helper_path.exists():
            raise RuntimeError(f"update helper missing: {helper_path}")
        state_path = self.update_state_path()

        if shutil.which("systemd-run"):
            self.reset_failed_update_units()
            unit_name = f"leikwan-slave-update-{int(time.time())}-{request_token[:8]}"
            subprocess.run(
                [
                    "systemd-run",
                    "--quiet",
                    "--no-block",
                    "--collect",
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
        backend_mode = str(payload.get("backend_mode", "current_mode") or "current_mode").strip()
        cf_mode = payload.get("cf_mode", {})
        if not isinstance(cf_mode, dict):
            cf_mode = {}
        mptcp_underlay = payload.get("mptcp_underlay", {})
        if not isinstance(mptcp_underlay, dict):
            mptcp_underlay = {}
        sidecar_uplink = payload.get("sidecar_uplink", {})
        if not isinstance(sidecar_uplink, dict):
            sidecar_uplink = {}
        sidecar_uplink_selector = payload.get("sidecar_uplink_selector", {})
        if not isinstance(sidecar_uplink_selector, dict):
            sidecar_uplink_selector = {}
        if sidecar_uplink.get("enabled") and str(sidecar_uplink.get("relay_target", "") or "").strip() != "":
            target = str(sidecar_uplink.get("relay_target", "") or "").strip()
        if sidecar_uplink_selector.get("enabled") and int(sidecar_uplink_selector.get("selector_port", 0) or 0) > 0:
            target = f"127.0.0.1:{int(sidecar_uplink_selector.get('selector_port', 0) or 0)}"
        publish_bond_runtime = bool(payload.get("publish_bond_runtime", False)) or ingress_protocol == "plain_tcp_mux"
        metadata_path = self.metadata_path(service_name)

        self.cleanup_superseded_services_for_forward(int(task["forward_id"]), service_name)

        if mptcp_underlay and mptcp_underlay.get("enabled") and not publish_bond_runtime:
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
                "sidecar_uplink": sidecar_uplink,
                "sidecar_uplink_selector": sidecar_uplink_selector,
                "filter_profile": str(payload.get("filter_profile", "off")),
                "status": "active",
            }
            self.write_json(metadata_path, metadata)
            self.apply_mptcp_underlay(service_name, mptcp_underlay)
            if sidecar_uplink.get("enabled"):
                # compose model comment: true MPTCP underlay may ride over static uplink; early return used to skip it.
                self.apply_sidecar_uplink(service_name, sidecar_uplink)
            if sidecar_uplink_selector.get("enabled"):
                self.ensure_sidecar_uplink_selector(service_name, sidecar_uplink_selector)
            else:
                self.remove_sidecar_uplink_selector(service_name)
            return {
                "service_name": service_name,
                "unit_path": "",
                "backend_port": 0,
                "public_port": public_port,
                "mux_service": "",
                "relay_service_name": None,
                "relay_client_service_name": None,
            }

        if sidecar_uplink.get("enabled"):
            self.apply_sidecar_uplink(service_name, sidecar_uplink)
        if sidecar_uplink_selector.get("enabled"):
            self.ensure_sidecar_uplink_selector(service_name, sidecar_uplink_selector)
        else:
            self.remove_sidecar_uplink_selector(service_name)

        unit_path = self.service_unit_path(service_name)
        relay_unit_path = self.service_unit_path(relay_name)
        relay_client_unit_path = self.service_unit_path(relay_client_name)
        metadata_path = self.metadata_path(service_name)

        existing = self.load_service_metadata(service_name) or {}
        previous_public_port = int(existing.get("listen_port", 0) or 0)
        backend_port = int(existing.get("backend_port", 0) or 0)
        relay_client_port = int(existing.get("relay_client_port", 0) or 0)

        self.ensure_listener_route_available(public_port, path_prefix, service_name)
        if self.backend_port_needs_reallocation(service_name, backend_port):
            backend_port = self.allocate_backend_port(service_name, {public_port})

        use_plain_tcp_backend = backend_protocol == "tcp_relay"
        selection: dict[str, Any] | None = None

        if use_plain_tcp_backend:
            unit_body = self.render_plain_tcp_backend_unit(service_name, backend_port, target)
            expected_exec = "leikwan-ingress backend"
        elif backend_mode == "cf_mode" and cf_mode != {}:
            selection_mode = dict(cf_mode)
            selection_mode["probe_target"] = target
            selection = self.select_cf_location_ip(selection_mode)
            selected_ip = str(selection["selected_ip"])
            unit_body = self.render_cf_wss_backend_unit(
                service_name=service_name,
                backend_port=backend_port,
                target=target,
                selected_ip=selected_ip,
                dial_host=str(cf_mode.get("dial_host", "") or ""),
                remote_port=int(cf_mode.get("dial_port", 443) or 443),
                sni_host=str(cf_mode.get("sni_host", "") or ""),
                path_prefix=str(cf_mode.get("path_prefix", path_prefix) or path_prefix),
                transport_mode="tcp" if "udp" in transport_mode.lower() and "tcp" in transport_mode.lower() else transport_mode,
            )
            expected_exec = "wstunnel client"
        else:
            unit_body = self.render_backend_unit(service_name, backend_port, target, path_prefix)
            expected_exec = "wstunnel server"
        unit_changed = self.write_text_if_changed(unit_path, unit_body)
        backend_start_deferred = False
        try:
            self.ensure_backend_service(service_name, unit_changed, expected_exec_substring=expected_exec)
        except RuntimeError as exc:
            if mptcp_underlay and mptcp_underlay.get("enabled") and is_transient_systemd_error(str(exc)):
                backend_start_deferred = True
                logging.warning(
                    "transient backend systemd failure ignored for %s before applying mptcp underlay: %s",
                    service_name,
                    exc,
                )
            else:
                raise

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
            "backend_mode": backend_mode,
            "cf_mode": cf_mode,
            "selected_cf_ip": str(selection["selected_ip"]) if selection is not None else "",
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
            "sidecar_uplink": sidecar_uplink,
            "sidecar_uplink_selector": sidecar_uplink_selector,
            "filter_profile": str(payload.get("filter_profile", "off")),
            "status": "active",
        }
        self.write_json(metadata_path, metadata)

        if (not use_plain_tcp_backend) and backend_mode == "cf_mode" and cf_mode != {} and "udp" in transport_mode.lower():
            shadow_service = udp_uot_shadow_service_name(service_name)
            existing_shadow = self.load_service_metadata(shadow_service) or {}
            shadow_backend_port = int(existing_shadow.get("backend_port", 0) or 0)
            if shadow_backend_port <= 0:
                shadow_backend_port = self.allocate_backend_port(shadow_service)
            shadow_route_key = route_key + ".uot"
            shadow_metadata = dict(metadata)
            shadow_metadata.update(
                {
                    "service_name": shadow_service,
                    "unit_path": str(self.service_unit_path(shadow_service)),
                    "backend_port": shadow_backend_port,
                    "path_prefix": shadow_route_key,
                    "route_key": shadow_route_key,
                    "transport_mode": "tcp",
                }
            )
            shadow_unit = self.render_cf_wss_backend_unit(
                service_name=shadow_service,
                backend_port=shadow_backend_port,
                target="127.0.0.1:8999",
                selected_ip=str(selection["selected_ip"]),
                dial_host=str(cf_mode.get("dial_host", "") or ""),
                remote_port=int(cf_mode.get("dial_port", 443) or 443),
                sni_host=str(cf_mode.get("sni_host", "") or ""),
                path_prefix=shadow_route_key,
                transport_mode="tcp",
            )
            shadow_unit_changed = self.write_text_if_changed(self.service_unit_path(shadow_service), shadow_unit)
            self.ensure_backend_service(shadow_service, shadow_unit_changed, expected_exec_substring="wstunnel client")
            self.save_service_metadata(shadow_service, shadow_metadata)
        elif self.udp_uot_sidepath_enabled(task, payload):
            settings = self.udp_uot_sidepath_settings()
            shadow_service = udp_uot_shadow_service_name(service_name)
            existing_shadow = self.load_service_metadata(shadow_service) or {}
            shadow_backend_port = int(existing_shadow.get("backend_port", 0) or 0)
            if shadow_backend_port <= 0:
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
            self.ensure_backend_service(shadow_service, shadow_unit_changed, expected_exec_substring="leikwan-ingress backend")
            self.save_service_metadata(shadow_service, shadow_metadata)
        else:
            self.remove_udp_uot_shadow_service(task, payload, service_name)

        # CFWG slave relay: create a wstunnel backend that relays client WG UDP
        # through this slave to the sidecar WG endpoint via CF.
        # The client connects to the slave's WSS mux using a different path
        # (e.g. "Windows-9.1.iso") which routes here, and this backend forwards
        # to the sidecar via CF using the cfwg path.
        cfwg_relay = payload.get("cfwg_relay", {})
        if isinstance(cfwg_relay, dict) and truthy(cfwg_relay.get("enabled", False)):
            self.ensure_cfwg_relay_service(task, payload, metadata, cfwg_relay, service_name, public_port)

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
            "wstunnel_start_deferred": backend_start_deferred,
            "selector_port": int(sidecar_uplink_selector.get("selector_port", 0) or 0),
        }

    def stop_service(self, task: dict[str, Any], keep_files: bool) -> dict[str, Any]:
        base_service_name = safe_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_name = relay_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_client_name = relay_client_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        payload = dict(task.get("payload", {}))
        service_names = self.lifecycle_service_names(task, payload)
        public_ports: set[int] = set()
        base_metadata: dict[str, Any] = {}
        for service_name in service_names:
            metadata = self.load_service_metadata(service_name) or {}
            if service_name == base_service_name:
                base_metadata = metadata
            public_port = int(metadata.get("listen_port", payload.get("listen_port", 0)) or 0)
            if public_port > 0:
                public_ports.add(public_port)
            if metadata:
                metadata["status"] = "suspended"
                self.write_json(self.metadata_path(service_name), metadata)

        base_targeted = base_service_name in service_names
        if shutil.which("systemctl"):
            for service_name in service_names:
                run_command(f"systemctl stop {service_name}.service", check=False)
                run_command(f"systemctl disable {service_name}.service", check=False)
            if base_targeted:
                run_command(f"systemctl stop {relay_name}.service", check=False)
                run_command(f"systemctl disable {relay_name}.service", check=False)
                run_command(f"systemctl stop {relay_client_name}.service", check=False)
                run_command(f"systemctl disable {relay_client_name}.service", check=False)
        if base_targeted:
            self.remove_mptcp_underlay(base_service_name, base_metadata.get("mptcp_underlay") if isinstance(base_metadata, dict) else None)
            self.remove_sidecar_uplink(base_service_name, base_metadata.get("sidecar_uplink") if isinstance(base_metadata, dict) else None)
            self.remove_sidecar_uplink_selector(base_service_name)

        if not keep_files:
            relay_unit_path = self.service_unit_path(relay_name)
            relay_client_unit_path = self.service_unit_path(relay_client_name)
            for service_name in service_names:
                unit_path = self.service_unit_path(service_name)
                metadata_path = self.metadata_path(service_name)
                if unit_path.exists():
                    unit_path.unlink()
                if metadata_path.exists():
                    metadata_path.unlink()
            if base_targeted and relay_unit_path.exists():
                relay_unit_path.unlink()
            if base_targeted and relay_client_unit_path.exists():
                relay_client_unit_path.unlink()
            if shutil.which("systemctl"):
                run_command("systemctl daemon-reload", check=False)

        for public_port in sorted(public_ports):
            self.sync_listener(public_port)

        return {"service_name": base_service_name, "stopped": True, "public_port": next(iter(public_ports), 0)}

    def destroy_service(self, task: dict[str, Any]) -> dict[str, Any]:
        base_service_name = safe_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_name = relay_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        relay_client_name = relay_client_service_name(int(task["service_id"]), int(task["endpoint_id"]))
        payload = dict(task.get("payload", {}))
        service_names = self.lifecycle_service_names(task, payload)
        public_ports: set[int] = set()
        base_metadata: dict[str, Any] = {}
        for service_name in service_names:
            metadata = self.load_service_metadata(service_name) or {}
            if service_name == base_service_name:
                base_metadata = metadata
            public_port = int(metadata.get("listen_port", payload.get("listen_port", 0)) or 0)
            if public_port > 0:
                public_ports.add(public_port)
            metadata_path = self.metadata_path(service_name)
            if metadata_path.exists():
                metadata_path.unlink()

        relay_unit_path = self.service_unit_path(relay_name)
        relay_client_unit_path = self.service_unit_path(relay_client_name)

        base_targeted = base_service_name in service_names
        if shutil.which("systemctl"):
            for service_name in service_names:
                run_command(f"systemctl stop {service_name}.service", check=False)
                run_command(f"systemctl disable {service_name}.service", check=False)
            if base_targeted:
                run_command(f"systemctl stop {relay_name}.service", check=False)
                run_command(f"systemctl disable {relay_name}.service", check=False)
                run_command(f"systemctl stop {relay_client_name}.service", check=False)
                run_command(f"systemctl disable {relay_client_name}.service", check=False)
        if base_targeted:
            self.remove_sidecar_uplink_selector(base_service_name)
            self.remove_mptcp_underlay(base_service_name, base_metadata.get("mptcp_underlay") if isinstance(base_metadata, dict) else None)
            self.remove_sidecar_uplink(base_service_name, base_metadata.get("sidecar_uplink") if isinstance(base_metadata, dict) else None)
        for service_name in service_names:
            unit_path = self.service_unit_path(service_name)
            if unit_path.exists():
                unit_path.unlink()
        if base_targeted and relay_unit_path.exists():
            relay_unit_path.unlink()
        if base_targeted and relay_client_unit_path.exists():
            relay_client_unit_path.unlink()
        if base_targeted:
            self.delete_nft_rules(int(task["endpoint_id"]))
            self.delete_filter_marker(base_service_name)
        for public_port in sorted(public_ports):
            self.sync_listener(public_port)
        if shutil.which("systemctl"):
            run_command("systemctl daemon-reload", check=False)
        return {"service_name": base_service_name, "deleted": True, "public_port": next(iter(public_ports), 0)}

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
            "service_name": str(metadata.get("service_name", "")).strip(),
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

        keep_base_service_name = udp_uot_base_service_name(keep_service_name)
        keep_shadow_service_name = udp_uot_shadow_service_name(keep_base_service_name)
        keep_service_names = {keep_base_service_name, keep_shadow_service_name}
        stale_metadata: list[dict[str, Any]] = []
        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            if int(metadata.get("forward_id", 0) or 0) != forward_id:
                continue
            service_name = str(metadata.get("service_name", "")).strip()
            if service_name in keep_service_names:
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
        candidates: list[tuple[dict[str, Any], str]] = []

        for metadata in self.load_all_service_metadata():
            if not self.metadata_is_active(metadata):
                continue
            forward_id = int(metadata.get("forward_id", 0) or 0)
            endpoint_id = int(metadata.get("endpoint_id", 0) or 0)
            service_name = str(metadata.get("service_name", "")).strip()
            if forward_id <= 0 or endpoint_id <= 0 or service_name == "":
                continue
            family_base_service_name = udp_uot_base_service_name(service_name)
            candidates.append((metadata, family_base_service_name))
            current = keepers.get(forward_id)
            if current is None or endpoint_id > current[0]:
                keepers[forward_id] = (endpoint_id, family_base_service_name)

        for metadata, family_base_service_name in candidates:
            forward_id = int(metadata.get("forward_id", 0) or 0)
            service_name = str(metadata.get("service_name", "")).strip()
            keeper = keepers.get(forward_id)
            if keeper is None:
                continue
            keeper_base_service_name = keeper[1]
            keeper_shadow_service_name = udp_uot_shadow_service_name(keeper_base_service_name)
            if service_name in {keeper_base_service_name, keeper_shadow_service_name}:
                continue
            if family_base_service_name == keeper_base_service_name:
                continue
            logging.info(
                "removing stale duplicate service for forward_id=%s old_service=%s keep_service=%s",
                forward_id,
                service_name,
                keeper[1],
            )
            self.destroy_service(self.destroy_task_for_metadata(metadata))

    def cleanup_orphan_wstunnel_svc_units(self) -> None:
        """Reap orphaned wstunnel-svc-*.service units whose backing metadata file
        no longer exists. Legacy orphans (deleted forwards / pre-allocator agents)
        crash-loop forever on stale colliding backend ports, and the
        metadata-driven reconcile never touches them because it iterates metadata,
        not on-disk units. Safety: (a) NO-OP when no service metadata exists at all
        (BUG-1 present-guard: a transiently-unreadable/empty services dir must never
        wipe live units); (b) never reap a unit that is currently active."""
        systemd_dir = Path(str(self.config.get("systemd_dir", "/etc/systemd/system")))
        try:
            unit_files = sorted(systemd_dir.glob("wstunnel-svc-*.service"))
        except OSError:
            return
        if not unit_files:
            return
        if not any(self.services_dir.glob("*.json")):
            return
        have_systemctl = shutil.which("systemctl") is not None
        reaped = 0
        for unit_path in unit_files:
            service_name = unit_path.stem
            if self.metadata_path(service_name).exists():
                continue
            if have_systemctl:
                state = run_command(f"systemctl is-active {service_name}.service", check=False).stdout.strip()
                if state == "active":
                    logging.warning(
                        "orphan wstunnel-svc unit %s has no metadata but is active; skipping reap",
                        service_name,
                    )
                    continue
            logging.warning("reaping orphan wstunnel-svc unit with no metadata: %s", service_name)
            if have_systemctl:
                run_command(f"systemctl stop {service_name}.service", check=False)
                run_command(f"systemctl disable {service_name}.service", check=False)
                run_command(f"systemctl reset-failed {service_name}.service", check=False)
            try:
                unit_path.unlink()
            except OSError:
                logging.exception("failed to remove orphan unit file %s", unit_path)
            reaped += 1
        if reaped and have_systemctl:
            run_command("systemctl daemon-reload", check=False)

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

    def lifecycle_service_names(self, task: dict[str, Any], payload: dict[str, Any]) -> list[str]:
        explicit_service_name = str(task.get("service_name", payload.get("service_name", "")) or "").strip()
        if explicit_service_name != "":
            return [explicit_service_name]

        base_service_name = safe_name(int(task["service_id"]), int(task["endpoint_id"]))
        shadow_service_name = udp_uot_shadow_service_name(base_service_name)
        names = [base_service_name]
        if self.load_service_metadata(shadow_service_name) is not None or self.udp_uot_sidepath_enabled(task, payload):
            names.append(shadow_service_name)
        return names

    def is_udp_uot_shadow_metadata(self, metadata: dict[str, Any]) -> bool:
        service_name = str(metadata.get("service_name", "")).strip()
        if not service_name.endswith("-udp-uot"):
            return False
        shadow_suffix = str(self.udp_uot_sidepath_settings().get("shadow_route_suffix", ".uot") or ".uot")
        route_key = str(metadata.get("route_key", metadata.get("path_prefix", "")) or "").strip()
        return route_key.endswith(shadow_suffix)

    def remove_udp_uot_shadow_service(self, task: dict[str, Any], payload: dict[str, Any], base_service_name: str) -> None:
        shadow_service_name = udp_uot_shadow_service_name(base_service_name)
        shadow_metadata = self.load_service_metadata(shadow_service_name)
        if shadow_metadata is None:
            return
        shadow_payload = dict(payload)
        shadow_payload["listen_port"] = int(shadow_metadata.get("listen_port", payload.get("listen_port", 0)) or 0)
        self.destroy_service(
            {
                "service_name": shadow_service_name,
                "service_id": int(task.get("service_id", 0) or 0),
                "forward_id": int(task.get("forward_id", 0) or 0),
                "endpoint_id": int(task.get("endpoint_id", 0) or 0),
                "payload": shadow_payload,
            }
        )

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
        sockets: list[socket.socket] = []
        try:
            for host, family in (("127.0.0.1", socket.AF_INET), ("0.0.0.0", socket.AF_INET)):
                sock = socket.socket(family, socket.SOCK_STREAM)
                sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                try:
                    sock.bind((host, port))
                except OSError:
                    sock.close()
                    return False
                sockets.append(sock)
            return True
        finally:
            for sock in sockets:
                sock.close()

    def backend_port_needs_reallocation(self, service_name: str, backend_port: int) -> bool:
        if backend_port <= 0:
            return True
        if backend_port in self.listener_ports():
            logging.warning(
                "service %s backend_port=%s collides with public listener port; reallocating",
                service_name,
                backend_port,
            )
            return True
        if not self.tcp_port_available(backend_port):
            current_cmdline = self.service_main_process_cmdline(service_name)
            if current_cmdline and f":{backend_port}" in current_cmdline:
                return False
            logging.warning(
                "service %s backend_port=%s is already bound by another process; reallocating",
                service_name,
                backend_port,
            )
            return True
        return False

    def allocate_backend_port(self, service_name: str, reserved_ports: set[int] | None = None) -> int:
        start = int(self.config.get("backend_port_range_start", 20000))
        end = int(self.config.get("backend_port_range_end", 40000))
        if start <= 0 or end <= 0 or end < start:
            raise RuntimeError("invalid backend port range")

        used = self.used_backend_ports(service_name)
        used.update(self.listener_ports())
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
                run_systemctl_tolerating_transient("systemctl daemon-reload")
            run_systemctl_tolerating_transient(f"systemctl enable {service_name}.service")
            if unit_changed:
                run_command(f"systemctl restart {service_name}.service")
            else:
                run_command(f"systemctl start {service_name}.service", check=False)

    @staticmethod
    def systemd_is_active(service_name: str) -> bool:
        if not shutil.which("systemctl"):
            return False
        return run_command(f"systemctl is-active --quiet {service_name}.service", check=False).returncode == 0

    def ensure_backend_service(self, service_name: str, unit_changed: bool, expected_exec_substring: str | None = None) -> None:
        if not shutil.which("systemctl"):
            return
        is_active = self.systemd_is_active(service_name)
        if expected_exec_substring and is_active and not unit_changed:
            current_cmdline = self.service_main_process_cmdline(service_name)
            if expected_exec_substring not in current_cmdline:
                unit_changed = True
        if unit_changed:
            run_systemctl_tolerating_transient("systemctl daemon-reload")
        run_systemctl_tolerating_transient(f"systemctl enable {service_name}.service")
        if unit_changed and is_active:
            run_systemctl_tolerating_transient(f"systemctl restart {service_name}.service")
        elif not is_active:
            run_systemctl_tolerating_transient(f"systemctl start {service_name}.service")

    def service_main_process_cmdline(self, service_name: str) -> str:
        if not shutil.which("systemctl"):
            return ""
        result = run_command(f"systemctl show {service_name}.service --property MainPID --value", check=False)
        if result.returncode != 0:
            return ""
        pid_text = str(result.stdout or "").strip() if hasattr(result, "stdout") else ""
        try:
            pid = int(pid_text)
        except (TypeError, ValueError):
            return ""
        if pid <= 0:
            return ""
        cmdline_path = Path("/proc") / str(pid) / "cmdline"
        try:
            raw = cmdline_path.read_bytes()
        except FileNotFoundError:
            return ""
        return raw.replace(b"\x00", b" ").decode("utf-8", errors="replace").strip()

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

    def render_cf_wss_backend_unit(
        self,
        service_name: str,
        backend_port: int,
        target: str,
        selected_ip: str,
        dial_host: str,
        remote_port: int,
        sni_host: str,
        path_prefix: str,
        transport_mode: str,
    ) -> str:
        local_proto = "udp" if str(transport_mode).strip().lower() == "udp" else "tcp"
        explicit_dial_host = str(dial_host or "").strip()
        effective_sni_host = str(sni_host or "").strip()
        remote_host = str(selected_ip or "").strip() or explicit_dial_host or effective_sni_host
        sni_args = ""
        if effective_sni_host != "":
            sni_args = (
                f"  --tls-sni-override {effective_sni_host} \\\n"
                f'  --http-headers "Host: {effective_sni_host}" \\\n'
            )
        return f"""[Unit]
Description=Leikwan CF WSS Backend {service_name}
After=network.target

[Service]
Type=simple
ExecStart={self.config['wstunnel_bin']} client -L {local_proto}://127.0.0.1:{backend_port}:{target} \
{sni_args}\
  --http-upgrade-path-prefix "{path_prefix}" \
  wss://{remote_host}:{remote_port}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""

    def ensure_cfwg_relay_service(
        self,
        task: dict[str, Any],
        payload: dict[str, Any],
        parent_metadata: dict[str, Any],
        cfwg_relay: dict[str, Any],
        parent_service_name: str,
        public_port: int,
    ) -> None:
        """Create a wstunnel backend that relays WG UDP from clients through this slave to CF.

        Traffic: client -> wstunnel WSS (slave mux, path=slave_path_prefix)
                 -> this backend wstunnel -> CF -> sidecar WG endpoint.

        Config fields in cfwg_relay:
          - enabled: bool
          - slave_path_prefix: str (e.g. "Windows-9.1.iso") 鈥?path client uses to reach this relay
          - cf_target_host: str 鈥?sidecar WG endpoint host reachable via CF
          - cf_target_port: int 鈥?sidecar WG endpoint port
          - cf_dial_host: str 鈥?CF IP to connect to (optional, falls back to cf_mode.dial_host)
          - cf_dial_port: int 鈥?CF port (optional, falls back to cf_mode.dial_port or 443)
          - cf_sni_host: str 鈥?SNI host for CF connection
          - cf_path_prefix: str 鈥?path on sidecar's CF mux for the WG endpoint
        """
        slave_path_prefix = str(cfwg_relay.get("slave_path_prefix", "") or "").strip().strip("/")
        if slave_path_prefix == "":
            return

        cf_target_host = str(cfwg_relay.get("cf_target_host", "") or "").strip()
        cf_target_port = int(cfwg_relay.get("cf_target_port", 0) or 0)
        if cf_target_host == "" or cf_target_port <= 0:
            return

        cf_mode = payload.get("cf_mode", {})
        if not isinstance(cf_mode, dict):
            cf_mode = {}

        cf_dial_host = str(cfwg_relay.get("cf_dial_host", "") or cf_mode.get("dial_host", "") or "").strip()
        cf_dial_port = int(cfwg_relay.get("cf_dial_port", 0) or 0)
        if cf_dial_port <= 0:
            cf_dial_port = int(cf_mode.get("dial_port", 443) or 443)
        cf_sni_host = str(cfwg_relay.get("cf_sni_host", "") or cf_mode.get("sni_host", "") or "").strip()
        cf_path_prefix = str(cfwg_relay.get("cf_path_prefix", "") or "").strip().strip("/")
        if cf_path_prefix == "":
            return

        # Target for wstunnel: the sidecar WG endpoint host:port
        target = f"{cf_target_host}:{cf_target_port}"

        relay_service = f"{parent_service_name}-cfwg-relay"
        existing = self.load_service_metadata(relay_service) or {}
        backend_port = int(existing.get("backend_port", 0) or 0)
        if self.backend_port_needs_reallocation(relay_service, backend_port):
            backend_port = self.allocate_backend_port(relay_service, {public_port})

        # Select CF IP for this relay (reuse parent selection if available)
        selected_ip = str(parent_metadata.get("selected_cf_ip", "") or cf_dial_host or "").strip()
        if selected_ip == "":
            selected_ip = cf_dial_host

        unit_body = self.render_cf_wss_backend_unit(
            service_name=relay_service,
            backend_port=backend_port,
            target=target,
            selected_ip=selected_ip,
            dial_host=cf_dial_host,
            remote_port=cf_dial_port,
            sni_host=cf_sni_host,
            path_prefix=cf_path_prefix,
            transport_mode="udp",
        )
        unit_changed = self.write_text_if_changed(self.service_unit_path(relay_service), unit_body)
        self.ensure_backend_service(relay_service, unit_changed, expected_exec_substring="wstunnel client")

        relay_metadata = {
            "service_name": relay_service,
            "service_id": int(task.get("service_id", 0) or 0),
            "forward_id": int(task.get("forward_id", 0) or 0),
            "endpoint_id": int(task.get("endpoint_id", 0) or 0),
            "listen_port": public_port,
            "backend_port": backend_port,
            "target": target,
            "path_prefix": slave_path_prefix,
            "route_key": slave_path_prefix,
            "backend_mode": "cf_mode",
            "cf_mode": {
                "dial_host": cf_dial_host,
                "dial_port": cf_dial_port,
                "sni_host": cf_sni_host,
                "path_prefix": cf_path_prefix,
            },
            "selected_cf_ip": selected_ip,
            "ingress_protocol": "wss_path_mux",
            "backend_protocol": "wss_backend",
            "ingress_auth_token": "",
            "transport_mode": "udp",
            "relay_protocols": [],
            "relay_target": "",
            "relay_service_name": "",
            "relay_client_service_name": "",
            "relay_client_port": 0,
            "mptcp_underlay": {},
            "sidecar_uplink": {},
            "filter_profile": "off",
            "status": "active",
            "cfwg_relay_parent": parent_service_name,
        }
        self.save_service_metadata(relay_service, relay_metadata)

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
        return Path(str(self.config.get("wireguard_config_dir", "/etc/wireguard") or "/etc/wireguard")) / f"{interface_name}.conf"

    def stable_static_sidecar_local_udp_port(self, uplink: dict[str, Any]) -> int:
        try:
            point_id = int(uplink.get("sidecar_point_id", 0) or 0)
            combination_id = int(uplink.get("forward_combination_id", 0) or 0)
            node_id = int(uplink.get("node_id", 0) or 0)
        except (TypeError, ValueError):
            return 0
        if point_id <= 0 or combination_id <= 0 or node_id <= 0:
            return 0
        lane = str(uplink.get("lane", "") or "").strip().lower()
        lane_offset = 1 if lane in {"backup", "b"} else 0
        return 43000 + abs(((point_id * 1009) + (combination_id * 131) + (node_id * 17) + lane_offset) % 2000)

    def apply_sidecar_uplink(self, service_name: str, uplink: dict[str, Any]) -> None:
        if not uplink.get("enabled") or not shutil.which("wg") or not shutil.which("wg-quick"):
            return
        self.ensure_wireguard_identity()
        private_key = self.wireguard_private_key_path().read_text(encoding="utf-8").strip()
        interface_name = str(uplink.get("interface_name", "") or service_name).strip()[:15]
        local_address = str(uplink.get("local_address", "") or "").strip()
        peer_address = str(uplink.get("peer_address", "") or "").strip()
        peer_public_key = str(uplink.get("sidecar_peer_public_key", "") or "").strip()
        endpoint_host = str(uplink.get("sidecar_endpoint_host", "") or "").strip()
        endpoint_port = max(0, int(uplink.get("sidecar_endpoint_port", 0) or 0))
        outer_transport = str(uplink.get("outer_transport", "udp") or "udp").strip().lower()
        peer_tcp_fallback_port = max(0, int(uplink.get("peer_tcp_fallback_port", 0) or 0))
        local_uot_udp_port = max(0, int(uplink.get("local_uot_udp_port", 0) or 0))
        local_wstunnel_udp_port = max(0, int(uplink.get("local_wstunnel_udp_port", 0) or 0))
        gost_listen_udp_port = max(0, int(uplink.get("gost_listen_udp_port", 0) or 0))
        mtu = max(0, int(uplink.get("mtu", 0) or 0))
        local_listen_port = max(0, int(uplink.get("local_listen_port", 0) or 0))
        if local_listen_port <= 0:
            local_listen_port = self.stable_static_sidecar_local_udp_port(uplink)
        keepalive = max(0, int(uplink.get("peer_keepalive_seconds", 25) or 25))
        if local_address == "" or peer_address == "" or peer_public_key == "":
            # Enabled uplink with incomplete data (e.g. the sidecar has not yet
            # reported its WireGuard public key, or the panel published a partial
            # lane). We cannot render a valid WG config, so we must NOT leave a
            # previously-enabled wg-quick@ unit running against a stale/absent
            # config -- that is exactly the "/etc/wireguard/<iface>.conf does not
            # exist" fail-loop. Tear the interface down; the next reconcile
            # re-provisions it once the panel publishes complete data.
            logging.warning(
                "static sidecar uplink %s missing local/peer address or sidecar key; tearing down until complete data is published",
                interface_name,
            )
            self.remove_sidecar_uplink(interface_name, {"interface_name": interface_name})
            return
        force_wg_reinit = False  # set when a leigod carrier swap (gost<->lguot) needs a fresh WG handshake
        if outer_transport == "wstunnel_udp" and local_wstunnel_udp_port > 0:
            cf_target_host = str(uplink.get("cf_target_host", "") or "127.0.0.1").strip()
            cf_target_port = max(0, int(uplink.get("cf_target_port", 0) or 0))
            cf_dial_host = str(uplink.get("cf_dial_host", "") or "").strip()
            cf_dial_port = max(0, int(uplink.get("cf_dial_port", 0) or 0))
            cf_sni_host = str(uplink.get("cf_sni_host", "") or "").strip()
            cf_path_prefix = str(uplink.get("cf_path_prefix", "") or "").strip().strip("/")
            cf_selection = None
            raw_cf_mode = uplink.get("cf_mode", {})
            if isinstance(raw_cf_mode, dict):
                selection_mode = dict(raw_cf_mode)
                selection_mode.setdefault("dial_host", cf_dial_host)
                selection_mode.setdefault("dial_port", cf_dial_port)
                selection_mode.setdefault("sni_host", cf_sni_host)
                selection_mode.setdefault("path_prefix", cf_path_prefix)
                # Validate the chosen CF edge with a REAL wstunnel handshake to the
                # transit target instead of the raw-socket probe. A CF edge that 400s
                # the routed lws-5-1-N websocket path still passes the raw probe (false
                # 101) but fails a real wstunnel client, so without probe_target the
                # selector wedges on a 400-returning edge (the forward-relay path at
                # reconcile_runtime already does this; mirror it for slave transit).
                if cf_target_host != "" and cf_target_port > 0:
                    selection_mode.setdefault("probe_target", f"{cf_target_host}:{cf_target_port}")
                    selection_mode["validate_current"] = True
                if selection_mode.get("location_group_id") and isinstance(selection_mode.get("cidrs"), list):
                    try:
                        cf_selection = self.select_cf_location_ip(selection_mode)
                    except Exception as exc:  # noqa: BLE001
                        logging.warning("failed to select CF candidate for static sidecar uplink %s: %s", interface_name, exc)
            elif isinstance(uplink.get("cf_location_group_id", None), int) or str(uplink.get("cf_location_group_id", "") or "").isdigit():
                cidrs = uplink.get("cf_cidrs", [])
                if isinstance(cidrs, list) and cidrs:
                    selection_mode = {
                        "location_group_id": int(uplink.get("cf_location_group_id", 0) or 0),
                        "cidrs": cidrs,
                        "probe_mode": str(uplink.get("cf_probe_mode", "tcp") or "tcp"),
                        "dial_port": cf_dial_port,
                        "sni_host": cf_sni_host,
                        "path_prefix": cf_path_prefix,
                        "cache_ttl_seconds": int(uplink.get("cf_cache_ttl_seconds", 120) or 120),
                        "switch_improvement_ms": int(uplink.get("cf_switch_improvement_ms", 15) or 15),
                        "max_candidates": int(uplink.get("cf_max_candidates", 8) or 8),
                        # Real-handshake validation so a 400-returning edge is rejected
                        # (see the matching block in the cf_mode branch above).
                        "probe_target": (f"{cf_target_host}:{cf_target_port}" if cf_target_host != "" and cf_target_port > 0 else ""),
                        "validate_current": True,
                    }
                    try:
                        cf_selection = self.select_cf_location_ip(selection_mode)
                    except Exception as exc:  # noqa: BLE001
                        logging.warning("failed to select CF candidate for static sidecar uplink %s: %s", interface_name, exc)
            if isinstance(cf_selection, dict):
                selected_cf_ip = str(cf_selection.get("selected_ip", "") or "").strip()
                if selected_cf_ip != "":
                    cf_dial_host = selected_cf_ip
            if cf_target_host != "" and cf_target_port > 0 and cf_dial_host != "" and cf_dial_port > 0 and cf_path_prefix != "":
                self.ensure_sidecar_wstunnel_udp_client_service(
                    interface_name,
                    local_wstunnel_udp_port,
                    cf_target_host,
                    cf_target_port,
                    cf_dial_host,
                    cf_dial_port,
                    cf_sni_host,
                    cf_path_prefix,
                )
                endpoint_host = "127.0.0.1"
                endpoint_port = local_wstunnel_udp_port
            self.remove_sidecar_uot_client_service(interface_name)
        elif outer_transport == "tcp" and local_uot_udp_port > 0 and endpoint_host != "" and peer_tcp_fallback_port > 0:
            self.ensure_sidecar_uot_client_service(interface_name, local_uot_udp_port, endpoint_host, peer_tcp_fallback_port)
            endpoint_host = "127.0.0.1"
            endpoint_port = local_uot_udp_port
            self.remove_sidecar_wstunnel_udp_client_service(interface_name)
        elif outer_transport == "leigod":
            # LeiGod basis: carry this WG hop over a LeiGod line via gost UDP-over-TCP. The slave runs
            # leigod@<line> (SOCKS 127.0.0.1:20000+line) + a gost client; the WG [Peer] Endpoint is
            # re-pinned to the local gost UDP listener (mirrors the cf wstunnel_udp / tcp UoT arms).
            leigod_line_id = max(0, int(uplink.get("leigod_line_id", 0) or 0))
            sidecar_gost_host = str(uplink.get("sidecar_gost_host", "") or "").strip()
            sidecar_gost_port = max(0, int(uplink.get("sidecar_gost_port", 0) or 0))
            sidecar_wg_listen_port = max(0, int(uplink.get("sidecar_wg_listen_port", 0) or 0))
            socks_user = str(uplink.get("socks_user", "") or "wguot").strip() or "wguot"
            socks_pass = str(uplink.get("socks_pass", "") or "").strip()
            leigod_line_toml = str(uplink.get("leigod_line_toml", "") or "")
            # leigod_uot_impl selects the UoT carrier for this lane: "gost" (default) or "lguot"
            # (CPU-efficient batched recvmmsg/sendmmsg). Both ends must agree -- the panel publishes
            # the same value into the slave AND sidecar uplink for a lane.
            leigod_uot_impl = str(uplink.get("leigod_uot_impl", "gost") or "gost").strip().lower()
            if (gost_listen_udp_port > 0 and leigod_line_id > 0 and sidecar_gost_host != ""
                    and sidecar_gost_port > 0 and sidecar_wg_listen_port > 0 and socks_pass != ""):
                self.ensure_leigod_line_service(leigod_line_id, leigod_line_toml)
                systemd_dir = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system"))
                gost_unit_present = (systemd_dir / f"leikwan-gost-{interface_name}.service").exists()
                lguot_unit_present = (systemd_dir / f"leikwan-lguot-{interface_name}.service").exists()
                if leigod_uot_impl == "lguot":
                    # Carrier swap gost->lguot. Remove the gost client FIRST so it releases the shared
                    # gost_listen_udp_port before lguot's ListenUDP binds it (no SO_REUSEPORT -> would
                    # EADDRINUSE-flap under Restart=always). lguot client SOCKS5-CONNECTs through the line
                    # to the sidecar lguot server (reuses sidecar_gost_port + socks_pass as the token).
                    if gost_unit_present:
                        force_wg_reinit = True
                    self.remove_sidecar_gost_uot_client_service(interface_name)
                    self.ensure_sidecar_lguot_client_service(
                        interface_name, gost_listen_udp_port, leigod_line_id,
                        sidecar_gost_host, sidecar_gost_port, socks_pass,
                    )
                else:
                    if lguot_unit_present:
                        force_wg_reinit = True
                    self.remove_sidecar_lguot_client_service(interface_name)
                    self.ensure_sidecar_gost_uot_client_service(
                        interface_name, gost_listen_udp_port, sidecar_wg_listen_port, leigod_line_id,
                        sidecar_gost_host, sidecar_gost_port, socks_user, socks_pass,
                    )
                endpoint_host = "127.0.0.1"
                endpoint_port = gost_listen_udp_port
            else:
                self.remove_sidecar_gost_uot_client_service(interface_name)
                self.remove_sidecar_lguot_client_service(interface_name)
            self.remove_sidecar_uot_client_service(interface_name)
            self.remove_sidecar_wstunnel_udp_client_service(interface_name)
        else:
            self.remove_sidecar_uot_client_service(interface_name)
            self.remove_sidecar_wstunnel_udp_client_service(interface_name)
            self.remove_sidecar_gost_uot_client_service(interface_name)
            self.remove_sidecar_lguot_client_service(interface_name)
        if outer_transport == "leigod" and gost_listen_udp_port > 0 and local_listen_port == gost_listen_udp_port:
            # The local gost UDP listener owns gost_listen_udp_port; don't let WireGuard bind it too.
            local_listen_port = 0
        if outer_transport == "wstunnel_udp" and local_wstunnel_udp_port > 0 and local_listen_port == local_wstunnel_udp_port:
            # The local wstunnel UDP listener owns local_wstunnel_udp_port.  If
            # WireGuard also binds the same ListenPort, wg-quick fails on
            # interface-up with "RTNETLINK answers: Address already in use".
            # Let WireGuard pick a source port while keeping its peer Endpoint
            # pinned to the local wstunnel listener.
            local_listen_port = 0
        endpoint = f"Endpoint = {endpoint_host}:{endpoint_port}\n" if endpoint_host != "" and endpoint_port > 0 else ""
        listen_line = f"ListenPort = {local_listen_port}\n" if local_listen_port > 0 else ""
        keepalive_line = f"PersistentKeepalive = {keepalive}\n" if keepalive > 0 else ""
        mtu_line = f"MTU = {mtu}\n" if mtu > 0 else ""
        config = (
            "[Interface]\n"
            f"PrivateKey = {private_key}\n"
            f"{listen_line}"
            f"Address = {local_address}/32\n"
            f"{mtu_line}"
            "\n"
            "[Peer]\n"
            f"PublicKey = {peer_public_key}\n"
            f"{endpoint}"
            f"AllowedIPs = {peer_address}/32\n"
            f"{keepalive_line}"
        )
        path = self.wireguard_interface_path(interface_name)
        changed = self.write_text_if_changed(path, config)
        if shutil.which("systemctl"):
            service_name = f"wg-quick@{interface_name}"
            is_active = self.systemd_is_active(service_name)
            if changed:
                run_command("systemctl daemon-reload", check=False)
            if changed or not is_active or force_wg_reinit:
                run_command(f"systemctl enable {service_name}.service", check=False)
                # A leigod carrier swap (gost<->lguot) keeps the WG .conf byte-identical (same Endpoint),
                # so write_text_if_changed is a no-op and WG would NOT restart -- but the UoT carrier under
                # it changed. Bounce WG to force a fresh slave-initiated handshake: that (a) re-seeds the new
                # lguot client's WG-source learn and (b) re-roams the sidecar WG peer onto the live socket.
                action = "restart" if (changed or force_wg_reinit) else "start"
                result = run_command(f"systemctl {action} {service_name}.service", check=False)
                detail = str(getattr(result, "stderr", "") or getattr(result, "stdout", "") or "")
                if int(getattr(result, "returncode", 0) or 0) != 0 and is_transient_systemd_error(detail):
                    logging.warning("transient systemd %s failure for %s; reloading directly with wg-quick", action, interface_name)
                    run_command(f"wg-quick down {interface_name}", check=False)
                    run_command(f"ip link delete dev {interface_name}", check=False)
                    run_command(f"wg-quick up {interface_name}", check=False)

    def remove_sidecar_uplink(self, service_name: str, uplink: dict[str, Any] | None = None) -> None:
        interface_name = str((uplink or {}).get("interface_name", "") or service_name).strip()[:15]
        if interface_name == "":
            return
        if shutil.which("systemctl"):
            run_command(f"systemctl stop wg-quick@{interface_name}.service", check=False)
            run_command(f"systemctl disable wg-quick@{interface_name}.service", check=False)
            run_command(f"systemctl reset-failed wg-quick@{interface_name}.service", check=False)
        if shutil.which("wg-quick"):
            run_command(f"wg-quick down {interface_name}", check=False)
        if shutil.which("ip"):
            run_command(f"ip link delete dev {interface_name}", check=False)
        self.remove_sidecar_uot_client_service(interface_name)
        self.remove_sidecar_gost_uot_client_service(interface_name)
        self.remove_sidecar_lguot_client_service(interface_name)
        path = self.wireguard_interface_path(interface_name)
        if path.exists():
            path.unlink()
        self.remove_sidecar_wstunnel_udp_client_service(interface_name)

    def ensure_leigod_line_service(self, line_id: int, line_toml: str = "") -> None:
        # Fully self-provision leigod@<line> from panel-published state: write the unit template (using
        # the configured leigod binary + root) and the per-line config toml, then start it. The shared
        # account session.json is delivered separately via apply_leigod_session (panel management). A
        # single account session works concurrently across many slaves, so one published session + one
        # published line toml is all a slave needs. Idempotent (write_text_if_changed). We only START
        # lines here (never auto-stop: a shared line may back another lane); per-interface gost clients
        # are reaped by remove_sidecar_uplink / cleanup_orphan_sidecar_uplinks.
        if int(line_id) <= 0 or not shutil.which("systemctl"):
            return
        leigod_bin = str(self.config.get("leigod_bin", "/usr/local/bin/leigod-linux-amd64") or "/usr/local/bin/leigod-linux-amd64")
        leigod_root = Path(str(self.config.get("leigod_root", "/opt/leigod") or "/opt/leigod"))
        # Churn guard: never write a Restart=always unit on a node lacking the leigod accelerator binary.
        if shutil.which(leigod_bin) is None and not Path(leigod_bin).exists():
            return
        if isinstance(line_toml, str) and line_toml.strip() != "":
            try:
                lines_dir = leigod_root / "lines"
                lines_dir.mkdir(parents=True, exist_ok=True)
                toml_path = lines_dir / f"{int(line_id)}.toml"
                self.write_text_if_changed(toml_path, line_toml)
                try:
                    os.chmod(toml_path, 0o600)
                except OSError:
                    pass
            except OSError as exc:  # noqa: BLE001
                logging.warning("failed to write leigod line %s toml: %s", line_id, exc)
        unit_body = (
            "[Unit]\n"
            "Description=leigod exit line %i\n"
            "After=network-online.target\n"
            "Wants=network-online.target\n\n"
            "[Service]\n"
            "Type=simple\n"
            "User=root\n"
            "ExecStartPre=/bin/bash -c 'sleep $((RANDOM %% 15))'\n"
            f"ExecStart={leigod_bin} up --session {leigod_root}/session.json --config {leigod_root}/lines/%i.toml\n"
            "Restart=always\n"
            "RestartSec=12\n\n"
            "[Install]\n"
            "WantedBy=multi-user.target\n"
        )
        unit_path = Path("/etc/systemd/system/leigod@.service")
        unit_changed = self.write_text_if_changed(unit_path, unit_body)
        if unit_changed:
            run_command("systemctl daemon-reload", check=False)
        # Don't start until the session exists, else leigod crash-loops on "no session".
        if not (leigod_root / "session.json").exists():
            return
        unit = f"leigod@{int(line_id)}.service"
        if unit_changed or not self.systemd_is_active(unit):
            run_command(f"systemctl enable {unit}", check=False)
            run_command(f"systemctl {'restart' if unit_changed else 'start'} {unit}", check=False)

    def ensure_sidecar_gost_uot_client_service(
        self,
        interface_name: str,
        gost_listen_udp_port: int,
        sidecar_wg_listen_port: int,
        leigod_line_id: int,
        sidecar_gost_host: str,
        sidecar_gost_port: int,
        socks_user: str,
        socks_pass: str,
    ) -> None:
        if (gost_listen_udp_port <= 0 or sidecar_gost_port <= 0 or sidecar_gost_host == ""
                or sidecar_wg_listen_port <= 0 or socks_pass == ""):
            return
        line_socks_port = 20000 + int(leigod_line_id)
        service_name = f"leikwan-gost-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        gost_bin = str(self.config.get("gost_bin", "/usr/local/bin/gost") or "/usr/local/bin/gost")
        # Do NOT write a Restart=always gost unit on a node that lacks the gost binary: it would fail every
        # 3s in a systemd restart loop (the churn/storm failure mode). If gost is absent, remove any stale
        # unit and skip; the leigod uplink simply won't realize on this node and the selector falls back.
        if shutil.which(gost_bin) is None:
            self.remove_sidecar_gost_uot_client_service(interface_name)
            return
        exec_start = (
            f"{gost_bin} "
            f"-L udp://127.0.0.1:{gost_listen_udp_port}/127.0.0.1:{sidecar_wg_listen_port}?keepAlive=true&ttl=30s "
            f"-F socks5://127.0.0.1:{line_socks_port} "
            f"-F socks5://{socks_user}:{socks_pass}@{sidecar_gost_host}:{sidecar_gost_port}?udp=true"
        )
        body = f"""[Unit]
Description=Leikwan LeiGod gost UoT Client {service_name}
After=network.target

[Service]
Type=simple
ExecStart={exec_start}
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

    def remove_sidecar_gost_uot_client_service(self, interface_name: str) -> None:
        service_name = f"leikwan-gost-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command("systemctl daemon-reload", check=False)
        if unit_path.exists():
            unit_path.unlink()

    def ensure_lguot_binary(self) -> None:
        """Download the lguot UoT binary from the panel if it is missing -- self-heal for the
        leigod_uot_impl=lguot rollout without re-running the installer. Sha-gating is the installer's
        job; at runtime we only ensure presence (download-if-missing) to avoid a re-download churn loop.
        """
        lguot_bin = Path(str(self.config.get("lguot_bin", "/usr/local/bin/lguot") or "/usr/local/bin/lguot"))
        if lguot_bin.exists() and os.access(str(lguot_bin), os.X_OK):
            return
        master = str(self.config.get("master_url", "") or "").rstrip("/")
        if master == "":
            return
        url = f"{master}/artifacts/lguot/lguot_linux_amd64"
        try:
            with request.urlopen(request.Request(url), timeout=60) as resp:
                tmp_path = lguot_bin.with_suffix(".tmp")
                tmp_path.write_bytes(resp.read())
                tmp_path.chmod(0o755)
                tmp_path.rename(lguot_bin)
            logging.info("lguot binary installed at %s", lguot_bin)
        except Exception as exc:  # noqa: BLE001
            logging.warning("failed to download lguot binary: %s", exc)

    def ensure_sidecar_lguot_client_service(
        self,
        interface_name: str,
        gost_listen_udp_port: int,
        leigod_line_id: int,
        sidecar_gost_host: str,
        sidecar_gost_port: int,
        token: str,
    ) -> None:
        """LeiGod basis, CPU-efficient carrier: lguot client takes WG UDP on the local
        gost_listen_udp_port and frames it over a single TCP stream SOCKS5-CONNECTed through the line
        (127.0.0.1:20000+line) to the sidecar's lguot server (sidecar_gost_host:sidecar_gost_port),
        authenticated by the shared token (= socks_pass). Drop-in peer of the gost client arm."""
        if (gost_listen_udp_port <= 0 or sidecar_gost_port <= 0 or sidecar_gost_host == "" or token == ""):
            return
        line_socks_port = 20000 + int(leigod_line_id)
        service_name = f"leikwan-lguot-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        lguot_bin = str(self.config.get("lguot_bin", "/usr/local/bin/lguot") or "/usr/local/bin/lguot")
        self.ensure_lguot_binary()
        # Churn guard: never write a Restart=always unit on a node lacking the lguot binary (the
        # systemd restart-storm failure mode). If absent, remove any stale unit and skip; the selector
        # falls back to the other bases.
        if shutil.which(lguot_bin) is None and not Path(lguot_bin).exists():
            self.remove_sidecar_lguot_client_service(interface_name)
            return
        exec_start = (
            f"{lguot_bin} client "
            f"-listen 127.0.0.1:{gost_listen_udp_port} "
            f"-socks 127.0.0.1:{line_socks_port} "
            f"-target {sidecar_gost_host}:{sidecar_gost_port} "
            f"-token {token}"
        )
        body = f"""[Unit]
Description=Leikwan LeiGod lguot UoT Client {service_name}
After=network.target

[Service]
Type=simple
ExecStart={exec_start}
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

    def remove_sidecar_lguot_client_service(self, interface_name: str) -> None:
        service_name = f"leikwan-lguot-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command("systemctl daemon-reload", check=False)
        if unit_path.exists():
            unit_path.unlink()

    def ensure_sidecar_wstunnel_udp_client_service(
        self,
        interface_name: str,
        listen_udp_port: int,
        target_host: str,
        target_port: int,
        dial_host: str,
        dial_port: int,
        sni_host: str,
        path_prefix: str,
    ) -> None:
        if listen_udp_port <= 0 or target_port <= 0 or dial_host == "" or dial_port <= 0 or path_prefix == "":
            return
        service_name = f"leikwan-wgcf-{interface_name}"
        target = f"{target_host}:{target_port}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        unit_body = self.render_cf_wss_backend_unit(
            service_name=service_name,
            backend_port=listen_udp_port,
            target=target,
            selected_ip=dial_host,
            dial_host=dial_host,
            remote_port=dial_port,
            sni_host=sni_host,
            path_prefix=path_prefix,
            transport_mode="udp",
        )
        changed = self.write_text_if_changed(unit_path, unit_body)
        self.ensure_backend_service(service_name, changed, expected_exec_substring="wstunnel client")

    def remove_sidecar_wstunnel_udp_client_service(self, interface_name: str) -> None:
        service_name = f"leikwan-wgcf-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command("systemctl daemon-reload", check=False)
        if unit_path.exists():
            unit_path.unlink()

    def ensure_sidecar_uot_client_service(self, interface_name: str, listen_udp_port: int, remote_host: str, remote_tcp_port: int) -> None:
        if listen_udp_port <= 0 or remote_tcp_port <= 0 or remote_host == "":
            return
        service_name = f"leikwan-uot-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        body = f"""[Unit]
Description=Leikwan UoT Client {service_name}
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/leikwan-uot.py client --listen-udp 127.0.0.1:{listen_udp_port} --remote-tcp {remote_host}:{remote_tcp_port}
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

    def remove_sidecar_uot_client_service(self, interface_name: str) -> None:
        service_name = f"leikwan-uot-{interface_name}"
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system")) / f"{service_name}.service"
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {service_name}.service", check=False)
            run_command(f"systemctl disable {service_name}.service", check=False)
            run_command("systemctl daemon-reload", check=False)
        if unit_path.exists():
            unit_path.unlink()

    def evaluate_latency_policy_window(self, chain_key: str, policy: dict[str, Any], sample: dict[str, Any]) -> dict[str, Any]:
        state = self.load_latency_policy_state()
        chain_state = state.get(chain_key, {}) if isinstance(state.get(chain_key), dict) else {}
        samples = chain_state.get("samples", []) if isinstance(chain_state.get("samples"), list) else []
        window_seconds = max(10, int(policy.get("evaluation_window_seconds", 3600) or 3600))
        timestamp = float(sample.get("timestamp", time.time()) or time.time())
        record = {
            "timestamp": timestamp,
            "latency_ms": float(sample.get("latency_ms", 0.0) or 0.0),
            "loss_ratio_percent": float(sample.get("loss_ratio_percent", 0.0) or 0.0),
        }
        samples.append(record)
        cutoff = timestamp - window_seconds
        samples = [item for item in samples if float(item.get("timestamp", 0.0) or 0.0) >= cutoff]
        desired = policy.get("desired_latency_ms")
        desired_latency = float(desired) if desired not in (None, "") else None
        max_exceed_time = max(1.0, float(policy.get("max_exceed_time_seconds", 15) or 15))
        max_exceed_ratio = max(0.0, min(100.0, float(policy.get("max_exceed_ratio_percent", 25) or 25)))
        max_loss_ratio = max(0.0, min(100.0, float(policy.get("max_loss_ratio_percent", 2) or 2)))
        exceeded = [item for item in samples if desired_latency is not None and float(item.get("latency_ms", 0.0) or 0.0) > desired_latency]
        exceed_ratio = (len(exceeded) / len(samples) * 100.0) if samples else 0.0
        continuous_exceed = 0.0
        if exceeded:
            tail = []
            for item in reversed(samples):
                if desired_latency is not None and float(item.get("latency_ms", 0.0) or 0.0) > desired_latency:
                    tail.append(item)
                else:
                    break
            if tail:
                continuous_exceed = timestamp - float(tail[-1].get("timestamp", timestamp) or timestamp)
        loss_ratio = max((float(item.get("loss_ratio_percent", 0.0) or 0.0) for item in samples), default=0.0)
        acceptable = True
        if desired_latency is not None and continuous_exceed > max_exceed_time:
            acceptable = False
        if exceed_ratio > max_exceed_ratio:
            acceptable = False
        if loss_ratio > max_loss_ratio:
            acceptable = False
        result = {
            "acceptable": acceptable,
            "recommended_action": "none" if acceptable else "change_sidepath",
            "should_restart_wg": False,
            "exceed_ratio_percent": round(exceed_ratio, 2),
            "continuous_exceed_seconds": round(continuous_exceed, 2),
            "loss_ratio_percent": round(loss_ratio, 2),
            "samples": samples,
            "last_switch_reason": "latency_policy" if not acceptable else "",
        }
        state[chain_key] = result
        self.save_latency_policy_state(state)
        return result

    @staticmethod
    def candidate_latency_summary(candidate_latencies_ms: dict[str, float | int | None]) -> dict[str, Any]:
        usable: list[tuple[str, float]] = []
        for key, value in candidate_latencies_ms.items():
            if value in (None, ""):
                continue
            usable.append((str(key), float(value)))
        usable.sort(key=lambda item: item[1])
        if not usable:
            return {
                "best_candidate": "",
                "best_latency_ms": None,
                "current_latency_ms": None,
                "spread_ms": None,
            }
        current_latency = None
        for key, value in usable:
            if key == "current":
                current_latency = value
                break
        best_key, best_value = usable[0]
        spread = None if current_latency is None else max(0.0, current_latency - best_value)
        return {
            "best_candidate": best_key,
            "best_latency_ms": best_value,
            "current_latency_ms": current_latency,
            "spread_ms": spread,
        }

    def classify_forward_chain_remediation(
        self,
        chain_key: str,
        policy: dict[str, Any],
        current_sample: dict[str, Any],
        candidate_latencies_ms: dict[str, float | int | None],
    ) -> dict[str, Any]:
        window = self.evaluate_latency_policy_window(chain_key, policy, current_sample)
        summary = self.candidate_latency_summary(candidate_latencies_ms)
        best_candidate = str(summary.get("best_candidate", "") or "")
        best_latency_ms = summary.get("best_latency_ms")
        spread_ms = summary.get("spread_ms")

        decision = dict(window)
        decision.update(
            {
                "action": "observe",
                "selected_candidate": "current",
                "best_candidate": best_candidate,
                "best_latency_ms": best_latency_ms,
                "spread_ms": spread_ms,
            }
        )

        if bool(window.get("acceptable", True)):
            decision["recommended_action"] = "none"
            return decision

        if best_candidate not in {"", "current"} and spread_ms is not None and spread_ms >= 40.0:
            decision["action"] = "change_sidepath"
            decision["selected_candidate"] = best_candidate
            decision["recommended_action"] = "change_sidepath"
            decision["should_restart_wg"] = False
            return decision

        decision["action"] = "escalate_sidecar_upstream"
        decision["selected_candidate"] = "current"
        decision["recommended_action"] = "escalate_sidecar_upstream"
        decision["should_restart_wg"] = False
        return decision

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
        uses_static_sidecar_uplink = bool(underlay.get("uses_static_sidecar_uplink", False))
        if slave_address == "" or client_address == "" or sidecar_address == "" or client_public_key == "":
            return
        if not uses_static_sidecar_uplink and sidecar_public_key == "":
            return
        sidecar_endpoint = ""
        if sidecar_endpoint_host != "" and sidecar_endpoint_port > 0:
            sidecar_endpoint = f"Endpoint = {sidecar_endpoint_host}:{sidecar_endpoint_port}\n"
        sidecar_keepalive = f"PersistentKeepalive = {peer_keepalive_seconds}\n" if peer_keepalive_seconds > 0 else ""
        sidecar_route_up = ""
        sidecar_route_down = ""
        if not uses_static_sidecar_uplink:
            sidecar_route_up = f"PostUp = ip -4 route replace {sidecar_address}/32 dev {interface_name} src {slave_address} initcwnd 80 initrwnd 80\n"
            sidecar_route_down = f"PostDown = ip -4 route del {sidecar_address}/32 dev {interface_name} >/dev/null 2>&1 || true\n"
        config = (
            "[Interface]\n"
            f"PrivateKey = {private_key}\n"
            f"ListenPort = {listen_port}\n"
            f"Address = {slave_address}/32\n"
            f"PostUp = ip -4 route replace {client_address}/32 dev {interface_name} src {slave_address} initcwnd 80 initrwnd 80\n"
            f"{sidecar_route_up}"
            f"PostDown = ip -4 route del {client_address}/32 dev {interface_name} >/dev/null 2>&1 || true\n"
            f"{sidecar_route_down}"
            "PostUp = sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true\n"
            "PostUp = sysctl -w net.ipv6.conf.all.forwarding=1 >/dev/null 2>&1 || true\n"
            "\n"
            "[Peer]\n"
            f"PublicKey = {client_public_key}\n"
            f"AllowedIPs = {client_address}/32\n"
        )
        if not uses_static_sidecar_uplink:
            config += (
                "\n"
                "[Peer]\n"
                f"PublicKey = {sidecar_public_key}\n"
                f"{sidecar_endpoint}"
                f"AllowedIPs = {sidecar_address}/32\n"
                f"{sidecar_keepalive}"
            )
        path = self.wireguard_interface_path(interface_name)
        changed = self.write_text_if_changed(path, config)
        self.ensure_clean_mptcp_underlay(interface_name, underlay)
        expected_peers = [client_public_key] if uses_static_sidecar_uplink else [client_public_key, sidecar_public_key]
        if not changed and not wireguard_peers_match_config(interface_name, expected_peers):
            logging.warning("mptcp underlay %s live peers differ from rendered config; forcing wg-quick reload", interface_name)
            changed = True
        # The client may keep the slave public address out of its WireGuard
        # profile by pointing WG at a local UoT UDP socket even when the
        # declared slave underlay transport remains "udp".  In that shape the
        # TCP fallback port still must be pre-created on the slave and bridged
        # to the real WG UDP listen port; otherwise the client-side local UoT
        # listener accepts packets but its remote TCP dial is refused.
        fallback_tcp_port = int(underlay.get("fallback_tcp_port", 0) or 0)
        client_cf_relay_enabled = truthy(underlay.get("client_cf_relay_enabled", False))
        if fallback_tcp_port > 0 and not client_cf_relay_enabled:
            self.ensure_uot_server_service(interface_name, fallback_tcp_port, listen_port)
        elif client_cf_relay_enabled:
            self.remove_uot_server_service(interface_name)
        if client_cf_relay_enabled:
            self.ensure_mptcp_client_cf_relay_service(service_name, underlay)
        if shutil.which("systemctl"):
            if changed:
                run_command("systemctl daemon-reload", check=False)
            run_command(f"systemctl enable wg-quick@{interface_name}.service", check=False)
            if changed:
                result = run_command(f"systemctl restart wg-quick@{interface_name}.service", check=False)
                detail = str(getattr(result, "stderr", "") or getattr(result, "stdout", "") or "")
                if int(getattr(result, "returncode", 0) or 0) != 0 and is_transient_systemd_error(detail):
                    logging.warning("transient systemd restart failure for %s; reloading directly with wg-quick", interface_name)
                    run_command(f"wg-quick down {interface_name}", check=False)
                    run_command(f"ip link delete dev {interface_name}", check=False)
                    run_command(f"wg-quick up {interface_name}")
                elif int(getattr(result, "returncode", 0) or 0) != 0:
                    raise RuntimeError(
                        f"command failed: systemctl restart wg-quick@{interface_name}.service\n"
                        f"stdout={getattr(result, 'stdout', '')}\n"
                        f"stderr={getattr(result, 'stderr', '')}"
                    )
        # Start the pre-connect relay if configured (slave_preconnect mode)
        if underlay.get("slave_preconnect"):
            self.ensure_preconnect_relay(service_name, underlay)

    def ensure_mptcp_client_cf_relay_service(self, service_name: str, underlay: dict[str, Any]) -> None:
        interface_name = self.mptcp_underlay_interface_name(service_name, underlay)
        listen_port = int(underlay.get("client_cf_relay_listen_port", 0) or 0)
        path_prefix = str(underlay.get("client_cf_relay_path_prefix", "") or "").strip().strip("/")
        target_host = str(underlay.get("client_cf_relay_target_host", "127.0.0.1") or "127.0.0.1").strip()
        target_port = int(underlay.get("client_cf_relay_target_port", 0) or 0)
        if listen_port <= 0 or target_port <= 0 or path_prefix == "":
            return
        relay_service = f"leikwan-mptcp-client-cf-{interface_name}"
        existing = self.load_service_metadata(relay_service) or {}
        backend_port = int(existing.get("backend_port", 0) or 0)
        if self.backend_port_needs_reallocation(relay_service, backend_port):
            backend_port = self.allocate_backend_port(relay_service, {listen_port})

        unit_body = self.render_backend_unit(relay_service, backend_port, f"{target_host}:{target_port}", path_prefix)
        unit_changed = self.write_text_if_changed(self.service_unit_path(relay_service), unit_body)
        self.ensure_backend_service(relay_service, unit_changed, expected_exec_substring="wstunnel server")
        self.save_service_metadata(
            relay_service,
            {
                "service_name": relay_service,
                "service_id": int(underlay.get("service_id", 0) or 0),
                "forward_id": int(underlay.get("forward_id", 0) or 0),
                "endpoint_id": int(underlay.get("endpoint_id", 0) or 0),
                "listen_port": listen_port,
                "backend_port": backend_port,
                "target": f"{target_host}:{target_port}",
                "path_prefix": path_prefix,
                "route_key": path_prefix,
                "ingress_protocol": "wss_path_mux",
                "backend_protocol": "wss_backend",
                "transport_mode": "udp",
                "mptcp_underlay": {},
                "status": "active",
                "mptcp_client_cf_relay_parent": service_name,
            },
        )
        self.sync_listener(listen_port)

    # ---- Slave Pre-Connect MPTCP Relay ----

    def preconnect_relay_service_name(self, service_name: str) -> str:
        return f"leikwan-preconnect-{service_name.replace('wstunnel-svc-', '')}"

    def preconnect_relay_config_path(self, service_name: str) -> Path:
        return self.state_dir / f"preconnect-{service_name.replace('wstunnel-svc-', '')}.json"

    def preconnect_relay_bin_path(self) -> str:
        # Prefer co-located script next to slave_agent.py
        candidate = Path(__file__).parent / "preconnect_relay.py"
        if candidate.is_file():
            return str(candidate)
        return "/usr/local/bin/leikwan-preconnect-relay.py"

    def ensure_preconnect_relay(self, service_name: str, underlay: dict[str, Any]) -> dict[str, Any]:
        """Start/update the pre-connect MPTCP relay for a published service.

        The relay listens on the slave WG address and relays client TCP
        connections to the sidecar via MPTCP. This moves MPTCP session
        management from the client to the slave.
        """
        if not underlay.get("slave_preconnect"):
            return {}

        slave_address = str(underlay.get("slave_address", "") or "").strip()
        sidecar_address = str(underlay.get("sidecar_address", "") or "").strip()
        relay_port = int(underlay.get("preconnect_relay_port", 0) or 0)
        sidecar_port = int(underlay.get("sidecar_mptcp_port", 0) or 0)
        session_token = str(underlay.get("tcp_session_token", "") or "").strip()
        forward_id = int(underlay.get("forward_id", 0) or 0)

        if slave_address == "" or sidecar_address == "" or relay_port <= 0 or sidecar_port <= 0:
            logging.warning("preconnect relay: missing required fields for %s", service_name)
            return {}

        relay_service = self.preconnect_relay_service_name(service_name)
        config_path = self.preconnect_relay_config_path(service_name)
        relay_bin = self.preconnect_relay_bin_path()

        # Write relay config
        relay_config = {
            "listen_host": slave_address,
            "listen_port": relay_port,
            "health_port": relay_port + 1,
            "sidecar_host": sidecar_address,
            "sidecar_port": sidecar_port,
            "tcp_session_token": session_token,
            "udp_session_token": str(underlay.get("udp_session_token", session_token) or session_token),
            "forward_id": forward_id,
            "transport": str(underlay.get("transport", "tcp") or "tcp"),
            "max_connections": int(underlay.get("preconnect_max_connections", 256) or 256),
            "connect_timeout_s": int(underlay.get("preconnect_connect_timeout_s", 5) or 5),
            "tcp_congestion": str(underlay.get("tcp_congestion", "bbr") or "bbr"),
            "socket_buffer_bytes": int(underlay.get("socket_buffer_bytes", 8 * 1024 * 1024) or 8 * 1024 * 1024),
            "log_level": str(self.config.get("log_level", "INFO")),
        }
        config_changed = self.write_text_if_changed(
            config_path,
            json.dumps(relay_config, ensure_ascii=False, indent=2) + "\n",
        )

        # Write systemd unit
        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system"))) / f"{relay_service}.service"
        unit_body = (
            f"[Unit]\n"
            f"Description=Leikwan Pre-Connect MPTCP Relay ({service_name})\n"
            f"After=network.target\n"
            f"\n"
            f"[Service]\n"
            f"Type=simple\n"
            f"ExecStart=/usr/bin/python3 {relay_bin} --config {config_path}\n"
            f"Restart=always\n"
            f"RestartSec=3\n"
            f"StandardOutput=journal\n"
            f"StandardError=journal\n"
            f"\n"
            f"[Install]\n"
            f"WantedBy=multi-user.target\n"
        )
        unit_changed = self.write_text_if_changed(unit_path, unit_body)

        if shutil.which("systemctl"):
            if unit_changed or config_changed:
                run_command("systemctl daemon-reload", check=False)
            run_systemctl_tolerating_transient(f"systemctl enable {relay_service}.service")
            if unit_changed or config_changed:
                run_systemctl_tolerating_transient(f"systemctl restart {relay_service}.service")
            elif not self.is_service_active(relay_service):
                run_systemctl_tolerating_transient(f"systemctl start {relay_service}.service")

        logging.info(
            "preconnect relay %s: listen=%s:%d sidecar=%s:%d forward_id=%d",
            relay_service, slave_address, relay_port, sidecar_address, sidecar_port, forward_id,
        )
        return {
            "relay_service": relay_service,
            "relay_host": slave_address,
            "relay_port": relay_port,
            "health_port": relay_port + 1,
        }

    def stop_preconnect_relay(self, service_name: str) -> None:
        """Stop and disable the pre-connect relay for a service."""
        relay_service = self.preconnect_relay_service_name(service_name)
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {relay_service}.service", check=False)
            run_command(f"systemctl disable {relay_service}.service", check=False)
            run_command(f"systemctl reset-failed {relay_service}.service", check=False)

        unit_path = Path(str(self.config.get("systemd_dir", "/etc/systemd/system"))) / f"{relay_service}.service"
        if unit_path.exists():
            unit_path.unlink()

        config_path = self.preconnect_relay_config_path(service_name)
        if config_path.exists():
            config_path.unlink()

    def is_service_active(self, service_name: str) -> bool:
        if not shutil.which("systemctl"):
            return False
        result = run_command(f"systemctl is-active {service_name}.service", check=False)
        return str(getattr(result, "stdout", "") or "").strip() == "active"

    # ---- End Pre-Connect Relay ----

    def remove_mptcp_underlay(self, service_name: str, underlay: dict[str, Any] | None = None) -> None:
        interface_name = self.mptcp_underlay_interface_name(service_name, underlay)
        self.stop_preconnect_relay(service_name)
        self.remove_mptcp_client_cf_relay_service(service_name, underlay)
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

    def remove_mptcp_client_cf_relay_service(self, service_name: str, underlay: dict[str, Any] | None = None) -> None:
        interface_name = self.mptcp_underlay_interface_name(service_name, underlay)
        relay_service = f"leikwan-mptcp-client-cf-{interface_name}"
        metadata = self.load_service_metadata(relay_service)
        public_port = int((metadata or {}).get("listen_port", 0) or 0)
        if shutil.which("systemctl"):
            run_command(f"systemctl stop {relay_service}.service", check=False)
            run_command(f"systemctl disable {relay_service}.service", check=False)
            run_command(f"systemctl reset-failed {relay_service}.service", check=False)
        unit_path = self.service_unit_path(relay_service)
        if unit_path.exists():
            unit_path.unlink()
        metadata_path = self.metadata_path(relay_service)
        if metadata_path.exists():
            metadata_path.unlink()
        if public_port > 0:
            self.sync_listener(public_port)

    def ensure_clean_mptcp_underlay(self, interface_name: str, underlay: dict[str, Any]) -> None:
        normalized = str(interface_name or "").strip()[:15]
        if normalized == "":
            return
        if shutil.which("systemctl"):
            run_command(f"systemctl stop wg-quick@{normalized}.service", check=False)
            run_command(f"systemctl reset-failed wg-quick@{normalized}.service", check=False)
        if shutil.which("wg-quick"):
            run_command(f"wg-quick down {normalized}", check=False)
        if shutil.which("ip"):
            run_command(f"ip link delete dev {normalized}", check=False)

        desired_addresses = {
            str(underlay.get("slave_address", "") or "").strip(),
            str(underlay.get("client_address", "") or "").strip(),
            str(underlay.get("sidecar_address", "") or "").strip(),
        }
        desired_addresses = {item for item in desired_addresses if item != ""}
        if desired_addresses and shutil.which("ip"):
            address_dump = run_command(["ip", "-o", "-4", "addr", "show"], check=False)
            for raw_line in str(address_dump.stdout or "").splitlines():
                parts = raw_line.split()
                if len(parts) < 4:
                    continue
                iface = parts[1]
                cidr = parts[3]
                address = cidr.split("/", 1)[0].strip()
                if address not in desired_addresses or iface == normalized:
                    continue
                if iface == "lo":
                    continue
                logging.warning("removing conflicting interface %s holding %s for %s", iface, address, normalized)
                if shutil.which("systemctl"):
                    run_command(f"systemctl stop wg-quick@{iface}.service", check=False)
                    run_command(f"systemctl disable wg-quick@{iface}.service", check=False)
                    run_command(f"systemctl reset-failed wg-quick@{iface}.service", check=False)
                if shutil.which("wg-quick"):
                    run_command(f"wg-quick down {iface}", check=False)
                run_command(f"ip link delete dev {iface}", check=False)

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
            self.remove_mptcp_client_cf_relay_service(iface, {"interface_name": iface})
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

    def cleanup_orphan_sidecar_uplinks(self, section_present: bool = True) -> None:
        # BUG-1: the orphan sweep derives its "active set" from saved state and
        # tears down any live lws* interface/unit not in that set. It must only
        # run when this cycle actually carried an authoritative static-uplink
        # section. If the section was absent (or the management fetch failed),
        # skip the sweep entirely -- otherwise a transiently-omitted section
        # would orphan-wipe every live lane.
        if not section_present:
            return
        previous = self.load_static_sidecar_uplink_state()
        previous_interfaces = previous.get("interfaces", {}) if isinstance(previous.get("interfaces", {}), dict) else {}
        active_interfaces = {
            str((info if isinstance(info, dict) else {}).get("interface_name", iface) or "").strip()[:15]
            for iface, info in previous_interfaces.items()
        }
        active_interfaces = {iface for iface in active_interfaces if iface}
        self.prune_sidecar_uplink_selectors(active_interfaces)

        if not shutil.which("wg"):
            return

        result = run_command("wg show interfaces", check=False)
        for iface in str(getattr(result, "stdout", "") or "").strip().split():
            if not iface.startswith("lws"):
                continue
            if iface in active_interfaces:
                continue
            logging.info("removing orphan static sidecar uplink interface: %s", iface)
            self.remove_sidecar_uplink(iface, {"interface_name": iface})

        if shutil.which("systemctl"):
            unit_result = run_command(
                "systemctl list-units 'wg-quick@lws*' 'leikwan-wgcf-lws*' 'leikwan-gost-lws*' 'leikwan-lguot-lws*' '*-sidecar-selector.service' --type=service --all --no-legend --no-pager",
                check=False,
            )
            for line in str(getattr(unit_result, "stdout", "") or "").splitlines():
                unit_name = line.strip().split()[0] if line.strip() else ""
                if unit_name == "":
                    continue
                wg_match = re.match(r"^wg-quick@(lws[^.]+)\.service$", unit_name)
                cf_match = re.match(r"^leikwan-wgcf-(lws[^.]+)\.service$", unit_name)
                gost_match = re.match(r"^leikwan-gost-(lws[^.]+)\.service$", unit_name)
                lguot_match = re.match(r"^leikwan-lguot-(lws[^.]+)\.service$", unit_name)
                selector_match = re.match(r"^(.+)-sidecar-selector\.service$", unit_name)
                if wg_match is not None:
                    iface = wg_match.group(1)
                    if iface not in active_interfaces:
                        run_command(f"systemctl stop {unit_name}", check=False)
                        run_command(f"systemctl disable {unit_name}", check=False)
                        run_command(f"systemctl reset-failed {unit_name}", check=False)
                elif cf_match is not None:
                    iface = cf_match.group(1)
                    if iface not in active_interfaces:
                        run_command(f"systemctl stop {unit_name}", check=False)
                        run_command(f"systemctl disable {unit_name}", check=False)
                        run_command(f"systemctl reset-failed {unit_name}", check=False)
                elif gost_match is not None:
                    iface = gost_match.group(1)
                    if iface not in active_interfaces:
                        run_command(f"systemctl stop {unit_name}", check=False)
                        run_command(f"systemctl disable {unit_name}", check=False)
                        run_command(f"systemctl reset-failed {unit_name}", check=False)
                elif lguot_match is not None:
                    iface = lguot_match.group(1)
                    if iface not in active_interfaces:
                        run_command(f"systemctl stop {unit_name}", check=False)
                        run_command(f"systemctl disable {unit_name}", check=False)
                        run_command(f"systemctl reset-failed {unit_name}", check=False)
                elif selector_match is not None:
                    service_name = selector_match.group(1)
                    state_path = self.sidecar_uplink_selector_config_path(service_name)
                    if not state_path.exists():
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
    selector_parser = subparsers.add_parser("sidecar-selector")
    selector_parser.add_argument("--service-name", required=True)
    selector_parser.add_argument("--selector-file", required=True)

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
    if args.command == "sidecar-selector":
        selector = json.loads(Path(args.selector_file).read_text(encoding="utf-8"))
        if not isinstance(selector, dict):
            raise RuntimeError("selector file must contain a JSON object")
        try:
            asyncio.run(SidecarUplinkSelectorProxy(agent, args.service_name, selector).serve())
        except KeyboardInterrupt:
            return 0
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())
