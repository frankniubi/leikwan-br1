#!/usr/bin/env python3
from __future__ import annotations

import argparse
import datetime
import email.message
import email.utils
import contextlib
import hashlib
import hmac
import http.client
import importlib.util
import io
import json
import ipaddress
import logging
import os
import pathlib
import platform
import random
import re
import shutil
import socket
import ssl
import struct
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

AGENT_VERSION = "2026.08.04.1"
UPDATE_RETRY_SECONDS = 300
CLIENT_BOND_ENDPOINT_PROBATION_SECONDS = 60.0
CLIENT_BOND_ENDPOINT_QUARANTINE_SECONDS = 30.0 * 60.0
UPDATE_STALE_SECONDS = 900
INVALID_FORWARD_LOG_INTERVAL_SECONDS = 600
SELF_LEG_FAST_PROBE_CACHE_TTL_SECONDS = 60.0
SELF_LEG_FAST_PROBE_CACHE_MAX_LATENCY_MS = 80.0
DEFAULT_MANAGED_CONFIG = {
    "state_dir": "/var/lib/leikwan-client-agent",
    "poll_interval_seconds": 30,
    "health_check_interval_seconds": 20,
    "log_level": "INFO",
    "wireguard_listen_port": 0,
    "wireguard_fallback_tcp_port": 0,
}
MPTCP_PROTOCOL = getattr(socket, "IPPROTO_MPTCP", 262)
MPTCP_FALSE_VALUES = {"", "0", "false", "no", "off", "disabled"}
HELPER_CANDIDATE_KINDS = {"rented_group_leg", "donated_slave_leg"}  # donor_candidate_consumed_as_helper
STRICT_DOH_PROVIDERS = [
    ("AliDNS", "https://dns.alidns.com/dns-query", ("223.5.5.5", "223.6.6.6")),
    ("DNSPod", "https://doh.pub/dns-query", ("1.12.12.12", "120.53.53.53")),
    ("360", "https://doh.360.cn/dns-query", ("101.226.4.6", "218.30.118.6")),
]
STRICT_DOH_CACHE_TTL_SECONDS = 60.0
LEIKWAN_PROTECTED_SUFFIX = ".leikwanhost.com"
LEIKWAN_PROTECTED_APEX = "leikwanhost.com"


class StrictDoHError(RuntimeError):
    pass


class StrictDoHResolver:
    def __init__(self, ttl_seconds: float = STRICT_DOH_CACHE_TTL_SECONDS):
        self.ttl_seconds = ttl_seconds
        self._cache: dict[str, tuple[float, list[str]]] = {}

    def resolve_a(self, host: str) -> list[str]:
        normalized = normalize_dns_host(host)
        now = time.time()
        cached = self._cache.get(normalized)
        if cached is not None and cached[0] > now:
            return list(cached[1])
        errors: list[str] = []
        for provider_name, provider_url, bootstrap_ips in STRICT_DOH_PROVIDERS:
            for bootstrap_ip in bootstrap_ips:
                try:
                    addresses = resolve_a_via_doh_provider(normalized, provider_url, bootstrap_ip)
                except Exception as exc:  # noqa: BLE001 - strict fallback tries the next China-first provider/IP.
                    errors.append(f"{provider_name}@{bootstrap_ip}: {exc}")
                    continue
                if addresses:
                    self._cache[normalized] = (now + self.ttl_seconds, list(addresses))
                    return list(addresses)
                errors.append(f"{provider_name}@{bootstrap_ip}: no A records")
        raise StrictDoHError(f"strict DoH failed for {normalized}: {'; '.join(errors)}")


_STRICT_DOH_RESOLVER = StrictDoHResolver()


def normalize_dns_host(host: str) -> str:
    normalized = host.strip().rstrip(".").lower()
    if not normalized:
        raise StrictDoHError("empty host")
    try:
        normalized.encode("ascii")
    except UnicodeEncodeError as exc:
        raise StrictDoHError(f"non-ascii host is not supported: {host}") from exc
    return normalized


def host_is_ip_literal(host: str) -> bool:
    try:
        ipaddress.ip_address(host.strip("[]"))
        return True
    except ValueError:
        return False


def host_is_private_or_loopback_literal(host: str) -> bool:
    try:
        address = ipaddress.ip_address(host.strip("[]"))
    except ValueError:
        return False
    return bool(address.is_private or address.is_loopback or address.is_link_local)


def is_protected_leikwan_host(host: str) -> bool:
    normalized = host.strip().strip("[]").rstrip(".").lower()
    if normalized in {"localhost", "ip6-localhost", "ip6-loopback"}:
        return False
    if host_is_ip_literal(normalized):
        return False
    return normalized == LEIKWAN_PROTECTED_APEX or normalized.endswith(LEIKWAN_PROTECTED_SUFFIX)


def is_protected_leikwan_url(url: str) -> bool:
    parsed = urllib.parse.urlparse(url)
    if parsed.scheme not in {"http", "https"}:
        return False
    host = parsed.hostname or ""
    if not is_protected_leikwan_host(host):
        return False
    if host_is_private_or_loopback_literal(host):
        return False
    return True


def build_dns_a_query(host: str, query_id: int | None = None) -> tuple[bytes, int]:
    normalized = normalize_dns_host(host)
    if query_id is None:
        query_id = int.from_bytes(os.urandom(2), "big")
    parts = normalized.split(".")
    question = bytearray()
    for part in parts:
        encoded = part.encode("ascii")
        if len(encoded) == 0 or len(encoded) > 63:
            raise StrictDoHError(f"invalid DNS label in {host}")
        question.append(len(encoded))
        question.extend(encoded)
    question.append(0)
    question.extend(struct.pack("!HH", 1, 1))  # A, IN
    header = struct.pack("!HHHHHH", query_id, 0x0100, 1, 0, 0, 0)
    return header + bytes(question), query_id


def _skip_dns_name(message: bytes, offset: int) -> int:
    jumped = False
    seen = 0
    while True:
        if offset >= len(message):
            raise StrictDoHError("truncated DNS name")
        length = message[offset]
        if length & 0xC0 == 0xC0:
            if offset + 1 >= len(message):
                raise StrictDoHError("truncated DNS pointer")
            offset += 2
            jumped = True
            return offset
        if length & 0xC0:
            raise StrictDoHError("unsupported DNS label type")
        offset += 1
        if length == 0:
            return offset
        offset += length
        seen += 1
        if offset > len(message) or seen > 128:
            raise StrictDoHError("invalid DNS name")
        if jumped:
            return offset


def parse_dns_a_response(message: bytes, query_id: int) -> list[str]:
    if len(message) < 12:
        raise StrictDoHError("truncated DNS response")
    resp_id, flags, qdcount, ancount, _nscount, _arcount = struct.unpack("!HHHHHH", message[:12])
    if resp_id != query_id:
        raise StrictDoHError("DNS response id mismatch")
    if flags & 0x8000 == 0:
        raise StrictDoHError("DNS response is not a response")
    rcode = flags & 0x000F
    if rcode != 0:
        raise StrictDoHError(f"DNS response error rcode={rcode}")
    offset = 12
    for _ in range(qdcount):
        offset = _skip_dns_name(message, offset)
        if offset + 4 > len(message):
            raise StrictDoHError("truncated DNS question")
        offset += 4
    addresses: list[str] = []
    for _ in range(ancount):
        offset = _skip_dns_name(message, offset)
        if offset + 10 > len(message):
            raise StrictDoHError("truncated DNS answer")
        rtype, rclass, _ttl, rdlength = struct.unpack("!HHIH", message[offset : offset + 10])
        offset += 10
        if offset + rdlength > len(message):
            raise StrictDoHError("truncated DNS answer data")
        rdata = message[offset : offset + rdlength]
        offset += rdlength
        if rtype == 1 and rclass == 1 and rdlength == 4:
            addresses.append(socket.inet_ntoa(rdata))
    return addresses


def resolve_a_via_doh_provider(host: str, provider_url: str, bootstrap_ip: str, timeout: float = 5.0) -> list[str]:
    query, query_id = build_dns_a_query(host)
    parsed = urllib.parse.urlparse(provider_url)
    if parsed.scheme != "https" or not parsed.hostname:
        raise StrictDoHError(f"unsupported DoH provider URL: {provider_url}")
    port = parsed.port or 443
    path = parsed.path or "/dns-query"
    if parsed.query:
        path = f"{path}?{parsed.query}"
    conn = ResolvedHTTPSConnection(parsed.hostname, port=port, timeout=timeout, resolved_ip=bootstrap_ip)
    try:
        conn.request(
            "POST",
            path,
            body=query,
            headers={
                "Host": parsed.netloc,
                "Accept": "application/dns-message",
                "Content-Type": "application/dns-message",
                "Content-Length": str(len(query)),
                "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
            },
        )
        response = conn.getresponse()
        body = response.read()
        if response.status != 200:
            raise StrictDoHError(f"DoH HTTP {response.status}")
        return parse_dns_a_response(body, query_id)
    finally:
        conn.close()


class ResolvedHTTPConnection(http.client.HTTPConnection):
    def __init__(self, host: str, port: int | None = None, timeout=socket._GLOBAL_DEFAULT_TIMEOUT, source_address=None, *, resolved_ip: str):
        super().__init__(host, port=port, timeout=timeout, source_address=source_address)
        self.resolved_ip = resolved_ip

    def connect(self) -> None:
        self.sock = socket.create_connection((self.resolved_ip, self.port), self.timeout, self.source_address)
        if self._tunnel_host:
            self._tunnel()


class ResolvedHTTPSConnection(http.client.HTTPSConnection):
    def __init__(
        self,
        host: str,
        port: int | None = None,
        timeout=socket._GLOBAL_DEFAULT_TIMEOUT,
        source_address=None,
        *,
        resolved_ip: str,
        context: ssl.SSLContext | None = None,
    ):
        super().__init__(host, port=port, timeout=timeout, source_address=source_address, context=context)
        self.resolved_ip = resolved_ip

    def connect(self) -> None:
        sock = socket.create_connection((self.resolved_ip, self.port), self.timeout, self.source_address)
        if self._tunnel_host:
            self.sock = sock
            self._tunnel()
            sock = self.sock
        # Strict DoH preserves normal certificate verification and SNI for the original host;
        # it only bypasses poisoned system DNS by dialing the resolved IPv4 address.
        self.sock = self._context.wrap_socket(sock, server_hostname=self.host)


class _HTTPClientResponseAdapter:
    def __init__(self, response: http.client.HTTPResponse):
        self._response = response
        self.status = response.status
        self.code = response.status
        self.reason = response.reason
        self.headers = response.headers

    def read(self, *args, **kwargs) -> bytes:
        return self._response.read(*args, **kwargs)

    def close(self) -> None:
        self._response.close()

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb) -> bool:
        self.close()
        return False


def _request_headers_for_http_client(req: urllib.request.Request, host_header: str) -> dict[str, str]:
    headers: dict[str, str] = {}
    for key, value in req.header_items():
        if key.lower() not in {"host", "connection"}:
            headers[key] = value
    headers["Host"] = host_header
    headers["Connection"] = "close"
    return headers


def urlopen_with_leikwan_doh(req: urllib.request.Request, timeout: float = 30, resolver: StrictDoHResolver | None = None):
    url = req.full_url
    if not is_protected_leikwan_url(url):
        return urllib.request.urlopen(req, timeout=timeout)
    resolver = resolver or _STRICT_DOH_RESOLVER
    parsed = urllib.parse.urlparse(url)
    host = parsed.hostname or ""
    addresses = resolver.resolve_a(host)
    if not addresses:
        raise urllib.error.URLError(f"strict DoH returned no A records for {host}")
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    target = urllib.parse.urlunparse(("", "", parsed.path or "/", parsed.params, parsed.query, ""))
    host_header = host
    if parsed.port is not None:
        host_header = f"{host}:{parsed.port}"
    data = req.data
    headers = _request_headers_for_http_client(req, host_header)
    if data is not None and "Content-Length" not in headers:
        headers["Content-Length"] = str(len(data))

    last_error: Exception | None = None
    for resolved_ip in addresses:
        if parsed.scheme == "https":
            conn = ResolvedHTTPSConnection(host, port=port, timeout=timeout, resolved_ip=resolved_ip)
        else:
            conn = ResolvedHTTPConnection(host, port=port, timeout=timeout, resolved_ip=resolved_ip)
        try:
            conn.request(req.get_method(), target, body=data, headers=headers)
            response = conn.getresponse()
            if response.status >= 400:
                body = response.read()
                message = email.message.Message()
                for key, value in response.headers.items():
                    message[key] = value
                raise urllib.error.HTTPError(url, response.status, response.reason, message, io.BytesIO(body))
            return _HTTPClientResponseAdapter(response)
        except urllib.error.HTTPError:
            conn.close()
            raise
        except Exception as exc:
            last_error = exc
            conn.close()

    raise urllib.error.URLError(f"strict DoH connection failed for {host}: {last_error}")


def _urlopen_for_request(req: urllib.request.Request, timeout: float = 30):
    proxy_url = next(
        (os.environ.get(name, "").strip() for name in ("HTTPS_PROXY", "https_proxy", "HTTP_PROXY", "http_proxy") if os.environ.get(name, "").strip()),
        "",
    )
    if proxy_url:
        return urllib.request.urlopen(req, timeout=timeout)
    if is_protected_leikwan_url(req.full_url):
        return urlopen_with_leikwan_doh(req, timeout=timeout)
    return urllib.request.urlopen(req, timeout=timeout)


def http_json(
    method: str,
    url: str,
    token: str,
    payload: dict | None = None,
    *,
    extra_headers: dict[str, str] | None = None,
    timeout: float = 30,
) -> dict:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
    }
    if extra_headers:
        headers.update(extra_headers)
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with _urlopen_for_request(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"http {exc.code} {url}: {body}") from exc


def http_json_with_headers(
    method: str,
    url: str,
    token: str,
    payload: dict | None = None,
    *,
    extra_headers: dict[str, str] | None = None,
    timeout: float = 30,
) -> tuple[dict, dict[str, str]]:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
    }
    if extra_headers:
        headers.update(extra_headers)
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with _urlopen_for_request(req, timeout=timeout) as resp:
            content = resp.read().decode("utf-8")
            response_headers = {key.lower(): value for key, value in resp.headers.items()}
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"http {exc.code} {url}: {body}") from exc
    return json.loads(content), response_headers


def file_sha256(path: pathlib.Path) -> str:
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


