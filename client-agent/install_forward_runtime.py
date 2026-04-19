#!/usr/bin/env python3
from __future__ import annotations

import json
import pathlib
from urllib.parse import quote


def normalize_mode(mode: str) -> str:
    mode = str(mode or "").strip().lower()
    if mode in ("race", "failover", "multi-path", "lb"):
        return "race"
    if mode in ("bond", "bonding", "aggregate", "aggregated"):
        return "bond"
    if mode in ("mptcp", "mptcp-wireguard", "mptcp-ipsec", "multipath-tcp"):
        return "mptcp"
    return "single"


def normalize_speed_limit(value: object) -> int | None:
    try:
        numeric = int(float(str(value)))
    except (TypeError, ValueError):
        return None
    return numeric if numeric > 0 else None


def rate_limit_base_port(forward_id: int) -> int:
    return 45000 + ((forward_id * 53) % 10000)


def bonding_config(forward: dict) -> dict:
    config = forward.get("bonding", {})
    return config if isinstance(config, dict) else {}


def mptcp_config(forward: dict) -> dict:
    config = forward.get("mptcp", {})
    return config if isinstance(config, dict) else {}


def truthy(value: object) -> bool:
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def int_or_default(value: object, default: int) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def render_tcp_leg(target: str, priority: int) -> str:
    return f"tcp://{target}?priority={priority}"


def bond_quic_leg_enabled(bond: dict) -> bool:
    return truthy(bond.get("quic_enabled", True)) or truthy(bond.get("quic_via_slave", False))


def render_quic_leg_for_endpoint(endpoint: dict, bond: dict) -> str | None:
    if not bond_quic_leg_enabled(bond):
        return None
    if not truthy(endpoint.get("supports_quic_leg", True)):
        return None

    host = str(endpoint.get("host") or "").strip()
    port = int_or_default(endpoint.get("port"), 0)
    if host == "" or port <= 0:
        return None

    server_name = str(endpoint.get("host") or bond.get("quic_server_name") or host).strip()
    insecure = truthy(bond.get("quic_insecure", True))
    priority = int_or_default(bond.get("quic_priority"), 0)
    params = [f"priority={priority}"]
    if server_name != "":
        params.append(f"server_name={quote(server_name, safe='')}")
    if insecure:
        params.append("insecure=1")
    return f"quic://{host}:{port}?" + "&".join(params)


def adaptive_join_timeout_ms(bond: dict, endpoints: list[dict]) -> int:
    configured = max(40, int_or_default(bond.get("join_timeout_ms"), 400))
    adaptive_enabled = truthy(bond.get("adaptive_join_timeout", True))
    floor = max(20, int_or_default(bond.get("join_timeout_floor_ms"), 35))
    per_leg = max(0, int_or_default(bond.get("join_timeout_per_leg_ms"), 20))
    ceiling = max(floor, int_or_default(bond.get("join_timeout_ceiling_ms"), min(configured, 220)))
    if not adaptive_enabled:
        return max(floor, min(ceiling, configured))

    wss_legs = max(1, len(endpoints))
    quic_legs = sum(1 for endpoint in endpoints if render_quic_leg_for_endpoint(endpoint, bond) is not None)
    if quic_legs == 0:
        wss_only_ceiling = max(ceiling, configured)
        return max(floor, min(wss_only_ceiling, configured))
    total_legs = max(1, wss_legs + quic_legs)
    adaptive = floor + max(0, total_legs - 1) * per_leg
    return max(floor, min(ceiling, adaptive))


def active_leg_limit(bond: dict, default: int = 0) -> int:
    value = int_or_default(bond.get("active_leg_limit", bond.get("quic_active_leg_limit", default)), default)
    return max(0, value)


def standby_wss_enabled(bond: dict) -> bool:
    return truthy(bond.get("wss_standby_enabled", True))


