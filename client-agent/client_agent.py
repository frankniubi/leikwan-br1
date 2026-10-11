#!/usr/bin/env python3
from __future__ import annotations

import argparse
import base64
import concurrent.futures
import email.message
import email.utils
import contextlib
import datetime
import errno
import hashlib
import http.client
import importlib.util
import io
import json
import ipaddress
import logging
import os
import pathlib
import secrets
import platform
import random
import re
import shutil
import socket
import ssl
import stat
import struct
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

AGENT_VERSION = "2026.10.02.1"

# Last content written to each managed runtime file. The restart decision needs
# it to distinguish a real contract change from a volatile-field rotation: a new
# policy_generation rewrites the file but must not cycle the forward service.
WRITTEN_RUNTIME_CONTENT: dict[str, str] = {}
UPDATE_RETRY_SECONDS = 300
# Per-token update ledger. A panel-issued manual token is never cleared by the
# panel, so a single "last completed token" field let a manual token and an
# auto-release token undo each other forever (each completion made the other
# look pending again).
UPDATE_LEDGER_MAX_ENTRIES = 16
# An auto-release token that is computed again after its update reported
# success did not converge (e.g. a desired hash that the installer can never
# satisfy). Retry it with exponential backoff instead of every retry window.
UPDATE_CONVERGE_BACKOFF_BASE_SECONDS = 300
UPDATE_CONVERGE_BACKOFF_MAX_SECONDS = 24 * 3600
# W1 offline validity: the longest policy/lease validity this agent accepts.
# The panel grants min(header, its own limit) and keeps renew_after at its
# normal interval, so renewal cadence is unchanged while an outage of up to
# this long no longer stops traffic. Without the header grants stay <= 300 s.
ULTRA_OFFLINE_VALIDITY_HEADER = "X-Leikwan-Ultra-Offline-Validity"
ULTRA_OFFLINE_VALIDITY_SECONDS = 1800
# Highest validity the agent is prepared to hold (runtime/desktop contract).
ULTRA_MAX_POLICY_VALIDITY_SECONDS = 3600
# Control-plane retry backoff for network / 5xx failures (full jitter).
CONTROL_PLANE_BACKOFF_BASE_SECONDS = 5.0
CONTROL_PLANE_BACKOFF_MAX_SECONDS = 60.0
CONTROL_PLANE_BACKOFF_MIN_SECONDS = 1.0
# The panel refuses a device that is not already online once the client's
# online device limit is full. A slot frees when another device goes silent
# (180 s), is kicked, or the limit is raised, so retry at a steady cadence.
DEVICE_LIMIT_ERROR_CODE = "client_online_limit_reached"
DEVICE_LIMIT_RETRY_SECONDS = 60.0
# Stable per-install device identity sent as X-Device-Id on every panel call.
DEVICE_ID_FILENAME = "device-id"
# Ultra sealed lease v1 (docs/specs/2026-10-02-ultra-sealed-lease-v1.md): one
# P-256 key per device, persisted 0600 beside device-id, public half sent on
# every lease request so the panel can seal endpoint/credential to it.
LEASE_KEY_FILENAME = "ultra-lease-key"
ULTRA_LEASE_KEY_HEADER = "X-Leikwan-Lease-Key"
LEASE_UNSEAL_FAILED = "lease_unseal_failed"
# Panel refusals of the lease key. None of them may regenerate the key: a
# conflict means another key is live for this device id, and replacing ours
# would fight the legitimate holder.
ULTRA_LEASE_KEY_REFUSALS = ("lease_key_invalid", "lease_sealing_required", "lease_device_key_conflict")
# Runtime status written by the Ultra runtime next to its control socket.
# The runtime's StatusWriter writes the policy state only when it changes (at
# most every 5 s), so the file's age says nothing by itself; a status older
# than the agent's last lease delivery is what no longer counts.
ULTRA_RUNTIME_DIR = pathlib.Path("/run/ultra")
# A cached lease is re-delivered to an idle runtime only while it still has
# this much validity left, and at most once per interval per forward.
ULTRA_REDELIVERY_MIN_REMAINING_SECONDS = 15.0
ULTRA_REDELIVERY_INTERVAL_SECONDS = 30.0
ULTRA_POLICY_STATES = {"active", "awaiting", "lapsed", "revoked"}
# Policy states in which a runtime has no usable policy but is alive and will
# recover by itself once the control plane answers again. Restarting it only
# churns while the panel is unreachable.
ULTRA_IDLE_POLICY_STATES = {"awaiting", "lapsed"}
ULTRA_LEGACY_POLICY_ERRORS = {"awaiting_policy": "awaiting", "policy_lapsed": "lapsed"}


def agent_artifact_path() -> pathlib.Path:
    """Return the bytes that identify this running agent distribution."""
    if getattr(sys, "frozen", False):
        return pathlib.Path(sys.executable).resolve()
    return pathlib.Path(__file__).resolve()


def helper_artifact_path(name: str) -> pathlib.Path:
    """Return the installed on-disk location of a helper shipped beside the agent.

    A PyInstaller one-file agent executes from an extracted ``/tmp/_MEIxxxx``
    directory, so ``__file__``-adjacent paths name files that were never
    installed. The installer places every helper next to the frozen executable
    (``/usr/local/bin``); hash gates and heartbeat provenance must read those
    installed bytes. The Python distribution keeps its source-adjacent layout.
    """
    if getattr(sys, "frozen", False):
        return agent_artifact_path().parent / name
    return pathlib.Path(__file__).with_name(name)


def ultra_offline_validity_headers() -> dict[str, str]:
    """Headers that ask the panel for a grant that survives a short outage."""
    return {ULTRA_OFFLINE_VALIDITY_HEADER: str(ULTRA_OFFLINE_VALIDITY_SECONDS)}


def ultra_policy_state_from_status(status: object) -> str:
    """Normalize a runtime status document to active|awaiting|lapsed|revoked.

    New runtimes write ``policy_state``; older ones only report the condition
    through ``error_code`` (``awaiting_policy``/``policy_lapsed``).
    Returns "" when the status does not say.
    """
    if not isinstance(status, dict):
        return ""
    state = str(status.get("policy_state", "") or "").strip().lower()
    if state in ULTRA_POLICY_STATES:
        return state
    for key in ("error_code", "last_error"):
        legacy = str(status.get(key, "") or "").strip().lower()
        if legacy in ULTRA_LEGACY_POLICY_ERRORS:
            return ULTRA_LEGACY_POLICY_ERRORS[legacy]
    return ""


class ControlPlaneBackoff:
    """Exponential backoff with full jitter for control-plane retries.

    Delay n is uniform in [0, min(cap, base * 2**(n-1))] (floored at 1 s), so a
    fleet that lost the panel does not retry in lock step when it comes back.
    """

    def __init__(
        self,
        base: float = CONTROL_PLANE_BACKOFF_BASE_SECONDS,
        cap: float = CONTROL_PLANE_BACKOFF_MAX_SECONDS,
        rng: random.Random | None = None,
    ) -> None:
        self.base = float(base)
        self.cap = float(cap)
        self.rng = rng or random.SystemRandom()
        self.failures = 0

    def ceiling(self) -> float:
        exponent = min(max(self.failures - 1, 0), 32)
        return min(self.cap, self.base * (2 ** exponent))

    def next_delay(self) -> float:
        self.failures += 1
        return max(CONTROL_PLANE_BACKOFF_MIN_SECONDS, self.rng.uniform(0.0, self.ceiling()))

    def reset(self) -> None:
        self.failures = 0


def agent_artifact_sha256() -> str:
    return file_sha256(agent_artifact_path())


class ControlPlaneResponseError(RuntimeError):
    """The server answered, but its response could not be decoded safely."""

    response_received = True
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
    # Request a bounded candidate batch at Ultra start; the runtime selects
    # one best path and keeps it sticky for the policy generation.
    "ultra_selection_mode": "auto",
}

DEBUG_LOG_CHUNK_BYTES = 256 * 1024
DEBUG_LOG_BYTE_LIMIT = 100 * 1024 * 1024
DEBUG_LOG_MAX_FILES = 32


def redact_debug_text(value: str) -> str:
    """Remove bearer material and credential-shaped values before upload."""
    text = str(value or "").replace("\x00", "")
    text = re.sub(r"(?i)(authorization\s*:\s*bearer\s+)[^\s,;]+", r"\1[REDACTED]", text)
    text = re.sub(
        r"(?i)(\b(?:password|passwd|secret|token|credential|session[_-]?ticket|private[_-]?key|api[_-]?key)\b\s*[:=]\s*)(?:\"[^\"]*\"|'[^']*'|[^\s,;]+)",
        r"\1[REDACTED]",
        text,
    )
    text = re.sub(
        r"(?i)([?&](?:token|password|secret|credential|key)=)[^&\s]+",
        r"\1[REDACTED]",
        text,
    )
    # Keep logs line-oriented and bounded; control characters can corrupt the
    # JSON transport or terminal log viewers.
    text = "\n".join("".join(ch for ch in line if ch in "\t\r" or ord(ch) >= 0x20) for line in text.splitlines())
    return text


def debug_log_paths(config: dict, state_dir: pathlib.Path) -> list[pathlib.Path]:
    configured = config.get("debug_log_paths") if isinstance(config, dict) else None
    roots: list[pathlib.Path] = []
    if isinstance(configured, list):
        for raw in configured[:DEBUG_LOG_MAX_FILES]:
            try:
                candidate = pathlib.Path(str(raw or "")).expanduser()
            except (TypeError, ValueError):
                continue
            if candidate.is_file():
                roots.append(candidate)
    if not roots:
        roots.extend([
            state_dir.parent / "logs",
            state_dir / "logs",
            pathlib.Path("/var/log/leikwan"),
            pathlib.Path("/var/log/leikwan-client-agent.log"),
            pathlib.Path(__file__).with_name("logs"),
        ])
    files: list[pathlib.Path] = []
    seen: set[str] = set()
    for root in roots:
        candidates = [root] if root.is_file() else list(root.glob("**/*")) if root.is_dir() else []
        for path in candidates:
            if len(files) >= DEBUG_LOG_MAX_FILES or not path.is_file():
                continue
            if path.suffix.lower() not in {".log", ".txt", ".out", ".err"}:
                continue
            key = str(path.resolve())
            if key in seen:
                continue
            seen.add(key)
            files.append(path)
    files.sort(key=lambda path: path.stat().st_mtime if path.exists() else 0, reverse=True)
    return files[:DEBUG_LOG_MAX_FILES]


def debug_journal_bytes() -> bytes:
    """Read a bounded service journal when systemd is the configured logger."""
    journalctl = shutil.which("journalctl")
    if not journalctl:
        return b""
    try:
        result = subprocess.run(
            [journalctl, "-u", "leikwan-client-agent.service", "-n", "2000", "--no-pager", "-o", "short-iso"],
            capture_output=True,
            check=False,
            timeout=8,
        )
    except (OSError, subprocess.SubprocessError):
        return b""
    if result.returncode not in (0, 1):
        return b""
    return bytes(result.stdout or b"")[:DEBUG_LOG_BYTE_LIMIT]


def upload_debug_collection(
    master: str,
    token: str,
    client_meta: dict,
    state_dir: pathlib.Path,
    config: dict | None = None,
    post=None,
    device_id: str = "",
) -> dict:
    """Upload a resumable, sanitized collection without blocking sync_once."""
    collection = client_meta.get("debug_collection") if isinstance(client_meta, dict) else None
    if not isinstance(collection, dict):
        return {"status": "not_requested", "chunks": 0, "bytes": 0}
    uuid = str(collection.get("uuid", "") or "").strip().lower()
    status = str(collection.get("status", "") or "").strip().lower()
    if not re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", uuid) or status != "active":
        return {"status": "inactive", "chunks": 0, "bytes": 0}
    expires = parse_rfc3339(collection.get("expires_at"))
    if expires is not None and expires <= time.time():
        return {"status": "expired", "chunks": 0, "bytes": 0}
    state_path = state_dir / "debug-upload-state.json"
    try:
        state = json.loads(state_path.read_text(encoding="utf-8")) if state_path.is_file() else {}
    except (OSError, json.JSONDecodeError):
        state = {}
    if not isinstance(state, dict) or state.get("collection_uuid") != uuid:
        state = {"collection_uuid": uuid, "next_sequence": 0, "files": {}}
    if state.get("completed") is True:
        return {"status": "complete", "chunks": 0, "bytes": 0}
    files_state = state.get("files") if isinstance(state.get("files"), dict) else {}
    state["files"] = files_state
    post = post or (lambda payload: http_json_with_headers(
        "POST",
        f"{master.rstrip('/')}/api/client/debug/log",
        token,
        payload,
        extra_headers=device_request_headers(
            device_id or load_or_create_device_id(state_dir) or str(client_meta.get("current_device_id", "") or "")
        ),
    )[0])
    total_bytes = 0
    chunks_sent = 0
    remaining = DEBUG_LOG_BYTE_LIMIT

    def persist() -> None:
        state_dir.mkdir(parents=True, exist_ok=True)
        temporary = state_path.with_suffix(".tmp")
        temporary.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
        temporary.replace(state_path)

    def source_iter():
        for path in debug_log_paths(config or {}, state_dir):
            if remaining <= 0:
                return
            try:
                with path.open("rb") as handle:
                    raw = handle.read(remaining)
            except OSError:
                continue
            if raw:
                yield str(path), raw
        if remaining > 0:
            journal = debug_journal_bytes()
            if journal:
                yield "journalctl-leikwan-client-agent.log", journal[:remaining]

    for source_path, raw in source_iter():
        if remaining <= 0:
            break
        sanitized = redact_debug_text(raw.decode("utf-8", errors="replace")).encode("utf-8")[:remaining]
        if not sanitized:
            continue
        digest = hashlib.sha256(sanitized).hexdigest()
        key = source_path
        previous = files_state.get(key)
        if isinstance(previous, dict) and previous.get("sha256") == digest:
            continue
        source_name = pathlib.Path(source_path).name[:128]
        for offset in range(0, len(sanitized), DEBUG_LOG_CHUNK_BYTES):
            chunk = sanitized[offset : offset + DEBUG_LOG_CHUNK_BYTES]
            sequence = int(state.get("next_sequence", 0) or 0)
            payload = {
                "collection_uuid": uuid,
                "sequence": sequence,
                "sha256": hashlib.sha256(chunk).hexdigest(),
                "chunk_base64": base64.b64encode(chunk).decode("ascii"),
                "source": source_name,
            }
            last_error = None
            for attempt in range(3):
                try:
                    response = post(payload)
                    if not isinstance(response, dict) or response.get("ok") is not True:
                        raise RuntimeError("debug upload rejected")
                    last_error = None
                    break
                except Exception as exc:  # noqa: BLE001
                    last_error = exc
                    if attempt < 2:
                        time.sleep(0.5 * (attempt + 1))
            if last_error is not None:
                logging.warning("debug log upload paused: %s", last_error)
                persist()
                return {"status": "retry_later", "chunks": chunks_sent, "bytes": total_bytes}
            state["next_sequence"] = sequence + 1
            chunks_sent += 1
            total_bytes += len(chunk)
            remaining -= len(chunk)
            persist()
        files_state[key] = {"sha256": digest, "bytes": len(sanitized)}
        persist()
    state["completed"] = True
    persist()
    return {"status": "complete", "chunks": chunks_sent, "bytes": total_bytes}