class ClientAgent:
    def __init__(
        self,
        config_path: pathlib.Path,
        *,
        http_client=None,
        clock=None,
        jitter=None,
        stop_event=None,
        config_changed_event=None,
        command_runner=None,
    ):
        self.config_path = config_path
        self.config = json.loads(config_path.read_text(encoding="utf-8-sig"))
        self.state_dir = pathlib.Path(self.config.get("state_dir", "/var/lib/leikwan-client-agent"))
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.applied_path = self.state_dir / "applied.json"
        self.pull_state_path = self.state_dir / "config_pull_state.json"
        self.usage_state_path = self.state_dir / "usage_state.json"
        self.latest_forwards: list[dict] = []
        self.health_restart_after: dict[int, float] = {}
        self.health_reorder_after: dict[int, float] = {}
        self.invalid_forward_log_after: dict[int, float] = {}
        self.self_leg_probe_cache: dict[str, dict] = {}
        self._cfwg_latency_watchdog = None
        self._http_client = http_client
        self._clock = clock or time.time
        self._jitter = jitter or random.uniform
        self.stop_event = stop_event or threading.Event()
        self.config_changed_event = config_changed_event or threading.Event()
        self.command_runner = command_runner
        self.observed_config_revision = 0
        self.config_watch_thread = None
        self.latest_linux_path_leases: dict = {"lines": []}
        self.latest_pathd_metadata: dict[str, str] = {}
        logging.basicConfig(
            level=getattr(logging, str(self.config.get("log_level", "INFO")).upper(), logging.INFO),
            format="%(asctime)s %(levelname)s %(message)s",
        )

    @staticmethod
    def write_text_if_changed(path: pathlib.Path, content: str, mode: int | None = None) -> bool:
        existing = None
        if path.exists():
            try:
                existing = path.read_text(encoding="utf-8")
            except OSError:
                existing = None

        changed = existing != content
        if changed:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding="utf-8")

        if mode is not None and path.exists() and os.name != "nt":
            current_mode = path.stat().st_mode & 0o777
            if current_mode != mode:
                path.chmod(mode)
                changed = True

        return changed

    @staticmethod
    def detect_time_service() -> str:
        services = [
            ("ntp.service", "ntp"),
            ("ntpd.service", "ntpd"),
            ("chronyd.service", "chronyd"),
            ("systemd-timesyncd.service", "systemd-timesyncd"),
        ]
        if not shutil.which("systemctl"):
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

    def time_sync_state(self) -> dict:
        if not shutil.which("timedatectl"):
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

    def panel_clock_state_from_headers(self, headers: dict[str, str]) -> dict:
        local_state = self.time_sync_state()
        date_header = str(headers.get("date", "") or "").strip()
        if date_header == "":
            return {
                "status": "unknown",
                "offset_seconds": None,
                "panel_time": "",
                "service": local_state.get("service", ""),
                "synchronized": local_state.get("synchronized"),
            }
        try:
            panel_dt = email.utils.parsedate_to_datetime(date_header)
            offset_seconds = round(time.time() - panel_dt.timestamp(), 3)
        except Exception:  # noqa: BLE001
            return {
                "status": "unknown",
                "offset_seconds": None,
                "panel_time": date_header,
                "service": local_state.get("service", ""),
                "synchronized": local_state.get("synchronized"),
            }
        absolute_offset = abs(offset_seconds)
        status = "ok" if absolute_offset <= 3 else "warning" if absolute_offset <= 10 else "error"
        return {
            "status": status,
            "offset_seconds": offset_seconds,
            "panel_time": panel_dt.isoformat(),
            "service": local_state.get("service", ""),
            "synchronized": local_state.get("synchronized"),
        }

    @staticmethod
    def normalize_mode(value: object) -> str:
        mode = str(value or "").strip().lower()
        if mode in {"race", "failover", "multi-path", "lb"}:
            return "race"
        if mode in {"bond", "bonding", "aggregate", "aggregated"}:
            return "bond"
        if mode in {"mptcp", "mptcp-wireguard", "mptcp-ipsec", "multipath-tcp"}:
            return "mptcp"
        return "single"

    @classmethod
    def sidecar_frontend_mode(cls, forward: dict) -> str:
        """Return the explicitly published single/race sidecar frontend mode."""
        if not isinstance(forward, dict):
            return ""
        bonding = forward.get("bonding", {})
        if not isinstance(bonding, dict):
            return ""
        frontend_mode = str(bonding.get("frontend_mode", "") or "").strip().lower()
        if frontend_mode not in {"single", "race"}:
            return ""
        if cls.contract_bool(forward.get("sidecar_required")) is not True:
            return ""
        if not cls.forward_contract_is_sidecar_aggregation(forward):
            return ""
        return frontend_mode

    @classmethod
    def forward_uses_bond_runtime(cls, forward: dict) -> bool:
        if not isinstance(forward, dict):
            return False
        if cls.normalize_mode(forward.get("mode", "single")) == "bond":
            return True
        return cls.sidecar_frontend_mode(forward) != ""

    @staticmethod
    def binary_available(path: pathlib.Path) -> bool:
        return path.is_file() and (os.name == "nt" or os.access(path, os.X_OK))

    def bond_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("bond_bin", "/usr/local/bin/leikwan-bond") or "/usr/local/bin/leikwan-bond")
        return pathlib.Path(raw)

    def mptcp_client_path(self) -> pathlib.Path:
        raw = str(self.config.get("mptcp_client_bin", "/usr/local/bin/leikwan-mptcp-client.py") or "/usr/local/bin/leikwan-mptcp-client.py")
        return pathlib.Path(raw)

    @staticmethod
    def mptcp_capability() -> dict:
        sysctl_path = pathlib.Path("/proc/sys/net/mptcp/enabled")
        sysctl_present = sysctl_path.exists()
        sysctl_value = ""
        sysctl_enabled = False
        if sysctl_present:
            try:
                sysctl_value = sysctl_path.read_text(encoding="utf-8").strip().lower()
            except OSError:
                sysctl_value = ""
            sysctl_enabled = sysctl_value not in MPTCP_FALSE_VALUES

        socket_supported = False
        socket_error = ""
        if os.name != "nt":
            try:
                with socket.socket(socket.AF_INET, socket.SOCK_STREAM, MPTCP_PROTOCOL):
                    socket_supported = True
            except OSError as exc:
                socket_error = str(exc)

        supported = socket_supported and (sysctl_enabled or not sysctl_present)
        if supported:
            reason = "mptcp_socket_available"
        elif sysctl_present and not sysctl_enabled:
            reason = f"net.mptcp.enabled={sysctl_value or 'unset'}"
        elif socket_error:
            reason = socket_error
        else:
            reason = "mptcp_socket_unavailable"

        return {
            "supported": supported,
            "sysctl_present": sysctl_present,
            "sysctl_enabled": sysctl_enabled,
            "sysctl_value": sysctl_value,
            "socket_supported": socket_supported,
            "protocol": MPTCP_PROTOCOL,
            "reason": reason,
        }

    @classmethod
    def running_kernel_supports_mptcp(cls) -> bool:
        return bool(cls.mptcp_capability().get("supported", False))

    @staticmethod
    def mptcp_tcp_fallback_allowed(mptcp: dict) -> bool:
        if not isinstance(mptcp, dict):
            return True
        return str(mptcp.get("allow_tcp_fallback", True)).strip().lower() in {"1", "true", "yes", "on", "", "none"}

    def wireguard_dir(self) -> pathlib.Path:
        path = self.state_dir / "wireguard"
        path.mkdir(parents=True, exist_ok=True)
        return path

    def wireguard_private_key_path(self) -> pathlib.Path:
        return self.wireguard_dir() / "private.key"

    def wireguard_public_key_path(self) -> pathlib.Path:
        return self.wireguard_dir() / "public.key"

    def wireguard_listen_port(self) -> int:
        configured = int(self.config.get("wireguard_listen_port", 0) or 0)
        if configured > 0:
            return configured
        client_id = int(self.config.get("client_id", 0) or 0)
        return 43000 + (client_id % 1000)

    def wireguard_fallback_tcp_port(self) -> int:
        configured = int(self.config.get("wireguard_fallback_tcp_port", 0) or 0)
        if configured > 0:
            return configured
        return self.wireguard_listen_port() + 10000

    @staticmethod
    def install_forward_runtime_path() -> pathlib.Path:
        return pathlib.Path(__file__).with_name("install_forward_runtime.py")

    def semantic_agent_config(self) -> dict:
        payload = dict(self.config)
        payload["wireguard_listen_port"] = self.wireguard_listen_port()
        payload["wireguard_fallback_tcp_port"] = self.wireguard_fallback_tcp_port()
        payload["install_forward_runtime_sha256"] = file_sha256(self.install_forward_runtime_path())
        payload["mptcp_client_sha256"] = file_sha256(self.mptcp_client_path())
        payload.setdefault("installer_sha256", str(self.config.get("installer_sha256", "") or ""))
        payload["kernel_release"] = platform.release()
        return payload

    @classmethod
    def strip_apply_observation_fields(cls, value: object) -> object:
        observation_keys = {
            "quality_score",
            "one_leg_rtt_ms",
            "latency_ewma_ms",
            "last_latency_ms",
            "measured_rtt_ms",
            "cf_wg_probe_latency_ms",
            "dynamic_priority",
            "health_role",
            "probe_state",
            "last_error",
            "last_probe_at",
            "last_seen_at",
            "last_heartbeat_at",
            "updated_at",
        }
        if isinstance(value, dict):
            return {
                str(key): cls.strip_apply_observation_fields(item)
                for key, item in value.items()
                if str(key) not in observation_keys
            }
        if isinstance(value, list):
            return [cls.strip_apply_observation_fields(item) for item in value]
        return value

    @staticmethod
    def stable_json_hash(payload: object) -> str:
        encoded = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
        return hashlib.sha256(encoded).hexdigest()

    def forward_contract_signature(self, forward: dict) -> str:
        config = self.config if isinstance(getattr(self, "config", None), dict) else {}
        client_id = int(config.get("client_id", 0) or 0)
        configured_wg_port = int(config.get("wireguard_listen_port", 0) or 0)
        wireguard_listen_port = configured_wg_port if configured_wg_port > 0 else 43000 + (client_id % 1000)
        configured_fallback_port = int(config.get("wireguard_fallback_tcp_port", 0) or 0)
        wireguard_fallback_tcp_port = configured_fallback_port if configured_fallback_port > 0 else wireguard_listen_port + 10000
        bond_bin = pathlib.Path(str(config.get("bond_bin", "/usr/local/bin/leikwan-bond") or "/usr/local/bin/leikwan-bond"))
        mptcp_client = pathlib.Path(str(config.get("mptcp_client_bin", "/usr/local/bin/leikwan-mptcp-client.py") or "/usr/local/bin/leikwan-mptcp-client.py"))
        runtime_inputs = {
            "agent_version": AGENT_VERSION,
            "agent_sha256": file_sha256(pathlib.Path(__file__).resolve()),
            "install_forward_runtime_sha256": file_sha256(self.install_forward_runtime_path()),
            "mptcp_client_sha256": file_sha256(mptcp_client),
            "bond_bin": str(bond_bin),
            "mptcp_client_bin": str(mptcp_client),
            "state_dir": str(getattr(self, "state_dir", config.get("state_dir", "/var/lib/leikwan-client-agent"))),
            "wireguard_listen_port": wireguard_listen_port,
            "wireguard_fallback_tcp_port": wireguard_fallback_tcp_port,
            "kernel_supports_mptcp": self.running_kernel_supports_mptcp(),
        }
        normalized_forward = self.strip_apply_observation_fields(forward)
        return self.stable_json_hash(
            {
                "forward": normalized_forward,
                "runtime_inputs": runtime_inputs,
            }
        )

    def effective_forward_contract_signature(self, forward: dict) -> str:
        render_forward = dict(forward)
        candidate_pool = self.select_forward_candidates(render_forward)
        if candidate_pool:
            render_forward["candidate_pool"] = candidate_pool
        runtime_mode = self.choose_runtime_family(render_forward, self.running_kernel_supports_mptcp())
        render_forward = self.canonicalize_forward_runtime_contract(render_forward, runtime_mode)
        mode = self.normalize_mode(render_forward.get("mode", runtime_mode))
        if self.forward_uses_bond_runtime(render_forward):
            render_forward = self.apply_latency_target_candidate_escalation(render_forward)
            render_forward["endpoints"] = self.select_bond_runtime_endpoints(render_forward)
        elif mode == "mptcp":
            render_forward["candidate_pool"] = self.filter_mptcp_capable_candidates(
                [item for item in render_forward.get("candidate_pool", []) if isinstance(item, dict)]
            )
            render_forward = self.apply_mptcp_runtime_health_policy(render_forward)
        return self.forward_contract_signature(self.strip_runtime_observation_fields(render_forward))

    @staticmethod
    def rendered_runtime_signature(rendered: dict, mode: str, transport: str, listen_port: int) -> str:
        units = [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
        normalized_units = []
        for unit in units:
            if not isinstance(unit, dict):
                continue
            normalized_files = []
            for file_entry in unit.get("files", []):
                if not isinstance(file_entry, dict):
                    continue
                normalized_files.append(
                    {
                        "path": str(file_entry.get("path", "") or ""),
                        "content": str(file_entry.get("content", "") or ""),
                        "mode": file_entry.get("mode"),
                    }
                )
            normalized_units.append(
                {
                    "service_name": str(unit.get("service_name", "") or ""),
                    "service_path": str(unit.get("service_path", "") or ""),
                    "service_unit": str(unit.get("service_unit", "") or ""),
                    "start_script_path": str(unit.get("start_script_path", "") or ""),
                    "start_script": str(unit.get("start_script", "") or ""),
                    "files": normalized_files,
                }
            )
        return ClientAgent.stable_json_hash(
            {
                "mode": mode,
                "transport": transport,
                "listen_port": listen_port,
                "units": normalized_units,
            }
        )

    @staticmethod
    def rendered_managed_paths(rendered: dict) -> set[str]:
        units = [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
        paths: set[str] = set()
        for unit in units:
            if not isinstance(unit, dict):
                continue
            service_path = str(unit.get("service_path", "") or "").strip()
            if service_path != "":
                paths.add(service_path)
            start_script_path = str(unit.get("start_script_path", "") or "").strip()
            if start_script_path != "":
                paths.add(start_script_path)
            for file_entry in unit.get("files", []):
                if not isinstance(file_entry, dict):
                    continue
                extra_path = str(file_entry.get("path", "") or "").strip()
                if extra_path != "":
                    paths.add(extra_path)
        return paths

    @staticmethod
    def mptcp_underlay_requires_presence(underlay: dict) -> bool:
        if not isinstance(underlay, dict):
            return False
        legs = underlay.get("legs", [])
        if not isinstance(legs, list) or not legs:
            return True
        for leg in legs:
            if not isinstance(leg, dict):
                continue
            role = str(leg.get("leg_role", "") or "").strip().lower()
            if role == "standby" or bool(leg.get("backup_only", False)):
                continue
            return True
        return False

    def previous_mptcp_underlay_interfaces(self, previous: dict) -> list[str]:
        if not isinstance(previous, dict):
            return []
        runtime_mode = self.normalize_mode(previous.get("runtime_mode") or previous.get("published_mode") or "")
        if runtime_mode != "mptcp":
            return []
        raw_paths = previous.get("managed_paths", [])
        paths = [str(item) for item in raw_paths if str(item).strip() != ""] if isinstance(raw_paths, list) else []
        interfaces: set[str] = set()
        for raw_path in paths:
            if not raw_path.endswith(".json"):
                continue
            path = pathlib.Path(raw_path)
            if not path.exists():
                continue
            try:
                payload = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            if not isinstance(payload, dict):
                continue
            underlays = payload.get("underlays", [])
            if not isinstance(underlays, list) or not underlays:
                underlay = payload.get("underlay", {})
                underlays = [underlay] if isinstance(underlay, dict) else []
            for underlay in underlays:
                if not isinstance(underlay, dict) or not self.mptcp_underlay_requires_presence(underlay):
                    continue
                interface_name = str(underlay.get("interface_name", "") or "").strip()
                if interface_name != "":
                    interfaces.add(interface_name)
        return sorted(interfaces)

    def applied_forward_runtime_is_present(self, previous: dict) -> bool:
        raw_units = previous.get("units", []) if isinstance(previous, dict) else []
        units = [item for item in raw_units if isinstance(item, dict)] if isinstance(raw_units, list) else []
        if not units:
            return False
        for unit in units:
            service_name = str(unit.get("service_name", "") or "").strip()
            if service_name == "" or not self.systemd_is_active(service_name):
                return False
        raw_paths = previous.get("managed_paths", []) if isinstance(previous, dict) else []
        paths = [str(item) for item in raw_paths if str(item).strip() != ""] if isinstance(raw_paths, list) else []
        if not paths:
            return False
        if not all(pathlib.Path(path).exists() for path in paths):
            return False
        for interface_name in self.previous_mptcp_underlay_interfaces(previous):
            if not self.private_wg_interface_present(interface_name):
                return False
        return True

    def persist_forward_apply_signatures(
        self,
        forward_id: int,
        contract_signature: str,
        rendered_signature: str | None = None,
    ) -> None:
        applied = self.load_applied()
        previous = applied.get(str(forward_id), {})
        if not isinstance(previous, dict):
            return
        previous["contract_signature"] = contract_signature
        if rendered_signature is not None:
            previous["rendered_signature"] = rendered_signature
        previous["updated_at"] = int(time.time())
        applied[str(forward_id)] = previous
        self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")

    def forward_apply_is_noop(self, forward_id: int, contract_signature: str) -> bool:
        previous = self.load_applied().get(str(forward_id), {})
        if not isinstance(previous, dict):
            return False
        if str(previous.get("contract_signature", "") or "") != contract_signature:
            return False
        return self.applied_forward_runtime_is_present(previous)

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

    @staticmethod
    def transport_protocols(value: object) -> list[str]:
        transport = str(value or "tcp+udp").strip().lower()
        protocols: list[str] = []
        if transport in {"tcp", "tcp+udp"}:
            protocols.append("tcp")
        if transport in {"udp", "tcp+udp"}:
            protocols.append("udp")
        return protocols or ["tcp"]

    def ensure_nft_table(self) -> None:
        if not shutil.which("nft"):
            return
        subprocess.run(
            "nft list table inet leikwan_meter >/dev/null 2>&1 || nft add table inet leikwan_meter",
            shell=True,
            check=False,
        )
        subprocess.run(
            "nft list chain inet leikwan_meter input >/dev/null 2>&1 || nft add chain inet leikwan_meter input '{ type filter hook input priority 0; policy accept; }'",
            shell=True,
            check=False,
        )
        subprocess.run(
            "nft list chain inet leikwan_meter output >/dev/null 2>&1 || nft add chain inet leikwan_meter output '{ type filter hook output priority 0; policy accept; }'",
            shell=True,
            check=False,
        )

    def add_nft_rules(self, forward_id: int, listen_port: int, transport: str) -> None:
        if listen_port <= 0 or not shutil.which("nft"):
            return
        self.ensure_nft_table()
        for protocol in self.transport_protocols(transport):
            subprocess.run(
                f'nft add rule inet leikwan_meter input {protocol} dport {listen_port} counter comment "lw_client_{forward_id}_in_{protocol}"',
                shell=True,
                check=False,
            )
            subprocess.run(
                f'nft add rule inet leikwan_meter output {protocol} sport {listen_port} counter comment "lw_client_{forward_id}_out_{protocol}"',
                shell=True,
                check=False,
            )

    @staticmethod
    def parse_host_port(value: str) -> tuple[str, int] | None:
        candidate = str(value or "").strip()
        if candidate == "":
            return None
        if candidate.startswith("["):
            match = re.fullmatch(r"\[(.+)\]:(\d+)", candidate)
            if not match:
                return None
            return match.group(1), int(match.group(2))
        host, separator, port_text = candidate.rpartition(":")
        if separator == "" or host.strip() == "" or port_text.strip() == "":
            return None
        try:
            return host.strip(), int(port_text.strip())
        except ValueError:
            return None

    def expected_bond_leg_ports(self, forward: dict) -> set[int]:
        forward_id = int(forward.get("forward_id", 0) or 0)
        endpoints = [endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        base = 20000 + ((forward_id * 37) % 15000)
        ports: set[int] = set()
        protocols = self.transport_protocols(str(forward.get("transport", "tcp+udp") or "tcp+udp"))
        if "tcp" in protocols:
            for index, _endpoint in enumerate(endpoints):
                ports.add(base + index)
        if "udp" in protocols:
            for index, _endpoint in enumerate(endpoints):
                ports.add(base + 200 + index)
        return ports

    def collect_live_bond_slave_connections(self, forward: dict) -> list[dict] | None:
        endpoints = [dict(endpoint) for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        if endpoints == []:
            return []
        if not shutil.which("ss"):
            return None

        endpoint_map: dict[tuple[str, int], dict] = {}
        for endpoint in endpoints:
            host = str(endpoint.get("host", "")).strip()
            port = int(endpoint.get("port", 0) or 0)
            if host == "" or port <= 0:
                continue
            endpoint_map[(host, port)] = endpoint

        result = subprocess.run(
            "ss -tnp state established",
            shell=True,
            text=True,
            capture_output=True,
            check=False,
        )
        if result.returncode != 0:
            return None
        matches_by_endpoint: dict[int, dict] = {}
        for line in result.stdout.splitlines():
            parts = line.split()
            if len(parts) < 5:
                continue
            parsed_addrs = [parsed for parsed in (self.parse_host_port(token) for token in parts) if parsed is not None]
            if len(parsed_addrs) < 2:
                continue
            local_addr, remote_addr = parsed_addrs[-2], parsed_addrs[-1]
            _local_host, _local_port = local_addr
            remote_host, remote_port = remote_addr
            endpoint = endpoint_map.get((remote_host, remote_port))
            if endpoint is None:
                continue
            endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
            match = dict(endpoint)
            match["live_remote"] = f"{remote_host}:{remote_port}"
            if endpoint_id > 0:
                matches_by_endpoint[endpoint_id] = match

        ordered_matches: list[dict] = []
        for endpoint in endpoints:
            endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
            match = matches_by_endpoint.get(endpoint_id)
            if match is not None:
                ordered_matches.append(match)
        return ordered_matches

    def delete_nft_rules(self, forward_id: int) -> None:
        if not shutil.which("nft"):
            return
        result = subprocess.run("nft -a list table inet leikwan_meter", shell=True, text=True, capture_output=True, check=False)
        current_chain = ""
        for line in result.stdout.splitlines():
            stripped = line.strip()
            chain_match = re.match(r"chain\s+(\w+)", stripped)
            if chain_match:
                current_chain = chain_match.group(1)
                continue
            if f'lw_client_{forward_id}_' not in stripped:
                continue
            handle_match = re.search(r"# handle (\d+)", stripped)
            if handle_match and current_chain:
                subprocess.run(
                    f"nft delete rule inet leikwan_meter {current_chain} handle {handle_match.group(1)}",
                    shell=True,
                    check=False,
                )

    def update_state_path(self) -> pathlib.Path:
        return self.state_dir / "agent_update_state.json"

    def load_update_state(self) -> dict:
        path = self.update_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_update_state(self, payload: dict) -> None:
        self.update_state_path().write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")

    def load_pull_state(self) -> dict:
        if not self.pull_state_path.exists():
            return {}
        try:
            payload = json.loads(self.pull_state_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_pull_state(self, payload: dict) -> None:
        existing = self.load_pull_state()
        existing.update(payload)
        self.pull_state_path.write_text(json.dumps(existing, ensure_ascii=False, indent=2), encoding="utf-8")

    def bridge_lite_filter_candidate_nodes(self, profile: dict, candidates: list[dict]) -> list[dict]:
        carriers = {str(item).strip().lower() for item in profile.get("selected_carriers", []) if str(item).strip() != ""}
        allow_paid = bool(profile.get("allow_paid_lines", False))
        result = []
        for candidate in candidates:
            carrier = str(candidate.get("carrier", "") or "").strip().lower()
            pricing = str(candidate.get("pricing_class", "free") or "free").strip().lower()
            if carriers and carrier not in carriers:
                continue
            if not allow_paid and pricing == "paid":
                continue
            result.append(candidate)
        return result

    def bridge_lite_select_best_node(self, candidates: list[dict]) -> dict:
        ordered = sorted(
            candidates,
            key=lambda item: (
                float(item.get("probe_latency_ms", item.get("latency_ms", 1e9)) or 1e9),
                int(item.get("priority", 1000) or 1000),
                int(item.get("id", 0) or 0),
            ),
        )
        if not ordered:
            raise RuntimeError("no allowed bridge-lite entry candidates")
        return ordered[0]

    def bridge_lite_runtime_status_payload(self, profile: dict, selected_node: dict, status: str) -> dict:
        pricing = str(selected_node.get("pricing_class", "free") or "free").strip().lower()
        return {
            "status": status,
            "profile_id": int(profile.get("profile_id", profile.get("id", 0)) or 0),
            "profile_name": str(profile.get("profile_name", profile.get("name", "")) or ""),
            "selected_entry": int(selected_node.get("id", 0) or 0),
            "selected_carrier": str(selected_node.get("carrier", "") or ""),
            "used_paid_line": pricing == "paid",
        }

    def bridge_lite_fetch_linux_profile_runtime(self, master: str, token: str) -> dict:
        payload = http_json_with_headers("GET", f"{master.rstrip('/')}/api/client/configs/pull", token)[0]
        return payload.get("linux_profile_runtime", {}) if isinstance(payload, dict) else {}

    def bond_leg_snapshot_dir(self) -> pathlib.Path:
        configured = str(self.config.get("bond_leg_snapshot_dir", "") or "").strip()
        if configured != "":
            return pathlib.Path(configured)
        return pathlib.Path("/run/leikwan")

    def load_bond_leg_reputation_snapshots(self) -> list[dict[str, object]]:
        snapshots: list[dict[str, object]] = []
        snapshot_dir = self.bond_leg_snapshot_dir()
        for path in sorted(snapshot_dir.glob("bond-leg-health-forward-*.json")):
            try:
                payload = json.loads(path.read_text(encoding="utf-8"))
            except Exception:  # noqa: BLE001
                continue
            if isinstance(payload, dict):
                snapshots.append(payload)
        return snapshots

    def load_forward_bond_runtime_snapshot(self, forward_id: int) -> dict[str, object]:
        if forward_id <= 0:
            return {}
        snapshot_path = self.bond_leg_snapshot_dir() / f"bond-leg-health-forward-{forward_id}.json"
        if not snapshot_path.exists():
            return {}
        try:
            payload = json.loads(snapshot_path.read_text(encoding="utf-8"))
        except Exception:  # noqa: BLE001
            return {}
        return payload if isinstance(payload, dict) else {}

    @staticmethod
    def state_timestamp(value: object) -> float:
        try:
            return float(value)
        except (TypeError, ValueError):
            return 0.0

    def should_retry_update(self, update_state: dict, request_token: str, now: float) -> bool:
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

    def device_id(self) -> str:
        configured = str(self.config.get("device_id", "") or "").strip()
        if configured:
            return configured[:191]
        machine_id_path = pathlib.Path(str(self.config.get("machine_id_path", "/etc/machine-id") or "/etc/machine-id"))
        try:
            machine_id = machine_id_path.read_text(encoding="utf-8").strip()
        except OSError:
            machine_id = f"{platform.node()}|{self.config.get('client_id', 0)}"
        return "linux-" + hashlib.sha256(machine_id.encode("utf-8")).hexdigest()[:32]

    @staticmethod
    def device_platform() -> str:
        machine = platform.machine().strip().lower()
        architecture = {
            "x86_64": "amd64",
            "amd64": "amd64",
            "aarch64": "arm64",
            "arm64": "arm64",
        }.get(machine, machine or "unknown")
        return f"linux-{architecture}"

    def device_headers(self) -> dict[str, str]:
        return {
            "X-Device-Id": self.device_id(),
            "X-Device-Platform": self.device_platform(),
            "X-Device-Name": str(self.config.get("device_name", platform.node()) or platform.node())[:191],
        }

    @staticmethod
    def has_internal_path_runtime(forward: dict) -> bool:
        path_runtime = forward.get("path_runtime")
        return path_runtime is not None and (not isinstance(path_runtime, dict) or path_runtime != {})

    def config_watch_once(self) -> None:
        query = urllib.parse.urlencode({"revision": self.observed_config_revision, "wait": 25})
        master = str(self.config["master_url"]).rstrip("/")
        http_client = self._http_client or http_json_with_headers
        response, _headers = http_client(
            "GET",
            f"{master}/api/client/configs/watch?{query}",
            str(self.config["agent_token"]),
            extra_headers=self.device_headers(),
            timeout=28,
        )
        revision = max(0, int(response.get("revision", 0) or 0))
        changed = bool(response.get("changed", False)) or revision > self.observed_config_revision
        self.observed_config_revision = max(self.observed_config_revision, revision)
        if changed:
            self.config_changed_event.set()

    def run_config_watch_loop(self) -> None:
        backoff = 1
        while not self.stop_event.is_set():
            try:
                self.config_watch_once()
                backoff = 1
            except Exception as exc:  # noqa: BLE001
                logging.warning("config watch failed: %s", exc)
                if self.stop_event.wait(backoff):
                    return
                backoff = min(30, backoff * 2)

    def start_config_watch(self) -> None:
        if self.config_watch_thread is not None and self.config_watch_thread.is_alive():
            return
        self.config_watch_thread = threading.Thread(
            target=self.run_config_watch_loop,
            name="leikwan-config-watch",
            daemon=True,
        )
        self.config_watch_thread.start()

    def next_full_sync_deadline(self, now: float) -> float:
        interval = max(5, int(self.config.get("poll_interval_seconds", 30) or 30))
        jitter_cap = min(1.0, interval * 0.1)
        return now + interval + float(self._jitter(0.0, jitter_cap))

    def pathd_runtime_dir(self) -> pathlib.Path:
        return pathlib.Path(str(self.config.get("pathd_runtime_dir", "/run/leikwan-pathd") or "/run/leikwan-pathd"))

    def pathd_desired_path(self) -> pathlib.Path:
        return self.pathd_runtime_dir() / "desired.json"

    def pathd_status_path(self) -> pathlib.Path:
        return self.pathd_runtime_dir() / "status.json"

    def pathd_unit_path(self) -> pathlib.Path:
        systemd_dir = pathlib.Path(str(self.config.get("systemd_dir", "/etc/systemd/system") or "/etc/systemd/system"))
        return systemd_dir / "leikwan-pathd.service"

    def pathd_binary_path(self) -> pathlib.Path:
        return pathlib.Path(str(self.config.get("pathd_bin", "/usr/local/bin/leikwan-pathd") or "/usr/local/bin/leikwan-pathd"))

    def leigod_binary_path(self) -> pathlib.Path:
        return pathlib.Path(str(self.config.get("leigod_bin", "/usr/local/bin/leigod-linux-amd64") or "/usr/local/bin/leigod-linux-amd64"))

    @staticmethod
    def _pathd_socket(host: object, port: object, *, require_ip: bool = False) -> str:
        normalized_host = str(host or "").strip().strip("[]")
        try:
            normalized_port = int(port or 0)
        except (TypeError, ValueError) as exc:
            raise RuntimeError("invalid pathd socket port") from exc
        if normalized_host == "" or any(character.isspace() for character in normalized_host):
            raise RuntimeError("invalid pathd socket host")
        if normalized_port < 1 or normalized_port > 65535:
            raise RuntimeError("invalid pathd socket port")
        try:
            address = ipaddress.ip_address(normalized_host)
        except ValueError as exc:
            if require_ip:
                raise RuntimeError("pathd egress must be an IP address") from exc
            return f"{normalized_host}:{normalized_port}"
        return f"[{address}]:{normalized_port}" if address.version == 6 else f"{address}:{normalized_port}"

    def _pathd_expiry(self, value: object) -> int:
        raw = str(value or "").strip()
        if raw == "":
            raise RuntimeError("pathd lease expiry is missing")
        try:
            parsed = datetime.datetime.fromisoformat(raw.replace("Z", "+00:00"))
        except ValueError as exc:
            raise RuntimeError("pathd lease expiry is invalid") from exc
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=datetime.timezone.utc)
        expiry = int(parsed.timestamp())
        if expiry <= int(self._clock()):
            raise RuntimeError("pathd lease is expired")
        return expiry

    @staticmethod
    def _valid_pathd_lease_id(value: object) -> bool:
        return re.fullmatch(
            r"[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}",
            str(value or "").strip().lower(),
        ) is not None

    def _pathd_local_leases(self, payload: dict) -> tuple[dict, dict[int, dict]]:
        raw_projection = payload.get("linux_path_leases", {})
        if not isinstance(raw_projection, dict):
            raise RuntimeError("linux_path_leases must be an object")
        session_json = raw_projection.get("session_json", "")
        raw_lines = raw_projection.get("lines", [])
        if not isinstance(raw_lines, list) or len(raw_lines) > 16:
            raise RuntimeError("pathd local lease count is invalid")
        if session_json == "" and raw_lines == []:
            session_json = "{}"
        if not isinstance(session_json, str) or session_json == "" or len(session_json.encode("utf-8")) > 1024 * 1024:
            raise RuntimeError("pathd provider session is invalid")
        client_id = int(self.config.get("client_id", 0) or 0)
        client_device_id = self.device_id()
        normalized: list[dict] = []
        by_line: dict[int, dict] = {}
        for raw in raw_lines:
            if not isinstance(raw, dict):
                raise RuntimeError("pathd local lease must be an object")
            line_id = int(raw.get("line_id", 0) or 0)
            lease_id = str(raw.get("lease_id", "") or "").strip().lower()
            lease_generation = int(raw.get("lease_generation", 0) or 0)
            state = str(raw.get("lease_state", "") or "").strip().lower()
            max_lanes = int(raw.get("max_parallel_lanes", 0) or 0)
            config_sha256 = str(raw.get("config_sha256", "") or "").strip().lower()
            config_toml = raw.get("toml", "")
            socks_host = str(raw.get("socks_host", "") or "").strip()
            socks_port = int(raw.get("socks_port", 0) or 0)
            if line_id < 1 or line_id > 45535 or line_id in by_line:
                raise RuntimeError("pathd local lease line is invalid or duplicate")
            if not self._valid_pathd_lease_id(lease_id) or lease_generation <= 0:
                raise RuntimeError("pathd local lease fence is invalid")
            if state not in {"pending", "active"} or max_lanes < 1 or max_lanes > 4:
                raise RuntimeError("pathd local lease state is invalid")
            try:
                owner_id = int(raw.get("lease_owner_id", 0) or 0)
            except (TypeError, ValueError) as exc:
                raise RuntimeError("pathd local lease owner id is invalid") from exc
            owner_type = str(raw.get("lease_owner_type", "") or "").strip()
            owner_device_id = str(raw.get("lease_owner_device_id", "") or "").strip()
            if owner_type != "client_device":
                raise RuntimeError("pathd local lease owner type is invalid")
            if client_id <= 0 or owner_id != client_id:
                raise RuntimeError("pathd local lease owner id is invalid")
            if owner_device_id == "" or not hmac.compare_digest(owner_device_id, client_device_id):
                raise RuntimeError("pathd local lease owner device is invalid")
            if re.fullmatch(r"[a-f0-9]{64}", config_sha256) is None or not isinstance(config_toml, str) or config_toml == "":
                raise RuntimeError("pathd local provider config is invalid")
            if not hmac.compare_digest(hashlib.sha256(config_toml.encode("utf-8")).hexdigest(), config_sha256):
                raise RuntimeError("pathd local provider config hash mismatch")
            if socks_host != "127.0.0.1" or socks_port < 20000 or socks_port > 29999:
                raise RuntimeError("pathd local SOCKS endpoint is invalid")
            expires_at_unix = self._pathd_expiry(raw.get("expires_at"))
            if state == "pending":
                try:
                    ready_deadline_unix = self._pathd_expiry(raw.get("ready_deadline_at"))
                except RuntimeError as exc:
                    raise RuntimeError("pathd pending lease ready deadline is invalid or expired") from exc
                if ready_deadline_unix > expires_at_unix:
                    raise RuntimeError("pathd pending lease ready deadline exceeds expiry")
            line = {
                "line_id": line_id,
                "lease_id": lease_id,
                "lease_generation": lease_generation,
                "max_lanes": max_lanes,
                "config_sha256": config_sha256,
                "config_toml": config_toml,
                "expires_at_unix": expires_at_unix,
                "socks_addr": f"127.0.0.1:{socks_port}",
            }
            normalized.append(line)
            by_line[line_id] = dict(raw) | {"_normalized": line}
        normalized.sort(key=lambda row: row["line_id"])
        provider = {
            "binary": str(self.leigod_binary_path()),
            "session_json": session_json,
            "session_sha256": hashlib.sha256(session_json.encode("utf-8")).hexdigest(),
            "local_socks_port_min": 20000,
            "local_socks_port_max": 29999,
        }
        return {"provider": provider, "local_leases": normalized}, by_line

    @staticmethod
    def _pathd_policy(raw: object, leg_count: int) -> dict:
        policy = raw if isinstance(raw, dict) else {}
        throughput_min = float(policy.get("throughput_min_mbps", 10.0) or 10.0)
        if not 0.001 <= throughput_min <= 10000.0:
            raise RuntimeError("pathd throughput floor is invalid")
        return {
            "chunk_size": 65536,
            "max_window_bytes": 8 * 1024 * 1024,
            "retransmit_ms": 700,
            "join_timeout_ms": 3000,
            "connect_timeout_ms": 3000,
            "preferred_initial_legs": leg_count,
            "minimum_initial_legs": 1,
            "initial_leg_wait_ms": 3000,
            "leg_reconnect_backoff_ms": 5000,
            "leg_reconnect_backoff_max_ms": 60000,
            "interactive_chunk_bytes": 16384,
            "latency_guard_bytes": max(65536, min(1048576, int(policy.get("interactive_max_bytes", 65536) or 65536))),
            "interactive_rtt_slack_ms": 30,
            "warmup_trigger_bytes": 65536,
            "warmup_slice_bytes": 65536,
            "warmup_leg_delay_ms": 20,
            "bulk_promote_bytes": 262144,
            "leg_cooldown_ms": 60000,
            "throughput_min_sample_bytes": 1048576,
            "throughput_min_sample_ms": 1000,
            "adaptive_line_admission": bool(policy.get("adaptive_line_admission", True)),
            "throughput_min_mbps": throughput_min,
            "throughput_relative_floor": 0.35,
            "throughput_bad_windows": 3,
            "throughput_recovery_windows": 2,
            "throughput_quarantine_ms": 60000,
            "throughput_probe_interval_ms": 30000,
            "throughput_probe_bytes": 1048576,
            "latency_first": bool(policy.get("latency_first", True)),
            "udp_primary_only": True,
            "default_strategy": "latency_best",
        }

    def build_pathd_desired(self, payload: dict) -> dict:
        if not isinstance(payload, dict):
            raise RuntimeError("pathd pull payload must be an object")
        generation = max(1, int(payload.get("config_revision", 0) or 0))
        lease_projection, local_by_line = self._pathd_local_leases(payload)
        raw_forwards = payload.get("forwards", [])
        if not isinstance(raw_forwards, list):
            raise RuntimeError("pathd forwards must be a list")
        hybrid_forwards = []
        for forward in raw_forwards:
            if not isinstance(forward, dict):
                continue
            runtime = forward.get("path_runtime", {})
            if (
                isinstance(runtime, dict)
                and runtime.get("engine") == "linux_pathd"
                and str(forward.get("service_status", "active")) == "active"
            ):
                hybrid_forwards.append(forward)
        if len(hybrid_forwards) > 64:
            raise RuntimeError("pathd forward count exceeds limit")
        auth_refs: dict[str, str] = {}
        forwards: list[dict] = []
        listeners: set[str] = set()
        for forward in sorted(hybrid_forwards, key=lambda row: int(row.get("forward_id", 0) or 0)):
            runtime = forward.get("path_runtime", {})
            if set(runtime) - {"engine", "placement", "protocol", "target", "egress", "local_leases", "slave_legs", "policy", "contract_hash"}:
                raise RuntimeError("pathd runtime contains unsupported fields")
            if runtime.get("placement") != "linux_hybrid" or runtime.get("protocol") != "tcp":
                raise RuntimeError("pathd runtime placement or protocol is invalid")
            forward_id = int(forward.get("forward_id", 0) or 0)
            listen = self._pathd_socket("0.0.0.0", forward.get("listen_port"))
            if forward_id <= 0 or listen in listeners:
                raise RuntimeError("pathd forward listener is invalid or duplicate")
            listeners.add(listen)
            egress = runtime.get("egress", {})
            if not isinstance(egress, dict):
                raise RuntimeError("pathd egress is invalid")
            egress_socket = self._pathd_socket(egress.get("host"), egress.get("port"), require_ip=True)
            session_token = str(egress.get("session_token", "") or "").strip()
            if session_token == "" or len(session_token) > 4096 or any(character.isspace() for character in session_token):
                raise RuntimeError("pathd session token is invalid")
            legs: list[dict] = []
            route_ids: set[int] = set()
            raw_local = runtime.get("local_leases", [])
            raw_slave = runtime.get("slave_legs", [])
            if not isinstance(raw_local, list) or not isinstance(raw_slave, list):
                raise RuntimeError("pathd runtime legs must be lists")
            for local in raw_local:
                if not isinstance(local, dict):
                    raise RuntimeError("pathd local runtime lease is invalid")
                line_id = int(local.get("physical_line_id", 0) or 0)
                authority = local_by_line.get(line_id)
                normalized = (authority or {}).get("_normalized", {})
                if not authority or str(authority.get("lease_state", "")) != "active":
                    raise RuntimeError("pathd forward references a non-active local lease")
                lease_id = str(local.get("lease_id", "") or "").lower()
                lease_generation = int(local.get("lease_generation", 0) or 0)
                if lease_id != normalized.get("lease_id") or lease_generation != normalized.get("lease_generation"):
                    raise RuntimeError("pathd local lease fence is stale")
                execution_group = str(local.get("execution_group", "") or "").strip()
                expected_execution_group = (
                    f"client:{int(authority['lease_owner_id'])}:{str(authority['lease_owner_device_id'])}"
                )
                if execution_group != expected_execution_group:
                    raise RuntimeError("pathd local execution group does not match lease owner")
                routes = local.get("routes", [])
                if not isinstance(routes, list) or not routes or len(routes) > int(normalized["max_lanes"]):
                    raise RuntimeError("pathd local route count is invalid")
                for route in routes:
                    route_id = int((route or {}).get("route_id", 0) or 0) if isinstance(route, dict) else 0
                    if route_id <= 0 or route_id in route_ids:
                        raise RuntimeError("pathd leg route is invalid or duplicate")
                    route_ids.add(route_id)
                    query = urllib.parse.urlencode(
                        {"line_id": line_id, "lease_generation": lease_generation, "leg_route_id": route_id}
                    )
                    legs.append(
                        {
                            "uri": f"socks5://127.0.0.1:{authority['socks_port']}?{query}",
                            "execution_group": execution_group,
                            "line_id": line_id,
                            "lease_id": lease_id,
                            "lease_generation": lease_generation,
                            "leg_route_id": route_id,
                        }
                    )
            local_line_ids = set(local_by_line)
            route_key = str(forward.get("path_prefix", "") or "").strip()
            if route_key == "" or len(route_key) > 256:
                raise RuntimeError("pathd ingress route key is invalid")
            for slave in raw_slave:
                if not isinstance(slave, dict):
                    raise RuntimeError("pathd slave leg is invalid")
                line_id = int(slave.get("physical_line_id", 0) or 0)
                route_id = int(slave.get("route_id", 0) or 0)
                endpoint_id = int(slave.get("endpoint_id", 0) or 0)
                node_id = int(slave.get("node_id", 0) or 0)
                execution_group = str(slave.get("execution_group", "") or "").strip()
                lease_id = str(slave.get("lease_id", "") or "").strip().lower()
                lease_generation = int(slave.get("lease_generation", 0) or 0)
                token = str(slave.get("ingress_auth_token", "") or "").strip()
                if line_id in local_line_ids or line_id < 1 or line_id > 45535:
                    raise RuntimeError("pathd slave line conflicts with local authority")
                if route_id <= 0 or route_id in route_ids or endpoint_id <= 0 or node_id <= 0:
                    raise RuntimeError("pathd slave route identity is invalid or duplicate")
                if execution_group != f"node:{node_id}":
                    raise RuntimeError("pathd slave execution group does not match lease owner")
                if not self._valid_pathd_lease_id(lease_id) or lease_generation <= 0:
                    raise RuntimeError("pathd slave lease fence is invalid")
                if token == "" or len(token) > 2048 or any(character.isspace() for character in token):
                    raise RuntimeError("pathd ingress auth token is invalid")
                endpoint = self._pathd_socket(slave.get("host"), slave.get("port"))
                if int(slave.get("port", 0) or 0) < 7000 or int(slave.get("port", 0) or 0) > 9000:
                    raise RuntimeError("pathd ingress port must be in 7000-9000")
                auth_ref = f"endpoint-{endpoint_id}"
                if auth_ref in auth_refs and not hmac.compare_digest(auth_refs[auth_ref], token):
                    raise RuntimeError("pathd ingress auth reference conflicts")
                auth_refs[auth_ref] = token
                route_ids.add(route_id)
                query = urllib.parse.urlencode(
                    {
                        "route_key": route_key,
                        "auth_ref": auth_ref,
                        "endpoint_id": endpoint_id,
                        "node_id": node_id,
                        "leg_route_id": route_id,
                    }
                )
                legs.append(
                    {
                        "uri": f"ingress+tcp://{endpoint}?{query}",
                        "execution_group": execution_group,
                        "line_id": line_id,
                        "lease_id": lease_id,
                        "lease_generation": lease_generation,
                        "leg_route_id": route_id,
                    }
                )
            legs.sort(key=lambda row: row["leg_route_id"])
            if not legs or len(legs) > 16:
                raise RuntimeError("pathd forward leg count is invalid")
            forwards.append(
                {
                    "forward_id": forward_id,
                    "listen": listen,
                    "egress": egress_socket,
                    "session_token": session_token,
                    "legs": legs,
                    "policy": self._pathd_policy(runtime.get("policy"), len(legs)),
                }
            )
        return {
            "generation": generation,
            **lease_projection,
            "auth_refs": dict(sorted(auth_refs.items())),
            "forwards": forwards,
        }

    def _atomic_write_pathd_bytes(self, path: pathlib.Path, content: bytes) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        if os.name != "nt":
            path.parent.chmod(0o700)
        tmp_path = path.with_name(f".{path.name}.{os.getpid()}.{threading.get_ident()}.tmp")
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        if hasattr(os, "O_NOFOLLOW"):
            flags |= os.O_NOFOLLOW
        fd = os.open(tmp_path, flags, 0o600)
        try:
            with os.fdopen(fd, "wb") as handle:
                handle.write(content)
                handle.flush()
                os.fsync(handle.fileno())
            if os.name != "nt":
                os.chmod(tmp_path, 0o600)
            os.replace(tmp_path, path)
            if os.name != "nt":
                directory_fd = os.open(path.parent, os.O_RDONLY)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
        finally:
            with contextlib.suppress(OSError):
                os.close(fd)
            with contextlib.suppress(OSError):
                tmp_path.unlink()

    def atomic_write_pathd_desired(self, desired: dict) -> None:
        encoded = json.dumps(desired, ensure_ascii=True, sort_keys=True, separators=(",", ":")).encode("utf-8")
        self._atomic_write_pathd_bytes(self.pathd_desired_path(), encoded)

    def render_pathd_unit(self) -> str:
        binary = str(self.pathd_binary_path())
        runtime_dir = str(self.pathd_runtime_dir())
        if any(character.isspace() for character in binary + runtime_dir):
            raise RuntimeError("pathd paths cannot contain whitespace")
        return "\n".join(
            [
                "[Unit]",
                "Description=Leikwan path aggregation daemon",
                "After=network-online.target",
                "Wants=network-online.target",
                "",
                "[Service]",
                "Type=simple",
                f"ExecStart={binary} --config {runtime_dir}/desired.json --status {runtime_dir}/status.json",
                "Restart=on-failure",
                "RestartSec=3",
                "KillMode=control-group",
                "NoNewPrivileges=true",
                "ProtectHome=true",
                "ProtectSystem=strict",
                f"ReadWritePaths={runtime_dir}",
                "PrivateTmp=true",
                "",
                "[Install]",
                "WantedBy=multi-user.target",
                "",
            ]
        )

    def _run_pathd_command(self, parts: list[str]) -> int:
        if self.command_runner is not None:
            result = self.command_runner(parts)
            return int(getattr(result, "returncode", result) or 0)
        result = subprocess.run(parts, text=True, capture_output=True, check=False)
        if result.returncode != 0:
            logging.warning("pathd command failed command=%s stderr=%s", parts, result.stderr.strip())
        return int(result.returncode)

    def load_pathd_status(self) -> dict:
        path = self.pathd_status_path()
        try:
            if not path.is_file() or path.stat().st_size > 256 * 1024:
                return {}
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError, TypeError, json.JSONDecodeError):
            return {}
        return payload if isinstance(payload, dict) else {}

    def _pathd_status_accepts(self, desired: dict) -> bool:
        status = self.load_pathd_status()
        try:
            status_generation = int(status.get("generation", 0) or 0)
            desired_generation = int(desired["generation"])
        except (TypeError, ValueError, OverflowError):
            return False
        if status_generation != desired_generation:
            return False
        if str(status.get("status", "") or "") not in {"ready", "degraded"}:
            return False
        forwards = status.get("forwards", {})
        if not isinstance(forwards, dict):
            return False
        return all(
            isinstance(forwards.get(str(row["forward_id"])), dict)
            and forwards[str(row["forward_id"])].get("listener") == "ready"
            for row in desired["forwards"]
        )

    def wait_for_pathd_generation(self, desired: dict) -> bool:
        timeout_seconds = max(0.0, min(30.0, float(self.config.get("pathd_status_timeout_seconds", 8) or 0)))
        deadline = self._clock() + timeout_seconds
        while True:
            if self._pathd_status_accepts(desired):
                return True
            if self._clock() >= deadline:
                return False
            self.stop_event.wait(min(0.1, max(0.0, deadline - self._clock())))

    def validate_pathd_artifact(self, client_meta: dict) -> bool:
        self.latest_pathd_metadata = {}
        expected_version = str(client_meta.get("desired_pathd_version", "") or "").strip()
        expected_sha256 = str(client_meta.get("desired_pathd_sha256", "") or "").strip().lower()
        binary = self.pathd_binary_path()
        if expected_version == "" or re.fullmatch(r"[a-f0-9]{64}", expected_sha256) is None:
            return False
        if not binary.is_file() or (os.name != "nt" and not os.access(binary, os.X_OK)):
            return False
        if not hmac.compare_digest(file_sha256(binary).lower(), expected_sha256):
            return False
        provider = self.leigod_binary_path()
        if not provider.is_file() or (os.name != "nt" and not os.access(provider, os.X_OK)):
            return False
        self.latest_pathd_metadata = {"version": expected_version, "sha256": expected_sha256}
        return True

    def reconcile_pathd(self, payload: dict) -> bool:
        try:
            desired = self.build_pathd_desired(payload)
        except Exception as exc:  # noqa: BLE001
            logging.warning("pathd desired state rejected before write: %s", exc)
            raise
        client_meta = payload.get("client", {})
        if not isinstance(client_meta, dict) or not self.validate_pathd_artifact(client_meta):
            logging.warning("pathd artifact is missing or hash-mismatched")
            return False
        desired_path = self.pathd_desired_path()
        previous_bytes = desired_path.read_bytes() if desired_path.is_file() else None
        previous = None
        if previous_bytes is not None:
            with contextlib.suppress(ValueError, TypeError, json.JSONDecodeError):
                decoded = json.loads(previous_bytes.decode("utf-8"))
                previous = decoded if isinstance(decoded, dict) else None
        if previous is not None:
            previous_generation = int(previous.get("generation", 0) or 0)
            desired_generation = int(desired["generation"])
            if desired_generation < previous_generation:
                logging.warning("pathd generation rollback rejected desired=%s current=%s", desired_generation, previous_generation)
                return False
            if desired_generation == previous_generation and previous != desired:
                logging.warning("pathd same-generation content change rejected generation=%s", desired_generation)
                return False
            if previous == desired and self._pathd_status_accepts(desired):
                return True
        unit_changed = self.write_text_if_changed(self.pathd_unit_path(), self.render_pathd_unit(), mode=0o644)
        try:
            self.atomic_write_pathd_desired(desired)
            if unit_changed and self._run_pathd_command(["systemctl", "daemon-reload"]) != 0:
                return self._restore_pathd_desired(previous_bytes)
            command = (
                ["systemctl", "enable", "--now", "leikwan-pathd.service"]
                if previous_bytes is None
                else ["systemctl", "kill", "-s", "HUP", "leikwan-pathd.service"]
            )
            if self._run_pathd_command(command) == 0 and self.wait_for_pathd_generation(desired):
                return True
        except Exception as exc:  # noqa: BLE001
            logging.warning("pathd generation apply failed generation=%s error=%s", desired["generation"], exc)
        logging.warning("pathd rejected desired generation=%s", desired["generation"])
        return self._restore_pathd_desired(previous_bytes)

    def _restore_pathd_desired(self, previous_bytes: bytes | None) -> bool:
        if previous_bytes is None:
            with contextlib.suppress(OSError):
                self.pathd_desired_path().unlink()
            self._run_pathd_command(["systemctl", "stop", "leikwan-pathd.service"])
            return False
        self._atomic_write_pathd_bytes(self.pathd_desired_path(), previous_bytes)
        self._run_pathd_command(["systemctl", "kill", "-s", "HUP", "leikwan-pathd.service"])
        return False

    def pathd_heartbeat(self, client_meta: dict) -> dict:
        capability = (
            dict(self.latest_pathd_metadata)
            if isinstance(client_meta, dict) and self.validate_pathd_artifact(client_meta)
            else {}
        )
        capability_version = str(capability.get("version", "") or "").strip()
        capability_sha256 = str(capability.get("sha256", "") or "").strip().lower()
        capability = (
            {"version": capability_version[:64], "sha256": capability_sha256}
            if capability_version != "" and re.fullmatch(r"[a-f0-9]{64}", capability_sha256) is not None
            else {}
        )

        def bounded_int(value: object, maximum: int) -> int:
            try:
                parsed = int(value)
            except (TypeError, ValueError, OverflowError):
                return 0
            return max(0, min(maximum, parsed))

        def enum_value(value: object, allowed: set[str]) -> str:
            normalized = str(value or "").strip().lower()
            return normalized if normalized in allowed else "unknown"

        status = self.load_pathd_status()
        forwards = status.get("forwards", {}) if isinstance(status.get("forwards"), dict) else {}
        sanitized_forwards = {}
        forward_rows = []
        for key, row in forwards.items():
            forward_id = bounded_int(key, (1 << 63) - 1)
            if forward_id > 0 and isinstance(row, dict):
                forward_rows.append((forward_id, row))
        for forward_id, row in sorted(forward_rows, key=lambda item: item[0])[:64]:
            sanitized_forwards[str(forward_id)] = {
                "listener": enum_value(row.get("listener"), {"ready", "failed"}),
                "sessions": bounded_int(row.get("sessions"), (1 << 31) - 1),
            }
        desired_lines = {}
        desired_rows = self.latest_linux_path_leases.get("lines", [])
        if isinstance(desired_rows, list):
            for row in desired_rows[:16]:
                if not isinstance(row, dict):
                    continue
                line_id = bounded_int(row.get("line_id"), 45535)
                if line_id > 0:
                    desired_lines[line_id] = row
        local_lines = []
        for row in status.get("local_lines", []) if isinstance(status.get("local_lines"), list) else []:
            if not isinstance(row, dict) or len(local_lines) >= 16:
                continue
            line_id = bounded_int(row.get("line_id"), 45535)
            desired = desired_lines.get(line_id)
            if not isinstance(desired, dict):
                continue
            lease_id = str(row.get("lease_id", "") or "").strip().lower()
            lease_generation = bounded_int(row.get("lease_generation"), (1 << 63) - 1)
            config_sha256 = str(row.get("config_sha256", "") or "").strip().lower()
            expected_lease_id = str(desired.get("lease_id", "") or "").strip().lower()
            expected_generation = bounded_int(desired.get("lease_generation"), (1 << 63) - 1)
            expected_sha256 = str(desired.get("config_sha256", "") or "").strip().lower()
            if (
                not self._valid_pathd_lease_id(lease_id)
                or lease_generation <= 0
                or re.fullmatch(r"[a-f0-9]{64}", config_sha256) is None
                or not hmac.compare_digest(lease_id, expected_lease_id)
                or lease_generation != expected_generation
                or not hmac.compare_digest(config_sha256, expected_sha256)
            ):
                continue
            tcp_connect_ms = bounded_int(row.get("tcp_connect_ms"), 60_000)
            last_error_code = enum_value(
                row.get("last_error_code"),
                {"provider_process_exited", "tcp_connect_failed"},
            )
            local_lines.append(
                {
                    "catalog_id": bounded_int(desired.get("catalog_id"), (1 << 63) - 1),
                    "line_id": line_id,
                    "lease_id": lease_id,
                    "lease_generation": lease_generation,
                    "config_sha256": config_sha256,
                    "service_state": enum_value(row.get("service_state"), {"active", "failed"}),
                    "tcp_status": enum_value(row.get("tcp_status"), {"ok", "unreachable", "unknown"}),
                    "tcp_connect_ms": tcp_connect_ms,
                    "last_error_code": "" if last_error_code == "unknown" else last_error_code,
                }
            )
        return {
            "capability": capability,
            "status": {
                "generation": bounded_int(status.get("generation"), (1 << 63) - 1),
                "status": enum_value(status.get("status"), {"ready", "degraded", "stopped"}),
                "forwards": sanitized_forwards,
            },
            "local_lines": local_lines,
        }

    def run(self) -> None:
        self.start_config_watch()
        next_sync = 0.0
        next_health = 0.0
        while not self.stop_event.is_set():
            now = self._clock()
            config_changed = self.config_changed_event.is_set()
            if config_changed or now >= next_sync:
                if config_changed:
                    self.config_changed_event.clear()
                try:
                    self.sync_once()
                except Exception as exc:  # noqa: BLE001
                    self.save_pull_state(
                        {
                            "status": "error",
                            "last_attempt_at": now,
                            "last_error": str(exc),
                            "agent_version": AGENT_VERSION,
                        }
                    )
                    logging.exception("sync failed: %s", exc)
                next_sync = self.next_full_sync_deadline(now)

            if now >= next_health:
                try:
                    self.ensure_runtime_health(self.latest_forwards)
                except Exception as exc:  # noqa: BLE001
                    logging.exception("health check failed: %s", exc)
                next_health = now + int(self.config.get("health_check_interval_seconds", 20))

            try:
                self.run_cfwg_latency_watchdog(self.latest_forwards)
            except Exception as exc:  # noqa: BLE001
                logging.exception("cfwg latency watchdog failed: %s", exc)

            if self.stop_event.wait(1.0):
                break

    def run_cfwg_latency_watchdog(self, forwards: list[dict]) -> None:
        watchdog_config = self.config.get("cfwg_latency_watchdog", {})
        if not isinstance(watchdog_config, dict):
            watchdog_config = {}
        if not bool(watchdog_config.get("enabled", False)):
            return
        internal_path_runtime_present = any(
            isinstance(forward, dict) and self.has_internal_path_runtime(forward)
            for forward in forwards
        )
        legacy_forwards = [
            forward
            for forward in forwards
            if isinstance(forward, dict) and not self.has_internal_path_runtime(forward)
        ]
        if internal_path_runtime_present and legacy_forwards == []:
            return
        try:
            from cfwg_latency_watchdog import CfwgLatencyWatchdog
        except Exception:
            module_path = pathlib.Path(__file__).with_name("cfwg_latency_watchdog.py")
            try:
                spec = importlib.util.spec_from_file_location("cfwg_latency_watchdog", module_path)
                if spec is None or spec.loader is None:
                    raise RuntimeError(f"cannot load {module_path}")
                module = importlib.util.module_from_spec(spec)
                sys.modules["cfwg_latency_watchdog"] = module
                spec.loader.exec_module(module)
                CfwgLatencyWatchdog = module.CfwgLatencyWatchdog
            except Exception as exc:  # noqa: BLE001
                logging.warning("cfwg latency watchdog enabled but module unavailable: %s", exc)
                return
        if self._cfwg_latency_watchdog is None:
            self._cfwg_latency_watchdog = CfwgLatencyWatchdog(
                state_dir=self.state_dir,
                config=watchdog_config,
            )
        else:
            self._cfwg_latency_watchdog.update_config(watchdog_config)
        self._cfwg_latency_watchdog.tick(legacy_forwards if internal_path_runtime_present else forwards)

    def local_refresh_request_token_path(self) -> pathlib.Path:
        return self.state_dir / "runtime-refresh-request.json"

    def consume_local_refresh_requests(self) -> set[int]:
        path = self.local_refresh_request_token_path()
        if not path.exists():
            return set()
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return set()
        if not isinstance(payload, dict):
            return set()
        forward_ids = payload.get("forward_ids", [])
        if not isinstance(forward_ids, list):
            return set()
        applied = self.load_applied()
        force_ids: set[int] = set()
        for raw_forward_id in forward_ids:
            try:
                forward_id = int(raw_forward_id)
            except (TypeError, ValueError):
                continue
            if forward_id <= 0:
                continue
            token = str(payload.get(str(forward_id), "") or payload.get("token", "") or "")
            if token == "" or str(applied.get(f"_last_runtime_refresh_{forward_id}", "") or "") == token:
                continue
            force_ids.add(forward_id)
            applied[f"_last_runtime_refresh_{forward_id}"] = token
        if force_ids:
            self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")
        return force_ids
    def sync_once(self) -> None:
        master = self.config["master_url"].rstrip("/")
        token = self.config["agent_token"]
        pull_query = urllib.parse.urlencode(
            {
                "device_id": self.device_id(),
                "platform": self.device_platform(),
                "device_name": str(self.config.get("device_name", platform.node()) or platform.node())[:191],
            }
        )
        payload, response_headers = http_json_with_headers(
            "GET", f"{master}/api/client/configs/pull?{pull_query}", token
        )
        forwards = payload.get("forwards", [])
        if not isinstance(forwards, list):
            raise RuntimeError("client config forwards must be a list")
        self.latest_forwards = [forward for forward in forwards if isinstance(forward, dict)]
        client_meta = payload.get("client", {}) if isinstance(payload.get("client"), dict) else {}
        self.observed_config_revision = max(
            self.observed_config_revision,
            max(0, int(payload.get("config_revision", 0) or 0)),
        )
        linux_path_leases = payload.get("linux_path_leases", {"lines": []})
        self.latest_linux_path_leases = linux_path_leases if isinstance(linux_path_leases, dict) else {"lines": []}
        applied_forward_ids: list[int] = []
        noop_forward_ids: list[int] = []
        skipped_invalid_forward_ids: list[int] = []
        inactive_forward_ids: list[int] = []
        try:
            self.apply_management_state(client_meta)
        except Exception as exc:  # noqa: BLE001
            logging.exception("failed to apply client management state: %s", exc)
        master = self.config["master_url"].rstrip("/")
        token = self.config["agent_token"]
        desired_ids = set()
        force_runtime_refresh_ids = self.consume_local_refresh_requests()
        active_pathd_ids = {
            int(forward.get("forward_id", 0) or 0)
            for forward in forwards
            if isinstance(forward, dict)
            and str(forward.get("service_status", "active")) == "active"
            and isinstance(forward.get("path_runtime"), dict)
            and forward["path_runtime"].get("engine") == "linux_pathd"
        }
        pathd_requested = bool(active_pathd_ids) or bool(self.latest_linux_path_leases.get("lines")) or self.pathd_desired_path().exists()
        pathd_ready = True
        if pathd_requested:
            try:
                pathd_ready = self.reconcile_pathd(payload)
            except Exception as exc:  # noqa: BLE001
                pathd_ready = False
                logging.exception("pathd reconcile failed: %s", exc)
        prior_applied = self.load_applied()
        for forward in forwards:
            forward_id = int(forward["forward_id"])
            raw_path_runtime = forward.get("path_runtime")
            path_runtime = raw_path_runtime if isinstance(raw_path_runtime, dict) else {}
            path_engine = str(path_runtime.get("engine", "") or "").strip()
            malformed_path_runtime = raw_path_runtime is not None and (
                not isinstance(raw_path_runtime, dict)
                or (path_runtime != {} and path_engine == "")
                or path_engine not in {"", "linux_pathd"}
            )
            if malformed_path_runtime:
                if str(forward.get("service_status", "active")) != "active":
                    inactive_forward_ids.append(forward_id)
                    self.stop_forward(forward_id)
                    continue
                desired_ids.add(forward_id)
                skipped_invalid_forward_ids.append(forward_id)
                self.log_invalid_forward(forward_id, "skipping forward with unsupported path runtime engine")
                continue
            is_pathd_forward = isinstance(path_runtime, dict) and path_runtime.get("engine") == "linux_pathd"
            if is_pathd_forward:
                if str(forward.get("service_status", "active")) != "active":
                    inactive_forward_ids.append(forward_id)
                    continue
                desired_ids.add(forward_id)
                if not pathd_ready:
                    skipped_invalid_forward_ids.append(forward_id)
                    continue
                previous = prior_applied.get(str(forward_id), {})
                if isinstance(previous, dict) and previous and previous.get("runtime_mode") != "linux_pathd":
                    self.stop_forward(forward_id, delete_files=True)
                applied_forward_ids.append(forward_id)
                continue
            previous = prior_applied.get(str(forward_id), {})
            if isinstance(previous, dict) and previous.get("runtime_mode") == "linux_pathd":
                current = self.load_applied()
                current.pop(str(forward_id), None)
                self.applied_path.write_text(json.dumps(current, ensure_ascii=False, indent=2), encoding="utf-8")
            validation_forward = dict(forward)
            candidate_pool = self.select_forward_candidates(validation_forward)
            if candidate_pool:
                validation_forward["candidate_pool"] = candidate_pool
            runtime_mode = self.choose_runtime_family(validation_forward, self.running_kernel_supports_mptcp())
            validation_forward = self.canonicalize_forward_runtime_contract(validation_forward, runtime_mode)
            mode = self.normalize_mode(validation_forward.get("mode", runtime_mode))
            if str(forward.get("service_status", "active")) != "active":
                inactive_forward_ids.append(forward_id)
                self.stop_forward(forward_id)
                continue
            usable_endpoints = self.usable_endpoints(validation_forward)
            if usable_endpoints == [] and mode != "mptcp":
                skipped_invalid_forward_ids.append(forward_id)
                self.log_invalid_forward(
                    forward_id,
                    "skipping forward with no usable endpoints"
                    f" mode={mode}"
                    f" transport={str(forward.get('transport', 'tcp+udp'))}"
                    f" target={str(forward.get('target', ''))}",
                )
                self.stop_forward(forward_id, delete_files=True)
                continue
            if self.forward_uses_bond_runtime(validation_forward):
                bond_bin = self.bond_binary_path()
                if not self.binary_available(bond_bin):
                    skipped_invalid_forward_ids.append(forward_id)
                    self.log_invalid_forward(
                        forward_id,
                        "skipping bond runtime because required binary is missing"
                        f" bond_bin={bond_bin}"
                        f" transport={str(forward.get('transport', 'tcp+udp'))}"
                        f" target={str(forward.get('target', ''))}",
                    )
                    self.stop_forward(forward_id, delete_files=True)
                    continue
            if mode == "mptcp":
                mptcp_client = self.mptcp_client_path()
                if not mptcp_client.is_file():
                    skipped_invalid_forward_ids.append(forward_id)
                    self.log_invalid_forward(
                        forward_id,
                        "skipping mptcp forward because required helper is missing"
                        f" mptcp_client={mptcp_client}"
                        f" target={str(forward.get('target', ''))}",
                    )
                    self.stop_forward(forward_id, delete_files=True)
                    continue
                mptcp = forward.get("mptcp", {})
                if not isinstance(mptcp, dict):
                    mptcp = {}
                allow_tcp_fallback = self.mptcp_tcp_fallback_allowed(mptcp)
                if not self.running_kernel_supports_mptcp() and not allow_tcp_fallback:
                    skipped_invalid_forward_ids.append(forward_id)
                    self.log_invalid_forward(
                        forward_id,
                        "skipping mptcp forward because running kernel does not support MPTCP"
                        f" mptcp_client={mptcp_client}"
                        f" target={str(forward.get('target', ''))}",
                    )
                    self.stop_forward(forward_id, delete_files=True)
                    continue
            desired_ids.add(forward_id)
            try:
                contract_signature = self.effective_forward_contract_signature(forward)
                force_runtime_restart = forward_id in force_runtime_refresh_ids
                if not force_runtime_restart and self.forward_apply_is_noop(forward_id, contract_signature):
                    noop_forward_ids.append(forward_id)
                    applied_forward_ids.append(forward_id)
                    logging.debug("forward %s unchanged; skipping no-op runtime apply", forward_id)
                    continue
                self.apply_forward(forward, contract_signature=contract_signature, force_runtime_restart=force_runtime_restart)
                applied_forward_ids.append(forward_id)
            except Exception as exc:  # noqa: BLE001
                logging.exception(
                    "failed to apply forward %s mode=%s transport=%s endpoints=%s: %s",
                    forward_id,
                    mode,
                    str(forward.get("transport", "tcp+udp")),
                    len([endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]),
                    exc,
                )
                continue

        applied = self.load_applied()
        if pathd_ready:
            for forward in forwards:
                if not isinstance(forward, dict):
                    continue
                forward_id = int(forward.get("forward_id", 0) or 0)
                if forward_id not in active_pathd_ids:
                    continue
                applied[str(forward_id)] = {
                    "runtime_mode": "linux_pathd",
                    "service_name": "leikwan-pathd",
                    "contract_signature": self.effective_forward_contract_signature(forward),
                    "rendered_signature": str(forward.get("path_runtime", {}).get("contract_hash", "") or ""),
                }
        for forward_key in list(applied.keys()):
            try:
                forward_id = int(forward_key)
            except (TypeError, ValueError):
                continue
            if forward_id not in desired_ids:
                self.stop_forward(forward_id, delete_files=True)
                applied.pop(str(forward_id), None)

        self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")
        self.reconcile_global_mptcp_state(self.latest_forwards)
        self.run_diagnostics_if_requested(master, token, client_meta, forwards)
        usage_totals = self.collect_usage_totals()
        wireguard_public_key = self.ensure_wireguard_identity()
        local_clock = self.time_sync_state()
        pathd_report = self.pathd_heartbeat(client_meta)
        cfwg_watchdog_path = pathlib.Path(__file__).with_name("cfwg_latency_watchdog.py")
        http_json(
            "POST",
            f"{master}/api/client/heartbeat",
            token,
            {
                "agent_version": AGENT_VERSION,
                "usage_bytes_in": usage_totals["bytes_in"],
                "usage_bytes_out": usage_totals["bytes_out"],
                "supports_mptcp": self.running_kernel_supports_mptcp(),
                "mptcp_capability": self.mptcp_capability(),
                "kernel_release": platform.release(),
                "device_id": self.device_id(),
                "device_name": str(self.config.get("device_name", platform.node()) or platform.node())[:191],
                "platform": self.device_platform(),
                "installer_version": str(self.config.get("installer_version", "") or ""),
                "installer_sha256": str(self.config.get("installer_sha256", "") or ""),
                "install_forward_runtime_sha256": file_sha256(self.install_forward_runtime_path()),
                "mptcp_client_sha256": file_sha256(self.mptcp_client_path()),
                "cfwg_watchdog_sha256": file_sha256(cfwg_watchdog_path) if cfwg_watchdog_path.is_file() else "",
                "wireguard_public_key": wireguard_public_key,
                "wireguard_listen_port": self.wireguard_listen_port(),
                "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
                "clock_sync": local_clock,
                "leikwan_pathd": pathd_report["capability"],
                "pathd_status": pathd_report["status"],
                "linux_path_line_health": pathd_report["local_lines"],
                "bond_leg_reputation": {
                    "forwards": self.load_bond_leg_reputation_snapshots(),
                },
            },
        )
        now = time.time()
        self.save_pull_state(
            {
                "status": "ok",
                "last_attempt_at": now,
                "last_success_at": now,
                "last_error": "",
                "agent_version": AGENT_VERSION,
                "master_url": master,
                "client_id": int(client_meta.get("id", self.config.get("client_id", 0)) or 0),
                "forwards_total": len(self.latest_forwards),
                "applied_forward_ids": applied_forward_ids,
                "noop_forward_ids": noop_forward_ids,
                "invalid_forward_ids": skipped_invalid_forward_ids,
                "inactive_forward_ids": inactive_forward_ids,
                "agent_update_request_token": str(client_meta.get("agent_update_request_token", "") or ""),
                "diag_request_token": str(client_meta.get("diag_request_token", "") or ""),
                "clock": self.panel_clock_state_from_headers(response_headers),
            }
        )

    @staticmethod
    def usable_endpoints(forward: dict) -> list[dict]:
        usable = []
        for endpoint in forward.get("endpoints", []):
            if not isinstance(endpoint, dict):
                continue
            host = str(endpoint.get("host", "")).strip()
            try:
                port = int(endpoint.get("port", 0) or 0)
            except (TypeError, ValueError):
                port = 0
            if host == "" or port <= 0:
                continue
            normalized = dict(endpoint)
            normalized["host"] = host
            normalized["port"] = port
            usable.append(normalized)
        return usable

    def log_invalid_forward(self, forward_id: int, message: str) -> None:
        now = time.time()
        next_allowed = self.invalid_forward_log_after.get(forward_id, 0.0)
        if now < next_allowed:
            return
        self.invalid_forward_log_after[forward_id] = now + INVALID_FORWARD_LOG_INTERVAL_SECONDS
        logging.warning("forward %s %s", forward_id, message)

    def apply_management_state(self, client_meta: dict) -> None:
        self.apply_config_patch(client_meta.get("agent_config_json"))
        explicit_request_token = str(client_meta.get("agent_update_request_token", "") or "")
        auto_request_token = self.auto_update_request_token(client_meta)
        update_state = self.load_update_state()
        completed_request_token = str(update_state.get("last_completed_request_token", "") or "")

        if explicit_request_token != "":
            if completed_request_token == explicit_request_token:
                applied = self.load_applied()
                if applied.get("_last_agent_update_request_token") != explicit_request_token:
                    applied["_last_agent_update_request_token"] = explicit_request_token
                    self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")
            elif not self.management_requires_local_update(client_meta):
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
                applied = self.load_applied()
                applied["_last_agent_update_request_token"] = explicit_request_token
                self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")
                explicit_request_token = ""

        for request_token in [token for token in [explicit_request_token, auto_request_token] if token]:
            if completed_request_token == request_token:
                continue

            now = time.time()
            if not self.should_retry_update(update_state, request_token, now):
                continue

            update_state.update(
                {
                    "request_token": request_token,
                    "status": "scheduled",
                    "last_attempt_at": now,
                    "last_error": "",
                }
            )
            self.save_update_state(update_state)
            try:
                self.request_self_update(request_token)
            except Exception as exc:  # noqa: BLE001
                update_state["status"] = "error"
                update_state["last_error"] = str(exc)
                update_state["last_failed_at"] = now
                self.save_update_state(update_state)
                raise
            return

    def auto_update_request_token(self, client_meta: dict) -> str:
        if str(self.config.get("disable_auto_update", "") or "").lower() in {"1", "true", "yes", "on"}:
            return ""
        desired_agent_version = str(client_meta.get("desired_agent_version", "") or "").strip()
        desired_agent_sha256 = str(client_meta.get("desired_agent_sha256", "") or "").strip().lower()
        desired_installer_version = str(client_meta.get("desired_installer_version", "") or "").strip()
        desired_installer_sha256 = str(client_meta.get("desired_installer_sha256", "") or "").strip().lower()
        desired_bond_sha256 = str(client_meta.get("desired_bond_sha256", "") or "").strip().lower()
        desired_mptcp_runtime_sha256 = str(client_meta.get("desired_mptcp_runtime_sha256", "") or "").strip().lower()
        desired_cfwg_watchdog_sha256 = str(client_meta.get("desired_cfwg_watchdog_sha256", "") or "").strip().lower()
        mismatches: list[str] = []

        if desired_agent_version and desired_agent_version != AGENT_VERSION:
            mismatches.append(f"agent={desired_agent_version}")

        if desired_agent_sha256:
            local_agent_sha256 = file_sha256(pathlib.Path(__file__).resolve()).lower()
            if local_agent_sha256 != desired_agent_sha256:
                mismatches.append(f"agent_sha256={desired_agent_sha256}")

        local_installer_version = str(self.config.get("installer_version", "") or "").strip()
        if desired_installer_version and local_installer_version != desired_installer_version:
            mismatches.append(f"installer={desired_installer_version}")

        local_installer_sha256 = str(self.config.get("installer_sha256", "") or "").strip().lower()
        if desired_installer_sha256 and local_installer_sha256 != desired_installer_sha256:
            mismatches.append(f"installer_sha256={desired_installer_sha256}")

        if desired_bond_sha256:
            local_bond_sha256 = file_sha256(self.bond_binary_path()).lower()
            if local_bond_sha256 != desired_bond_sha256:
                mismatches.append(f"bond={desired_bond_sha256}")

        if desired_mptcp_runtime_sha256:
            local_mptcp_runtime_sha256 = file_sha256(self.mptcp_client_path()).lower()
            if local_mptcp_runtime_sha256 != desired_mptcp_runtime_sha256:
                mismatches.append(f"mptcp_runtime={desired_mptcp_runtime_sha256}")

        if desired_cfwg_watchdog_sha256:
            local_cfwg_watchdog_path = pathlib.Path(__file__).with_name("cfwg_latency_watchdog.py")
            if local_cfwg_watchdog_path.is_file():
                local_cfwg_watchdog_sha256 = file_sha256(local_cfwg_watchdog_path).lower()
            else:
                local_cfwg_watchdog_sha256 = ""
            if local_cfwg_watchdog_sha256 != desired_cfwg_watchdog_sha256:
                mismatches.append(f"cfwg_watchdog={desired_cfwg_watchdog_sha256}")

        if mismatches == []:
            return ""

        return "auto-release:" + "|".join(mismatches)

    def management_requires_local_update(self, client_meta: dict) -> bool:
        return self.auto_update_request_token(client_meta) != ""

    def apply_config_patch(self, patch: object) -> None:
        if not isinstance(patch, dict):
            return

        allowed_keys = {
            "master_url",
            "artifact_base_url",
            "disable_auto_update",
            "poll_interval_seconds",
            "health_check_interval_seconds",
            "log_level",
            "state_dir",
            "udp_uot_sidepath",
            "cfwg_latency_watchdog",
            "runtime_refresh",
            "cfwg_edge_pins",
        }
        config_changed = False
        for key, value in patch.items():
            if key not in allowed_keys:
                continue
            if value is None:
                if key in DEFAULT_MANAGED_CONFIG:
                    value = DEFAULT_MANAGED_CONFIG[key]
                else:
                    if key not in self.config:
                        continue
                    self.config.pop(key, None)
                    config_changed = True
                    continue
            if self.config.get(key) == value:
                continue
            self.config[key] = value
            config_changed = True

        if not config_changed:
            return

        self.config_path.write_text(json.dumps(self.config, ensure_ascii=False, indent=2), encoding="utf-8")
        logging.getLogger().setLevel(getattr(logging, str(self.config.get("log_level", "INFO")).upper(), logging.INFO))
        new_state_dir = pathlib.Path(self.config.get("state_dir", "/var/lib/leikwan-client-agent"))
        new_state_dir.mkdir(parents=True, exist_ok=True)
        if new_state_dir != self.state_dir:
            if self.applied_path.exists():
                target_applied = new_state_dir / "applied.json"
                if not target_applied.exists():
                    target_applied.write_text(self.applied_path.read_text(encoding="utf-8"), encoding="utf-8")
            if self.pull_state_path.exists():
                target_pull_state = new_state_dir / "config_pull_state.json"
                if not target_pull_state.exists():
                    target_pull_state.write_text(self.pull_state_path.read_text(encoding="utf-8"), encoding="utf-8")
            if self.usage_state_path.exists():
                target_usage_state = new_state_dir / "usage_state.json"
                if not target_usage_state.exists():
                    target_usage_state.write_text(self.usage_state_path.read_text(encoding="utf-8"), encoding="utf-8")
            self.state_dir = new_state_dir
            self.applied_path = new_state_dir / "applied.json"
            self.pull_state_path = new_state_dir / "config_pull_state.json"
            self.usage_state_path = new_state_dir / "usage_state.json"

        logging.info("applied client config patch keys=%s", ",".join(sorted(set(patch.keys()) & allowed_keys)))

    def request_self_update(self, request_token: str) -> None:
        helper_path = pathlib.Path("/usr/local/bin/leikwan-client-agent-update")
        if not helper_path.exists():
            raise RuntimeError(f"update helper missing: {helper_path}")

        state_path = self.update_state_path()
        if shutil.which("systemd-run"):
            unit_name = f"leikwan-client-agent-update-{int(time.time())}-{request_token[:8]}"
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
            subprocess.Popen(  # noqa: S603
                [
                    "/usr/bin/env",
                    "bash",
                    str(helper_path),
                    "--request-token",
                    request_token,
                    "--state-path",
                    str(state_path),
                ],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                stdin=subprocess.DEVNULL,
            )
        logging.info("requested client self-update token=%s", request_token)

    def collect_usage_totals(self) -> dict[str, int]:
        if not shutil.which("nft"):
            return {"bytes_in": 0, "bytes_out": 0}

        result = subprocess.run("nft -a list table inet leikwan_meter", shell=True, text=True, capture_output=True, check=False)
        if result.returncode != 0:
            return {"bytes_in": 0, "bytes_out": 0}

        usage_state: dict[str, int] = {}
        if self.usage_state_path.exists():
            try:
                loaded = json.loads(self.usage_state_path.read_text(encoding="utf-8"))
                if isinstance(loaded, dict):
                    usage_state = {str(key): int(value) for key, value in loaded.items()}
            except (json.JSONDecodeError, TypeError, ValueError):
                usage_state = {}

        current_bytes: dict[str, int] = {}
        for line in result.stdout.splitlines():
            comment_match = re.search(r'comment "([^"]+)"', line)
            bytes_match = re.search(r"bytes (\d+)", line)
            if comment_match and bytes_match:
                current_bytes[comment_match.group(1)] = int(bytes_match.group(1))

        totals = {"bytes_in": 0, "bytes_out": 0}
        for comment, total_bytes in current_bytes.items():
            if not comment.startswith("lw_client_"):
                continue
            previous = int(usage_state.get(comment, 0))
            delta = total_bytes - previous if total_bytes >= previous else total_bytes
            usage_state[comment] = total_bytes

            match = re.match(r"lw_client_(\d+)_(in|out)_(tcp|udp)", comment)
            if not match:
                continue
            direction = match.group(2)
            totals[f"bytes_{direction}"] += max(0, delta)

        self.usage_state_path.write_text(json.dumps(usage_state, ensure_ascii=False, indent=2), encoding="utf-8")
        return totals

    def apply_forward(self, forward: dict, contract_signature: str | None = None, force_runtime_restart: bool = False) -> None:
        from install_forward_runtime import render_forward_runtime  # local helper

        forward_id = int(forward["forward_id"])
        mode = self.normalize_mode(forward.get("mode", "single"))
        transport = str(forward.get("transport", "tcp+udp") or "tcp+udp").strip().lower()
        endpoints = [endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        bond_bin = self.bond_binary_path()
        mptcp_client = self.mptcp_client_path()
        if self.forward_uses_bond_runtime(forward) and not self.binary_available(bond_bin):
            logging.warning("forward %s requested bond runtime but bond binary is missing at %s", forward_id, bond_bin)
        if mode == "mptcp" and not mptcp_client.is_file():
            logging.warning("forward %s requested mptcp mode but helper is missing at %s", forward_id, mptcp_client)

        render_forward = dict(forward)
        candidate_pool = self.select_forward_candidates(render_forward)
        if candidate_pool:
            render_forward["candidate_pool"] = candidate_pool
        runtime_mode = self.choose_runtime_family(render_forward, self.running_kernel_supports_mptcp())
        render_forward = self.canonicalize_forward_runtime_contract(render_forward, runtime_mode)
        mode = self.normalize_mode(render_forward.get("mode", runtime_mode))

        if self.forward_uses_bond_runtime(render_forward):
            render_forward = self.apply_latency_target_candidate_escalation(render_forward)
            render_forward["endpoints"] = self.select_bond_runtime_endpoints(render_forward)
        elif mode == "mptcp":
            render_forward["candidate_pool"] = self.filter_mptcp_capable_candidates(
                [item for item in render_forward.get("candidate_pool", []) if isinstance(item, dict)]
            )
            render_forward = self.apply_mptcp_runtime_health_policy(render_forward)

        render_forward = self.strip_runtime_observation_fields(render_forward)
        contract_forward = dict(render_forward)
        rendered = render_forward_runtime(render_forward, self.semantic_agent_config())
        listen_port = int(forward.get("listen_port", 0) or 0)
        rendered_signature = self.rendered_runtime_signature(rendered, mode, transport, listen_port)
        effective_contract_signature = self.forward_contract_signature(contract_forward)
        if contract_signature is None or self.forward_uses_bond_runtime(render_forward) or mode == "mptcp":
            contract_signature = effective_contract_signature
        applied = self.load_applied()
        previous = applied.get(str(forward_id), {})
        previous_runtime_present = self.applied_forward_runtime_is_present(previous) if isinstance(previous, dict) and previous else False
        previous_runtime_mode = self.normalize_mode(previous.get("runtime_mode") or previous.get("published_mode") or "") if isinstance(previous, dict) else ""
        live_mptcp_runtime_interfaces = self.mptcp_forward_runtime_interfaces(forward_id) if mode != "mptcp" else []
        cleanup_for_runtime_transition = previous_runtime_mode == "mptcp" and mode != "mptcp"
        cleanup_for_stale_non_mptcp_runtime = mode != "mptcp" and live_mptcp_runtime_interfaces != []
        rendered_unchanged = isinstance(previous, dict) and str(previous.get("rendered_signature", "") or "") == rendered_signature
        if cleanup_for_runtime_transition or cleanup_for_stale_non_mptcp_runtime:
            if cleanup_for_stale_non_mptcp_runtime and not cleanup_for_runtime_transition:
                logging.info(
                    "forward %s mode=%s has stale mptcp/self-leg interfaces: %s; cleaning before no-op check",
                    forward_id,
                    mode,
                    ",".join(live_mptcp_runtime_interfaces),
                )
            self.cleanup_mptcp_forward_runtime(forward_id)
            previous_runtime_present = False
        if rendered_unchanged and previous_runtime_present:
            if force_runtime_restart:
                service_name = str(rendered.get("service_name", "") or f"leikwan-client-{forward_id}")
                subprocess.run("systemctl daemon-reload", shell=True, check=False)
                subprocess.run(f"systemctl enable {service_name}.service", shell=True, check=False)
                subprocess.run(f"systemctl restart {service_name}.service", shell=True, check=False)
                self.persist_forward_apply_signatures(forward_id, contract_signature, rendered_signature)
                logging.info("forward %s rendered runtime unchanged; restarted service only", forward_id)
                return
            self.persist_forward_apply_signatures(forward_id, contract_signature, rendered_signature)
            logging.debug("forward %s rendered runtime unchanged; recorded signature and skipped no-op apply", forward_id)
            return
        runtime_reapply_needed = force_runtime_restart

        logging.info(
            "applying forward %s mode=%s transport=%s endpoints=%s target=%s",
            forward_id,
            mode,
            transport,
            len(endpoints),
            str(forward.get("target", "")),
        )
        self.delete_nft_rules(forward_id)
        self.add_nft_rules(forward_id, listen_port, transport)
        previous_units: list[dict] = []
        previous_managed_paths: list[str] = []
        if isinstance(previous, dict):
            raw_units = previous.get("units", [])
            if isinstance(raw_units, list):
                previous_units = [item for item in raw_units if isinstance(item, dict)]
            previous_managed_paths = [
                str(path)
                for path in previous.get("managed_paths", [])
                if str(path).strip() != ""
            ]

        units = [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
        persisted_units: list[dict] = []
        mptcp_runtime_cleaned = False
        current_service_names = {str(unit.get("service_name", "") or "") for unit in units}
        for previous_unit in previous_units:
            previous_service_name = str(previous_unit.get("service_name", "") or "")
            if previous_service_name == "" or previous_service_name in current_service_names:
                continue
            subprocess.run(f"systemctl stop {previous_service_name}.service", shell=True, check=False)
            subprocess.run(f"systemctl disable {previous_service_name}.service", shell=True, check=False)

        for unit in units:
            service_name = str(unit["service_name"])
            service_path = pathlib.Path(str(unit["service_path"]))
            unit_changed = self.write_text_if_changed(service_path, str(unit["service_unit"]))
            start_script_path = pathlib.Path(str(unit["start_script_path"])) if str(unit.get("start_script_path", "")).strip() != "" else None
            script_changed = False
            if start_script_path is not None and str(unit.get("start_script", "")) != "":
                script_changed = self.write_text_if_changed(start_script_path, str(unit["start_script"]), mode=0o700)
            files_changed = False

            managed_paths = [str(service_path)]
            if start_script_path is not None:
                managed_paths.append(str(start_script_path))

            for file_entry in unit.get("files", []):
                extra_path = pathlib.Path(str(file_entry["path"]))
                file_changed = self.write_text_if_changed(
                    extra_path,
                    str(file_entry.get("content", "")),
                    int(file_entry["mode"]) if "mode" in file_entry and file_entry.get("mode") is not None else None,
                )
                files_changed = files_changed or file_changed
                managed_paths.append(str(extra_path))

            service_active = self.systemd_is_active(service_name)
            if unit_changed or script_changed or files_changed or not service_active or runtime_reapply_needed:
                if mode == "mptcp" and not mptcp_runtime_cleaned:
                    self.cleanup_mptcp_forward_runtime(forward_id)
                    mptcp_runtime_cleaned = True
                subprocess.run("systemctl daemon-reload", shell=True, check=False)
                subprocess.run(f"systemctl enable {service_name}.service", shell=True, check=False)
                subprocess.run(f"systemctl restart {service_name}.service", shell=True, check=False)

            persisted_units.append(
                {
                    "service_name": service_name,
                    "managed_paths": managed_paths,
                }
            )

            logging.info(
                "applied forward %s service=%s unit_changed=%s script_changed=%s",
                forward_id,
                service_name,
                unit_changed,
                script_changed,
            )

        current_managed_paths = {path for unit in persisted_units for path in unit["managed_paths"]}
        stale_path_removed = False
        for previous_path_raw in previous_managed_paths:
            if previous_path_raw in current_managed_paths:
                continue
            previous_path = pathlib.Path(previous_path_raw)
            if previous_path.exists():
                previous_path.unlink()
                stale_path_removed = True
        if stale_path_removed:
            subprocess.run("systemctl daemon-reload", shell=True, check=False)

        applied[str(forward_id)] = {
            "units": persisted_units,
            "managed_paths": sorted(current_managed_paths),
            "published_mode": self.normalize_mode(forward.get("mode", "single")),
            "runtime_mode": mode,
            "contract_signature": contract_signature,
            "rendered_signature": rendered_signature,
            "updated_at": int(time.time()),
        }
        self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")

    def stop_forward(self, forward_id: int, delete_files: bool = False) -> None:
        applied = self.load_applied()
        previous = applied.get(str(forward_id), {})
        units = previous.get("units", []) if isinstance(previous, dict) else []
        if not units:
            units = [{"service_name": f"leikwan-client-{forward_id}", "managed_paths": []}]

        for unit in units:
            service_name = str(unit.get("service_name", "") or "")
            if service_name == "":
                continue
            subprocess.run(f"systemctl stop {service_name}.service", shell=True, check=False)
            subprocess.run(f"systemctl disable {service_name}.service", shell=True, check=False)
            service_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
            if delete_files and service_path.exists():
                service_path.unlink()
            if delete_files:
                start_script_path = pathlib.Path(f"/usr/local/bin/leikwan-client-start-{forward_id}.sh")
                if start_script_path.exists():
                    start_script_path.unlink()

        self.delete_nft_rules(forward_id)
        if delete_files:
            self.cleanup_mptcp_forward_runtime(forward_id)
            if isinstance(previous, dict):
                for managed_path_raw in previous.get("managed_paths", []):
                    managed_path = pathlib.Path(str(managed_path_raw))
                    if managed_path.exists():
                        managed_path.unlink()
            subprocess.run("systemctl daemon-reload", shell=True, check=False)

    def cleanup_mptcp_forward_runtime(self, forward_id: int) -> None:
        if forward_id <= 0:
            return
        self.__class__.cleanup_orphan_mptcp_runtime(set(), target_forward_ids={forward_id})

    @classmethod
    def mptcp_forward_runtime_interfaces(cls, forward_id: int) -> list[str]:
        if forward_id <= 0 or shutil.which("wg") is None:
            return []
        result = subprocess.run(["wg", "show", "interfaces"], text=True, capture_output=True, check=False)
        interfaces = result.stdout.strip().split()
        matches: list[str] = []
        for iface in interfaces:
            if (
                re.match(rf"^lw-mptcp-{forward_id}-", iface)
                or re.match(rf"^lw-cfwg-{forward_id}$", iface)
                or re.match(rf"^lw-cfsr-{forward_id}$", iface)
                or re.match(rf"^lw-self-{forward_id}$", iface)
            ):
                matches.append(iface)
        return sorted(matches)

    @classmethod
    def cleanup_orphan_mptcp_runtime(
        cls,
        active_forward_ids: set[int],
        target_forward_ids: set[int] | None = None,
    ) -> None:
        if shutil.which("wg") is None:
            return
        result = subprocess.run(["wg", "show", "interfaces"], text=True, capture_output=True, check=False)
        interfaces = result.stdout.strip().split()
        removed_service_units = False
        for iface in interfaces:
            match = (
                re.match(r"^lw-mptcp-(\d+)-", iface)
                or re.match(r"^lw-cfwg-(\d+)$", iface)
                or re.match(r"^lw-cfsr-(\d+)$", iface)
                or re.match(r"^lw-self-(\d+)$", iface)
            )
            if match is None:
                continue
            forward_id = int(match.group(1))
            if target_forward_ids is not None:
                should_remove = forward_id in target_forward_ids
            else:
                should_remove = forward_id not in active_forward_ids
            if not should_remove:
                continue
            subprocess.run(["systemctl", "stop", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "reset-failed", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["wg-quick", "down", iface], text=True, capture_output=True, check=False)
            subprocess.run(["ip", "link", "delete", "dev", iface], text=True, capture_output=True, check=False)
            conf_path = pathlib.Path(f"/etc/wireguard/{iface}.conf")
            if conf_path.exists():
                conf_path.unlink()
            unit_root = pathlib.Path("/etc/systemd/system")
            if unit_root.exists():
                for unit_path in unit_root.glob(f"leikwan-uot-lw-mptcp-{forward_id}-*.service"):
                    subprocess.run(["systemctl", "stop", unit_path.name], text=True, capture_output=True, check=False)
                    subprocess.run(["systemctl", "disable", unit_path.name], text=True, capture_output=True, check=False)
                    unit_path.unlink()
                    removed_service_units = True
        if removed_service_units:
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)

    def ensure_runtime_health(self, forwards: list[dict]) -> None:
        if not forwards:
            return

        for forward in forwards:
            if str(forward.get("service_status", "active")) != "active":
                continue
            if self.has_internal_path_runtime(forward):
                continue

            forward_id = int(forward.get("forward_id", 0) or 0)
            if forward_id <= 0:
                continue

            service_name = f"leikwan-client-{forward_id}"
            if not self.systemd_is_active(service_name):
                self.restart_forward_service(forward_id, service_name, "systemd inactive")
                continue

            endpoints = [endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
            mode = self.applied_runtime_mode(forward_id) or self.normalize_mode(forward.get("mode", "single"))
            if mode == "mptcp":
                if self.mptcp_setup_in_progress(forward_id):
                    logging.info("forward %s mptcp setup-underlays still in progress; skipping health probe", forward_id)
                    continue
                listen_port = int(forward.get("listen_port", 0) or 0)
                local_ok, _, local_error = self.local_listener_present(listen_port, "tcp")
                if not local_ok:
                    active_age = self.service_active_age_seconds(service_name)
                    if active_age is not None and active_age < 20.0:
                        logging.info(
                            "forward %s mptcp service started %.1fs ago; deferring local listener restart: %s",
                            forward_id,
                            active_age,
                            local_error,
                        )
                        continue
                    self.restart_forward_service(forward_id, service_name, f"mptcp local listener probe failed: {local_error}")
                    continue
                mptcp = forward.get("mptcp", {})
                if not isinstance(mptcp, dict):
                    mptcp = {}
                candidates = self.mptcp_leg_candidates(forward, mptcp)
                if candidates:
                    selected_self_leg_primary = self.forward_has_selected_self_leg(forward_id, forward)
                    cf_wg_candidates = [
                        candidate
                        for candidate in candidates
                        if str(candidate.get("leg", {}).get("outer_transport", "") or "").strip().lower() == "wstunnel_udp"
                    ]
                    ready_results = []
                    refresh_needed = False
                    for candidate in candidates:
                        endpoint_id = int(candidate.get("endpoint_id", 0) or 0)
                        previous_role = (
                            self.load_bond_endpoint_health()
                            .get(str(forward_id), {})
                            .get(str(endpoint_id), {})
                            .get("role", "healthy")
                        )
                        ready, latency_ms, error = self.mptcp_candidate_health_probe(candidate)
                        ready_results.append(ready)
                        updated = self.record_bond_endpoint_probe_result(
                            forward_id,
                            endpoint_id,
                            ready,
                            latency_ms,
                            error,
                            one_leg_rtt_ms=latency_ms,
                        )
                        if str(previous_role or "healthy") != str(updated.get("role", "healthy") or "healthy"):
                            refresh_needed = True
                    cf_wg_ready = any(
                        ready
                        for candidate, ready in zip(candidates, ready_results)
                        if candidate in cf_wg_candidates
                    )
                    if not any(ready_results):
                        if selected_self_leg_primary:
                            logging.warning(
                                "forward %s has selected cf_wg self-leg primary; helper mptcp probes all failed, "
                                "keeping current runtime shape",
                                forward_id,
                            )
                            continue
                        logging.warning(
                            "forward %s has a live local mptcp listener but all sidecar leg tcp probes failed; "
                            "isolating failed legs and refreshing runtime instead of restarting",
                            forward_id,
                        )
                        self.refresh_mptcp_runtime_endpoints(forward_id, dict(forward), service_name)
                        continue
                    if refresh_needed or not all(ready_results):
                        if (selected_self_leg_primary or cf_wg_candidates) and any(ready_results):
                            logging.info(
                                "forward %s selected self-leg remains reachable; deferring helper-only mptcp health refresh",
                                forward_id,
                            )
                            continue
                        self.refresh_mptcp_runtime_endpoints(forward_id, dict(forward), service_name)
                else:
                    remote_hosts = self.mptcp_remote_hosts(mptcp)
                    remote_port = int(mptcp.get("sidecar_port", 0) or mptcp.get("remote_port", 0) or 0)
                    remote_ok, _, remote_error, _reachable_host = self.remote_tcp_probe_any(remote_hosts, remote_port)
                    if not remote_ok:
                        self.restart_forward_service(forward_id, service_name, f"mptcp sidecar probe failed: {remote_error}")
                continue
            listen_port = int(forward.get("listen_port", 0) or 0)
            local_ok, _, local_error = self.local_runtime_probe(
                listen_port,
                str(forward.get("transport", "tcp+udp") or "tcp+udp"),
            )
            if not local_ok:
                self.restart_forward_service(forward_id, service_name, f"local listen probe failed: {local_error}")
                continue
            if endpoints == []:
                continue

            ready_results = []
            bonding = forward.get("bonding", {})
            if not isinstance(bonding, dict):
                bonding = {}
            bond_runtime = self.forward_uses_bond_runtime(forward)
            plain_tcp_ingress = bond_runtime and str(bonding.get("ingress_mode", "wss_path_mux")) == "plain_tcp_mux"
            backend_mode = str(forward.get("backend_mode", "current_mode") or "current_mode")
            bond_session_token = str(bonding.get("session_token", "") or "").strip()
            self_leg_primary = bond_runtime and self.forward_has_selected_self_leg(forward_id, forward)
            for endpoint_index, endpoint in enumerate(endpoints):
                bond_one_leg_probe_failed = False
                if plain_tcp_ingress:
                    ready, latency_ms, error = self.remote_plain_tcp_ready_probe(
                        str(endpoint.get("host", "")),
                        int(endpoint.get("port", 0) or 0),
                        str(bonding.get("route_key", "") or forward.get("path_prefix", "")),
                        str(endpoint.get("ingress_auth_token", "") or bonding.get("ingress_auth_token", "")),
                    )
                else:
                    ready, latency_ms, error = self.remote_ready_probe(
                        str(endpoint.get("host", "")),
                        int(endpoint.get("port", 0) or 0),
                        str(forward.get("sni", "") or endpoint.get("cdn_host", "") or endpoint.get("host", "")),
                        str(forward.get("path_prefix", "")),
                    )
                if ready and bond_runtime and plain_tcp_ingress and bond_session_token != "":
                    ready, stronger_latency_ms, stronger_error = self.cf_bond_one_leg_connect_probe(forward, endpoint, endpoint_index)
                    if stronger_latency_ms is not None:
                        latency_ms = stronger_latency_ms
                    if stronger_error is not None:
                        error = stronger_error
                    bond_one_leg_probe_failed = not ready
                ready_results.append(ready)
                if bond_runtime:
                    endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
                    previous_role = (
                        self.load_bond_endpoint_health()
                        .get(str(forward_id), {})
                        .get(str(endpoint_id), {})
                        .get("role", "healthy")
                    )
                    if not ready and plain_tcp_ingress and (backend_mode == "cf_mode" or bond_one_leg_probe_failed):
                        updated = self.force_bond_endpoint_quarantine(
                            forward_id,
                            endpoint_id,
                            error,
                            latency_ms,
                        )
                    else:
                        updated = self.record_bond_endpoint_probe_result(
                            forward_id,
                            endpoint_id,
                            ready,
                            latency_ms,
                            error,
                        )
                    if str(previous_role or "healthy") != str(updated.get("role", "healthy") or "healthy"):
                        if not self_leg_primary:
                            self.refresh_bond_runtime_endpoints(forward_id, dict(forward), service_name)

            if mode in {"race", "bond"}:
                if not any(ready_results):
                    if self_leg_primary:
                        logging.warning(
                            "forward %s has selected self-leg primary; slave helper ready probes all failed, "
                            "quarantining helpers without restarting",
                            forward_id,
                        )
                        continue
                    self.restart_forward_service(forward_id, service_name, "all slave endpoints failed ready probe")
                    continue

                if bond_runtime and plain_tcp_ingress and not self_leg_primary:
                    live_matches = self.collect_live_bond_slave_connections(forward)
                    if live_matches is not None and live_matches == []:
                        self.restart_forward_service(
                            forward_id,
                            service_name,
                            "published runtime mismatch: no live slave connections matched panel endpoints",
                        )
                        continue

                healthy_endpoints = [endpoint for endpoint, ready in zip(endpoints, ready_results) if ready]
                unhealthy_endpoints = [endpoint for endpoint, ready in zip(endpoints, ready_results) if not ready]
                desired_order = healthy_endpoints + unhealthy_endpoints
                current_order = [int(endpoint.get("endpoint_id", 0) or 0) for endpoint in endpoints]
                next_order = [int(endpoint.get("endpoint_id", 0) or 0) for endpoint in desired_order]
                if mode == "race" and healthy_endpoints and unhealthy_endpoints and current_order != next_order:
                    self.reorder_forward_endpoints(forward_id, dict(forward), desired_order, service_name)
            elif not ready_results[0]:
                self.restart_forward_service(forward_id, service_name, "primary slave endpoint failed ready probe")

    def forward_has_selected_self_leg(self, forward_id: int, forward: dict) -> bool:
        candidate_pool = [item for item in forward.get("candidate_pool", []) if isinstance(item, dict)]
        selected = next((item for item in candidate_pool if bool(item.get("selected_primary", False))), None)
        if isinstance(selected, dict) and str(selected.get("candidate_kind", "") or "") == "self_leg":
            return True
        if selected is None and candidate_pool:
            first = candidate_pool[0]
            if str(first.get("candidate_kind", "") or "") == "self_leg" and bool(first.get("ready", True)):
                return True

        script_path = pathlib.Path(f"/usr/local/bin/leikwan-client-start-{int(forward_id)}.sh")
        try:
            script = script_path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            return False
        return "display_host=self_leg" in script or "candidate_kind=self_leg" in script

    def applied_runtime_mode(self, forward_id: int) -> str:
        try:
            payload = self.load_applied().get(str(int(forward_id)), {})
        except Exception:
            payload = {}
        if isinstance(payload, dict) and payload:
            raw_mode = payload.get("runtime_mode") or payload.get("published_mode") or ""
            if str(raw_mode).strip() != "":
                return self.normalize_mode(raw_mode)
        return ""

    @staticmethod
    def systemd_is_active(service_name: str) -> bool:
        result = subprocess.run(f"systemctl is-active {service_name}.service", shell=True, text=True, capture_output=True)
        return result.stdout.strip() == "active"

    @staticmethod
    def service_status_matches_bond_restart_rule(service_name: str) -> bool:
        commands = [
            f"journalctl -u {service_name}.service --no-pager -n 200 --since '-2 min'",
            f"systemctl status {service_name}.service --no-pager -n 120",
        ]

        output = ""
        for command in commands:
            result = subprocess.run(
                command,
                shell=True,
                text=True,
                capture_output=True,
                check=False,
            )
            candidate = f"{getattr(result, 'stdout', '')}\n{getattr(result, 'stderr', '')}".lower()
            if candidate.strip() != "":
                output = candidate
                break

        if "bond tcp stream" not in output:
            return False
        return (
            "connection reset by peer (os error 104)" in output
            or "broken pipe (os error 32)" in output
        )

    @staticmethod
    def service_active_age_seconds(service_name: str) -> float | None:
        if not service_name.strip():
            return None
        result = subprocess.run(
            ["systemctl", "show", service_name, "-p", "ActiveEnterTimestampMonotonic", "--value"],
            text=True,
            capture_output=True,
            check=False,
        )
        if result.returncode != 0:
            return None
        try:
            active_us = int(result.stdout.strip() or "0")
        except ValueError:
            return None
        if active_us <= 0:
            return None
        try:
            uptime_ticks = int(float(pathlib.Path("/proc/uptime").read_text(encoding="utf-8").split()[0]) * 1_000_000)
        except (OSError, IndexError, ValueError):
            return None
        if uptime_ticks < active_us:
            return None
        return (uptime_ticks - active_us) / 1_000_000.0

    @staticmethod
    def mptcp_setup_in_progress(forward_id: int) -> bool:
        if forward_id <= 0:
            return False
        pattern = f"leikwan-mptcp-client.py --config /etc/leikwan/mptcp-forward-{forward_id}.json --setup-underlays"
        result = subprocess.run(
            ["pgrep", "-f", pattern],
            text=True,
            capture_output=True,
            check=False,
        )
        return result.returncode == 0 and result.stdout.strip() != ""

    def restart_forward_service(self, forward_id: int, service_name: str, reason: str) -> None:
        now = time.time()
        next_allowed = self.health_restart_after.get(forward_id, 0.0)
        if now < next_allowed:
            return

        subprocess.run(f"systemctl enable {service_name}.service", shell=True, check=False)
        subprocess.run(f"systemctl restart {service_name}.service", shell=True, check=False)
        self.health_restart_after[forward_id] = now + 30
        logging.warning("restarted %s due to runtime health check: %s", service_name, reason)

    def reorder_forward_endpoints(self, forward_id: int, forward: dict, endpoints: list[dict], service_name: str) -> None:
        now = time.time()
        next_allowed = self.health_reorder_after.get(forward_id, 0.0)
        if now < next_allowed:
            return

        forward["endpoints"] = endpoints
        self.apply_forward(forward)
        self.health_reorder_after[forward_id] = now + 30
        for index, latest_forward in enumerate(self.latest_forwards):
            if int(latest_forward.get("forward_id", 0) or 0) != forward_id:
                continue
            self.latest_forwards[index] = forward
            break
        logging.info("reordered %s endpoints to prefer healthy slave routes", service_name)

    def refresh_bond_runtime_endpoints(self, forward_id: int, forward: dict, service_name: str) -> None:
        now = time.time()
        next_allowed = self.health_reorder_after.get(forward_id, 0.0)
        if now < next_allowed:
            return

        self.apply_forward(forward)
        self.health_reorder_after[forward_id] = now + 30
        for index, latest_forward in enumerate(self.latest_forwards):
            if int(latest_forward.get("forward_id", 0) or 0) != forward_id:
                continue
            self.latest_forwards[index] = forward
            break
        logging.info("refreshed %s to apply bond endpoint health policy", service_name)

    def refresh_mptcp_runtime_endpoints(self, forward_id: int, forward: dict, service_name: str) -> None:
        now = time.time()
        next_allowed = self.health_reorder_after.get(forward_id, 0.0)
        if now < next_allowed:
            return

        contract_signature = self.effective_forward_contract_signature(forward)
        if self.forward_apply_is_noop(forward_id, contract_signature):
            self.persist_forward_apply_signatures(forward_id, contract_signature)
            self.health_reorder_after[forward_id] = now + 30
            logging.debug("refreshed %s mptcp leg health policy already rendered; no-op", service_name)
            return

        self.apply_forward(forward, contract_signature=contract_signature, force_runtime_restart=False)
        self.health_reorder_after[forward_id] = now + 30
        for index, latest_forward in enumerate(self.latest_forwards):
            if int(latest_forward.get("forward_id", 0) or 0) != forward_id:
                continue
            self.latest_forwards[index] = forward
            break
        logging.info("refreshed %s to apply mptcp leg health policy", service_name)

    def load_applied(self) -> dict:
        if not self.applied_path.exists():
            return {}
        try:
            return json.loads(self.applied_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}

    def bond_endpoint_health_path(self) -> pathlib.Path:
        return self.state_dir / "bond-endpoint-health.json"

    def load_bond_endpoint_health(self) -> dict:
        path = self.bond_endpoint_health_path()
        if not path.exists():
            return {}
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}

    def save_bond_endpoint_health(self, payload: dict) -> None:
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.write_text_if_changed(
            self.bond_endpoint_health_path(),
            json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        )

    @staticmethod
    def bond_runtime_base_port(forward_id: int) -> int:
        return 20000 + ((forward_id * 37) % 15000)

    @classmethod
    def bond_runtime_endpoint_back_port(cls, forward_id: int, endpoint_index: int, protocol: str = "tcp") -> int:
        base = cls.bond_runtime_base_port(forward_id)
        offset = max(0, int(endpoint_index))
        return base + offset + (200 if str(protocol).strip().lower() == "udp" else 0)

    def record_bond_endpoint_probe_result(
        self,
        forward_id: int,
        endpoint_id: int,
        ok: bool,
        latency_ms: float | None,
        error: str | None,
        now: float | None = None,
        one_leg_rtt_ms: float | None = None,
    ) -> dict:
        now = time.time() if now is None else now
        health = self.load_bond_endpoint_health()
        entry = health.setdefault(str(forward_id), {}).setdefault(
            str(endpoint_id),
            {
                "role": "healthy",
                "consecutive_failures": 0,
                "consecutive_successes": 0,
                "quarantine_until": 0.0,
                "probation_until": 0.0,
                "last_error": "",
                "last_latency_ms": None,
                "one_leg_rtt_ms": None,
                "quality_score": 1.0,
            },
        )

        if ok:
            entry["consecutive_successes"] = int(entry.get("consecutive_successes", 0) or 0) + 1
            entry["consecutive_failures"] = 0
            entry["last_error"] = ""
            entry["last_latency_ms"] = latency_ms
            if one_leg_rtt_ms is not None:
                entry["one_leg_rtt_ms"] = one_leg_rtt_ms
            if latency_ms is None:
                entry["quality_score"] = 1.0
            else:
                clamped = max(0.0, min(float(latency_ms), 200.0))
                entry["quality_score"] = round(max(0.1, 1.0 - (clamped / 200.0)), 4)
            if float(entry.get("quarantine_until", 0.0) or 0.0) > 0:
                entry["role"] = "probation"
                entry["probation_until"] = max(
                    float(entry.get("probation_until", 0.0) or 0.0),
                    now + CLIENT_BOND_ENDPOINT_PROBATION_SECONDS,
                )
                entry["quarantine_until"] = 0.0
            elif int(entry["consecutive_successes"]) >= 3:
                entry["role"] = "healthy"
                entry["probation_until"] = 0.0
        else:
            entry["consecutive_failures"] = int(entry.get("consecutive_failures", 0) or 0) + 1
            entry["consecutive_successes"] = 0
            entry["last_error"] = error or "probe_failed"
            if int(entry["consecutive_failures"]) >= 3:
                entry["role"] = "quarantined"
                entry["quarantine_until"] = now + CLIENT_BOND_ENDPOINT_QUARANTINE_SECONDS
                entry["probation_until"] = 0.0
                entry["quality_score"] = 0.0
            elif int(entry["consecutive_failures"]) >= 2:
                entry["role"] = "probation"
                entry["probation_until"] = max(
                    float(entry.get("probation_until", 0.0) or 0.0),
                    now + CLIENT_BOND_ENDPOINT_PROBATION_SECONDS,
                )
                entry["quality_score"] = min(float(entry.get("quality_score", 1.0) or 1.0), 0.5)

        self.save_bond_endpoint_health(health)
        return entry

    def force_bond_endpoint_quarantine(
        self,
        forward_id: int,
        endpoint_id: int,
        error: str | None,
        latency_ms: float | None = None,
        now: float | None = None,
    ) -> dict:
        now = time.time() if now is None else now
        health = self.load_bond_endpoint_health()
        entry = health.setdefault(str(forward_id), {}).setdefault(
            str(endpoint_id),
            {
                "role": "healthy",
                "consecutive_failures": 0,
                "consecutive_successes": 0,
                "quarantine_until": 0.0,
                "probation_until": 0.0,
                "last_error": "",
                "last_latency_ms": None,
                "one_leg_rtt_ms": None,
                "quality_score": 1.0,
            },
        )
        entry["role"] = "quarantined"
        entry["consecutive_failures"] = max(3, int(entry.get("consecutive_failures", 0) or 0) + 1)
        entry["consecutive_successes"] = 0
        entry["quarantine_until"] = now + CLIENT_BOND_ENDPOINT_QUARANTINE_SECONDS
        entry["probation_until"] = 0.0
        entry["last_error"] = error or "cf_bond_leg_probe_failed"
        entry["last_latency_ms"] = latency_ms
        entry["quality_score"] = 0.0
        self.save_bond_endpoint_health(health)
        return entry

    @staticmethod
    def latency_first_primary_sort_key(endpoint: dict) -> tuple[float, float, int, int]:
        rtt_value = 999999.0
        for key in ("one_leg_rtt_ms", "latency_ewma_ms", "last_latency_ms"):
            try:
                candidate = float(endpoint.get(key))
            except (TypeError, ValueError):
                continue
            if candidate > 0:
                rtt_value = candidate
                break
        quality_score = float(endpoint.get("quality_score", 0.0) or 0.0)
        dynamic_priority = int(endpoint.get("dynamic_priority", 0) or 0)
        endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
        return (rtt_value, -quality_score, dynamic_priority, endpoint_id)

    @staticmethod
    def cf_bond_runtime_health_entry(leg: dict, now: float) -> tuple[str, dict] | None:
        endpoint_id = int(leg.get("endpoint_id", 0) or 0)
        tcp = leg.get("tcp", {}) if isinstance(leg.get("tcp", {}), dict) else {}
        if endpoint_id <= 0 or tcp == {}:
            return None

        runtime_state = str(tcp.get("state", "healthy") or "healthy").strip().lower()
        runtime_reason = str(tcp.get("last_reason", "") or "").strip()

        role = "healthy"
        quarantine_until = 0.0
        probation_until = 0.0
        if runtime_state == "quarantined":
            role = "quarantined"
            quarantine_until = now + 90.0
        elif runtime_state in {"probation", "degraded"}:
            role = "probation"
            probation_until = now + 60.0

        return (
            str(endpoint_id),
            {
                "role": role,
                "consecutive_failures": 3 if role == "quarantined" else (2 if role == "probation" else 0),
                "consecutive_successes": 3 if role == "healthy" else 0,
                "quarantine_until": quarantine_until,
                "probation_until": probation_until,
                "last_error": runtime_reason,
                "last_latency_ms": None,
                "one_leg_rtt_ms": None,
                "quality_score": 0.6 if role == "healthy" else (0.4 if role == "probation" else 0.0),
            },
        )

    @classmethod
    def latency_first_bond_enabled(cls, forward: dict) -> bool:
        if not cls.forward_uses_bond_runtime(forward):
            return False
        bond = forward.get("bonding", {})
        if not isinstance(bond, dict):
            return False
        return str(bond.get("policy_mode", "") or "").strip() == "latency_first"

    @staticmethod
    def latency_first_mptcp_enabled(forward: dict) -> bool:
        if ClientAgent.normalize_mode(forward.get("mode", "single")) != "mptcp":
            return False
        mptcp = forward.get("mptcp", {})
        if not isinstance(mptcp, dict):
            mptcp = {}
        return str(mptcp.get("policy_mode", "latency_first") or "latency_first").strip() == "latency_first"

    @staticmethod
    def endpoint_id_for_runtime(endpoint: dict, fallback_index: int) -> int:
        try:
            endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
        except (TypeError, ValueError):
            endpoint_id = 0
        return endpoint_id if endpoint_id > 0 else fallback_index + 1

    def mptcp_endpoint_health_snapshot(self, forward: dict, now: float) -> dict:
        forward_id = int(forward.get("forward_id", 0) or 0)
        if forward_id <= 0:
            return {}
        return self.load_bond_endpoint_health().get(str(forward_id), {})

    def select_mptcp_runtime_endpoints(self, forward: dict, now: float | None = None) -> list[dict]:
        now = time.time() if now is None else now
        endpoints = [dict(endpoint) for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        if not endpoints:
            return []

        mptcp = forward.get("mptcp", {})
        if not isinstance(mptcp, dict):
            mptcp = {}
        health = self.mptcp_endpoint_health_snapshot(forward, now)
        base_priority = 10

        decorated = []
        deferred_panel_pending = []
        for index, endpoint in enumerate(endpoints):
            endpoint_id = self.endpoint_id_for_runtime(endpoint, index)
            endpoint["endpoint_id"] = endpoint_id
            endpoint["leg"] = self.mptcp_leg_for_endpoint(forward, endpoint_id)
            entry = dict(health.get(str(endpoint_id), {}))
            leg_panel_health = ""
            leg = endpoint.get("leg", {}) if isinstance(endpoint.get("leg", {}), dict) else {}
            if isinstance(leg, dict):
                leg_panel_health = str(leg.get("health_status", "") or "").strip().lower()
            panel_health = str(endpoint.get("health_status", leg_panel_health or "active") or leg_panel_health or "active").strip().lower()
            if panel_health not in {"", "active"}:
                deferred_panel_pending.append(endpoint)
                continue
            role = str(entry.get("role", "healthy") or "healthy")
            quarantine_until = float(entry.get("quarantine_until", 0.0) or 0.0)
            probation_until = float(entry.get("probation_until", 0.0) or 0.0)
            if role == "quarantined" and quarantine_until <= now:
                role = "probation"
            if role == "probation" and probation_until <= now and int(entry.get("consecutive_successes", 0) or 0) >= 3:
                role = "healthy"

            endpoint["health_role"] = role
            endpoint["quality_score"] = float(entry.get("quality_score", 1.0 if role == "healthy" else (0.5 if role == "probation" else 0.0)) or 0.0)
            endpoint["one_leg_rtt_ms"] = entry.get("one_leg_rtt_ms")
            endpoint["latency_ewma_ms"] = entry.get("latency_ewma_ms")
            endpoint["last_latency_ms"] = entry.get("last_latency_ms")
            if role == "healthy":
                endpoint["dynamic_priority"] = base_priority
            elif role == "probation":
                endpoint["dynamic_priority"] = base_priority + 15
            else:
                endpoint["dynamic_priority"] = base_priority + 30
            decorated.append(endpoint)

        latency_first = self.latency_first_mptcp_enabled(forward)
        if not decorated:
            decorated = deferred_panel_pending

        if not latency_first:
            preferred = [item for item in decorated if item.get("health_role", "healthy") != "quarantined"]
            if preferred:
                preferred.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
                return preferred
            decorated.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
            return decorated[:1]

        healthy = [item for item in decorated if item.get("health_role", "healthy") == "healthy"]
        healthy.sort(key=self.latency_first_primary_sort_key)
        probation = [item for item in decorated if item["health_role"] == "probation"]
        probation.sort(key=self.latency_first_primary_sort_key)
        quarantined = [item for item in decorated if item["health_role"] == "quarantined"]
        quarantined.sort(key=self.latency_first_primary_sort_key)

        active_limit = max(0, int(mptcp.get("active_leg_limit", 0) or 0))
        selected = healthy or probation[:1] or quarantined[:1]
        if active_limit > 0 and len(selected) > active_limit:
            selected = selected[:active_limit]

        # Slave-preconnect health is only observable through the client->slave
        # WG address.  If a helper was quarantined while its WG was removed, it
        # can never recover because the health probe has no route. Keep one
        # non-active standby underlay rendered as backup-only so the health
        # loop can keep probing the preconnect relay without making it the
        # primary data path.
        if (healthy or self.forward_has_selected_self_leg(int(forward.get("forward_id", 0) or 0), forward)) and len(selected) < 2:
            selected_ids = {int(item.get("endpoint_id", 0) or 0) for item in selected}
            for standby_pool in (probation, quarantined):
                for standby in standby_pool:
                    standby_id = int(standby.get("endpoint_id", 0) or 0)
                    if standby_id in selected_ids or not self.endpoint_has_preconnect_probe(standby):
                        continue
                    standby["leg_role"] = "standby"
                    standby["backup_only"] = True
                    selected.append(standby)
                    selected_ids.add(standby_id)
                    if len(selected) >= 2:
                        break
                if len(selected) >= 2:
                    break

        for index, endpoint in enumerate(selected):
            if str(endpoint.get("leg_role", "") or "") == "standby":
                endpoint["backup_only"] = True
                continue
            endpoint["leg_role"] = "primary" if index == 0 else "helper"
        return selected

    @staticmethod
    def mptcp_leg_for_endpoint(forward: dict, endpoint_id: int) -> dict:
        mptcp = forward.get("mptcp", {}) if isinstance(forward, dict) else {}
        if not isinstance(mptcp, dict):
            return {}
        underlays = mptcp.get("underlays", [])
        if not isinstance(underlays, list) or not underlays:
            underlay = mptcp.get("underlay", {})
            underlays = [underlay] if isinstance(underlay, dict) else []
        for underlay in underlays:
            if not isinstance(underlay, dict):
                continue
            legs = underlay.get("legs", [])
            if not isinstance(legs, list):
                continue
            for leg in legs:
                if not isinstance(leg, dict):
                    continue
                try:
                    leg_endpoint_id = int(leg.get("endpoint_id", 0) or 0)
                except (TypeError, ValueError):
                    leg_endpoint_id = 0
                if leg_endpoint_id == endpoint_id:
                    return dict(leg)
        return {}

    @staticmethod
    def endpoint_has_preconnect_probe(endpoint: dict) -> bool:
        leg = endpoint.get("leg", {}) if isinstance(endpoint, dict) else {}
        if not isinstance(leg, dict):
            leg = {}
        try:
            relay_port = int(leg.get("preconnect_relay_port", 0) or 0)
        except (TypeError, ValueError):
            relay_port = 0
        return relay_port > 0 or bool(leg.get("slave_preconnect", False))

    @staticmethod
    def mptcp_underlay_leg_endpoint_id(leg: dict, endpoint_by_address: dict[str, int]) -> int:
        try:
            endpoint_id = int(leg.get("endpoint_id", 0) or 0)
        except (TypeError, ValueError):
            endpoint_id = 0
        if endpoint_id > 0:
            return endpoint_id
        for key in ("address", "local_address", "client_address", "mptcp_remote_address", "peer_public_host", "peer_address"):
            value = str(leg.get(key, "") or "").strip()
            mapped = endpoint_by_address.get(value, 0)
            if mapped > 0:
                return mapped
        return 0

    def apply_mptcp_runtime_health_policy(self, forward: dict, now: float | None = None) -> dict:
        if self.normalize_mode(forward.get("mode", "single")) != "mptcp":
            return forward

        now = time.time() if now is None else now
        selected = self.select_mptcp_runtime_endpoints(forward, now=now)
        if not selected:
            return forward

        selected_ids = {int(endpoint.get("endpoint_id", 0) or 0) for endpoint in selected}
        selected_by_id = {int(endpoint.get("endpoint_id", 0) or 0): endpoint for endpoint in selected}
        selected_rank = {int(endpoint.get("endpoint_id", 0) or 0): index for index, endpoint in enumerate(selected)}
        endpoint_by_address: dict[str, int] = {}
        for endpoint in selected:
            endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
            for key in ("host", "mptcp_remote_address", "peer_public_host"):
                value = str(endpoint.get(key, "") or "").strip()
                if endpoint_id > 0 and value != "":
                    endpoint_by_address[value] = endpoint_id

        next_forward = dict(forward)
        next_forward["endpoints"] = selected
        mptcp = dict(forward.get("mptcp", {}) if isinstance(forward.get("mptcp", {}), dict) else {})
        mptcp.setdefault("policy_mode", "latency_first")

        primary_leg: dict | None = None
        primary_endpoint = selected[0] if selected else {}
        filtered_underlays = []
        used_underlay_keys: set[tuple[str, str]] = set()
        raw_underlays = mptcp.get("underlays", [])
        if isinstance(raw_underlays, list):
            for raw_underlay in raw_underlays:
                if not isinstance(raw_underlay, dict):
                    continue
                underlay = dict(raw_underlay)
                raw_legs = underlay.get("legs", [])
                if not isinstance(raw_legs, list):
                    raw_legs = []
                filtered_legs = []
                for raw_leg in raw_legs:
                    if not isinstance(raw_leg, dict):
                        continue
                    leg = dict(raw_leg)
                    endpoint_id = self.mptcp_underlay_leg_endpoint_id(leg, endpoint_by_address)
                    if endpoint_id <= 0 or endpoint_id not in selected_ids:
                        continue
                    selected_endpoint = selected_by_id.get(endpoint_id, {})
                    for key in ("address", "local_address", "client_address", "peer_address", "mptcp_remote_address", "peer_public_host"):
                        value = str(leg.get(key, "") or "").strip()
                        if value != "":
                            endpoint_by_address[value] = endpoint_id
                    role = str(selected_endpoint.get("leg_role", "helper") or "helper")
                    leg["endpoint_id"] = endpoint_id
                    leg["leg_role"] = role
                    leg["health_role"] = str(selected_endpoint.get("health_role", "healthy") or "healthy")
                    # Keep helper subflows enabled for throughput. Quarantined legs are already filtered out.
                    leg["backup_only"] = bool(leg.get("backup_only", False) or selected_endpoint.get("backup_only", False))
                    if self.endpoint_has_preconnect_probe({"leg": leg}):
                        preconnect_remote = str(leg.get("slave_address", "") or leg.get("peer_address", "") or "").strip()
                        if preconnect_remote != "":
                            # In slave-preconnect mode the client only talks to
                            # the slave-side relay.  The abandoned sidecar
                            # overlay IP must not be rendered into client WG or
                            # MPTCP runtime config because it is intentionally
                            # unreachable from the client.
                            leg["peer_address"] = preconnect_remote
                            leg["mptcp_remote_address"] = preconnect_remote
                            leg["sidecar_address"] = preconnect_remote
                    if role == "primary" and primary_leg is None:
                        primary_leg = dict(leg)
                    filtered_legs.append(leg)
                if filtered_legs:
                    first_leg = filtered_legs[0]
                    underlay_key = (
                        str(first_leg.get("local_address", "") or "").strip(),
                        str(first_leg.get("mptcp_remote_address", "") or "").strip(),
                    )
                    if underlay_key != ("", "") and underlay_key in used_underlay_keys:
                        continue
                    if underlay_key != ("", ""):
                        used_underlay_keys.add(underlay_key)
                    underlay["legs"] = filtered_legs
                    filtered_underlays.append(underlay)
        if filtered_underlays:
            filtered_underlays.sort(
                key=lambda underlay: min(
                    selected_rank.get(int(leg.get("endpoint_id", 0) or 0), 999999)
                    for leg in underlay.get("legs", [])
                    if isinstance(leg, dict)
                ) if isinstance(underlay.get("legs", []), list) and underlay.get("legs", []) else 999999
            )

        renamed_devices: dict[str, str] = {}
        if filtered_underlays:
            for index, underlay in enumerate(filtered_underlays):
                if not isinstance(underlay, dict):
                    continue
                old_name = str(underlay.get("interface_name", "") or "").strip()
                new_name = f"lw-mptcp-{int(forward.get('forward_id', 0) or 0)}-{index}"
                if old_name != "" and new_name != "" and old_name != new_name:
                    renamed_devices[old_name] = new_name
                if new_name != "":
                    underlay["interface_name"] = new_name
            mptcp["underlays"] = filtered_underlays
            first_underlay = filtered_underlays[0]
            if isinstance(first_underlay, dict):
                first_leg_list = first_underlay.get("legs", [])
                if primary_leg is None and isinstance(first_leg_list, list) and first_leg_list:
                    primary_leg = dict(first_leg_list[0])

        filtered_mptcp_endpoints = []
        used_mptcp_endpoint_keys: set[tuple[str, str]] = set()
        raw_mptcp_endpoints = mptcp.get("mptcp_endpoints", [])
        if isinstance(raw_mptcp_endpoints, list):
            for index, raw_endpoint in enumerate(raw_mptcp_endpoints):
                if not isinstance(raw_endpoint, dict):
                    continue
                endpoint = dict(raw_endpoint)
                endpoint_id = self.mptcp_underlay_leg_endpoint_id(endpoint, endpoint_by_address)
                if endpoint_id <= 0 and index < len(selected):
                    endpoint_id = int(selected[index].get("endpoint_id", 0) or 0)
                if endpoint_id <= 0 or endpoint_id not in selected_ids:
                    continue
                selected_endpoint = selected_by_id.get(endpoint_id, {})
                endpoint["endpoint_id"] = endpoint_id
                endpoint["leg_role"] = str(selected_endpoint.get("leg_role", "helper") or "helper")
                endpoint["health_role"] = str(selected_endpoint.get("health_role", "healthy") or "healthy")
                if selected_endpoint.get("backup_only", False):
                    endpoint["backup_only"] = True
                endpoint_key = (
                    str(endpoint.get("address", "") or "").strip(),
                    str(endpoint.get("dev", "") or "").strip(),
                )
                address_only = str(endpoint.get("address", "") or "").strip()
                if address_only != "" and any(str(item.get("address", "") or "").strip() == address_only for item in filtered_mptcp_endpoints):
                    continue
                if endpoint_key != ("", "") and endpoint_key in used_mptcp_endpoint_keys:
                    continue
                if endpoint_key != ("", ""):
                    used_mptcp_endpoint_keys.add(endpoint_key)
                filtered_mptcp_endpoints.append(endpoint)
        if filtered_mptcp_endpoints:
            filtered_mptcp_endpoints.sort(key=lambda endpoint: selected_rank.get(int(endpoint.get("endpoint_id", 0) or 0), 999999))
        if renamed_devices:
            for endpoint in filtered_mptcp_endpoints:
                if not isinstance(endpoint, dict):
                    continue
                dev = str(endpoint.get("dev", "") or "").strip()
                if dev in renamed_devices:
                    endpoint["dev"] = renamed_devices[dev]
        if filtered_mptcp_endpoints:
            mptcp["mptcp_endpoints"] = filtered_mptcp_endpoints

        if primary_leg is not None:
            try:
                preconnect_relay_port = int(primary_leg.get("preconnect_relay_port", 0) or 0)
            except (TypeError, ValueError):
                preconnect_relay_port = 0
            preconnect_relay_host = str(
                primary_leg.get("slave_address", "")
                or primary_leg.get("peer_address", "")
                or ""
            ).strip()
            if self.endpoint_has_preconnect_probe({"leg": primary_leg}) and preconnect_relay_host != "" and preconnect_relay_port > 0:
                mptcp["slave_relay_host"] = preconnect_relay_host
                mptcp["slave_relay_port"] = preconnect_relay_port
                mptcp["sidecar_host"] = preconnect_relay_host
                mptcp["remote_host"] = preconnect_relay_host
                mptcp["sidecar_port"] = preconnect_relay_port
                mptcp["remote_port"] = preconnect_relay_port
            else:
                remote_address = str(primary_leg.get("mptcp_remote_address", "") or "").strip()
                if remote_address != "":
                    mptcp["sidecar_host"] = remote_address
                    mptcp["remote_host"] = remote_address

        configured_subflows = max(1, int(mptcp.get("subflow_limit", len(selected)) or len(selected) or 1))
        runtime_leg_count = len(filtered_mptcp_endpoints) or len(filtered_underlays) or len(selected)
        mptcp["subflow_limit"] = max(1, min(configured_subflows, max(1, runtime_leg_count)))
        next_forward["mptcp"] = mptcp
        return next_forward

    def reconcile_cf_bond_runtime_health(self, forward: dict, now: float | None = None) -> dict:
        now = time.time() if now is None else now
        if not self.forward_uses_bond_runtime(forward):
            return self.load_bond_endpoint_health()
        if str(forward.get("backend_mode", "current_mode") or "current_mode") != "cf_mode":
            return self.load_bond_endpoint_health()

        forward_id = int(forward.get("forward_id", 0) or 0)
        if forward_id <= 0:
            return self.load_bond_endpoint_health()

        snapshot = self.load_forward_bond_runtime_snapshot(forward_id)
        legs = snapshot.get("legs", []) if isinstance(snapshot.get("legs", []), list) else []
        health = self.load_bond_endpoint_health()
        scoped = health.setdefault(str(forward_id), {})
        changed = False

        for leg in legs:
            if not isinstance(leg, dict):
                continue
            mapped = self.cf_bond_runtime_health_entry(leg, now)
            if mapped is None:
                continue
            endpoint_id, entry = mapped
            previous = scoped.get(endpoint_id)
            if isinstance(previous, dict) and entry.get("one_leg_rtt_ms") is None and previous.get("one_leg_rtt_ms") is not None:
                entry["one_leg_rtt_ms"] = previous.get("one_leg_rtt_ms")
            if previous != entry:
                scoped[endpoint_id] = entry
                changed = True

        if changed:
            self.save_bond_endpoint_health(health)
        return health

    @staticmethod
    def forward_has_mptcp_runtime_config(forward: dict) -> bool:
        mptcp = forward.get("mptcp", {}) if isinstance(forward, dict) else {}
        if not isinstance(mptcp, dict):
            return False
        token = str(mptcp.get("tcp_session_token", "") or "").strip()
        if token == "":
            return False
        try:
            port = int(mptcp.get("sidecar_port", 0) or mptcp.get("remote_port", 0) or 0)
        except (TypeError, ValueError):
            port = 0
        return port > 0

    @staticmethod
    def contract_bool(value: object) -> bool | None:
        if isinstance(value, bool):
            return value
        if value is None:
            return None
        text = str(value).strip().lower()
        if text in {"1", "true", "yes", "on"}:
            return True
        if text in {"", "0", "false", "no", "off"}:
            return False
        return None

    @classmethod
    def forward_contract_is_sidecar_aggregation(cls, forward: dict) -> bool:
        if not isinstance(forward, dict):
            return False
        active_family = str(forward.get("active_family", "") or "").strip().lower()
        target_contract = str(forward.get("target_contract", "") or "").strip().lower()
        sidecar_required = cls.contract_bool(forward.get("sidecar_required"))
        aggregation_enabled = cls.contract_bool(forward.get("aggregation_enabled"))
        if active_family == "sidecar_aggregation" or target_contract == "sidecar_aggregation":
            return True
        if target_contract == "dedicated_forward_node" and sidecar_required is False and aggregation_enabled is False:
            return False
        if active_family == "leigod_only" and sidecar_required is False and aggregation_enabled is False:
            return False
        if sidecar_required is True or aggregation_enabled is True:
            return True
        try:
            return int(forward.get("bond_sidecar_point_id", 0) or 0) > 0
        except (TypeError, ValueError):
            return False

    @classmethod
    def forward_contract_is_dedicated_leigod(cls, forward: dict) -> bool:
        if not isinstance(forward, dict) or cls.forward_contract_is_sidecar_aggregation(forward):
            return False
        backend_mode = str(forward.get("backend_mode", "") or "").strip().lower()
        active_family = str(forward.get("active_family", "") or "").strip().lower()
        target_contract = str(forward.get("target_contract", "") or "").strip().lower()
        sidecar_required = cls.contract_bool(forward.get("sidecar_required"))
        aggregation_enabled = cls.contract_bool(forward.get("aggregation_enabled"))
        no_sidecar = sidecar_required is False and aggregation_enabled is False
        if target_contract == "dedicated_forward_node" and no_sidecar:
            return True
        if backend_mode == "leigod_mode" and (active_family == "leigod_only" or no_sidecar):
            return True
        return False

    def canonicalize_forward_runtime_contract(self, forward: dict, runtime_mode: str | None = None) -> dict:
        normalized = dict(forward)
        if self.forward_contract_is_dedicated_leigod(normalized):
            normalized["mode"] = "single"
            normalized["client_mode"] = "single"
            normalized["runtime_preference"] = "single"
            normalized["active_family"] = "leigod_only"
            normalized["target_contract"] = "dedicated_forward_node"
            normalized["aggregation_enabled"] = False
            normalized["sidecar_required"] = False
            for key in (
                "bonding",
                "mptcp",
                "bond_remote_host",
                "bond_remote_port",
                "sidecar_uplink",
                "sidecar_uplink_selector",
                "publish_bond_runtime",
            ):
                normalized.pop(key, None)
            for key in list(normalized.keys()):
                if key.startswith("bond_sidecar_") or key.startswith("mptcp_sidecar_"):
                    normalized.pop(key, None)
            return normalized
        if runtime_mode is not None:
            normalized["mode"] = self.normalize_mode(runtime_mode)
        return normalized

    def choose_runtime_family(self, forward: dict, mptcp_supported: bool) -> str:
        requested_mode = self.normalize_mode(forward.get("mode", "single"))
        if self.forward_contract_is_dedicated_leigod(forward):
            return "single"
        if self.forward_contract_is_sidecar_aggregation(forward):
            return "bond"
        runtime_preference = str(forward.get("runtime_preference", "single") or "single").strip()
        if runtime_preference != "mptcp_preferred_bond_fallback":
            return requested_mode

        mptcp_runtime_ready = self.forward_has_mptcp_runtime_config(forward)
        candidate_pool = [item for item in forward.get("candidate_pool", []) if isinstance(item, dict)]
        primary = next((item for item in candidate_pool if bool(item.get("selected_primary", False))), candidate_pool[0] if candidate_pool else None)
        if isinstance(primary, dict) and str(primary.get("candidate_kind", "") or "") == "self_leg":
            primary_caps = [str(value).strip().lower() for value in primary.get("runtime_capabilities", [])]
            probe_transport = str(primary.get("probe_transport", "") or "").strip().lower()
            try:
                target_sidecar_bond_port = int(primary.get("target_sidecar_bond_port", 0) or 0)
            except (TypeError, ValueError):
                target_sidecar_bond_port = 0
            # CF self-leg is a websocket landing leg into the sidecar bond
            # backend.  Keeping the whole forward in MPTCP family makes the
            # Rust relay speak its application-bond framing through that
            # websocket leg and has reproduced long stalls/520s under live
            # reverse-iperf traffic.  Use the mature bond runtime for this
            # shape even when an MPTCP sidecar runtime is present; rented
            # helpers can still be appended as normal slave-mediated bond legs.
            if probe_transport == "cf_direct_wss" and target_sidecar_bond_port > 0:
                return "bond"
            if bool(primary.get("self_leg_mptcp_solo_allowed", False)) and mptcp_supported and "mptcp" in primary_caps and (requested_mode == "mptcp" or mptcp_runtime_ready):
                return "mptcp"
            if mptcp_supported and mptcp_runtime_ready and "mptcp" in primary_caps:
                return "mptcp"
            return "bond"
        if mptcp_supported and mptcp_runtime_ready:
            mptcp_capable = [
                item
                for item in candidate_pool
                if "mptcp" in [str(value).strip().lower() for value in item.get("runtime_capabilities", [])]
            ]
            if mptcp_capable:
                return "mptcp"
        if requested_mode in {"bond", "mptcp"}:
            return "bond"
        return requested_mode if requested_mode != "single" else "bond"

    def candidate_measured_rtt_ms(self, forward: dict, candidate: dict, now: float | None = None) -> float:
        candidate_kind = str(candidate.get("candidate_kind", "") or "").strip()
        if candidate_kind == "self_leg":
            probe_transport = str(candidate.get("probe_transport", "") or "").strip().lower()
            if probe_transport == "":
                for key in ("measured_rtt_ms", "one_leg_rtt_ms", "latency_ewma_ms", "last_latency_ms"):
                    try:
                        value = float(candidate.get(key, 0) or 0)
                    except (TypeError, ValueError):
                        value = 0.0
                    if value > 0:
                        return value
            # Self-leg reachability changes independently of the last
            # panel/runtime ranking pass. Reprobe the live self-leg transport
            # before trusting stale measured_rtt/last_latency fields.
            try:
                if probe_transport in {"private_wg", "cf_wg"}:
                    wg = candidate.get("self_leg_wg", {}) if isinstance(candidate.get("self_leg_wg", {}), dict) else {}
                    interface_name = str(wg.get("interface_name", "") or "").strip()
                    outer_transport = str(wg.get("outer_transport", "udp") or "udp").strip().lower()
                    forward_id = int(forward.get("forward_id", candidate.get("forward_id", 0)) or 0)
                    cf_wg_udp_tunnel_ready = True
                    if probe_transport == "cf_wg" and outer_transport == "wstunnel_udp" and forward_id > 0:
                        local_udp_port = 40000 + (forward_id % 2000)
                        cf_wg_udp_tunnel_ready = self.local_listener_present(local_udp_port, "udp")[0]
                    if interface_name and not self.private_wg_interface_present(interface_name):
                        candidate["probe_state"] = "private_wg_bootstrap"
                        candidate["ready"] = True
                        latency_ms = self.private_wg_self_leg_bootstrap_latency_ms(candidate)
                        ok = True
                        _error = None
                    elif probe_transport == "cf_wg" and outer_transport == "wstunnel_udp" and not cf_wg_udp_tunnel_ready:
                        candidate["probe_state"] = "cf_wg_udp_tunnel_bootstrap"
                        candidate["ready"] = True
                        latency_ms = self.private_wg_self_leg_bootstrap_latency_ms(candidate)
                        ok = True
                        _error = None
                    else:
                        ok, latency_ms, _error = self.private_wg_self_leg_connect_probe(forward, candidate, timeout=3.0)
                        if (
                            not ok
                            and probe_transport == "cf_wg"
                            and outer_transport == "wstunnel_udp"
                            and interface_name
                            and cf_wg_udp_tunnel_ready
                        ):
                            candidate["probe_state"] = "cf_wg_probe_transient"
                            candidate["ready"] = True
                            candidate["last_error"] = _error or "self_leg_wg_probe_failed"
                            latency_ms = self.private_wg_self_leg_bootstrap_latency_ms(candidate)
                            ok = True
                            _error = None
                else:
                    ok, latency_ms, _error = self.self_leg_direct_cf_connect_probe(forward, candidate, timeout=3.0)
            except Exception as exc:  # noqa: BLE001
                candidate["ready"] = False
                candidate["last_error"] = str(exc)
                return 999999.0
            if ok and latency_ms is not None:
                connect_ip = str(candidate.get("dial_connect_ip", "") or "").strip()
                if connect_ip == "":
                    connect_ip = self.resolve_self_leg_connect_ip(candidate, forward)
                if connect_ip != "":
                    candidate["dial_connect_ip"] = connect_ip
                reported_latency_ms = float(latency_ms)
                if probe_transport == "cf_wg":
                    candidate["cf_wg_probe_latency_ms"] = reported_latency_ms
                    candidate["measured_rtt_ms"] = self.private_wg_self_leg_bootstrap_latency_ms(candidate)
                    candidate["last_latency_ms"] = reported_latency_ms
                    candidate["probe_state"] = candidate.get("probe_state", "cf_wg_probe_ok")
                    candidate["ready"] = True
                    candidate.pop("last_error", None)
                    return float(candidate["measured_rtt_ms"])
                candidate["measured_rtt_ms"] = reported_latency_ms
                candidate["last_latency_ms"] = reported_latency_ms
                candidate["ready"] = True
                candidate.pop("last_error", None)
                return reported_latency_ms
            candidate["ready"] = False
            candidate["last_error"] = _error or "self_leg_probe_failed"
            return 999999.0

        for key in ("measured_rtt_ms", "one_leg_rtt_ms", "latency_ewma_ms", "last_latency_ms"):
            try:
                value = float(candidate.get(key, 0) or 0)
            except (TypeError, ValueError):
                value = 0.0
            if value > 0:
                return value


        forward_id = int(forward.get("forward_id", 0) or 0)
        endpoint_id = int(candidate.get("endpoint_id", 0) or 0)
        if forward_id > 0 and endpoint_id > 0:
            health = self.load_bond_endpoint_health().get(str(forward_id), {})
            entry = health.get(str(endpoint_id), {}) if isinstance(health, dict) else {}
            if isinstance(entry, dict):
                for key in ("one_leg_rtt_ms", "latency_ewma_ms", "last_latency_ms"):
                    try:
                        value = float(entry.get(key, 0) or 0)
                    except (TypeError, ValueError):
                        value = 0.0
                    if value > 0:
                        candidate.setdefault(key, value)
                        return value
        return 999999.0

    def select_forward_candidates(self, forward: dict, now: float | None = None) -> list[dict]:
        candidates = [dict(item) for item in forward.get("candidate_pool", []) if isinstance(item, dict)]
        ready = [item for item in candidates if bool(item.get("ready", True))]
        for item in ready:
            if str(item.get("candidate_kind", "") or "") == "self_leg" and str(item.get("probe_transport", "") or "").strip().lower() == "cf_wg":
                connect_ip = self.resolve_self_leg_connect_ip(item, forward)
                if connect_ip != "":
                    item["dial_connect_ip"] = connect_ip
        for item in ready:
            item["measured_rtt_ms"] = self.candidate_measured_rtt_ms(forward, item, now=now)
        ready = [item for item in ready if bool(item.get("ready", True))]
        ready.sort(
            key=lambda item: (
                float(item.get("measured_rtt_ms", 999999.0) or 999999.0),
                0 if str(item.get("candidate_kind", "") or "") == "self_leg" else 1,
                int(item.get("origin_group_order", 999999) or 999999),
                int(item.get("endpoint_id", 0) or 0),
            )
        )
        max_legs = int(forward.get("max_legs_used", forward.get("helper_group_cap", 0)) or 0)
        if max_legs > 0:
            ready = ready[:max_legs]
        for item in ready:
            item.pop("selected_primary", None)
            item.pop("leg_role", None)
            item.pop("backup_only", None)
        if ready:
            ready[0]["selected_primary"] = True
            if self.normalize_mode(forward.get("mode", "single")) == "mptcp":
                for index, item in enumerate(ready):
                    if index == 0:
                        item["leg_role"] = "primary"
                        item["backup_only"] = False
                    else:
                        item["leg_role"] = "standby" if str(ready[0].get("candidate_kind", "") or "") == "self_leg" else "helper"
                        item["backup_only"] = item["leg_role"] == "standby"
        return ready

    @staticmethod
    def filter_mptcp_capable_candidates(candidate_pool: list[dict]) -> list[dict]:
        return [
            dict(item)
            for item in candidate_pool
            if isinstance(item, dict)
            and "mptcp" in [str(value).strip().lower() for value in item.get("runtime_capabilities", [])]
        ]

    @staticmethod
    def is_donor_candidate(item: dict) -> bool:
        return str(item.get("candidate_kind", "") or "") == "donated_slave_leg"

    @staticmethod
    def donor_log_label(item: dict) -> str:
        return "donor=true" if ClientAgent.is_donor_candidate(item) else "donor=false"

    @classmethod
    def strip_runtime_observation_fields(cls, forward: dict) -> dict:
        next_forward = dict(forward)
        observation_keys = {
            "quality_score",
            "one_leg_rtt_ms",
            "latency_ewma_ms",
            "last_latency_ms",
            "dynamic_priority",
        }
        endpoints = []
        for endpoint in next_forward.get("endpoints", []):
            if not isinstance(endpoint, dict):
                continue
            cleaned = dict(endpoint)
            for key in observation_keys:
                cleaned.pop(key, None)
            endpoints.append(cleaned)
        if endpoints:
            next_forward["endpoints"] = endpoints

        candidate_pool = []
        for candidate in next_forward.get("candidate_pool", []):
            if not isinstance(candidate, dict):
                continue
            cleaned = dict(candidate)
            for key in observation_keys | {"measured_rtt_ms", "cf_wg_probe_latency_ms", "health_role", "probe_state", "last_error"}:
                cleaned.pop(key, None)
            candidate_pool.append(cleaned)
        if candidate_pool:
            next_forward["candidate_pool"] = candidate_pool

        mptcp = next_forward.get("mptcp")
        if isinstance(mptcp, dict):
            mptcp_copy = dict(mptcp)
            mptcp_endpoints = []
            for endpoint in mptcp_copy.get("mptcp_endpoints", []):
                if not isinstance(endpoint, dict):
                    continue
                cleaned = dict(endpoint)
                for key in observation_keys:
                    cleaned.pop(key, None)
                mptcp_endpoints.append(cleaned)
            if mptcp_endpoints:
                mptcp_copy["mptcp_endpoints"] = mptcp_endpoints
            underlays = []
            for underlay in mptcp_copy.get("underlays", []):
                if not isinstance(underlay, dict):
                    continue
                underlay_copy = dict(underlay)
                legs = []
                for leg in underlay_copy.get("legs", []):
                    if not isinstance(leg, dict):
                        continue
                    cleaned = dict(leg)
                    for key in observation_keys:
                        cleaned.pop(key, None)
                    legs.append(cleaned)
                if legs:
                    underlay_copy["legs"] = legs
                underlays.append(underlay_copy)
            if underlays:
                mptcp_copy["underlays"] = underlays
            next_forward["mptcp"] = mptcp_copy
        return next_forward

    def apply_latency_target_candidate_escalation(self, forward: dict) -> dict:
        next_forward = dict(forward)
        desired_latency_ms = float(forward.get("desired_latency_ms", 0) or 0)
        helper_cap = max(0, int(forward.get("helper_group_cap", 0) or 0))
        pool = [dict(item) for item in forward.get("candidate_pool", []) if isinstance(item, dict)]
        primary = next((item for item in pool if bool(item.get("selected_primary", False))), None)
        if desired_latency_ms <= 0 or helper_cap <= 0 or primary is None:
            next_forward["candidate_pool"] = pool
            return next_forward
        if str(primary.get("candidate_kind", "") or "") != "self_leg":
            next_forward["candidate_pool"] = pool
            return next_forward
        primary_rtt = float(primary.get("measured_rtt_ms", 999999.0) or 999999.0)
        if primary_rtt <= desired_latency_ms:
            next_forward["candidate_pool"] = pool
            return next_forward

        chosen = 0
        helpers = [item for item in pool if str(item.get("candidate_kind", "") or "") in HELPER_CANDIDATE_KINDS and bool(item.get("ready", True))]
        helpers.sort(
            key=lambda item: (
                float(item.get("measured_rtt_ms", 999999.0) or 999999.0),
                int(item.get("origin_group_order", 999999) or 999999),
                int(item.get("endpoint_id", 0) or 0),
            )
        )
        for helper in helpers:
            if chosen >= helper_cap:
                helper.pop("active_helper", None)
                continue
            helper["active_helper"] = True
            chosen += 1
        next_forward["candidate_pool"] = pool
        return next_forward


    @staticmethod
    def private_wg_interface_present(interface_name: str) -> bool:
        name = str(interface_name or "").strip()
        if name == "" or os.name == "nt":
            return False
        return pathlib.Path(f"/sys/class/net/{name}").exists()

    def private_wg_self_leg_bootstrap_latency_ms(self, candidate: dict) -> float:
        try:
            value = float(candidate.get("bootstrap_rtt_ms", 1.0) or 1.0)
        except (TypeError, ValueError):
            value = 1.0
        return max(0.1, value)

    def private_wg_self_leg_connect_probe(self, forward: dict, candidate: dict, timeout: float = 3.0) -> tuple[bool, float | None, str | None]:
        wg = candidate.get("self_leg_wg", {})
        if not isinstance(wg, dict) or not bool(wg.get("enabled", True)):
            return False, None, "self_leg_wg_missing"
        host = str(wg.get("sidecar_address", "") or "").strip()
        try:
            port = int(candidate.get("target_sidecar_mptcp_port", 0) or candidate.get("target_sidecar_port", 0) or 0)
        except (TypeError, ValueError):
            port = 0
        if port <= 0:
            mptcp = forward.get("mptcp", {}) if isinstance(forward.get("mptcp", {}), dict) else {}
            try:
                port = int(mptcp.get("sidecar_port", 0) or mptcp.get("remote_port", 0) or 0)
            except (TypeError, ValueError):
                port = 0
        if host == "" or port <= 0:
            return False, None, "self_leg_wg_target_missing"
        probe = getattr(self, "private_wg_self_leg_probe", None)
        probe_func = probe if callable(probe) else self.remote_tcp_probe
        attempts = max(1, int((self.config if isinstance(getattr(self, "config", None), dict) else {}).get("self_leg_private_wg_probe_attempts", 3) or 3))
        best_latency: float | None = None
        last_error: str | None = None
        for attempt in range(attempts):
            ok, latency_ms, error = probe_func(host, port, timeout=timeout)
            if ok:
                if latency_ms is None:
                    return True, None, None
                if best_latency is None or float(latency_ms) < best_latency:
                    best_latency = float(latency_ms)
                continue
            last_error = error or last_error or "self_leg_wg_probe_failed"
            if attempt + 1 < attempts:
                time.sleep(0.05)
        if best_latency is not None:
            return True, round(best_latency, 1), None
        return False, None, last_error or "self_leg_wg_probe_failed"

    def normalize_self_leg_runtime_shape(self, candidate: dict) -> dict:
        if not isinstance(candidate, dict):
            raise RuntimeError("self_leg_candidate_invalid")
        if str(candidate.get("candidate_kind", "") or "") != "self_leg":
            raise RuntimeError("self_leg_candidate_required")
        shape = {
            "candidate_kind": "self_leg",
            "probe_transport": str(candidate.get("probe_transport", "") or "").strip(),
            "dial_host": str(candidate.get("dial_host", "") or "").strip(),
            "dial_connect_ip": str(candidate.get("dial_connect_ip", "") or "").strip(),
            "dial_port": int(candidate.get("dial_port", 0) or 0),
            "cf_sni_host": str(candidate.get("cf_sni_host", "") or candidate.get("sni_host", "") or "").strip(),
            "path_prefix": str(candidate.get("path_prefix", "") or "").strip(),
            "ingress_auth_token": str(candidate.get("ingress_auth_token", "") or ""),
            "target_sidecar_host": str(candidate.get("target_sidecar_host", "") or "").strip(),
            "target_sidecar_port": int(candidate.get("target_sidecar_port", 0) or 0),
        }
        if shape["probe_transport"] not in {"cf_direct_wss", "cf_wg"}:
            raise RuntimeError("self_leg_probe_transport_must_be_cf_direct_wss")
        if shape["dial_host"] == "" or shape["dial_port"] <= 0 or shape["path_prefix"] == "":
            raise RuntimeError("self_leg_direct_cf_fields_missing")
        return shape

    @staticmethod
    def normalized_self_leg_cf_path_key(path_prefix: str) -> str:
        return "/".join(segment for segment in str(path_prefix or "").strip().strip("/").split("/") if segment)

    @staticmethod
    def self_leg_cf_dial_port(candidate: dict, forward: dict | None = None) -> int:
        forward = forward or {}
        wg = candidate.get("self_leg_wg", {}) if isinstance(candidate.get("self_leg_wg", {}), dict) else {}
        for value in (
            candidate.get("dial_port"),
            wg.get("cf_dial_port"),
            candidate.get("cf_dial_port"),
            forward.get("bond_sidecar_cf_listen_port"),
            forward.get("cf_listen_port"),
        ):
            try:
                port = int(value or 0)
            except (TypeError, ValueError):
                port = 0
            if port > 0:
                return port
        return 0

    @staticmethod
    def self_leg_cf_wss_path_prefix(candidate: dict, forward: dict | None = None) -> str:
        forward = forward or {}
        wg = candidate.get("self_leg_wg", {}) if isinstance(candidate.get("self_leg_wg", {}), dict) else {}
        probe_transport = str(candidate.get("probe_transport", "") or "").strip().lower()
        if probe_transport == "cf_wg":
            path_prefix = str(
                wg.get("cf_path_prefix", "")
                or candidate.get("cf_path_prefix", "")
                or candidate.get("path_prefix", "")
                or forward.get("path_prefix", "")
                or ""
            ).strip()
            if path_prefix != "" and not path_prefix.endswith(".cfwg"):
                path_prefix += ".cfwg"
            return path_prefix
        return str(
            candidate.get("cf_path_prefix", "")
            or candidate.get("path_prefix", "")
            or forward.get("path_prefix", "")
            or ""
        ).strip()

    @classmethod
    def self_leg_cf_best_ip_map_keys(cls, candidate: dict, forward: dict | None = None) -> list[str]:
        path_prefix = cls.self_leg_cf_wss_path_prefix(candidate, forward)
        normalized_path = cls.normalized_self_leg_cf_path_key(path_prefix)
        port = cls.self_leg_cf_dial_port(candidate, forward)
        keys: list[str] = []

        def add_key(value: str) -> None:
            value = str(value or "").strip()
            if value != "" and value not in keys:
                keys.append(value)

        if port > 0:
            # CF edge reachability is scoped by both the public CF port and the
            # websocket route path.  A best IP for 443/<path> can be invalid for
            # 2053/<path>.cfwg, so new cache entries are always port+path keyed.
            add_key(f"{port}|{normalized_path}" if normalized_path != "" else "")
            if path_prefix != "" and path_prefix != normalized_path:
                add_key(f"{port}|{path_prefix}")
            return keys

        # Backward compatibility for old cache files written before CF route
        # identity included the port.  Only use these when the candidate does
        # not publish a CF port, otherwise path-only entries are ambiguous.
        add_key(normalized_path)
        if path_prefix != "" and path_prefix != normalized_path:
            add_key(path_prefix)
        return keys

    def resolve_self_leg_connect_ip_with_source(self, candidate: dict, forward: dict | None = None) -> tuple[str, str]:
        explicit = str(candidate.get("dial_connect_ip", "") or "").strip()
        dial_host = str(candidate.get("dial_host", "") or "").strip()
        if dial_host == "":
            return "", "missing"
        if re.fullmatch(r"\d+\.\d+\.\d+\.\d+", dial_host):
            return dial_host, "dial_host"
        config = self.config if isinstance(getattr(self, "config", None), dict) else {}
        if "state_dir" in config:
            state_dir = pathlib.Path(str(config.get("state_dir", "/var/lib/leikwan-client-agent")) or "/var/lib/leikwan-client-agent")
            best_map_path = state_dir / "self_leg_cf_best_ips.json"
            try:
                best_map_raw = json.loads(best_map_path.read_text(encoding="utf-8"))
            except Exception:
                best_map_raw = {}
            if isinstance(best_map_raw, dict):
                forward_id = int((forward or {}).get("forward_id", 0) or 0)
                scoped = best_map_raw.get(str(forward_id), {}) if forward_id > 0 else {}
                if isinstance(scoped, dict):
                    for map_key in self.self_leg_cf_best_ip_map_keys(candidate, forward):
                        candidates = scoped.get(map_key, [])
                        if isinstance(candidates, list):
                            for value in candidates:
                                ip = str(value or "").strip()
                                if re.fullmatch(r"\d+\.\d+\.\d+\.\d+", ip):
                                    return ip, "best_map"
        if explicit != "":
            return explicit, "explicit"
        published_candidates = candidate.get("self_leg_cf_candidates", [])
        if isinstance(published_candidates, list):
            for value in published_candidates:
                ip = str(value or "").strip()
                if re.fullmatch(r"\d+\.\d+\.\d+\.\d+", ip):
                    return ip, "published"
        env_path = pathlib.Path(str(config.get("self_leg_best_ip_env", "/etc/wstunnel/best_ip.env")) or "/etc/wstunnel/best_ip.env")
        try:
            raw = env_path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            return "", "missing"
        for line in raw.splitlines():
            stripped = line.strip()
            if stripped.startswith("CURRENT_BEST_IP="):
                value = stripped.split("=", 1)[1].strip().strip('"').strip("'")
                if re.fullmatch(r"\d+\.\d+\.\d+\.\d+", value):
                    return value, "env"
        return "", "missing"

    def resolve_self_leg_connect_ip(self, candidate: dict, forward: dict | None = None) -> str:
        return self.resolve_self_leg_connect_ip_with_source(candidate, forward)[0]

    def save_self_leg_best_ip_candidates(self, forward: dict, candidate: dict, ordered_candidates: list[str]) -> None:
        forward_id = int((forward or {}).get("forward_id", 0) or 0)
        map_keys = self.self_leg_cf_best_ip_map_keys(candidate, forward)
        if forward_id <= 0 or map_keys == []:
            return
        ordered: list[str] = []
        for value in ordered_candidates:
            ip = str(value or "").strip()
            if not re.fullmatch(r"\d+\.\d+\.\d+\.\d+", ip):
                continue
            if ip not in ordered:
                ordered.append(ip)
        if ordered == []:
            return
        config = self.config if isinstance(getattr(self, "config", None), dict) else {}
        if "state_dir" not in config:
            return
        state_dir = pathlib.Path(str(config.get("state_dir", "/var/lib/leikwan-client-agent")) or "/var/lib/leikwan-client-agent")
        best_map_path = state_dir / "self_leg_cf_best_ips.json"
        try:
            best_map_raw = json.loads(best_map_path.read_text(encoding="utf-8"))
        except Exception:
            best_map_raw = {}
        if not isinstance(best_map_raw, dict):
            best_map_raw = {}
        scoped = best_map_raw.get(str(forward_id), {})
        if not isinstance(scoped, dict):
            scoped = {}
        scoped[map_keys[0]] = ordered[:8]
        best_map_raw[str(forward_id)] = scoped
        best_map_path.parent.mkdir(parents=True, exist_ok=True)
        best_map_path.write_text(json.dumps(best_map_raw, ensure_ascii=False, indent=2), encoding="utf-8")

    @staticmethod
    def expand_self_leg_cf_candidate_ips(candidate: dict, limit_default: int = 8) -> list[str]:
        seen: set[str] = set()
        ordered: list[str] = []
        raw_cidrs = candidate.get("self_leg_cf_cidrs", [])
        limit = max(1, min(32, int(candidate.get("self_leg_cf_max_candidates", limit_default) or limit_default)))
        if isinstance(raw_cidrs, list):
            candidate_iters = []
            for raw in raw_cidrs:
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
            active_iters = candidate_iters
            while active_iters and len(ordered) < limit:
                next_iters = []
                progressed = False
                for iterator in active_iters:
                    try:
                        ip = next(iterator)
                    except StopIteration:
                        continue
                    progressed = True
                    if ip not in seen:
                        seen.add(ip)
                        ordered.append(ip)
                        if len(ordered) >= limit:
                            break
                    next_iters.append(iterator)
                if not progressed:
                    break
                active_iters = next_iters
        return ordered[:limit]

    def self_leg_candidate_edge_latency_ms(
        self,
        connect_ip: str,
        port: int,
        sni_host: str,
        timeout: float = 3.0,
    ) -> float | None:
        curl_bin = shutil.which("curl")
        if curl_bin:
            connect_timeout = max(1, int(timeout))
            max_time = max(connect_timeout + 2, int(timeout) + 2)
            command = [
                curl_bin,
                "-k",
                "-sS",
                "-o",
                "/dev/null",
                "-w",
                "%{http_code} %{time_connect} %{time_appconnect}",
                "--connect-timeout",
                str(connect_timeout),
                "--max-time",
                str(max_time),
            ]
            server_name = str(sni_host or connect_ip).strip()
            if server_name != "":
                command.extend(["--resolve", f"{server_name}:{int(port)}:{connect_ip}"])
                command.extend(["-H", f"Host: {server_name}"])
            command.append(f"https://{server_name}:{int(port)}/_leikwan/healthz")
            try:
                result = subprocess.run(command, text=True, capture_output=True, timeout=max_time + 1, check=False)
                output = (result.stdout or "").strip().split()
                if len(output) >= 2:
                    return round(float(output[1]) * 1000, 1)
                if len(output) >= 3:
                    return round(float(output[2]) * 1000, 1)
            except Exception:
                return None
        started = time.time()
        try:
            with socket.create_connection((connect_ip, int(port)), timeout=timeout):
                return round((time.time() - started) * 1000, 1)
        except Exception:
            return None

    @staticmethod
    def self_leg_websocket_handshake_error(log_text: str) -> str | None:
        text = str(log_text or "")
        if text == "":
            return None
        patterns = [
            r"Invalid status code:\s*\d+",
            r"failed to do websocket handshake[^\n]*",
            r"websocket handshake[^\n]*(?:failed|error)[^\n]*",
            r"HTTP status client error[^\n]*",
        ]
        for pattern in patterns:
            match = re.search(pattern, text, flags=re.IGNORECASE)
            if match:
                return match.group(0).strip()
        return None

    def probe_self_leg_bond_path(
        self,
        connect_ip: str,
        port: int,
        sni_host: str,
        path_prefix: str,
        target_port: int,
        session_token: str,
        bonding: dict,
        timeout: float = 3.0,
    ) -> tuple[bool, float | None, str | None]:
        if str(session_token or "").strip() == "":
            return False, None, "self_leg_bond_session_token_missing"
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listen_sock:
            listen_sock.bind(("127.0.0.1", 0))
            wstunnel_port = int(listen_sock.getsockname()[1])
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listen_sock:
            listen_sock.bind(("127.0.0.1", 0))
            bond_probe_port = int(listen_sock.getsockname()[1])

        wstunnel_bin = str((self.config if isinstance(getattr(self, "config", None), dict) else {}).get("wstunnel_bin", "/usr/local/bin/wstunnel") or "/usr/local/bin/wstunnel").strip() or "/usr/local/bin/wstunnel"
        bond_bin = str(self.bond_binary_path())
        if not pathlib.Path(wstunnel_bin).exists():
            return False, None, "wstunnel_bin_missing"
        if not pathlib.Path(bond_bin).exists():
            return False, None, f"bond_binary_missing:{bond_bin}"
        normalized_path = "/".join(segment for segment in str(path_prefix).strip("/").split("/") if segment)
        if normalized_path == "":
            return False, None, "self_leg_path_prefix_missing"

        wstunnel_command = [
            wstunnel_bin,
            "client",
            "-L",
            f"tcp://127.0.0.1:{wstunnel_port}:127.0.0.1:{int(target_port)}",
            "--tls-sni-override",
            sni_host,
            "--http-headers",
            f"Host: {sni_host}",
            "--http-upgrade-path-prefix",
            normalized_path,
            f"wss://{connect_ip}:{int(port)}",
        ]
        leg_spec = f"tcp://127.0.0.1:{wstunnel_port}?priority=10&display_host=self_leg&role=primary"
        bond_command = [
            bond_bin,
            "client",
            "--protocol",
            "tcp",
            "--listen",
            f"127.0.0.1:{bond_probe_port}",
            "--legs",
            leg_spec,
            "--session-token",
            str(session_token),
            "--chunk-size",
            str(int(bonding.get("chunk_size", 131072) or 131072)),
            "--max-window-bytes",
            str(int(bonding.get("max_window_bytes", 16777216) or 16777216)),
            "--retransmit-ms",
            str(int(bonding.get("retransmit_ms", 300) or 300)),
            "--connect-timeout-ms",
            str(int(bonding.get("connect_timeout_ms", 3000) or 3000)),
            "--join-timeout-ms",
            str(int(bonding.get("join_timeout_ms", 400) or 400)),
            "--interactive-chunk-bytes",
            str(int(bonding.get("interactive_chunk_bytes", 16 * 1024) or 16 * 1024)),
            "--latency-guard-bytes",
            str(int(bonding.get("latency_guard_bytes", 256 * 1024) or 256 * 1024)),
            "--interactive-rtt-slack-ms",
            str(int(bonding.get("interactive_rtt_slack_ms", 30) or 30)),
            "--warmup-trigger-bytes",
            str(int(bonding.get("warmup_trigger_bytes", 65536) or 65536)),
            "--warmup-slice-bytes",
            str(int(bonding.get("warmup_slice_bytes", 65536) or 65536)),
            "--warmup-leg-delay-ms",
            str(int(bonding.get("warmup_leg_delay_ms", 20) or 20)),
            "--bulk-promote-bytes",
            str(int(bonding.get("bulk_promote_bytes", 1048576) or 1048576)),
            "--leg-cooldown-ms",
            str(int(bonding.get("leg_cooldown_ms", 1800000) or 1800000)),
        ]
        if str(bonding.get("policy_mode", "") or "").strip() == "latency_first":
            bond_command.insert(4, "--latency-first")

        started = time.time()
        wstunnel_log: pathlib.Path | None = None
        bond_log: pathlib.Path | None = None
        wstunnel_process: subprocess.Popen | None = None
        bond_process: subprocess.Popen | None = None
        try:
            with tempfile.NamedTemporaryFile(prefix="leikwan-selfleg-bond-wstunnel-", suffix=".log", mode="w+", encoding="utf-8", delete=False) as wstunnel_handle, tempfile.NamedTemporaryFile(prefix="leikwan-selfleg-bond-", suffix=".log", mode="w+", encoding="utf-8", delete=False) as bond_handle:
                wstunnel_log = pathlib.Path(wstunnel_handle.name)
                bond_log = pathlib.Path(bond_handle.name)
                wstunnel_process = subprocess.Popen(wstunnel_command, stdout=wstunnel_handle, stderr=wstunnel_handle, text=True)

                def read_log(path: pathlib.Path | None, handle) -> str:
                    with contextlib.suppress(Exception):
                        handle.flush()
                    if path is None:
                        return ""
                    try:
                        return path.read_text(encoding="utf-8", errors="ignore")
                    except OSError:
                        return ""

                def combined_error(default: str) -> str:
                    output = read_log(wstunnel_log, wstunnel_handle) + "\n" + read_log(bond_log, bond_handle)
                    handshake_error = self.self_leg_websocket_handshake_error(output)
                    if handshake_error is not None:
                        return handshake_error
                    lines = [line.strip() for line in output.splitlines() if line.strip() != ""]
                    return lines[-1] if lines else default

                deadline = time.time() + max(1.0, timeout)
                while time.time() < deadline:
                    if wstunnel_process.poll() is not None:
                        return False, None, combined_error(f"wstunnel_exit_{wstunnel_process.returncode}")
                    try:
                        with socket.create_connection(("127.0.0.1", wstunnel_port), timeout=0.15):
                            break
                    except Exception:
                        time.sleep(0.05)
                else:
                    return False, None, combined_error("wstunnel_local_listener_timeout")

                bond_process = subprocess.Popen(bond_command, stdout=bond_handle, stderr=bond_handle, text=True)
                deadline = time.time() + max(1.0, timeout)
                established_seen = False
                while time.time() < deadline:
                    if wstunnel_process.poll() is not None:
                        return False, None, combined_error(f"wstunnel_exit_{wstunnel_process.returncode}")
                    if bond_process.poll() is not None:
                        return False, None, combined_error(f"bond_probe_exit_{bond_process.returncode}")
                    output = read_log(bond_log, bond_handle)
                    if "bond client TCP transport session established" in output:
                        established_seen = True
                        break
                    time.sleep(0.05)
                if not established_seen:
                    return False, None, combined_error("self_leg_bond_probe_session_not_established")
                return True, round((time.time() - started) * 1000, 1), None
        finally:
            for process in (bond_process, wstunnel_process):
                if process is None:
                    continue
                with contextlib.suppress(Exception):
                    if process.poll() is None:
                        process.terminate()
                with contextlib.suppress(Exception):
                    process.wait(timeout=1.0)
                with contextlib.suppress(Exception):
                    if process.poll() is None:
                        process.kill()
            for path in (wstunnel_log, bond_log):
                if path is not None:
                    with contextlib.suppress(Exception):
                        path.unlink()

    def probe_self_leg_websocket_path(
        self,
        connect_ip: str,
        port: int,
        sni_host: str,
        path_prefix: str,
        target_port: int,
        timeout: float = 3.0,
    ) -> tuple[bool, float | None, str | None]:
        wstunnel_bin = str((self.config if isinstance(getattr(self, "config", None), dict) else {}).get("wstunnel_bin", "/usr/local/bin/wstunnel") or "/usr/local/bin/wstunnel").strip() or "/usr/local/bin/wstunnel"
        if not pathlib.Path(wstunnel_bin).exists():
            return False, None, "wstunnel_bin_missing"
        normalized_path = "/".join(segment for segment in str(path_prefix).strip("/").split("/") if segment)
        if normalized_path == "":
            return False, None, "self_leg_path_prefix_missing"
        local_port = 0
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listen_sock:
            listen_sock.bind(("127.0.0.1", 0))
            local_port = int(listen_sock.getsockname()[1])
        command = [
            wstunnel_bin,
            "client",
            "-L",
            f"tcp://127.0.0.1:{local_port}:127.0.0.1:{int(target_port)}",
            "--tls-sni-override",
            sni_host,
            "--http-headers",
            f"Host: {sni_host}",
            "--http-upgrade-path-prefix",
            normalized_path,
            f"wss://{connect_ip}:{int(port)}",
        ]
        started = time.time()
        log_path: pathlib.Path | None = None

        with tempfile.NamedTemporaryFile(prefix="leikwan-selfleg-wstunnel-", suffix=".log", mode="w+", encoding="utf-8", delete=False) as log_handle:
            log_path = pathlib.Path(log_handle.name)
            process = subprocess.Popen(
                command,
                stdout=log_handle,
                stderr=log_handle,
                text=True,
            )

            def read_log() -> str:
                with contextlib.suppress(Exception):
                    log_handle.flush()
                if log_path is None:
                    return ""
                try:
                    return log_path.read_text(encoding="utf-8", errors="ignore")
                except OSError:
                    return ""

            def process_error(default: str | None = None) -> str:
                output = read_log()
                handshake_error = self.self_leg_websocket_handshake_error(output)
                if handshake_error is not None:
                    return handshake_error
                lines = [line.strip() for line in output.splitlines() if line.strip() != ""]
                if lines:
                    return lines[-1]
                return default or f"wstunnel_exit_{process.returncode}"

            try:
                deadline = time.time() + max(1.0, timeout)
                while time.time() < deadline:
                    if process.poll() is not None:
                        return False, None, process_error()
                    try:
                        with socket.create_connection(("127.0.0.1", local_port), timeout=0.2) as probe_sock:
                            probe_sock.settimeout(0.2)
                            grace_deadline = time.time() + min(max(0.8, timeout / 2.0), 1.8)
                            saw_eof = False
                            while time.time() < grace_deadline:
                                handshake_error = self.self_leg_websocket_handshake_error(read_log())
                                if handshake_error is not None:
                                    return False, None, handshake_error
                                if process.poll() is not None:
                                    return False, None, process_error()
                                try:
                                    received = probe_sock.recv(1)
                                except (socket.timeout, TimeoutError, BlockingIOError):
                                    received = None
                                if received == b"":
                                    saw_eof = True
                                    break
                                time.sleep(0.1)
                            handshake_error = self.self_leg_websocket_handshake_error(read_log())
                            if handshake_error is not None:
                                return False, None, handshake_error
                            if saw_eof:
                                return False, None, "wstunnel_probe_connection_closed"
                            return True, round((time.time() - started) * 1000, 1), None
                    except Exception:
                        time.sleep(0.1)
                return False, None, "wstunnel_local_listener_timeout"
            finally:
                with contextlib.suppress(Exception):
                    process.terminate()
                with contextlib.suppress(Exception):
                    process.wait(timeout=1)
                if log_path is not None:
                    with contextlib.suppress(Exception):
                        log_path.unlink()

    def self_leg_probe_cache_key(self, forward: dict, candidate: dict, shape: dict, target_port: int) -> str:
        forward_id = int((forward or {}).get("forward_id", 0) or 0)
        published_candidates = [str(value or "").strip() for value in (candidate.get("self_leg_cf_candidates", []) if isinstance(candidate.get("self_leg_cf_candidates", []), list) else [])]
        cidrs = [str(value or "").strip() for value in (candidate.get("self_leg_cf_cidrs", []) if isinstance(candidate.get("self_leg_cf_cidrs", []), list) else [])]
        shape_candidate = dict(candidate)
        if str(shape.get("path_prefix", "") or "").strip() != "":
            shape_candidate["path_prefix"] = str(shape.get("path_prefix", "") or "").strip()
        if int(shape.get("dial_port", 0) or 0) > 0:
            shape_candidate["dial_port"] = int(shape.get("dial_port", 0) or 0)
        payload = {
            "forward_id": forward_id,
            "cf_route_key": self.self_leg_cf_best_ip_map_keys(shape_candidate, forward)[:1],
            "path_prefix": str(shape.get("path_prefix", "") or ""),
            "dial_host": str(shape.get("dial_host", "") or ""),
            "dial_port": int(shape.get("dial_port", 0) or 0),
            "cf_sni_host": str(shape.get("cf_sni_host", "") or ""),
            "target_port": int(target_port or 0),
            "published_candidates": published_candidates,
            "cidrs": cidrs,
            "max_candidates": int(candidate.get("self_leg_cf_max_candidates", 0) or 0),
        }
        return json.dumps(payload, ensure_ascii=False, sort_keys=True)

    def self_leg_direct_cf_connect_probe(
        self,
        forward: dict,
        candidate: dict,
        timeout: float = 3.0,
    ) -> tuple[bool, float | None, str | None]:
        try:
            shape = self.normalize_self_leg_runtime_shape(candidate)
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

        connect_ip, connect_ip_source = self.resolve_self_leg_connect_ip_with_source(candidate, forward)
        connect_ip = connect_ip or shape["dial_host"]
        if connect_ip != "":
            candidate["dial_connect_ip"] = connect_ip
        target_port = int(candidate.get("target_sidecar_port", 0) or 0)
        cache_key = self.self_leg_probe_cache_key(forward, candidate, shape, target_port)
        cache_store = getattr(self, "self_leg_probe_cache", None)
        if not isinstance(cache_store, dict):
            cache_store = {}
            self.self_leg_probe_cache = cache_store
        cache_entry = cache_store.get(cache_key)
        if isinstance(cache_entry, dict):
            cached_at = float(cache_entry.get("ts", 0.0) or 0.0)
            cached_result = cache_entry.get("result")
            if isinstance(cached_result, list):
                cached_result = tuple(cached_result)
            cached_ok = bool(isinstance(cached_result, tuple) and len(cached_result) == 3 and bool(cached_result[0]))
            cached_latency: float | None = None
            if cached_ok:
                try:
                    cached_latency = float(cached_result[1]) if cached_result[1] is not None else None
                except (TypeError, ValueError):
                    cached_latency = None
            # Reuse only fresh GOOD websocket-path observations.  Slow successful
            # probes are deliberately not reused: a long-lived daemon had been
            # sticking to a stale ~150ms self-leg observation while a fresh agent
            # immediately found ~30ms candidates.  Fast observations are safe to
            # reuse briefly to avoid re-render/restart churn between sync and
            # health-refresh cycles.
            cache_ttl = float((self.config if isinstance(getattr(self, "config", None), dict) else {}).get("self_leg_fast_probe_cache_ttl_seconds", SELF_LEG_FAST_PROBE_CACHE_TTL_SECONDS) or SELF_LEG_FAST_PROBE_CACHE_TTL_SECONDS)
            max_fast_latency = float((self.config if isinstance(getattr(self, "config", None), dict) else {}).get("self_leg_fast_probe_cache_max_latency_ms", SELF_LEG_FAST_PROBE_CACHE_MAX_LATENCY_MS) or SELF_LEG_FAST_PROBE_CACHE_MAX_LATENCY_MS)
            if (
                cached_ok
                and cached_latency is not None
                and cached_latency > 0
                and cached_latency <= max_fast_latency
                and time.time() - cached_at <= cache_ttl
            ):
                cached_ip = str(cache_entry.get("best_ip", "") or "").strip()
                if cached_ip != "":
                    candidate["dial_connect_ip"] = cached_ip
                return cached_result
        if target_port > 0:
            published_candidates = candidate.get("self_leg_cf_candidates", [])
            probe_candidates = []
            if isinstance(published_candidates, list):
                for value in published_candidates:
                    ip = str(value or "").strip()
                    if ip != "" and ip not in probe_candidates:
                        probe_candidates.append(ip)
            expanded_candidates = self.expand_self_leg_cf_candidate_ips(candidate)
            for ip in expanded_candidates:
                if ip != "" and ip not in probe_candidates:
                    probe_candidates.append(ip)
            prefer_cached_ip = connect_ip_source in {"best_map", "explicit", "env", "dial_host"}
            if connect_ip != "" and prefer_cached_ip:
                probe_candidates = [ip for ip in probe_candidates if ip != connect_ip]
                probe_candidates.insert(0, connect_ip)
            if not probe_candidates:
                probe_candidates.append(shape["dial_host"])

            websocket_successes: list[tuple[str, float]] = []
            bonding = forward.get("bonding", {}) if isinstance((forward or {}).get("bonding", {}), dict) else {}
            bond_session_token = str(bonding.get("session_token", "") or "").strip()
            use_bond_probe = bond_session_token != "" and int(candidate.get("target_sidecar_bond_port", 0) or target_port or 0) > 0
            for probe_ip in probe_candidates:
                if use_bond_probe:
                    websocket_probe = self.probe_self_leg_bond_path(
                        probe_ip,
                        int(shape["dial_port"]),
                        str(shape["cf_sni_host"] or shape["dial_host"]),
                        str(shape["path_prefix"]),
                        int(candidate.get("target_sidecar_bond_port", 0) or target_port),
                        bond_session_token,
                        bonding,
                        timeout=timeout,
                    )
                else:
                    websocket_probe = self.probe_self_leg_websocket_path(
                        probe_ip,
                        int(shape["dial_port"]),
                        str(shape["cf_sni_host"] or shape["dial_host"]),
                        str(shape["path_prefix"]),
                        target_port,
                        timeout=timeout,
                    )
                if websocket_probe[0] and websocket_probe[1] is not None:
                    websocket_successes.append((probe_ip, float(websocket_probe[1])))

            if websocket_successes:
                best_ip, best_latency = websocket_successes[0]
                prefer_cached_ip = connect_ip_source in {"best_map", "explicit", "env", "dial_host"}
                preferred_ip = connect_ip if connect_ip != "" and prefer_cached_ip else ""
                edge_successes: list[tuple[str, float]] = []
                if len(websocket_successes) > 1:
                    for probe_ip, websocket_latency in websocket_successes:
                        edge_latency = self.self_leg_candidate_edge_latency_ms(
                            probe_ip,
                            int(shape["dial_port"]),
                            str(shape["cf_sni_host"] or shape["dial_host"]),
                            timeout=timeout,
                        )
                        if edge_latency is None or edge_latency <= 0:
                            continue
                        edge_successes.append((probe_ip, float(edge_latency)))
                if edge_successes:
                    best_edge_latency = min(latency_ms for _probe_ip, latency_ms in edge_successes)
                    best_ip, best_latency = next((probe_ip, latency_ms) for probe_ip, latency_ms in edge_successes if latency_ms == best_edge_latency)
                    if preferred_ip != "":
                        preferred_match = next((item for item in edge_successes if item[0] == preferred_ip), None)
                        if preferred_match is not None and preferred_match[1] <= best_edge_latency + 0.5:
                            best_ip, best_latency = preferred_match
                else:
                    best_websocket_latency = min(latency_ms for _probe_ip, latency_ms in websocket_successes)
                    best_ip, best_latency = next((probe_ip, latency_ms) for probe_ip, latency_ms in websocket_successes if latency_ms == best_websocket_latency)
                    if preferred_ip != "":
                        preferred_match = next((item for item in websocket_successes if item[0] == preferred_ip), None)
                        if preferred_match is not None and preferred_match[1] <= best_websocket_latency + 0.5:
                            best_ip, best_latency = preferred_match
                candidate["dial_connect_ip"] = best_ip
                ordered_candidates = [best_ip] + [ip for ip in probe_candidates if ip != best_ip]
                try:
                    self.save_self_leg_best_ip_candidates(forward, candidate, ordered_candidates)
                except Exception:
                    logging.exception("failed to persist self-leg best-ip candidates for forward %s", int((forward or {}).get("forward_id", 0) or 0))
                cache_store[cache_key] = {
                    "ts": time.time(),
                    "best_ip": best_ip,
                    "ordered_candidates": ordered_candidates,
                    "result": [True, float(best_latency), None],
                }
                return True, float(best_latency), None

        started = time.time()
        curl_bin = shutil.which("curl")
        if curl_bin:
            connect_timeout = max(1, int(timeout))
            max_time = max(connect_timeout + 2, int(timeout) + 2)
            request_path = "/_leikwan/readyz/" + "/".join(
                urllib.parse.quote(segment, safe="")
                for segment in shape["path_prefix"].strip("/").split("/")
                if segment != ""
            )
            command = [
                curl_bin,
                "-k",
                "-sS",
                "-o",
                "/dev/null",
                "-w",
                "%{http_code} %{time_connect} %{time_appconnect}",
                "--connect-timeout",
                str(connect_timeout),
                "--max-time",
                str(max_time),
            ]
            server_name = shape["cf_sni_host"] or shape["dial_host"]
            connect_host = self.resolve_self_leg_connect_ip(candidate, forward) or shape["dial_host"]
            if server_name != "":
                command.extend(["--resolve", f"{server_name}:{shape['dial_port']}:{connect_host}"])
                command.extend(["-H", f"Host: {server_name}"])
                url_host = server_name
            else:
                url_host = connect_host
            command.append(f"https://{url_host}:{shape['dial_port']}{request_path}")
            result = subprocess.run(command, text=True, capture_output=True, timeout=max_time + 1, check=False)
            output = (result.stdout or "").strip()
            parts = output.split()
            status = parts[0][-3:] if parts else ""
            latency_ms: float | None = None
            if len(parts) >= 2:
                try:
                    latency_ms = round(float(parts[1]) * 1000, 1)
                except (TypeError, ValueError):
                    latency_ms = None
            if latency_ms is None and len(parts) >= 3:
                try:
                    latency_ms = round(float(parts[2]) * 1000, 1)
                except (TypeError, ValueError):
                    latency_ms = None
            if latency_ms is None:
                latency_ms = round((time.time() - started) * 1000, 1)
            if status and status[0] in {"2", "3", "4"}:
                cache_store[cache_key] = {
                    "ts": time.time(),
                    "best_ip": connect_host,
                    "ordered_candidates": [connect_host],
                    "result": [True, latency_ms, None],
                }
                return True, latency_ms, None
            return False, None, (result.stderr or result.stdout or f"http_status_{status or 'missing'}").strip()

        try:
            connect_host = self.resolve_self_leg_connect_ip(candidate, forward) or shape["dial_host"]
            with socket.create_connection((connect_host, shape["dial_port"]), timeout=timeout):
                latency_ms = round((time.time() - started) * 1000, 1)
                cache_store[cache_key] = {
                    "ts": time.time(),
                    "best_ip": connect_host,
                    "ordered_candidates": [connect_host],
                    "result": [True, latency_ms, None],
                }
                return True, latency_ms, None
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

    def cf_bond_one_leg_connect_probe(
        self,
        forward: dict,
        endpoint: dict,
        endpoint_index: int,
        timeout: float = 3.0,
    ) -> tuple[bool, float | None, str | None]:
        forward_id = int(forward.get("forward_id", 0) or 0)
        endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
        host = str(endpoint.get("host", "") or "").strip()
        token = str((forward.get("bonding", {}) or {}).get("session_token", "") or "").strip()
        if forward_id <= 0 or endpoint_id <= 0 or host == "" or token == "":
            return False, None, "cf_bond_probe_missing_forward_fields"

        back_port = self.bond_runtime_endpoint_back_port(forward_id, endpoint_index, "tcp")
        ws_priority = int(((forward.get("bonding", {}) or {}).get("ws_priority", 10) or 10))
        leg_spec = f"tcp://127.0.0.1:{back_port}?priority={ws_priority}&endpoint_id={endpoint_id}&display_host={host}"
        bond_bin = str(self.bond_binary_path())
        if not pathlib.Path(bond_bin).exists():
            return False, None, f"bond_binary_missing:{bond_bin}"

        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listen_sock:
            listen_sock.bind(("127.0.0.1", 0))
            probe_port = int(listen_sock.getsockname()[1])

        bonding = forward.get("bonding", {}) if isinstance(forward.get("bonding", {}), dict) else {}
        command = [
            bond_bin,
            "client",
            "--protocol",
            "tcp",
            "--listen",
            f"127.0.0.1:{probe_port}",
            "--legs",
            leg_spec,
            "--session-token",
            token,
            "--chunk-size",
            str(int(bonding.get("chunk_size", 65536) or 65536)),
            "--max-window-bytes",
            str(int(bonding.get("max_window_bytes", 8388608) or 8388608)),
            "--retransmit-ms",
            str(int(bonding.get("retransmit_ms", 700) or 700)),
            "--connect-timeout-ms",
            str(int(bonding.get("connect_timeout_ms", 3000) or 3000)),
            "--join-timeout-ms",
            str(int(bonding.get("join_timeout_ms", 400) or 400)),
            "--interactive-chunk-bytes",
            str(int(bonding.get("interactive_chunk_bytes", 16 * 1024) or 16 * 1024)),
            "--latency-guard-bytes",
            str(int(bonding.get("latency_guard_bytes", 256 * 1024) or 256 * 1024)),
            "--interactive-rtt-slack-ms",
            str(int(bonding.get("interactive_rtt_slack_ms", 30) or 30)),
            "--warmup-trigger-bytes",
            str(int(bonding.get("warmup_trigger_bytes", 65536) or 65536)),
            "--warmup-slice-bytes",
            str(int(bonding.get("warmup_slice_bytes", 65536) or 65536)),
            "--warmup-leg-delay-ms",
            str(int(bonding.get("warmup_leg_delay_ms", 20) or 20)),
            "--bulk-promote-bytes",
            str(int(bonding.get("bulk_promote_bytes", 262144) or 262144)),
            "--leg-cooldown-ms",
            str(int(bonding.get("leg_cooldown_ms", 1800000) or 1800000)),
        ]
        process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
        started = time.time()

        def process_error() -> str:
            if process.stderr is None:
                return f"bond_probe_exit_{process.returncode}"
            output = process.stderr.read() or ""
            lines = [line.strip() for line in output.splitlines() if line.strip() != ""]
            return lines[-1] if lines else f"bond_probe_exit_{process.returncode}"

        try:
            deadline = started + max(0.5, timeout)
            last_error = "cf_bond_leg_probe_timeout"
            while time.time() < deadline:
                if process.poll() is not None:
                    return False, None, process_error()
                try:
                    with socket.create_connection(("127.0.0.1", probe_port), timeout=0.3):
                        return True, round((time.time() - started) * 1000, 1), None
                except Exception as exc:  # noqa: BLE001
                    last_error = str(exc)
                    time.sleep(0.05)
            return False, None, last_error
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=1.0)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=1.0)

    def select_bond_runtime_endpoints(self, forward: dict, now: float | None = None) -> list[dict]:
        now = time.time() if now is None else now
        endpoints = [dict(endpoint) for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        bonding = forward.get("bonding", {})
        if not isinstance(bonding, dict):
            bonding = {}
        base_priority = int(bonding.get("ws_priority", 10) or 10)
        health = self.reconcile_cf_bond_runtime_health(forward, now).get(str(int(forward.get("forward_id", 0) or 0)), {})

        decorated = []
        for endpoint in endpoints:
            endpoint_id = str(int(endpoint.get("endpoint_id", 0) or 0))
            entry = dict(health.get(endpoint_id, {}))
            role = str(entry.get("role", "healthy") or "healthy")
            quarantine_until = float(entry.get("quarantine_until", 0.0) or 0.0)
            probation_until = float(entry.get("probation_until", 0.0) or 0.0)
            if role == "quarantined" and quarantine_until <= now:
                role = "probation"
            if role == "probation" and probation_until <= now and int(entry.get("consecutive_successes", 0) or 0) >= 3:
                role = "healthy"

            endpoint["health_role"] = role
            endpoint["quality_score"] = float(entry.get("quality_score", 1.0 if role == "healthy" else (0.5 if role == "probation" else 0.0)) or 0.0)
            endpoint["one_leg_rtt_ms"] = entry.get("one_leg_rtt_ms")
            endpoint["latency_ewma_ms"] = entry.get("latency_ewma_ms")
            endpoint["last_latency_ms"] = entry.get("last_latency_ms")
            if role == "healthy":
                endpoint["dynamic_priority"] = base_priority
            elif role == "probation":
                endpoint["dynamic_priority"] = base_priority + 15
            else:
                endpoint["dynamic_priority"] = base_priority + 30
            decorated.append(endpoint)

        latency_first = self.latency_first_bond_enabled(forward)
        is_cf_bond = str(forward.get("backend_mode", "current_mode") or "current_mode") == "cf_mode"
        if not latency_first:
            preferred = [item for item in decorated if item["health_role"] != "quarantined"]
            if preferred:
                preferred.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
                return preferred

            decorated.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
            return decorated[:1]

        healthy = [item for item in decorated if item["health_role"] == "healthy"]
        healthy.sort(key=self.latency_first_primary_sort_key)
        probation = [item for item in decorated if item["health_role"] == "probation"]
        probation.sort(key=self.latency_first_primary_sort_key)
        quarantined = [item for item in decorated if item["health_role"] == "quarantined"]
        quarantined.sort(key=self.latency_first_primary_sort_key)

        if healthy:
            selected = healthy[:3] if is_cf_bond else healthy
        elif probation:
            selected = probation[:1]
        else:
            selected = quarantined[:1]

        for index, endpoint in enumerate(selected):
            endpoint["leg_role"] = "primary" if index == 0 else "helper"
        return selected

    def run_diagnostics_if_requested(self, master: str, token: str, client_meta: dict, forwards: list[dict]) -> None:
        request_token = str(client_meta.get("diag_request_token", "") or "")
        if request_token == "":
            return

        applied = self.load_applied()
        if applied.get("_last_diag_request_token") == request_token:
            return

        results = []
        error_message = ""
        try:
            for forward in forwards:
                forward_id = int(forward["forward_id"])
                local_service_name = f"leikwan-client-{forward_id}"
                local_status = subprocess.run(f"systemctl is-active {local_service_name}.service", shell=True, text=True, capture_output=True)
                local_service_active = local_status.stdout.strip() == "active"
                e2e_reachable, e2e_latency_ms, e2e_error = (False, None, "local service inactive")
                mode = self.applied_runtime_mode(forward_id) or self.normalize_mode(forward.get("mode", "single"))
                if local_service_active:
                    if mode == "mptcp":
                        e2e_reachable, e2e_latency_ms, e2e_error = self.local_listener_present(int(forward.get("listen_port", 0)), "tcp")
                    else:
                        e2e_reachable, e2e_latency_ms, e2e_error = self.local_runtime_probe(
                            int(forward.get("listen_port", 0)),
                            str(forward.get("transport", "tcp+udp") or "tcp+udp"),
                        )
                endpoint_results = []
                if mode == "mptcp":
                    mptcp = forward.get("mptcp", {})
                    if not isinstance(mptcp, dict):
                        mptcp = {}
                    for candidate in self.mptcp_leg_candidates(forward, mptcp):
                        host = str(candidate.get("host", "") or "")
                        port = int(candidate.get("port", 0) or 0)
                        reachable, latency_ms, err = self.mptcp_candidate_health_probe(candidate)
                        endpoint_results.append({
                            "endpoint_id": int(candidate.get("endpoint_id", 0) or 0),
                            "host": host,
                            "port": port,
                            "reachable": reachable,
                            "latency_ms": latency_ms,
                            "error": err,
                            "segment": "client_sidecar_mptcp_leg",
                        })
                else:
                    bonding = forward.get("bonding", {})
                    if not isinstance(bonding, dict):
                        bonding = {}
                    plain_tcp_ingress = self.forward_uses_bond_runtime(forward) and str(bonding.get("ingress_mode", "wss_path_mux")) == "plain_tcp_mux"
                    for endpoint in forward.get("endpoints", []):
                        host = str(endpoint.get("host", ""))
                        port = int(endpoint.get("port", 0))
                        if plain_tcp_ingress:
                            reachable, latency_ms, err = self.remote_plain_tcp_ready_probe(
                                host,
                                port,
                                str(bonding.get("route_key", "") or forward.get("path_prefix", "")),
                                str(endpoint.get("ingress_auth_token", "") or bonding.get("ingress_auth_token", "")),
                            )
                        else:
                            reachable, latency_ms, err = self.remote_ready_probe(
                                host,
                                port,
                                str(forward.get("sni", "") or endpoint.get("cdn_host", "") or host),
                                str(forward.get("path_prefix", "")),
                            )
                        endpoint_results.append({
                            "endpoint_id": int(endpoint.get("endpoint_id", 0)),
                            "host": host,
                            "port": port,
                            "reachable": reachable,
                            "latency_ms": latency_ms,
                            "error": err,
                            "segment": "client_slave",
                        })
                results.append({
                    "forward_id": forward_id,
                    "line_client_name": str(forward.get("line_client_name", "")),
                    "line_client_groups": [g.get("name", "") for g in forward.get("line_client_groups", [])],
                    "endpoint_mode": str(forward.get("endpoint_mode", "")),
                    "forward_chain_name": str(forward.get("forward_chain_name", "")),
                    "local_service_active": local_service_active,
                    "local_listen_port": int(forward.get("listen_port", 0)),
                    "target": str(forward.get("target", "")),
                    "endpoint_results": endpoint_results,
                    "end_to_end_result": {
                        "reachable": e2e_reachable,
                        "latency_ms": e2e_latency_ms,
                        "error": e2e_error,
                        "segment": "client_slave_endpoint",
                    },
                })
        except Exception as exc:  # noqa: BLE001
            error_message = str(exc)

        http_json(
            "POST",
            f"{master}/api/client/diagnostics/result",
            token,
            {
                "request_token": request_token,
                "results": results,
                "error": error_message,
            },
        )
        applied["_last_diag_request_token"] = request_token
        self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")

    @staticmethod
    def tcp_ping(host: str, port: int, timeout: float = 2.5) -> tuple[bool, float | None, str | None]:
        started = time.time()
        try:
            with socket.create_connection((host, port), timeout=timeout):
                return True, round((time.time() - started) * 1000, 1), None
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

    @staticmethod
    def preconnect_health_http_probe(host: str, port: int, timeout: float = 2.5) -> tuple[bool, float | None, str | None]:
        started = time.time()
        try:
            with socket.create_connection((host, port), timeout=timeout) as sock:
                sock.settimeout(timeout)
                request = (
                    "GET /health HTTP/1.1\r\n"
                    f"Host: {host}\r\n"
                    "Connection: close\r\n"
                    "\r\n"
                ).encode("ascii", errors="ignore")
                sock.sendall(request)
                response = b""
                while b"\r\n\r\n" not in response and len(response) < 4096:
                    chunk = sock.recv(1024)
                    if not chunk:
                        break
                    response += chunk
            header, _, body = response.partition(b"\r\n\r\n")
            status_line = header.splitlines()[0].decode("ascii", errors="ignore") if header.splitlines() else ""
            latency_ms = round((time.time() - started) * 1000, 1)
            if not status_line.startswith("HTTP/"):
                return False, None, f"invalid health response: {status_line or 'empty'}"
            parts = status_line.split()
            status_code = int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else 0
            if 200 <= status_code < 300:
                return True, latency_ms, None
            detail = body.decode("utf-8", errors="ignore").strip()
            return False, None, f"preconnect health http {status_code}: {detail[:160]}"
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

    def mptcp_candidate_health_probe(self, candidate: dict) -> tuple[bool, float | None, str | None]:
        host = str(candidate.get("host", "") or "")
        port = int(candidate.get("port", 0) or 0)
        if str(candidate.get("health_probe", "") or "").strip() == "preconnect_http":
            return self.preconnect_health_http_probe(host, port)
        return self.remote_tcp_probe(host, port)

    @staticmethod
    def mptcp_remote_hosts(mptcp: dict) -> list[str]:
        hosts: list[str] = []

        def add_host(value: object) -> None:
            host = str(value or "").strip()
            if host != "" and host not in hosts:
                hosts.append(host)

        add_host(mptcp.get("sidecar_host"))
        add_host(mptcp.get("remote_host"))
        underlays = mptcp.get("underlays", [])
        if not isinstance(underlays, list) or not underlays:
            underlay = mptcp.get("underlay", {})
            underlays = [underlay] if isinstance(underlay, dict) else []
        for underlay in underlays:
            if not isinstance(underlay, dict):
                continue
            legs = underlay.get("legs", [])
            if isinstance(legs, list):
                for leg in legs:
                    if isinstance(leg, dict):
                        add_host(leg.get("mptcp_remote_address"))
        return hosts

    def mptcp_leg_candidates(self, forward: dict, mptcp: dict | None = None) -> list[dict]:
        if mptcp is None:
            raw_mptcp = forward.get("mptcp", {})
            mptcp = raw_mptcp if isinstance(raw_mptcp, dict) else {}
        remote_port = int(mptcp.get("sidecar_port", 0) or mptcp.get("remote_port", 0) or 0)
        top_level_slave_relay_host = str(mptcp.get("slave_relay_host", "") or "").strip()
        top_level_slave_relay_port = int(mptcp.get("slave_relay_port", 0) or 0)
        candidates: list[dict] = []
        seen: set[tuple[int, str, int]] = set()
        endpoints = [endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        endpoint_by_id = {self.endpoint_id_for_runtime(endpoint, index): dict(endpoint) for index, endpoint in enumerate(endpoints)}
        endpoint_by_host = {str(endpoint.get("host", "") or "").strip(): endpoint_id for endpoint_id, endpoint in endpoint_by_id.items()}

        underlays = mptcp.get("underlays", [])
        if not isinstance(underlays, list) or not underlays:
            underlay = mptcp.get("underlay", {})
            underlays = [underlay] if isinstance(underlay, dict) else []

        for underlay_index, underlay in enumerate(underlays):
            if not isinstance(underlay, dict):
                continue
            legs = underlay.get("legs", [])
            if not isinstance(legs, list):
                continue
            for leg_index, leg in enumerate(legs):
                if not isinstance(leg, dict):
                    continue
                try:
                    endpoint_id = int(leg.get("endpoint_id", 0) or 0)
                except (TypeError, ValueError):
                    endpoint_id = 0
                if endpoint_id <= 0:
                    endpoint_id = int(endpoint_by_host.get(str(leg.get("peer_public_host", "") or "").strip(), 0) or 0)
                if endpoint_id <= 0:
                    endpoint_id = len(candidates) + 1
                health_probe = "tcp"
                relay_port = 0
                host = ""
                port = remote_port
                try:
                    relay_port = int(leg.get("preconnect_relay_port", 0) or 0)
                except (TypeError, ValueError):
                    relay_port = 0
                if relay_port > 0 or bool(leg.get("slave_preconnect", False)):
                    host = str(leg.get("slave_address", "") or leg.get("peer_address", "") or "").strip()
                    if relay_port <= 0 and top_level_slave_relay_host != "" and top_level_slave_relay_port > 0 and len(candidates) == 0:
                        host = top_level_slave_relay_host
                        relay_port = top_level_slave_relay_port
                    port = relay_port + 1 if relay_port > 0 else 0
                    health_probe = "preconnect_http"
                elif top_level_slave_relay_host != "" and top_level_slave_relay_port > 0 and len(candidates) == 0:
                    host = top_level_slave_relay_host
                    relay_port = top_level_slave_relay_port
                    port = relay_port + 1
                    health_probe = "preconnect_http"
                else:
                    host = str(leg.get("mptcp_remote_address", "") or "").strip()
                    if host == "":
                        host = str(mptcp.get("sidecar_host") or mptcp.get("remote_host") or "").strip()
                if host == "" or port <= 0:
                    continue
                key = (endpoint_id, host, port)
                if key in seen:
                    continue
                seen.add(key)
                endpoint = endpoint_by_id.get(endpoint_id, {})
                candidates.append(
                    {
                        "endpoint_id": endpoint_id,
                        "host": host,
                        "port": port,
                        "relay_port": relay_port,
                        "health_probe": health_probe,
                        "underlay_index": underlay_index,
                        "leg_index": leg_index,
                        "endpoint": endpoint,
                        "leg": dict(leg),
                    }
                )

        if candidates == []:
            for index, host in enumerate(self.mptcp_remote_hosts(mptcp), start=1):
                if remote_port <= 0:
                    continue
                candidates.append(
                    {
                        "endpoint_id": index,
                        "host": host,
                        "port": remote_port,
                        "underlay_index": -1,
                        "leg_index": index - 1,
                        "endpoint": endpoint_by_id.get(index, {}),
                        "leg": {},
                    }
                )
        return candidates

    @classmethod
    def remote_ready_probe(
        cls,
        host: str,
        port: int,
        sni: str,
        path_prefix: str,
        timeout: float = 3.0,
    ) -> tuple[bool, float | None, str | None]:
        if host == "" or port <= 0:
            return False, None, "endpoint missing host or port"

        normalized = str(path_prefix or "").strip().strip("/")
        if normalized == "":
            return False, None, "path_prefix missing"

        request_path = "/_leikwan/readyz/" + "/".join(
            urllib.parse.quote(segment, safe="") for segment in normalized.split("/") if segment != ""
        )
        server_name = str(sni or host)
        started = time.time()
        curl_bin = shutil.which("curl")

        if curl_bin:
            connect_timeout = max(1, int(timeout))
            max_time = max(connect_timeout + 2, int(timeout) + 2)
            command = [
                curl_bin,
                "-k",
                "-sS",
                "-o",
                "/dev/null",
                "-w",
                "%{http_code}",
                "--connect-timeout",
                str(connect_timeout),
                "--max-time",
                str(max_time),
                "-H",
                f"Host: {server_name}",
                f"https://{host}:{port}{request_path}",
            ]
            result = subprocess.run(
                command,
                text=True,
                capture_output=True,
                check=False,
            )
            status_code = str(result.stdout or "").strip()
            if result.returncode == 0 and status_code == "200":
                return True, round((time.time() - started) * 1000, 1), None
            if result.returncode != 0:
                detail = str(result.stderr or "").strip() or str(result.stdout or "").strip() or f"curl exited {result.returncode}"
                return False, None, detail
            if status_code != "":
                return False, None, f"HTTP {status_code}"

        try:
            with socket.create_connection((host, port), timeout=timeout) as raw_sock:
                context = ssl.create_default_context()
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                with context.wrap_socket(raw_sock, server_hostname=server_name) as tls_sock:
                    tls_sock.settimeout(timeout)
                    request = (
                        f"GET {request_path} HTTP/1.1\r\n"
                        f"Host: {server_name}\r\n"
                        "Connection: close\r\n"
                        f"User-Agent: Leikwan-Client-Agent/{AGENT_VERSION}\r\n\r\n"
                    )
                    tls_sock.sendall(request.encode("utf-8"))

                    response = b""
                    while b"\r\n\r\n" not in response and len(response) < 8192:
                        chunk = tls_sock.recv(4096)
                        if not chunk:
                            break
                        response += chunk

            first_line = response.split(b"\r\n", 1)[0].decode("utf-8", errors="ignore").strip()
            if first_line.startswith("HTTP/") and " 200 " in first_line:
                return True, round((time.time() - started) * 1000, 1), None
            if first_line == "":
                return False, None, "empty_ready_probe_response"
            return False, None, first_line
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

    @classmethod
    def remote_plain_tcp_ready_probe(
        cls,
        host: str,
        port: int,
        route_key: str,
        ingress_auth_token: str,
        timeout: float = 3.0,
    ) -> tuple[bool, float | None, str | None]:
        if host == "" or port <= 0:
            return False, None, "endpoint missing host or port"
        normalized_route_key = str(route_key or "").strip().strip("/")
        if normalized_route_key == "":
            return False, None, "route_key missing"
        auth_token = str(ingress_auth_token or "").strip()
        if auth_token == "":
            return False, None, "ingress_auth_token missing"

        started = time.time()
        try:
            with socket.create_connection((host, port), timeout=timeout) as sock:
                sock.settimeout(timeout)
                payload = f"LEIKWAN-INGRESS 1 PING {normalized_route_key} {auth_token}\n"
                sock.sendall(payload.encode("utf-8"))
                response = sock.recv(64)
            first_line = response.decode("utf-8", errors="ignore").strip()
            if first_line == "OK":
                return True, round((time.time() - started) * 1000, 1), None
            if first_line == "":
                return False, None, "empty_plain_tcp_probe_response"
            return False, None, first_line
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

    @classmethod
    def local_forward_probe(cls, listen_port: int, timeout: float = 2.5) -> tuple[bool, float | None, str | None]:
        if listen_port <= 0:
            return False, None, "listen_port missing"

        attempts = [("127.0.0.1", listen_port)]
        if socket.has_ipv6:
            attempts.append(("::1", listen_port))

        last_error = "probe_failed"
        for host, port in attempts:
            started = time.time()
            try:
                family = socket.AF_INET6 if ":" in host else socket.AF_INET
                with socket.socket(family, socket.SOCK_STREAM) as sock:
                    sock.settimeout(timeout)
                    sock.connect((host, port))
                return True, round((time.time() - started) * 1000, 1), None
            except Exception as exc:  # noqa: BLE001
                last_error = str(exc)
                continue
        return False, None, last_error

    @classmethod
    def local_listener_present(cls, listen_port: int, transport: str = "tcp", timeout: float = 2.5) -> tuple[bool, float | None, str | None]:
        if listen_port <= 0:
            return False, None, "listen_port missing"

        normalized = str(transport or "tcp").strip().lower()
        started = time.time()
        if shutil.which("ss"):
            protocol_flag = "-ltn" if normalized == "tcp" else "-lun"
            result = subprocess.run(
                f"ss {protocol_flag} sport = :{listen_port}",
                shell=True,
                text=True,
                capture_output=True,
                check=False,
            )
            if result.returncode == 0 and f":{listen_port}" in result.stdout:
                return True, round((time.time() - started) * 1000, 1), None
            return False, None, "listener_not_found"

        if normalized == "udp":
            return False, None, "udp listener check requires ss"

        return cls.local_forward_probe(listen_port, timeout=timeout)

    def local_runtime_probe(self, listen_port: int, transport: object) -> tuple[bool, float | None, str | None]:
        protocols = self.transport_protocols(transport)
        max_latency_ms: float | None = None

        if "tcp" in protocols:
            ok, latency_ms, error = self.local_forward_probe(listen_port)
            if not ok:
                return False, latency_ms, error
            if latency_ms is not None:
                max_latency_ms = latency_ms

        if "udp" in protocols:
            ok, latency_ms, error = self.local_listener_present(listen_port, "udp")
            if not ok:
                message = error or "listener_not_found"
                if "tcp" in protocols:
                    message = f"udp listener probe failed: {message}"
                return False, latency_ms, message
            if latency_ms is not None:
                max_latency_ms = latency_ms if max_latency_ms is None else max(max_latency_ms, latency_ms)

        return True, max_latency_ms, None

    @staticmethod
    def rendered_mptcp_subflow_limit(forward_id: int) -> int:
        if forward_id <= 0:
            return 0
        config_path = pathlib.Path(f"/etc/leikwan/mptcp-forward-{forward_id}.json")
        if not config_path.exists():
            return 0
        try:
            decoded = json.loads(config_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return 0
        if not isinstance(decoded, dict):
            return 0
        try:
            value = int(decoded.get("subflow_limit", 0) or 0)
        except (TypeError, ValueError):
            return 0
        return max(0, value)

    def reconcile_global_mptcp_state(self, forwards: list[dict]) -> None:
        """Reconcile global MPTCP state.

        When the Rust relay is in use, it manages endpoints via its own
        watchdog thread. Only set global limits here; skip endpoint
        deletion to avoid fighting with the Rust relay.
        """
        if os.name == "nt" or shutil.which("ip") is None:
            return

        total_subflow_limit = 0
        for forward in forwards:
            if self.normalize_mode(forward.get("mode", "single")) != "mptcp":
                continue
            if str(forward.get("service_status", "active")) != "active":
                continue
            mptcp = forward.get("mptcp", {})
            if not isinstance(mptcp, dict):
                continue
            configured_limit = max(1, int(mptcp.get("subflow_limit", 0) or 0))
            runtime_limit = self.rendered_mptcp_subflow_limit(int(forward.get("forward_id", 0) or 0))
            if runtime_limit > 0:
                configured_limit = min(configured_limit, runtime_limit)
            total_subflow_limit = max(total_subflow_limit, configured_limit)

        if total_subflow_limit > 0:
            subprocess.run(
                f"ip mptcp limits set subflow {total_subflow_limit} add_addr_accepted {total_subflow_limit}",
                shell=True,
                check=False,
            )

    @classmethod
    def remote_tcp_probe(cls, host: str, port: int, timeout: float = 3.0) -> tuple[bool, float | None, str | None]:
        host = str(host or "").strip()
        if host == "" or port <= 0:
            return False, None, "remote host/port missing"
        started = time.time()
        try:
            with socket.create_connection((host, port), timeout=timeout):
                pass
            return True, round((time.time() - started) * 1000, 1), None
        except Exception as exc:  # noqa: BLE001
            return False, None, str(exc)

    @classmethod
    def remote_tcp_probe_any(
        cls,
        hosts: list[str],
        port: int,
        timeout: float = 3.0,
    ) -> tuple[bool, float | None, str | None, str | None]:
        unique_hosts = [str(host).strip() for host in hosts if str(host).strip() != ""]
        if unique_hosts == [] or port <= 0:
            return False, None, "remote host/port missing", None
        errors: list[str] = []
        for host in unique_hosts:
            reachable, latency_ms, err = cls.remote_tcp_probe(host, port, timeout=timeout)
            if reachable:
                return True, latency_ms, None, host
            errors.append(f"{host}: {err}")
        return False, None, "; ".join(errors), None

    def check(self, live: bool = False) -> dict:
        bond_bin = self.bond_binary_path()
        bond_available = self.binary_available(bond_bin)
        mptcp_client = self.mptcp_client_path()
        mptcp_client_present = mptcp_client.is_file()
        mptcp_capability = self.mptcp_capability()
        mptcp_kernel_supported = bool(mptcp_capability.get("supported", False))
        wireguard_public_key = self.ensure_wireguard_identity()
        summary = {
            "ok": True,
            "agent_version": AGENT_VERSION,
            "config_path": str(self.config_path),
            "state_dir": str(getattr(self, "state_dir", "/var/lib/leikwan-client-agent")),
            "master_url": str(self.config.get("master_url", "")),
            "client_id": int(self.config.get("client_id", 0) or 0),
            "service_active": self.systemd_is_active("leikwan-client-agent"),
            "agent_update_state": self.load_update_state(),
            "update_state": self.load_update_state(),
            "pull_state": self.load_pull_state(),
            "applied": self.load_applied(),
            "forward_strategies": self.local_forward_strategies(),
            "runtime": {
                "bond_bin": str(bond_bin),
                "bond_bin_present": bond_available,
                "mptcp_client_bin": str(mptcp_client),
                "mptcp_client_bin_present": mptcp_client_present,
                "mptcp_kernel_supported": mptcp_kernel_supported,
                "mptcp_capability": mptcp_capability,
                "wireguard_public_key_present": wireguard_public_key != "",
                "wireguard_listen_port": self.wireguard_listen_port(),
                "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            },
        }

        if live:
            try:
                payload = http_json(
                    "GET",
                    f"{str(self.config['master_url']).rstrip('/')}/api/client/configs/pull",
                    str(self.config["agent_token"]),
                )
                forwards = [forward for forward in payload.get("forwards", []) if isinstance(forward, dict)]
                bond_forward_ids = [
                    int(forward.get("forward_id", 0) or 0)
                    for forward in forwards
                    if self.forward_uses_bond_runtime(forward)
                ]
                mptcp_forward_ids = [
                    int(forward.get("forward_id", 0) or 0)
                    for forward in forwards
                    if self.normalize_mode(forward.get("mode", "single")) == "mptcp"
                ]
                summary["live_pull"] = {
                    "ok": True,
                    "forwards_total": len(forwards),
                    "forward_ids": [int(forward.get("forward_id", 0) or 0) for forward in forwards],
                    "invalid_forward_ids": [
                        int(forward.get("forward_id", 0) or 0)
                        for forward in forwards
                        if (
                            self.normalize_mode(forward.get("mode", "single")) != "mptcp"
                            and self.usable_endpoints(forward) == []
                        )
                    ],
                    "bond_forward_ids": bond_forward_ids,
                    "mptcp_forward_ids": mptcp_forward_ids,
                }
                if bond_forward_ids and not bond_available:
                    summary["ok"] = False
                    summary["live_pull"]["bond_dependency_error"] = f"bond runtime forwards require {bond_bin}"
                if mptcp_forward_ids and not mptcp_client_present:
                    summary["ok"] = False
                    summary["live_pull"]["mptcp_dependency_error"] = f"mptcp mode forwards require {mptcp_client}"
                elif mptcp_forward_ids and not mptcp_kernel_supported:
                    fallback_forwards = [
                        int(forward.get("forward_id", 0) or 0)
                        for forward in forwards
                        if (
                            self.normalize_mode(forward.get("mode", "single")) == "mptcp"
                            and self.mptcp_tcp_fallback_allowed(forward.get("mptcp", {}) if isinstance(forward.get("mptcp", {}), dict) else {})
                        )
                    ]
                    if sorted(fallback_forwards) != sorted(mptcp_forward_ids):
                        summary["ok"] = False
                        summary["live_pull"]["mptcp_dependency_error"] = "mptcp mode forwards require a running kernel with CONFIG_MPTCP unless allow_tcp_fallback is enabled"
                    else:
                        summary["live_pull"]["mptcp_dependency_warning"] = "running kernel lacks MPTCP; configured forwards can run in TCP fallback mode"
            except Exception as exc:  # noqa: BLE001
                summary["ok"] = False
                summary["live_pull"] = {
                    "ok": False,
                    "error": str(exc),
                }

        return summary

    def local_forward_strategies(self) -> dict[str, str]:
        strategy_dir = pathlib.Path(
            getattr(self, "state_dir", "/var/lib/leikwan-client-agent")
        ) / "forward-strategies"
        if not strategy_dir.is_dir():
            return {}
        strategies: dict[str, str] = {}
        for path in sorted(strategy_dir.glob("*.strategy")):
            if not path.stem.isdigit() or int(path.stem) <= 0:
                continue
            try:
                value = path.read_text(encoding="utf-8").strip().lower()
            except OSError:
                value = "latency_best"
            strategies[path.stem] = value if value in {"speed_max", "latency_best", "random"} else "latency_best"
        return strategies


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="/etc/leikwan-client-agent/config.json")
    parser.add_argument("command", nargs="?", choices=["run", "check"], default="run")
    parser.add_argument("--live", action="store_true", help="When used with check, fetch the latest config pull from master.")
    args = parser.parse_args()
    agent = ClientAgent(pathlib.Path(args.config))
    if args.command == "check":
        summary = agent.check(live=args.live)
        print(json.dumps(summary, ensure_ascii=False, indent=2))
        return 0 if summary.get("ok", False) else 1
    agent.run()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
