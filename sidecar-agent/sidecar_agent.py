#!/usr/bin/env python3
from __future__ import annotations

import argparse
import email.utils
import hashlib
import json
import logging
import os
import pathlib
import shutil
import socket
import sys
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

AGENT_VERSION = "2026.08.04.1"
UPDATE_RETRY_SECONDS = 300
UPDATE_STALE_SECONDS = 900
DEFAULT_CONFIG = {
    "master_url": "",
    "agent_token": "",
    "state_dir": "/var/lib/leikwan-sidecar",
    "service_name": "leikwan-bond-sidecar",
    "runtime_config_path": "/etc/leikwan-sidecar/runtime.json",
    "runtime_bin": "/usr/local/bin/leikwan-mptcp-sidecar.py",
    "heartbeat_interval_seconds": 30,
    "log_level": "INFO",
    "allow_tcp_fallback": False,
    "underlay": {},
    "wireguard_listen_port": 14969,
    "wireguard_fallback_tcp_port": 24969,
    "installer_version": "",
}


def file_sha256(path: pathlib.Path) -> str:
    if not path.exists():
        return ""
    hasher = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            hasher.update(chunk)
    return hasher.hexdigest()


def http_json(method: str, url: str, token: str, payload: dict | None = None) -> dict:
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "User-Agent": f"Leikwan-Sidecar-Agent/{AGENT_VERSION}",
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
        "User-Agent": f"Leikwan-Sidecar-Agent/{AGENT_VERSION}",
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


def load_config(path: pathlib.Path) -> dict:
    data = dict(DEFAULT_CONFIG)
    if path.exists():
        loaded = json.loads(path.read_text(encoding="utf-8-sig"))
        if isinstance(loaded, dict):
            data.update(loaded)
    return data


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
        fd, temp_name = tempfile.mkstemp(prefix=path.name + ".", suffix=".tmp", dir=str(path.parent))
        temp_path = pathlib.Path(temp_name)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                handle.write(content)
                handle.flush()
                os.fsync(handle.fileno())
            if mode is not None and os.name != "nt":
                temp_path.chmod(mode)
            temp_path.replace(path)
        finally:
            if temp_path.exists():
                temp_path.unlink()
    if mode is not None and path.exists() and os.name != "nt":
        current_mode = path.stat().st_mode & 0o777
        if current_mode != mode:
            path.chmod(mode)
            changed = True
    return changed


