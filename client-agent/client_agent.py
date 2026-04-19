#!/usr/bin/env python3
from __future__ import annotations

import argparse
import email.utils
import json
import logging
import os
import pathlib
import re
import shutil
import socket
import ssl
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

AGENT_VERSION = "2026.04.17.4"
UPDATE_RETRY_SECONDS = 300
UPDATE_STALE_SECONDS = 900
INVALID_FORWARD_LOG_INTERVAL_SECONDS = 600
DEFAULT_MANAGED_CONFIG = {
    "state_dir": "/var/lib/leikwan-client-agent",
    "poll_interval_seconds": 30,
    "health_check_interval_seconds": 20,
    "log_level": "INFO",
    "wireguard_listen_port": 0,
    "wireguard_fallback_tcp_port": 0,
}


def http_json(method: str, url: str, token: str, payload: dict | None = None) -> dict:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
    }
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        raise RuntimeError(f"http {exc.code} {url}: {body}") from exc


def http_json_with_headers(method: str, url: str, token: str, payload: dict | None = None) -> tuple[dict, dict[str, str]]:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
    }
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
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
    def __init__(self, config_path: pathlib.Path):
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
    def running_kernel_supports_mptcp() -> bool:
        return pathlib.Path("/proc/sys/net/mptcp/enabled").exists()

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

    def run(self) -> None:
        next_sync = 0.0
        next_health = 0.0
        while True:
            now = time.time()
            if now >= next_sync:
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
                next_sync = now + int(self.config.get("poll_interval_seconds", 30))

            if now >= next_health:
                try:
                    self.ensure_runtime_health(self.latest_forwards)
                except Exception as exc:  # noqa: BLE001
                    logging.exception("health check failed: %s", exc)
                next_health = now + int(self.config.get("health_check_interval_seconds", 20))

            time.sleep(5)

    def sync_once(self) -> None:
        master = self.config["master_url"].rstrip("/")
        token = self.config["agent_token"]
        payload, response_headers = http_json_with_headers("GET", f"{master}/api/client/configs/pull", token)
        forwards = payload.get("forwards", [])
        self.latest_forwards = [forward for forward in forwards if isinstance(forward, dict)]
        client_meta = payload.get("client", {})
        applied_forward_ids: list[int] = []
        skipped_invalid_forward_ids: list[int] = []
        inactive_forward_ids: list[int] = []
        try:
            self.apply_management_state(client_meta)
        except Exception as exc:  # noqa: BLE001
            logging.exception("failed to apply client management state: %s", exc)
        master = self.config["master_url"].rstrip("/")
        token = self.config["agent_token"]
        desired_ids = set()
        for forward in forwards:
            forward_id = int(forward["forward_id"])
            mode = self.normalize_mode(forward.get("mode", "single"))
            if str(forward.get("service_status", "active")) != "active":
                inactive_forward_ids.append(forward_id)
                self.stop_forward(forward_id)
                continue
            usable_endpoints = self.usable_endpoints(forward)
            if usable_endpoints == [] and self.normalize_mode(forward.get("mode", "single")) != "mptcp":
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
            if mode == "bond":
                bond_bin = self.bond_binary_path()
                if not self.binary_available(bond_bin):
                    skipped_invalid_forward_ids.append(forward_id)
                    self.log_invalid_forward(
                        forward_id,
                        "skipping bond forward because required binary is missing"
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
                allow_tcp_fallback = str(mptcp.get("allow_tcp_fallback", "")).strip().lower() in {"1", "true", "yes", "on"}
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
                self.apply_forward(forward)
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
        http_json(
            "POST",
            f"{master}/api/client/heartbeat",
            token,
            {
                "agent_version": AGENT_VERSION,
                "usage_bytes_in": usage_totals["bytes_in"],
                "usage_bytes_out": usage_totals["bytes_out"],
                "supports_mptcp": self.running_kernel_supports_mptcp(),
                "wireguard_public_key": wireguard_public_key,
                "wireguard_listen_port": self.wireguard_listen_port(),
                "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
                "clock_sync": local_clock,
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

        if explicit_request_token != "" and completed_request_token == explicit_request_token:
            applied = self.load_applied()
            if applied.get("_last_agent_update_request_token") != explicit_request_token:
                applied["_last_agent_update_request_token"] = explicit_request_token
                self.applied_path.write_text(json.dumps(applied, ensure_ascii=False, indent=2), encoding="utf-8")

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
        desired_agent_version = str(client_meta.get("desired_agent_version", "") or "").strip()
        desired_agent_sha256 = str(client_meta.get("desired_agent_sha256", "") or "").strip().lower()
        desired_installer_version = str(client_meta.get("desired_installer_version", "") or "").strip()
        desired_bond_sha256 = str(client_meta.get("desired_bond_sha256", "") or "").strip().lower()
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

        if desired_bond_sha256:
            local_bond_sha256 = file_sha256(self.bond_binary_path()).lower()
            if local_bond_sha256 != desired_bond_sha256:
                mismatches.append(f"bond={desired_bond_sha256}")

        if mismatches == []:
            return ""

        return "auto-release:" + "|".join(mismatches)

    def apply_config_patch(self, patch: object) -> None:
        if not isinstance(patch, dict):
            return

        allowed_keys = {
            "master_url",
            "poll_interval_seconds",
            "health_check_interval_seconds",
            "log_level",
            "state_dir",
        }
        config_changed = False
        for key, value in patch.items():
            if key not in allowed_keys:
                continue
            if value is None:
                if key not in DEFAULT_MANAGED_CONFIG:
                    continue
                value = DEFAULT_MANAGED_CONFIG[key]
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

    def apply_forward(self, forward: dict) -> None:
        from install_forward_runtime import render_forward_runtime  # local helper

        forward_id = int(forward["forward_id"])
        mode = self.normalize_mode(forward.get("mode", "single"))
        transport = str(forward.get("transport", "tcp+udp") or "tcp+udp").strip().lower()
        endpoints = [endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        bond_bin = self.bond_binary_path()
        mptcp_client = self.mptcp_client_path()
        logging.info(
            "applying forward %s mode=%s transport=%s endpoints=%s target=%s",
            forward_id,
            mode,
            transport,
            len(endpoints),
            str(forward.get("target", "")),
        )
        if mode == "bond" and not self.binary_available(bond_bin):
            logging.warning("forward %s requested bond mode but bond binary is missing at %s", forward_id, bond_bin)
        if mode == "mptcp" and not mptcp_client.is_file():
            logging.warning("forward %s requested mptcp mode but helper is missing at %s", forward_id, mptcp_client)

        render_forward = dict(forward)
        if mode == "bond":
            render_forward["endpoints"] = self.select_bond_runtime_endpoints(render_forward)

        rendered = render_forward_runtime(render_forward, self.config)
        self.delete_nft_rules(forward_id)
        self.add_nft_rules(forward_id, int(forward.get("listen_port", 0) or 0), transport)
        applied = self.load_applied()
        units = [rendered] + [item for item in rendered.get("extra_units", []) if isinstance(item, dict)]
        persisted_units: list[dict] = []

        for unit in units:
            service_name = str(unit["service_name"])
            service_path = pathlib.Path(str(unit["service_path"]))
            unit_changed = self.write_text_if_changed(service_path, str(unit["service_unit"]))
            start_script_path = pathlib.Path(str(unit["start_script_path"])) if str(unit.get("start_script_path", "")).strip() != "" else None
            script_changed = False
            if start_script_path is not None and str(unit.get("start_script", "")) != "":
                script_changed = self.write_text_if_changed(start_script_path, str(unit["start_script"]), mode=0o700)

            managed_paths = [str(service_path)]
            if start_script_path is not None:
                managed_paths.append(str(start_script_path))

            for file_entry in unit.get("files", []):
                extra_path = pathlib.Path(str(file_entry["path"]))
                self.write_text_if_changed(
                    extra_path,
                    str(file_entry.get("content", "")),
                    int(file_entry["mode"]) if "mode" in file_entry and file_entry.get("mode") is not None else None,
                )
                managed_paths.append(str(extra_path))

            if unit_changed or script_changed or not self.systemd_is_active(service_name):
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

        applied[str(forward_id)] = {
            "units": persisted_units,
            "managed_paths": sorted({path for unit in persisted_units for path in unit["managed_paths"]}),
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

        self.delete_nft_rules(forward_id)
        if delete_files:
            if isinstance(previous, dict):
                for managed_path_raw in previous.get("managed_paths", []):
                    managed_path = pathlib.Path(str(managed_path_raw))
                    if (
                        managed_path == pathlib.Path(f"/etc/systemd/system/leikwan-client-{forward_id}.service")
                        or managed_path == pathlib.Path(f"/usr/local/bin/leikwan-client-start-{forward_id}.sh")
                    ):
                        continue
                    if managed_path.exists():
                        managed_path.unlink()
            subprocess.run("systemctl daemon-reload", shell=True, check=False)

    def ensure_runtime_health(self, forwards: list[dict]) -> None:
        if not forwards:
            return

        for forward in forwards:
            if str(forward.get("service_status", "active")) != "active":
                continue

            forward_id = int(forward.get("forward_id", 0) or 0)
            if forward_id <= 0:
                continue

            service_name = f"leikwan-client-{forward_id}"
            if not self.systemd_is_active(service_name):
                self.restart_forward_service(forward_id, service_name, "systemd inactive")
                continue

            endpoints = [endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
            mode = self.normalize_mode(forward.get("mode", "single"))
            if mode == "bond" and self.service_status_matches_bond_restart_rule(service_name):
                self.restart_forward_service(forward_id, service_name, "bond status matched reset warning rule")
                continue
            if mode == "mptcp":
                if self.mptcp_setup_in_progress(forward_id):
                    logging.info("forward %s mptcp setup-underlays still in progress; skipping health probe", forward_id)
                    continue
                listen_port = int(forward.get("listen_port", 0) or 0)
                local_ok, _, local_error = self.local_listener_present(listen_port, "tcp")
                if not local_ok:
                    self.restart_forward_service(forward_id, service_name, f"mptcp local listener probe failed: {local_error}")
                    continue
                mptcp = forward.get("mptcp", {})
                if not isinstance(mptcp, dict):
                    mptcp = {}
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
            plain_tcp_ingress = mode == "bond" and str(bonding.get("ingress_mode", "wss_path_mux")) == "plain_tcp_mux"
            for endpoint in endpoints:
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
                ready_results.append(ready)
                if mode == "bond":
                    endpoint_id = int(endpoint.get("endpoint_id", 0) or 0)
                    previous_role = (
                        self.load_bond_endpoint_health()
                        .get(str(forward_id), {})
                        .get(str(endpoint_id), {})
                        .get("role", "healthy")
                    )
                    updated = self.record_bond_endpoint_probe_result(
                        forward_id,
                        endpoint_id,
                        ready,
                        latency_ms,
                        error,
                    )
                    if str(previous_role or "healthy") != str(updated.get("role", "healthy") or "healthy"):
                        self.refresh_bond_runtime_endpoints(forward_id, dict(forward), service_name)

            if mode in {"race", "bond"}:
                if not any(ready_results):
                    self.restart_forward_service(forward_id, service_name, "all slave endpoints failed ready probe")
                    continue

                if mode == "bond" and plain_tcp_ingress:
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

    def record_bond_endpoint_probe_result(
        self,
        forward_id: int,
        endpoint_id: int,
        ok: bool,
        latency_ms: float | None,
        error: str | None,
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
            },
        )

        if ok:
            entry["consecutive_successes"] = int(entry.get("consecutive_successes", 0) or 0) + 1
            entry["consecutive_failures"] = 0
            entry["last_error"] = ""
            entry["last_latency_ms"] = latency_ms
            if float(entry.get("quarantine_until", 0.0) or 0.0) > 0:
                entry["role"] = "probation"
                entry["probation_until"] = max(float(entry.get("probation_until", 0.0) or 0.0), now + 60.0)
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
                entry["quarantine_until"] = now + 90.0
                entry["probation_until"] = 0.0
            elif int(entry["consecutive_failures"]) >= 2:
                entry["role"] = "probation"
                entry["probation_until"] = max(float(entry.get("probation_until", 0.0) or 0.0), now + 60.0)

        self.save_bond_endpoint_health(health)
        return entry

    def select_bond_runtime_endpoints(self, forward: dict, now: float | None = None) -> list[dict]:
        now = time.time() if now is None else now
        endpoints = [dict(endpoint) for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
        bonding = forward.get("bonding", {})
        if not isinstance(bonding, dict):
            bonding = {}
        base_priority = int(bonding.get("ws_priority", 10) or 10)
        health = self.load_bond_endpoint_health().get(str(int(forward.get("forward_id", 0) or 0)), {})

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
            if role == "healthy":
                endpoint["dynamic_priority"] = base_priority
            elif role == "probation":
                endpoint["dynamic_priority"] = base_priority + 15
            else:
                endpoint["dynamic_priority"] = base_priority + 30
            decorated.append(endpoint)

        preferred = [item for item in decorated if item["health_role"] != "quarantined"]
        if preferred:
            preferred.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
            return preferred

        decorated.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
        return decorated[:1]

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
                mode = self.normalize_mode(forward.get("mode", "single"))
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
                    port = int(mptcp.get("sidecar_port", 0) or mptcp.get("remote_port", 0) or 0)
                    for index, host in enumerate(self.mptcp_remote_hosts(mptcp), start=1):
                        reachable, latency_ms, err = self.remote_tcp_probe(host, port)
                        endpoint_results.append({
                            "endpoint_id": index,
                            "host": host,
                            "port": port,
                            "reachable": reachable,
                            "latency_ms": latency_ms,
                            "error": err,
                            "segment": "client_sidecar",
                        })
                else:
                    bonding = forward.get("bonding", {})
                    if not isinstance(bonding, dict):
                        bonding = {}
                    plain_tcp_ingress = self.normalize_mode(forward.get("mode", "single")) == "bond" and str(bonding.get("ingress_mode", "wss_path_mux")) == "plain_tcp_mux"
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
            total_subflow_limit = max(total_subflow_limit, max(1, int(mptcp.get("subflow_limit", 0) or 0)))

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
        mptcp_kernel_supported = self.running_kernel_supports_mptcp()
        wireguard_public_key = self.ensure_wireguard_identity()
        summary = {
            "ok": True,
            "agent_version": AGENT_VERSION,
            "config_path": str(self.config_path),
            "state_dir": str(self.state_dir),
            "master_url": str(self.config.get("master_url", "")),
            "client_id": int(self.config.get("client_id", 0) or 0),
            "service_active": self.systemd_is_active("leikwan-client-agent"),
            "update_state": self.load_update_state(),
            "pull_state": self.load_pull_state(),
            "applied": self.load_applied(),
            "runtime": {
                "bond_bin": str(bond_bin),
                "bond_bin_present": bond_available,
                "mptcp_client_bin": str(mptcp_client),
                "mptcp_client_bin_present": mptcp_client_present,
                "mptcp_kernel_supported": mptcp_kernel_supported,
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
                    if self.normalize_mode(forward.get("mode", "single")) == "bond"
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
                    summary["live_pull"]["bond_dependency_error"] = f"bond mode forwards require {bond_bin}"
                if mptcp_forward_ids and not mptcp_client_present:
                    summary["ok"] = False
                    summary["live_pull"]["mptcp_dependency_error"] = f"mptcp mode forwards require {mptcp_client}"
                elif mptcp_forward_ids and not mptcp_kernel_supported:
                    summary["ok"] = False
                    summary["live_pull"]["mptcp_dependency_error"] = "mptcp mode forwards require a running kernel with CONFIG_MPTCP and /proc/sys/net/mptcp/enabled"
            except Exception as exc:  # noqa: BLE001
                summary["ok"] = False
                summary["live_pull"] = {
                    "ok": False,
                    "error": str(exc),
                }

        return summary


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
