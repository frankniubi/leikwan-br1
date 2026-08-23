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
import secrets
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


AGENT_VERSION = "2026.08.24.1"
MAX_INVENTORY_BYTES = 256 * 1024


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
    canonical = "\n".join(
        [
            method.upper(),
            path,
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
        elif key == "speed_ip":
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
    for raw in raw_lines[:1024]:
        if not isinstance(raw, dict):
            continue
        try:
            line_id = int(raw.get("line_id", 0) or 0)
        except (TypeError, ValueError):
            continue
        if 0 < line_id <= 45535:
            records[line_id] = raw
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


def build_inventory(lines_dir: Path, index_path: Path, *, max_lines: int = 256) -> dict[str, object]:
    limit = max(1, min(256, int(max_lines)))
    index = _inventory_index(index_path)
    candidates: list[tuple[int, Path]] = []
    for path in lines_dir.glob("*.toml"):
        try:
            line_id = int(path.stem)
        except ValueError:
            continue
        if 0 < line_id <= 45535 and path.is_file():
            candidates.append((line_id, path))
    candidates.sort(key=lambda item: item[0])
    complete = len(candidates) <= limit
    normalized: list[dict[str, object]] = []
    for line_id, path in candidates[:limit]:
        raw = path.read_bytes()
        metadata = _parse_line_config(raw)
        indexed = index.get(line_id, {})
        line: dict[str, object] = {
            "line_id": line_id,
            "title": _bounded_text(indexed.get("title") or indexed.get("name") or indexed.get("label")),
            "family_key": _bounded_text(indexed.get("family_key") or indexed.get("family")),
            "game_id": _unsigned_int(metadata.get("game_id"), indexed.get("game_id")),
            "area_id": _unsigned_int(metadata.get("area_id"), indexed.get("area_id")),
            "line_type": _unsigned_int(metadata.get("line_type"), indexed.get("line_type")),
            "discovery_ip": _literal_ip(indexed.get("speed_ip"), indexed.get("discovery_ip"), metadata.get("speed_ip")),
            "config_sha256": hashlib.sha256(raw).hexdigest(),
        }
        normalized.append(line)
    fingerprint_input = json.dumps(
        {"complete": complete, "lines": normalized},
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    payload: dict[str, object] = {
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


class SourceAgent:
    def __init__(self, config_path: Path):
        self.config = json.loads(config_path.read_text(encoding="utf-8-sig"))
        self.base_url = str(self.config["panel_url"]).rstrip("/")
        if urllib.parse.urlparse(self.base_url).scheme != "https":
            raise RuntimeError("panel_url must use HTTPS")
        self.source_id = str(self.config["source_id"])
        self.source_secret = str(self.config["source_secret"])
        self.binary_path = Path(self.config.get("binary_path", "/opt/ultra-source/runtime/line-client"))
        self.lines_dir = Path(self.config.get("lines_dir", "/opt/ultra-source/lines"))
        self.state_dir = Path(self.config.get("state_dir", "/var/lib/ultra-source-agent"))
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.inventory_path = Path(self.config.get("inventory_path", str(self.lines_dir / "index.json")))
        self.inventory_refresh = max(300, min(86400, int(self.config.get("inventory_refresh_seconds", 1800))))
        self.max_inventory_lines = max(1, min(256, int(self.config.get("max_inventory_lines", 256))))
        self.inventory_state_path = self.state_dir / "inventory-state.json"
        configured_session = str(self.config.get("session_path", "") or "").strip()
        self.session_path = Path(configured_session) if configured_session else None
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
            "/api/ultra/source/jobs",
            None,
            self.timeout,
            attempts=3,
        )
        ticket = str(jobs.get("upload_ticket", "") or "")
        line_ids = []
        for raw_line_id in jobs.get("line_ids", []) if isinstance(jobs.get("line_ids"), list) else []:
            line_id = int(raw_line_id or 0)
            if 0 < line_id <= 45535 and line_id not in line_ids:
                line_ids.append(line_id)
        line_ids = line_ids[: self.max_lines]
        if not line_ids:
            return 0
        materials = []
        for line_id in line_ids:
            try:
                materials.append(
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
        return len(result.get("accepted_line_ids", [])) if isinstance(result.get("accepted_line_ids"), list) else 0

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