class SidecarAgent:
    def __init__(self, config_path: pathlib.Path):
        self.config_path = config_path
        self.config = load_config(config_path)
        self.state_dir = pathlib.Path(str(self.config.get("state_dir", DEFAULT_CONFIG["state_dir"])))
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self.runtime_stats_path = self.state_dir / "runtime-stats.json"
        self.pull_state_path = self.state_dir / "pull-state.json"
        logging.basicConfig(
            level=getattr(logging, str(self.config.get("log_level", "INFO")).upper(), logging.INFO),
            format="%(asctime)s %(levelname)s %(message)s",
        )

    def wireguard_dir(self) -> pathlib.Path:
        path = self.state_dir / "wireguard"
        path.mkdir(parents=True, exist_ok=True)
        return path

    def wireguard_private_key_path(self) -> pathlib.Path:
        return self.wireguard_dir() / "private.key"

    def wireguard_public_key_path(self) -> pathlib.Path:
        return self.wireguard_dir() / "public.key"

    def wireguard_listen_port(self) -> int:
        configured = int(self.config.get("wireguard_listen_port", DEFAULT_CONFIG["wireguard_listen_port"]) or DEFAULT_CONFIG["wireguard_listen_port"])
        return configured if configured > 0 else int(DEFAULT_CONFIG["wireguard_listen_port"])

    def wireguard_fallback_tcp_port(self) -> int:
        configured = int(self.config.get("wireguard_fallback_tcp_port", DEFAULT_CONFIG["wireguard_fallback_tcp_port"]) or DEFAULT_CONFIG["wireguard_fallback_tcp_port"])
        return configured if configured > 0 else int(DEFAULT_CONFIG["wireguard_fallback_tcp_port"])

    @staticmethod
    def running_kernel_supports_mptcp() -> bool:
        return pathlib.Path("/proc/sys/net/mptcp/enabled").exists()

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
        if shutil.which("wg") is None:
            return ""
        if not private_key_path.exists():
            result = subprocess.run(["wg", "genkey"], text=True, capture_output=True, check=False)
            private_key = result.stdout.strip()
            if result.returncode != 0 or private_key == "":
                return ""
            write_text_if_changed(private_key_path, private_key + "\n", mode=0o600)
        try:
            private_key = private_key_path.read_text(encoding="utf-8").strip()
        except OSError:
            logging.exception("WireGuard identity degraded: private key is unreadable")
            return ""
        if private_key == "":
            return ""
        result = subprocess.run(["wg", "pubkey"], input=private_key + "\n", text=True, capture_output=True, check=False)
        public_key = result.stdout.strip()
        if result.returncode != 0 or public_key == "":
            return ""
        write_text_if_changed(public_key_path, public_key + "\n", mode=0o644)
        return public_key


    def self_leg_client_peer_state_path(self) -> pathlib.Path:
        return self.state_dir / "self-leg-client-peer-state.json"

    def load_self_leg_client_peer_state(self) -> dict:
        path = self.self_leg_client_peer_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_self_leg_client_peer_state(self, payload: dict) -> None:
        self.self_leg_client_peer_state_path().write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def self_leg_client_interfaces_dir(self) -> pathlib.Path:
        path = self.wireguard_dir() / "self-leg-clients"
        path.mkdir(parents=True, exist_ok=True)
        return path

    @staticmethod
    def self_leg_client_system_path(interface_name: str) -> pathlib.Path:
        return pathlib.Path("/etc/wireguard") / f"{interface_name}.conf"

    def apply_self_leg_client_peer(self, peer: dict[str, object]) -> None:
        if not peer.get("enabled", True) or shutil.which("wg") is None or shutil.which("wg-quick") is None:
            return
        self.ensure_wireguard_identity()
        try:
            private_key = self.wireguard_private_key_path().read_text(encoding="utf-8").strip()
        except OSError:
            logging.exception("self-leg peer skipped: WireGuard private key is unavailable")
            return
        if private_key == "":
            logging.error("self-leg peer skipped: WireGuard private key is empty")
            return
        interface_name = str(peer.get("interface_name", "") or "").strip()[:15]
        local_address = str(peer.get("sidecar_address", "") or peer.get("local_address", "") or "").strip()
        client_address = str(peer.get("client_address", "") or peer.get("peer_address", "") or "").strip()
        client_public_key = str(peer.get("client_public_key", "") or "").strip()
        listen_port = max(0, int(peer.get("listen_port", 0) or 0))
        keepalive = max(0, int(peer.get("persistent_keepalive", peer.get("peer_keepalive_seconds", 25)) or 0))
        if interface_name == "" or local_address == "" or client_address == "" or client_public_key == "":
            return
        keepalive_line = f"PersistentKeepalive = {keepalive}\n" if keepalive > 0 else ""
        config = (
            "[Interface]\n"
            f"PrivateKey = {private_key}\n"
            + (f"ListenPort = {listen_port}\n" if listen_port > 0 else "")
            + f"Address = {local_address}/32\n"
            "\n"
            "[Peer]\n"
            f"PublicKey = {client_public_key}\n"
            f"AllowedIPs = {client_address}/32\n"
            f"{keepalive_line}"
        )
        path = self.self_leg_client_interfaces_dir() / f"{interface_name}.conf"
        changed = write_text_if_changed(path, config, mode=0o600)
        system_path = self.self_leg_client_system_path(interface_name)
        system_changed = write_text_if_changed(system_path, config, mode=0o600)
        if shutil.which("systemctl") is not None:
            service_name = f"wg-quick@{interface_name}"
            service_active = self.systemd_is_active(service_name)
            if changed or system_changed:
                subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
            if changed or system_changed or not service_active:
                subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)

    def remove_self_leg_client_peer(self, peer: dict[str, object]) -> None:
        interface_name = str(peer.get("interface_name", "") or "").strip()[:15]
        if interface_name == "":
            return
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"wg-quick@{interface_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"wg-quick@{interface_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "reset-failed", f"wg-quick@{interface_name}.service"], text=True, capture_output=True, check=False)
        path = self.self_leg_client_interfaces_dir() / f"{interface_name}.conf"
        if path.exists():
            path.unlink()
        system_path = self.self_leg_client_system_path(interface_name)
        if system_path.exists():
            system_path.unlink()

    def load_runtime_stats(self) -> dict:
        if not self.runtime_stats_path.exists():
            return {}
        try:
            payload = json.loads(self.runtime_stats_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def load_bond_leg_reputation_snapshot(self) -> dict | None:
        path = self.state_dir / "bond-leg-health.json"
        if not path.exists():
            return None
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except Exception:  # noqa: BLE001
            return None
        return payload if isinstance(payload, dict) else None

    def save_pull_state(self, payload: dict) -> None:
        self.pull_state_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def donation_reverse_probe_targets_path(self) -> pathlib.Path:
        return self.state_dir / "donation-reverse-probe-targets.json"

    def load_donation_reverse_probe_targets(self) -> list[dict]:
        path = self.donation_reverse_probe_targets_path()
        if not path.exists():
            return []
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            logging.warning("invalid donation reverse probe targets file: %s", path)
            return []
        targets = payload.get("targets", []) if isinstance(payload, dict) else payload
        return [item for item in targets if isinstance(item, dict)] if isinstance(targets, list) else []

    def save_donation_reverse_probe_targets(self, targets: list[dict]) -> None:
        self.donation_reverse_probe_targets_path().write_text(json.dumps({"targets": targets}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def sidecar_uplink_state_path(self) -> pathlib.Path:
        return self.state_dir / "slave-sidecar-uplink-state.json"

    def load_sidecar_uplink_state(self) -> dict:
        path = self.sidecar_uplink_state_path()
        if not path.exists():
            return {}
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_sidecar_uplink_state(self, payload: dict) -> None:
        self.sidecar_uplink_state_path().write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def sidecar_uplink_interfaces_dir(self) -> pathlib.Path:
        path = self.wireguard_dir() / "interfaces"
        path.mkdir(parents=True, exist_ok=True)
        return path

    @staticmethod
    def sidecar_uplink_system_path(interface_name: str) -> pathlib.Path:
        return pathlib.Path("/etc/wireguard") / f"{interface_name}.conf"

    def validate_shared_sidecar_uplinks(self, uplinks: list[dict[str, object]]) -> tuple[str, int]:
        if not uplinks:
            raise ValueError("shared sidecar uplink group is empty")
        interface_name = str(uplinks[0].get("interface_name", "") or "").strip()[:15]
        listen_port = int(uplinks[0].get("listen_port", 0) or 0)
        point_id = int(uplinks[0].get("sidecar_point_id", 0) or 0)
        if interface_name == "" or listen_port <= 0 or point_id <= 0:
            raise ValueError("shared sidecar uplink identity is incomplete")

        peer_keys: set[str] = set()
        interface_addresses: set[str] = set()
        allowed_ips: set[str] = set()
        for uplink in uplinks:
            if str(uplink.get("interface_name", "") or "").strip()[:15] != interface_name:
                raise ValueError("shared sidecar interface mismatch")
            if int(uplink.get("listen_port", 0) or 0) != listen_port:
                raise ValueError("shared sidecar listen port mismatch")
            if int(uplink.get("sidecar_point_id", 0) or 0) != point_id:
                raise ValueError("shared sidecar point mismatch")
            if str(uplink.get("outer_transport", "udp") or "udp").strip().lower() != "udp":
                raise ValueError("shared sidecar canary only supports direct UDP outer transport")

            peer_key = str(uplink.get("slave_peer_public_key", "") or "").strip()
            local_address = str(uplink.get("local_address", "") or "").strip()
            peer_address = str(uplink.get("peer_address", "") or "").strip()
            if peer_key == "" or local_address == "" or peer_address == "":
                raise ValueError("shared sidecar peer is incomplete")
            if peer_key in peer_keys:
                raise ValueError("duplicate shared sidecar peer public key")
            if local_address in interface_addresses:
                raise ValueError("duplicate shared sidecar interface address")
            if peer_address in allowed_ips:
                raise ValueError("duplicate shared sidecar peer AllowedIP")
            peer_keys.add(peer_key)
            interface_addresses.add(local_address)
            allowed_ips.add(peer_address)

            for raw_address in uplink.get("overlay_local_addresses", []) or []:
                address = str(raw_address).strip()
                if address != "":
                    interface_addresses.add(address)
            for raw_address in uplink.get("overlay_peer_allowed_ips", []) or []:
                address = str(raw_address).strip()
                if address == "":
                    continue
                if address in allowed_ips:
                    raise ValueError("overlapping shared sidecar peer AllowedIP")
                allowed_ips.add(address)
        return interface_name, listen_port

    def render_shared_sidecar_wireguard_config(self, uplinks: list[dict[str, object]], private_key: str) -> str:
        _, listen_port = self.validate_shared_sidecar_uplinks(uplinks)
        if private_key.strip() == "":
            raise ValueError("shared sidecar private key is empty")

        ordered = sorted(
            uplinks,
            key=lambda item: (
                int(item.get("node_id", 0) or 0),
                str(item.get("slave_peer_public_key", "") or ""),
            ),
        )
        interface_addresses: list[str] = []
        for uplink in ordered:
            candidates = [uplink.get("local_address", ""), *(uplink.get("overlay_local_addresses", []) or [])]
            for raw_address in candidates:
                address = str(raw_address).strip()
                if address != "" and address not in interface_addresses:
                    interface_addresses.append(address)

        lines = ["[Interface]", f"PrivateKey = {private_key.strip()}", f"ListenPort = {listen_port}"]
        lines.extend(f"Address = {address}/32" for address in interface_addresses)
        for uplink in ordered:
            peer_address = str(uplink.get("peer_address", "") or "").strip()
            allowed = [peer_address]
            for raw_address in uplink.get("overlay_peer_allowed_ips", []) or []:
                address = str(raw_address).strip()
                if address != "" and address not in allowed:
                    allowed.append(address)
            keepalive = max(0, int(uplink.get("peer_keepalive_seconds", 0) or 0))
            lines.extend([
                "",
                "[Peer]",
                f"PublicKey = {str(uplink.get('slave_peer_public_key', '') or '').strip()}",
                "AllowedIPs = " + ", ".join(f"{address}/32" for address in allowed),
            ])
            if keepalive > 0:
                lines.append(f"PersistentKeepalive = {keepalive}")
        return "\n".join(lines) + "\n"

    def apply_shared_slave_sidecar_interface(self, uplinks: list[dict[str, object]]) -> None:
        interface_name, _ = self.validate_shared_sidecar_uplinks(uplinks)
        if shutil.which("wg") is None or shutil.which("wg-quick") is None:
            raise RuntimeError("WireGuard tools are unavailable")
        self.ensure_wireguard_identity()
        try:
            private_key = self.wireguard_private_key_path().read_text(encoding="utf-8").strip()
        except OSError as exc:
            raise RuntimeError(f"shared slave-sidecar interface {interface_name} private key is unavailable") from exc
        config = self.render_shared_sidecar_wireguard_config(uplinks, private_key)
        path = self.sidecar_uplink_interfaces_dir() / f"{interface_name}.conf"
        system_path = self.sidecar_uplink_system_path(interface_name)
        with tempfile.TemporaryDirectory(prefix=f"{interface_name}-", dir=str(self.state_dir)) as staging_dir:
            candidate_path = pathlib.Path(staging_dir) / f"{interface_name}.conf"
            candidate_path.write_text(config, encoding="utf-8")
            if os.name != "nt":
                candidate_path.chmod(0o600)
            validation = subprocess.run(
                ["wg-quick", "strip", str(candidate_path)],
                text=True,
                capture_output=True,
                check=False,
            )
            if validation.returncode != 0:
                raise RuntimeError(
                    f"wg-quick validation failed for {interface_name}: "
                    f"{validation.stderr or validation.stdout}"
                )

        def snapshot(target: pathlib.Path) -> tuple[str, int] | None:
            if not target.exists():
                return None
            return target.read_text(encoding="utf-8"), target.stat().st_mode & 0o777

        def restore(target: pathlib.Path, previous: tuple[str, int] | None) -> None:
            if previous is None:
                if target.exists():
                    target.unlink()
                return
            previous_content, previous_mode = previous
            write_text_if_changed(target, previous_content, mode=previous_mode)

        previous_state = snapshot(path)
        previous_system = snapshot(system_path)
        service_name = f"wg-quick@{interface_name}"
        service_active = shutil.which("systemctl") is not None and self.systemd_is_active(service_name)
        service_enabled = False
        if shutil.which("systemctl") is not None:
            service_enabled = subprocess.run(
                ["systemctl", "is-enabled", f"{service_name}.service"],
                text=True,
                capture_output=True,
                check=False,
            ).returncode == 0
        try:
            changed = write_text_if_changed(path, config, mode=0o600)
            system_changed = write_text_if_changed(system_path, config, mode=0o600)
            if shutil.which("systemctl") is None:
                raise RuntimeError("systemctl is unavailable")
            if changed or system_changed or not service_active:
                enabled = subprocess.run(
                    ["systemctl", "enable", f"{service_name}.service"],
                    text=True,
                    capture_output=True,
                    check=False,
                )
                if enabled.returncode != 0:
                    raise RuntimeError(
                        f"systemctl enable failed for {service_name}: {enabled.stderr or enabled.stdout}"
                    )
                started = subprocess.run(
                    ["systemctl", "restart" if service_active else "start", f"{service_name}.service"],
                    text=True,
                    capture_output=True,
                    check=False,
                )
                if started.returncode != 0:
                    raise RuntimeError(
                        f"systemctl {'restart' if service_active else 'start'} failed for {service_name}: "
                        f"{started.stderr or started.stdout}"
                    )
        except Exception:
            restore(path, previous_state)
            restore(system_path, previous_system)
            if service_active and previous_system is not None:
                restored = subprocess.run(
                    ["systemctl", "restart", f"{service_name}.service"],
                    text=True,
                    capture_output=True,
                    check=False,
                )
                if restored.returncode != 0:
                    logging.error(
                        "failed to restore previous shared WireGuard interface %s: %s",
                        interface_name,
                        restored.stderr or restored.stdout,
                    )
            elif previous_system is None:
                subprocess.run(
                    ["systemctl", "stop", f"{service_name}.service"],
                    text=True,
                    capture_output=True,
                    check=False,
                )
            if not service_enabled:
                subprocess.run(
                    ["systemctl", "disable", f"{service_name}.service"],
                    text=True,
                    capture_output=True,
                    check=False,
                )
            subprocess.run(
                ["systemctl", "reset-failed", f"{service_name}.service"],
                text=True,
                capture_output=True,
                check=False,
            )
            raise

    @staticmethod
    def static_sidecar_point_id_from_interface(interface_name: str) -> int:
        normalized = str(interface_name or "").strip()[:15]
        offset = 4 if normalized.startswith("lwsp") else 3 if normalized.startswith("lws") else 0
        if offset == 0:
            return 0
        digits = ""
        for char in normalized[offset:]:
            if not char.isdigit():
                break
            digits += char
        return int(digits) if digits else 0

    def apply_slave_sidecar_uplink(self, uplink: dict[str, object]) -> None:
        if not uplink.get("enabled") or shutil.which("wg") is None or shutil.which("wg-quick") is None:
            return
        self.ensure_wireguard_identity()
        try:
            private_key = self.wireguard_private_key_path().read_text(encoding="utf-8").strip()
        except OSError:
            logging.exception("slave-sidecar uplink skipped: WireGuard private key is unavailable")
            return
        if private_key == "":
            logging.error("slave-sidecar uplink skipped: WireGuard private key is empty")
            return
        interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
        local_address = str(uplink.get("local_address", "") or "").strip()
        peer_address = str(uplink.get("peer_address", "") or "").strip()
        peer_public_key = str(uplink.get("slave_peer_public_key", "") or "").strip()
        listen_port = max(0, int(uplink.get("listen_port", 0) or 0))
        outer_transport = str(uplink.get("outer_transport", "udp") or "udp").strip().lower()
        peer_tcp_fallback_port = max(0, int(uplink.get("peer_tcp_fallback_port", 0) or 0))
        keepalive = max(0, int(uplink.get("peer_keepalive_seconds", 0) or 0))
        cf_path_prefix = str(uplink.get("cf_path_prefix", "") or "").strip()
        cf_target_host = str(uplink.get("cf_target_host", "") or "").strip()
        cf_target_port = max(0, int(uplink.get("cf_target_port", 0) or 0))
        if interface_name == "" or local_address == "" or peer_address == "" or peer_public_key == "":
            return
        if outer_transport == "tcp" and peer_tcp_fallback_port > 0 and listen_port > 0:
            self.ensure_sidecar_uot_server_service(interface_name, peer_tcp_fallback_port, listen_port)
        else:
            self.remove_sidecar_uot_server_service(interface_name)
        if cf_path_prefix != "" and cf_target_host != "" and cf_target_port > 0:
            try:
                self.ensure_sidecar_cf_wstunnel_backend(interface_name, cf_path_prefix, cf_target_host, cf_target_port)
            except Exception as exc:  # noqa: BLE001
                logging.exception("slave-sidecar uplink %s skipped CF backend reconcile: %s", interface_name, exc)
        else:
            self.remove_sidecar_cf_wstunnel_backend(interface_name)
        # LeiGod basis: terminate the slave's gost UDP-over-TCP carrier. The slave dials this gost
        # SOCKS server through its LeiGod line; the server delivers the unwrapped UDP to the local WG
        # ListenPort below, exactly like the legacy tcp/UoT server path.
        gost_socks_listen_port = max(0, int(uplink.get("gost_socks_listen_port", 0) or 0))
        socks_user = str(uplink.get("socks_user", "") or "").strip()
        socks_pass = str(uplink.get("socks_pass", "") or "").strip()
        # leigod_uot_impl selects the UoT carrier for this lane (must match the slave side, the panel
        # publishes the same value to both): "gost" (default) or "lguot" (CPU-efficient).
        leigod_uot_impl = str(uplink.get("leigod_uot_impl", "gost") or "gost").strip().lower()
        # MPTCP overlay routing across the leigod hop: the sidecar must OWN each overlay
        # sidecar address (extra Interface Address) so its 0.0.0.0 MPTCP listener receives
        # the subflow, and carry each overlay client address in the peer AllowedIPs so the
        # return path routes back over this uplink. Without this the overlay blackholes out
        # the sidecar's public default route (the data-path gap that broke leigod_mode MPTCP).
        overlay_peer_allowed_ips = [
            str(ip).strip() for ip in (uplink.get("overlay_peer_allowed_ips") or [])
            if str(ip).strip() != ""
        ]
        overlay_local_addresses = [
            str(ip).strip() for ip in (uplink.get("overlay_local_addresses") or [])
            if str(ip).strip() != ""
        ]
        if outer_transport == "leigod" and gost_socks_listen_port > 0 and listen_port > 0 and socks_user != "" and socks_pass != "":
            if leigod_uot_impl == "lguot":
                # lguot server terminates the slave's framed-UDP stream and delivers it to the local WG
                # ListenPort; the slave's socks_pass is the shared auth token. Reuses gost_socks_listen_port.
                # Remove the gost server FIRST -- both bind the same TCP port, so ensure-before-remove would
                # EADDRINUSE-flap the new server under Restart=always.
                self.remove_sidecar_leigod_gost_server_service(interface_name)
                self.ensure_sidecar_leigod_lguot_server_service(interface_name, gost_socks_listen_port, listen_port, socks_pass)
            else:
                self.remove_sidecar_leigod_lguot_server_service(interface_name)
                self.ensure_sidecar_leigod_gost_server_service(interface_name, gost_socks_listen_port, socks_user, socks_pass)
        else:
            self.remove_sidecar_leigod_gost_server_service(interface_name)
            self.remove_sidecar_leigod_lguot_server_service(interface_name)
        keepalive_line = f"PersistentKeepalive = {keepalive}\n" if keepalive > 0 else ""
        overlay_address_lines = "".join(f"Address = {addr}/32\n" for addr in overlay_local_addresses)
        allowed_ips = ", ".join([f"{peer_address}/32"] + [f"{ip}/32" for ip in overlay_peer_allowed_ips])
        config = (
            "[Interface]\n"
            f"PrivateKey = {private_key}\n"
            f"ListenPort = {listen_port}\n"
            f"Address = {local_address}/32\n"
            f"{overlay_address_lines}"
            "\n"
            "[Peer]\n"
            f"PublicKey = {peer_public_key}\n"
            f"AllowedIPs = {allowed_ips}\n"
            f"{keepalive_line}"
        )
        path = self.sidecar_uplink_interfaces_dir() / f"{interface_name}.conf"
        changed = write_text_if_changed(path, config, mode=0o600)
        system_path = self.sidecar_uplink_system_path(interface_name)
        system_changed = write_text_if_changed(system_path, config, mode=0o600)
        if shutil.which("systemctl") is not None:
            service_name = f"wg-quick@{interface_name}"
            service_active = self.systemd_is_active(service_name)
            if changed or system_changed:
                subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
            if changed or system_changed or not service_active:
                subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)

    def remove_slave_sidecar_uplink(self, uplink: dict[str, object]) -> None:
        interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
        if interface_name == "":
            return
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"wg-quick@{interface_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"wg-quick@{interface_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "reset-failed", f"wg-quick@{interface_name}.service"], text=True, capture_output=True, check=False)
        path = self.sidecar_uplink_interfaces_dir() / f"{interface_name}.conf"
        if path.exists():
            path.unlink()
        system_path = self.sidecar_uplink_system_path(interface_name)
        if system_path.exists():
            system_path.unlink()
        self.remove_sidecar_uot_server_service(interface_name)
        self.remove_sidecar_cf_wstunnel_backend(interface_name)
        self.remove_sidecar_leigod_gost_server_service(interface_name)
        self.remove_sidecar_leigod_lguot_server_service(interface_name)

    def restart_slave_sidecar_uplink(self, interface_name: str) -> None:
        iface = str(interface_name or "").strip()[:15]
        if iface == "" or shutil.which("systemctl") is None:
            return
        subprocess.run(["systemctl", "restart", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)

    @staticmethod
    def wireguard_system_dir() -> pathlib.Path:
        return pathlib.Path("/etc/wireguard")

    def cleanup_stale_mptcp_transit_interfaces(self, point_id: int, keep: "set[str] | None" = None) -> None:
        """Reap stale MPTCP slave-transit interfaces (lw-sc-<point>-<idx>).

        These devices are created LIVE by the mptcp relay (not via wg-quick), so the
        cleanup must actually delete the kernel device with `ip link del` — stopping
        the (disabled/failed) wg-quick unit and removing the conf is a no-op against
        the live device, which is why stale interfaces accumulated forever as the
        published leg count shrank.

        `keep` is the set of interface_names the panel published THIS cycle for this
        point; any matching interface in `keep` is preserved so a mixed point (static
        lws* uplinks AND active lw-sc transit legs) never loses its live traffic.
        keep=None is treated as the empty set (reap everything for the point).
        """
        if point_id <= 0:
            return
        keep_set = set(keep) if keep else set()
        scoped_prefix = f"lw-sc-{point_id}-"

        # Live kernel devices currently known to WireGuard for this point.
        live_interfaces: list[str] = []
        if shutil.which("wg") is not None:
            result = subprocess.run(["wg", "show", "interfaces"], text=True, capture_output=True, check=False)
            if result.returncode == 0:
                live_interfaces = [
                    iface for iface in result.stdout.strip().split()
                    if iface.startswith(scoped_prefix) and iface not in keep_set
                ]

        wg_dir = self.wireguard_system_dir()
        for iface in live_interfaces:
            # Stop/disable any (likely failed) wg-quick unit and clear its failed state.
            if shutil.which("systemctl") is not None:
                subprocess.run(["systemctl", "stop", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "disable", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "reset-failed", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
            # ACTUALLY remove the live kernel device (the relay created it directly).
            if shutil.which("ip") is not None:
                subprocess.run(["ip", "link", "del", "dev", iface], text=True, capture_output=True, check=False)
            elif shutil.which("wg-quick") is not None:
                subprocess.run(["wg-quick", "down", iface], text=True, capture_output=True, check=False)
            conf_path = wg_dir / f"{iface}.conf"
            try:
                if conf_path.exists():
                    conf_path.unlink()
            except OSError:
                pass

        # Reap conf-only orphans: lw-sc-<point>-*.conf with no live device and not kept.
        live_now = set(live_interfaces)
        try:
            conf_orphans = list(wg_dir.glob(f"{scoped_prefix}*.conf")) if wg_dir.exists() else []
        except OSError:
            conf_orphans = []
        for conf_path in conf_orphans:
            iface = conf_path.name[: -len(".conf")]
            if iface in keep_set or iface in live_now:
                continue
            try:
                conf_path.unlink()
            except OSError:
                pass

    def ensure_sidecar_uot_server_service(self, interface_name: str, listen_tcp_port: int, target_udp_port: int) -> None:
        if listen_tcp_port <= 0 or target_udp_port <= 0:
            return
        service_name = f"leikwan-uot-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
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
        changed = write_text_if_changed(unit_path, body, mode=0o644)
        if shutil.which("systemctl") is not None:
            if changed:
                subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)

    def remove_sidecar_uot_server_service(self, interface_name: str) -> None:
        service_name = f"leikwan-uot-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
        if unit_path.exists():
            unit_path.unlink()

    def ensure_sidecar_leigod_gost_server_service(self, interface_name: str, listen_tcp_port: int, socks_user: str, socks_pass: str) -> None:
        """gost UDP-over-TCP SOCKS server for the LeiGod basis (the SG-VM 'server' role).

        The slave's gost client dials this over its LeiGod line; gost terminates the UoT framing and
        delivers the unwrapped UDP to 127.0.0.1:<WG listen_port>. Must be gost (not leikwan-uot.py) so it
        interoperates with the slave's gost UoT framing. The cloud security-group must allow inbound TCP
        on listen_tcp_port -- the LeiGod line exit connects to it.
        """
        if listen_tcp_port <= 0 or socks_user == "" or socks_pass == "":
            return
        service_name = f"leikwan-leigod-gost-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
        body = f"""[Unit]
Description=Leikwan LeiGod gost UoT Server {service_name}
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/gost -L socks5://{socks_user}:{socks_pass}@:{listen_tcp_port}?udp=true
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
        changed = write_text_if_changed(unit_path, body, mode=0o644)
        if shutil.which("systemctl") is not None and changed:
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)

    def remove_sidecar_leigod_gost_server_service(self, interface_name: str) -> None:
        service_name = f"leikwan-leigod-gost-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
        if unit_path.exists():
            unit_path.unlink()

    def ensure_lguot_binary(self) -> None:
        """Download the lguot UoT binary from the panel if missing (self-heal for the
        leigod_uot_impl=lguot rollout without re-running the sidecar installer)."""
        lguot_path = pathlib.Path("/usr/local/bin/lguot")
        if lguot_path.exists() and os.access(str(lguot_path), os.X_OK):
            return
        master = str(self.config.get("master_url", "") or "").rstrip("/")
        if not master:
            return
        url = f"{master}/artifacts/lguot/lguot_linux_amd64"
        try:
            with urllib.request.urlopen(urllib.request.Request(url), timeout=60) as resp:
                tmp_path = lguot_path.with_suffix(".tmp")
                tmp_path.write_bytes(resp.read())
                tmp_path.chmod(0o755)
                tmp_path.rename(lguot_path)
            logging.info("lguot binary installed at %s", lguot_path)
        except Exception as exc:  # noqa: BLE001
            logging.warning("failed to download lguot binary: %s", exc)

    def ensure_sidecar_leigod_lguot_server_service(self, interface_name: str, listen_tcp_port: int, wg_listen_port: int, token: str) -> None:
        """lguot UoT server for the LeiGod basis (the leigod_uot_impl=lguot peer of the gost server).

        The slave's lguot client SOCKS5-CONNECTs over its LeiGod line to :listen_tcp_port; the server
        de-frames with batched sendmmsg and delivers the UDP to 127.0.0.1:<WG listen_port>, authenticated
        by the shared token (= socks_pass). Unlike the gost SOCKS server, the WG target port is fixed at
        the unit (-wg). The cloud security-group must allow inbound TCP on listen_tcp_port.
        """
        if listen_tcp_port <= 0 or wg_listen_port <= 0 or token == "":
            return
        self.ensure_lguot_binary()
        lguot_bin = "/usr/local/bin/lguot"
        # Churn guard: never write a Restart=always unit on a node lacking the lguot binary.
        if shutil.which(lguot_bin) is None and not pathlib.Path(lguot_bin).exists():
            self.remove_sidecar_leigod_lguot_server_service(interface_name)
            return
        service_name = f"leikwan-leigod-lguot-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
        body = f"""[Unit]
Description=Leikwan LeiGod lguot UoT Server {service_name}
After=network.target

[Service]
Type=simple
ExecStart={lguot_bin} server -listen :{listen_tcp_port} -wg 127.0.0.1:{wg_listen_port} -token {token}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
        changed = write_text_if_changed(unit_path, body, mode=0o644)
        if shutil.which("systemctl") is not None and changed:
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)

    def remove_sidecar_leigod_lguot_server_service(self, interface_name: str) -> None:
        service_name = f"leikwan-leigod-lguot-server-{interface_name}"
        unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
        if unit_path.exists():
            unit_path.unlink()

    def ensure_sidecar_cf_wstunnel_backend(self, interface_name: str, path_prefix: str, target_host: str, target_port: int) -> None:
        """Create wstunnel server backend for cf_mode slave transit underlay.

        This creates a standalone wstunnel server that:
        1. Listens on a dynamically allocated local port (26000-26999 by default)
        2. Accepts WebSocket connections with the specified path prefix
        3. Forwards UDP traffic to the target (WireGuard listen port)

        The CF mux will route incoming WSS connections to this backend based on path prefix.
        """
        if path_prefix == "" or target_host == "" or target_port <= 0:
            return

        service_name = f"leikwan-cf-slave-{interface_name}"

        # Allocate a backend port for this service
        backend_port = self.reconcile_cf_backend_port(service_name, self.allocate_cf_backend_port(service_name))

        # Create the wstunnel backend service
        target = f"{target_host}:{target_port}"
        unit_body = self.render_cf_backend_unit(service_name, backend_port, target, path_prefix)
        unit_path = self.unit_path_for(service_name)

        changed = write_text_if_changed(unit_path, unit_body, mode=0o644)

        if shutil.which("systemctl") is not None:
            if changed:
                subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)

        # Register this backend in CF metadata so it persists across agent restarts
        metadata = self.load_managed_cf_metadata()
        # Remove any existing entry for this service
        metadata = [item for item in metadata if str(item.get("service_name", "")) != service_name]
        # Add new entry
        metadata.append({
            "service_name": service_name,
            "backend_port": backend_port,
            "path_prefix": path_prefix,
            "target": target,
            "interface_name": interface_name,
            "backend_type": "slave_transit_cf_wg",
        })
        self.save_managed_cf_metadata(metadata)

    def remove_sidecar_cf_wstunnel_backend(self, interface_name: str) -> None:
        """Remove wstunnel server backend for cf_mode slave transit underlay."""
        service_name = f"leikwan-cf-slave-{interface_name}"
        unit_path = self.unit_path_for(service_name)

        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)

        if unit_path.exists():
            unit_path.unlink()

        # Remove from CF metadata
        metadata = self.load_managed_cf_metadata()
        metadata = [item for item in metadata if str(item.get("service_name", "")) != service_name]
        self.save_managed_cf_metadata(metadata)

    def cleanup_orphan_slave_transit_cf_backends(self, active_interfaces: set[str]) -> None:
        """Reconcile CF slave-transit backends against the authoritative active uplink set."""
        active = {str(item or "").strip()[:15] for item in active_interfaces if str(item or "").strip() != ""}
        metadata = self.load_managed_cf_metadata()
        if metadata == []:
            return
        kept: list[dict[str, Any]] = []
        removed_any = False
        removed_services: set[str] = set()
        for item in metadata:
            if not isinstance(item, dict):
                continue
            if str(item.get("backend_type", "") or "") != "slave_transit_cf_wg":
                kept.append(item)
                continue
            interface_name = str(item.get("interface_name", "") or "").strip()[:15]
            service_name = str(item.get("service_name", "") or "").strip()
            if interface_name != "" and interface_name in active:
                kept.append(item)
                continue
            removed_any = True
            if service_name != "":
                removed_services.add(service_name)
                logging.warning("removing orphan CF slave-transit backend %s for inactive interface %s", service_name, interface_name)
                self.disable_runtime(service_name, self.unit_path_for(service_name))
        if removed_any:
            self.save_managed_cf_metadata(kept)
            # C.6c: prune the swept slave-transit rows from any cf-routes-*.json the
            # cf mux reads from. This must happen even when cf_runtime is absent from
            # the current payload (the cf mux otherwise keeps routing to a dead leg).
            if removed_services:
                self.prune_cf_routes_files(removed_services)

    def prune_cf_routes_files(self, removed_services: set[str]) -> None:
        """Remove rows for the given service names from every cf-routes-<port>.json."""
        targets = {str(name or "").strip() for name in removed_services if str(name or "").strip() != ""}
        if not targets:
            return
        for routes_path in self.state_dir.glob("cf-routes-*.json"):
            try:
                payload = json.loads(routes_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            if not isinstance(payload, dict):
                continue
            rows = payload.get("routes", [])
            if not isinstance(rows, list):
                continue
            kept_rows = [
                row
                for row in rows
                if not (isinstance(row, dict) and str(row.get("service_name", "") or "").strip() in targets)
            ]
            if len(kept_rows) == len(rows):
                continue
            payload["routes"] = kept_rows
            try:
                routes_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            except OSError:
                logging.exception("failed to prune swept slave-transit rows from %s", routes_path)

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

    def time_sync_state(self) -> dict:
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

    def panel_clock_state_from_headers(self, headers: dict[str, str]) -> dict:
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

    def service_name(self) -> str:
        return str(self.config.get("service_name", DEFAULT_CONFIG["service_name"]) or DEFAULT_CONFIG["service_name"])

    def service_unit_path(self) -> pathlib.Path:
        return pathlib.Path(f"/etc/systemd/system/{self.service_name()}.service")

    def runtime_config_path(self) -> pathlib.Path:
        return pathlib.Path(str(self.config.get("runtime_config_path", DEFAULT_CONFIG["runtime_config_path"]) or DEFAULT_CONFIG["runtime_config_path"]))

    def runtime_bin(self) -> str:
        return str(self.config.get("runtime_bin", DEFAULT_CONFIG["runtime_bin"]) or DEFAULT_CONFIG["runtime_bin"])

    def apply_config_patch(self, patch: dict) -> None:
        changed = False
        for key in ("service_name", "runtime_config_path", "state_dir", "artifact_base_url"):
            if key not in patch:
                continue
            value = str(patch.get(key, "") or "").strip()
            if value == "":
                continue
            if str(self.config.get(key, "") or "") != value:
                self.config[key] = value
                changed = True

        if "udp_uot_sidepath" in patch:
            sidepath = patch.get("udp_uot_sidepath")
            if sidepath is None:
                if "udp_uot_sidepath" in self.config:
                    self.config.pop("udp_uot_sidepath", None)
                    changed = True
            elif isinstance(sidepath, dict) and self.config.get("udp_uot_sidepath") != sidepath:
                self.config["udp_uot_sidepath"] = sidepath
                changed = True

        if "managed_iperf3_ports" in patch:
            managed_ports = self.normalize_managed_iperf3_ports(patch.get("managed_iperf3_ports"))
            if managed_ports == []:
                if "managed_iperf3_ports" in self.config:
                    self.config.pop("managed_iperf3_ports", None)
                    changed = True
            elif self.config.get("managed_iperf3_ports") != managed_ports:
                self.config["managed_iperf3_ports"] = managed_ports
                changed = True

        if "disable_auto_update" in patch:
            value = patch.get("disable_auto_update")
            enabled = str(value).strip().lower() in {"1", "true", "yes", "on"} if not isinstance(value, bool) else value
            if value is None:
                if self.local_auto_update_locked():
                    if bool(self.config.get("disable_auto_update", False)) is not True:
                        self.config["disable_auto_update"] = True
                        changed = True
                elif "disable_auto_update" in self.config:
                    self.config.pop("disable_auto_update", None)
                    changed = True
            elif bool(self.config.get("disable_auto_update", False)) != enabled:
                self.config["disable_auto_update"] = enabled
                changed = True

        if changed:
            write_text_if_changed(self.config_path, json.dumps(self.config, ensure_ascii=False, indent=2) + "\n", mode=0o600)
            self.state_dir = pathlib.Path(str(self.config.get("state_dir", DEFAULT_CONFIG["state_dir"])))
            self.state_dir.mkdir(parents=True, exist_ok=True)
            self.runtime_stats_path = self.state_dir / "runtime-stats.json"
            self.pull_state_path = self.state_dir / "pull-state.json"

    def mptcp_service_name(self) -> str:
        service_name = self.service_name()
        if "bond-sidecar" in service_name:
            return service_name.replace("bond-sidecar", "mptcp-sidecar", 1)
        return service_name + "-mptcp"

    def local_auto_update_locked(self) -> bool:
        if bool(self.config.get("disable_auto_update_lock", False)):
            return True
        lock_path = pathlib.Path(str(self.config.get("disable_auto_update_lock_path", "/etc/leikwan-sidecar/disable-auto-update.lock") or ""))
        try:
            return lock_path.exists()
        except OSError:
            return False

    def auto_update_disabled(self) -> bool:
        return bool(self.config.get("disable_auto_update", False)) or self.local_auto_update_locked()

    def mptcp_runtime_config_path(self) -> pathlib.Path:
        primary = self.runtime_config_path()
        suffix = primary.suffix or ".json"
        stem = primary.stem
        if stem == "runtime":
            next_stem = "mptcp-runtime"
        elif "bond" in stem:
            next_stem = stem.replace("bond", "mptcp", 1)
        else:
            next_stem = stem + "-mptcp"
        return primary.with_name(next_stem + suffix)

    def mptcp_start_script_path(self) -> pathlib.Path:
        return self.state_dir / "start-mptcp-sidecar.sh"

    def udp_uot_service_name(self) -> str:
        return self.service_name() + "-udp-uot"

    def udp_uot_runtime_config_path(self) -> pathlib.Path:
        return self.state_dir / "udp-uot-runtime.json"

    def udp_uot_start_script_path(self) -> pathlib.Path:
        return self.state_dir / "start-sidecar-udp-uot.sh"

    def cf_service_name(self, listen_port: int) -> str:
        return self.service_name() + f"-cf-{listen_port}"

    def cf_routes_path(self, listen_port: int) -> pathlib.Path:
        return self.state_dir / f"cf-routes-{listen_port}.json"

    def cf_managed_metadata_path(self) -> pathlib.Path:
        return self.state_dir / "cf-managed.json"

    def load_managed_cf_metadata(self) -> list[dict[str, Any]]:
        path = self.cf_managed_metadata_path()
        if not path.exists():
            return []
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return []
        return payload if isinstance(payload, list) else []

    def save_managed_cf_metadata(self, payload: list[dict[str, Any]]) -> None:
        self.cf_managed_metadata_path().write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def managed_iperf3_state_path(self) -> pathlib.Path:
        return self.state_dir / "managed-iperf3-ports.json"

    def load_managed_iperf3_ports(self) -> list[int]:
        path = self.managed_iperf3_state_path()
        if not path.exists():
            return []
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return []
        values = payload.get("ports", []) if isinstance(payload, dict) else payload
        if not isinstance(values, list):
            return []
        ports: list[int] = []
        for value in values:
            try:
                port = int(value)
            except (TypeError, ValueError):
                continue
            if 1 <= port <= 65535 and port not in ports:
                ports.append(port)
        return ports

    def save_managed_iperf3_ports(self, ports: list[int]) -> None:
        self.managed_iperf3_state_path().write_text(json.dumps({"ports": ports}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def managed_iperf3_service_name(self, port: int) -> str:
        return f"{self.service_name()}-iperf3-{int(port)}"

    @staticmethod
    def normalize_managed_iperf3_ports(values: object) -> list[int]:
        if not isinstance(values, list):
            return []
        ports: list[int] = []
        for value in values:
            try:
                port = int(value)
            except (TypeError, ValueError):
                continue
            if 1 <= port <= 65535 and port not in ports:
                ports.append(port)
        return ports

    @staticmethod
    def render_managed_iperf3_unit(service_name: str, iperf3_bin: str, port: int) -> str:
        return f"""[Unit]
Description=Leikwan Managed iperf3 Server {service_name}
After=network.target

[Service]
Type=simple
ExecStartPre=/bin/bash -lc 'command -v {iperf3_bin} >/dev/null || command -v iperf3 >/dev/null || (apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y iperf3)'
ExecStart=/bin/bash -lc 'while true; do timeout --kill-after=3s 12s {iperf3_bin} -s -p {int(port)} --one-off --idle-timeout 5; sleep 0.05; done'
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""

    def disable_managed_iperf3_service(self, port: int) -> None:
        service_name = self.managed_iperf3_service_name(port)
        unit_path = self.unit_path_for(service_name)
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "stop", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "disable", f"{service_name}.service"], text=True, capture_output=True, check=False)
            subprocess.run(["systemctl", "reset-failed", f"{service_name}.service"], text=True, capture_output=True, check=False)
        if unit_path.exists():
            unit_path.unlink()
        if shutil.which("systemctl") is not None:
            subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)

    def apply_managed_iperf3_servers(self) -> None:
        requested_ports = self.normalize_managed_iperf3_ports(self.config.get("managed_iperf3_ports", []))
        previous_ports = self.load_managed_iperf3_ports()
        for stale_port in previous_ports:
            if stale_port not in requested_ports:
                self.disable_managed_iperf3_service(stale_port)
        iperf3_bin = shutil.which("iperf3") or "/usr/bin/iperf3"
        for port in requested_ports:
            service_name = self.managed_iperf3_service_name(port)
            unit_body = self.render_managed_iperf3_unit(service_name, iperf3_bin, port)
            unit_path = self.unit_path_for(service_name)
            try:
                unit_changed = write_text_if_changed(unit_path, unit_body)
                if shutil.which("systemctl") is not None:
                    if unit_changed:
                        subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
                    if unit_changed or not self.systemd_is_active(service_name):
                        subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
                        subprocess.run(["systemctl", "restart", f"{service_name}.service"], text=True, capture_output=True, check=False)
            except OSError:
                logging.exception("failed to install managed iperf3 service %s", service_name)
        self.save_managed_iperf3_ports(requested_ports)

    def mptcp_forward_routes_path(self) -> pathlib.Path:
        return self.state_dir / "mptcp-forward-routes.json"

    def cf_uot_service_name(self, forward_id: int) -> str:
        return self.service_name() + f"-cf-uot-{forward_id}"

    def cf_uot_runtime_config_path(self, forward_id: int) -> pathlib.Path:
        return self.state_dir / f"cf-uot-{forward_id}.json"

    def cf_uot_start_script_path(self, forward_id: int) -> pathlib.Path:
        return self.state_dir / f"start-cf-uot-{forward_id}.sh"

    def cf_backend_port_range(self) -> range:
        start = int(self.config.get("cf_backend_port_start", 26000) or 26000)
        end = int(self.config.get("cf_backend_port_end", 26999) or 26999)
        if start <= 0 or end < start:
            start, end = 26000, 26999
        return range(start, end + 1)

    def allocate_cf_backend_port(self, service_name: str, reserved_ports: set[int] | None = None) -> int:
        reserved = set(reserved_ports or set())
        used: set[int] = set()
        existing = 0
        for item in self.load_managed_cf_metadata():
            if not isinstance(item, dict):
                continue
            backend_port = int(item.get("backend_port", 0) or 0)
            if backend_port <= 0:
                continue
            if str(item.get("service_name", "")) == service_name:
                existing = backend_port
                continue
            used.add(backend_port)
        if existing > 0 and existing not in used and existing not in reserved:
            return existing
        for port in self.cf_backend_port_range():
            if port not in used and port not in reserved and not self.cf_backend_port_listening(port):
                return port
        raise RuntimeError("no free sidecar cf backend port available")

    @staticmethod
    def cf_backend_port_listening(port: int) -> bool:
        if port <= 0 or os.name == "nt":
            return False
        try:
            result = subprocess.run(
                f"ss -ltn 'sport = :{port}'",
                shell=True,
                text=True,
                capture_output=True,
                check=False,
            )
            return result.returncode == 0 and "LISTEN" in result.stdout
        except OSError:
            return False

    def reconcile_cf_backend_port(self, service_name: str, backend_port: int, reserved_ports: set[int] | None = None) -> int:
        if backend_port <= 0:
            return self.allocate_cf_backend_port(service_name, reserved_ports)
        reserved = set(reserved_ports or set())
        if backend_port in reserved:
            return self.allocate_cf_backend_port(service_name, reserved)
        service_active = self.systemd_is_active(service_name)
        if service_active:
            active_pid_result = subprocess.run(
                f"systemctl show {service_name}.service -p MainPID --value",
                shell=True,
                text=True,
                capture_output=True,
                check=False,
            )
            active_pid = int(active_pid_result.stdout.strip() or "0") if active_pid_result.returncode == 0 and active_pid_result.stdout.strip().isdigit() else 0
            if active_pid <= 0:
                return backend_port
            try:
                owner_result = subprocess.run(
                    f"ss -ltnp 'sport = :{backend_port}'",
                    shell=True,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                if owner_result.returncode == 0 and str(active_pid) in owner_result.stdout:
                    return backend_port
            except OSError:
                return backend_port
        else:
            try:
                owner_result = subprocess.run(
                    f"ss -ltnp 'sport = :{backend_port}'",
                    shell=True,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                if owner_result.returncode != 0 or "LISTEN" not in owner_result.stdout:
                    try:
                        with socket.create_connection(("127.0.0.1", backend_port), timeout=0.15):
                            pass
                    except ConnectionRefusedError:
                        return backend_port
                    except OSError:
                        return backend_port
            except OSError:
                return backend_port
        logging.warning("CF backend port %s for %s is occupied by another process; reallocating", backend_port, service_name)
        for stale_service in [
            str(item.get("service_name", "") or "")
            for item in self.load_managed_cf_metadata()
            if isinstance(item, dict)
            and str(item.get("service_name", "") or "") != service_name
            and int(item.get("backend_port", 0) or 0) == backend_port
        ]:
            if stale_service != "":
                self.disable_runtime(stale_service, self.unit_path_for(stale_service))
        metadata = self.load_managed_cf_metadata()
        metadata = [
            item
            for item in metadata
            if not (
                isinstance(item, dict)
                and (
                    str(item.get("service_name", "") or "") == service_name
                    or int(item.get("backend_port", 0) or 0) == backend_port
                )
            )
        ]
        self.save_managed_cf_metadata(metadata)
        reserved.add(backend_port)
        return self.allocate_cf_backend_port(service_name, reserved)

    def render_cf_backend_unit(self, service_name: str, backend_port: int, target: str, path_prefix: str) -> str:
        return f"""[Unit]
Description=Leikwan CF Backend {service_name}
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/wstunnel server ws://127.0.0.1:{backend_port} \
  --restrict-to {target} \
  --restrict-http-upgrade-path-prefix {path_prefix} \
  --websocket-ping-frequency-sec 5
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""

    def render_cf_mux_unit(self, listen_port: int, tls_cert_path: str, tls_key_path: str, routes_path: pathlib.Path) -> str:
        return f"""[Unit]
Description=Leikwan CF WSS Mux {listen_port}
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/leikwan-wss-mux.py --listen-port {listen_port} --tls-cert {tls_cert_path} --tls-key {tls_key_path} --routes-file {routes_path}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""

    @staticmethod
    def bond_binary_path() -> pathlib.Path:
        return pathlib.Path("/usr/local/bin/leikwan-bond")

    @staticmethod
    def unit_path_for(service_name: str) -> pathlib.Path:
        return pathlib.Path(f"/etc/systemd/system/{service_name}.service")

    def systemd_is_active(self, service_name: str | None = None) -> bool:
        if os.name == "nt":
            return True
        unit_name = service_name or self.service_name()
        result = subprocess.run(
            f"systemctl is-active {unit_name}.service",
            shell=True,
            text=True,
            capture_output=True,
            check=False,
        )
        return result.stdout.strip() == "active"

    @staticmethod
    def systemd_available() -> bool:
        return os.name != "nt" or shutil.which("systemctl") is not None or "pytest" in pathlib.Path(sys.argv[0]).name or "unittest" in pathlib.Path(sys.argv[0]).name

    def disable_runtime(self, service_name: str, unit_path: pathlib.Path, start_script_path: pathlib.Path | None = None, runtime_config_path: pathlib.Path | None = None) -> None:
        if self.systemd_available():
            subprocess.run(f"systemctl stop {service_name}.service", shell=True, check=False)
            subprocess.run(f"systemctl disable {service_name}.service", shell=True, check=False)
        if unit_path.exists():
            unit_path.unlink()
        if start_script_path is not None and start_script_path.exists():
            start_script_path.unlink()
        if runtime_config_path is not None and runtime_config_path.exists():
            runtime_config_path.unlink()
        if self.systemd_available():
            subprocess.run("systemctl daemon-reload", shell=True, check=False)

    def ensure_relay_binary(self) -> None:
        """Download Rust MPTCP relay binary from Panel if not present."""
        relay_path = self.state_dir / "leikwan-mptcp-relay"
        if relay_path.exists() and os.access(str(relay_path), os.X_OK):
            return
        master = str(self.config.get("master_url", "")).rstrip("/")
        if not master:
            return
        url = f"{master}/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64"
        logging.info("downloading relay binary from %s", url)
        try:
            req = urllib.request.Request(url)
            with urllib.request.urlopen(req, timeout=60) as resp:
                tmp_path = relay_path.with_suffix(".tmp")
                tmp_path.write_bytes(resp.read())
                tmp_path.chmod(0o755)
                tmp_path.rename(relay_path)
            logging.info("relay binary installed at %s", relay_path)
        except Exception as exc:  # noqa: BLE001
            logging.warning("failed to download relay binary: %s", exc)

    def apply_management_config(self, management: dict, _state: dict | None = None) -> None:
        self.apply_management(management)

    def apply_management(self, management: dict) -> None:
        donation_reverse_probe_targets = management.get("donation_reverse_probe_targets", [])
        if isinstance(donation_reverse_probe_targets, list):
            self.save_donation_reverse_probe_targets([item for item in donation_reverse_probe_targets if isinstance(item, dict)])
        config_patch = dict(management.get("config_patch", {})) if isinstance(management.get("config_patch", {}), dict) else {}
        if config_patch != {}:
            self.apply_config_patch(config_patch)
        if os.name != "nt":
            self.apply_managed_iperf3_servers()

        explicit_request_token = str(management.get("agent_update_request_token", "") or "")
        auto_request_token = self.auto_update_request_token(management)
        if self.auto_update_disabled():
            explicit_request_token = ""
            auto_request_token = ""
        update_state = self.load_update_state()
        completed_request_token = str(update_state.get("last_completed_request_token", "") or "")

        # An explicit agent_update_request_token is a manual force-refresh: it must
        # drive request_self_update even when no version/sha mismatch is present
        # (the loop's last_completed_request_token check still prevents re-running a
        # token that already completed, so this does not loop).
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
                # A transient launch failure (e.g. systemd-run hiccup) must not crash
                # the heartbeat. Record it as "running" so should_retry_update throttles
                # retries for UPDATE_STALE_SECONDS instead of hot-looping.
                update_state["status"] = "running"
                update_state["last_error"] = str(exc)
                update_state["last_failed_at"] = now
                self.save_update_state(update_state)
            break

        bond_runtime = management.get("bond_runtime") if isinstance(management.get("bond_runtime"), dict) else None
        mptcp_runtime = management.get("mptcp_runtime") if isinstance(management.get("mptcp_runtime"), dict) else None
        if bond_runtime is None and mptcp_runtime is None:
            legacy_runtime = dict(management)
            legacy_mode = str(legacy_runtime.get("mode", "bond") or "bond").strip().lower()
            if legacy_mode == "mptcp":
                mptcp_runtime = legacy_runtime
            else:
                bond_runtime = legacy_runtime

        # Transit keep-set: the lw-sc-<point>-<idx> names the panel published THIS
        # cycle. Computed from the RAW published underlays (not the later L1677
        # processing) so the static-uplink cleanup site can preserve a mixed point's
        # active transit legs while still reaping stale ones.
        transit_keep: set[str] = set()
        if isinstance(mptcp_runtime, dict):
            for raw_ul in mptcp_runtime.get("underlays", []) or []:
                if isinstance(raw_ul, dict):
                    name = str(raw_ul.get("interface_name", "") or "")
                    if name:
                        transit_keep.add(name)

        primary_service_name = self.service_name()
        primary_unit_path = self.service_unit_path()
        primary_runtime_path = self.runtime_config_path()
        primary_start_script = self.state_dir / "start-sidecar.sh"
        point_id = 0
        service_name_suffix = primary_service_name.rsplit("-", 1)[-1]
        if service_name_suffix.isdigit():
            point_id = int(service_name_suffix)

        secondary_service_name = self.mptcp_service_name()
        secondary_unit_path = self.unit_path_for(secondary_service_name)
        secondary_runtime_path = self.mptcp_runtime_config_path()
        secondary_start_script = self.mptcp_start_script_path()

        # Empty-feed authoritative-wipe guard (BUG-1): only treat the section as
        # authoritative when the key is actually present in the payload. An ABSENT
        # section (omitted key, or a fetch that returned nothing) must be a NO-OP:
        # never tear down live uplinks/backends and never overwrite persisted state.
        # Mirror slave_agent.py which uses `"key" in management`. An explicitly
        # present (possibly empty) list IS authoritative -> empty means teardown.
        sidecar_uplinks_present = "sidecar_uplinks" in management
        raw_sidecar_uplinks = management.get("sidecar_uplinks", [])
        sidecar_uplinks_valid = isinstance(raw_sidecar_uplinks, list)
        if sidecar_uplinks_valid:
            for raw_uplink in raw_sidecar_uplinks:
                if not isinstance(raw_uplink, dict):
                    sidecar_uplinks_valid = False
                    break
                interface_name = str(raw_uplink.get("interface_name", "") or "").strip()[:15]
                if interface_name == "":
                    sidecar_uplinks_valid = False
                    break
                if str(raw_uplink.get("wireguard_peer_mode", "") or "").strip().lower() == "shared":
                    try:
                        sidecar_point_id = int(raw_uplink.get("sidecar_point_id", 0) or 0)
                    except (TypeError, ValueError):
                        sidecar_uplinks_valid = False
                        break
                    if sidecar_point_id <= 0:
                        sidecar_uplinks_valid = False
                        break
        if sidecar_uplinks_present and not sidecar_uplinks_valid:
            logging.error("malformed sidecar_uplinks section; preserving all current interfaces")
            sidecar_uplinks_present = False
        sidecar_uplinks = raw_sidecar_uplinks if sidecar_uplinks_valid else []
        if sidecar_uplinks_present:
            current_uplink_state = self.load_sidecar_uplink_state()
            active_interfaces: set[str] = set()
            disabled_interfaces: set[str] = set()
            static_uplink_point_ids: set[int] = set()
            shared_groups: dict[int, list[dict[str, object]]] = {}
            legacy_uplinks: list[dict[str, object]] = []
            for uplink in sidecar_uplinks:
                if not isinstance(uplink, dict):
                    continue
                interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
                if interface_name == "":
                    continue
                if str(uplink.get("wireguard_peer_mode", "") or "").strip().lower() == "shared":
                    shared_groups.setdefault(int(uplink.get("sidecar_point_id", 0) or 0), []).append(uplink)
                else:
                    legacy_uplinks.append(uplink)

            failed_shared_points: set[int] = set()
            shared_interface_names = {
                str(peer.get("interface_name", "") or "").strip()[:15]
                for peers in shared_groups.values()
                for peer in peers
            }
            for shared_point_id, peers in shared_groups.items():
                enabled_peers = [peer for peer in peers if bool(peer.get("enabled", True))]
                if not enabled_peers:
                    disabled_names = {
                        str(peer.get("interface_name", "") or "").strip()[:15]
                        for peer in peers
                        if str(peer.get("interface_name", "") or "").strip()[:15] != ""
                    }
                    if len(disabled_names) != 1:
                        failed_shared_points.add(shared_point_id)
                        logging.error("shared sidecar point %s has conflicting disabled interfaces; preserving current state", shared_point_id)
                        continue
                    interface_name = next(iter(disabled_names))
                    disabled_interfaces.add(interface_name)
                    self.remove_slave_sidecar_uplink({"interface_name": interface_name})
                    continue
                try:
                    interface_name, _ = self.validate_shared_sidecar_uplinks(enabled_peers)
                    self.apply_shared_slave_sidecar_interface(enabled_peers)
                except Exception:  # noqa: BLE001
                    failed_shared_points.add(shared_point_id)
                    logging.exception("shared slave-sidecar point %s reconcile failed; preserving current interfaces", shared_point_id)
                    continue
                active_interfaces.add(interface_name)
                if shared_point_id > 0:
                    static_uplink_point_ids.add(shared_point_id)

            for uplink in legacy_uplinks:
                interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
                legacy_point_id = int(uplink.get("sidecar_point_id", point_id) or point_id)
                if legacy_point_id in failed_shared_points:
                    continue
                if interface_name in shared_interface_names:
                    logging.error("legacy slave-sidecar uplink %s conflicts with shared desired state; skipping legacy record", interface_name)
                    continue
                if not bool(uplink.get("enabled", True)):
                    disabled_interfaces.add(interface_name)
                    self.remove_slave_sidecar_uplink({"interface_name": interface_name})
                    continue
                point_for_uplink = int(uplink.get("sidecar_point_id", point_id) or point_id)
                if interface_name.startswith("lws") and point_for_uplink > 0:
                    static_uplink_point_ids.add(point_for_uplink)
                active_interfaces.add(interface_name)
                # Per-uplink isolation (BUG-2): one exhausted/bad uplink must not
                # abort the whole heartbeat reconcile.
                try:
                    self.apply_slave_sidecar_uplink(uplink)
                except Exception:  # noqa: BLE001
                    logging.exception("slave-sidecar uplink %s reconcile failed; continuing", interface_name)
            for cleanup_point_id in sorted(static_uplink_point_ids - failed_shared_points):
                self.cleanup_stale_mptcp_transit_interfaces(cleanup_point_id, keep=transit_keep)
            existing_slaves = current_uplink_state.get("slaves", {}) if isinstance(current_uplink_state.get("slaves", {}), dict) else {}
            for slave_id, info in list(existing_slaves.items()):
                iface = str((info if isinstance(info, dict) else {}).get("interface_name", "") or "").strip()[:15]
                existing_point_id = self.static_sidecar_point_id_from_interface(iface)
                if existing_point_id in failed_shared_points:
                    active_interfaces.add(iface)
                    continue
                if iface != "" and iface not in active_interfaces:
                    if iface not in disabled_interfaces:
                        self.remove_slave_sidecar_uplink({"interface_name": iface})
                    existing_slaves.pop(slave_id, None)
            next_state = {"slaves": {}}
            for interface_name in sorted(active_interfaces):
                previous_info = existing_slaves.get(interface_name)
                next_state["slaves"][interface_name] = (
                    dict(previous_info)
                    if isinstance(previous_info, dict)
                    else {"interface_name": interface_name}
                )
            self.save_sidecar_uplink_state(next_state)
            self.cleanup_orphan_slave_transit_cf_backends(active_interfaces)

        self_leg_present = "self_leg_client_peers" in management
        raw_self_leg_peers = management.get("self_leg_client_peers", [])
        self_leg_client_peers = raw_self_leg_peers if isinstance(raw_self_leg_peers, list) else []
        if self_leg_present:
            current_self_leg_state = self.load_self_leg_client_peer_state()
            active_self_leg_interfaces: set[str] = set()
            disabled_self_leg_interfaces: set[str] = set()
            for peer in self_leg_client_peers:
                if not isinstance(peer, dict):
                    continue
                interface_name = str(peer.get("interface_name", "") or "").strip()[:15]
                if interface_name == "":
                    continue
                if not bool(peer.get("enabled", True)):
                    disabled_self_leg_interfaces.add(interface_name)
                    self.remove_self_leg_client_peer({"interface_name": interface_name})
                    continue
                active_self_leg_interfaces.add(interface_name)
                try:
                    self.apply_self_leg_client_peer(peer)
                except Exception:  # noqa: BLE001
                    logging.exception("self-leg peer %s reconcile failed; continuing", interface_name)
            existing_self_leg = current_self_leg_state.get("interfaces", {}) if isinstance(current_self_leg_state.get("interfaces", {}), dict) else {}
            for key, info in list(existing_self_leg.items()):
                iface = str((info if isinstance(info, dict) else {}).get("interface_name", key) or "").strip()[:15]
                if iface != "" and iface not in active_self_leg_interfaces:
                    if iface not in disabled_self_leg_interfaces:
                        self.remove_self_leg_client_peer({"interface_name": iface})
                    existing_self_leg.pop(key, None)
            next_self_leg_state = {"interfaces": {}}
            for peer in self_leg_client_peers:
                if not isinstance(peer, dict) or not bool(peer.get("enabled", True)):
                    continue
                interface_name = str(peer.get("interface_name", "") or "").strip()[:15]
                if interface_name == "":
                    continue
                next_self_leg_state["interfaces"][interface_name] = {"interface_name": interface_name}
            self.save_self_leg_client_peer_state(next_self_leg_state)

        cf_runtime = management.get("cf_runtime") if isinstance(management.get("cf_runtime"), dict) else None
        if cf_runtime is not None:
            listen_port = int(cf_runtime.get("listen_port", 0) or 0)
            routes = cf_runtime.get("routes", [])
            cf_service_name = self.cf_service_name(listen_port)
            cf_unit_path = self.unit_path_for(cf_service_name)
            cf_routes_path = self.cf_routes_path(listen_port)
            if listen_port <= 0 or not isinstance(routes, list) or routes == []:
                self.disable_runtime(cf_service_name, cf_unit_path, runtime_config_path=cf_routes_path)
                self.save_managed_cf_metadata([])
            else:
                previous_cf_metadata = self.load_managed_cf_metadata()
                previous_cf_services = {
                    str(item.get("service_name", "") or "")
                    for item in previous_cf_metadata
                    if isinstance(item, dict) and str(item.get("service_name", "") or "") != ""
                }
                previous_slave_transit_services = {
                    str(item.get("service_name", "") or "")
                    for item in previous_cf_metadata
                    if (
                        isinstance(item, dict)
                        and str(item.get("backend_type", "") or "") == "slave_transit_cf_wg"
                        and str(item.get("service_name", "") or "") != ""
                    )
                }
                rendered_routes: list[dict[str, Any]] = []
                reserved_cf_backend_ports: set[int] = set()
                active_cf_services: set[str] = set()
                for route in routes:
                    if not isinstance(route, dict):
                        continue
                    service_name = str(route.get("service_name", "") or "").strip()
                    path_prefix = str(route.get("path_prefix", "") or "").strip()
                    target = str(route.get("target", "") or "").strip()
                    if service_name == "" or path_prefix == "" or target == "":
                        continue
                    # Per-route isolation (BUG-2): allocate_cf_backend_port raises
                    # RuntimeError on port exhaustion. One exhausted/bad route must
                    # not abort the whole cf_runtime reconcile (and later sections).
                    try:
                        backend_mode = str(route.get("backend_mode", "") or "").strip().lower()
                        target_host, _, target_port_text = target.rpartition(":")
                        target_port = int(target_port_text) if target_host.strip() != "" and target_port_text.strip().isdigit() else 0
                        direct_backend = backend_mode == "direct" and target_host.strip() != "" and target_port > 0
                        if direct_backend:
                            backend_port = target_port
                            backend_host = target_host.strip()
                            active_cf_services.add(service_name)
                            stale_unit_path = self.unit_path_for(service_name)
                            preview_path = self.state_dir / f"{service_name}.service.preview"
                            if preview_path.exists():
                                preview_path.unlink()
                            if os.name != "nt" and stale_unit_path.exists():
                                self.disable_runtime(service_name, stale_unit_path)
                        else:
                            backend_port = self.allocate_cf_backend_port(service_name, reserved_cf_backend_ports)
                            reserved_cf_backend_ports.add(backend_port)
                            backend_host = "127.0.0.1"
                            unit_body = self.render_cf_backend_unit(service_name, backend_port, target, path_prefix)
                            preview_path = self.state_dir / f"{service_name}.service.preview"
                            preview_path.write_text(unit_body, encoding="utf-8")
                            active_cf_services.add(service_name)
                            if self.systemd_available():
                                backend_unit_path = self.unit_path_for(service_name)
                                try:
                                    unit_changed = write_text_if_changed(backend_unit_path, unit_body)
                                    if unit_changed:
                                        subprocess.run("systemctl daemon-reload", shell=True, check=False)
                                    if unit_changed or not self.systemd_is_active(service_name):
                                        subprocess.run(f"systemctl enable {service_name}.service", shell=True, check=False)
                                        subprocess.run(f"systemctl restart {service_name}.service", shell=True, check=False)
                                except OSError:
                                    logging.exception("failed to install CF backend service %s", service_name)
                    except Exception:  # noqa: BLE001
                        logging.exception("CF backend route %s reconcile failed; skipping route", service_name)
                        continue
                    rendered_routes.append(
                        {
                            "forward_id": int(route.get("forward_id", 0) or 0),
                            "service_name": service_name,
                            "path_prefix": path_prefix,
                            "target": target,
                            "backend_host": backend_host,
                            "backend_port": backend_port,
                            "backend_mode": backend_mode,
                            "transport_mode": str(route.get("transport_mode", "tcp") or "tcp"),
                            "shadow_route_key": str(route.get("shadow_route_key", "") or ""),
                        }
                    )
                for stale_service in sorted(previous_cf_services - active_cf_services - previous_slave_transit_services):
                    if self.systemd_available():
                        self.disable_runtime(stale_service, self.unit_path_for(stale_service))

                # Add slave transit CF backends to rendered routes
                # These are created by apply_slave_sidecar_uplink and registered in CF metadata
                slave_transit_service_names: set[str] = set()
                for metadata_entry in self.load_managed_cf_metadata():
                    if not isinstance(metadata_entry, dict):
                        continue
                    backend_type = str(metadata_entry.get("backend_type", "") or "")
                    if backend_type != "slave_transit_cf_wg":
                        continue
                    service_name = str(metadata_entry.get("service_name", "") or "")
                    if service_name in active_cf_services:
                        # Already processed
                        continue
                    # Per-slave-transit isolation (BUG-2): reconcile_cf_backend_port
                    # raises RuntimeError on port exhaustion. One bad slave-transit
                    # backend must not abort the loop or the later sections.
                    try:
                        # Add this slave transit backend to the routes
                        backend_port = self.reconcile_cf_backend_port(
                            service_name,
                            int(metadata_entry.get("backend_port", 0) or 0),
                            reserved_cf_backend_ports,
                        )
                        reserved_cf_backend_ports.add(backend_port)
                        path_prefix = str(metadata_entry.get("path_prefix", "") or "")
                        target = str(metadata_entry.get("target", "") or "")
                        if self.systemd_available() and path_prefix != "" and target != "":
                            unit_body = self.render_cf_backend_unit(service_name, backend_port, target, path_prefix)
                            unit_changed = write_text_if_changed(self.unit_path_for(service_name), unit_body)
                            if unit_changed:
                                subprocess.run("systemctl daemon-reload", shell=True, check=False)
                            if unit_changed or not self.systemd_is_active(service_name):
                                subprocess.run(f"systemctl enable {service_name}.service", shell=True, check=False)
                                subprocess.run(f"systemctl restart {service_name}.service", shell=True, check=False)
                    except Exception:  # noqa: BLE001
                        logging.exception("CF slave-transit backend %s reconcile failed; skipping", service_name)
                        continue
                    rendered_routes.append({
                        "forward_id": 0,  # Slave transit backends don't have a forward_id
                        "service_name": service_name,
                        "path_prefix": path_prefix,
                        "target": target,
                        "backend_host": "127.0.0.1",
                        "backend_port": backend_port,
                        "backend_mode": "wstunnel",
                        "transport_mode": "udp",
                        "shadow_route_key": "",
                        "interface_name": str(metadata_entry.get("interface_name", "") or ""),
                        "backend_type": "slave_transit_cf_wg",
                    })
                    active_cf_services.add(service_name)
                    slave_transit_service_names.add(service_name)

                cf_metadata = self.load_managed_cf_metadata()
                slave_transit_metadata = [
                    item
                    for item in cf_metadata
                    if (
                        isinstance(item, dict)
                        and str(item.get("backend_type", "") or "") == "slave_transit_cf_wg"
                        and str(item.get("service_name", "") or "") not in slave_transit_service_names
                    )
                ]
                self.save_managed_cf_metadata(rendered_routes + slave_transit_metadata)
                cf_routes_path.write_text(json.dumps({"routes": rendered_routes}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
                mptcp_forward_routes: list[dict[str, Any]] = []
                if isinstance(mptcp_runtime, dict):
                    raw_forward_routes = mptcp_runtime.get("forward_routes", [])
                    if isinstance(raw_forward_routes, list):
                        backend_by_forward_id: dict[int, dict[str, Any]] = {}
                        for entry in rendered_routes:
                            if not isinstance(entry, dict):
                                continue
                            fid = int(entry.get("forward_id", 0) or 0)
                            if fid <= 0:
                                continue
                            service_name = str(entry.get("service_name", "") or "")
                            is_shadow_route = service_name.endswith("-uot") or str(entry.get("shadow_route_key", "") or "") != ""
                            if fid in backend_by_forward_id and is_shadow_route:
                                continue
                            backend_by_forward_id[fid] = {
                                "target_host": str(entry.get("backend_host", "127.0.0.1") or "127.0.0.1"),
                                "target_port": int(entry.get("backend_port", 0) or 0),
                            }
                        for raw_route in raw_forward_routes:
                            if not isinstance(raw_route, dict):
                                continue
                            route = dict(raw_route)
                            fid = int(route.get("forward_id", 0) or 0)
                            backend = backend_by_forward_id.get(fid)
                            if route.get("target_mode") == "cf_local_backend" and isinstance(backend, dict):
                                route["target_host"] = str(backend.get("target_host", "127.0.0.1") or "127.0.0.1")
                                route["target_port"] = int(backend.get("target_port", 0) or 0)
                            mptcp_forward_routes.append(route)
                self.mptcp_forward_routes_path().write_text(json.dumps({"routes": mptcp_forward_routes}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
                unit_body = self.render_cf_mux_unit(
                    listen_port,
                    str(cf_runtime.get("tls_cert_path", "") or ""),
                    str(cf_runtime.get("tls_key_path", "") or ""),
                    cf_routes_path,
                )
                unit_changed = write_text_if_changed(cf_unit_path, unit_body)
                if self.systemd_available():
                    if unit_changed:
                        subprocess.run("systemctl daemon-reload", shell=True, check=False)
                    if unit_changed or not self.systemd_is_active(cf_service_name):
                        subprocess.run(f"systemctl enable {cf_service_name}.service", shell=True, check=False)
                        subprocess.run(f"systemctl restart {cf_service_name}.service", shell=True, check=False)

        if bond_runtime is not None:
            bond_forward_ids = [
                int(value)
                for value in bond_runtime.get("forward_ids", [])
                if str(value).strip().isdigit()
            ] if isinstance(bond_runtime.get("forward_ids", []), list) else []
            runtime_forward_id = bond_forward_ids[0] if len(bond_forward_ids) == 1 else 0
            runtime_config = {
                "listen_host": str(bond_runtime.get("bind_host", "0.0.0.0") or "0.0.0.0"),
                "listen_port": int(bond_runtime.get("listen_port", 0) or 0),
                "token_secret": str(bond_runtime.get("token_secret", "") or ""),
                "stats_path": str(self.runtime_stats_path),
                "log_level": str(self.config.get("log_level", "INFO")),
                "allow_tcp_fallback": True,
            }
            runtime_changed = write_text_if_changed(primary_runtime_path, json.dumps(runtime_config, ensure_ascii=False, indent=2) + "\n", mode=0o600)
            start_script_lines = [
                "#!/usr/bin/env bash",
                "set -euo pipefail",
                "export LEIKWAN_BOND_OBSERVER_TYPE='sidecar'",
                f"export LEIKWAN_BOND_OBSERVER_ID='{point_id}'",
                f"export LEIKWAN_BOND_FORWARD_ID='{runtime_forward_id}'",
                f"export LEIKWAN_BOND_STATE_DIR='{self.state_dir}'",
                f"export LEIKWAN_BOND_SNAPSHOT_PATH='{self.state_dir / 'bond-leg-health.json'}'",
                f'BOND_SERVER="{self.bond_binary_path().as_posix()}"',
                f'LISTEN="{runtime_config["listen_host"]}:{runtime_config["listen_port"]}"',
                f'TOKEN_SECRET="{runtime_config["token_secret"]}"',
                'exec "$BOND_SERVER" server --listen "$LISTEN" --token-secret "$TOKEN_SECRET"',
            ]
            start_script_changed = write_text_if_changed(primary_start_script, "\n".join(start_script_lines) + "\n", mode=0o755)
            unit_body = f"""[Unit]
Description=Leikwan Bond Sidecar
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/bin/bash {primary_start_script}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
            unit_changed = write_text_if_changed(primary_unit_path, unit_body)
            if self.systemd_available():
                if unit_changed or runtime_changed or start_script_changed:
                    subprocess.run("systemctl daemon-reload", shell=True, check=False)
                if unit_changed or runtime_changed or start_script_changed or not self.systemd_is_active(primary_service_name):
                    subprocess.run(f"systemctl enable {primary_service_name}.service", shell=True, check=False)
                    subprocess.run(f"systemctl restart {primary_service_name}.service", shell=True, check=False)
        else:
            self.disable_runtime(primary_service_name, primary_unit_path, primary_start_script, primary_runtime_path)

        sidepath = self.config.get("udp_uot_sidepath", {})
        if not isinstance(sidepath, dict):
            sidepath = {}
        enabled_forward_ids = {
            int(value)
            for value in sidepath.get("enabled_forward_ids", [])
            if str(value).strip().isdigit()
        }
        if cf_runtime is not None and isinstance(cf_runtime.get("routes"), list):
            for route in cf_runtime.get("routes", []):
                if not isinstance(route, dict):
                    continue
                if "udp" not in str(route.get("transport_mode", "") or "").lower():
                    continue
                enabled_forward_ids.add(int(route.get("forward_id", 0) or 0))

        udp_service_name = self.udp_uot_service_name()
        udp_unit_path = self.unit_path_for(udp_service_name)
        udp_runtime_path = self.udp_uot_runtime_config_path()
        udp_start_script = self.udp_uot_start_script_path()

        if (bond_runtime is not None or cf_runtime is not None) and enabled_forward_ids:
            udp_bind_host = "0.0.0.0"
            udp_token_secret = ""
            if bond_runtime is not None:
                udp_bind_host = str(bond_runtime.get("bind_host", "0.0.0.0") or "0.0.0.0")
                udp_token_secret = str(bond_runtime.get("token_secret", "") or "")
            elif cf_runtime is not None:
                udp_bind_host = str(cf_runtime.get("listen_host", "0.0.0.0") or "0.0.0.0")
                udp_token_secret = str(management.get("token_secret", "") or "")
            runtime_config = {
                "listen_host": udp_bind_host,
                "listen_port": int(sidepath.get("sidecar_port", 8999) or 8999),
                "token_secret": udp_token_secret,
                "allowed_forward_ids": sorted(enabled_forward_ids),
                "reorder_window_packets": int(sidepath.get("reorder_window_packets", 4096) or 4096),
                "reorder_window_bytes": int(sidepath.get("reorder_window_bytes", 16777216) or 16777216),
                "reorder_timeout_ms": int(sidepath.get("reorder_timeout_ms", 25) or 25),
                "stats_path": str(self.state_dir / "udp-uot-runtime-stats.json"),
            }
            runtime_changed = write_text_if_changed(udp_runtime_path, json.dumps(runtime_config, ensure_ascii=False, indent=2) + "\n", mode=0o600)
            start_script_lines = [
                "#!/usr/bin/env bash",
                "set -euo pipefail",
                f"exec /usr/local/bin/leikwan-udp-uot-merge --config {udp_runtime_path}",
            ]
            start_changed = write_text_if_changed(udp_start_script, "\n".join(start_script_lines) + "\n", mode=0o755)
            unit_body = f"""[Unit]
Description=Leikwan UDP UoT Sidepath
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/bin/bash {udp_start_script}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
            unit_changed = write_text_if_changed(udp_unit_path, unit_body)
            if self.systemd_available():
                if unit_changed or runtime_changed or start_changed:
                    subprocess.run("systemctl daemon-reload", shell=True, check=False)
                if unit_changed or runtime_changed or start_changed or not self.systemd_is_active(udp_service_name):
                    subprocess.run(f"systemctl enable {udp_service_name}.service", shell=True, check=False)
                    subprocess.run(f"systemctl restart {udp_service_name}.service", shell=True, check=False)
        else:
            self.disable_runtime(udp_service_name, udp_unit_path, udp_start_script, udp_runtime_path)

        if mptcp_runtime is not None:
            self.ensure_relay_binary()
            underlay = dict(mptcp_runtime.get("underlay", {})) if isinstance(mptcp_runtime.get("underlay", {}), dict) else {}
            if underlay != {}:
                underlay["local_private_key_path"] = str(self.wireguard_private_key_path())
                underlay.setdefault("listen_port", self.wireguard_listen_port())
            mptcp_policy = dict(mptcp_runtime.get("mptcp_policy", {})) if isinstance(mptcp_runtime.get("mptcp_policy", {}), dict) else {}
            raw_underlays = mptcp_runtime.get("underlays", [])
            underlays = []
            if isinstance(raw_underlays, list):
                base_listen_port = self.wireguard_listen_port()
                for idx, raw_ul in enumerate(raw_underlays):
                    if not isinstance(raw_ul, dict):
                        continue
                    ul = dict(raw_ul)
                    ul["local_private_key_path"] = str(self.wireguard_private_key_path())
                    ul.setdefault("listen_port", base_listen_port + idx)
                    underlays.append(ul)
            runtime_config = {
                "listen_host": str(mptcp_runtime.get("bind_host", "0.0.0.0") or "0.0.0.0"),
                "listen_port": int(mptcp_runtime.get("listen_port", 0) or 0),
                "token_secret": str(mptcp_runtime.get("token_secret", "") or ""),
                "stats_path": str(self.state_dir / "mptcp-runtime-stats.json"),
                "log_level": str(self.config.get("log_level", "INFO")),
                "tcp_congestion_control": str(self.config.get("tcp_congestion_control", "bbr") or "bbr"),
                "allow_tcp_fallback": bool(self.config.get("allow_tcp_fallback", False)),
                "underlay": underlay if underlay != {} else self.config.get("underlay", {}),
                "underlays": underlays,
                "manage_mptcp_endpoints": bool(mptcp_policy.get("endpoints")),
                "mptcp_endpoint_device": str(mptcp_policy.get("endpoint_device", "") or ""),
                "mptcp_endpoint_id_base": int(mptcp_policy.get("endpoint_id_base", 0) or 0),
                "mptcp_endpoints": mptcp_policy.get("endpoints", []),
                "subflow_limit": max(1, len(mptcp_policy.get("endpoints", [])) if isinstance(mptcp_policy.get("endpoints", []), list) else 1),
                "forward_routes_path": str(self.mptcp_forward_routes_path()),
                "forward_routes": mptcp_runtime.get("forward_routes", []) if isinstance(mptcp_runtime.get("forward_routes", []), list) else [],
            }
            runtime_changed = write_text_if_changed(secondary_runtime_path, json.dumps(runtime_config, ensure_ascii=False, indent=2) + "\n", mode=0o600)
            rust_relay_path = pathlib.Path(str(self.state_dir)) / "leikwan-mptcp-relay"
            start_script_lines = [
                "#!/usr/bin/env bash",
                "set -euo pipefail",
                f'/usr/bin/python3 {self.runtime_bin()} --config {secondary_runtime_path} --setup-only',
                f'RUST_RELAY="{rust_relay_path}"',
                'if [ -x "$RUST_RELAY" ]; then',
                f'    exec "$RUST_RELAY" server --config {secondary_runtime_path}',
                'else',
                f'    exec /usr/bin/python3 {self.runtime_bin()} --config {secondary_runtime_path}',
                'fi',
            ]
            start_script_changed = write_text_if_changed(secondary_start_script, "\n".join(start_script_lines) + "\n", mode=0o755)
            if bond_runtime is None:
                write_text_if_changed(primary_start_script, "\n".join(start_script_lines) + "\n", mode=0o755)
            unit_body = f"""[Unit]
Description=Leikwan MPTCP Sidecar
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/bin/bash {secondary_start_script}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
            unit_changed = write_text_if_changed(secondary_unit_path, unit_body)
            if self.systemd_available():
                if unit_changed or runtime_changed or start_script_changed:
                    subprocess.run("systemctl daemon-reload", shell=True, check=False)
                if unit_changed or runtime_changed or start_script_changed or not self.systemd_is_active(secondary_service_name):
                    subprocess.run(f"systemctl enable {secondary_service_name}.service", shell=True, check=False)
                    subprocess.run(f"systemctl restart {secondary_service_name}.service", shell=True, check=False)
            # Accumulation fix: reap any lw-sc-<point>-* not in the currently-published
            # set (e.g. when legs shrink from 5 to 2, indices 2,3,4 get deleted).
            self.cleanup_stale_mptcp_transit_interfaces(
                point_id,
                keep={
                    str(ul.get("interface_name", ""))
                    for ul in underlays
                    if str(ul.get("interface_name", ""))
                },
            )
        else:
            self.disable_runtime(secondary_service_name, secondary_unit_path, secondary_start_script, secondary_runtime_path)
            self.cleanup_stale_mptcp_transit_interfaces(point_id)

    def probe_donation_target(self, target: dict) -> dict:
        host = str(target.get("public_host", "") or "").strip()
        port = int(target.get("public_port", 0) or 0)
        protocol = str(target.get("protocol", "tcp") or "tcp").strip().lower()
        started = time.time()
        ok = False
        error = ""
        if host == "" or port <= 0:
            error = "missing_target"
        elif protocol == "udp":
            try:
                with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
                    sock.settimeout(1.0)
                    sock.sendto(b"leikwan-donation-probe", (host, port))
                    ok = True
            except Exception as exc:  # noqa: BLE001
                error = str(exc)
        else:
            try:
                with socket.create_connection((host, port), timeout=1.0):
                    ok = True
            except Exception as exc:  # noqa: BLE001
                error = str(exc)
        result = {
            "ok": ok,
            "latency_ms": round((time.time() - started) * 1000, 1) if ok else None,
            "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        }
        if error:
            result["error"] = error[:200]
        return result

    def build_donation_reverse_probe_payload(self) -> list[dict]:
        # donation_reverse_probe_loop
        reports: list[dict] = []
        now = time.time()
        for target in self.load_donation_reverse_probe_targets():
            donor_id = int(target.get("donor_id", 0) or 0)
            protocol = str(target.get("protocol", "tcp") or "tcp").strip().lower()
            if donor_id <= 0:
                continue
            result = self.probe_donation_target(target)
            report = dict(target)
            report.update(result)
            report["donor_id"] = donor_id
            report["protocol"] = protocol
            reports.append(report)
            if not bool(report.get("ok", False)):
                last_key = f"_donation_probe_log_{donor_id}_{protocol}"
                last_logged = float(getattr(self, last_key, 0.0) or 0.0)
                if now - last_logged >= 60.0:
                    logging.warning("donation reverse probe failed donor=%s protocol=%s error=%s", donor_id, protocol, report.get("error", ""))
                    setattr(self, last_key, now)
        return reports

    def wireguard_peer_state_snapshot(self) -> list[dict]:
        if shutil.which("wg") is None:
            return []
        try:
            result = subprocess.run(
                ["wg", "show", "all", "dump"],
                text=True,
                capture_output=True,
                check=False,
            )
        except OSError:
            return []
        if result.returncode != 0:
            return []
        now = time.time()
        peers: list[dict] = []
        for line in result.stdout.splitlines():
            parts = line.split("\t")
            if len(parts) < 9:
                continue
            interface_name = parts[0].strip()
            peer_public_key = parts[1].strip()
            if interface_name == "" or peer_public_key == "":
                continue
            try:
                latest_handshake = int(parts[5] or "0")
                rx_bytes = int(parts[6] or "0")
                tx_bytes = int(parts[7] or "0")
            except ValueError:
                continue
            handshake_age = None
            if latest_handshake > 0:
                handshake_age = max(0, int(now - latest_handshake))
            peers.append(
                {
                    "interface_name": interface_name,
                    "peer_public_key": peer_public_key,
                    "latest_handshake_epoch": latest_handshake,
                    "handshake_age_seconds": handshake_age,
                    "rx_bytes": max(0, rx_bytes),
                    "tx_bytes": max(0, tx_bytes),
                }
            )
        return peers

    def build_heartbeat_payload(self) -> dict:
        stats = self.load_runtime_stats()
        wireguard_public_key = self.ensure_wireguard_identity()
        local_clock = self.time_sync_state()
        status = "ok"
        health_error = ""
        if wireguard_public_key == "":
            status = "degraded"
            health_error = "wireguard_identity_missing"
            logging.error("WireGuard identity missing; reporting sidecar heartbeat as degraded")
        payload = {
            "status": status,
            "version": AGENT_VERSION,
            "usage_bytes_in": int(stats.get("bytes_in", 0) or 0),
            "usage_bytes_out": int(stats.get("bytes_out", 0) or 0),
            "supports_mptcp": self.running_kernel_supports_mptcp(),
            "wireguard_public_key": wireguard_public_key,
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            "clock_sync": local_clock,
        }
        payload["agent_sha256"] = file_sha256(pathlib.Path(__file__).resolve())
        payload["agent_update_state"] = self.load_update_state()
        if health_error != "":
            payload["health_error"] = health_error
        bond_leg_reputation = self.load_bond_leg_reputation_snapshot()
        if bond_leg_reputation is not None:
            payload["bond_leg_reputation"] = bond_leg_reputation
        wireguard_peers = self.wireguard_peer_state_snapshot()
        if wireguard_peers:
            payload["wireguard_peer_state"] = wireguard_peers
        donation_reverse_probe = self.build_donation_reverse_probe_payload()
        if donation_reverse_probe:
            payload["donation_reverse_probe"] = donation_reverse_probe
        return payload

    def heartbeat_once(self) -> None:
        master = str(self.config.get("master_url", "")).rstrip("/")
        token = str(self.config.get("agent_token", ""))
        payload = self.build_heartbeat_payload()
        response, response_headers = http_json_with_headers("POST", f"{master}/api/sidecar/heartbeat", token, payload)
        management = response.get("management", {})
        if not isinstance(management, dict):
            raise RuntimeError("invalid management payload from sidecar heartbeat")
        self.apply_management(management)
        now = time.time()
        self.save_pull_state(
            {
                "status": "ok",
                "last_attempt_at": now,
                "last_success_at": now,
                "service_name": self.service_name(),
                "listen_port": int(management.get("listen_port", 0) or 0),
                "runtime_bin": self.runtime_bin(),
                "runtime_config_path": str(self.runtime_config_path()),
                "clock": self.panel_clock_state_from_headers(response_headers),
            }
        )

    def auto_update_request_token(self, management: dict) -> str:
        if not isinstance(management, dict):
            return ""
        # Apply any panel config_patch (e.g. unpin disable_auto_update) before the
        # disable gate so a standalone token computation honors the same unpin that
        # apply_management would. apply_config_patch enforces disable_auto_update_lock.
        config_patch = management.get("config_patch", {})
        if isinstance(config_patch, dict) and config_patch != {}:
            self.apply_config_patch(config_patch)
        if self.auto_update_disabled():
            return ""

        desired_agent_version = str(management.get("desired_sidecar_agent_version", "") or "").strip()
        desired_agent_sha256 = str(management.get("desired_sidecar_agent_sha256", "") or "").strip().lower()
        desired_installer_version = str(management.get("desired_installer_version", "") or "").strip()
        desired_bond_sha256 = str(management.get("desired_bond_sha256", "") or "").strip().lower()
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

        desired_wss_mux_sha256 = str(management.get("desired_wss_mux_sha256", "") or "").strip().lower()
        if desired_wss_mux_sha256:
            local_wss_mux_sha256 = file_sha256(pathlib.Path("/usr/local/bin/leikwan-wss-mux.py")).lower()
            if local_wss_mux_sha256 != desired_wss_mux_sha256:
                mismatches.append(f"wss_mux={desired_wss_mux_sha256}")

        desired_sidecar_runtime_sha256 = str(management.get("desired_sidecar_runtime_sha256", "") or "").strip().lower()
        if desired_sidecar_runtime_sha256:
            local_sidecar_runtime_sha256 = file_sha256(pathlib.Path(self.runtime_bin())).lower()
            if local_sidecar_runtime_sha256 != desired_sidecar_runtime_sha256:
                mismatches.append(f"sidecar_runtime={desired_sidecar_runtime_sha256}")

        if mismatches == []:
            return ""

        return "auto-release:" + "|".join(mismatches)

    def management_requires_local_update(self, management: dict) -> bool:
        return self.auto_update_request_token(management) != ""

    def request_self_update(self, request_token: str) -> None:
        helper_path = pathlib.Path("/usr/local/bin/leikwan-sidecar-update")
        if not helper_path.exists():
            raise RuntimeError(f"update helper missing: {helper_path}")
        state_path = self.update_state_path()

        if shutil.which("systemd-run"):
            unit_name = f"leikwan-sidecar-update-{int(time.time())}-{request_token[:8]}"
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
                    "--config-path",
                    str(self.config_path),
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
                    "--config-path",
                    str(self.config_path),
                    "--request-token",
                    request_token,
                    "--state-path",
                    str(state_path),
                ],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                stdin=subprocess.DEVNULL,
            )
        logging.info("requested sidecar self-update token=%s", request_token)

    def run(self) -> None:
        interval = max(5, int(self.config.get("heartbeat_interval_seconds", 30) or 30))
        while True:
            started = time.time()
            try:
                self.heartbeat_once()
            except Exception as exc:  # noqa: BLE001
                logging.exception("sidecar heartbeat failed: %s", exc)
                self.save_pull_state(
                    {
                        "status": "error",
                        "last_attempt_at": started,
                        "last_error": str(exc),
                        "service_name": self.service_name(),
                    }
                )
            sleep_for = max(1, interval - (time.time() - started))
            time.sleep(sleep_for)

    def check(self) -> dict:
        wireguard_public_key = self.ensure_wireguard_identity()
        return {
            "ok": True,
            "agent_version": AGENT_VERSION,
            "config_path": str(self.config_path),
            "master_url": str(self.config.get("master_url", "")),
            "service_name": self.service_name(),
            "service_active": self.systemd_is_active(),
            "runtime_bin": self.runtime_bin(),
            "runtime_config_path": str(self.runtime_config_path()),
            "state_dir": str(self.state_dir),
            "supports_mptcp": self.running_kernel_supports_mptcp(),
            "wireguard_public_key_present": wireguard_public_key != "",
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            "pull_state": json.loads(self.pull_state_path.read_text(encoding="utf-8")) if self.pull_state_path.exists() else {},
        }


def main() -> int:
    parser = argparse.ArgumentParser(description="Leikwan sidecar agent")
    parser.add_argument("--config", default="/etc/leikwan-sidecar/config.json")
    parser.add_argument("command", nargs="?", choices=["run", "check", "version"], default="run")
    args = parser.parse_args()
    agent = SidecarAgent(pathlib.Path(args.config))
    if args.command == "version":
        print(AGENT_VERSION)
        return 0
    if args.command == "check":
        print(json.dumps(agent.check(), ensure_ascii=False, indent=2))
        return 0
    agent.run()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