def parse_rfc3339(value: object) -> float | None:
    text = str(value or "").strip()
    if text == "":
        return None
    try:
        parsed = datetime.datetime.fromisoformat(text.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        return None
    return parsed.timestamp()


def format_rfc3339(value: float) -> str:
    return datetime.datetime.fromtimestamp(float(value), datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _stable_poll_jitter(base: float, stable_key: str) -> float:
    digest = hashlib.sha256(stable_key.encode("utf-8", errors="ignore")).digest()
    ratio = int.from_bytes(digest[:2], "big") / 65535.0
    return base * (0.8 + ratio * 0.4)


def ultra_config_poll_delay(
    forwards: list[dict],
    *,
    now: float,
    base_delay: int,
    stable_key: str,
) -> float:
    normal_delay = float(max(5, int(base_delay)))
    delay = normal_delay
    for forward in forwards:
        if not isinstance(forward, dict) or str(forward.get("backend_mode", "") or "").strip().lower() != "ultra":
            continue
        forward_id = int(forward.get("forward_id", 0) or 0)
        access = forward.get("ultra_access")
        if not isinstance(access, dict):
            urgent = _stable_poll_jitter(7.5, f"{stable_key}:{forward_id}:missing")
            delay = min(delay, max(5.0, min(10.0, urgent)))
            continue
        expires_at = parse_rfc3339(access.get("expires_at"))
        renew_after = parse_rfc3339(access.get("renew_after"))
        if expires_at is None:
            urgent = _stable_poll_jitter(7.5, f"{stable_key}:{forward_id}:invalid")
            delay = min(delay, max(5.0, min(10.0, urgent)))
            continue
        if renew_after is not None and now < renew_after:
            continue
        remaining = expires_at - now
        jitter_key = f"{stable_key}:{forward_id}:{int(expires_at)}"
        if remaining > 300:
            candidate = _stable_poll_jitter(20.0, jitter_key)
            candidate = max(16.0, min(24.0, candidate))
        elif remaining > 120:
            candidate = _stable_poll_jitter(10.0, jitter_key)
            candidate = max(8.0, min(12.0, candidate))
        else:
            candidate = _stable_poll_jitter(7.5, jitter_key)
            candidate = max(5.0, min(10.0, candidate))
        delay = min(delay, candidate)
    return max(5.0, delay)


def forward_requires_panel_endpoints(forward: dict, mode: str) -> bool:
    return str(forward.get("backend_mode", "") or "").strip().lower() != "ultra" and mode != "mptcp"


def unsupported_client_backend_mode(forward: dict) -> str:
    backend = str(forward.get("backend_mode", "") or "").strip().lower()
    return "cf_mode" if backend == "cf_mode" else ""


def forward_runtime_service_name(forward: dict) -> str:
    forward_id = int(forward.get("forward_id", 0) or 0)
    prefix = "ultra-forward" if str(forward.get("backend_mode", "") or "").strip().lower() == "ultra" else "leikwan-client"
    return f"{prefix}-{forward_id}"


def normalize_ultra_selection_mode(value: object) -> str:
    return "auto" if str(value or "").strip().lower() == "auto" else "single"


def ultra_endpoint_key(lease: object) -> tuple[str, int, str] | None:
    if not isinstance(lease, dict):
        return None
    endpoint = lease.get("endpoint")
    if not isinstance(endpoint, dict):
        return None
    try:
        port = int(endpoint.get("port", 0) or 0)
    except (TypeError, ValueError):
        port = 0
    ip = str(endpoint.get("ip", "") or "").strip().lower()
    if not ip or port <= 0:
        return None
    # selection_ref identifies a logical line even when multiple lines share
    # one relay socket. Fall back to the username for legacy fixtures.
    selection_ref = str(lease.get("selection_ref", "") or "").strip()
    if selection_ref == "":
        credential = lease.get("credential")
        if not isinstance(credential, dict):
            credential = {}
        selection_ref = str(credential.get("user", "") or "").strip().lower()
    return (ip, port, selection_ref)
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


# Panel egress: the no-GUI client talks to the panel (and its mirrors and the
# strict-DoH providers) directly, like the desktop client (UseProxy=false +
# socket bound to the physical NIC). Users commonly run Clash/Mihomo/sing-box/
# v2ray/OpenVPN/Tailscale/ZeroTier on the same box; their proxy env vars and
# TUN/TAP default routes must not capture control-plane traffic.
PANEL_EGRESS_AUTO = "auto"
PANEL_EGRESS_CONFIG_KEY = "panel_egress_interface"
PANEL_EGRESS_CACHE_SECONDS = 30.0
# Linux interface-name prefixes that are never a physical uplink: VPN/proxy TUN
# devices, overlay meshes, container/VM bridges and tunnel pseudo-devices.
# ppp* is deliberately NOT here: on Linux ppp0 is usually the PPPoE WAN itself.
# PPP devices only rank after other physical default routes, so a PPTP/L2TP VPN
# that leaves the LAN default route in place does not win.
PANEL_EGRESS_EXCLUDED_NAME_PREFIXES = (
    "tun", "tap", "utun", "wg", "meta", "mihomo", "clash", "sing", "xray", "v2ray",
    "tailscale", "zt", "nordlynx", "warp", "ipsec", "vti", "gre", "erspan", "ip6tnl",
    "ip6gre", "sit", "tunl", "docker", "br-", "veth", "virbr", "vmnet", "vboxnet",
    "lxcbr", "lxdbr", "incusbr", "podman", "cni", "flannel", "cali", "cilium",
    "weave", "vxlan", "kube", "dummy", "ifb", "nlmon",
)
PANEL_EGRESS_LOW_PRIORITY_NAME_PREFIXES = ("ppp",)
# ARPHRD types of point-to-point tunnel devices (sysfs "type"): NONE (TUN and
# WireGuard, whatever they are named), TUNNEL, TUNNEL6, SIT, IPGRE, IP6GRE.
PANEL_EGRESS_EXCLUDED_ARPHRD_TYPES = frozenset({65534, 768, 769, 776, 778, 823})
# Clash/Mihomo/sing-box fake-ip DNS answers from 198.18.0.0/15. Such an address
# is only reachable through the TUN, never through a bound physical socket.
PANEL_EGRESS_FAKE_IP_NETWORK = ipaddress.ip_network("198.18.0.0/15")
_PANEL_EGRESS_INTERFACE_NAME = re.compile(r"^[A-Za-z0-9_.:@+-]{1,15}$")


def panel_egress_interface_is_tunnel_like(name: str, info: dict | None = None) -> bool:
    normalized = str(name or "").strip().lower()
    if normalized == "" or normalized == "lo":
        return True
    if normalized.startswith(PANEL_EGRESS_EXCLUDED_NAME_PREFIXES):
        return True
    info = info if isinstance(info, dict) else {}
    if info.get("tun_flags"):
        return True
    try:
        arphrd_type = int(info.get("type", -1))
    except (TypeError, ValueError):
        arphrd_type = -1
    return arphrd_type in PANEL_EGRESS_EXCLUDED_ARPHRD_TYPES


def _read_sysfs_text(path: pathlib.Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="ignore").strip()
    except OSError:
        return ""


def _parse_proc_net_route(text: str) -> list[dict]:
    """Return the IPv4 default routes of the main table from /proc/net/route."""
    defaults: list[dict] = []
    for line in text.splitlines()[1:]:
        fields = line.split()
        if len(fields) < 8:
            continue
        name, destination, gateway, flags, metric, mask = fields[0], fields[1], fields[2], fields[3], fields[6], fields[7]
        try:
            flag_bits = int(flags, 16)
            metric_value = int(metric)
            gateway_ip = socket.inet_ntoa(struct.pack("<I", int(gateway, 16)))
        except (ValueError, struct.error, OSError):
            continue
        if flag_bits & 0x1 == 0:  # RTF_UP
            continue
        if destination == "00000000" and mask == "00000000":
            defaults.append({"interface": name, "metric": metric_value, "gateway": gateway_ip, "table": "main"})
    return defaults


def _parse_ip_route_default(text: str) -> list[dict]:
    """Parse `ip -4 -o route show default table all`, keeping non-main tables only."""
    routes: list[dict] = []
    for line in text.splitlines():
        tokens = line.split()
        if not tokens or tokens[0] != "default":
            continue
        values: dict[str, str] = {}
        for index in range(1, len(tokens) - 1):
            if tokens[index] in {"via", "dev", "metric", "table"}:
                values.setdefault(tokens[index], tokens[index + 1])
        table = values.get("table", "main")
        if table in {"main", "local", "254", "255"} or values.get("dev", "") == "":
            continue
        try:
            metric_value = int(values.get("metric", "0"))
        except ValueError:
            metric_value = 0
        routes.append({"interface": values["dev"], "metric": metric_value, "gateway": values.get("via", ""), "table": table})
    return routes


def read_linux_egress_snapshot(
    sys_class_net: str = "/sys/class/net",
    proc_route: str = "/proc/net/route",
    run=subprocess.run,
) -> dict:
    interfaces: dict[str, dict] = {}
    base = pathlib.Path(sys_class_net)
    try:
        names = sorted(os.listdir(base))
    except OSError:
        names = []
    for name in names:
        entry = base / name
        raw_type = _read_sysfs_text(entry / "type")
        interfaces[name] = {
            "operstate": _read_sysfs_text(entry / "operstate").lower() or "unknown",
            "tun_flags": (entry / "tun_flags").exists(),
            "type": int(raw_type) if raw_type.isdigit() else -1,
        }
    try:
        main_defaults = _parse_proc_net_route(pathlib.Path(proc_route).read_text(encoding="utf-8", errors="ignore"))
    except OSError:
        main_defaults = []
    other_defaults: list[dict] = []
    ip_bin = shutil.which("ip")
    if ip_bin:
        try:
            result = run([ip_bin, "-4", "-o", "route", "show", "default", "table", "all"], text=True, capture_output=True, timeout=3, check=False)
            if result.returncode == 0:
                other_defaults = _parse_ip_route_default(result.stdout or "")
        except (OSError, subprocess.SubprocessError):
            other_defaults = []
    return {
        "interfaces": interfaces,
        "main_default_routes": main_defaults,
        "other_default_routes": other_defaults,
    }


def normalize_panel_egress_override(value: object) -> tuple[str, str]:
    """Return (override, problem); override is "auto" or a syntactically valid interface name."""
    text = str(value if value is not None else "").strip()
    if text == "" or text.lower() == PANEL_EGRESS_AUTO:
        return PANEL_EGRESS_AUTO, ""
    if not _PANEL_EGRESS_INTERFACE_NAME.match(text) or text in {".", ".."}:
        return PANEL_EGRESS_AUTO, f"invalid {PANEL_EGRESS_CONFIG_KEY} {text!r}"
    return text, ""


def select_panel_egress_interface(snapshot: dict, override: str = PANEL_EGRESS_AUTO) -> tuple[str, str]:
    """Pick the physical interface for panel sockets.

    Returns (interface, detail); interface is "" when panel sockets stay unbound.
    An explicit override is honored when the interface exists (the operator's
    choice wins even if it looks like a tunnel); a missing one falls back to
    auto. Auto picks the eligible interface holding the lowest-metric main table
    default route, else the lowest-metric eligible default route of any other
    routing table (policy-routing setups).
    """
    interfaces = snapshot.get("interfaces") if isinstance(snapshot.get("interfaces"), dict) else {}
    notes: list[str] = []
    override_name, problem = normalize_panel_egress_override(override)
    if problem:
        notes.append(problem)
    elif override_name != PANEL_EGRESS_AUTO:
        if override_name in interfaces and override_name != "lo":
            return override_name, f"{PANEL_EGRESS_CONFIG_KEY} override"
        notes.append(f"{PANEL_EGRESS_CONFIG_KEY} {override_name!r} not found")

    def eligible(name: str) -> bool:
        info = interfaces.get(name)
        if not isinstance(info, dict):
            return False
        if str(info.get("operstate", "unknown")).lower() in {"down", "lowerlayerdown", "notpresent"}:
            return False
        return not panel_egress_interface_is_tunnel_like(name, info)

    def rank(route: dict) -> tuple[int, int, str]:
        name = str(route.get("interface", ""))
        low_priority = 1 if name.lower().startswith(PANEL_EGRESS_LOW_PRIORITY_NAME_PREFIXES) else 0
        try:
            metric = int(route.get("metric", 0))
        except (TypeError, ValueError):
            metric = 0
        return (low_priority, metric, name)

    for key, label in (("main_default_routes", "default route"), ("other_default_routes", "policy default route")):
        routes = [route for route in snapshot.get(key) or [] if isinstance(route, dict) and eligible(str(route.get("interface", "")))]
        if routes:
            best = min(routes, key=rank)
            detail = f"auto: {label} via {best.get('gateway') or '-'} metric {best.get('metric', 0)}"
            if str(best.get("table", "main")) != "main":
                detail += f" table {best.get('table')}"
            if notes:
                detail = "; ".join(notes) + "; " + detail
            return str(best["interface"]), detail
    notes.append("no physical interface holds a default route")
    return "", "; ".join(notes)


class PanelEgress:
    """Chosen panel egress interface plus SO_BINDTODEVICE with a safe fallback.

    Binding is best effort: an unsupported platform, a missing privilege or a
    vanished interface leaves panel sockets unbound (logged once) instead of
    breaking control-plane connectivity.
    """

    def __init__(self, snapshot_reader=None, cache_seconds: float = PANEL_EGRESS_CACHE_SECONDS, platform_supported=None):
        self.snapshot_reader = snapshot_reader or read_linux_egress_snapshot
        self.cache_seconds = cache_seconds
        self.platform_supported = platform_supported
        self.override = PANEL_EGRESS_AUTO
        self._override_problem = ""
        self._lock = threading.Lock()
        self._cached: tuple[float, str, str] | None = None
        self._bind_disabled_reason = ""
        self._warned: set[str] = set()

    def configure(self, config: dict | None) -> None:
        raw = config.get(PANEL_EGRESS_CONFIG_KEY, PANEL_EGRESS_AUTO) if isinstance(config, dict) else PANEL_EGRESS_AUTO
        override, problem = normalize_panel_egress_override(raw)
        with self._lock:
            if override != self.override or problem != self._override_problem:
                self._cached = None
            self.override = override
            self._override_problem = problem
        if problem:
            self._warn_once(problem, "%s; using auto panel egress selection", problem)

    def invalidate(self) -> None:
        with self._lock:
            self._cached = None

    def platform_supports_binding(self) -> bool:
        if self.platform_supported is not None:
            return bool(self.platform_supported)
        return sys.platform.startswith("linux")

    def _warn_once(self, key: str, message: str, *args) -> None:
        if key in self._warned:
            return
        self._warned.add(key)
        logging.warning(message, *args)

    def resolve(self) -> tuple[str, str]:
        """Return (interface, detail); interface "" means unbound and detail says why."""
        if not self.platform_supports_binding():
            return "", "platform lacks SO_BINDTODEVICE"
        with self._lock:
            if self._bind_disabled_reason:
                return "", self._bind_disabled_reason
            cached = self._cached
            override = self.override
            override_problem = self._override_problem
        now = time.monotonic()
        if cached is not None and cached[0] > now:
            return cached[1], cached[2]
        try:
            interface, detail = select_panel_egress_interface(self.snapshot_reader(), override)
        except Exception as exc:  # noqa: BLE001 - selection must never break the control plane.
            interface, detail = "", f"interface discovery failed: {exc}"
        if override_problem and override_problem not in detail:
            detail = f"{override_problem}; {detail}"
        with self._lock:
            self._cached = (now + self.cache_seconds, interface, detail)
        if cached is None or cached[1] != interface:
            if interface:
                logging.info("panel egress interface %s (%s)", interface, detail)
            else:
                logging.info("panel egress unbound: %s", detail)
        return interface, detail

    def interface_for_socket(self) -> str:
        return self.resolve()[0]

    def bind_socket(self, sock: socket.socket, interface: str) -> bool:
        option = getattr(socket, "SO_BINDTODEVICE", 25)
        try:
            sock.setsockopt(socket.SOL_SOCKET, option, interface.encode("ascii") + b"\0")
            return True
        except (OSError, UnicodeEncodeError) as exc:
            code = getattr(exc, "errno", None)
            if code in {errno.EPERM, errno.EACCES, errno.ENOPROTOOPT}:
                with self._lock:
                    self._bind_disabled_reason = f"SO_BINDTODEVICE not permitted ({exc})"
                self._warn_once("bind-disabled", "panel egress: SO_BINDTODEVICE %s failed (%s); panel sockets stay unbound", interface, exc)
            else:
                # ENODEV and friends: the interface vanished or was renamed.
                self.invalidate()
                self._warn_once(f"bind-{interface}-{code}", "panel egress: cannot bind to %s (%s); this connection stays unbound", interface, exc)
            return False

    def status(self) -> dict:
        interface, detail = self.resolve()
        return {
            "proxies_ignored": True,
            "mode": PANEL_EGRESS_AUTO if self.override == PANEL_EGRESS_AUTO else "override",
            "configured": self.override,
            "interface": interface,
            "bound": interface != "",
            "detail": detail,
            "summary": interface if interface else f"unbound: {detail}",
        }


PANEL_EGRESS = PanelEgress()


def configure_panel_egress(config: dict | None) -> None:
    PANEL_EGRESS.configure(config)


def _panel_addresses_for_bound_socket(host: str, port: int) -> list[tuple]:
    infos = socket.getaddrinfo(host, port, 0, socket.SOCK_STREAM)
    if host_is_ip_literal(str(host)) or not infos:
        return infos

    def fake(info: tuple) -> bool:
        try:
            return ipaddress.ip_address(info[4][0]) in PANEL_EGRESS_FAKE_IP_NETWORK
        except (ValueError, IndexError, TypeError):
            return False

    if not all(fake(info) for info in infos):
        return infos
    # The system resolver is a TUN tool's fake-ip DNS. Its answers are only
    # routable through the TUN, so ask the strict DoH providers (dialed by IP,
    # over the bound physical interface) for the real address instead.
    try:
        addresses = _STRICT_DOH_RESOLVER.resolve_a(host)
    except StrictDoHError as exc:
        logging.warning("panel egress: %s resolves to fake-ip %s and strict DoH failed: %s", host, infos[0][4][0], exc)
        return infos
    return [(socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, "", (address, port)) for address in addresses] or infos


def _panel_destination_is_loopback(host: object) -> bool:
    # A loopback panel (local testing, an SSH tunnel) is unreachable through a
    # socket bound to the physical NIC.
    try:
        return ipaddress.ip_address(str(host).split("%", 1)[0]).is_loopback
    except ValueError:
        return False


def panel_create_connection(address, timeout=socket._GLOBAL_DEFAULT_TIMEOUT, source_address=None):
    """socket.create_connection for panel traffic, bound to the physical egress interface."""
    interface = PANEL_EGRESS.interface_for_socket()
    if not interface:
        return socket.create_connection(address, timeout, source_address)
    host, port = address[0], address[1]
    last_error: OSError | None = None
    for family, socktype, proto, _canonname, sockaddr in _panel_addresses_for_bound_socket(host, port):
        sock = None
        try:
            sock = socket.socket(family, socktype, proto)
            if not _panel_destination_is_loopback(sockaddr[0]):
                PANEL_EGRESS.bind_socket(sock, interface)
            if timeout is not socket._GLOBAL_DEFAULT_TIMEOUT:
                sock.settimeout(timeout)
            if source_address:
                sock.bind(source_address)
            sock.connect(sockaddr)
            return sock
        except OSError as exc:
            last_error = exc
            if sock is not None:
                sock.close()
    if last_error is not None:
        raise last_error
    raise OSError(f"getaddrinfo returned no addresses for {host}")


class PanelHTTPConnection(http.client.HTTPConnection):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._create_connection = panel_create_connection


class PanelHTTPSConnection(http.client.HTTPSConnection):
    # TLS, SNI and certificate verification stay the stdlib's; only the TCP
    # dial is replaced.
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._create_connection = panel_create_connection


class PanelHTTPHandler(urllib.request.HTTPHandler):
    def http_open(self, req):
        return self.do_open(PanelHTTPConnection, req)


class PanelHTTPSHandler(urllib.request.HTTPSHandler):
    def https_open(self, req):
        return self.do_open(PanelHTTPSConnection, req, context=self._context)


def build_panel_opener() -> urllib.request.OpenerDirector:
    # An explicit empty ProxyHandler replaces the default one, which would read
    # http_proxy/https_proxy/all_proxy from the environment.
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), PanelHTTPHandler(), PanelHTTPSHandler())


PANEL_OPENER = build_panel_opener()


def panel_urlopen(req: urllib.request.Request, timeout: float = 30):
    return PANEL_OPENER.open(req, timeout=timeout)


class ResolvedHTTPConnection(http.client.HTTPConnection):
    def __init__(self, host: str, port: int | None = None, timeout=socket._GLOBAL_DEFAULT_TIMEOUT, source_address=None, *, resolved_ip: str):
        super().__init__(host, port=port, timeout=timeout, source_address=source_address)
        self.resolved_ip = resolved_ip

    def connect(self) -> None:
        self.sock = panel_create_connection((self.resolved_ip, self.port), self.timeout, self.source_address)
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
        sock = panel_create_connection((self.resolved_ip, self.port), self.timeout, self.source_address)
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
        return panel_urlopen(req, timeout=timeout)
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
    """Open a panel/control-plane request directly.

    Proxy environment variables are ignored on purpose (a local Clash/v2ray
    proxy must not carry panel traffic) and sockets are bound to the physical
    egress interface (see PanelEgress).
    """
    if is_protected_leikwan_url(req.full_url):
        return urlopen_with_leikwan_doh(req, timeout=timeout)
    return panel_urlopen(req, timeout=timeout)


def _apply_extra_headers(headers: dict[str, str], extra_headers: dict[str, str] | None) -> None:
    for raw_key, raw_value in (extra_headers or {}).items():
        key = str(raw_key).strip()
        value = str(raw_value)
        if key == "" or "\r" in key or "\n" in key or "\r" in value or "\n" in value:
            raise ValueError("invalid extra HTTP header")
        headers[key] = value


def _valid_device_id(value: str) -> bool:
    # Same bounds the panel enforces for X-Device-Id.
    return 0 < len(value) <= 191 and re.search(r"[\x00-\x20\x7f]", value) is None


_DEVICE_ID_WARNED_PATHS: set[str] = set()


def load_or_create_device_id(state_dir: pathlib.Path | str) -> str:
    """Return the persisted device id, creating ``linux-<uuid4 hex>`` once.

    The panel counts this id against the client's online device limit. An id
    that could not be persisted is never sent: every restart would otherwise
    look like a new device. Returns "" in that case (panel falls back to its
    legacy, limit-exempt identity).
    """
    path = pathlib.Path(state_dir) / DEVICE_ID_FILENAME
    try:
        existing = path.read_text(encoding="utf-8").strip()
    except FileNotFoundError:
        existing = ""
    except OSError as exc:
        if str(path) not in _DEVICE_ID_WARNED_PATHS:
            _DEVICE_ID_WARNED_PATHS.add(str(path))
            logging.warning("device id unreadable at %s: %s", path, exc)
        return ""
    if _valid_device_id(existing):
        return existing
    candidate = f"linux-{uuid.uuid4().hex}"
    temporary_name = ""
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=str(path.parent))
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            stream.write(candidate + "\n")
            stream.flush()
            os.fsync(stream.fileno())
        with contextlib.suppress(OSError):
            os.chmod(temporary_name, 0o600)
        if existing == "":
            try:
                # Publish without clobbering an id another process created
                # concurrently (e.g. `check --live` racing the agent).
                os.link(temporary_name, path)
            except FileExistsError:
                winner = path.read_text(encoding="utf-8").strip()
                if _valid_device_id(winner):
                    return winner
                os.replace(temporary_name, path)
            except OSError:
                os.replace(temporary_name, path)
        else:
            os.replace(temporary_name, path)
    except OSError as exc:
        if str(path) not in _DEVICE_ID_WARNED_PATHS:
            _DEVICE_ID_WARNED_PATHS.add(str(path))
            logging.warning("device id could not be persisted at %s: %s", path, exc)
        return ""
    finally:
        if temporary_name and os.path.exists(temporary_name):
            with contextlib.suppress(OSError):
                os.unlink(temporary_name)
    logging.info("created device id %s at %s", candidate, path)
    return candidate


_LEASE_SEAL_MODULE: object = None
_LEASE_KEY_WARNED: set[str] = set()


def lease_seal_module():
    """Return the leikwan_lease_seal helper, or None when it is not installed.

    A sibling import works for the frozen binary and the Docker layout; the
    Python distribution installs it next to this file. A missing helper only
    disables sealing (no header is sent), it never stops the agent.
    """
    global _LEASE_SEAL_MODULE
    if _LEASE_SEAL_MODULE is not None:
        return _LEASE_SEAL_MODULE or None
    module = None
    try:
        import leikwan_lease_seal as module  # type: ignore[no-redef]
    except Exception:  # noqa: BLE001
        module_path = helper_artifact_path("leikwan_lease_seal.py")
        try:
            spec = importlib.util.spec_from_file_location("leikwan_lease_seal", module_path)
            if spec is None or spec.loader is None:
                raise ImportError(f"cannot load {module_path}")
            module = importlib.util.module_from_spec(spec)
            sys.modules["leikwan_lease_seal"] = module
            spec.loader.exec_module(module)
        except Exception as exc:  # noqa: BLE001
            sys.modules.pop("leikwan_lease_seal", None)
            logging.warning("Ultra lease sealing unavailable (helper not installed: %s); leases stay unsealed", type(exc).__name__)
            module = None
    _LEASE_SEAL_MODULE = module if module is not None else False
    return module


def load_or_create_lease_key(state_dir: pathlib.Path | str):
    """Return this device's persisted Ultra lease key (``None`` if sealing is unavailable).

    The key lives in ``state_dir/ultra-lease-key`` with mode 0600. A missing or
    corrupt file is regenerated; an unreadable or unwritable one yields a
    process-lifetime key so leases still work (the panel re-pins an idle key).
    """
    module = lease_seal_module()
    if module is None:
        return None
    path = pathlib.Path(state_dir) / LEASE_KEY_FILENAME
    try:
        key, event = module.load_or_create_device_key(path)
    except Exception as exc:  # noqa: BLE001
        logging.warning("Ultra lease key unavailable at %s (%s); leases stay unsealed", path, type(exc).__name__)
        return None
    if event in {"created", "replaced"}:
        logging.info("%s Ultra lease key kid=%s at %s", "created" if event == "created" else "regenerated corrupt", key.kid, path)
    elif event in {"unreadable", "unpersisted"} and str(path) not in _LEASE_KEY_WARNED:
        _LEASE_KEY_WARNED.add(str(path))
        logging.warning("Ultra lease key %s at %s; using a key for this process only (kid=%s)", event, path, key.kid)
    return key


def ultra_lease_key_refusal(error: BaseException) -> str:
    """Name the lease-key refusal code in a panel error, or ""."""
    text = str(error)
    for code in ULTRA_LEASE_KEY_REFUSALS:
        if code in text:
            return code
    return ""


