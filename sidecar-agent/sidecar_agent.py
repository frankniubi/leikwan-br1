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
import time
import urllib.error
import urllib.request

AGENT_VERSION = "2026.05.17.2"
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
        path.write_text(content, encoding="utf-8")
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
        private_key = private_key_path.read_text(encoding="utf-8").strip()
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
        private_key = self.wireguard_private_key_path().read_text(encoding="utf-8").strip()
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

    def apply_slave_sidecar_uplink(self, uplink: dict[str, object]) -> None:
        if not uplink.get("enabled") or shutil.which("wg") is None or shutil.which("wg-quick") is None:
            return
        self.ensure_wireguard_identity()
        private_key = self.wireguard_private_key_path().read_text(encoding="utf-8").strip()
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
            self.ensure_sidecar_cf_wstunnel_backend(interface_name, cf_path_prefix, cf_target_host, cf_target_port)
        else:
            self.remove_sidecar_cf_wstunnel_backend(interface_name)
        keepalive_line = f"PersistentKeepalive = {keepalive}\n" if keepalive > 0 else ""
        config = (
            "[Interface]\n"
            f"PrivateKey = {private_key}\n"
            f"ListenPort = {listen_port}\n"
            f"Address = {local_address}/32\n"
            "\n"
            "[Peer]\n"
            f"PublicKey = {peer_public_key}\n"
            f"AllowedIPs = {peer_address}/32\n"
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

    def restart_slave_sidecar_uplink(self, interface_name: str) -> None:
        iface = str(interface_name or "").strip()[:15]
        if iface == "" or shutil.which("systemctl") is None:
            return
        subprocess.run(["systemctl", "restart", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)

    def cleanup_stale_mptcp_transit_interfaces(self, point_id: int) -> None:
        if point_id <= 0 or shutil.which("wg") is None:
            return
        result = subprocess.run(["wg", "show", "interfaces"], text=True, capture_output=True, check=False)
        interfaces = result.stdout.strip().split() if result.returncode == 0 else []
        scoped_prefix = f"lw-sc-{point_id}-"
        for iface in interfaces:
            if not iface.startswith(scoped_prefix):
                continue
            if shutil.which("systemctl") is not None:
                subprocess.run(["systemctl", "stop", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
                subprocess.run(["systemctl", "disable", f"wg-quick@{iface}.service"], text=True, capture_output=True, check=False)
            elif shutil.which("wg-quick") is not None:
                subprocess.run(["wg-quick", "down", iface], text=True, capture_output=True, check=False)
            conf_path = pathlib.Path(f"/etc/wireguard/{iface}.conf")
            if conf_path.exists():
                conf_path.unlink()

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

    def ensure_sidecar_cf_wstunnel_backend(self, interface_name: str, path_prefix: str, target_host: str, target_port: int) -> None:
        """Create wstunnel server backend for cf_mode slave transit underlay.

        This creates a standalone wstunnel server that:
        1. Listens on a dynamically allocated local port (26000-26099 range)
        2. Accepts WebSocket connections with the specified path prefix
        3. Forwards UDP traffic to the target (WireGuard listen port)

        The CF mux will route incoming WSS connections to this backend based on path prefix.
        """
        if path_prefix == "" or target_host == "" or target_port <= 0:
            return

        service_name = f"leikwan-cf-slave-{interface_name}"

        # Allocate a backend port for this service
        backend_port = self.allocate_cf_backend_port(service_name)

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
                if "disable_auto_update" in self.config:
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
ExecStart={iperf3_bin} -s -p {int(port)} --one-off
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
        for port in range(26000, 26100):
            if port not in used and port not in reserved:
                return port
        raise RuntimeError("no free sidecar cf backend port available")

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
        if bool(self.config.get("disable_auto_update", False)):
            explicit_request_token = ""
            auto_request_token = ""
        update_state = self.load_update_state()
        completed_request_token = str(update_state.get("last_completed_request_token", "") or "")

        if explicit_request_token != "" and completed_request_token != explicit_request_token and not self.management_requires_local_update(management):
            now = time.time()
            update_state.update(
                {
                    "request_token": explicit_request_token,
                    "status": "success",
                    "last_attempt_at": now,
                    "last_completed_request_token": explicit_request_token,
                    "last_completed_at": now,
                    "installed_version": AGENT_VERSION,
                    "installed_bond_sha256": file_sha256(self.bond_binary_path()),
                    "last_error": "",
                }
            )
            self.save_update_state(update_state)
            explicit_request_token = ""

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

        sidecar_uplinks = management.get("sidecar_uplinks", []) if isinstance(management.get("sidecar_uplinks", []), list) else []
        current_uplink_state = self.load_sidecar_uplink_state()
        active_interfaces: set[str] = set()
        for uplink in sidecar_uplinks:
            if not isinstance(uplink, dict):
                continue
            interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
            if interface_name == "":
                continue
            active_interfaces.add(interface_name)
            self.apply_slave_sidecar_uplink(uplink)
        existing_slaves = current_uplink_state.get("slaves", {}) if isinstance(current_uplink_state.get("slaves", {}), dict) else {}
        for slave_id, info in list(existing_slaves.items()):
            iface = str((info if isinstance(info, dict) else {}).get("interface_name", "") or "").strip()[:15]
            if iface != "" and iface not in active_interfaces:
                self.remove_slave_sidecar_uplink({"interface_name": iface})
                existing_slaves.pop(slave_id, None)
        if sidecar_uplinks:
            next_state = {"slaves": {}}
            for uplink in sidecar_uplinks:
                if not isinstance(uplink, dict):
                    continue
                interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
                if interface_name == "":
                    continue
                slave_key = interface_name.split("-")[-1]
                next_state["slaves"][slave_key] = {"interface_name": interface_name}
            self.save_sidecar_uplink_state(next_state)

        self_leg_client_peers = management.get("self_leg_client_peers", []) if isinstance(management.get("self_leg_client_peers", []), list) else []
        current_self_leg_state = self.load_self_leg_client_peer_state()
        active_self_leg_interfaces: set[str] = set()
        for peer in self_leg_client_peers:
            if not isinstance(peer, dict):
                continue
            interface_name = str(peer.get("interface_name", "") or "").strip()[:15]
            if interface_name == "":
                continue
            active_self_leg_interfaces.add(interface_name)
            self.apply_self_leg_client_peer(peer)
        existing_self_leg = current_self_leg_state.get("interfaces", {}) if isinstance(current_self_leg_state.get("interfaces", {}), dict) else {}
        for key, info in list(existing_self_leg.items()):
            iface = str((info if isinstance(info, dict) else {}).get("interface_name", key) or "").strip()[:15]
            if iface != "" and iface not in active_self_leg_interfaces:
                self.remove_self_leg_client_peer({"interface_name": iface})
                existing_self_leg.pop(key, None)
        if self_leg_client_peers:
            next_self_leg_state = {"interfaces": {}}
            for peer in self_leg_client_peers:
                if not isinstance(peer, dict):
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
                previous_cf_services = {
                    str(item.get("service_name", "") or "")
                    for item in self.load_managed_cf_metadata()
                    if isinstance(item, dict) and str(item.get("service_name", "") or "") != ""
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
                for stale_service in sorted(previous_cf_services - active_cf_services):
                    if self.systemd_available():
                        self.disable_runtime(stale_service, self.unit_path_for(stale_service))

                # Add slave transit CF backends to rendered routes
                # These are created by apply_slave_sidecar_uplink and registered in CF metadata
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
                    # Add this slave transit backend to the routes
                    rendered_routes.append({
                        "forward_id": 0,  # Slave transit backends don't have a forward_id
                        "service_name": service_name,
                        "path_prefix": str(metadata_entry.get("path_prefix", "") or ""),
                        "target": str(metadata_entry.get("target", "") or ""),
                        "backend_host": "127.0.0.1",
                        "backend_port": int(metadata_entry.get("backend_port", 0) or 0),
                        "backend_mode": "wstunnel",
                        "transport_mode": "udp",
                        "shadow_route_key": "",
                    })
                    active_cf_services.add(service_name)

                self.save_managed_cf_metadata(rendered_routes)
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

    def build_heartbeat_payload(self) -> dict:
        stats = self.load_runtime_stats()
        wireguard_public_key = self.ensure_wireguard_identity()
        local_clock = self.time_sync_state()
        payload = {
            "status": "ok",
            "version": AGENT_VERSION,
            "usage_bytes_in": int(stats.get("bytes_in", 0) or 0),
            "usage_bytes_out": int(stats.get("bytes_out", 0) or 0),
            "supports_mptcp": self.running_kernel_supports_mptcp(),
            "wireguard_public_key": wireguard_public_key,
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            "clock_sync": local_clock,
        }
        bond_leg_reputation = self.load_bond_leg_reputation_snapshot()
        if bond_leg_reputation is not None:
            payload["bond_leg_reputation"] = bond_leg_reputation
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
        if bool(self.config.get("disable_auto_update", False)):
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
