#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import base64
import hashlib
import hmac
import json
import logging
import os
import pathlib
import re
import socket
import struct
import subprocess
import time
from typing import Any

MPTCP_PROTOCOL = getattr(socket, "IPPROTO_MPTCP", 262)
FRAME_HEADER_BYTES = 4
PIPE_READ_CHUNK_BYTES = 256 * 1024
PIPE_DRAIN_THRESHOLD_BYTES = 1024 * 1024
DEFAULT_SOCKET_BUFFER_BYTES = 8 * 1024 * 1024


def load_json(path: pathlib.Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(payload, dict):
        raise RuntimeError(f"config must be an object: {path}")
    return payload


def truthy(value: object) -> bool:
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


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


def tune_socket(sock: socket.socket | None, tcp_congestion_control: str | None = None, socket_buffer_bytes: int = 0) -> None:
    if sock is None:
        return
    buf_size = socket_buffer_bytes if socket_buffer_bytes > 0 else DEFAULT_SOCKET_BUFFER_BYTES
    with Suppress():
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, buf_size)
    with Suppress():
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, buf_size)
    with Suppress():
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    desired_cc = str(tcp_congestion_control or "").strip()
    if desired_cc != "" and hasattr(socket, "TCP_CONGESTION"):
        with Suppress():
            sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_CONGESTION, desired_cc.encode("ascii"))