def open_ultra_lease(lease: dict, lease_key: object) -> dict:
    """Open a sealed lease (unchanged when unsealed); raises on any failure."""
    if not isinstance(lease, dict) or "sealed" not in lease:
        return lease
    module = lease_seal_module()
    if module is None or lease_key is None:
        raise ValueError("sealed lease without a device key")
    return module.open_sealed_lease(lease, lease_key)


def device_request_headers(device_id: str = "") -> dict[str, str]:
    headers = {
        "x-device-platform": "linux",
        "x-device-architecture": local_runtime_architecture(),
    }
    if device_id:
        headers["x-device-id"] = device_id
    return headers


class DeviceLimitReachedError(RuntimeError):
    """The panel refused this device: the client's online device limit is full.

    This is a business answer, not an outage: it must not rotate mirrors,
    penalize an endpoint or trigger network backoff.
    """

    response_received = True
    error_code = DEVICE_LIMIT_ERROR_CODE

    def __init__(
        self,
        url: str = "",
        status: int = 429,
        online_device_limit: int | None = None,
        active_online_devices: int | None = None,
    ):
        self.url = url
        self.status = int(status)
        self.online_device_limit = online_device_limit
        self.active_online_devices = active_online_devices
        super().__init__(f"http {self.status} {url}: {DEVICE_LIMIT_ERROR_CODE} ({self.counts_text() or 'counts unknown'})")

    def counts_text(self) -> str:
        if self.online_device_limit is None or self.active_online_devices is None:
            return ""
        return f"{self.active_online_devices} of {self.online_device_limit} devices online"

    def user_message(self, retry_seconds: float | None = None) -> str:
        counts = self.counts_text()
        message = "device limit reached" + (f" ({counts})" if counts else "")
        message += ": disconnect another device or ask your provider to raise the device limit"
        if retry_seconds is not None:
            message += f"; retrying in {int(retry_seconds)}s"
        return message


def parse_device_limit_error(status: int, body: str, url: str = "") -> DeviceLimitReachedError | None:
    """Recognize the panel's online-device-limit refusal in an HTTP error body."""
    if not 400 <= int(status) <= 499:
        return None
    try:
        payload = json.loads(body)
    except (TypeError, ValueError):
        return None
    if not isinstance(payload, dict) or payload.get("error") != DEVICE_LIMIT_ERROR_CODE:
        return None
    details = payload.get("details") if isinstance(payload.get("details"), dict) else {}

    def count(key: str) -> int | None:
        for source in (payload, details):
            value = source.get(key)
            if isinstance(value, bool):
                continue
            try:
                number = int(value)
            except (TypeError, ValueError):
                continue
            if number >= 0:
                return number
        return None

    return DeviceLimitReachedError(url, int(status), count("online_device_limit"), count("active_online_devices"))


def _raise_for_http_error(exc: urllib.error.HTTPError, url: str) -> None:
    body = exc.read().decode("utf-8", errors="ignore")
    limit_error = parse_device_limit_error(exc.code, body, url)
    if limit_error is not None:
        raise limit_error from exc
    raise RuntimeError(f"http {exc.code} {url}: {body}") from exc


def http_json(
    method: str,
    url: str,
    token: str,
    payload: dict | None = None,
    *,
    extra_headers: dict[str, str] | None = None,
) -> dict:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
    }
    _apply_extra_headers(headers, extra_headers)
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with _urlopen_for_request(req, timeout=30) as resp:
            try:
                return json.loads(resp.read().decode("utf-8"))
            except (UnicodeDecodeError, json.JSONDecodeError) as exc:
                raise ControlPlaneResponseError(f"invalid JSON response from {url}") from exc
    except urllib.error.HTTPError as exc:
        _raise_for_http_error(exc, url)


def http_json_with_headers(
    method: str,
    url: str,
    token: str,
    payload: dict | None = None,
    *,
    extra_headers: dict[str, str] | None = None,
) -> tuple[dict, dict[str, str]]:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
    }
    _apply_extra_headers(headers, extra_headers)
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with _urlopen_for_request(req, timeout=30) as resp:
            content = resp.read().decode("utf-8")
            response_headers = {key.lower(): value for key, value in resp.headers.items()}
    except urllib.error.HTTPError as exc:
        _raise_for_http_error(exc, url)
    try:
        return json.loads(content), response_headers
    except json.JSONDecodeError as exc:
        raise ControlPlaneResponseError(f"invalid JSON response from {url}") from exc


DEFAULT_PANEL_MIRROR_URLS = ["https://api-leikwanbridge.nbwly.com"]


def local_runtime_architecture() -> str:
    """Return the manifest architecture token understood by the panel."""
    machine = platform.machine().strip().lower()
    if machine in {"aarch64", "arm64", "armv8", "armv8l"}:
        return "arm64"
    if machine in {"x86_64", "amd64", "x64"}:
        return "amd64"
    return machine or "unknown"


