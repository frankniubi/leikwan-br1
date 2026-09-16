sudo cp -a /usr/local/bin/ultra-source-agent.py /tmp/ultra_source_agent.py.before-upgrade
sudo tee /usr/local/bin/ultra-source-agent.py >/dev/null <<'ULTRA_EOF'
#!/usr/bin/env python3
"""Bounded outbound-only Ultra material collector."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import ipaddress
import json
import logging
import os
import random
import re
import secrets
import shutil
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

try:
    import tomllib
except ImportError:  # pragma: no cover - Python 3.10 compatibility on source hosts
    tomllib = None


AGENT_VERSION = "2026.09.13.1"
MAX_INVENTORY_BYTES = 256 * 1024
INVENTORY_SCHEMA_VERSION = 3
MAX_INVENTORY_RECORDS = 1024
CATALOG_SCHEMA_VERSION = 1

LINELIST_SUMMARY_RE = re.compile(
    r"^os_type=\d+\s+game_id=\d+\s+area_id=\d+\s+lines=(\d+)\s*$",
    re.MULTILINE,
)
LINELIST_ROW_RE = re.compile(
    r"^\s*line_id=(\d+)\s+area_id=(\d+)\s+line_type=(\d+)\s+"
    r"title=(.*?)\s+speed_ip=(\S+)\s+pc_port=(\d+)\s*$",
    re.MULTILINE,
)


def rfc3339(timestamp: int | float) -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(timestamp))


def signed_headers(
    source_id: str,
    source_secret: str,
    method: str,
    path: str,
    body: bytes,
    *,
    timestamp: int | None = None,
    nonce: str | None = None,
) -> dict[str, str]:
    issued = int(time.time()) if timestamp is None else int(timestamp)
    request_nonce = nonce or secrets.token_hex(16)
    # The panel signs the request path without its query string, so a parameter
    # added to the URL must not leak into the canonical request.
    signed_path = path.split("?", 1)[0]
    canonical = "\n".join(
        [
            method.upper(),
            signed_path,
            source_id,
            str(issued),
            request_nonce,
            hashlib.sha256(body).hexdigest(),
        ]
    ).encode("utf-8")
    signature = hmac.new(source_secret.encode("utf-8"), canonical, hashlib.sha256).hexdigest()
    return {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": f"Ultra-Source-Agent/{AGENT_VERSION}",
        "X-Ultra-Source-Id": source_id,
        "X-Ultra-Timestamp": str(issued),
        "X-Ultra-Nonce": request_nonce,
        "X-Ultra-Signature": signature,
    }


def request_json(
    base_url: str,
    source_id: str,
    source_secret: str,
    method: str,
    path: str,
    payload: dict | None,
    timeout: float,
) -> dict:
    body = b"" if payload is None else json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    headers = signed_headers(source_id, source_secret, method, path, body)
    request = urllib.request.Request(
        urllib.parse.urljoin(base_url.rstrip("/") + "/", path.lstrip("/")),
        data=body if method.upper() != "GET" else None,
        headers=headers,
        method=method.upper(),
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            decoded = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        message = exc.read().decode("utf-8", errors="ignore")[:512]
        raise RuntimeError(f"control request failed with HTTP {exc.code}: {message}") from exc
    if not isinstance(decoded, dict) or decoded.get("ok") is not True:
        raise RuntimeError("control response was rejected")
    return decoded


def request_json_with_retries(
    base_url: str,
    source_id: str,
    source_secret: str,
    method: str,
    path: str,
    payload: dict | None,
    timeout: float,
    *,
    attempts: int = 3,
) -> dict:
    last_error: Exception | None = None
    count = max(1, min(3, int(attempts)))
    for attempt in range(count):
        try:
            return request_json(base_url, source_id, source_secret, method, path, payload, timeout)
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            if attempt + 1 < count:
                time.sleep(0.5 if attempt == 0 else 1.5)
    raise RuntimeError(f"control request retries exhausted: {last_error}") from last_error


def _flatten_mapping(value: object) -> dict[str, object]:
    flattened: dict[str, object] = {}
    if not isinstance(value, dict):
        return flattened
    for raw_key, item in value.items():
        key = str(raw_key).strip().lower()
        if isinstance(item, dict):
            flattened.update(_flatten_mapping(item))
        elif key:
            flattened[key] = item
    return flattened


def _parse_line_config(raw: bytes) -> dict[str, object]:
    if tomllib is not None:
        try:
            return _flatten_mapping(tomllib.loads(raw.decode("utf-8-sig")))
        except (UnicodeDecodeError, ValueError):
            return {}
    parsed: dict[str, object] = {}
    for raw_line in raw.decode("utf-8-sig", errors="ignore").splitlines():
        line = raw_line.split("#", 1)[0].strip()
        if not line or line.startswith("[") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip().lower()
        value = value.strip().strip('"').strip("'")
        if key in {"line_id", "game_id", "area_id", "line_type"}:
            try:
                parsed[key] = int(value)
            except ValueError:
                continue
        elif key in {
            "speed_ip",
            "title",
            "family_key",
            "family",
            "access_class",
            "native_udp_state",
            "native_socks5_state",
            "route_class",
            "pricing_class",
            "pricing_evidence",
            "audit_at",
            "audited_at",
            "audit_timestamp",
            "checked_at",
            "audit_hash",
            "manifest_hash",
            "audit_sha256",
            "manifest_sha256",
            "sha256",
        }:
            parsed[key] = value
    return parsed


def _inventory_index(index_path: Path) -> dict[int, dict[str, object]]:
    try:
        if index_path.stat().st_size > MAX_INVENTORY_BYTES:
            raise RuntimeError("inventory index exceeds size limit")
        decoded = json.loads(index_path.read_text(encoding="utf-8-sig"))
    except FileNotFoundError:
        return {}
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("inventory index is invalid") from exc
    raw_lines = decoded.get("lines", []) if isinstance(decoded, dict) else decoded
    if not isinstance(raw_lines, list):
        return {}
    records: dict[int, dict[str, object]] = {}
    for raw in raw_lines[:MAX_INVENTORY_RECORDS]:
        if not isinstance(raw, dict):
            continue
        try:
            line_id = int(raw.get("line_id", 0) or 0)
        except (TypeError, ValueError):
            continue
        if 0 < line_id <= 45535:
            records[line_id] = raw
    return records


def _line_audit_manifest(manifest_path: Path | None) -> dict[int, dict[str, object]]:
    if manifest_path is None:
        return {}
    try:
        if manifest_path.stat().st_size > MAX_INVENTORY_BYTES:
            raise RuntimeError("line audit manifest exceeds size limit")
        decoded = json.loads(manifest_path.read_text(encoding="utf-8-sig"))
    except FileNotFoundError:
        return {}
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("line audit manifest is invalid") from exc

    known_fields = {
        "access_class",
        "native_udp_state",
        "native_socks5_state",
        "route_class",
        "pricing_class",
        "pricing_evidence",
        "audit_at",
        "audited_at",
        "audit_timestamp",
        "audit_time",
        "checked_at",
        "timestamp",
        "at",
        "audit_hash",
        "manifest_hash",
        "audit_sha256",
        "manifest_sha256",
        "sha256",
        "hash",
    }

    def known_values(value: object) -> dict[str, object]:
        if not isinstance(value, dict):
            return {}
        return {str(key).strip().lower(): item for key, item in value.items()
                if str(key).strip().lower() in known_fields}

    def default_values(value: object) -> dict[str, object]:
        defaults = known_values(value)
        if isinstance(value, dict):
            for key in ("audit", "metadata"):
                defaults.update(known_values(value.get(key)))
        return defaults

    def has_line_id(value: object, depth: int = 0) -> bool:
        if not isinstance(value, dict):
            return False
        if value.get("line_id") not in (None, ""):
            return True
        if depth >= 3:
            return False
        return any(has_line_id(value.get(key), depth + 1) for key in ("line", "audit", "record", "data"))

    def line_id_from(*values: object, _depth: int = 0) -> int:
        for value in values:
            if isinstance(value, dict):
                try:
                    candidate = int(value.get("line_id", 0) or 0)
                except (TypeError, ValueError):
                    candidate = 0
                if 0 < candidate <= 45535:
                    return candidate
                if _depth < 3:
                    candidate = line_id_from(
                        *(value.get(key) for key in ("line", "audit", "record", "data")),
                        _depth=_depth + 1,
                    )
                    if candidate:
                        return candidate
        return 0

    manifest_defaults: dict[str, object] = {}
    if isinstance(decoded, dict):
        # Defaults may be flat or grouped under an audit/defaults object. Keep
        # this allowlist-only so credentials and opaque provider fields never
        # enter the inventory record.
        manifest_defaults.update(default_values(decoded))
        for key in ("defaults", "default", "audit_defaults", "audit_default"):
            manifest_defaults.update(default_values(decoded.get(key)))
        top_audit = decoded.get("audit")
        if isinstance(top_audit, dict) and not has_line_id(top_audit):
            manifest_defaults.update(default_values(top_audit))

    if isinstance(decoded, list):
        raw_lines: object = decoded
    elif isinstance(decoded, dict):
        if has_line_id(decoded):
            raw_lines = [decoded]
        else:
            raw_lines = decoded
            for key in ("lines", "records", "audits", "audit"):
                if key in decoded:
                    raw_lines = decoded[key]
                    break
    else:
        return {}

    if isinstance(raw_lines, list):
        iterable = ((None, raw) for raw in raw_lines[:MAX_INVENTORY_RECORDS])
    elif isinstance(raw_lines, dict):
        iterable = list(raw_lines.items())[:MAX_INVENTORY_RECORDS]
    else:
        return {}

    records: dict[int, dict[str, object]] = {}
    for raw_key, raw in iterable:
        if not isinstance(raw, dict):
            continue
        line_id = line_id_from(raw, raw.get("line"), raw.get("audit"), {"line_id": raw_key})
        if 0 < line_id <= 45535:
            record = dict(manifest_defaults)
            nested_line = raw.get("line")
            nested_audit = raw.get("audit")
            record.update(default_values(nested_line))
            if isinstance(nested_audit, dict):
                record.update(default_values(nested_audit))
            record.update(known_values(raw))
            record["line_id"] = line_id
            records[line_id] = record
    return records


def _bounded_text(value: object, limit: int = 255) -> str:
    text = str(value or "").strip()
    if any(ord(character) < 32 and character not in "\t\n\r" for character in text):
        return ""
    return text[:limit]


def _unsigned_int(*values: object) -> int | None:
    for value in values:
        if value is None or value == "":
            continue
        try:
            number = int(value)
        except (TypeError, ValueError):
            continue
        if 0 <= number <= 4294967295:
            return number
    return None


def _literal_ip(*values: object) -> str:
    for value in values:
        text = str(value or "").strip()
        try:
            return str(ipaddress.ip_address(text))
        except ValueError:
            continue
    return ""


def _single_line_text(value: object, limit: int = 255) -> str:
    return _bounded_text(" ".join(str(value or "").split()), limit)


def _safe_inventory_text(value: object, limit: int = 255) -> str:
    if not isinstance(value, (bool, int, float, str)):
        return ""
    return _single_line_text(value, limit)


def _first_value(*records: dict[str, object], keys: tuple[str, ...]) -> object:
    for record in records:
        for key in keys:
            value = record.get(key)
            if value is not None and value != "":
                return value
    return None


def _inventory_classification(value: object) -> str:
    text = _safe_inventory_text(value, 64).lower()
    return text or "unknown"


def _inventory_evidence(value: object) -> str:
    return _safe_inventory_text(value, 255) or "unknown"


def _inventory_audit_at(value: object) -> str:
    return _safe_inventory_text(value, 64) or "unknown"


def _inventory_audit_hash(*records: dict[str, object]) -> str:
    for record in records:
        for key in ("audit_hash", "manifest_hash", "audit_sha256", "manifest_sha256", "sha256", "hash"):
            text = _safe_inventory_text(record.get(key), 64).lower()
            if re.fullmatch(r"[0-9a-f]{64}", text):
                return text
    return "unknown"


def _inventory_config_hash(
    line_id: int,
    raw: bytes | None,
    line: dict[str, object],
    *records: dict[str, object],
) -> str:
    if raw is not None:
        return hashlib.sha256(raw).hexdigest()
    for record in records:
        text = _safe_inventory_text(record.get("config_sha256"), 64).lower()
        if re.fullmatch(r"[0-9a-f]{64}", text):
            return text
    identity = dict(line)
    identity.pop("config_sha256", None)
    identity["line_id"] = line_id
    encoded = json.dumps(identity, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def parse_linelist_output(output: str) -> tuple[bool, dict[int, dict[str, object]]]:
    summary = LINELIST_SUMMARY_RE.search(str(output))
    if summary is None:
        return False, {}
    declared_count = int(summary.group(1))
    matched_count = 0
    records: dict[int, dict[str, object]] = {}
    for match in LINELIST_ROW_RE.finditer(str(output)):
        matched_count += 1
        line_id = int(match.group(1))
        if line_id < 1 or line_id > 45535:
            continue
        title = _single_line_text(match.group(4), 255)
        segments = [segment.strip() for segment in title.split("-") if segment.strip()]
        records[line_id] = {
            "line_id": line_id,
            "title": title,
            "catalog_ingress": _single_line_text(segments[0] if len(segments) >= 2 else "", 191),
            "catalog_destination": _single_line_text(segments[1] if len(segments) >= 2 else "", 191),
            "discovery_ip": _literal_ip(match.group(5)),
        }
    if matched_count != declared_count:
        return False, {}
    return True, records


def _empty_catalog_cache() -> dict[str, object]:
    return {"schema_version": CATALOG_SCHEMA_VERSION, "records": {}, "pairs": {}}


def _read_catalog_cache(path: Path) -> dict[str, object]:
    try:
        if path.stat().st_size > MAX_INVENTORY_BYTES:
            return _empty_catalog_cache()
        decoded = json.loads(path.read_text(encoding="utf-8-sig"))
    except (FileNotFoundError, OSError, UnicodeDecodeError, json.JSONDecodeError):
        return _empty_catalog_cache()
    if not isinstance(decoded, dict):
        return _empty_catalog_cache()
    records = decoded.get("records", {})
    pairs = decoded.get("pairs", {})
    return {
        "schema_version": CATALOG_SCHEMA_VERSION,
        "records": records if isinstance(records, dict) else {},
        "pairs": pairs if isinstance(pairs, dict) else {},
    }


def _write_catalog_cache(path: Path, cache: dict[str, object]) -> None:
    encoded = json.dumps(cache, ensure_ascii=False, separators=(",", ":"), sort_keys=True) + "\n"
    if len(encoded.encode("utf-8")) > MAX_INVENTORY_BYTES:
        raise RuntimeError("catalog cache exceeds size limit")
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    try:
        temporary.write_text(encoded, encoding="utf-8")
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)
        os.chmod(path, 0o600)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


def _line_config_groups(lines_dir: Path) -> dict[tuple[int, int], list[tuple[int, Path]]]:
    groups: dict[tuple[int, int], list[tuple[int, Path]]] = {}
    for path in lines_dir.glob("*.toml"):
        try:
            line_id = int(path.stem)
            metadata = _parse_line_config(path.read_bytes())
            game_id = int(metadata.get("game_id", 0) or 0)
            area_id = int(metadata.get("area_id", 0) or 0)
        except (OSError, TypeError, ValueError):
            continue
        if not (0 < line_id <= 45535 and game_id > 0 and area_id > 0):
            continue
        groups.setdefault((game_id, area_id), []).append((line_id, path))
    for members in groups.values():
        members.sort(key=lambda item: item[0])
    return dict(sorted(groups.items()))


def refresh_catalog_cache(
    lines_dir: Path,
    binary_path: Path,
    session_path: Path | None,
    cache_path: Path,
    *,
    now: int,
    refresh_seconds: int,
    retry_seconds: int,
    max_queries: int,
    timeout: float,
) -> dict[str, int]:
    cache = _read_catalog_cache(cache_path)
    records = dict(cache.get("records", {}))
    pair_states = dict(cache.get("pairs", {}))
    groups = _line_config_groups(lines_dir)
    due: list[tuple[tuple[int, int], list[tuple[int, Path]]]] = []
    refresh_after = max(300, int(refresh_seconds))
    retry_after = max(60, int(retry_seconds))
    for pair, members in groups.items():
        key = f"{pair[0]}:{pair[1]}"
        state = pair_states.get(key, {})
        state = state if isinstance(state, dict) else {}
        last_attempt = int(state.get("last_attempt_at", 0) or 0)
        last_success = int(state.get("last_success_at", 0) or 0)
        if last_success > 0 and now - last_success < refresh_after:
            continue
        if last_success <= 0 and last_attempt > 0 and now - last_attempt < retry_after:
            continue
        due.append((pair, members))
    attempted = 0
    succeeded = 0
    changed = 0
    for pair, members in due[: max(1, min(64, int(max_queries)))]:
        attempted += 1
        key = f"{pair[0]}:{pair[1]}"
        previous_state = pair_states.get(key, {})
        previous_state = previous_state if isinstance(previous_state, dict) else {}
        pair_states[key] = {
            "last_attempt_at": now,
            "last_success_at": int(previous_state.get("last_success_at", 0) or 0),
        }
        command = [str(binary_path), "linelist", "--config", str(members[0][1])]
        if session_path is not None:
            command.extend(["--session", str(session_path)])
        try:
            completed = subprocess.run(
                command,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=max(1.0, min(30.0, float(timeout))),
                check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            continue
        if completed.returncode != 0:
            continue
        valid, current = parse_linelist_output(completed.stdout)
        if not valid:
            continue
        local_ids = {line_id for line_id, _path in members}
        before = {str(line_id): records.get(str(line_id)) for line_id in local_ids if str(line_id) in records}
        for line_id in local_ids:
            records.pop(str(line_id), None)
        for line_id, record in current.items():
            if line_id in local_ids:
                records[str(line_id)] = record
        after = {str(line_id): records.get(str(line_id)) for line_id in local_ids if str(line_id) in records}
        if before != after:
            changed += 1
        pair_states[key] = {"last_attempt_at": now, "last_success_at": now}
        succeeded += 1
    cache = {
        "schema_version": CATALOG_SCHEMA_VERSION,
        "updated_at": now,
        "records": dict(sorted(records.items(), key=lambda item: int(item[0]))),
        "pairs": dict(sorted(pair_states.items())),
    }
    if attempted > 0 or not cache_path.exists():
        _write_catalog_cache(cache_path, cache)
    return {"attempted": attempted, "succeeded": succeeded, "changed": changed}


def _catalog_snapshot(
    path: Path | None,
    required_pairs: set[tuple[int, int]],
) -> tuple[dict[int, dict[str, object]], bool]:
    if path is None:
        return {}, False
    cache = _read_catalog_cache(path)
    raw_records = cache.get("records", {})
    records: dict[int, dict[str, object]] = {}
    for raw_line_id, raw in list(raw_records.items())[:MAX_INVENTORY_RECORDS] if isinstance(raw_records, dict) else []:
        if not isinstance(raw, dict):
            continue
        try:
            line_id = int(raw_line_id)
        except (TypeError, ValueError):
            continue
        if 0 < line_id <= 45535:
            records[line_id] = raw
    pair_states = cache.get("pairs", {})
    authoritative = bool(required_pairs)
    for game_id, area_id in required_pairs:
        state = pair_states.get(f"{game_id}:{area_id}", {}) if isinstance(pair_states, dict) else {}
        if not isinstance(state, dict) or int(state.get("last_success_at", 0) or 0) <= 0:
            authoritative = False
            break
    return records, authoritative


def build_inventory(
    lines_dir: Path,
    index_path: Path,
    *,
    catalog_path: Path | None = None,
    audit_manifest_path: Path | None = None,
    max_lines: int = 256,
) -> dict[str, object]:
    limit = max(1, min(256, int(max_lines)))
    index = _inventory_index(index_path)
    audit = _line_audit_manifest(audit_manifest_path)
    catalog, _catalog_authoritative = _catalog_snapshot(catalog_path, set())
    local_paths: dict[int, Path] = {}
    for path in lines_dir.glob("*.toml"):
        try:
            line_id = int(path.stem)
        except ValueError:
            continue
        if 0 < line_id <= 45535 and path.is_file():
            local_paths[line_id] = path

    candidate_ids = sorted(set(local_paths) | set(index) | set(audit))
    all_lines: list[dict[str, object]] = []
    for line_id in candidate_ids:
        path = local_paths.get(line_id)
        raw = path.read_bytes() if path is not None else None
        metadata = _parse_line_config(raw) if raw is not None else {}
        indexed = index.get(line_id, {})
        audited = audit.get(line_id, {})
        cataloged = catalog.get(line_id, {})
        line: dict[str, object] = {
            "line_id": line_id,
            "title": _safe_inventory_text(
                _first_value(cataloged, audited, indexed, metadata, keys=("title", "name", "label"))
            ),
            "family_key": _safe_inventory_text(
                _first_value(audited, indexed, metadata, keys=("family_key", "family"))
            ),
            "game_id": _unsigned_int(
                metadata.get("game_id"), audited.get("game_id"), indexed.get("game_id")
            ),
            "area_id": _unsigned_int(
                metadata.get("area_id"), audited.get("area_id"), indexed.get("area_id")
            ),
            "line_type": _unsigned_int(
                metadata.get("line_type"), audited.get("line_type"), indexed.get("line_type")
            ),
            # Carrier metadata (2026-09-11): the sweep index is the freshest source,
            # the audit manifest may add it later. The panel normalizes 电信/联通/移动/通用
            # into telecom/unicom/mobile/generic.
            "in_line_type": _unsigned_int(
                metadata.get("in_line_type"), audited.get("in_line_type"), indexed.get("in_line_type")
            ),
            "isp": _safe_inventory_text(
                _first_value(indexed, audited, metadata, keys=("isp",)), 32
            ),
            "provider": _safe_inventory_text(
                _first_value(indexed, audited, metadata, keys=("provider",)), 64
            ),
            # The audit manifest may declare the dispatch priority: a line whose relay
            # association drops big datagrams is dispatched last, like the Tencent ones.
            "dispatch_class": _inventory_classification(
                _first_value(audited, indexed, keys=("dispatch_class",))
            ),
            "catalog_ingress": _safe_inventory_text(
                _first_value(cataloged, audited, indexed, keys=("catalog_ingress",)), 191
            ),
            "catalog_destination": _safe_inventory_text(
                _first_value(cataloged, audited, indexed, keys=("catalog_destination",)), 191
            ),
            "discovery_ip": _literal_ip(
                cataloged.get("discovery_ip"),
                audited.get("discovery_ip"),
                indexed.get("speed_ip"),
                indexed.get("discovery_ip"),
                metadata.get("speed_ip"),
            ),
            "access_class": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("access_class",))
            ),
            "native_udp_state": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("native_udp_state",))
            ),
            "native_socks5_state": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("native_socks5_state",))
            ),
            "route_class": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("route_class",))
            ),
            "pricing_class": _inventory_classification(
                _first_value(audited, indexed, metadata, keys=("pricing_class",))
            ),
            "pricing_evidence": _inventory_evidence(
                _first_value(audited, indexed, metadata, keys=("pricing_evidence",))
            ),
            "audit_at": _inventory_audit_at(
                _first_value(
                    audited,
                    indexed,
                    metadata,
                    keys=("audit_at", "audited_at", "audit_timestamp", "checked_at", "timestamp"),
                )
            ),
            "audit_hash": _inventory_audit_hash(audited, indexed, metadata),
        }
        line["config_sha256"] = _inventory_config_hash(line_id, raw, line, audited, indexed)
        all_lines.append(line)

    complete = len(all_lines) <= limit
    normalized = all_lines[:limit]
    fingerprint_input = json.dumps(
        {
            "schema_version": INVENTORY_SCHEMA_VERSION,
            "complete": complete,
            "line_count": len(all_lines),
            "lines": all_lines,
        },
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    payload: dict[str, object] = {
        "schema_version": INVENTORY_SCHEMA_VERSION,
        "agent_version": AGENT_VERSION,
        "inventory_fingerprint": hashlib.sha256(fingerprint_input).hexdigest(),
        "complete": complete,
        "lines": normalized,
    }
    if len(json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")) > MAX_INVENTORY_BYTES:
        raise RuntimeError("inventory payload exceeds size limit")
    return payload


def inventory_due(
    state_path: Path,
    fingerprint: str,
    *,
    now: int | float,
    refresh_seconds: int | float,
) -> bool:
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
    except (FileNotFoundError, OSError, UnicodeDecodeError, json.JSONDecodeError):
        return True
    if not isinstance(state, dict) or str(state.get("inventory_fingerprint", "")) != fingerprint:
        return True
    try:
        uploaded_at = float(state.get("uploaded_at", 0))
    except (TypeError, ValueError):
        return True
    return float(now) - uploaded_at >= max(60.0, float(refresh_seconds))


def write_inventory_state(state_path: Path, fingerprint: str, *, uploaded_at: int | float) -> None:
    state_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = state_path.with_name(f".{state_path.name}.{os.getpid()}.tmp")
    encoded = json.dumps(
        {"inventory_fingerprint": fingerprint, "uploaded_at": int(uploaded_at)},
        separators=(",", ":"),
    ) + "\n"
    try:
        temporary.write_text(encoded, encoding="utf-8")
        os.chmod(temporary, 0o600)
        os.replace(temporary, state_path)
        os.chmod(state_path, 0o600)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


def normalize_acquisition(line_id: int, response: dict, *, now: int, lifetime_seconds: int) -> dict:
    data = response.get("data") if isinstance(response, dict) else None
    if not isinstance(data, dict):
        raise RuntimeError("acquisition response has no data")
    user = str(data.get("line_user", "") or "").strip()
    credential = str(data.get("line_token", "") or "").strip()
    if not user or len(user) > 255 or not credential or len(credential) > 255:
        raise RuntimeError("acquisition response has invalid material")
    default_port = int(data.get("pc_port", 0) or 0)
    relays: list[dict[str, object]] = []
    seen: set[tuple[str, int]] = set()

    def add(ip_value: object, port_value: object) -> None:
        ip_text = str(ip_value or "").strip()
        try:
            ip_text = str(ipaddress.ip_address(ip_text))
        except ValueError:
            return
        try:
            port = int(port_value or default_port)
        except (TypeError, ValueError):
            return
        if not 1 <= port <= 65535 or (ip_text, port) in seen:
            return
        seen.add((ip_text, port))
        relays.append({"ip": ip_text, "port": port})

    add(data.get("speed_ip"), default_port)
    add(data.get("test_speed_ip"), default_port)
    for item in data.get("fail_over_list", []) or []:
        if isinstance(item, dict):
            add(item.get("speed_ip") or item.get("server_ip"), item.get("pc_port") or item.get("server_port"))
    spare_ports = data.get("spare_port_list", []) or []
    for index, item in enumerate(data.get("spare_list", []) or []):
        if isinstance(item, dict):
            add(item.get("speed_ip") or item.get("server_ip") or item.get("ip"), item.get("pc_port") or item.get("server_port") or item.get("port"))
        else:
            port = spare_ports[index] if index < len(spare_ports) else default_port
            add(item, port)
    if not relays:
        raise RuntimeError("acquisition response has no literal relay entries")
    relays = relays[:8]
    generation_seed = json.dumps([line_id, user, credential, relays], separators=(",", ":"), sort_keys=True).encode("utf-8")
    return {
        "line_id": int(line_id),
        "generation": hashlib.sha256(generation_seed).hexdigest(),
        "acquired_at": rfc3339(now),
        "expires_at": rfc3339(now + max(60, int(lifetime_seconds))),
        "user": user,
        "credential": credential,
        "relays": relays,
        "probe": {"ok": True},
    }


def acquire_line(
    line_id: int,
    binary_path: Path,
    lines_dir: Path,
    output_path: Path,
    *,
    session_path: Path | None = None,
    timeout: float,
    lifetime_seconds: int,
    now: int | None = None,
) -> dict:
    line_path = lines_dir / f"{line_id}.toml"
    if not line_path.is_file():
        raise RuntimeError(f"line configuration {line_id} is missing")
    output_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        output_path.unlink()
    except FileNotFoundError:
        pass
    command = [str(binary_path), "startacc", "--config", str(line_path)]
    if session_path is not None:
        command.extend(["--session", str(session_path)])
    command.extend(["--save", str(output_path)])
    completed = subprocess.run(
        command,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=max(1.0, float(timeout)),
        check=False,
    )
    if completed.returncode != 0 or not output_path.is_file():
        raise RuntimeError(f"line acquisition {line_id} failed")
    try:
        response = json.loads(output_path.read_text(encoding="utf-8"))
        return normalize_acquisition(
            line_id,
            response,
            now=int(time.time()) if now is None else int(now),
            lifetime_seconds=lifetime_seconds,
        )
    finally:
        try:
            output_path.unlink()
        except FileNotFoundError:
            pass


def advertised_relay_ips(advertised: object) -> list:
    """The relay IPs the panel advertised for a review, validated and deduplicated.

    The panel sends the set it re-derives from ultra_lines.relay_ips_json, which
    is also the set reviewProof() checks a probed endpoint against, so an agent
    cannot widen its own mandate by claiming an endpoint.
    """
    if isinstance(advertised, str):
        advertised = [item for item in advertised.split(",")]
    if not isinstance(advertised, (list, tuple)):
        return []
    seen = {}
    for item in advertised:
        ip = str(item or "").strip()
        if not ip:
            continue
        seen.setdefault(ip.lower(), ip)
    return list(seen.values())


def select_review_relay(relays: object, advertised: object) -> tuple:
    """Pick the relay a review may probe.

    The reviewed endpoint must be one the panel advertises for the line, and the
    panel stores relay IPs only - it cannot express ip:port for a review - so the
    advertised IP is paired with the port the line material carried for it. An
    endpoint the source host picked for itself is not evidence about the
    candidate, which is how 8 of the first 9 reviews came to test the wrong
    endpoint.

    Returns (relay, reason); reason is "" when a relay was chosen.
    """
    usable = []
    for raw in relays if isinstance(relays, list) else []:
        if not isinstance(raw, dict):
            continue
        ip = str(raw.get("ip", "") or "").strip()
        try:
            port = int(raw.get("port", 0) or 0)
        except (TypeError, ValueError):
            continue
        if not ip or port <= 0 or port > 65535:
            continue
        usable.append({"ip": ip, "port": port})
    if not usable:
        return None, "line material has no relay"
    mandate = advertised_relay_ips(advertised)
    if not mandate:
        # An older panel payload carries no advertised set at all. Run the review
        # anyway so the agent keeps working, but its proof names an endpoint the
        # panel never advertised, and the panel's own proof gate refuses it - the
        # intended outcome for evidence about an endpoint nobody advertised.
        return usable[0], ""
    by_ip = {}
    for relay in usable:
        by_ip.setdefault(relay["ip"].lower(), relay)
    for ip in mandate:
        chosen = by_ip.get(ip.lower())
        if chosen is not None:
            return chosen, ""
    return None, "no advertised relay in the line material"


def non_discriminating_review_detail(reason: str, target: str = "", relay: str = "") -> dict:
    """A probe-shaped result that can never be mistaken for evidence.

    The panel stores this verbatim and its proof gate records the review as
    rebuttal=blocked, so nothing is struck, demoted or lifted on a review that
    could not test an advertised endpoint.
    """
    return {
        "passed": False,
        "relay": relay,
        "target": target,
        "rounds": 0,
        "last_error": reason,
        "end_to_end_ok": 0,
        "local_path_only": True,
        "proof": {
            "endpoint": relay,
            "local_path_only": True,
            "end_to_end_ok": 0,
            "target": target,
            "method": "relay_stream_to_client_target",
            "reason": reason,
        },
    }


class SourceAgent:
    def __init__(self, config_path: Path):
        self.config = json.loads(config_path.read_text(encoding="utf-8-sig"))
        self.base_url = str(self.config["panel_url"]).rstrip("/")
        if urllib.parse.urlparse(self.base_url).scheme != "https":
            raise RuntimeError("panel_url must use HTTPS")
        self.source_id = str(self.config["source_id"])
        self.source_secret = str(self.config["source_secret"])
        self.binary_path = Path(self.config.get("binary_path", "/opt/ultra-source/runtime/line-client"))
        # Panel-requested reviews of quarantined lines. The probe keeps a large
        # UDP payload and a real DNS query alive for the whole window; a line the
        # provider side can still carry is cleared on the panel.
        self.review_script = Path(self.config.get("review_script", "/opt/ultra-source/runtime/ultra_line_review.py"))
        self.review_interval = float(self.config.get("review_interval_seconds", 20))
        self.review_big_bytes = int(self.config.get("review_big_bytes", 1400))
        self.review_require_big_packet = bool(self.config.get("review_require_big_packet", False))
        self.max_reviews_per_cycle = int(self.config.get("max_reviews_per_cycle", 1))
        self.lines_dir = Path(self.config.get("lines_dir", "/opt/ultra-source/lines"))
        self.state_dir = Path(self.config.get("state_dir", "/var/lib/ultra-source-agent"))
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.inventory_path = Path(self.config.get("inventory_path", str(self.lines_dir / "index.json")))
        self.inventory_refresh = max(300, min(86400, int(self.config.get("inventory_refresh_seconds", 1800))))
        self.max_inventory_lines = max(1, min(256, int(self.config.get("max_inventory_lines", 256))))
        self.inventory_state_path = self.state_dir / "inventory-state.json"
        configured_audit = ""
        for key in ("audit_manifest_path", "line_audit_manifest_path"):
            configured_audit = str(self.config.get(key, "") or "").strip()
            if configured_audit:
                break
        self.audit_manifest_path = Path(configured_audit) if configured_audit else None
        self.catalog_path = Path(self.config.get("catalog_path", str(self.state_dir / "catalog-cache.json")))
        self.catalog_refresh = max(1800, min(604800, int(self.config.get("catalog_refresh_seconds", 21600))))
        self.catalog_retry = max(60, min(3600, int(self.config.get("catalog_retry_seconds", 300))))
        self.catalog_max_queries = max(1, min(8, int(self.config.get("catalog_max_queries_per_cycle", 4))))
        self.catalog_timeout = max(2.0, min(30.0, float(self.config.get("catalog_query_timeout_seconds", 15))))
        configured_session = str(self.config.get("session_path", "") or "").strip()
        self.session_path = Path(configured_session) if configured_session else None
        self._harden_session_permissions()
        self.login_binary_path = Path(self.config.get("login_binary_path", str(self.binary_path)))
        self.login_mobile = str(self.config.get("login_mobile", "") or "").strip()
        self.login_country = max(1, min(999, int(self.config.get("login_country", 86))))
        self.login_region = max(1, min(999, int(self.config.get("login_region", 1))))
        self.login_config_path = str(self.config.get("login_config_path", "") or "").strip()
        self.login_password_env = str(self.config.get("login_password_env", "LEIGOD_PASSWORD") or "").strip()
        self.login_home = Path(str(self.config.get("login_home", "") or "").strip()) if str(self.config.get("login_home", "") or "").strip() else None
        self.login_timeout = max(15.0, min(180.0, float(self.config.get("login_timeout_seconds", 60))))
        self.login_retry = max(60.0, min(3600.0, float(self.config.get("login_retry_seconds", 300))))
        self._last_login_at = 0.0
        self.timeout = max(2.0, min(30.0, float(self.config.get("request_timeout_seconds", 10))))
        self.acquire_timeout = max(5.0, min(60.0, float(self.config.get("acquire_timeout_seconds", 30))))
        self.material_lifetime = max(300, min(3600, int(self.config.get("material_lifetime_seconds", 1800))))
        self.max_lines = max(1, min(8, int(self.config.get("max_lines_per_cycle", 8))))
        self.poll_interval = max(30.0, min(900.0, float(self.config.get("poll_interval_seconds", 60))))

    def run_cycle(self) -> int:
        try:
            inventory = build_inventory(
                self.lines_dir,
                self.inventory_path,
                catalog_path=self.catalog_path,
                audit_manifest_path=self.audit_manifest_path,
                max_lines=self.max_inventory_lines,
            )
            now = int(time.time())
            fingerprint = str(inventory["inventory_fingerprint"])
            if inventory_due(
                self.inventory_state_path,
                fingerprint,
                now=now,
                refresh_seconds=self.inventory_refresh,
            ):
                request_json(
                    self.base_url,
                    self.source_id,
                    self.source_secret,
                    "POST",
                    "/api/ultra/source/inventory",
                    inventory,
                    self.timeout,
                )
                write_inventory_state(self.inventory_state_path, fingerprint, uploaded_at=now)
        except Exception as exc:  # noqa: BLE001
            logging.warning("inventory upload failed: %s", exc)
        jobs = request_json_with_retries(
            self.base_url,
            self.source_id,
            self.source_secret,
            "GET",
            "/api/ultra/source/jobs?reviews_capacity=" + str(max(0, self.max_reviews_per_cycle)),
            None,
            self.timeout,
            attempts=3,
        )
        ticket = str(jobs.get("upload_ticket", "") or "")
        reviews = []
        for raw_review in jobs.get("reviews", []) if isinstance(jobs.get("reviews"), list) else []:
            if isinstance(raw_review, dict):
                reviews.append(raw_review)
        line_ids = []
        for raw_line_id in jobs.get("line_ids", []) if isinstance(jobs.get("line_ids"), list) else []:
            line_id = int(raw_line_id or 0)
            if 0 < line_id <= 45535 and line_id not in line_ids:
                line_ids.append(line_id)
        line_ids = line_ids[: self.max_lines]
        if not line_ids:
            self.process_reviews(reviews)
            self.refresh_catalog()
            return 0
        def acquire_requested(requested_ids: list[int] | None = None) -> tuple[list[dict], list[int]]:
            pending_ids = line_ids if requested_ids is None else requested_ids
            acquired: list[dict] = []
            failed: list[int] = []
            for line_id in pending_ids:
                try:
                    acquired.append(
                        acquire_line(
                            line_id,
                            self.binary_path,
                            self.lines_dir,
                            self.state_dir / f"line-{line_id}.json.tmp",
                            session_path=self.session_path,
                            timeout=self.acquire_timeout,
                            lifetime_seconds=self.material_lifetime,
                        )
                    )
                except Exception as exc:  # noqa: BLE001
                    logging.warning("line %s acquisition failed: %s", line_id, exc)
                    failed.append(line_id)
            return acquired, failed

        materials, failed_line_ids = acquire_requested()
        if failed_line_ids and self.reauthenticate():
            logging.info(
                "source session refreshed; retrying %s failed line materials",
                len(failed_line_ids),
            )
            retried, _still_failed = acquire_requested(failed_line_ids)
            materials.extend(retried)
        if not materials:
            raise RuntimeError("no requested material could be acquired")
        result = request_json(
            self.base_url,
            self.source_id,
            self.source_secret,
            "POST",
            "/api/ultra/source/materials",
            {"upload_ticket": ticket, "materials": materials},
            self.timeout,
        )
        accepted = len(result.get("accepted_line_ids", [])) if isinstance(result.get("accepted_line_ids"), list) else 0
        self.process_reviews(reviews)
        self.refresh_catalog()
        return accepted

    def process_reviews(self, reviews: list[dict]) -> int:
        """Run the reviews the panel asked for and report each outcome back.

        Reviews are serial and happen after the material work, so a long review
        window cannot delay the line acquisitions that keep forwards alive.
        """
        if not reviews:
            return 0
        completed = 0
        for review in reviews[: max(0, self.max_reviews_per_cycle)]:
            review_id = int(review.get("review_id", 0) or 0)
            line_id = int(review.get("line_id", 0) or 0)
            seconds = int(review.get("seconds", 600) or 600)
            if review_id <= 0 or line_id <= 0:
                continue
            # The panel hands down the advertised relay set and the client's
            # target; without them the probe can only test an endpoint it picked
            # for itself, which is not evidence about this candidate.
            payload = self.run_review(
                review_id,
                line_id,
                seconds,
                advertised=review.get("relays"),
                target=str(review.get("target", "") or ""),
            )
            try:
                request_json(
                    self.base_url,
                    self.source_id,
                    self.source_secret,
                    "POST",
                    "/api/ultra/source/review",
                    payload,
                    self.timeout,
                )
                completed += 1
                detail = payload.get("detail") if isinstance(payload.get("detail"), dict) else {}
                proof = detail.get("proof") if isinstance(detail.get("proof"), dict) else {}
                # Local, secret-free evidence that the review was discriminating:
                # the panel records rebuttal=blocked unless the proof block names
                # an advertised endpoint and carried a real transfer, so this line
                # is how an operator tells a working deploy from a silent one.
                logging.info(
                    "review %s line %s passed=%s proof_reason=%s end_to_end_ok=%s zero_downstream=%s note=%s",
                    review_id,
                    line_id,
                    bool(payload.get("passed")),
                    str(proof.get("reason", "") or "none"),
                    proof.get("end_to_end_ok", 0),
                    detail.get("target_tcp_zero_downstream", 0),
                    str(payload.get("note", ""))[:160],
                )
            except Exception as exc:  # noqa: BLE001
                logging.warning("review %s could not be reported: %s", review_id, exc)
        return completed

    def run_review(
        self,
        review_id: int,
        line_id: int,
        seconds: int,
        advertised: object = None,
        target: str = "",
    ) -> dict:
        """Acquire the line and probe the client's target through an advertised relay.

        The probe's verdict requires a real transfer to the client's target over
        the relay, so a review that cannot be pointed at an advertised endpoint -
        or that has no client target - is reported as a non-discriminating result
        rather than run against an endpoint of the agent's own choosing.
        """
        target_text = str(target or "").strip()
        if not self.review_script.is_file():
            return {
                "review_id": review_id,
                "passed": False,
                "note": "review probe is not installed",
                "detail": non_discriminating_review_detail("review probe is not installed", target_text),
            }
        try:
            material = acquire_line(
                line_id,
                self.binary_path,
                self.lines_dir,
                self.state_dir / f"review-{line_id}.json.tmp",
                session_path=self.session_path,
                timeout=self.acquire_timeout,
                lifetime_seconds=self.material_lifetime,
            )
        except Exception as exc:  # noqa: BLE001
            note = f"line acquisition failed: {exc}"
            return {
                "review_id": review_id,
                "passed": False,
                "note": note,
                "detail": non_discriminating_review_detail(note, target_text),
            }
        relays = material.get("relays") if isinstance(material.get("relays"), list) else []
        mandate = advertised_relay_ips(advertised)
        relay, reason = select_review_relay(relays, mandate)
        if relay is None:
            return {
                "review_id": review_id,
                "passed": False,
                "note": reason,
                "detail": non_discriminating_review_detail(reason, target_text),
            }
        relay_text = f"{relay['ip']}:{relay['port']}"
        window = max(30, min(1800, seconds))
        command = [
            "python3",
            str(self.review_script),
            "--relay", relay_text,
            "--user", str(material.get("user", "")),
            "--password", str(material.get("credential", "")),
            "--seconds", str(window),
            "--interval", str(max(1.0, self.review_interval)),
            "--big-bytes", str(max(64, min(65000, self.review_big_bytes))),
            "--target", target_text,
            "--json",
        ]
        if mandate:
            command.extend(["--advertised", ",".join(mandate)])
        if self.review_require_big_packet:
            command.append("--require-big-packet")
        try:
            completed = subprocess.run(
                command,
                capture_output=True,
                text=True,
                timeout=window + 120,
                check=False,
            )
        except subprocess.TimeoutExpired:
            return {
                "review_id": review_id,
                "passed": False,
                "note": "review timed out",
                "detail": non_discriminating_review_detail("review timed out", target_text, relay_text),
            }
        except OSError as exc:
            note = f"review could not start: {exc}"
            return {
                "review_id": review_id,
                "passed": False,
                "note": note,
                "detail": non_discriminating_review_detail(note, target_text, relay_text),
            }
        detail: dict = {}
        for line in reversed((completed.stdout or "").strip().splitlines()):
            try:
                parsed = json.loads(line)
            except ValueError:
                continue
            if isinstance(parsed, dict):
                detail = parsed
                break
        if not detail:
            # An agent whose probe predates --target/--advertised exits on an
            # argparse error with no JSON at all; say so instead of reporting an
            # empty review the operator cannot explain.
            stderr_lines = [item for item in (completed.stderr or "").strip().splitlines() if item.strip()]
            note = f"review probe produced no result (exit {completed.returncode})"
            if stderr_lines:
                note = note + ": " + stderr_lines[-1].strip()
            note = note[:240]
            return {
                "review_id": review_id,
                "passed": False,
                "note": note,
                "detail": non_discriminating_review_detail(note, target_text, relay_text),
            }
        passed = bool(detail.get("passed")) and completed.returncode == 0
        note = str(detail.get("last_error", "") or "")[:240]
        return {"review_id": review_id, "passed": passed, "note": note, "detail": detail}
    def reauthenticate(self) -> bool:
        """Refresh the LeiGod session without destroying a working session."""
        if not self.login_mobile or not self.login_password_env or not self.login_binary_path.is_file():
            return False
        now = time.time()
        if now - self._last_login_at < self.login_retry:
            return False
        self._last_login_at = now
        environment = os.environ.copy()
        if self.login_home is not None:
            environment["HOME"] = str(self.login_home)
        command = [
            str(self.login_binary_path),
            "login",
            "--mobile",
            self.login_mobile,
            "--password-env",
            self.login_password_env,
            "--country",
            str(self.login_country),
            "--region",
            str(self.login_region),
        ]
        if self.login_config_path:
            command.extend(["--config", self.login_config_path])
        try:
            completed = subprocess.run(
                command,
                env=environment,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=self.login_timeout,
                check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            return False
        if completed.returncode != 0:
            return False

        candidates: list[Path] = []
        if self.login_home is not None:
            candidates.append(self.login_home / ".leigod" / "session.json")
        if self.session_path is not None:
            candidates.append(self.session_path)
        source = next((path for path in candidates if path.is_file()), None)
        if source is None or self.session_path is None:
            return False
        if source.resolve() == self.session_path.resolve():
            return self._harden_session_permissions()
        try:
            if source.stat().st_size <= 64 * 1024:
                self.session_path.parent.mkdir(parents=True, exist_ok=True)
                temporary = self.session_path.with_name(f".{self.session_path.name}.{os.getpid()}.tmp")
                shutil.copyfile(source, temporary)
                os.chmod(temporary, 0o600)
                os.replace(temporary, self.session_path)
                os.chmod(self.session_path, 0o600)
                return True
        except OSError:
            try:
                temporary.unlink()
            except (UnboundLocalError, FileNotFoundError):
                pass
        return False

    def _harden_session_permissions(self) -> bool:
        if self.session_path is None:
            return False
        try:
            if not self.session_path.is_file():
                return False
            os.chmod(self.session_path, 0o600)
            return True
        except OSError:
            return False

    def refresh_catalog(self) -> None:
        try:
            result = refresh_catalog_cache(
                self.lines_dir,
                self.binary_path,
                self.session_path,
                self.catalog_path,
                now=int(time.time()),
                refresh_seconds=self.catalog_refresh,
                retry_seconds=self.catalog_retry,
                max_queries=self.catalog_max_queries,
                timeout=self.catalog_timeout,
            )
            if result["attempted"] > 0:
                logging.info(
                    "catalog refresh completed; attempted=%s succeeded=%s changed=%s",
                    result["attempted"],
                    result["succeeded"],
                    result["changed"],
                )
        except Exception as exc:  # noqa: BLE001
            logging.warning("catalog refresh failed: %s", exc)

    def run(self, once: bool = False) -> None:
        failures = 0
        while True:
            try:
                accepted = self.run_cycle()
                failures = 0
                logging.info("source cycle completed; accepted=%s", accepted)
            except Exception as exc:  # noqa: BLE001
                failures += 1
                logging.warning("source cycle failed: %s", exc)
            if once:
                return
            backoff = min(300.0, self.poll_interval * (2 ** min(failures, 3)))
            time.sleep(max(5.0, backoff * random.uniform(0.75, 1.25)))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="/etc/ultra-source-agent.json")
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    SourceAgent(Path(args.config)).run(once=args.once)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
ULTRA_EOF
sudo sed -i 's/\r$//' /usr/local/bin/ultra-source-agent.py
sudo chmod 755 /usr/local/bin/ultra-source-agent.py
sudo chown root:root /usr/local/bin/ultra-source-agent.py