def run_command(parts: list[str], check: bool = False) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(parts, text=True, capture_output=True, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(
            f"command failed: {' '.join(parts)}\nstdout={result.stdout}\nstderr={result.stderr}"
        )
    return result


def shutil_which(command: str) -> str | None:
    try:
        import shutil

        return shutil.which(command)
    except Exception:  # noqa: BLE001
        return None


def apply_wireguard_underlay(config: dict[str, Any]) -> None:
    if os.name == "nt":
        return
    interfaces = config.get("interfaces", [])
    if not isinstance(interfaces, list):
        return
    for item in interfaces:
        if not isinstance(item, dict):
            continue
        name = str(item.get("name", "") or "").strip()
        path_value = str(item.get("path", "") or "").strip()
        if path_value == "" and name != "":
            path_value = f"/etc/wireguard/{name}.conf"
        if path_value == "":
            continue
        changed = False
        if str(item.get("config", "") or "") != "":
            changed = write_text_if_changed(pathlib.Path(path_value), str(item.get("config", "")), mode=0o600)
        if truthy(item.get("autostart", True)) and name != "" and shutil_which("systemctl"):
            if changed:
                run_command(["systemctl", "daemon-reload"], check=False)
            run_command(["systemctl", "enable", f"wg-quick@{name}.service"], check=False)
            run_command(["systemctl", "restart", f"wg-quick@{name}.service"], check=False)


def apply_ipsec_underlay(config: dict[str, Any]) -> None:
    if os.name == "nt":
        return
    files = config.get("files", [])
    if isinstance(files, list):
        for item in files:
            if not isinstance(item, dict):
                continue
            path_value = str(item.get("path", "") or "").strip()
            if path_value == "":
                continue
            write_text_if_changed(
                pathlib.Path(path_value),
                str(item.get("content", "") or ""),
                int(item["mode"]) if "mode" in item and item.get("mode") is not None else None,
            )
    reload_commands = config.get("reload_commands", [])
    if isinstance(reload_commands, list):
        for command in reload_commands:
            if isinstance(command, list) and command:
                run_command([str(part) for part in command], check=False)


def build_wireguard_interface_config(config: dict[str, Any]) -> str:
    private_key_path = str(config.get("local_private_key_path", "") or "").strip()
    if private_key_path == "":
        raise RuntimeError("wireguard underlay missing local_private_key_path")
    private_key = pathlib.Path(private_key_path).read_text(encoding="utf-8").strip()
    listen_port = max(0, int(config.get("listen_port", 0) or 0))
    peer_keepalive = max(0, int(config.get("peer_keepalive_seconds", 25) or 25))
    legs = config.get("legs", [])
    if not isinstance(legs, list) or legs == []:
        raise RuntimeError("wireguard underlay missing legs")

    interface_name = str(config.get("interface_name", "") or "").strip()
    seen_addresses: list[str] = []
    peer_blocks: list[str] = []
    route_lines: list[str] = []
    seen_route_pairs: set[tuple[str, str]] = set()
    initcwnd = int(config.get("initcwnd", 80) or 80)
    initrwnd = int(config.get("initrwnd", 80) or 80)
    for leg in legs:
        if not isinstance(leg, dict):
            continue
        local_address = str(leg.get("local_address", "") or "").strip()
        peer_address = str(leg.get("peer_address", "") or "").strip()
        overlay_address = str(leg.get("mptcp_remote_address", "") or "").strip()
        peer_public_key = str(leg.get("peer_public_key", "") or "").strip()
        peer_host = str(leg.get("peer_public_host", "") or "").strip()
        outer_transport = str(leg.get("outer_transport", "udp") or "udp").strip().lower()
        peer_port = int(leg.get("peer_udp_port", 0) or 0)
        if outer_transport == "tcp":
            peer_host = "127.0.0.1"
            peer_port = int(leg.get("local_uot_udp_port", leg.get("peer_tcp_fallback_port", 0)) or 0)
        if local_address != "" and local_address not in seen_addresses:
            seen_addresses.append(local_address)
        if interface_name != "" and local_address != "":
            for route_target in (overlay_address, peer_address):
                route_target = str(route_target or "").strip()
                if route_target == "" or route_target == local_address:
                    continue
                route_key = (local_address, route_target)
                if route_key in seen_route_pairs:
                    continue
                seen_route_pairs.add(route_key)
                route_lines.append(f"PostUp = ip -4 route replace {route_target}/32 dev {interface_name} src {local_address} initcwnd {initcwnd} initrwnd {initrwnd}")
                route_lines.append(f"PostDown = ip -4 route del {route_target}/32 dev {interface_name} >/dev/null 2>&1 || true")
        if peer_public_key == "" or peer_host == "" or peer_port <= 0:
            continue
        allowed_ips = [item for item in [peer_address, overlay_address] if item != ""]
        peer_blocks.append(
            "[Peer]\n"
            f"PublicKey = {peer_public_key}\n"
            f"Endpoint = {peer_host}:{peer_port}\n"
            f"AllowedIPs = {', '.join(ip + '/32' for ip in allowed_ips)}\n"
            + (f"PersistentKeepalive = {peer_keepalive}\n" if peer_keepalive > 0 else "")
        )

    interface_lines = ["[Interface]", f"PrivateKey = {private_key}"]
    if listen_port > 0:
        interface_lines.append(f"ListenPort = {listen_port}")
    if seen_addresses:
        interface_lines.append("Address = " + ", ".join(address + "/32" for address in seen_addresses))
    interface_lines.extend(route_lines)
    interface_lines.append("")
    return "\n".join(interface_lines) + "\n" + "\n".join(peer_blocks)


def apply_wireguard_transit_underlays(underlays: list[dict[str, Any]], parent_config: dict[str, Any]) -> None:
    """Create N WG interfaces, one per underlay entry (one leg each)."""
    if os.name == "nt":
        return
    active_iface_names: set[str] = set()
    for underlay in underlays:
        if not isinstance(underlay, dict):
            continue
        merged = dict(underlay)
        for key in ("local_private_key_path", "initcwnd", "initrwnd"):
            if key not in merged and key in parent_config:
                merged[key] = parent_config[key]
        apply_wireguard_transit_underlay_single(merged)
        name = str(merged.get("interface_name", "") or "").strip()
        if name:
            active_iface_names.add(name)
    cleanup_stale_transit_interfaces(parent_config, active_iface_names)


def cleanup_stale_transit_interfaces(config: dict[str, Any], active_names: set[str]) -> None:
    """Remove WG interfaces from previous config that are no longer needed."""
    if os.name == "nt" or not shutil_which("wg"):
        return
    try:
        result = run_command(["wg", "show", "interfaces"], check=False)
        current_interfaces = result.stdout.strip().split()
    except Exception:
        return
    scoped_prefixes: set[str] = set()
    point_id = int(config.get("point_id", 0) or 0)
    if point_id > 0:
        scoped_prefixes.add(f"lw-sc-{point_id}-")
    for name in active_names:
        if not name.startswith("lw-sc-"):
            continue
        prefix = name.rsplit("-", 1)[0] + "-"
        scoped_prefixes.add(prefix)
    for iface in current_interfaces:
        if not iface.startswith("lw-sc-"):
            continue
        if scoped_prefixes and not any(iface.startswith(prefix) for prefix in scoped_prefixes):
            continue
        if iface in active_names:
            continue
        logging.info("removing stale transit interface: %s", iface)
        if shutil_which("systemctl"):
            run_command(["systemctl", "stop", f"wg-quick@{iface}.service"], check=False)
            run_command(["systemctl", "disable", f"wg-quick@{iface}.service"], check=False)
        elif shutil_which("wg-quick"):
            run_command(["wg-quick", "down", iface], check=False)
        conf_path = pathlib.Path(f"/etc/wireguard/{iface}.conf")
        if conf_path.exists():
            conf_path.unlink()


def apply_wireguard_transit_underlay_single(config: dict[str, Any]) -> None:
    if os.name == "nt":
        return
    name = str(config.get("interface_name", "") or "").strip()
    if name == "":
        raise RuntimeError("wireguard underlay missing interface_name")
    path_value = str(config.get("config_path", "") or "").strip()
    if path_value == "":
        path_value = f"/etc/wireguard/{name}.conf"
    for leg in config.get("legs", []) if isinstance(config.get("legs", []), list) else []:
        if not isinstance(leg, dict):
            continue
        if str(leg.get("outer_transport", "udp") or "udp").strip().lower() != "tcp":
            continue
        ensure_uot_client_service(name, leg)
    path = pathlib.Path(path_value)
    config_text = build_wireguard_interface_config(config)
    changed = write_text_if_changed(path, config_text, mode=0o600)
    if name != "" and shutil_which("systemctl"):
        if changed:
            run_command(["systemctl", "daemon-reload"], check=False)
            run_command(["systemctl", "enable", f"wg-quick@{name}.service"], check=False)
            run_command(["systemctl", "restart", f"wg-quick@{name}.service"], check=False)
        else:
            is_active = run_command(["systemctl", "is-active", f"wg-quick@{name}.service"], check=False)
            if is_active.stdout.strip() != "active":
                run_command(["systemctl", "enable", f"wg-quick@{name}.service"], check=False)
                run_command(["systemctl", "start", f"wg-quick@{name}.service"], check=False)
        return
    if shutil_which("wg-quick") and path.is_file():
        if changed:
            run_command(["wg-quick", "down", str(path)], check=False)
        run_command(["wg-quick", "up", str(path)], check=False)


def ensure_uot_client_service(interface_name: str, leg: dict[str, Any]) -> None:
    if os.name == "nt":
        return
    endpoint_id = int(leg.get("endpoint_id", 0) or 0)
    local_udp_port = local_uot_udp_port(interface_name, leg)
    remote_tcp_port = int(leg.get("peer_tcp_fallback_port", 0) or 0)
    remote_host = str(leg.get("peer_public_host", "") or "").strip()
    if endpoint_id <= 0 or local_udp_port <= 0 or remote_tcp_port <= 0 or remote_host == "":
        return
    service_name = f"leikwan-uot-{interface_name}-{endpoint_id}"
    unit_path = pathlib.Path(f"/etc/systemd/system/{service_name}.service")
    body = f"""[Unit]
Description=Leikwan UoT Client {service_name}
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/leikwan-uot.py client --listen-udp 127.0.0.1:{local_udp_port} --remote-tcp {remote_host}:{remote_tcp_port}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
    changed = write_text_if_changed(unit_path, body)
    if shutil_which("systemctl"):
        if changed:
            run_command(["systemctl", "daemon-reload"], check=False)
        run_command(["systemctl", "enable", f"{service_name}.service"], check=False)
        run_command(["systemctl", "restart", f"{service_name}.service"], check=False)


def apply_underlays(config: dict[str, Any]) -> None:
    """Dispatch underlay setup: supports both old 'underlay' and new 'underlays' formats."""
    underlays = config.get("underlays", [])
    if isinstance(underlays, list) and underlays:
        apply_wireguard_transit_underlays(underlays, config)
        return
    underlay = config.get("underlay", {})
    if isinstance(underlay, dict):
        apply_underlay(underlay)


def local_uot_udp_port(interface_name: str, leg: dict[str, Any]) -> int:
    configured = int(leg.get("local_uot_udp_port", 0) or 0)
    if configured > 0:
        return configured
    forward_id = int(leg.get("forward_id", 0) or 0)
    if forward_id <= 0:
        match = re.match(r"^lw-sc-(\d+)-", str(interface_name or "").strip())
        if match is not None:
            forward_id = int(match.group(1))
    node_id = int(leg.get("node_id", leg.get("endpoint_id", 0)) or 0)
    if forward_id > 0 and node_id > 0:
        return 45000 + abs(((forward_id * 257) + (node_id * 17)) % 15000)
    return int(leg.get("peer_tcp_fallback_port", 0) or 0)


def apply_underlay(config: dict[str, Any]) -> None:
    if not isinstance(config, dict):
        return
    underlay_type = str(config.get("type", "") or "").strip().lower()
    if underlay_type == "wireguard":
        if str(config.get("mode", "") or "").strip() == "slave_transit":
            apply_wireguard_transit_underlay_single(config)
        else:
            apply_wireguard_underlay(config)
    elif underlay_type in {"ipsec", "ikev2", "ikev2_ipsec"}:
        apply_ipsec_underlay(config)


def configure_mptcp_policy(config: dict[str, Any]) -> None:
    if os.name == "nt" or not truthy(config.get("manage_mptcp_endpoints", False)):
        return
    if not shutil_which("ip"):
        return
    subflow_limit = max(1, int(config.get("subflow_limit", 2) or 2))
    run_command(["ip", "mptcp", "limits", "set", "subflow", str(subflow_limit), "add_addr_accepted", str(subflow_limit)], check=False)

    # Apply MPTCP and TCP sysctls from config
    add_addr_timeout = int(config.get("add_addr_timeout", 5) or 5)
    tcp_wmem_max = int(config.get("tcp_wmem_max", 16777216) or 16777216)
    tcp_rmem_max = int(config.get("tcp_rmem_max", 16777216) or 16777216)
    sysctls = {
        "net.mptcp.add_addr_timeout": str(add_addr_timeout),
        "net.mptcp.stale_loss_cnt": "2",
        "net.ipv4.tcp_wmem": f"4096 65536 {tcp_wmem_max}",
        "net.ipv4.tcp_rmem": f"4096 131072 {tcp_rmem_max}",
        "net.core.wmem_max": str(tcp_wmem_max),
        "net.core.rmem_max": str(tcp_rmem_max),
        "net.ipv4.tcp_syn_retries": "2",
        "net.ipv4.tcp_no_metrics_save": "1",
    }
    for key, value in sysctls.items():
        run_command(["sysctl", "-w", f"{key}={value}"], check=False)

    endpoint_device = str(config.get("mptcp_endpoint_device", "") or "").strip()
    endpoint_id_base = int(config.get("mptcp_endpoint_id_base", 0) or 0)

    endpoint_entries = config.get("mptcp_endpoints", [])
    if not isinstance(endpoint_entries, list):
        return
    desired_entries: list[dict[str, Any]] = []
    for item in endpoint_entries:
        if isinstance(item, dict):
            address = str(item.get("address", "") or "").strip()
            backup_only = truthy(item.get("backup_only", False))
            signal = truthy(item.get("signal", False))
            subflow = truthy(item.get("subflow", False))
            fullmesh = truthy(item.get("fullmesh", False))
            dev = str(item.get("dev", "") or "").strip()
        else:
            address = str(item).strip()
            backup_only = False
            signal = False
            subflow = False
            fullmesh = False
            dev = ""
        if address == "":
            continue
        desired_entries.append(
            {
                "address": address,
                "backup_only": backup_only,
                "signal": signal,
                "subflow": subflow,
                "fullmesh": fullmesh,
                "dev": dev,
            }
        )

    def expected_flags(entry: dict[str, Any]) -> set[str]:
        flags: set[str] = set()
        if truthy(entry.get("subflow", False)):
            flags.add("subflow")
        if truthy(entry.get("signal", False)):
            flags.add("signal")
        if truthy(entry.get("backup_only", False)):
            flags.add("backup")
        if truthy(entry.get("fullmesh", False)):
            flags.add("fullmesh")
        return flags

    def relevant_flags(entry: dict[str, Any]) -> set[str]:
        current_flags = entry.get("flags")
        if not isinstance(current_flags, set):
            return set()
        return {flag for flag in current_flags if flag in {"subflow", "signal", "backup", "fullmesh"}}

    def parse_endpoint_show(output: str) -> list[dict[str, Any]]:
        parsed: list[dict[str, Any]] = []
        for raw_line in output.splitlines():
            line = raw_line.strip()
            if line == "" or line.startswith("add_addr_accepted "):
                continue
            parts = line.split()
            if parts == []:
                continue
            entry: dict[str, Any] = {
                "address": parts[0],
                "id": None,
                "dev": "",
                "flags": set(),
            }
            index = 1
            while index < len(parts):
                part = parts[index]
                if part == "id" and (index + 1) < len(parts):
                    try:
                        entry["id"] = int(parts[index + 1])
                    except ValueError:
                        entry["id"] = None
                    index += 2
                    continue
                if part == "dev" and (index + 1) < len(parts):
                    entry["dev"] = parts[index + 1]
                    index += 2
                    continue
                cast_flags = entry["flags"]
                if isinstance(cast_flags, set):
                    cast_flags.add(part)
                index += 1
            parsed.append(entry)
        return parsed

    desired_by_id = {endpoint_id_base + index: entry for index, entry in enumerate(desired_entries, start=1)}
    desired_by_address = {str(entry.get("address", "")): entry for entry in desired_entries if str(entry.get("address", "")) != ""}
    managed_id_end = endpoint_id_base + max(len(desired_entries), 10)

    all_our_devices = {endpoint_device} if endpoint_device else set()
    all_our_devices.update(
        str(entry.get("dev", "") or "").strip()
        for entry in desired_entries
        if str(entry.get("dev", "") or "").strip() != ""
    )

    def in_managed_window(endpoint_id: int) -> bool:
        return endpoint_id_base <= endpoint_id < managed_id_end

    def ensure_endpoint_device_ready(device_name: str) -> None:
        normalized = str(device_name or "").strip()
        if normalized == "":
            return
        result = run_command(["ip", "link", "show", "dev", normalized], check=False)
        if result.returncode != 0:
            raise RuntimeError(f"mptcp endpoint device not ready: {normalized}")

    existing_entries = parse_endpoint_show(run_command(["ip", "mptcp", "endpoint", "show"], check=False).stdout)
    for entry in existing_entries:
        endpoint_id = entry.get("id")
        if not isinstance(endpoint_id, int):
            continue
        expected_entry = desired_by_id.get(endpoint_id)
        entry_address = str(entry.get("address", ""))
        entry_dev = str(entry.get("dev", "") or "")
        address_entry = desired_by_address.get(entry_address)
        delete_entry = False
        if expected_entry is None:
            if in_managed_window(endpoint_id):
                delete_entry = True
            elif entry_dev != "" and entry_dev in all_our_devices:
                delete_entry = True
            elif address_entry is not None:
                expected_dev = str(address_entry.get("dev", "") or endpoint_device)
                delete_entry = (
                    relevant_flags(entry) != expected_flags(address_entry)
                    or (expected_dev != "" and entry_dev != expected_dev)
                )
        else:
            if entry_address != expected_entry["address"] or relevant_flags(entry) != expected_flags(expected_entry):
                delete_entry = True
            else:
                expected_dev = expected_entry.get("dev", "") or endpoint_device
                if expected_dev != "" and entry_dev != expected_dev:
                    delete_entry = True
        if delete_entry:
            run_command(["ip", "mptcp", "endpoint", "delete", "id", str(endpoint_id)], check=False)

    existing_entries = parse_endpoint_show(run_command(["ip", "mptcp", "endpoint", "show"], check=False).stdout)
    for index, desired_entry in desired_by_id.items():
        matching = next(
            (
                entry for entry in existing_entries
                if entry.get("id") == index
                and str(entry.get("address", "")) == desired_entry["address"]
                and relevant_flags(entry) == expected_flags(desired_entry)
                and (entry.get("dev", "") == (desired_entry.get("dev", "") or endpoint_device) or (desired_entry.get("dev", "") == "" and endpoint_device == ""))
            ),
            None,
        )
        if matching is not None:
            continue
        parts = ["ip", "mptcp", "endpoint", "add", desired_entry["address"], "id", str(index)]
        ep_dev = desired_entry.get("dev", "") or endpoint_device
        ensure_endpoint_device_ready(ep_dev)
        if ep_dev != "":
            parts.extend(["dev", ep_dev])
        if truthy(desired_entry.get("subflow", False)):
            parts.append("subflow")
        if truthy(desired_entry.get("signal", False)):
            parts.append("signal")
        if truthy(desired_entry.get("backup_only", False)):
            parts.append("backup")
        if truthy(desired_entry.get("fullmesh", False)):
            parts.append("fullmesh")
        result = run_command(parts, check=False)
        if result.returncode != 0:
            detail = str(result.stderr or result.stdout or "unknown error").strip()
            raise RuntimeError(f"failed to add MPTCP endpoint {desired_entry['address']} on {ep_dev or 'default'}: {detail}")


def base64url_decode(value: str) -> bytes:
    padding = "=" * ((4 - len(value) % 4) % 4)
    return base64.urlsafe_b64decode(value + padding)


def verify_session_token(token: str, secret: str, expected_protocol: str) -> dict[str, Any]:
    left, dot, right = token.partition(".")
    if dot == "" or left == "" or right == "":
        raise RuntimeError("invalid session token format")
    payload_bytes = base64url_decode(left)
    signature = base64url_decode(right)
    expected_signature = hmac.new(secret.encode("utf-8"), payload_bytes, hashlib.sha256).digest()
    if not hmac.compare_digest(signature, expected_signature):
        raise RuntimeError("invalid session token signature")
    payload = json.loads(payload_bytes.decode("utf-8"))
    if not isinstance(payload, dict):
        raise RuntimeError("invalid session token payload")
    if str(payload.get("target_protocol", "")) != expected_protocol:
        raise RuntimeError("protocol mismatch")
    expires_at = int(payload.get("expires_at", 0) or 0)
    if expires_at > 0 and time.time() > expires_at:
        raise RuntimeError("session token expired")
    return payload


class UsageTracker:
    def __init__(self, stats_path: pathlib.Path | None) -> None:
        self.stats_path = stats_path
        self.bytes_in = 0
        self.bytes_out = 0

    def add_in(self, size: int) -> None:
        self.bytes_in += max(0, size)
        self.flush()

    def add_out(self, size: int) -> None:
        self.bytes_out += max(0, size)
        self.flush()

    def flush(self) -> None:
        if self.stats_path is None:
            return
        payload = {
            "bytes_in": self.bytes_in,
            "bytes_out": self.bytes_out,
            "updated_at": int(time.time()),
        }
        self.stats_path.parent.mkdir(parents=True, exist_ok=True)
        self.stats_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


class Suppress:
    def __enter__(self) -> None:
        return None

    def __exit__(self, exc_type, exc, tb) -> bool:
        return exc is not None and isinstance(exc, Exception)


async def read_handshake(reader: asyncio.StreamReader) -> dict[str, Any]:
    length = struct.unpack(">I", await reader.readexactly(FRAME_HEADER_BYTES))[0]
    payload = await reader.readexactly(length)
    decoded = json.loads(payload.decode("utf-8"))
    if not isinstance(decoded, dict):
        raise RuntimeError("handshake must be an object")
    return decoded


async def pipe(
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    usage: UsageTracker,
    inbound: bool,
    propagate_eof_on_empty: bool = True,
    started_event: asyncio.Event | None = None,
    opposite_started_event: asyncio.Event | None = None,
    smart_half_close: bool = False,
) -> None:
    bytes_forwarded = 0
    pending_drain_bytes = 0
    while True:
        chunk = await reader.read(PIPE_READ_CHUNK_BYTES)
        if not chunk:
            if pending_drain_bytes > 0:
                await writer.drain()
            should_half_close = propagate_eof_on_empty or bytes_forwarded > 0
            if should_half_close and smart_half_close and opposite_started_event is not None:
                if opposite_started_event.is_set():
                    should_half_close = False
                else:
                    try:
                        await asyncio.wait_for(opposite_started_event.wait(), timeout=1.0)
                    except TimeoutError:
                        pass
                    if opposite_started_event.is_set():
                        should_half_close = False
            if should_half_close:
                half_close_writer(writer)
            break
        if inbound:
            usage.add_in(len(chunk))
        else:
            usage.add_out(len(chunk))
        writer.write(chunk)
        bytes_forwarded += len(chunk)
        pending_drain_bytes += len(chunk)
        if pending_drain_bytes >= PIPE_DRAIN_THRESHOLD_BYTES:
            await writer.drain()
            pending_drain_bytes = 0
        if started_event is not None:
            started_event.set()


def half_close_writer(writer: asyncio.StreamWriter) -> None:
    with Suppress():
        if writer.can_write_eof():
            writer.write_eof()
            return
    sock = writer.get_extra_info("socket")
    if sock is not None:
        with Suppress():
            sock.shutdown(socket.SHUT_WR)


async def close_writer(writer: asyncio.StreamWriter) -> None:
    with Suppress():
        writer.close()
        await writer.wait_closed()


async def handle_tcp(reader: asyncio.StreamReader, writer: asyncio.StreamWriter, payload: dict[str, Any], usage: UsageTracker) -> None:
    target_host = str(payload.get("target_host", "") or "").strip()
    target_port = int(payload.get("target_port", 0) or 0)
    if target_host == "" or target_port <= 0:
        raise RuntimeError("missing tcp target")
    tcp_congestion_control = str(payload.get("tcp_congestion_control", "") or "").strip()
    socket_buffer_bytes = int(payload.get("socket_buffer_bytes", 0) or 0)
    remote_reader, remote_writer = await asyncio.open_connection(target_host, target_port)
    tune_socket(writer.get_extra_info("socket"), tcp_congestion_control, socket_buffer_bytes)
    tune_socket(remote_writer.get_extra_info("socket"), tcp_congestion_control, socket_buffer_bytes)
    stream_to_target_started = asyncio.Event()
    target_to_stream_started = asyncio.Event()
    try:
        await asyncio.gather(
            pipe(
                reader,
                remote_writer,
                usage,
                inbound=True,
                propagate_eof_on_empty=False,
                started_event=stream_to_target_started,
                opposite_started_event=target_to_stream_started,
                smart_half_close=True,
            ),
            pipe(
                remote_reader,
                writer,
                usage,
                inbound=False,
                started_event=target_to_stream_started,
            ),
        )
    finally:
        await asyncio.gather(close_writer(remote_writer), close_writer(writer))


async def udp_stream_to_target(reader: asyncio.StreamReader, udp_sock: socket.socket, usage: UsageTracker) -> None:
    loop = asyncio.get_running_loop()
    while True:
        header = await reader.readexactly(FRAME_HEADER_BYTES)
        length = struct.unpack(">I", header)[0]
        payload = await reader.readexactly(length)
        usage.add_in(len(payload))
        await loop.sock_sendall(udp_sock, payload)


async def udp_target_to_stream(udp_sock: socket.socket, writer: asyncio.StreamWriter, usage: UsageTracker) -> None:
    loop = asyncio.get_running_loop()
    while True:
        payload = await loop.sock_recv(udp_sock, 65535)
        if not payload:
            continue
        usage.add_out(len(payload))
        writer.write(struct.pack(">I", len(payload)))
        writer.write(payload)
        await writer.drain()


async def handle_udp(reader: asyncio.StreamReader, writer: asyncio.StreamWriter, payload: dict[str, Any], usage: UsageTracker) -> None:
    target_host = str(payload.get("target_host", "") or "").strip()
    target_port = int(payload.get("target_port", 0) or 0)
    if target_host == "" or target_port <= 0:
        raise RuntimeError("missing udp target")
    infos = await asyncio.get_running_loop().getaddrinfo(target_host, target_port, type=socket.SOCK_DGRAM)
    family, _socktype, _proto, _canonname, sockaddr = infos[0]
    udp_sock = socket.socket(family, socket.SOCK_DGRAM)
    udp_sock.setblocking(False)
    await asyncio.get_running_loop().sock_connect(udp_sock, sockaddr)
    try:
        await asyncio.gather(
            udp_stream_to_target(reader, udp_sock, usage),
            udp_target_to_stream(udp_sock, writer, usage),
        )
    finally:
        udp_sock.close()
        with Suppress():
            writer.close()
            await writer.wait_closed()


async def connection_handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter, config: dict[str, Any], usage: UsageTracker) -> None:
    try:
        handshake = await read_handshake(reader)
        protocol = str(handshake.get("protocol", "") or "").strip().lower()
        token = str(handshake.get("session_token", "") or "").strip()
        secret = str(config.get("token_secret", "") or "").strip()
        if secret == "":
            raise RuntimeError("token secret missing")
        payload = verify_session_token(token, secret, protocol)
        payload["tcp_congestion_control"] = str(config.get("tcp_congestion_control", "bbr") or "bbr").strip()
        payload["socket_buffer_bytes"] = int(config.get("socket_buffer_bytes", 0) or 0)
        if protocol == "tcp":
            await handle_tcp(reader, writer, payload, usage)
        elif protocol == "udp":
            await handle_udp(reader, writer, payload, usage)
        else:
            raise RuntimeError(f"unsupported protocol: {protocol}")
    except Exception as exc:  # noqa: BLE001
        logging.warning("sidecar session failed: %s", exc)
        with Suppress():
            writer.close()
            await writer.wait_closed()


def create_server_socket(host: str, port: int, allow_tcp_fallback: bool, tcp_congestion_control: str | None = None, socket_buffer_bytes: int = 0) -> socket.socket:
    family = socket.AF_INET6 if ":" in host else socket.AF_INET
    protocols = [MPTCP_PROTOCOL]
    if allow_tcp_fallback:
        protocols.append(socket.IPPROTO_TCP)
    last_error: Exception | None = None
    for protocol in protocols:
        try:
            sock = socket.socket(family, socket.SOCK_STREAM, protocol)
            tune_socket(sock, tcp_congestion_control, socket_buffer_bytes)
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            sock.bind((host, port))
            sock.listen(socket.SOMAXCONN)
            sock.setblocking(False)
            return sock
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            continue
    raise RuntimeError(f"unable to bind MPTCP listener on {host}:{port}: {last_error}")


async def main_async(config: dict[str, Any]) -> int:
    logging.basicConfig(
        level=getattr(logging, str(config.get("log_level", "INFO")).upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    apply_underlays(config)
    configure_mptcp_policy(config)
    usage = UsageTracker(
        pathlib.Path(str(config["stats_path"])) if str(config.get("stats_path", "")).strip() != "" else None
    )
    host = str(config.get("listen_host", "0.0.0.0") or "0.0.0.0")
    port = int(config.get("listen_port", 0) or 0)
    tcp_congestion_control = str(config.get("tcp_congestion_control", "bbr") or "bbr").strip()
    if port <= 0:
        raise RuntimeError("listen port missing")

    socket_buffer_bytes = int(config.get("socket_buffer_bytes", 0) or 0)
    sock = create_server_socket(host, port, truthy(config.get("allow_tcp_fallback", False)), tcp_congestion_control, socket_buffer_bytes)
    server = await asyncio.start_server(lambda r, w: connection_handler(r, w, config, usage), sock=sock)
    async with server:
        await server.serve_forever()
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Leikwan MPTCP sidecar runtime")
    parser.add_argument("--config", required=True)
    parser.add_argument("--setup-only", action="store_true")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    config = load_json(pathlib.Path(args.config))
    if args.setup_only:
        apply_underlays(config)
        configure_mptcp_policy(config)
        return 0
    return asyncio.run(main_async(config))


if __name__ == "__main__":
    raise SystemExit(main())