class ControlPlaneEndpointState:
    """Small persistent endpoint selector; API paths and payloads stay unchanged."""

    def __init__(self, path: pathlib.Path | None):
        self.path = pathlib.Path(path) if path is not None else None
        self.current_endpoint = ""
        self.failures: dict[str, dict] = {}
        self._lock = threading.RLock()
        self._load()

    def _load(self) -> None:
        if self.path is None or not self.path.exists():
            return
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return
        if isinstance(payload, dict):
            self.current_endpoint = str(payload.get("current_endpoint", "") or "").rstrip("/")
            raw = payload.get("failures", {})
            if isinstance(raw, dict):
                self.failures = {str(k): dict(v) for k, v in raw.items() if isinstance(v, dict)}

    def _save(self) -> None:
        if self.path is None:
            return
        self.path.parent.mkdir(parents=True, exist_ok=True)
        payload = {"current_endpoint": self.current_endpoint, "failures": self.failures}
        temporary = self.path.with_name(self.path.name + ".new")
        temporary.write_text(json.dumps(payload, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8")
        os.replace(temporary, self.path)

    def ordered(self, endpoints: list[str]) -> list[str]:
        with self._lock:
            normalized = [str(item).rstrip("/") for item in endpoints if str(item).strip()]
            if not normalized:
                return []
            primary = normalized[0]
            if self.current_endpoint in normalized and self.current_endpoint != primary:
                failure = self.failures.get(primary, {})
                count = int(failure.get("count", 0) or 0)
                last_failure = float(failure.get("last_failure_at", 0) or 0)
                retry_after = min(300.0, 15.0 * (2 ** max(0, count - 1)))
                if count > 0 and time.time() >= last_failure + retry_after:
                    return normalized
                return [self.current_endpoint] + [item for item in normalized if item != self.current_endpoint]
            return normalized

    def success(self, endpoint: str) -> None:
        with self._lock:
            self.current_endpoint = str(endpoint).rstrip("/")
            self.failures.pop(self.current_endpoint, None)
            self._save()

    def failure(self, endpoint: str, error: Exception) -> None:
        with self._lock:
            key = str(endpoint).rstrip("/")
            entry = self.failures.setdefault(key, {})
            entry["count"] = int(entry.get("count", 0) or 0) + 1
            entry["last_error"] = str(error)[:512]
            entry["last_failure_at"] = time.time()
            self._save()


def _is_control_plane_business_error(error: Exception) -> bool:
    match = re.search(r"\bhttp\s+(\d{3})\b", str(error), flags=re.IGNORECASE)
    if not match:
        return False
    return int(match.group(1)) in {400, 401, 403, 404, 409, 422}


def _has_control_plane_http_response(error: Exception) -> bool:
    return re.search(r"\bhttp\s+\d{3}\b", str(error), flags=re.IGNORECASE) is not None


class ControlPlaneUnavailableError(RuntimeError):
    """Every control-plane endpoint failed without a business answer."""


def is_transient_control_plane_error(error: BaseException) -> bool:
    """Network failures and 5xx answers back off; 4xx business errors do not."""
    if isinstance(error, (ControlPlaneUnavailableError, ControlPlaneResponseError)):
        return True
    if isinstance(error, (urllib.error.URLError, TimeoutError, ConnectionError, socket.timeout, http.client.HTTPException, ssl.SSLError)):
        return True
    codes = [int(code) for code in re.findall(r"\bhttp\s+(\d{3})\b", str(error), flags=re.IGNORECASE)]
    return bool(codes) and all(500 <= code <= 599 for code in codes)


def control_plane_endpoints(config: dict) -> list[str]:
    primary = str(config.get("master_url", "") or "").rstrip("/")
    candidates = [primary] if primary else []
    mirrors = config.get("panel_mirror_urls", [])
    if isinstance(mirrors, list):
        for raw_url in mirrors[:3]:
            url = str(raw_url or "").rstrip("/")
            parsed = urllib.parse.urlparse(url)
            if parsed.scheme == "https" and parsed.hostname and url not in candidates:
                candidates.append(url)
    return candidates


def pull_client_configs(
    config: dict,
    token: str,
    *,
    state_path: pathlib.Path | None = None,
    selector: ControlPlaneEndpointState | None = None,
    device_id: str = "",
) -> tuple[dict, dict[str, str], str]:
    endpoints = control_plane_endpoints(config)
    selector = selector or ControlPlaneEndpointState(state_path)
    errors: list[str] = []
    for base_url in selector.ordered(endpoints):
        try:
            payload, headers = http_json_with_headers(
                "GET",
                f"{base_url}/api/client/configs/pull",
                token,
                extra_headers={
                    **device_request_headers(device_id),
                    **ultra_offline_validity_headers(),
                },
            )
            selector.success(base_url)
            return payload, headers, base_url
        except DeviceLimitReachedError:
            # The panel answered; another mirror would give the same answer.
            raise
        except Exception as exc:  # noqa: BLE001
            selector.failure(base_url, exc)
            errors.append(f"{base_url}: {exc}")
            # Do not hide authentication/authorization or contract errors behind a mirror.
            if _is_control_plane_business_error(exc):
                raise
    raise ControlPlaneUnavailableError("; ".join(errors) if errors else "no control-plane endpoint configured")


def _normalize_ultra_selection(value: object) -> dict | None:
    if not isinstance(value, dict):
        return None
    selection_ref = str(value.get("selection_ref", value.get("entry_ref", "")) or "").strip()
    if re.fullmatch(r"[A-Za-z0-9_-]{16,128}", selection_ref) is None:
        return None
    try:
        rtt_ms = max(0, min(60000, int(value.get("rtt_ms", 0) or 0)))
    except (TypeError, ValueError):
        rtt_ms = 0
    normalized = {
        "selection_ref": selection_ref,
        "rtt_ms": rtt_ms,
        "selected_at": str(value.get("selected_at", "") or "").strip()[:40],
    }
    candidate_key_hash = str(value.get("candidate_key_hash", "") or "").strip().lower()
    if re.fullmatch(r"[a-f0-9]{64}", candidate_key_hash) is not None:
        normalized["candidate_key_hash"] = candidate_key_hash
    lease_hash = str(value.get("lease_hash", "") or "").strip().lower()
    if re.fullmatch(r"[a-f0-9]{64}", lease_hash) is not None:
        normalized["lease_hash"] = lease_hash
    for key, maximum in (("p95_rtt_ms", 60000), ("jitter_ms", 60000), ("loss_percent", 100), ("probe_sent", 4096), ("probe_received", 4096)):
        if key not in value:
            continue
        try:
            normalized[key] = max(0, min(maximum, int(value.get(key, 0) or 0)))
        except (TypeError, ValueError):
            normalized[key] = 0
    return normalized


def _normalize_ultra_feedback(value: object) -> list[dict]:
    if not isinstance(value, list):
        return []
    feedback: list[dict] = []
    for item in value[:8]:
        if not isinstance(item, dict):
            continue
        candidate_key_hash = str(item.get("candidate_key_hash", "") or "").strip().lower()
        if re.fullmatch(r"[a-f0-9]{64}", candidate_key_hash) is None:
            continue
        lease_hash = str(item.get("lease_hash", "") or "").strip().lower()
        if re.fullmatch(r"[a-f0-9]{64}", lease_hash) is None:
            continue
        protocol = str(item.get("protocol", "") or "").strip().lower()
        if protocol not in {"tcp", "udp", "tcp+udp", "native_socks5"}:
            continue
        outcome = str(item.get("outcome", "") or "").strip().lower()
        if outcome not in {"failure", "success"}:
            continue
        reason = str(item.get("reason", "") or "").strip().lower()[:64]
        if not reason or reason == "process_stderr":
            continue
        evidence_id = str(item.get("evidence_id", "") or "").strip()[:128]
        if evidence_id and re.fullmatch(r"[A-Za-z0-9._:-]{1,128}", evidence_id) is None:
            continue
        observed_at = str(item.get("observed_at", "") or "").strip()[:40]
        feedback.append(
            {
                "candidate_key_hash": candidate_key_hash,
                "lease_hash": lease_hash,
                "protocol": protocol,
                "outcome": outcome,
                "reason": reason,
                "evidence_id": evidence_id,
                "observed_at": observed_at,
            }
        )
    return feedback


def _normalize_optional_count(value: object) -> int | None:
    """Runtime counters/limits pass through only when they are a real positive integer."""
    if isinstance(value, bool) or value is None:
        return None
    try:
        count = int(value)
    except (TypeError, ValueError):
        return None
    return count if count > 0 else None


# A released runtime stamps itself "<version>.<shortsha>" at link time
# (scripts/build-ultra-*.ps1); an older or third-party build may report a bare
# hex build id. Both shapes are forwarded; anything else is dropped.
_RUNTIME_FINGERPRINT_RE = re.compile(
    r"(?:[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]+(?:\.[0-9a-f]{7,40})?|[a-f0-9]{8,64})"
)


def _normalize_runtime_attestation(value: object) -> dict | None:
    """Keep the runtime build attestation only when every field validates; never guess."""
    if not isinstance(value, dict):
        return None
    fingerprint = str(value.get("fingerprint", "") or "").strip().lower()
    if fingerprint != "development" and _RUNTIME_FINGERPRINT_RE.fullmatch(fingerprint) is None:
        return None
    self_sha256 = str(value.get("self_sha256", "") or "").strip().lower()
    if re.fullmatch(r"[a-f0-9]{64}", self_sha256) is None:
        return None
    # The runtime omits these when they are false (json omitempty on the Go
    # side), so "absent" means "not reported" and must stay absent - requiring a
    # bool here dropped the whole attestation for every healthy runtime and the
    # panel therefore never learned which build an agent-managed host runs.
    debugger = value.get("debugger")
    instrumented = value.get("instrumented")
    if debugger is not None and not isinstance(debugger, bool):
        return None
    if instrumented is not None and not isinstance(instrumented, bool):
        return None
    return {
        "fingerprint": fingerprint,
        "self_sha256": self_sha256,
        "debugger": debugger,
        "instrumented": instrumented,
    }


def collect_ultra_status(
    root: pathlib.Path = pathlib.Path("/run/ultra"),
    *,
    expected_uid: int = 0,
) -> list[dict]:
    try:
        root_stat = root.lstat()
    except OSError:
        return []
    if root.is_symlink() or not stat.S_ISDIR(root_stat.st_mode):
        return []
    statuses: list[dict] = []
    candidates: list[tuple[int, pathlib.Path]] = []
    for path in root.glob("forward-*.status.json"):
        match = re.fullmatch(r"forward-(\d+)\.status\.json", path.name)
        if match is None:
            continue
        forward_id = int(match.group(1))
        if 0 < forward_id <= 2_147_483_647:
            candidates.append((forward_id, path))
    candidates.sort(key=lambda item: item[0])
    nofollow = getattr(os, "O_NOFOLLOW", 0)
    for expected_forward_id, path in candidates:
        if len(statuses) >= 32:
            break
        descriptor: int | None = None
        try:
            descriptor = os.open(path, os.O_RDONLY | nofollow)
            opened = os.fstat(descriptor)
            if not stat.S_ISREG(opened.st_mode) or opened.st_uid != int(expected_uid) or opened.st_size > 64 * 1024:
                continue
            with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
                descriptor = None
                decoded = json.load(handle)
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            continue
        finally:
            if descriptor is not None:
                os.close(descriptor)
        if not isinstance(decoded, dict) or int(decoded.get("forward_id", 0) or 0) != expected_forward_id:
            continue
        state = str(decoded.get("state", "ready") or "ready").strip().lower()
        if state not in {"ready", "degraded", "error", "stopped"}:
            state = "error"
        zone = str(decoded.get("detected_ingress_zone", "unknown") or "unknown").strip().lower()
        if re.fullmatch(r"[a-z0-9-]{1,32}", zone) is None:
            zone = "unknown"
        status_entry = {
            "forward_id": expected_forward_id,
            "state": state,
            "detected_ingress_zone": zone,
            "location_source": str(decoded.get("location_source", "") or "").strip()[:32],
            "latest_tcp": _normalize_ultra_selection(decoded.get("latest_tcp")),
            "active_udp": _normalize_ultra_selection(decoded.get("active_udp")),
            "candidate_feedback": _normalize_ultra_feedback(decoded.get("candidate_feedback")),
            "updated_at": str(decoded.get("updated_at", "") or "").strip()[:40],
            "error_code": str(decoded.get("error_code", "") or "").strip()[:64],
            "policy_generation": str(decoded.get("policy_generation", "") or "").strip().lower()[:64],
            "bytes_in": max(0, int(decoded.get("bytes_in", 0) or 0)),
            "bytes_out": max(0, int(decoded.get("bytes_out", 0) or 0)),
        }
        policy_state = ultra_policy_state_from_status(decoded)
        if policy_state:
            status_entry["policy_state"] = policy_state
        # Measured path ceiling / oversize drops drive the overlay MTU, and the build
        # attestation drives runtime trust. Absent, zero or malformed values are omitted.
        for key, value in (
            ("udp_payload_ceiling", _normalize_optional_count(decoded.get("udp_payload_ceiling"))),
            ("udp_oversize_drops", _normalize_optional_count(decoded.get("udp_oversize_drops"))),
            ("runtime_attestation", _normalize_runtime_attestation(decoded.get("runtime_attestation"))),
        ):
            if value is not None:
                status_entry[key] = value
        statuses.append(status_entry)
    return statuses


def collect_ultra_candidate_feedback(statuses: list[dict]) -> list[dict]:
    feedback: list[dict] = []
    for status in statuses[:32]:
        if not isinstance(status, dict):
            continue
        try:
            forward_id = int(status.get("forward_id", 0) or 0)
        except (TypeError, ValueError):
            continue
        if forward_id <= 0 or forward_id > 2147483647:
            continue
        for item in _normalize_ultra_feedback(status.get("candidate_feedback")):
            enriched = dict(item)
            enriched["forward_id"] = forward_id
            feedback.append(enriched)
            if len(feedback) >= 32:
                return feedback
    return feedback


def resolve_ultra_leases(
    master: str,
    agent_token: str,
    device_id: str,
    forwards: list[dict],
    *,
    selection_mode: str = "auto",
    fallback_endpoints: list[str] | None = None,
    state_path: pathlib.Path | None = None,
    selector: ControlPlaneEndpointState | None = None,
    lease_key: object = None,
) -> list[dict]:
    normalized_selection_mode = normalize_ultra_selection_mode(selection_mode)
    max_candidates = 4 if normalized_selection_mode == "auto" else 1
    requests: list[dict] = []
    for forward in forwards:
        if not isinstance(forward, dict) or str(forward.get("backend_mode", "")).strip().lower() != "ultra":
            continue
        access = forward.get("ultra_access")
        if not isinstance(access, dict):
            continue
        forward_id = int(forward.get("forward_id", 0) or 0)
        session_ticket = str(access.get("session_ticket", "") or "").strip()
        generation = str(access.get("policy_generation", "") or "").strip().lower()
        target = str(forward.get("target", "") or "").strip()
        transport = str(access.get("transport", forward.get("transport", "tcp+udp")) or "tcp+udp").strip().lower()
        if forward_id <= 0 or session_ticket == "" or re.fullmatch(r"[a-f0-9]{64}", generation) is None or target == "":
            continue
        requests.append(
            {
                "forward_id": forward_id,
                "session_ticket": session_ticket,
                "policy_generation": generation,
                "nonce": secrets.token_hex(16),
                "target_digest": hashlib.sha256(target.encode("utf-8")).hexdigest(),
                "transport": transport,
                "selection_mode": normalized_selection_mode,
                "max_candidates": max_candidates,
            }
        )
    if not requests:
        return forwards
    selector = selector or ControlPlaneEndpointState(state_path)
    configured = [str(master).rstrip("/")]
    for endpoint in fallback_endpoints or []:
        normalized = str(endpoint or "").rstrip("/")
        if normalized and normalized not in configured:
            configured.append(normalized)
    last_error: Exception | None = None
    lease_items: list[dict] = []
    failed_forward_ids: set[int] = set()
    completed_batches = 0
    batches = [requests[index : index + 4] for index in range(0, len(requests), 4)]
    for batch in batches:
        batch_completed = False
        batch_forward_ids = {int(item["forward_id"]) for item in batch}
        for endpoint in selector.ordered(configured):
            try:
                # The panel caps requests at four forwards. Retry only this
                # batch, with fresh nonces, never previously successful batches.
                batch_requests = []
                for item in batch:
                    request = dict(item)
                    request["nonce"] = secrets.token_hex(16)
                    batch_requests.append(request)
                batch_response = http_json(
                    "POST",
                    f"{endpoint}/api/client/ultra/lease",
                    agent_token,
                    {"device_id": device_id, "forwards": batch_requests},
                    extra_headers={
                        **({"x-device-id": device_id} if device_id else {}),
                        **ultra_offline_validity_headers(),
                        **({ULTRA_LEASE_KEY_HEADER: lease_key.public_b64url} if lease_key is not None else {}),
                    },
                )
                if not isinstance(batch_response, dict) or batch_response.get("ok") is not True:
                    raise ControlPlaneResponseError("invalid ultra lease response")
                raw_leases = batch_response.get("leases")
                if not isinstance(raw_leases, list):
                    raise ControlPlaneResponseError("invalid ultra lease list")
                valid_leases = []
                for item in raw_leases:
                    if not isinstance(item, dict):
                        continue
                    try:
                        valid_leases.append(open_ultra_lease(item, lease_key))
                    except Exception as exc:  # noqa: BLE001
                        # Fail this lease closed: no clear-text fallback and no
                        # replay through a mirror. Never log the payload.
                        logging.warning(
                            "forward %s Ultra lease %s: %s (%s)",
                            item.get("forward_id"),
                            str(item.get("lease_id", ""))[:64],
                            LEASE_UNSEAL_FAILED,
                            type(exc).__name__,
                        )
                lease_items.extend(valid_leases)
                returned_forward_ids = {int(item.get("forward_id", 0) or 0) for item in valid_leases}
                failed_forward_ids.update(batch_forward_ids - returned_forward_ids)
                completed_batches += 1
                batch_completed = True
                selector.success(endpoint)
                break
            except DeviceLimitReachedError:
                # A refused device gets no lease from any mirror or batch.
                raise
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                selector.failure(endpoint, exc)
                refusal = ultra_lease_key_refusal(exc)
                if refusal:
                    # Reported and retried at the normal cadence; the key is kept.
                    logging.warning(
                        "Ultra lease refused for forwards %s: %s (kid=%s)",
                        sorted(batch_forward_ids),
                        refusal,
                        getattr(lease_key, "kid", "none"),
                    )
                # An HTTP response must not replay this POST through another CDN.
                if getattr(exc, "response_received", False) or _has_control_plane_http_response(exc):
                    if _is_control_plane_business_error(exc):
                        failed_forward_ids.update(batch_forward_ids)
                        completed_batches += 1
                        batch_completed = True
                    break
        if not batch_completed:
            failed_forward_ids.update(item["forward_id"] for item in batch)
    if completed_batches == 0:
        if last_error is not None and (getattr(last_error, "response_received", False) or _has_control_plane_http_response(last_error)):
            raise last_error
        raise ControlPlaneUnavailableError(f"ultra material resolution retries exhausted: {last_error}") from last_error
    if failed_forward_ids:
        logging.warning("Ultra lease batches failed for forwards %s; retaining successful batches", sorted(failed_forward_ids))
    leases_by_forward: dict[int, list[dict]] = {}
    for item in lease_items:
        if not isinstance(item, dict):
            continue
        forward_id = int(item.get("forward_id", 0) or 0)
        if forward_id <= 0:
            continue
        candidates = leases_by_forward.setdefault(forward_id, [])
        candidate_key = ultra_endpoint_key(item)
        if candidate_key is not None and any(ultra_endpoint_key(candidate) == candidate_key for candidate in candidates):
            continue
        if len(candidates) < max_candidates:
            candidates.append(item)
    result: list[dict] = []
    for forward in forwards:
        if not isinstance(forward, dict):
            continue
        enriched = dict(forward)
        enriched.pop("_ultra_lease_resolution_failed", None)
        if int(enriched.get("forward_id", 0) or 0) in failed_forward_ids:
            enriched["_ultra_lease_resolution_failed"] = True
        leases = leases_by_forward.get(int(enriched.get("forward_id", 0) or 0), [])
        if leases:
            enriched["_ultra_lease"] = leases[0]
            enriched["_ultra_leases"] = leases
        result.append(enriched)
    return result


def ultra_lease_candidates(forward: dict) -> list[dict]:
    raw = forward.get("_ultra_leases") if isinstance(forward, dict) else None
    if isinstance(raw, list):
        candidates = []
        seen_endpoints: set[tuple[str, int, str]] = set()
        for item in raw:
            if not isinstance(item, dict):
                continue
            key = ultra_endpoint_key(item)
            if key is not None and key in seen_endpoints:
                continue
            if key is not None:
                seen_endpoints.add(key)
            candidates.append(item)
        if candidates:
            return candidates[:4]
    single = forward.get("_ultra_lease") if isinstance(forward, dict) else None
    return [single] if isinstance(single, dict) else []


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
    def __init__(self, config_path: pathlib.Path):
        self.config_path = config_path
        self.config = json.loads(config_path.read_text(encoding="utf-8-sig"))
        configure_panel_egress(self.config)
        self.state_dir = pathlib.Path(self.config.get("state_dir", "/var/lib/leikwan-client-agent"))
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.applied_path = self.state_dir / "applied.json"
        self.pull_state_path = self.state_dir / "config_pull_state.json"
        self.usage_state_path = self.state_dir / "usage_state.json"
        self.endpoint_state_path = self.state_dir / "control-plane-endpoint.json"
        self.endpoint_selector = ControlPlaneEndpointState(self.endpoint_state_path)
        self.active_control_plane_endpoint = str(self.config.get("master_url", "") or "").rstrip("/")
        self.latest_forwards: list[dict] = []
        self.health_restart_after: dict[int, float] = {}
        self.health_reorder_after: dict[int, float] = {}
        self.invalid_forward_log_after: dict[int, float] = {}
        self.missing_forward_observations: dict[int, dict[str, object]] = {}
        self.last_ultra_leases: dict[int, list[dict]] = {}
        self.self_leg_probe_cache: dict[str, dict] = {}
        self._cfwg_latency_watchdog = None
        self._applied_state_lock = threading.RLock()
        self._forward_locks: dict[int, threading.RLock] = {}
        self._forward_locks_guard = threading.Lock()
        self._debug_upload_lock = threading.Lock()
        self._debug_upload_thread: threading.Thread | None = None
        logging.basicConfig(
            level=getattr(logging, str(self.config.get("log_level", "INFO")).upper(), logging.INFO),
            format="%(asctime)s %(levelname)s %(message)s",
        )

    def device_id(self) -> str:
        """Stable panel device identity persisted under state_dir ("" if unavailable)."""
        state_dir = getattr(self, "state_dir", None)
        if state_dir is None:
            return ""
        cached = getattr(self, "_device_id_cache", None)
        if isinstance(cached, tuple) and cached[0] == str(state_dir) and cached[1]:
            return cached[1]
        value = load_or_create_device_id(state_dir)
        self._device_id_cache = (str(state_dir), value)
        return value

    def lease_device_key(self):
        """This device's Ultra lease key (sealed lease v1), or None when unavailable."""
        state_dir = getattr(self, "state_dir", None)
        if state_dir is None:
            return None
        cached = getattr(self, "_lease_key_cache", None)
        if isinstance(cached, tuple) and cached[0] == str(state_dir) and cached[1] is not None:
            return cached[1]
        value = load_or_create_lease_key(state_dir)
        self._lease_key_cache = (str(state_dir), value)
        return value

    def pull_client_configs(self, token: str) -> tuple[dict, dict[str, str], str]:
        payload, headers, endpoint = pull_client_configs(
            self.config,
            token,
            state_path=getattr(self, "endpoint_state_path", None),
            selector=getattr(self, "endpoint_selector", None),
            device_id=self.device_id(),
        )
        self.active_control_plane_endpoint = endpoint
        return payload, headers, endpoint

    def control_plane_request(
        self,
        method: str,
        path: str,
        token: str,
        payload: dict | None = None,
        *,
        extra_headers: dict[str, str] | None = None,
    ) -> dict:
        selector = getattr(self, "endpoint_selector", None) or ControlPlaneEndpointState(
            getattr(self, "endpoint_state_path", None)
        )
        endpoints = selector.ordered(control_plane_endpoints(self.config))
        # Every panel call carries this install's device identity.
        headers = {**device_request_headers(self.device_id()), **(extra_headers or {})}
        errors: list[str] = []
        for endpoint in endpoints:
            try:
                if extra_headers:
                    result = http_json_with_headers(
                        method,
                        f"{endpoint.rstrip('/')}/{path.lstrip('/')}",
                        token,
                        payload,
                        extra_headers=headers,
                    )[0]
                else:
                    result = http_json(
                        method,
                        f"{endpoint.rstrip('/')}/{path.lstrip('/')}",
                        token,
                        payload,
                        extra_headers=headers,
                    )
                selector.success(endpoint)
                self.active_control_plane_endpoint = endpoint
                return result
            except DeviceLimitReachedError:
                raise
            except Exception as exc:  # noqa: BLE001
                selector.failure(endpoint, exc)
                errors.append(f"{endpoint}: {exc}")
                if getattr(exc, "response_received", False) or _has_control_plane_http_response(exc):
                    raise
        raise ControlPlaneUnavailableError("; ".join(errors) if errors else "no control-plane endpoint configured")

    def _systemd_watchdog_notify(self) -> None:
        notify_socket = os.environ.get("NOTIFY_SOCKET", "").strip()
        if notify_socket == "":
            return
        try:
            address = notify_socket
            if address.startswith("@"):
                address = "\0" + address[1:]
            sock = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
            try:
                sock.connect(address)
                sock.sendall(b"READY=1\nWATCHDOG=1\nSTATUS=Leikwan client agent running")
            finally:
                sock.close()
        except Exception:
            logging.debug("systemd watchdog notification unavailable", exc_info=True)

    def _forward_lock(self, forward_id: int) -> threading.RLock:
        if not hasattr(self, "_forward_locks_guard"):
            self._forward_locks_guard = threading.Lock()
        if not hasattr(self, "_forward_locks"):
            self._forward_locks = {}
        with self._forward_locks_guard:
            return self._forward_locks.setdefault(int(forward_id), threading.RLock())

    def _save_applied_entry(self, forward_id: int, entry: dict) -> None:
        if not hasattr(self, "_applied_state_lock"):
            self._applied_state_lock = threading.RLock()
        with self._applied_state_lock:
            applied = self.load_applied()
            applied[str(forward_id)] = entry
            self._write_applied_state_locked(applied)

    def _write_applied_state_locked(self, applied: dict) -> None:
        """Atomically publish applied runtime metadata under the process lock."""
        self.applied_path.parent.mkdir(parents=True, exist_ok=True)
        fd, temporary_name = tempfile.mkstemp(prefix=f".{self.applied_path.name}.", suffix=".tmp", dir=str(self.applied_path.parent))
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as stream:
                json.dump(applied, stream, ensure_ascii=False, indent=2)
                stream.write("\n")
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary_name, self.applied_path)
        finally:
            if os.path.exists(temporary_name):
                os.unlink(temporary_name)

    def write_applied_state(self, applied: dict) -> None:
        if not hasattr(self, "_applied_state_lock"):
            self._applied_state_lock = threading.RLock()
        with self._applied_state_lock:
            self._write_applied_state_locked(applied)

    def schedule_debug_log_upload(self, master: str, token: str, client_meta: dict) -> None:
        """Start at most one daemon uploader; config sync remains non-blocking."""
        if not isinstance(client_meta, dict) or not isinstance(client_meta.get("debug_collection"), dict):
            return
        if not hasattr(self, "_debug_upload_lock"):
            self._debug_upload_lock = threading.Lock()
        if not hasattr(self, "_debug_upload_thread"):
            self._debug_upload_thread = None
        with self._debug_upload_lock:
            if self._debug_upload_thread is not None and self._debug_upload_thread.is_alive():
                return
            thread = threading.Thread(
                target=self._run_debug_log_upload,
                args=(master, token, dict(client_meta)),
                name="debug-log-upload",
                daemon=True,
            )
            self._debug_upload_thread = thread
            thread.start()

    def _run_debug_log_upload(self, master: str, token: str, client_meta: dict) -> None:
        try:
            result = upload_debug_collection(
                master,
                token,
                client_meta,
                self.state_dir,
                self.config,
                post=lambda payload: self.control_plane_request(
                    "POST",
                    "/api/client/debug/log",
                    token,
                    payload,
                    extra_headers=device_request_headers(
                        self.device_id() or str(client_meta.get("current_device_id", "") or "")
                    ),
                ),
            )
            if result.get("status") not in {"not_requested", "inactive", "expired", "complete"}:
                logging.info("debug log upload status=%s chunks=%s bytes=%s", result.get("status"), result.get("chunks"), result.get("bytes"))
        except Exception as exc:  # noqa: BLE001
            logging.warning("debug log upload failed: %s", exc)

    @staticmethod
    def restart_relevant_content(service_name: str, content: str) -> str:
        """Content with volatile fields removed, for restart decisions only.

        rendered_runtime_signature already strips policy_generation so that a
        policy rotation does not look like a contract change. The restart check
        in _apply_forward_locked compared raw bytes instead, so every rotation
        rewrote the ultra config, reported files_changed, and restarted the
        forward service. That restart is what the operator sees as a drop: the
        listener disappears for tens of seconds while the unit cycles.

        Only the restart decision is normalised here. The file on disk still
        receives the real content, so the running runtime and any later read see
        the true generation.
        """
        if not service_name.startswith("ultra-forward-"):
            return content
        try:
            decoded = json.loads(content)
        except (TypeError, ValueError, json.JSONDecodeError):
            return content
        if not isinstance(decoded, dict) or "policy_generation" not in decoded:
            return content
        decoded = dict(decoded)
        decoded.pop("policy_generation", None)
        return json.dumps(decoded, ensure_ascii=False, sort_keys=True, separators=(",", ":"))

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
            WRITTEN_RUNTIME_CONTENT[str(path)] = content

        if mode is not None and path.exists() and os.name != "nt":
            current_mode = path.stat().st_mode & 0o777
            if current_mode != mode:
                path.chmod(mode)
                changed = True

        return changed

    @staticmethod
    def detect_time_service() -> str:
        services = [
            ("ntpsec.service", "ntpsec"),
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

    def wstunnel_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("wstunnel_bin", "/usr/local/bin/wstunnel") or "/usr/local/bin/wstunnel")
        return pathlib.Path(raw)

    def ultra_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("ultra_bin", "/usr/local/bin/ultra-client") or "/usr/local/bin/ultra-client")
        return pathlib.Path(raw)

    def bond_ingress_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("bond_ingress_bin", "/usr/local/bin/leikwan-ingress") or "/usr/local/bin/leikwan-ingress")
        return pathlib.Path(raw)

    def rate_limit_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("rate_limit_bin", "/usr/local/bin/leikwan-rate-limit.py") or "/usr/local/bin/leikwan-rate-limit.py")
        return pathlib.Path(raw)

    def tcp_race_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("tcp_race_bin", "/usr/local/bin/leikwan-tcp-race.py") or "/usr/local/bin/leikwan-tcp-race.py")
        return pathlib.Path(raw)

    def udp_fanout_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("udp_fanout_bin", "/usr/local/bin/leikwan-udp-fanout.py") or "/usr/local/bin/leikwan-udp-fanout.py")
        return pathlib.Path(raw)

    def uot_bond_binary_path(self) -> pathlib.Path:
        raw = str(self.config.get("uot_bond_bin", "/usr/local/bin/leikwan-uot-bond") or "/usr/local/bin/leikwan-uot-bond")
        return pathlib.Path(raw)

    def forward_runtime_requirements(self, forward: dict) -> dict[str, pathlib.Path]:
        """Return binaries required to render and run one published forward."""
        requirements: dict[str, pathlib.Path] = {}
        backend_mode = str(forward.get("backend_mode", "") or "").strip().lower()
        mode = self.normalize_mode(forward.get("mode", "single"))
        if backend_mode != "ultra":
            requirements["wstunnel_bin"] = self.wstunnel_binary_path()
        if backend_mode == "ultra":
            requirements["ultra_bin"] = self.ultra_binary_path()
            return requirements

        if self.forward_uses_bond_runtime(forward):
            requirements["bond_bin"] = self.bond_binary_path()
            bonding = forward.get("bonding", {})
            if isinstance(bonding, dict) and str(bonding.get("ingress_mode", "") or "").strip().lower() == "plain_tcp_mux":
                requirements["bond_ingress_bin"] = self.bond_ingress_binary_path()
        if mode == "mptcp":
            requirements["mptcp_client_bin"] = self.mptcp_client_path()

        try:
            speed_limit_mbps = int(forward.get("speed_limit_mbps", 0) or 0)
        except (TypeError, ValueError):
            speed_limit_mbps = 0
        if speed_limit_mbps > 0:
            requirements["rate_limit_bin"] = self.rate_limit_binary_path()

        endpoints = self.usable_endpoints(forward)
        if mode == "race" and len(endpoints) > 1:
            protocols = self.transport_protocols(forward.get("transport", "tcp+udp"))
            if "tcp" in protocols:
                requirements["tcp_race_bin"] = self.tcp_race_binary_path()
            if "udp" in protocols:
                requirements["udp_fanout_bin"] = self.udp_fanout_binary_path()

        sidepath = self.config.get("udp_uot_sidepath", {})
        enabled_ids = sidepath.get("enabled_forward_ids", []) if isinstance(sidepath, dict) else []
        if not isinstance(enabled_ids, list):
            enabled_ids = []
        sidepath_forwards = sidepath.get("forwards", {}) if isinstance(sidepath, dict) else {}
        if not isinstance(sidepath_forwards, dict):
            sidepath_forwards = {}
        if (
            self.forward_uses_bond_runtime(forward)
            and str(forward.get("transport", "tcp+udp") or "tcp+udp").strip().lower() == "tcp+udp"
            and int(forward.get("forward_id", 0) or 0) in {int(value) for value in enabled_ids if str(value).strip().isdigit()}
            and sidepath_forwards.get(str(int(forward.get("forward_id", 0) or 0))) is not None
        ):
            requirements["uot_bond_bin"] = self.uot_bond_binary_path()
        return requirements

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
        return helper_artifact_path("install_forward_runtime.py")

    def helper_artifact_hashes(self) -> dict[str, str]:
        """SHA-256 of the installed helper files reported to the control plane."""
        cfwg_watchdog_path = helper_artifact_path("cfwg_latency_watchdog.py")
        return {
            "install_forward_runtime_sha256": file_sha256(self.install_forward_runtime_path()),
            "mptcp_client_sha256": file_sha256(self.mptcp_client_path()),
            "wstunnel_sha256": file_sha256(self.wstunnel_binary_path()),
            "cfwg_watchdog_sha256": file_sha256(cfwg_watchdog_path) if cfwg_watchdog_path.is_file() else "",
        }

    def ultra_selection_mode(self) -> str:
        return normalize_ultra_selection_mode(self.config.get("ultra_selection_mode", "auto"))

    def semantic_agent_config(self) -> dict:
        payload = dict(self.config)
        payload["wireguard_listen_port"] = self.wireguard_listen_port()
        payload["wireguard_fallback_tcp_port"] = self.wireguard_fallback_tcp_port()
        payload["install_forward_runtime_sha256"] = file_sha256(self.install_forward_runtime_path())
        payload["mptcp_client_sha256"] = file_sha256(self.mptcp_client_path())
        payload["wstunnel_sha256"] = file_sha256(self.wstunnel_binary_path())
        payload["wstunnel_version"] = str(self.config.get("wstunnel_version", "") or "")
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
        uot_bond_bin = pathlib.Path(str(config.get("uot_bond_bin", "/usr/local/bin/leikwan-uot-bond") or "/usr/local/bin/leikwan-uot-bond"))
        raw_sidepath = config.get("udp_uot_sidepath", {})
        normalized_sidepath = dict(raw_sidepath) if isinstance(raw_sidepath, dict) else {}
        uot_build_info: dict = {}
        if normalized_sidepath:
            try:
                from install_forward_runtime import normalize_uot_scheduler, probe_uot_build_info

                normalized_sidepath.update(normalize_uot_scheduler(normalized_sidepath))
                if uot_bond_bin.exists() and not str(uot_bond_bin).lower().endswith(".py"):
                    uot_build_info = probe_uot_build_info(str(uot_bond_bin))
            except (ImportError, TypeError, ValueError):
                normalized_sidepath = self.strip_apply_observation_fields(normalized_sidepath)
        runtime_inputs = {
            "agent_version": AGENT_VERSION,
            "agent_sha256": agent_artifact_sha256(),
            "install_forward_runtime_sha256": file_sha256(self.install_forward_runtime_path()),
            "mptcp_client_sha256": file_sha256(mptcp_client),
            "udp_uot_sidepath": normalized_sidepath,
            "uot_bond_bin": str(uot_bond_bin),
            "uot_bond_sha256": file_sha256(uot_bond_bin),
            "uot_bond_build_info": uot_build_info,
            "bond_bin": str(bond_bin),
            "mptcp_client_bin": str(mptcp_client),
            "state_dir": str(getattr(self, "state_dir", config.get("state_dir", "/var/lib/leikwan-client-agent"))),
            "wireguard_listen_port": wireguard_listen_port,
            "wireguard_fallback_tcp_port": wireguard_fallback_tcp_port,
            "kernel_supports_mptcp": self.running_kernel_supports_mptcp(),
        }
        normalized_forward = self.strip_apply_observation_fields(forward)
        if isinstance(normalized_forward, dict) and str(normalized_forward.get("backend_mode", "") or "").strip().lower() == "ultra":
            normalized_forward = dict(normalized_forward)
            normalized_forward.pop("ultra_access", None)
            normalized_forward.pop("_ultra_material", None)
            normalized_forward.pop("_ultra_lease", None)
            normalized_forward.pop("_ultra_leases", None)
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
            service_name = str(unit.get("service_name", "") or "")
            normalized_files = []
            for file_entry in unit.get("files", []):
                if not isinstance(file_entry, dict):
                    continue
                if file_entry.get("restart_on_change") is False:
                    continue
                content = str(file_entry.get("content", "") or "")
                if service_name.startswith("ultra-forward-"):
                    try:
                        decoded = json.loads(content)
                    except (TypeError, ValueError, json.JSONDecodeError):
                        decoded = None
                    if isinstance(decoded, dict) and "policy_generation" in decoded:
                        decoded = dict(decoded)
                        decoded.pop("policy_generation", None)
                        content = json.dumps(decoded, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
                normalized_files.append(
                    {
                        "path": str(file_entry.get("path", "") or ""),
                        "content": content,
                        "mode": file_entry.get("mode"),
                    }
                )
            normalized_units.append(
                {
                    "service_name": service_name,
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

    @classmethod
    def ultra_static_runtime_matches_disk(
        cls, rendered: dict, previous: dict, mode: str, transport: str, listen_port: int
    ) -> bool:
        # Older agents hashed policy_generation. Verify the actual static files
        # before migrating that signature without interrupting a live runtime.
        raw_previous_paths = previous.get("managed_paths", []) if isinstance(previous, dict) else []
        previous_paths = {str(path).strip() for path in raw_previous_paths if str(path).strip() != ""} if isinstance(raw_previous_paths, list) else set()
        if cls.rendered_managed_paths(rendered) != previous_paths:
            return False
        disk = json.loads(json.dumps(rendered))
        units = [disk] + [item for item in disk.get("extra_units", []) if isinstance(item, dict)]
        if {unit.get("service_name") for unit in units} != {
            unit.get("service_name") for unit in previous.get("units", []) if isinstance(unit, dict)
        }:
            return False
        try:
            for unit in units:
                unit["service_unit"] = pathlib.Path(unit["service_path"]).read_text(encoding="utf-8")
                if unit.get("start_script_path"):
                    unit["start_script"] = pathlib.Path(unit["start_script_path"]).read_text(encoding="utf-8")
                for file_entry in unit.get("files", []):
                    file_path = pathlib.Path(file_entry["path"])
                    expected_mode = file_entry.get("mode")
                    if os.name == "posix" and expected_mode is not None and (file_path.stat().st_mode & 0o777) != int(expected_mode):
                        return False
                    file_entry["content"] = file_path.read_text(encoding="utf-8")
        except (OSError, UnicodeError, KeyError, TypeError):
            return False
        return cls.rendered_runtime_signature(disk, mode, transport, listen_port) == cls.rendered_runtime_signature(
            rendered, mode, transport, listen_port
        )

    def apply_reload_only_files(self, rendered: dict) -> bool:
        changed_any = False
        units = [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
        for unit in units:
            service_name = str(unit.get("service_name", "") or "").strip()
            for file_entry in unit.get("files", []):
                if not isinstance(file_entry, dict) or file_entry.get("restart_on_change") is not False:
                    continue
                path = pathlib.Path(str(file_entry.get("path", "") or ""))
                if str(path) in {"", "."}:
                    continue
                changed = self.write_text_if_changed(
                    path,
                    str(file_entry.get("content", "")),
                    int(file_entry["mode"]) if file_entry.get("mode") is not None else None,
                )
                changed_any = changed_any or changed
                if changed and service_name != "" and self.systemd_is_active(service_name):
                    reload_signal = str(file_entry.get("reload_signal", "") or "").strip().upper()
                    if reload_signal == "HUP":
                        subprocess.run(["systemctl", "kill", "-s", "HUP", f"{service_name}.service"], check=False)
        return changed_any

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

    @staticmethod
    def inferred_ultra_runtime_entry(forward_id: int) -> dict:
        service_name = f"ultra-forward-{int(forward_id)}"
        managed_paths = [
            f"/etc/systemd/system/{service_name}.service",
            f"/etc/ultra/forward-{int(forward_id)}.json",
        ]
        return {
            "units": [{"service_name": service_name, "managed_paths": managed_paths}],
            "managed_paths": managed_paths,
            "runtime_mode": "single",
            "published_mode": "ultra",
        }

    @staticmethod
    def systemd_main_pid(service_name: str) -> int:
        result = subprocess.run(
            ["systemctl", "show", f"{service_name}.service", "--property=MainPID", "--value"],
            text=True,
            capture_output=True,
            check=False,
        )
        try:
            return max(0, int(result.stdout.strip()))
        except (TypeError, ValueError):
            return 0

    @staticmethod
    def legacy_ultra_override_path(forward_id: int) -> pathlib.Path:
        return pathlib.Path(
            f"/etc/systemd/system/ultra-forward-{int(forward_id)}.service.d/20-extuot.conf"
        )

    def migrate_legacy_ultra_override(self, forward_id: int) -> bool:
        """Quarantine the exact pre-release external-UoT canary override."""
        path = self.legacy_ultra_override_path(forward_id)
        try:
            content = path.read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            return False
        expected = (
            "ExecStart=/usr/local/bin/ultra-client-extuot "
            f"--config /etc/ultra/forward-{int(forward_id)}.json"
        )
        exec_lines = {
            line.strip()
            for line in content.splitlines()
            if line.strip().startswith("ExecStart=")
        }
        if expected not in exec_lines or not exec_lines.issubset({"ExecStart=", expected}):
            logging.error(
                "forward %s has an unknown Ultra systemd override at %s; preserving it",
                forward_id,
                path,
            )
            return False
        backup_dir = self.state_dir / "legacy-systemd-overrides"
        backup = backup_dir / f"ultra-forward-{int(forward_id)}-20-extuot-{time.time_ns()}.conf"
        try:
            backup_dir.mkdir(parents=True, exist_ok=True)
            if os.name == "posix":
                backup_dir.chmod(0o700)
            os.replace(path, backup)
            if os.name == "posix":
                backup.chmod(0o600)
        except OSError:
            logging.exception("forward %s failed to quarantine legacy Ultra override %s", forward_id, path)
            return False
        logging.warning(
            "forward %s quarantined legacy ultra-client-extuot override to %s",
            forward_id,
            backup,
        )
        return True

    def ultra_runtime_requires_refresh(self, forward_id: int) -> bool:
        """A released runtime replaces the file, not the running process.

        Restart an active forward whose process still executes a replaced or
        deleted inode. A unit that deliberately runs another binary would never
        converge: once a restart for this installed binary produced a new main
        PID that still differs, stop forcing restarts until another runtime is
        installed. An unchanged PID means the restart never happened; retry.
        """
        service_name = f"ultra-forward-{int(forward_id)}"
        override_migrated = self.migrate_legacy_ultra_override(forward_id)
        if not self.systemd_is_active(service_name):
            return override_migrated
        main_pid = self.systemd_main_pid(service_name)
        installed = self.ultra_binary_path()
        running = pathlib.Path(f"/proc/{main_pid}/exe")
        try:
            current = main_pid > 0 and installed.is_file() and os.path.samefile(running, installed)
        except OSError:
            current = False
        if current and not override_migrated:
            return False
        try:
            stat = installed.stat()
            identity = (stat.st_dev, stat.st_ino, stat.st_mtime_ns)
        except OSError:
            identity = None
        attempted = self.__dict__.setdefault("_ultra_refresh_attempted", {})
        previous = attempted.get(int(forward_id))
        if (
            not override_migrated
            and identity is not None
            and previous is not None
            and previous[0] == identity
            and previous[1] != main_pid
        ):
            return False
        if identity is not None:
            attempted[int(forward_id)] = (identity, main_pid)
        logging.warning(
            "forward %s Ultra runtime refresh required (main_pid=%s binary_current=%s override_migrated=%s)",
            forward_id,
            main_pid,
            current,
            override_migrated,
        )
        return True

    def should_force_runtime_restart(self, forward: dict, requested_ids: set[int]) -> bool:
        forward_id = int(forward.get("forward_id", 0) or 0)
        requested = forward_id in requested_ids
        if forward_id <= 0 or str(forward.get("backend_mode", "") or "").strip().lower() != "ultra":
            return requested
        return self.ultra_runtime_requires_refresh(forward_id) or requested

    def preserve_missing_ultra_forward(self, forward_id: int, previous: dict, snapshot_revision: str) -> bool:
        if not isinstance(previous, dict):
            return False
        runtime_mode = str(previous.get("runtime_mode", "") or previous.get("published_mode", "")).strip().lower()
        if runtime_mode != "ultra" and not any(
            str(unit.get("service_name", "") or "").startswith("ultra-forward-")
            for unit in (previous.get("units", []) if isinstance(previous.get("units", []), list) else [])
            if isinstance(unit, dict)
        ):
            return False
        revision = snapshot_revision.strip() or "missing-revision"
        current = self.missing_forward_observations.get(int(forward_id), {})
        if str(current.get("revision", "")) != revision:
            current = {"revision": revision, "count": 0, "first_seen": time.time()}
        current["count"] = int(current.get("count", 0) or 0) + 1
        self.missing_forward_observations[int(forward_id)] = current
        if int(current["count"]) < 2:
            logging.warning(
                "forward %s missing from config snapshot revision=%s; preserving runtime pending confirmation",
                forward_id,
                revision[:64],
            )
            return True
        self.missing_forward_observations.pop(int(forward_id), None)
        return False

    @staticmethod
    def applied_forward_definition_is_present(previous: dict, forward_id: int | None = None) -> bool:
        if not isinstance(previous, dict):
            return False
        raw_units = previous.get("units", [])
        units = [item for item in raw_units if isinstance(item, dict)] if isinstance(raw_units, list) else []
        if not units or any(str(item.get("service_name", "") or "").strip() == "" for item in units):
            return False
        if forward_id is not None and forward_id > 0:
            expected_service = f"ultra-forward-{forward_id}"
            if not any(str(item.get("service_name", "") or "").strip() == expected_service for item in units):
                return False
        raw_paths = previous.get("managed_paths", [])
        paths = [str(item).strip() for item in raw_paths if str(item).strip() != ""] if isinstance(raw_paths, list) else []
        return bool(paths) and all(pathlib.Path(path).exists() for path in paths)

    def persist_forward_apply_signatures(
        self,
        forward_id: int,
        contract_signature: str,
        rendered_signature: str | None = None,
    ) -> None:
        if not hasattr(self, "_applied_state_lock"):
            self._applied_state_lock = threading.RLock()
        with self._applied_state_lock:
            applied = self.load_applied()
            previous = applied.get(str(forward_id), {})
            if not isinstance(previous, dict):
                return
            previous["contract_signature"] = contract_signature
            if rendered_signature is not None:
                previous["rendered_signature"] = rendered_signature
            previous["updated_at"] = int(time.time())
            applied[str(forward_id)] = previous
            self._write_applied_state_locked(applied)

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

    PULL_STATE_DEVICE_LIMIT_KEYS = ("error_code", "online_device_limit", "active_online_devices")

    def save_pull_state(self, payload: dict, drop_keys: tuple[str, ...] = ()) -> None:
        existing = self.load_pull_state()
        for key in drop_keys:
            existing.pop(key, None)
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
        payload, _headers, _endpoint = self.pull_client_configs(token)
        return payload.get("linux_profile_runtime", {}) if isinstance(payload, dict) else {}

    def bond_leg_snapshot_dir(self) -> pathlib.Path:
        configured = str(getattr(self, "config", {}).get("bond_leg_snapshot_dir", "") or "").strip()
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

    @classmethod
    def update_token_ledger(cls, update_state: dict) -> dict:
        """Return (and install) the bounded per-token ledger inside ``update_state``.

        Older agents and update helpers only recorded ``last_completed_request_token``.
        That completion is folded into the ledger so an old state file never makes
        an already-completed token look pending, and so a success written by an
        older helper (which does not know the ledger) is still observed.
        """
        raw = update_state.get("tokens")
        ledger: dict = {}
        if isinstance(raw, dict):
            for token, entry in raw.items():
                if isinstance(token, str) and token != "" and isinstance(entry, dict):
                    ledger[token] = dict(entry)
        update_state["tokens"] = ledger

        legacy_token = str(update_state.get("last_completed_request_token", "") or "")
        if legacy_token != "":
            legacy_at = cls.state_timestamp(update_state.get("last_completed_at"))
            entry = ledger.get(legacy_token)
            if entry is None:
                ledger[legacy_token] = {
                    "status": "success",
                    "attempts": 1,
                    "last_attempt_at": legacy_at,
                    "completed_at": legacy_at,
                    "source": "legacy",
                }
            elif str(entry.get("status", "") or "") != "success" and legacy_at > cls.state_timestamp(entry.get("completed_at")):
                # A helper that predates the ledger reported a newer completion.
                entry["status"] = "success"
                entry["completed_at"] = legacy_at
                entry["last_error"] = ""

        top_token = str(update_state.get("request_token", "") or "")
        top_status = str(update_state.get("status", "") or "")
        entry = ledger.get(top_token) if top_token else None
        if (
            isinstance(entry, dict)
            and top_status in {"running", "error"}
            and str(entry.get("status", "") or "") in {"scheduled", "running"}
            and cls.state_timestamp(update_state.get("last_attempt_at")) >= cls.state_timestamp(entry.get("last_attempt_at"))
        ):
            entry["status"] = top_status
            if top_status == "error":
                entry["last_error"] = str(update_state.get("last_error", "") or "")
        return ledger

    @classmethod
    def prune_update_ledger(cls, ledger: dict, keep: set[str] | None = None) -> None:
        keep = {token for token in (keep or set()) if token}
        if len(ledger) <= UPDATE_LEDGER_MAX_ENTRIES:
            return

        def recency(token: str) -> float:
            entry = ledger.get(token) or {}
            return max(
                cls.state_timestamp(entry.get("last_attempt_at")),
                cls.state_timestamp(entry.get("completed_at")),
                cls.state_timestamp(entry.get("converge_failed_at")),
            )

        evictable = sorted((token for token in ledger if token not in keep), key=recency)
        while len(ledger) > UPDATE_LEDGER_MAX_ENTRIES and evictable:
            ledger.pop(evictable.pop(0), None)

    @staticmethod
    def update_converge_backoff_seconds(failures: int) -> float:
        failures = max(1, int(failures))
        # Cap the exponent before shifting so a long-lived counter cannot overflow.
        exponent = min(failures - 1, 32)
        return float(min(UPDATE_CONVERGE_BACKOFF_BASE_SECONDS * (2 ** exponent), UPDATE_CONVERGE_BACKOFF_MAX_SECONDS))

    def update_convergence_summary(self) -> dict:
        """Compact, heartbeat-safe view of tokens whose update did not converge."""
        try:
            update_state = self.load_update_state()
        except Exception:  # noqa: BLE001
            return {"status": "unknown", "tokens": []}
        ledger = self.update_token_ledger(dict(update_state))
        now = time.time()
        failed = []
        for token, entry in ledger.items():
            if str(entry.get("status", "") or "") != "converge_failed":
                continue
            backoff_until = self.state_timestamp(entry.get("backoff_until"))
            failed.append(
                {
                    "token": token[:512],
                    "converge_failures": int(entry.get("converge_failures", 0) or 0),
                    "attempts": int(entry.get("attempts", 0) or 0),
                    "backoff_until": backoff_until,
                    "backoff_remaining_seconds": max(0.0, round(backoff_until - now, 1)),
                    "completed_at": self.state_timestamp(entry.get("completed_at")),
                }
            )
        failed.sort(key=lambda item: item["backoff_until"], reverse=True)
        return {"status": "converge_failed" if failed else "ok", "tokens": failed[:4]}

    def should_retry_update(self, update_state: dict, request_token: str, now: float) -> bool:
        ledger = update_state.get("tokens")
        entry = ledger.get(request_token) if isinstance(ledger, dict) else None
        if isinstance(entry, dict):
            entry_status = str(entry.get("status", "") or "")
            if entry_status == "success":
                return False
            if entry_status == "converge_failed" and now < self.state_timestamp(entry.get("backoff_until")):
                return False
        elif str(update_state.get("last_completed_request_token", "") or "") == request_token:
            return False

        tracked_status = str(update_state.get("status", "") or "")
        last_attempt_at = self.state_timestamp(update_state.get("last_attempt_at"))

        if tracked_status == "running" and last_attempt_at > 0 and (now - last_attempt_at) < UPDATE_STALE_SECONDS:
            return False

        if last_attempt_at > 0 and (now - last_attempt_at) < UPDATE_RETRY_SECONDS:
            return False

        return True

    def renew_ultra_leases_once(self) -> None:
        """Refresh compatible active Ultra runtimes independently of config/diagnostic work."""
        snapshot = list(self.latest_forwards)
        now = time.time()
        schedule = getattr(self, "_ultra_renew_schedule", None)
        if schedule is None:
            schedule = self._ultra_renew_schedule = {}
        due = []
        for forward in snapshot:
            if str(forward.get("backend_mode", "")).lower() != "ultra":
                continue
            fid = int(forward.get("forward_id", 0) or 0)
            access = forward.get("ultra_access") or {}
            renewal = parse_rfc3339(access.get("renew_after")) or now
            if fid > 0 and str(forward.get("service_status", "active")) == "active" and now >= schedule.get(fid, renewal - 30):
                due.append(forward)
        if not due:
            return
        # Failure retries are bounded; never launch overlapping maintenance cycles.
        for forward in due:
            schedule[int(forward["forward_id"])] = now + 10
        backoff = getattr(self, "_renew_backoff", None)
        if backoff is None:
            backoff = self._renew_backoff = ControlPlaneBackoff()
        try:
            payload, _headers, master = self.pull_client_configs(self.config["agent_token"])
        except Exception as exc:  # noqa: BLE001
            self._schedule_renewal_backoff(schedule, due, now, backoff, exc)
            raise
        fresh = {int(f.get("forward_id", 0) or 0): f for f in payload.get("forwards", []) if isinstance(f, dict)}
        compatible = []
        for old in due:
            fid = int(old["forward_id"])
            new = fresh.get(fid)
            if not new or str(new.get("service_status", "active")) != "active" or str(new.get("backend_mode", "")).lower() != "ultra":
                continue  # Normal authoritative reconciliation handles removal/revocation.
            if self.forward_contract_signature(old) != self.forward_contract_signature(new):
                continue  # Never hot-apply a changed target/transport/static contract.
            compatible.append(new)
        if not compatible:
            return
        client = payload.get("client") or {}
        try:
            resolved = resolve_ultra_leases(
                master, self.config["agent_token"], self.device_id() or str(client.get("current_device_id", "")), compatible,
                selection_mode=self.ultra_selection_mode(),
                fallback_endpoints=control_plane_endpoints(self.config),
                state_path=getattr(self, "endpoint_state_path", None),
                selector=getattr(self, "endpoint_selector", None),
                lease_key=self.lease_device_key(),
            )
        except Exception as exc:  # noqa: BLE001
            self._schedule_renewal_backoff(schedule, due, now, backoff, exc)
            raise
        backoff.reset()
        for forward in resolved:
            fid = int(forward["forward_id"])
            lock = self._forward_lock(fid)
            if not lock.acquire(blocking=False):
                continue
            try:
                # A concurrent authoritative sync may have removed/changed this forward.
                current = next((f for f in self.latest_forwards if int(f.get("forward_id", 0) or 0) == fid), None)
                if current is None or self.forward_contract_signature(current) != self.forward_contract_signature(forward):
                    continue
                if not self.systemd_is_active(forward_runtime_service_name(forward)):
                    continue
                leases = ultra_lease_candidates(forward)
                if leases and self.send_ultra_leases(fid, leases):
                    renewal = parse_rfc3339((forward.get("ultra_access") or {}).get("renew_after")) or time.time() + 40
                    schedule[fid] = max(time.time() + 10, renewal - 30)
                    logging.info("forward %s independent Ultra lease renewal delivered", fid)
            finally:
                lock.release()

    @staticmethod
    def _schedule_renewal_backoff(
        schedule: dict,
        due: list[dict],
        now: float,
        backoff: "ControlPlaneBackoff",
        error: BaseException,
    ) -> None:
        """Space renewal retries out while the control plane is unreachable.

        Business (4xx) answers keep the historical 10 s retry; network and 5xx
        failures back off exponentially with full jitter (5 s -> 60 s).
        """
        if isinstance(error, DeviceLimitReachedError):
            # Refused, not unreachable: poll at the steady device-limit cadence.
            for forward in due:
                schedule[int(forward["forward_id"])] = now + DEVICE_LIMIT_RETRY_SECONDS
            return
        if not is_transient_control_plane_error(error):
            return
        delay = backoff.next_delay()
        for forward in due:
            schedule[int(forward["forward_id"])] = now + delay

    def _ultra_renewal_loop(self, stop: threading.Event, interval_seconds: float = 5.0) -> None:
        while not stop.wait(interval_seconds):
            try:
                self.renew_ultra_leases_once()
            except Exception as exc:  # noqa: BLE001
                logging.warning("independent Ultra renewal failed (%s); retrying", type(exc).__name__)

    def _systemd_watchdog_loop(self, stop: threading.Event, interval_seconds: float = 20.0) -> None:
        # systemd's WatchdogSec is there to catch a wedged process, not a busy
        # one. Pinging only from the main loop meant a single slow sync (a lease
        # batch may legitimately spend up to 70 s on the runtime control socket)
        # starved the ping and systemd SIGABRT'd a perfectly healthy agent: 158
        # times in 48 h on the canary. Ping from its own thread instead.
        while not stop.wait(interval_seconds):
            self._systemd_watchdog_notify()

    def run(self) -> None:
        stop = threading.Event()
        renewal = threading.Thread(target=self._ultra_renewal_loop, args=(stop,), name="ultra-renewal", daemon=True)
        renewal.start()
        watchdog = threading.Thread(
            target=self._systemd_watchdog_loop, args=(stop,), name="systemd-watchdog", daemon=True
        )
        watchdog.start()
        try:
            next_sync = 0.0
            next_health = 0.0
            while True:
                now = time.time()
                if now >= next_sync:
                    next_sync = now + self.sync_with_backoff(now)

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

                self._systemd_watchdog_notify()
                time.sleep(5)
        finally:
            stop.set()
            renewal.join(timeout=1.0)

    def sync_with_backoff(self, now: float) -> float:
        """Run one sync and return the delay before the next one.

        Network and 5xx failures back off exponentially with full jitter
        (5 s -> 60 s, reset on success) so a fleet does not stampede a panel
        that is coming back; other failures keep the normal poll cadence.
        """
        backoff = getattr(self, "_sync_backoff", None)
        if backoff is None:
            backoff = self._sync_backoff = ControlPlaneBackoff()
        try:
            self.sync_once()
        except DeviceLimitReachedError as exc:
            # The panel is reachable and refuses this device until a slot
            # frees; keep the applied forwards and poll at a steady cadence.
            backoff.reset()
            self.save_pull_state(
                {
                    "status": "device_limit_reached",
                    "last_attempt_at": now,
                    "last_error": exc.user_message(),
                    "error_code": DEVICE_LIMIT_ERROR_CODE,
                    "online_device_limit": exc.online_device_limit,
                    "active_online_devices": exc.active_online_devices,
                    "agent_version": AGENT_VERSION,
                }
            )
            logging.warning("%s", exc.user_message(DEVICE_LIMIT_RETRY_SECONDS))
            return DEVICE_LIMIT_RETRY_SECONDS
        except Exception as exc:  # noqa: BLE001
            self.save_pull_state(
                {
                    "status": "error",
                    "last_attempt_at": now,
                    "last_error": str(exc),
                    "agent_version": AGENT_VERSION,
                },
                drop_keys=self.PULL_STATE_DEVICE_LIMIT_KEYS,
            )
            if is_transient_control_plane_error(exc):
                delay = backoff.next_delay()
                logging.warning(
                    "sync failed (control plane unavailable, attempt %s); retrying in %.1fs: %s",
                    backoff.failures,
                    delay,
                    exc,
                )
                return delay
            logging.exception("sync failed: %s", exc)
        else:
            backoff.reset()
        base_delay = int(self.config.get("poll_interval_seconds", 30) or 30)
        stable_key = str(self.config.get("client_id", "") or self.config.get("agent_token", "") or "client")
        return ultra_config_poll_delay(
            self.latest_forwards,
            now=now,
            base_delay=base_delay,
            stable_key=stable_key,
        )

    def run_cfwg_latency_watchdog(self, forwards: list[dict]) -> None:
        watchdog_config = self.config.get("cfwg_latency_watchdog", {})
        if not isinstance(watchdog_config, dict):
            watchdog_config = {}
        if not bool(watchdog_config.get("enabled", False)):
            return
        try:
            from cfwg_latency_watchdog import CfwgLatencyWatchdog
        except Exception:
            module_path = helper_artifact_path("cfwg_latency_watchdog.py")
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
        self._cfwg_latency_watchdog.tick(forwards)

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
            self.write_applied_state(applied)
        return force_ids
    def sync_once(self) -> None:
        token = self.config["agent_token"]
        payload, response_headers, master = self.pull_client_configs(token)
        raw_forwards = payload.get("forwards") if isinstance(payload, dict) else None
        if not isinstance(raw_forwards, list) or any(not isinstance(forward, dict) for forward in raw_forwards):
            raise ControlPlaneResponseError("invalid client config snapshot")
        pulled_forwards = list(raw_forwards)
        snapshot_revision = str(payload.get("config_revision", "") or "") if isinstance(payload, dict) else ""
        pulled_forward_ids = {int(forward.get("forward_id", 0) or 0) for forward in pulled_forwards if int(forward.get("forward_id", 0) or 0) > 0}
        for forward_id in pulled_forward_ids:
            self.missing_forward_observations.pop(forward_id, None)
        unsupported_forward_ids = []
        for forward in pulled_forwards:
            if unsupported_client_backend_mode(forward) == "":
                continue
            try:
                forward_id = int(forward.get("forward_id", 0) or 0)
            except (TypeError, ValueError):
                continue
            if forward_id > 0:
                unsupported_forward_ids.append(forward_id)
        self.latest_forwards = [
            forward
            for forward in pulled_forwards
            if unsupported_client_backend_mode(forward) == ""
        ]
        client_meta = payload.get("client", {})
        # The stable persisted id; the panel's echo of its derived (legacy) id
        # is only a fallback when the id file cannot be written.
        device_id = self.device_id() or (
            str(client_meta.get("current_device_id", "") or "") if isinstance(client_meta, dict) else ""
        )
        lease_resolution_failed = False
        try:
            runtime_forwards = resolve_ultra_leases(
                master,
                token,
                device_id,
                self.latest_forwards,
                selection_mode=self.ultra_selection_mode(),
                fallback_endpoints=control_plane_endpoints(self.config),
                state_path=getattr(self, "endpoint_state_path", None),
                selector=getattr(self, "endpoint_selector", None),
                lease_key=self.lease_device_key(),
            )
        except Exception as exc:  # noqa: BLE001
            # Keep an already-running Ultra process on its last in-memory lease
            # through transient control-plane failures. A successful response
            # with a missing lease still fails closed below.
            logging.warning("ultra lease resolution failed; preserving running runtimes for retry: %s", exc)
            lease_resolution_failed = True
            runtime_forwards = self.latest_forwards
        runtime_by_id = {
            int(forward.get("forward_id", 0) or 0): forward
            for forward in runtime_forwards
            if isinstance(forward, dict)
        }
        applied_forward_ids: list[int] = []
        noop_forward_ids: list[int] = []
        skipped_invalid_forward_ids: list[int] = []
        inactive_forward_ids: list[int] = []
        try:
            self.apply_management_state(client_meta)
        except Exception as exc:  # noqa: BLE001
            logging.exception("failed to apply client management state: %s", exc)
        token = self.config["agent_token"]
        desired_ids = set()
        force_runtime_refresh_ids = self.consume_local_refresh_requests()
        apply_executor = concurrent.futures.ThreadPoolExecutor(max_workers=4, thread_name_prefix="forward-apply")
        apply_futures: dict[concurrent.futures.Future, tuple[int, str, str, int]] = {}
        for forward_id in unsupported_forward_ids:
            self.log_invalid_forward(forward_id, "stopping unsupported backend_mode=cf_mode")
            self.stop_forward(forward_id, delete_files=True)
        for forward in self.latest_forwards:
            forward_id = int(forward["forward_id"])
            runtime_forward = runtime_by_id.get(forward_id, forward)
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
            if str(forward.get("backend_mode", "") or "").strip().lower() == "ultra" and not ultra_lease_candidates(runtime_forward):
                previous = self.load_applied().get(str(forward_id), {})
                refresh_failed = lease_resolution_failed or runtime_forward.get("_ultra_lease_resolution_failed") is True
                definition_present = self.applied_forward_definition_is_present(previous, forward_id)
                runtime_present = self.applied_forward_runtime_is_present(previous) if definition_present else False
                if definition_present:
                    reason = "refresh failed" if refresh_failed else "lease grant unavailable"
                    logging.warning(
                        "forward %s Ultra %s; preserving last known-good runtime (runtime_present=%s)",
                        forward_id,
                        reason,
                        runtime_present,
                    )
                    desired_ids.add(forward_id)
                    continue
                skipped_invalid_forward_ids.append(forward_id)
                self.log_invalid_forward(forward_id, "ultra lease missing or rejected by panel")
                self.stop_forward(forward_id, delete_files=True)
                continue
            usable_endpoints = self.usable_endpoints(validation_forward)
            if usable_endpoints == [] and forward_requires_panel_endpoints(validation_forward, mode):
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
                contract_signature = self.effective_forward_contract_signature(runtime_forward)
                force_runtime_restart = self.should_force_runtime_restart(
                    runtime_forward,
                    force_runtime_refresh_ids,
                )
                ultra_access_refresh = (
                    str(runtime_forward.get("backend_mode", "") or "").strip().lower() == "ultra"
                    and bool(ultra_lease_candidates(runtime_forward))
                )
                if not force_runtime_restart and not ultra_access_refresh and self.forward_apply_is_noop(forward_id, contract_signature):
                    noop_forward_ids.append(forward_id)
                    applied_forward_ids.append(forward_id)
                    logging.debug("forward %s unchanged; skipping no-op runtime apply", forward_id)
                    continue
                future = apply_executor.submit(
                    self._apply_forward_task,
                    runtime_forward,
                    contract_signature,
                    force_runtime_restart,
                )
                apply_futures[future] = (
                    forward_id,
                    mode,
                    str(forward.get("transport", "tcp+udp")),
                    len([endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]),
                )
                applied_forward_ids.append(forward_id)
            except Exception as exc:  # noqa: BLE001
                logging.exception(
                    "failed to queue forward %s mode=%s transport=%s endpoints=%s: %s",
                    forward_id,
                    mode,
                    str(forward.get("transport", "tcp+udp")),
                    len([endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]),
                    exc,
                )
                continue

        try:
            for future in concurrent.futures.as_completed(apply_futures):
                forward_id, mode, transport, endpoint_count = apply_futures[future]
                try:
                    future.result()
                except Exception as exc:  # noqa: BLE001
                    logging.exception(
                        "failed to apply forward %s mode=%s transport=%s endpoints=%s: %s",
                        forward_id,
                        mode,
                        transport,
                        endpoint_count,
                        exc,
                    )
        finally:
            apply_executor.shutdown(wait=True, cancel_futures=False)

        applied = self.load_applied()
        for forward_key in list(applied.keys()):
            try:
                forward_id = int(forward_key)
            except (TypeError, ValueError):
                continue
            if forward_id not in desired_ids:
                previous = applied.get(str(forward_id), {})
                if self.preserve_missing_ultra_forward(forward_id, previous, snapshot_revision):
                    desired_ids.add(forward_id)
                    continue
                self.stop_forward(forward_id, delete_files=True)
                applied.pop(str(forward_id), None)

        self.write_applied_state(applied)
        self.reconcile_global_mptcp_state(self.latest_forwards)
        self.schedule_debug_log_upload(master, token, client_meta)
        self.run_diagnostics_if_requested(master, token, client_meta, self.latest_forwards)
        usage_totals = self.collect_usage_totals()
        wireguard_public_key = self.ensure_wireguard_identity()
        local_clock = self.time_sync_state()
        helper_hashes = self.helper_artifact_hashes()
        # Preserve the historical endpoint shape for source-contract checks: f"{master}/api/client/heartbeat"
        ultra_status = collect_ultra_status()
        heartbeat_response = self.control_plane_request(
            "POST",
            "/api/client/heartbeat",
            token,
            {
                "agent_version": AGENT_VERSION,
                "agent_architecture": local_runtime_architecture(),
                "runtime_binary_mode": str(self.config.get("runtime_binary_mode", "python") or "python"),
                "usage_bytes_in": usage_totals["bytes_in"],
                "usage_bytes_out": usage_totals["bytes_out"],
                "ultra_usage": usage_totals.get("ultra_usage", []),
                "supports_mptcp": self.running_kernel_supports_mptcp(),
                "mptcp_capability": self.mptcp_capability(),
                "kernel_release": platform.release(),
                "installer_version": str(self.config.get("installer_version", "") or ""),
                "installer_sha256": str(self.config.get("installer_sha256", "") or ""),
                # Hashes of the installed helper bytes (next to the frozen
                # executable in binary mode), never the extracted bundle.
                "install_forward_runtime_sha256": helper_hashes["install_forward_runtime_sha256"],
                "mptcp_client_sha256": helper_hashes["mptcp_client_sha256"],
                "wstunnel_sha256": helper_hashes["wstunnel_sha256"],
                "wstunnel_version": str(self.config.get("wstunnel_version", "") or ""),
                "cfwg_watchdog_sha256": helper_hashes["cfwg_watchdog_sha256"],
                "agent_update_convergence": self.update_convergence_summary(),
                "wireguard_public_key": wireguard_public_key,
                "wireguard_listen_port": self.wireguard_listen_port(),
                "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
                "clock_sync": local_clock,
                "bond_leg_reputation": {
                    "forwards": self.load_bond_leg_reputation_snapshots(),
                },
                "ultra_status": ultra_status,
                "ultra_policy_states": {
                    str(entry["forward_id"]): entry["policy_state"]
                    for entry in ultra_status
                    if isinstance(entry, dict) and entry.get("policy_state")
                },
                "ultra_candidate_feedback": collect_ultra_candidate_feedback(ultra_status),
            },
        )
        self.apply_ultra_actions(heartbeat_response)
        self.commit_usage_state(usage_totals)
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
                "unsupported_forward_ids": unsupported_forward_ids,
                "agent_update_request_token": str(client_meta.get("agent_update_request_token", "") or ""),
                "diag_request_token": str(client_meta.get("diag_request_token", "") or ""),
                "clock": self.panel_clock_state_from_headers(response_headers),
            },
            drop_keys=self.PULL_STATE_DEVICE_LIMIT_KEYS,
        )

    def apply_ultra_actions(self, response: object) -> None:
        if not isinstance(response, dict):
            return
        actions = response.get("ultra_actions", [])
        if not isinstance(actions, list):
            return
        policies = {
            int(forward.get("forward_id", 0) or 0): forward
            for forward in self.latest_forwards[:64]
            if isinstance(forward, dict)
            and str(forward.get("backend_mode", "") or "").strip().lower() == "ultra"
        }
        for raw_action in actions[:32]:
            if not isinstance(raw_action, dict) or str(raw_action.get("action", "")) != "stop":
                continue
            try:
                forward_id = int(raw_action.get("forward_id", 0) or 0)
            except (TypeError, ValueError):
                continue
            if forward_id <= 0 or forward_id not in policies:
                continue
            generation = str(raw_action.get("policy_generation", "") or "").strip().lower()
            access = policies[forward_id].get("ultra_access")
            expected_generation = str(access.get("policy_generation", "") or "").strip().lower() if isinstance(access, dict) else ""
            if re.fullmatch(r"[a-f0-9]{64}", generation) is None or generation != expected_generation:
                continue
            self.log_invalid_forward(forward_id, "Panel requested immediate Ultra stop")
            self.stop_forward(forward_id, delete_files=True)

    def commit_usage_state(self, usage_totals: object) -> None:
        if not isinstance(usage_totals, dict):
            return
        state = usage_totals.get("_usage_state")
        if not isinstance(state, dict):
            return
        self.usage_state_path.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")

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
        authoritative_config = client_meta.get("agent_config_json")
        if isinstance(authoritative_config, dict):
            authoritative_config = dict(authoritative_config)
            authoritative_config.setdefault("udp_uot_sidepath", None)
        else:
            authoritative_config = {"udp_uot_sidepath": None}
        self.apply_config_patch(authoritative_config)
        # Root-local, time-bounded canary pin. Do not consume/acknowledge a
        # pending panel update while download mirrors are being reconciled.
        # Not panel-configurable; expired/invalid/unbounded holds fail open.
        try:
            hold_remaining = float(os.environ.get("LEIKWAN_UPDATE_HOLD_UNTIL", "0")) - time.time()
        except ValueError:
            hold_remaining = 0
        if 0 < hold_remaining <= 86400:
            return
        explicit_request_token = str(client_meta.get("agent_update_request_token", "") or "")
        auto_request_token = self.auto_update_request_token(client_meta)
        update_state = self.load_update_state()
        ledger = self.update_token_ledger(update_state)
        keep_tokens = {explicit_request_token, auto_request_token}
        state_dirty = False

        if explicit_request_token != "":
            explicit_entry = ledger.get(explicit_request_token)
            explicit_status = str((explicit_entry or {}).get("status", "") or "")
            if explicit_status in {"success", "converge_failed"}:
                # The panel never clears a manual token. Once it completed it
                # stays completed, whatever other tokens ran afterwards.
                applied = self.load_applied()
                if applied.get("_last_agent_update_request_token") != explicit_request_token:
                    applied["_last_agent_update_request_token"] = explicit_request_token
                    self.write_applied_state(applied)
                explicit_request_token = ""
            elif not self.management_requires_local_update(client_meta):
                now = time.time()
                entry = dict(explicit_entry or {})
                entry.update(
                    {
                        "status": "success",
                        "last_attempt_at": now,
                        "completed_at": now,
                        "attempts": int(entry.get("attempts", 0) or 0),
                        "installed_version": AGENT_VERSION,
                        "last_error": "",
                        "note": "already_current",
                    }
                )
                ledger[explicit_request_token] = entry
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
                self.prune_update_ledger(ledger, keep_tokens)
                self.save_update_state(update_state)
                applied = self.load_applied()
                applied["_last_agent_update_request_token"] = explicit_request_token
                self.write_applied_state(applied)
                explicit_request_token = ""

        if auto_request_token != "":
            auto_entry = ledger.get(auto_request_token)
            if isinstance(auto_entry, dict) and str(auto_entry.get("status", "") or "") == "success":
                # The updater reported success for exactly this mismatch set,
                # yet the same mismatch is computed again: the update did not
                # converge. Relaunching it every retry window is what restarted
                # agents every few minutes, so back off exponentially instead.
                now = time.time()
                failures = int(auto_entry.get("converge_failures", 0) or 0) + 1
                backoff = self.update_converge_backoff_seconds(failures)
                auto_entry.update(
                    {
                        "status": "converge_failed",
                        "converge_failures": failures,
                        "converge_failed_at": now,
                        "backoff_until": now + backoff,
                    }
                )
                log = logging.info
                if not bool(auto_entry.get("converge_warned", False)):
                    auto_entry["converge_warned"] = True
                    log = logging.warning
                log(
                    "agent update did not converge after a successful install token=%s failures=%s; "
                    "next attempt in %ss",
                    auto_request_token,
                    failures,
                    int(backoff),
                )
                state_dirty = True

        for request_token in [token for token in [explicit_request_token, auto_request_token] if token]:
            now = time.time()
            if not self.should_retry_update(update_state, request_token, now):
                continue

            entry = ledger.setdefault(request_token, {})
            entry.update(
                {
                    "status": "scheduled",
                    "attempts": int(entry.get("attempts", 0) or 0) + 1,
                    "last_attempt_at": now,
                    "last_error": "",
                }
            )
            update_state.update(
                {
                    "request_token": request_token,
                    "status": "scheduled",
                    "last_attempt_at": now,
                    "last_error": "",
                }
            )
            self.prune_update_ledger(ledger, keep_tokens)
            self.save_update_state(update_state)
            try:
                self.request_self_update(request_token, self.active_control_plane_endpoint)
            except Exception as exc:  # noqa: BLE001
                entry["status"] = "error"
                entry["last_error"] = str(exc)
                update_state["status"] = "error"
                update_state["last_error"] = str(exc)
                update_state["last_failed_at"] = now
                self.save_update_state(update_state)
                raise
            return

        if state_dirty:
            self.prune_update_ledger(ledger, keep_tokens)
            self.save_update_state(update_state)

    def auto_update_request_token(self, client_meta: dict) -> str:
        if str(self.config.get("disable_auto_update", "") or "").lower() in {"1", "true", "yes", "on"}:
            return ""
        desired_agent_version = str(client_meta.get("desired_agent_version", "") or "").strip()
        desired_agent_sha256 = str(client_meta.get("desired_agent_sha256", "") or "").strip().lower()
        desired_installer_version = str(client_meta.get("desired_installer_version", "") or "").strip()
        desired_installer_sha256 = str(client_meta.get("desired_installer_sha256", "") or "").strip().lower()
        desired_install_forward_runtime_sha256 = str(
            client_meta.get("desired_install_forward_runtime_sha256", "") or ""
        ).strip().lower()
        desired_ultra_runtime_sha256 = str(
            client_meta.get("desired_ultra_runtime_sha256", "") or ""
        ).strip().lower()
        desired_bond_sha256 = str(client_meta.get("desired_bond_sha256", "") or "").strip().lower()
        desired_mptcp_runtime_sha256 = str(client_meta.get("desired_mptcp_runtime_sha256", "") or "").strip().lower()
        desired_cfwg_watchdog_sha256 = str(client_meta.get("desired_cfwg_watchdog_sha256", "") or "").strip().lower()
        desired_wstunnel_sha256 = str(client_meta.get("desired_wstunnel_sha256", "") or "").strip().lower()
        mismatches: list[str] = []

        if desired_agent_version and desired_agent_version != AGENT_VERSION:
            mismatches.append(f"agent={desired_agent_version}")

        if desired_agent_sha256:
            local_agent_sha256 = agent_artifact_sha256().lower()
            if local_agent_sha256 != desired_agent_sha256:
                mismatches.append(f"agent_sha256={desired_agent_sha256}")

        local_installer_version = str(self.config.get("installer_version", "") or "").strip()
        if desired_installer_version and local_installer_version != desired_installer_version:
            mismatches.append(f"installer={desired_installer_version}")

        local_installer_sha256 = str(self.config.get("installer_sha256", "") or "").strip().lower()
        if desired_installer_sha256 and local_installer_sha256 != desired_installer_sha256:
            mismatches.append(f"installer_sha256={desired_installer_sha256}")

        if desired_install_forward_runtime_sha256:
            local_install_forward_runtime_sha256 = file_sha256(self.install_forward_runtime_path()).lower()
            if local_install_forward_runtime_sha256 != desired_install_forward_runtime_sha256:
                mismatches.append(f"install_forward_runtime={desired_install_forward_runtime_sha256}")

        if desired_ultra_runtime_sha256:
            local_ultra_runtime_sha256 = file_sha256(self.ultra_binary_path()).lower()
            if local_ultra_runtime_sha256 != desired_ultra_runtime_sha256:
                mismatches.append(f"ultra_runtime={desired_ultra_runtime_sha256}")

        if desired_bond_sha256:
            local_bond_sha256 = file_sha256(self.bond_binary_path()).lower()
            if local_bond_sha256 != desired_bond_sha256:
                mismatches.append(f"bond={desired_bond_sha256}")

        if desired_mptcp_runtime_sha256:
            local_mptcp_runtime_sha256 = file_sha256(self.mptcp_client_path()).lower()
            if local_mptcp_runtime_sha256 != desired_mptcp_runtime_sha256:
                mismatches.append(f"mptcp_runtime={desired_mptcp_runtime_sha256}")

        if desired_cfwg_watchdog_sha256:
            local_cfwg_watchdog_path = helper_artifact_path("cfwg_latency_watchdog.py")
            if local_cfwg_watchdog_path.is_file():
                local_cfwg_watchdog_sha256 = file_sha256(local_cfwg_watchdog_path).lower()
            else:
                local_cfwg_watchdog_sha256 = ""
            if local_cfwg_watchdog_sha256 != desired_cfwg_watchdog_sha256:
                mismatches.append(f"cfwg_watchdog={desired_cfwg_watchdog_sha256}")

        if desired_wstunnel_sha256:
            local_wstunnel_sha256 = file_sha256(self.wstunnel_binary_path()).lower()
            if local_wstunnel_sha256 != desired_wstunnel_sha256:
                mismatches.append(f"wstunnel={desired_wstunnel_sha256}")

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
            "panel_mirror_urls",
            "disable_auto_update",
            "poll_interval_seconds",
            "health_check_interval_seconds",
            "log_level",
            "state_dir",
            "udp_uot_sidepath",
            "cfwg_latency_watchdog",
            "runtime_refresh",
            "cfwg_edge_pins",
            "ultra_selection_mode",
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
            if getattr(self, "endpoint_state_path", None) is not None and self.endpoint_state_path.exists():
                target_endpoint_state = new_state_dir / "control-plane-endpoint.json"
                if not target_endpoint_state.exists():
                    target_endpoint_state.write_text(self.endpoint_state_path.read_text(encoding="utf-8"), encoding="utf-8")
            # Keep the panel device identity: a new id would occupy another
            # online-device slot.
            source_device_id = self.state_dir / DEVICE_ID_FILENAME
            target_device_id = new_state_dir / DEVICE_ID_FILENAME
            if source_device_id.exists() and not target_device_id.exists():
                target_device_id.write_text(source_device_id.read_text(encoding="utf-8"), encoding="utf-8")
                with contextlib.suppress(OSError):
                    os.chmod(target_device_id, 0o600)
            # The panel pins the lease key to the device id: move it as well.
            source_lease_key = self.state_dir / LEASE_KEY_FILENAME
            target_lease_key = new_state_dir / LEASE_KEY_FILENAME
            if source_lease_key.exists() and not target_lease_key.exists():
                with contextlib.suppress(OSError):
                    lease_key_bytes = source_lease_key.read_bytes()
                    fd = os.open(str(target_lease_key), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                    with os.fdopen(fd, "wb") as stream:
                        stream.write(lease_key_bytes)
            self._lease_key_cache = None
            self.state_dir = new_state_dir
            self.applied_path = new_state_dir / "applied.json"
            self.pull_state_path = new_state_dir / "config_pull_state.json"
            self.usage_state_path = new_state_dir / "usage_state.json"
            self.endpoint_state_path = new_state_dir / "control-plane-endpoint.json"
            self.endpoint_selector = ControlPlaneEndpointState(self.endpoint_state_path)

        logging.info("applied client config patch keys=%s", ",".join(sorted(set(patch.keys()) & allowed_keys)))

    def request_self_update(self, request_token: str, artifact_base: str | None = None) -> None:
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
                    # A failed transient update unit stays in systemd's failed
                    # table forever without --collect. The update is retried
                    # every UPDATE_RETRY_SECONDS while the token is unchanged, so
                    # a struggling release accumulated one failed unit per retry
                    # (440 on the canary host) until the table was unusable.
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
                    *( ["--artifact-base", artifact_base] if artifact_base else [] ),
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
                ] + (["--artifact-base", artifact_base] if artifact_base else []),
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                stdin=subprocess.DEVNULL,
            )
        logging.info("requested client self-update token=%s", request_token)

    def collect_usage_totals(self) -> dict:
        result = None
        if shutil.which("nft"):
            result = subprocess.run("nft -a list table inet leikwan_meter", shell=True, text=True, capture_output=True, check=False)

        usage_state: dict[str, int] = {}
        if self.usage_state_path.exists():
            try:
                loaded = json.loads(self.usage_state_path.read_text(encoding="utf-8"))
                if isinstance(loaded, dict):
                    usage_state = {str(key): int(value) for key, value in loaded.items()}
            except (json.JSONDecodeError, TypeError, ValueError):
                usage_state = {}

        current_bytes: dict[str, int] = {}
        for line in (result.stdout.splitlines() if result is not None and result.returncode == 0 else []):
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

        ultra_usage: list[dict] = []
        for status in collect_ultra_status():
            forward_id = int(status.get("forward_id", 0) or 0)
            generation = str(status.get("policy_generation", "") or "").strip().lower()
            if forward_id <= 0 or re.fullmatch(r"[a-f0-9]{64}", generation) is None:
                continue
            current_in = max(0, int(status.get("bytes_in", 0) or 0))
            current_out = max(0, int(status.get("bytes_out", 0) or 0))
            key_in = f"ultra:{forward_id}:in"
            key_out = f"ultra:{forward_id}:out"
            previous_in = int(usage_state.get(key_in, 0))
            previous_out = int(usage_state.get(key_out, 0))
            delta_in = current_in - previous_in if current_in >= previous_in else current_in
            delta_out = current_out - previous_out if current_out >= previous_out else current_out
            usage_state[key_in] = current_in
            usage_state[key_out] = current_out
            if delta_in == 0 and delta_out == 0:
                continue
            sequence_key = f"ultra:{forward_id}:sequence"
            sequence = int(usage_state.get(sequence_key, 0)) + 1
            usage_state[sequence_key] = sequence
            ultra_usage.append({
                "forward_id": forward_id,
                "policy_generation": generation,
                "sequence": sequence,
                "bytes_in": min(delta_in, 107374182400),
                "bytes_out": min(delta_out, 107374182400),
            })

        return {
            "bytes_in": totals["bytes_in"],
            "bytes_out": totals["bytes_out"],
            "ultra_usage": ultra_usage,
            "_usage_state": usage_state,
        }

    def apply_forward(self, forward: dict, contract_signature: str | None = None, force_runtime_restart: bool = False) -> None:
        forward_id = int(forward.get("forward_id", 0) or 0) if isinstance(forward, dict) else 0
        with self._forward_lock(forward_id):
            self._apply_forward_locked(forward, contract_signature, force_runtime_restart)

    def _apply_forward_task(
        self,
        forward: dict,
        contract_signature: str,
        force_runtime_restart: bool,
    ) -> None:
        self.apply_forward(
            forward,
            contract_signature=contract_signature,
            force_runtime_restart=force_runtime_restart,
        )

    @staticmethod
    def _capture_runtime_files(paths: set[str]) -> dict[str, tuple[bool, bytes, int]]:
        snapshot: dict[str, tuple[bool, bytes, int]] = {}
        for raw_path in sorted(paths):
            path = pathlib.Path(raw_path)
            try:
                if path.is_file() and not path.is_symlink():
                    snapshot[raw_path] = (True, path.read_bytes(), path.stat().st_mode & 0o777)
                else:
                    snapshot[raw_path] = (False, b"", 0)
            except OSError:
                snapshot[raw_path] = (False, b"", 0)
        return snapshot

    @staticmethod
    def _restore_runtime_file(path: pathlib.Path, content: bytes, mode: int) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".rollback", dir=str(path.parent))
        try:
            with os.fdopen(fd, "wb") as stream:
                stream.write(content)
                stream.flush()
                os.fchmod(stream.fileno(), mode or 0o600)
                os.fsync(stream.fileno())
            os.replace(temporary_name, path)
        finally:
            if os.path.exists(temporary_name):
                os.unlink(temporary_name)

    def _rollback_ultra_runtime_apply(
        self,
        forward_id: int,
        previous: dict,
        applied_before: dict,
        previous_runtime_present: bool,
        previous_paths: set[str],
        current_paths: set[str],
        previous_service_names: set[str],
        current_service_names: set[str],
        file_snapshot: dict[str, tuple[bool, bytes, int]],
        previous_leases: list[dict],
    ) -> None:
        """Restore the last known-good Ultra runtime after a failed handoff."""
        try:
            for service_name in sorted(current_service_names):
                subprocess.run(f"systemctl stop {service_name}.service", shell=True, check=False)
            for raw_path in sorted(current_paths - previous_paths):
                path = pathlib.Path(raw_path)
                try:
                    if path.exists() or path.is_symlink():
                        path.unlink()
                except OSError:
                    logging.warning("failed to remove rolled-back runtime path %s", path, exc_info=True)
            for raw_path, (exists, content, mode) in file_snapshot.items():
                path = pathlib.Path(raw_path)
                if exists:
                    self._restore_runtime_file(path, content, mode)
                elif path.exists() or path.is_symlink():
                    path.unlink()

            subprocess.run("systemctl daemon-reload", shell=True, check=False)
            if previous_runtime_present:
                for service_name in sorted(previous_service_names):
                    subprocess.run(f"systemctl enable {service_name}.service", shell=True, check=False)
                    subprocess.run(f"systemctl start {service_name}.service", shell=True, check=False)
                if previous_leases and not self.send_ultra_leases(forward_id, previous_leases):
                    logging.error("forward %s restored service but could not restore its previous Ultra lease", forward_id)
            else:
                for service_name in sorted(current_service_names):
                    subprocess.run(f"systemctl disable {service_name}.service", shell=True, check=False)

            applied = dict(applied_before)
            if previous:
                applied[str(forward_id)] = previous
            else:
                applied.pop(str(forward_id), None)
            self.write_applied_state(applied)
            logging.warning("forward %s Ultra runtime handoff rolled back after lease delivery failure", forward_id)
        except Exception:  # noqa: BLE001
            logging.exception("forward %s Ultra runtime rollback failed", forward_id)

    def _apply_forward_locked(self, forward: dict, contract_signature: str | None = None, force_runtime_restart: bool = False) -> None:
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
        ultra_runtime = str(render_forward.get("backend_mode", "") or "").strip().lower() == "ultra"
        previous_for_runtime_checks = previous
        if ultra_runtime and not previous_runtime_present:
            inferred_previous = self.inferred_ultra_runtime_entry(forward_id)
            inferred_paths = [pathlib.Path(path) for path in inferred_previous["managed_paths"]]
            if all(path.exists() for path in inferred_paths) and self.systemd_is_active(f"ultra-forward-{forward_id}"):
                previous_for_runtime_checks = dict(previous) if isinstance(previous, dict) else {}
                previous_for_runtime_checks.setdefault("units", inferred_previous["units"])
                previous_for_runtime_checks.setdefault("managed_paths", inferred_previous["managed_paths"])
                previous_for_runtime_checks.setdefault("runtime_mode", inferred_previous["runtime_mode"])
                previous_for_runtime_checks.setdefault("published_mode", inferred_previous["published_mode"])
                previous_runtime_present = True
                previous = previous_for_runtime_checks
                logging.info("forward %s recovered applied runtime metadata from live Ultra files", forward_id)
        previous_runtime_mode = self.normalize_mode(previous.get("runtime_mode") or previous.get("published_mode") or "") if isinstance(previous, dict) else ""
        live_mptcp_runtime_interfaces = self.mptcp_forward_runtime_interfaces(forward_id) if mode != "mptcp" else []
        cleanup_for_runtime_transition = previous_runtime_mode == "mptcp" and mode != "mptcp"
        cleanup_for_stale_non_mptcp_runtime = mode != "mptcp" and live_mptcp_runtime_interfaces != []
        rendered_unchanged = isinstance(previous, dict) and str(previous.get("rendered_signature", "") or "") == rendered_signature
        ultra_generation_only_refresh = (
            str(render_forward.get("backend_mode", "") or "").strip().lower() == "ultra"
            and previous_runtime_present
            and not rendered_unchanged
            and self.ultra_static_runtime_matches_disk(rendered, previous_for_runtime_checks, mode, transport, listen_port)
        )
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
        ultra_forced_restart = force_runtime_restart and str(render_forward.get("backend_mode", "") or "").strip().lower() == "ultra"
        if (rendered_unchanged or ultra_generation_only_refresh) and previous_runtime_present and not ultra_forced_restart:
            if str(render_forward.get("backend_mode", "") or "").strip().lower() == "ultra":
                leases = ultra_lease_candidates(render_forward)
                if not leases or not self.send_ultra_leases(forward_id, leases):
                    raise RuntimeError("ultra lease delivery failed")
                self.persist_forward_apply_signatures(forward_id, contract_signature, rendered_signature)
                logging.debug(
                    "forward %s static runtime unchanged%s; delivered fresh Ultra lease",
                    forward_id,
                    " (legacy signature migrated)" if ultra_generation_only_refresh and not rendered_unchanged else "",
                )
                return
            self.apply_reload_only_files(rendered)
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

        if ultra_runtime and not ultra_lease_candidates(render_forward):
            if previous_runtime_present or isinstance(previous, dict) and previous:
                logging.warning("forward %s Ultra lease unavailable; preserving current runtime before apply", forward_id)
                return
            raise RuntimeError("ultra lease delivery failed")

        previous_leases = self.cached_ultra_leases(forward_id) if ultra_runtime else []
        fresh_leases = ultra_lease_candidates(render_forward) if ultra_runtime else []
        if ultra_runtime and previous_runtime_present and not force_runtime_restart and not previous_leases:
            # The guard exists to avoid tearing down a *working* runtime when we
            # have no snapshotted lease to put back. previous_runtime_present is
            # only a metadata flag though, and it is also set when the runtime
            # identity is merely *inferred from files left on disk* -- including
            # a runtime that is already dead (process alive, no lease, stuck in
            # awaiting_policy, nothing bound). Deferring in that state is a
            # permanent dead end: the forward keeps its enabled status in the
            # panel while having no listener, and never recovers.
            #
            # So defer only when the runtime is genuinely still serving. If there
            # is no listener, apply normally -- which delivers the fresh lease
            # from render_forward and brings the forward back.
            listener_ok = self.ultra_runtime_is_serving(listen_port, transport)
            if listener_ok or not fresh_leases:
                logging.warning(
                    "forward %s Ultra static runtime change deferred because no previous lease snapshot is available",
                    forward_id,
                )
                return
            logging.warning(
                "forward %s Ultra static runtime change proceeding without a previous lease snapshot: "
                "no listener on port %s, so the runtime is not serving and the fresh lease will be applied",
                forward_id,
                listen_port,
            )

        applied_before = json.loads(json.dumps(applied, ensure_ascii=False))
        previous_paths = {
            str(path)
            for path in (previous.get("managed_paths", []) if isinstance(previous, dict) else [])
            if str(path).strip() != ""
        }
        current_paths = self.rendered_managed_paths(rendered)
        previous_service_names = {
            str(unit.get("service_name", "") or "")
            for unit in (previous.get("units", []) if isinstance(previous, dict) else [])
            if isinstance(unit, dict) and str(unit.get("service_name", "") or "").strip() != ""
        }
        current_service_names = {
            str(unit.get("service_name", "") or "")
            for unit in [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
            if str(unit.get("service_name", "") or "").strip() != ""
        }
        runtime_file_snapshot = self._capture_runtime_files(previous_paths | current_paths) if ultra_runtime else {}

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
                desired_content = str(file_entry.get("content", ""))
                file_changed = self.write_text_if_changed(
                    extra_path,
                    desired_content,
                    int(file_entry["mode"]) if "mode" in file_entry and file_entry.get("mode") is not None else None,
                )
                restart_on_change = file_entry.get("restart_on_change") is not False
                if file_changed and restart_on_change:
                    # A write is only a restart reason when the restart-relevant
                    # content actually differs. A bare policy_generation rotation
                    # rewrites the file and must not cycle the service; the new
                    # lease is delivered to the running runtime instead.
                    previous_content = WRITTEN_RUNTIME_CONTENT.get(str(extra_path))
                    if previous_content is None:
                        restart_relevant = True
                    else:
                        restart_relevant = self.restart_relevant_content(
                            service_name, previous_content
                        ) != self.restart_relevant_content(service_name, desired_content)
                    files_changed = files_changed or restart_relevant
                managed_paths.append(str(extra_path))

            service_active = self.systemd_is_active(service_name)
            restart_reasons = []
            if unit_changed:
                restart_reasons.append("unit_changed")
            if script_changed:
                restart_reasons.append("script_changed")
            if files_changed:
                restart_reasons.append("files_changed")
            if not service_active:
                restart_reasons.append("service_inactive")
            if runtime_reapply_needed:
                restart_reasons.append("forced_refresh")
            if restart_reasons:
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
            if restart_reasons:
                logging.info(
                    "forward %s service=%s runtime restart reasons=%s",
                    forward_id,
                    service_name,
                    ",".join(restart_reasons),
                )

        if str(render_forward.get("backend_mode", "") or "").strip().lower() == "ultra":
            leases = ultra_lease_candidates(render_forward)
            if not leases or not self.send_ultra_leases(forward_id, leases):
                self._rollback_ultra_runtime_apply(
                    forward_id,
                    previous if isinstance(previous, dict) else {},
                    applied_before,
                    previous_runtime_present,
                    previous_paths,
                    current_paths,
                    previous_service_names,
                    current_service_names,
                    runtime_file_snapshot,
                    previous_leases,
                )
                raise RuntimeError("ultra lease delivery failed")

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

        applied_entry = {
            "units": persisted_units,
            "managed_paths": sorted(current_managed_paths),
            "published_mode": self.normalize_mode(forward.get("mode", "single")),
            "runtime_mode": mode,
            "contract_signature": contract_signature,
            "rendered_signature": rendered_signature,
            "updated_at": int(time.time()),
        }
        self._save_applied_entry(forward_id, applied_entry)

    def ultra_runtime_is_serving(self, listen_port: int, transport: object) -> bool:
        """Whether an Ultra forward's runtime currently has a bound listener.

        Tells a runtime that is genuinely serving apart from one that only looks
        present because stale metadata was inferred from files left on disk. A
        dead runtime (process alive, no lease, awaiting_policy) has no listener,
        and deferring a runtime change for it strands the forward forever.
        """
        if listen_port <= 0:
            return False
        for protocol in self.transport_protocols(transport) or ["tcp"]:
            try:
                if self.local_listener_present(listen_port, protocol)[0]:
                    return True
            except Exception:
                continue
        return False

    def cached_ultra_leases(self, forward_id: int) -> list[dict]:
        leases = self.last_ultra_leases.get(int(forward_id), [])
        if not isinstance(leases, list):
            return []
        try:
            return json.loads(json.dumps(leases, ensure_ascii=False))
        except (TypeError, ValueError):
            return []

    def remember_ultra_leases(self, forward_id: int, leases: list[dict]) -> None:
        try:
            copied = json.loads(json.dumps(leases, ensure_ascii=False))
        except (TypeError, ValueError):
            return
        if isinstance(copied, list) and copied:
            self.last_ultra_leases[int(forward_id)] = copied

    def send_ultra_lease(self, forward_id: int, lease: dict) -> bool:
        return self.send_ultra_leases(forward_id, [lease])

    def send_ultra_leases(self, forward_id: int, leases: list[dict]) -> bool:
        if forward_id <= 0 or not isinstance(leases, list) or not 1 <= len(leases) <= 4:
            return False
        socket_path = pathlib.Path(f"/run/ultra/forward-{forward_id}.control.sock")
        wire_leases: list[dict] = []
        sensitive: list[tuple[dict, dict, dict]] = []
        try:
            for lease in leases:
                if not isinstance(lease, dict):
                    return False
                payload = json.loads(json.dumps(lease, ensure_ascii=False))
                payload["protocol"] = str(payload.get("protocol", "") or "")
                endpoint = payload.get("endpoint") if isinstance(payload.get("endpoint"), dict) else {}
                credential = payload.get("credential") if isinstance(payload.get("credential"), dict) else {}
                if not payload.get("policy_generation") or not payload.get("lease_id") or not endpoint or not credential:
                    return False
                wire_leases.append(payload)
                sensitive.append((payload, endpoint, credential))
            wire_payload: object = wire_leases[0] if len(wire_leases) == 1 else {"leases": wire_leases}
            encoded = json.dumps(wire_payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
            if not 1 <= len(encoded) <= 65536:
                return False
            frame = struct.pack(">I", len(encoded)) + encoded
            # Single external-UoT leases also verify TCP carrier + sidecar
            # HELLO before ACK. Keep one total verification budget regardless
            # of candidate count, still capped by the authorization expiry.
            timeout = 70.0
            expiries = [parse_rfc3339(lease.get("expires_at")) for lease in wire_leases]
            remaining = [expiry - time.time() - 5.0 for expiry in expiries if expiry is not None]
            budget = min([timeout, *remaining]) if remaining else timeout
            if budget <= 0:
                return False
            total_deadline = time.monotonic() + budget
            def remaining_timeout() -> float:
                left = total_deadline - time.monotonic()
                if left <= 0:
                    raise TimeoutError("Ultra lease delivery total deadline exceeded")
                return left
            for attempt in range(3):
                if time.monotonic() >= total_deadline:
                    break
                try:
                    deadline = min(total_deadline, time.monotonic() + 3.0)
                    while not socket_path.exists() and time.monotonic() < deadline:
                        time.sleep(0.1)
                    if not socket_path.exists():
                        raise OSError("control socket missing")
                    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                        connection.settimeout(min(3.0, remaining_timeout()))
                        connection.connect(str(socket_path))
                        connection.settimeout(remaining_timeout())
                        connection.sendall(frame)
                        connection.settimeout(remaining_timeout())
                        header = connection.recv(4)
                        if len(header) != 4:
                            raise OSError("control response header incomplete")
                        size = struct.unpack(">I", header)[0]
                        if not 1 <= size <= 65536:
                            raise OSError("control response size invalid")
                        response = b""
                        while len(response) < size:
                            connection.settimeout(remaining_timeout())
                            chunk = connection.recv(size - len(response))
                            if not chunk:
                                raise OSError("control response incomplete")
                            response += chunk
                        decoded = json.loads(response.decode("utf-8"))
                        if not isinstance(decoded, dict) or decoded.get("ok") is not True:
                            raise OSError(str(decoded.get("error", "lease rejected")) if isinstance(decoded, dict) else "lease rejected")
                        self.remember_ultra_leases(forward_id, leases)
                        delivered = getattr(self, "_ultra_lease_delivered_at", None)
                        if delivered is None:
                            delivered = self._ultra_lease_delivered_at = {}
                        delivered[int(forward_id)] = time.time()
                        return True
                except (OSError, json.JSONDecodeError, ValueError) as exc:
                    if attempt == 2 or time.monotonic() >= total_deadline:
                        logging.warning("Ultra lease delivery failed for forward %s: %s", forward_id, exc)
                        break
                    time.sleep(min((0.1, 0.3, 0.9)[attempt], max(0.0, total_deadline - time.monotonic())))
        finally:
            for payload, endpoint, credential in sensitive:
                payload.clear()
                endpoint.clear()
                credential.clear()
        return False

    def stop_forward(self, forward_id: int, delete_files: bool = False) -> None:
        applied = self.load_applied()
        previous = applied.get(str(forward_id), {})
        ultra_runtime = isinstance(previous, dict) and (
            str(previous.get("runtime_mode", "") or "").strip().lower() == "ultra"
            or any(
                str(unit.get("service_name", "") or "").startswith("ultra-forward-")
                for unit in (previous.get("units", []) if isinstance(previous.get("units", []), list) else [])
                if isinstance(unit, dict)
            )
        )
        units = previous.get("units", []) if isinstance(previous, dict) else []
        if not units:
            units = [
                {"service_name": f"leikwan-client-{forward_id}", "managed_paths": []},
                {"service_name": f"ultra-forward-{forward_id}", "managed_paths": []},
            ]

        for unit in units:
            service_name = str(unit.get("service_name", "") or "")
            if service_name == "":
                continue
            subprocess.run(f"systemctl stop {service_name}.service", shell=True, check=False)
            subprocess.run(f"systemctl disable {service_name}.service", shell=True, check=False)
            service_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
            if delete_files and service_path.exists():
                service_path.unlink()
            if delete_files or ultra_runtime:
                start_script_path = pathlib.Path(f"/usr/local/bin/leikwan-client-start-{forward_id}.sh")
                if start_script_path.exists():
                    start_script_path.unlink()

        self.delete_nft_rules(forward_id)
        for ephemeral_path in [
            pathlib.Path(f"/etc/ultra/forward-{forward_id}.json"),
            pathlib.Path(f"/run/ultra/forward-{forward_id}.control.sock"),
            pathlib.Path(f"/run/ultra/forward-{forward_id}.status.json"),
        ]:
            try:
                if ephemeral_path.exists() or ephemeral_path.is_symlink():
                    ephemeral_path.unlink()
            except OSError:
                pass
        if delete_files or ultra_runtime:
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

    def ultra_status_path(self, forward_id: int) -> pathlib.Path:
        root = pathlib.Path(getattr(self, "ultra_runtime_dir", ULTRA_RUNTIME_DIR))
        return root / f"forward-{int(forward_id)}.status.json"

    def read_ultra_runtime_status(self, forward_id: int) -> tuple[dict, float] | None:
        """Return (status, updated_at_epoch) of a forward's runtime status file.

        Only a regular, root-owned (or ``ultra_status_expected_uid``) file of
        bounded size is trusted, as in collect_ultra_status: an unprivileged
        writer must not be able to suppress health restarts.
        """
        path = self.ultra_status_path(forward_id)
        expected_uid = int(getattr(self, "ultra_status_expected_uid", 0))
        descriptor: int | None = None
        try:
            descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
            opened = os.fstat(descriptor)
            if not stat.S_ISREG(opened.st_mode) or opened.st_size > 64 * 1024:
                return None
            if hasattr(os, "getuid") and opened.st_uid != expected_uid:
                return None
            with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
                descriptor = None
                decoded = json.load(handle)
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            return None
        finally:
            if descriptor is not None:
                os.close(descriptor)
        if not isinstance(decoded, dict):
            return None
        forward_field = decoded.get("forward_id")
        if forward_field not in (None, "") and int(forward_field or 0) != int(forward_id):
            return None
        updated = opened.st_mtime
        reported = parse_rfc3339(decoded.get("updated_at"))
        if reported is not None:
            updated = max(updated, reported)
        return decoded, updated

    def ultra_runtime_idle_policy_state(self, forward_id: int) -> str:
        """Policy state when the runtime is alive but idle on purpose, else "".

        Idle means awaiting/lapsed: ``policy_state`` first, then the legacy
        ``error_code`` (``awaiting_policy``/``policy_lapsed``) of older runtimes.
        A status written before this agent's last lease delivery predates that
        lease and proves nothing, so it is ignored.
        """
        read = self.read_ultra_runtime_status(forward_id)
        if read is None:
            return ""
        status, updated_at = read
        delivered_at = (getattr(self, "_ultra_lease_delivered_at", None) or {}).get(int(forward_id))
        if delivered_at is not None and updated_at < delivered_at:
            return ""
        state = ultra_policy_state_from_status(status)
        return state if state in ULTRA_IDLE_POLICY_STATES else ""

    def valid_cached_ultra_leases(self, forward_id: int) -> list[dict]:
        """Cached leases that can still be delivered (enough validity left)."""
        leases = self.cached_ultra_leases(forward_id)
        if not leases:
            return []
        now = time.time()
        for lease in leases:
            expires_at = parse_rfc3339(lease.get("expires_at")) if isinstance(lease, dict) else None
            if expires_at is None or expires_at - now < ULTRA_REDELIVERY_MIN_REMAINING_SECONDS:
                return []
        return leases

    def redeliver_cached_ultra_lease(self, forward_id: int) -> bool | None:
        """Hand a still-valid cached lease to an idle runtime.

        A runtime that systemd restarted on its own comes back ``awaiting`` and
        would otherwise wait for the next renewal window. Returns None when
        there is nothing to deliver (or it was tried too recently), else the
        delivery result.
        """
        leases = self.valid_cached_ultra_leases(forward_id)
        if not leases:
            return None
        attempts = getattr(self, "_ultra_redelivery_at", None)
        if attempts is None:
            attempts = self._ultra_redelivery_at = {}
        now = time.monotonic()
        last = attempts.get(int(forward_id))
        if last is not None and now - last < ULTRA_REDELIVERY_INTERVAL_SECONDS:
            return None
        attempts[int(forward_id)] = now
        lock = self._forward_lock(forward_id)
        if not lock.acquire(blocking=False):
            return None
        try:
            delivered = self.send_ultra_leases(int(forward_id), leases)
        finally:
            lock.release()
        if delivered:
            logging.info("forward %s idle Ultra runtime received the cached lease; no restart needed", forward_id)
        return delivered

    def _note_ultra_idle(self, forward_id: int, state: str) -> None:
        noted = getattr(self, "_ultra_idle_noted", None)
        if noted is None:
            noted = self._ultra_idle_noted = {}
        previous = noted.get(forward_id, "")
        if state == previous:
            return
        if state:
            noted[forward_id] = state
            logging.info(
                "forward %s Ultra runtime alive with policy %s; not restarting while the control plane is unreachable",
                forward_id,
                state,
            )
        else:
            noted.pop(forward_id, None)

    def ensure_runtime_health(self, forwards: list[dict]) -> None:
        if not forwards:
            return

        for forward in forwards:
            if unsupported_client_backend_mode(forward) != "":
                forward_id = int(forward.get("forward_id", 0) or 0)
                if forward_id > 0:
                    self.stop_forward(forward_id, delete_files=True)
                continue
            if str(forward.get("service_status", "active")) != "active":
                continue

            forward_id = int(forward.get("forward_id", 0) or 0)
            if forward_id <= 0:
                continue

            if str(forward.get("backend_mode", "") or "").strip().lower() == "ultra":
                applied = self.load_applied().get(str(forward_id), {})
                if not self.applied_forward_definition_is_present(applied, forward_id):
                    # An active panel forward may be intentionally absent locally
                    # after a failed/partial reconciliation. Do not create a
                    # restart loop for a unit that this agent does not own.
                    continue
                access = forward.get("ultra_access")
                if not isinstance(access, dict) or not str(access.get("session_ticket", "") or "").strip():
                    # A broker grant can be temporarily absent from an otherwise
                    # valid panel snapshot. Do not turn that control-plane gap
                    # into a data-plane restart; the next sync will retry it.
                    continue

            service_name = forward_runtime_service_name(forward)
            if not self.systemd_is_active(service_name):
                self.restart_forward_service(forward_id, service_name, "systemd inactive")
                continue

            if str(forward.get("backend_mode", "") or "").strip().lower() == "ultra":
                # A cold runtime needs its first lease and up to 30s of probing.
                # Dialing its public port is not a readiness test: it creates a
                # real provider connection, and a transient miss must not restart
                # all active flows. Inspect listeners and debounce genuine loss.
                streaks = getattr(self, "_ultra_listener_failures", None)
                if streaks is None:
                    streaks = self._ultra_listener_failures = {}
                idle_state = self.ultra_runtime_idle_policy_state(forward_id)
                redelivered: bool | None = None
                if idle_state:
                    # Awaiting right after a restart, or lapsed: give it the
                    # current lease now instead of restarting it.
                    redelivered = self.redeliver_cached_ultra_lease(forward_id)
                    if redelivered:
                        streaks.pop(forward_id, None)
                        self._note_ultra_idle(forward_id, "")
                        continue
                age = self.service_active_age_seconds(service_name)
                if age is not None and age < 90.0:
                    streaks.pop(forward_id, None)
                    continue
                port = int(forward.get("listen_port", 0) or 0)
                probes = [self.local_listener_present(port, protocol)
                          for protocol in self.transport_protocols(forward.get("transport", "tcp+udp"))]
                if probes and all(item[0] for item in probes):
                    streaks.pop(forward_id, None)
                    self._note_ultra_idle(forward_id, "")
                    continue
                if idle_state and redelivered is not False:
                    # Alive, and waiting for (or past) its policy because the
                    # control plane is unreachable and no valid lease is at
                    # hand. A restart cannot fix that and only churns; it
                    # recovers as soon as a lease is delivered. (A runtime
                    # that refuses a valid lease falls through to the restart.)
                    streaks.pop(forward_id, None)
                    self._note_ultra_idle(forward_id, idle_state)
                    continue
                self._note_ultra_idle(forward_id, "")
                now = time.monotonic()
                count, first = streaks.get(forward_id, (0, now))
                streaks[forward_id] = (count + 1, first)
                if count + 1 >= 3 and now - first >= 10.0:
                    self.restart_forward_service(forward_id, service_name, "Ultra listener absent on 3 spaced checks")
                    streaks.pop(forward_id, None)
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
                    ready, stronger_latency_ms, stronger_error = self.bond_one_leg_connect_probe(forward, endpoint, endpoint_index)
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
                    if not ready and plain_tcp_ingress and bond_one_leg_probe_failed:
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
        with self._forward_lock(forward_id):
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
        entry["last_error"] = error or "bond_leg_probe_failed"
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
    def bond_runtime_health_entry(leg: dict, now: float) -> tuple[str, dict] | None:
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

    def reconcile_bond_runtime_health(self, forward: dict, now: float | None = None) -> dict:
        now = time.time() if now is None else now
        if not self.forward_uses_bond_runtime(forward):
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
            mapped = self.bond_runtime_health_entry(leg, now)
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
        if unsupported_client_backend_mode(forward) != "":
            return []
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

    def bond_one_leg_connect_probe(
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
            return False, None, "bond_probe_missing_forward_fields"

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
            last_error = "bond_leg_probe_timeout"
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
        if unsupported_client_backend_mode(forward) != "":
            return []
        now = time.time() if now is None else now
        endpoints = [dict(endpoint) for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        bonding = forward.get("bonding", {})
        if not isinstance(bonding, dict):
            bonding = {}
        base_priority = int(bonding.get("ws_priority", 10) or 10)
        health = self.reconcile_bond_runtime_health(forward, now).get(str(int(forward.get("forward_id", 0) or 0)), {})

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
            selected = healthy
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
                if unsupported_client_backend_mode(forward) != "":
                    continue
                forward_id = int(forward["forward_id"])
                local_service_name = forward_runtime_service_name(forward)
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

        self.control_plane_request(
            "POST",
            "/api/client/diagnostics/result",
            token,
            {
                "request_token": request_token,
                "results": results,
                "error": error_message,
            },
        )
        applied["_last_diag_request_token"] = request_token
        self.write_applied_state(applied)

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
        configure_panel_egress(getattr(self, "config", None))
        summary = {
            "ok": True,
            "agent_version": AGENT_VERSION,
            "panel_egress": PANEL_EGRESS.status(),
            "config_path": str(self.config_path),
            "state_dir": str(getattr(self, "state_dir", "/var/lib/leikwan-client-agent")),
            "master_url": str(self.config.get("master_url", "")),
            "panel_mirror_urls": control_plane_endpoints(self.config)[1:],
            "control_plane_endpoint_state": str(getattr(self, "endpoint_state_path", "")),
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
                "wstunnel_bin": str(self.wstunnel_binary_path()),
                "wstunnel_bin_present": self.binary_available(self.wstunnel_binary_path()),
                "mptcp_client_bin": str(mptcp_client),
                "mptcp_client_bin_present": mptcp_client_present,
                "mptcp_kernel_supported": mptcp_kernel_supported,
                "mptcp_capability": mptcp_capability,
                "wireguard_public_key_present": wireguard_public_key != "",
                "wireguard_listen_port": self.wireguard_listen_port(),
                "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            },
        }
        # Sealed-lease readiness, read-only: never creates the device key.
        seal_module = lease_seal_module()
        summary["ultra_lease_seal"] = {
            "available": seal_module is not None,
            "self_test": bool(seal_module is not None and seal_module.self_test()),
            "key_present": (pathlib.Path(getattr(self, "state_dir", "/var/lib/leikwan-client-agent")) / LEASE_KEY_FILENAME).is_file(),
        }
        if summary["service_active"] is not True:
            summary["ok"] = False
            summary["service_error"] = "leikwan-client-agent.service is not active"
        pull_state = summary["pull_state"]
        if isinstance(pull_state, dict) and pull_state.get("status") == "device_limit_reached":
            summary["ok"] = False
            summary["device_limit_error"] = str(pull_state.get("last_error", "") or "") or DeviceLimitReachedError(
                online_device_limit=pull_state.get("online_device_limit"),
                active_online_devices=pull_state.get("active_online_devices"),
            ).user_message()

        if live:
            try:
                selector = ControlPlaneEndpointState(getattr(self, "endpoint_state_path", None))
                live_errors: list[str] = []
                payload = None
                for endpoint in selector.ordered(control_plane_endpoints(self.config)):
                    try:
                        payload = http_json(
                            "GET",
                            f"{endpoint}/api/client/configs/pull",
                            str(self.config["agent_token"]),
                            extra_headers=device_request_headers(self.device_id()),
                        )
                        selector.success(endpoint)
                        self.active_control_plane_endpoint = endpoint
                        break
                    except DeviceLimitReachedError:
                        raise
                    except Exception as exc:  # noqa: BLE001
                        selector.failure(endpoint, exc)
                        live_errors.append(f"{endpoint}: {exc}")
                        if _is_control_plane_business_error(exc):
                            raise
                if payload is None:
                    raise RuntimeError("; ".join(live_errors) if live_errors else "no control-plane endpoint configured")
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
                invalid_forward_ids = [
                    int(forward.get("forward_id", 0) or 0)
                    for forward in forwards
                    if (
                        forward_requires_panel_endpoints(
                            forward,
                            self.normalize_mode(forward.get("mode", "single")),
                        )
                        and self.usable_endpoints(forward) == []
                    )
                ]
                summary["live_pull"] = {
                    "ok": True,
                    "forwards_total": len(forwards),
                    "forward_ids": [int(forward.get("forward_id", 0) or 0) for forward in forwards],
                    "invalid_forward_ids": invalid_forward_ids,
                    "bond_forward_ids": bond_forward_ids,
                    "mptcp_forward_ids": mptcp_forward_ids,
                }
                if invalid_forward_ids:
                    summary["ok"] = False
                    summary["live_pull"]["ok"] = False
                    summary["live_pull"]["invalid_forward_error"] = (
                        f"forwards have no usable endpoints: {invalid_forward_ids}"
                    )
                runtime_dependency_errors: dict[str, list[str]] = {}
                for forward in forwards:
                    forward_id = int(forward.get("forward_id", 0) or 0)
                    missing = [
                        name
                        for name, path in self.forward_runtime_requirements(forward).items()
                        if not self.binary_available(path)
                    ]
                    if missing:
                        runtime_dependency_errors[str(forward_id)] = missing
                if runtime_dependency_errors:
                    summary["ok"] = False
                    summary["live_pull"]["ok"] = False
                    summary["live_pull"]["runtime_dependency_errors"] = runtime_dependency_errors
                if bond_forward_ids and not bond_available:
                    summary["ok"] = False
                    summary["live_pull"]["bond_dependency_error"] = f"bond runtime forwards require {bond_bin}"
                if forwards and not summary["runtime"]["wstunnel_bin_present"]:
                    summary["ok"] = False
                    summary["live_pull"]["wstunnel_dependency_error"] = (
                        f"published client forwards require {self.wstunnel_binary_path()}"
                    )
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
            except DeviceLimitReachedError as exc:
                summary["ok"] = False
                summary["device_limit_error"] = exc.user_message()
                summary["live_pull"] = {
                    "ok": False,
                    "error": exc.user_message(),
                    "error_code": DEVICE_LIMIT_ERROR_CODE,
                    "online_device_limit": exc.online_device_limit,
                    "active_online_devices": exc.active_online_devices,
                }
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
        if summary.get("device_limit_error"):
            # Human line on stderr; stdout stays machine-readable JSON.
            print(f"leikwan-client-agent: {summary['device_limit_error']}", file=sys.stderr)
        print(json.dumps(summary, ensure_ascii=False, indent=2))
        return 0 if summary.get("ok", False) else 1
    agent.run()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