def select_active_quic_legs(endpoints: list[dict], bond: dict) -> list[str]:
    limit = active_leg_limit(bond)
    selected: list[str] = []
    for endpoint in endpoints:
        leg = render_quic_leg_for_endpoint(endpoint, bond)
        if leg is None:
            continue
        selected.append(leg)
        if limit > 0 and len(selected) >= limit:
            break
    return selected


def append_wait_for_local_listener(lines: list[str], ports: list[int]) -> None:
    if not ports:
        return
    lines.extend([
        "wait_for_local_listener() {",
        "  local port=\"$1\"",
        "  local deadline=$((SECONDS + 15))",
        "  while (( SECONDS < deadline )); do",
        "    if ss -ltn \"sport = :$port\" | grep -q \":$port\"; then",
        "      return 0",
        "    fi",
        "    sleep 0.2",
        "  done",
        "  return 1",
        "}",
    ])
    for port in ports:
        lines.append(f"wait_for_local_listener {port}")


def udp_uot_sidepath_settings(forward: dict, agent_config: dict) -> dict | None:
    raw = agent_config.get("udp_uot_sidepath", {})
    if not isinstance(raw, dict):
        return None
    enabled_ids = {
        int(value)
        for value in raw.get("enabled_forward_ids", [])
        if str(value).strip().isdigit()
    }
    forward_id = int(forward.get("forward_id", 0) or 0)
    if forward_id not in enabled_ids:
        return None
    return {
        "shadow_route_suffix": str(raw.get("shadow_route_suffix", ".uot") or ".uot"),
        "sidecar_port": int(raw.get("sidecar_port", 8999) or 8999),
        "reorder_window_packets": int(raw.get("reorder_window_packets", 4096) or 4096),
        "reorder_window_bytes": int(raw.get("reorder_window_bytes", 16777216) or 16777216),
        "reorder_timeout_ms": int(raw.get("reorder_timeout_ms", 25) or 25),
    }


def render_udp_uot_aux_unit(
    forward: dict,
    bond: dict,
    endpoints: list[dict],
    agent_config: dict,
    settings: dict,
) -> dict:
    forward_id = int(forward["forward_id"])
    route_key = str(bond.get("route_key", forward.get("path_prefix", ""))).strip()
    shadow_route_key = route_key + str(settings["shadow_route_suffix"])
    config_path = f"/etc/leikwan/uot-bond-forward-{forward_id}.json"
    service_name = f"leikwan-client-{forward_id}-udp-uot"
    service_path = f"/etc/systemd/system/{service_name}.service"
    runtime_bin = str(agent_config.get("uot_bond_bin", "/usr/local/bin/leikwan-uot-bond.py") or "/usr/local/bin/leikwan-uot-bond.py")
    config_payload = {
        "forward_id": forward_id,
        "listen_udp": f"0.0.0.0:{int(forward['listen_port'])}",
        "route_key": shadow_route_key,
        "session_token": str(bond.get("udp_session_token", "") or ""),
        "reorder_window_packets": int(settings["reorder_window_packets"]),
        "reorder_window_bytes": int(settings["reorder_window_bytes"]),
        "reorder_timeout_ms": int(settings["reorder_timeout_ms"]),
        "legs": [
            {
                "endpoint_id": int(endpoint.get("endpoint_id", 0) or 0),
                "host": str(endpoint["host"]),
                "port": int(endpoint["port"]),
                "auth_token": str(endpoint.get("ingress_auth_token", "") or bond.get("ingress_auth_token", "")),
            }
            for endpoint in endpoints
        ],
    }
    service_unit = f"""[Unit]
Description=Leikwan Client {forward_id} UDP UoT
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 {runtime_bin} --config {config_path}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
    return {
        "service_name": service_name,
        "service_path": service_path,
        "service_unit": service_unit,
        "start_script_path": "",
        "start_script": "",
        "files": [
            {
                "path": config_path,
                "content": json.dumps(config_payload, ensure_ascii=False, indent=2) + "\n",
                "mode": 0o600,
            }
        ],
    }


def render_forward_runtime(forward: dict, agent_config: dict) -> dict:
    forward_id = int(forward["forward_id"])
    listen_port = int(forward["listen_port"])
    target = str(forward["target"])
    sni = str(forward["sni"])
    path_prefix = str(forward["path_prefix"])
    endpoints = [endpoint for endpoint in forward.get("endpoints", []) if isinstance(endpoint, dict)]
    transport = str(forward.get("transport", "tcp+udp"))
    mode = normalize_mode(str(forward.get("mode", "single")))
    speed_limit_mbps = normalize_speed_limit(forward.get("speed_limit_mbps"))
    service_name = f"leikwan-client-{forward_id}"
    service_path = f"/etc/systemd/system/{service_name}.service"
    bond = bonding_config(forward)
    mptcp = mptcp_config(forward)
    bond_remote_target = str(bond.get("remote_target", "")).strip()
    bond_session_token = str(bond.get("session_token", "")).strip()
    bond_udp_session_token = str(bond.get("udp_session_token", "")).strip()
    bond_bin = str(agent_config.get("bond_bin", "/usr/local/bin/leikwan-bond")).strip() or "/usr/local/bin/leikwan-bond"
    bond_ingress_bin = str(agent_config.get("bond_ingress_bin", "/usr/local/bin/leikwan-ingress")).strip() or "/usr/local/bin/leikwan-ingress"

    usable_endpoints = []
    for endpoint in endpoints:
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
        usable_endpoints.append(normalized)
    endpoints = usable_endpoints

    if len(endpoints) > 1 and mode == "race":
        base = 20000 + ((forward_id * 37) % 15000)
        rate_base = rate_limit_base_port(forward_id)
        rate_tcp_port = rate_base
        rate_udp_port = rate_base + 1
        lines = ["#!/usr/bin/env bash", "set -euo pipefail", "trap 'kill $(jobs -p) 2>/dev/null || true' EXIT"]
        tcp_targets = []
        udp_targets = []
        for index, endpoint in enumerate(endpoints):
            host = endpoint["host"]
            port = endpoint["port"]
            tcp_back = base + index
            udp_back = base + 200 + index
            lines.append(f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{tcp_back}:{target} --tls-sni-override {sni} --http-headers \"Host: {sni}\" --http-upgrade-path-prefix \"{path_prefix}\" wss://{host}:{port} &")
            tcp_targets.append(f"127.0.0.1:{tcp_back}")
            if transport in ("udp", "tcp+udp"):
                lines.append(f"/usr/local/bin/wstunnel client -L udp://127.0.0.1:{udp_back}:{target}?timeout_sec=0 --tls-sni-override {sni} --http-headers \"Host: {sni}\" --http-upgrade-path-prefix \"{path_prefix}\" wss://{host}:{port} &")
                udp_targets.append(f"127.0.0.1:{udp_back}")
        if transport in ("tcp", "tcp+udp"):
            tcp_race_listen_port = rate_tcp_port if speed_limit_mbps else listen_port
            tcp_race_listen_host = "127.0.0.1" if speed_limit_mbps else "0.0.0.0"
            lines.append(f"/usr/bin/python3 /usr/local/bin/leikwan-tcp-race.py --listen {tcp_race_listen_host}:{tcp_race_listen_port} --targets {','.join(tcp_targets)} &")
            if speed_limit_mbps:
                lines.append(f"/usr/bin/python3 /usr/local/bin/leikwan-rate-limit.py tcp --listen 0.0.0.0:{listen_port} --target 127.0.0.1:{tcp_race_listen_port} --mbps {speed_limit_mbps} &")
        if transport in ("udp", "tcp+udp") and udp_targets:
            udp_fanout_listen_port = rate_udp_port if speed_limit_mbps else listen_port
            udp_fanout_listen_host = "127.0.0.1" if speed_limit_mbps else "0.0.0.0"
            lines.append(f"/usr/bin/python3 /usr/local/bin/leikwan-udp-fanout.py --listen {udp_fanout_listen_host}:{udp_fanout_listen_port} --targets {','.join(udp_targets)} --repeat 1 &")
            if speed_limit_mbps:
                lines.append(f"/usr/bin/python3 /usr/local/bin/leikwan-rate-limit.py udp --listen 0.0.0.0:{listen_port} --target 127.0.0.1:{udp_fanout_listen_port} --mbps {speed_limit_mbps} &")
        lines.append("wait -n")
        start_script_path = f"/usr/local/bin/leikwan-client-start-{forward_id}.sh"
        service_unit = f"""[Unit]
Description=Leikwan Client {forward_id}
After=network.target

[Service]
Type=simple
ExecStart=/bin/bash {start_script_path}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
        return {
            "service_name": service_name,
            "service_path": service_path,
            "service_unit": service_unit,
            "start_script_path": start_script_path,
            "start_script": "\n".join(lines) + "\n",
        }

    if mode == "bond":
        if bond_remote_target == "":
            raise RuntimeError("bond mode missing bonding.remote_target")
        if endpoints == []:
            raise RuntimeError("bond mode requires at least one endpoint")
        sidepath = udp_uot_sidepath_settings(forward, agent_config)
        primary_transport = transport
        extra_units: list[dict] = []
        if sidepath is not None and transport == "tcp+udp":
            primary_transport = "tcp"
            extra_units.append(render_udp_uot_aux_unit(forward, bond, endpoints, agent_config, sidepath))

        base = 20000 + ((forward_id * 37) % 15000)
        rate_base = rate_limit_base_port(forward_id)
        lines = ["#!/usr/bin/env bash", "set -euo pipefail", "trap 'kill $(jobs -p) 2>/dev/null || true' EXIT"]
        quic_via_slave = truthy(bond.get("quic_via_slave", True))
        use_local_rate_limit = speed_limit_mbps if not truthy(bond.get("disable_local_rate_limit", True)) else None
        ws_priority = int_or_default(bond.get("ws_priority"), 10)
        join_timeout_ms = adaptive_join_timeout_ms(bond, endpoints)
        selected_quic_legs = select_active_quic_legs(endpoints, bond)
        use_wss_as_active_legs = selected_quic_legs == []
        interactive_chunk_bytes = int_or_default(bond.get("interactive_chunk_bytes"), 16 * 1024)
        latency_guard_bytes = int_or_default(bond.get("latency_guard_bytes"), 256 * 1024)
        interactive_rtt_slack_ms = int_or_default(bond.get("interactive_rtt_slack_ms"), 30)
        warmup_trigger_bytes = int_or_default(bond.get("warmup_trigger_bytes"), 65536)
        warmup_slice_bytes = int_or_default(bond.get("warmup_slice_bytes"), 65536)
        warmup_leg_delay_ms = int_or_default(bond.get("warmup_leg_delay_ms"), 20)
        bulk_promote_bytes = int_or_default(bond.get("bulk_promote_bytes"), 262144)
        leg_cooldown_ms = int_or_default(bond.get("leg_cooldown_ms"), 3000)
        ingress_mode = str(bond.get("ingress_mode", "wss_path_mux")).strip() or "wss_path_mux"
        route_key = str(bond.get("route_key", path_prefix)).strip() or path_prefix
        tcp_ready_ports: list[int] = []
        udp_ready_ports: list[int] = []
        if primary_transport in ("tcp", "tcp+udp"):
            if bond_session_token == "":
                raise RuntimeError("bond mode missing bonding.session_token for tcp transport")
            tcp_leg_targets = list(selected_quic_legs) if quic_via_slave else []
            tcp_listen_port = rate_base if use_local_rate_limit else listen_port
            tcp_listen_host = "127.0.0.1" if use_local_rate_limit else "0.0.0.0"
            for index, endpoint in enumerate(endpoints):
                host = endpoint["host"]
                port = endpoint["port"]
                tcp_back = base + index
                ingress_auth_token = str(endpoint.get("ingress_auth_token", "") or bond.get("ingress_auth_token", "")).strip()
                if ingress_mode == "plain_tcp_mux":
                    if ingress_auth_token == "":
                        raise RuntimeError("bond mode plain_tcp_mux requires ingress_auth_token")
                    lines.append(
                        f"{bond_ingress_bin} client --listen 127.0.0.1:{tcp_back} "
                        f"--remote {host}:{port} --route-key {route_key} --auth-token {ingress_auth_token} &"
                    )
                else:
                    lines.append(
                        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{tcp_back}:{bond_remote_target} "
                        f"--tls-sni-override {sni} --http-headers \"Host: {sni}\" "
                        f"--http-upgrade-path-prefix \"{path_prefix}\" wss://{host}:{port} &"
                    )
                tcp_ready_ports.append(tcp_back)
                endpoint_priority = int_or_default(endpoint.get("dynamic_priority"), ws_priority)
                if use_wss_as_active_legs or not standby_wss_enabled(bond):
                    tcp_leg_targets.append(render_tcp_leg(f"127.0.0.1:{tcp_back}", endpoint_priority))
            append_wait_for_local_listener(lines, tcp_ready_ports)
            lines.append(
                f"{bond_bin} client --protocol tcp --listen {tcp_listen_host}:{tcp_listen_port} "
                f"--legs '{','.join(tcp_leg_targets)}' --session-token '{bond_session_token}' "
                f"--chunk-size {int(bond.get('chunk_size', 65536) or 65536)} "
                f"--max-window-bytes {int(bond.get('max_window_bytes', 8388608) or 8388608)} "
                f"--retransmit-ms {int(bond.get('retransmit_ms', 700) or 700)} "
                f"--connect-timeout-ms {int(bond.get('connect_timeout_ms', 3000) or 3000)} "
                f"--join-timeout-ms {join_timeout_ms} "
                f"--interactive-chunk-bytes {interactive_chunk_bytes} "
                f"--latency-guard-bytes {latency_guard_bytes} "
                f"--interactive-rtt-slack-ms {interactive_rtt_slack_ms} "
                f"--warmup-trigger-bytes {warmup_trigger_bytes} "
                f"--warmup-slice-bytes {warmup_slice_bytes} "
                f"--warmup-leg-delay-ms {warmup_leg_delay_ms} "
                f"--bulk-promote-bytes {bulk_promote_bytes} "
                f"--leg-cooldown-ms {leg_cooldown_ms} &"
            )
            if use_local_rate_limit:
                lines.append(
                    f"/usr/bin/python3 /usr/local/bin/leikwan-rate-limit.py tcp "
                    f"--listen 0.0.0.0:{listen_port} --target 127.0.0.1:{tcp_listen_port} --mbps {use_local_rate_limit} &"
                )
        if primary_transport in ("udp", "tcp+udp"):
            udp_token = bond_udp_session_token or bond_session_token
            if udp_token == "":
                raise RuntimeError("bond mode missing bonding.udp_session_token for udp transport")
            udp_leg_targets = list(selected_quic_legs) if quic_via_slave else []
            udp_listen_port = rate_base + 1 if use_local_rate_limit else listen_port
            udp_listen_host = "127.0.0.1" if use_local_rate_limit else "0.0.0.0"
            for index, endpoint in enumerate(endpoints):
                host = endpoint["host"]
                port = endpoint["port"]
                udp_back = base + 200 + index
                ingress_auth_token = str(endpoint.get("ingress_auth_token", "") or bond.get("ingress_auth_token", "")).strip()
                if ingress_mode == "plain_tcp_mux":
                    if ingress_auth_token == "":
                        raise RuntimeError("bond mode plain_tcp_mux requires ingress_auth_token")
                    lines.append(
                        f"{bond_ingress_bin} client --listen 127.0.0.1:{udp_back} "
                        f"--remote {host}:{port} --route-key {route_key} --auth-token {ingress_auth_token} &"
                    )
                else:
                    lines.append(
                        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{udp_back}:{bond_remote_target} "
                        f"--tls-sni-override {sni} --http-headers \"Host: {sni}\" "
                        f"--http-upgrade-path-prefix \"{path_prefix}\" wss://{host}:{port} &"
                    )
                udp_ready_ports.append(udp_back)
                endpoint_priority = int_or_default(endpoint.get("dynamic_priority"), ws_priority)
                if use_wss_as_active_legs or not standby_wss_enabled(bond):
                    udp_leg_targets.append(render_tcp_leg(f"127.0.0.1:{udp_back}", endpoint_priority))
            append_wait_for_local_listener(lines, udp_ready_ports)
            lines.append(
                f"{bond_bin} client --protocol udp --listen {udp_listen_host}:{udp_listen_port} "
                f"--legs '{','.join(udp_leg_targets)}' --session-token '{udp_token}' "
                f"--chunk-size {int(bond.get('chunk_size', 65536) or 65536)} "
                f"--max-window-bytes {int(bond.get('max_window_bytes', 8388608) or 8388608)} "
                f"--retransmit-ms {int(bond.get('retransmit_ms', 700) or 700)} "
                f"--connect-timeout-ms {int(bond.get('connect_timeout_ms', 3000) or 3000)} "
                f"--join-timeout-ms {join_timeout_ms} "
                f"--interactive-chunk-bytes {interactive_chunk_bytes} "
                f"--latency-guard-bytes {latency_guard_bytes} "
                f"--interactive-rtt-slack-ms {interactive_rtt_slack_ms} "
                f"--warmup-trigger-bytes {warmup_trigger_bytes} "
                f"--warmup-slice-bytes {warmup_slice_bytes} "
                f"--warmup-leg-delay-ms {warmup_leg_delay_ms} "
                f"--bulk-promote-bytes {bulk_promote_bytes} "
                f"--leg-cooldown-ms {leg_cooldown_ms} &"
            )
            if use_local_rate_limit:
                lines.append(
                    f"/usr/bin/python3 /usr/local/bin/leikwan-rate-limit.py udp "
                    f"--listen 0.0.0.0:{listen_port} --target 127.0.0.1:{udp_listen_port} --mbps {use_local_rate_limit} &"
                )
        lines.append("wait -n")
        start_script_path = f"/usr/local/bin/leikwan-client-start-{forward_id}.sh"
        service_unit = f"""[Unit]
Description=Leikwan Client {forward_id}
After=network.target

[Service]
Type=simple
ExecStart=/bin/bash {start_script_path}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
        return {
            "service_name": service_name,
            "service_path": service_path,
            "service_unit": service_unit,
            "start_script_path": start_script_path,
            "start_script": "\n".join(lines) + "\n",
            "extra_units": extra_units,
        }

    if mode == "mptcp":
        remote_host = str(
            mptcp.get("sidecar_host")
            or mptcp.get("remote_host")
            or forward.get("bond_sidecar_host", "")
            or forward.get("bond_remote_host", "")
        ).strip()
        remote_port = int_or_default(
            mptcp.get("sidecar_port")
            or mptcp.get("remote_port")
            or forward.get("bond_sidecar_port")
            or forward.get("bond_remote_port"),
            0,
        )
        if remote_host == "" or remote_port <= 0:
            raise RuntimeError("mptcp mode missing sidecar host/port")

        tcp_session_token = str(mptcp.get("tcp_session_token") or mptcp.get("session_token") or "").strip()
        udp_session_token = str(mptcp.get("udp_session_token") or tcp_session_token).strip()
        if transport in ("tcp", "tcp+udp") and tcp_session_token == "":
            raise RuntimeError("mptcp mode missing mptcp.tcp_session_token")
        if transport in ("udp", "tcp+udp") and udp_session_token == "":
            raise RuntimeError("mptcp mode missing mptcp.udp_session_token")

        helper_path = str(agent_config.get("mptcp_client_bin", "/usr/local/bin/leikwan-mptcp-client.py")).strip() or "/usr/local/bin/leikwan-mptcp-client.py"
        config_path = f"/etc/leikwan/mptcp-forward-{forward_id}.json"
        config_payload = {
            "forward_id": forward_id,
            "listen_port": listen_port,
            "transport": transport,
            "remote_host": remote_host,
            "remote_port": remote_port,
            "tcp_session_token": tcp_session_token,
            "udp_session_token": udp_session_token,
            "udp_mode": str(mptcp.get("udp_mode", "stream_compat") or "stream_compat"),
            "path_manager": str(mptcp.get("path_manager", "fullmesh") or "fullmesh"),
            "scheduler": str(mptcp.get("scheduler", "default") or "default"),
            "subflow_limit": int_or_default(mptcp.get("subflow_limit"), max(1, len(endpoints))) if endpoints else int_or_default(mptcp.get("subflow_limit"), 2),
            "connect_timeout_ms": int_or_default(mptcp.get("connect_timeout_ms"), 3000),
            "relay_idle_timeout_seconds": int_or_default(mptcp.get("relay_idle_timeout_seconds"), 120),
            "tcp_congestion_control": str(mptcp.get("tcp_congestion_control", "bbr") or "bbr"),
            "socket_buffer_bytes": int_or_default(mptcp.get("socket_buffer_bytes"), 8388608),
            "initcwnd": int_or_default(mptcp.get("initcwnd"), 80),
            "initrwnd": int_or_default(mptcp.get("initrwnd"), 80),
            "add_addr_timeout": int_or_default(mptcp.get("add_addr_timeout"), 5),
            "tcp_wmem_max": int_or_default(mptcp.get("tcp_wmem_max"), 16777216),
            "tcp_rmem_max": int_or_default(mptcp.get("tcp_rmem_max"), 16777216),
            "speed_limit_mbps": speed_limit_mbps,
            "endpoints": endpoints,
            "manage_mptcp_endpoints": truthy(mptcp.get("manage_mptcp_endpoints", True)),
            "mptcp_endpoint_device": str(mptcp.get("mptcp_endpoint_device", "") or ""),
            "mptcp_endpoint_id_base": int_or_default(mptcp.get("mptcp_endpoint_id_base"), 0),
            "mptcp_endpoints": mptcp.get("mptcp_endpoints", []),
            "underlay": dict(mptcp.get("underlay", {})) if isinstance(mptcp.get("underlay", {}), dict) else {},
            "underlays": [],
        }
        if isinstance(config_payload["underlay"], dict):
            config_payload["underlay"]["local_private_key_path"] = str(pathlib.Path(str(agent_config.get("state_dir", "/var/lib/leikwan-client-agent"))) / "wireguard" / "private.key")
            config_payload["underlay"].setdefault("listen_port", int_or_default(agent_config.get("wireguard_listen_port"), 0))

        # Inject private key and listen port into each underlay for multi-interface mode
        raw_underlays = mptcp.get("underlays", [])
        if isinstance(raw_underlays, list):
            base_listen_port = int_or_default(agent_config.get("wireguard_listen_port"), 0)
            privkey_path = str(pathlib.Path(str(agent_config.get("state_dir", "/var/lib/leikwan-client-agent"))) / "wireguard" / "private.key")
            injected_underlays = []
            for idx, raw_ul in enumerate(raw_underlays):
                if not isinstance(raw_ul, dict):
                    continue
                ul = dict(raw_ul)
                ul["local_private_key_path"] = privkey_path
                ul.setdefault("listen_port", base_listen_port + idx if base_listen_port > 0 else 0)
                injected_underlays.append(ul)
            config_payload["underlays"] = injected_underlays

        # Check for Rust MPTCP relay binary
        rust_relay_path = "/var/lib/leikwan-client-agent/leikwan-mptcp-relay"
        lines = ["#!/usr/bin/env bash", "set -euo pipefail"]
        lines.append("unset HTTP_PROXY HTTPS_PROXY ALL_PROXY http_proxy https_proxy all_proxy")
        lines.append("export NO_PROXY='*'")
        lines.append("export no_proxy='*'")
        lines.append(f"/usr/bin/python3 {helper_path} --config {config_path} --setup-underlays")
        lines.append(f'RUST_RELAY="{rust_relay_path}"')
        lines.append(f'if [ -x "$RUST_RELAY" ]; then')
        lines.append(f'    exec "$RUST_RELAY" client --config {config_path}')
        lines.append(f'else')
        lines.append(f'    exec /usr/bin/python3 {helper_path} --config {config_path}')
        lines.append(f'fi')
        start_script_path = f"/usr/local/bin/leikwan-client-start-{forward_id}.sh"
        service_unit = f"""[Unit]
Description=Leikwan Client {forward_id} MPTCP
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/bin/bash {start_script_path}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
        return {
            "service_name": service_name,
            "service_path": service_path,
            "service_unit": service_unit,
            "start_script_path": start_script_path,
            "start_script": "\n".join(lines) + "\n",
            "files": [
                {
                    "path": config_path,
                    "content": json.dumps(config_payload, ensure_ascii=False, indent=2) + "\n",
                    "mode": 0o600,
                }
            ],
        }

    if endpoints == []:
        raise RuntimeError(f"forward {forward_id} has no usable endpoints")

    endpoint = endpoints[0]
    rate_base = rate_limit_base_port(forward_id)
    internal_tcp_port = rate_base
    internal_udp_port = rate_base + 1
    if speed_limit_mbps:
        lines = ["#!/usr/bin/env bash", "set -euo pipefail", "trap 'kill $(jobs -p) 2>/dev/null || true' EXIT"]
        if transport in ("tcp", "tcp+udp"):
            lines.append(f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{internal_tcp_port}:{target} --tls-sni-override {sni} --http-headers \"Host: {sni}\" --http-upgrade-path-prefix \"{path_prefix}\" wss://{endpoint['host']}:{endpoint['port']} &")
            lines.append(f"/usr/bin/python3 /usr/local/bin/leikwan-rate-limit.py tcp --listen 0.0.0.0:{listen_port} --target 127.0.0.1:{internal_tcp_port} --mbps {speed_limit_mbps} &")
        if transport in ("udp", "tcp+udp"):
            lines.append(f"/usr/local/bin/wstunnel client -L udp://127.0.0.1:{internal_udp_port}:{target}?timeout_sec=0 --tls-sni-override {sni} --http-headers \"Host: {sni}\" --http-upgrade-path-prefix \"{path_prefix}\" wss://{endpoint['host']}:{endpoint['port']} &")
            lines.append(f"/usr/bin/python3 /usr/local/bin/leikwan-rate-limit.py udp --listen 0.0.0.0:{listen_port} --target 127.0.0.1:{internal_udp_port} --mbps {speed_limit_mbps} &")
        lines.append("wait -n")
        start_script_path = f"/usr/local/bin/leikwan-client-start-{forward_id}.sh"
        service_unit = f"""[Unit]
Description=Leikwan Client {forward_id}
After=network.target

[Service]
Type=simple
ExecStart=/bin/bash {start_script_path}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
        return {
            "service_name": service_name,
            "service_path": service_path,
            "service_unit": service_unit,
            "start_script_path": start_script_path,
            "start_script": "\n".join(lines) + "\n",
        }

    listen_flags: list[str] = []
    if transport in ("tcp", "tcp+udp"):
        listen_flags.append(f"-L tcp://[::]:{listen_port}:{target}")
    if transport in ("udp", "tcp+udp"):
        listen_flags.append(f"-L udp://[::]:{listen_port}:{target}?timeout_sec=0")
    exec_start = f"/usr/local/bin/wstunnel client {' '.join(listen_flags)} --tls-sni-override {sni} --http-headers \"Host: {sni}\" --http-upgrade-path-prefix \"{path_prefix}\" wss://{endpoint['host']}:{endpoint['port']}"
    service_unit = f"""[Unit]
Description=Leikwan Client {forward_id}
After=network.target

[Service]
Type=simple
ExecStart={exec_start}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
    return {
        "service_name": service_name,
        "service_path": service_path,
        "service_unit": service_unit,
        "start_script_path": None,
        "start_script": None,
    }
