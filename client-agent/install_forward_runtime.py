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


def render_mptcp_runtime_config(forward: dict) -> dict:
    mptcp = dict(mptcp_config(forward))
    raw_endpoints = mptcp.get("mptcp_endpoints", [])
    endpoints = [dict(item) for item in raw_endpoints if isinstance(item, dict)] if isinstance(raw_endpoints, list) else []
    max_legs = int_or_default(forward.get("max_legs_used", forward.get("helper_group_cap", 0)), 0)
    if max_legs > 0:
        endpoints = endpoints[:max_legs]
    configured = int_or_default(mptcp.get("subflow_limit"), max(1, len(endpoints) or 1))
    if max_legs > 0:
        configured = min(configured, max_legs)
    return {
        "subflow_limit": max(1, configured),
        "mptcp_endpoints": endpoints,
    }


def truthy(value: object) -> bool:
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def int_or_default(value: object, default: int) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _leg_query_params(priority: int, endpoint: dict | None = None) -> str:
    params = [f"priority={priority}"]
    if isinstance(endpoint, dict):
        endpoint_id = int_or_default(endpoint.get("endpoint_id"), 0)
        node_id = int_or_default(endpoint.get("node_id"), 0)
        host = str(endpoint.get("display_host", "") or endpoint.get("host", "") or "").strip()
        leg_role = str(endpoint.get("leg_role", "") or "").strip()
        if endpoint_id > 0:
            params.append(f"endpoint_id={endpoint_id}")
        if node_id > 0:
            params.append(f"node_id={node_id}")
        if host != "":
            params.append(f"display_host={quote(host, safe='')}")
        if leg_role in {"primary", "helper"}:
            params.append(f"role={quote(leg_role, safe='')}")
    return "&".join(params)


def render_tcp_leg(target: str, priority: int, endpoint: dict | None = None) -> str:
    return f"tcp://{target}?{_leg_query_params(priority, endpoint)}"


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

    server_name = str(bond.get("quic_server_name") or endpoint.get("host") or host).strip()
    insecure = truthy(bond.get("quic_insecure", True))
    priority = int_or_default(bond.get("quic_priority"), 0)
    params = _leg_query_params(priority, endpoint).split("&")
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
        "    if ss -lun \"sport = :$port\" | grep -q \":$port\"; then",
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


def udp_leg_weight(endpoint: dict, default_priority: int = 10) -> int:
    role = str(endpoint.get("health_role", "healthy") or "healthy").strip().lower()
    if role == "quarantined":
        return 1
    priority = int_or_default(endpoint.get("dynamic_priority"), default_priority)
    delta = max(0, priority - default_priority)
    if delta >= 30:
        return 1
    if delta >= 15:
        return 2
    return 8


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
    runtime_bin = str(agent_config.get("uot_bond_bin", "/usr/local/bin/leikwan-uot-bond") or "/usr/local/bin/leikwan-uot-bond")
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
                "dynamic_priority": int_or_default(endpoint.get("dynamic_priority"), int_or_default(bond.get("ws_priority"), 10)),
                "health_role": str(endpoint.get("health_role", "healthy") or "healthy"),
                "weight": udp_leg_weight(endpoint, int_or_default(bond.get("ws_priority"), 10)),
            }
            for endpoint in endpoints
        ],
    }
    if runtime_bin.endswith(".py"):
        exec_start = f"/usr/bin/python3 {runtime_bin} --config {config_path}"
    else:
        exec_start = f"{runtime_bin} --config {config_path}"
    service_unit = f"""[Unit]
Description=Leikwan Client {forward_id} UDP UoT
After=network.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart={exec_start}
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




def candidate_kind(candidate: dict) -> str:
    return str(candidate.get("candidate_kind", "") or "").strip().lower()


def selected_self_leg_candidates(forward: dict) -> list[dict]:
    raw_pool = forward.get("candidate_pool", [])
    if not isinstance(raw_pool, list):
        return []
    selected = [
        dict(item)
        for item in raw_pool
        if isinstance(item, dict)
        and candidate_kind(item) == "self_leg"
        and truthy(item.get("selected_primary", False))
    ]
    if selected:
        return selected
    return [dict(item) for item in raw_pool if isinstance(item, dict) and candidate_kind(item) == "self_leg"][:1]


def self_leg_endpoint(candidate: dict, forward: dict) -> dict | None:
    host = str(candidate.get("dial_connect_ip", "") or candidate.get("dial_host", "") or "").strip()
    port = int_or_default(candidate.get("dial_port"), 0)
    if host == "" or port <= 0:
        return None
    path_prefix = str(candidate.get("path_prefix", "") or forward.get("path_prefix", "") or "").strip()
    if path_prefix == "":
        return None
    sni_host = str(candidate.get("cf_sni_host", "") or candidate.get("sni_host", "") or forward.get("sni", "") or host).strip()
    return {
        "candidate_kind": "self_leg",
        "endpoint_id": int_or_default(candidate.get("endpoint_id"), 0),
        "host": host,
        "port": port,
        "display_host": "self_leg",
        "dial_connect_ip": str(candidate.get("dial_connect_ip", "") or "").strip(),
        "cdn_host": sni_host,
        "cf_sni_host": sni_host,
        "path_prefix": path_prefix,
        "leg_role": "primary",
        "supports_quic_leg": False,
        "dynamic_priority": max(1, int_or_default(candidate.get("dynamic_priority"), 10)),
        "health_role": str(candidate.get("health_role", "healthy") or "healthy"),
        "target_sidecar_port": int_or_default(
            candidate.get("target_sidecar_bond_port") or candidate.get("target_sidecar_port"),
            0,
        ),
        "target_sidecar_bond_port": int_or_default(candidate.get("target_sidecar_bond_port"), 0),
        "target_sidecar_mptcp_port": int_or_default(candidate.get("target_sidecar_mptcp_port"), 0),
    }



def selected_mptcp_self_leg_candidates(forward: dict) -> list[dict]:
    raw_pool = forward.get("candidate_pool", [])
    if not isinstance(raw_pool, list):
        return []
    selected = [
        dict(item)
        for item in raw_pool
        if isinstance(item, dict)
        and candidate_kind(item) == "self_leg"
        and truthy(item.get("selected_primary", False))
        and "mptcp" in [str(value).strip().lower() for value in item.get("runtime_capabilities", [])]
        and not (
            isinstance(item.get("self_leg_wg"), dict)
            and truthy(item.get("self_leg_wg", {}).get("enabled", True))
            and str(item.get("self_leg_wg", {}).get("outer_transport", "udp") or "udp").strip().lower() != "wstunnel_udp"
        )
    ]
    return selected[:1]


def selected_private_wg_self_leg_candidates(forward: dict) -> list[dict]:
    raw_pool = forward.get("candidate_pool", [])
    if not isinstance(raw_pool, list):
        return []
    selected = [
        dict(item)
        for item in raw_pool
        if isinstance(item, dict)
        and candidate_kind(item) == "self_leg"
        and truthy(item.get("selected_primary", False))
        and "mptcp" in [str(value).strip().lower() for value in item.get("runtime_capabilities", [])]
        and isinstance(item.get("self_leg_wg"), dict)
        and truthy(item.get("self_leg_wg", {}).get("enabled", True))
    ]
    return selected[:1]


def self_leg_wg_underlay(candidate: dict, forward: dict) -> dict | None:
    wg = candidate.get("self_leg_wg", {})
    if not isinstance(wg, dict) or not truthy(wg.get("enabled", True)):
        return None
    forward_id = int_or_default(forward.get("forward_id"), int_or_default(candidate.get("forward_id"), 0))
    interface_name = str(wg.get("interface_name", "") or (f"lw-self-{forward_id}" if forward_id > 0 else "")).strip()[:15]
    client_address = str(wg.get("client_address", "") or "").strip()
    sidecar_address = str(wg.get("sidecar_address", "") or "").strip()
    sidecar_public_key = str(wg.get("sidecar_public_key", "") or "").strip()
    outer_transport = str(wg.get("outer_transport", "udp") or "udp").strip().lower() or "udp"
    endpoint_host = str(wg.get("endpoint_host", "") or wg.get("sidecar_endpoint_host", "") or candidate.get("target_sidecar_host", "") or "").strip()
    endpoint_port = int_or_default(wg.get("endpoint_port") or wg.get("sidecar_endpoint_port"), 0)
    cf_wg_target_host = str(wg.get("cf_target_host", "") or "127.0.0.1").strip()
    cf_wg_target_port = endpoint_port
    if outer_transport == "wstunnel_udp" and forward_id > 0:
        cf_candidates = candidate.get("self_leg_cf_candidates", [])
        if isinstance(cf_candidates, list):
            preferred_cf_host = next((str(value).strip() for value in cf_candidates if str(value).strip()), "")
            if preferred_cf_host != "":
                wg["cf_dial_host"] = preferred_cf_host
        explicit_cf_connect_ip = str(candidate.get("dial_connect_ip", "") or "").strip()
        if explicit_cf_connect_ip != "":
            wg["cf_dial_host"] = explicit_cf_connect_ip
        cf_dial_port = int_or_default(
            candidate.get("dial_port")
            or wg.get("cf_dial_port")
            or candidate.get("cf_dial_port")
            or forward.get("bond_sidecar_cf_listen_port")
            or forward.get("cf_listen_port"),
            0,
        )
        if cf_dial_port > 0:
            wg["cf_dial_port"] = cf_dial_port
        cf_sni_host = str(candidate.get("cf_sni_host", "") or candidate.get("sni_host", "") or wg.get("cf_sni_host", "") or forward.get("sni", "") or "").strip()
        if cf_sni_host != "":
            wg["cf_sni_host"] = cf_sni_host
        base_path = str(candidate.get("cf_path_prefix", "") or wg.get("cf_path_prefix", "") or candidate.get("path_prefix", "") or forward.get("path_prefix", "") or "").strip().strip("/")
        if base_path != "":
            wg["cf_path_prefix"] = base_path if base_path.endswith(".cfwg") else base_path + ".cfwg"
        endpoint_host = "127.0.0.1"
        endpoint_port = 40000 + (forward_id % 2000)
    if interface_name == "" or client_address == "" or sidecar_address == "" or sidecar_public_key == "" or endpoint_host == "" or endpoint_port <= 0:
        return None
    leg = {
        "forward_id": forward_id,
        "endpoint_id": int_or_default(candidate.get("endpoint_id"), 0),
        "outer_transport": outer_transport,
        "local_address": client_address,
        "peer_address": sidecar_address,
        "mptcp_remote_address": sidecar_address,
        "peer_public_key": sidecar_public_key,
        "peer_public_host": endpoint_host,
        "peer_udp_port": endpoint_port,
        "peer_tcp_fallback_port": int_or_default(wg.get("fallback_tcp_port") or wg.get("peer_tcp_fallback_port"), 0),
        "cf_dial_host": str(wg.get("cf_dial_host", "") or "").strip(),
        "cf_dial_port": int_or_default(wg.get("cf_dial_port"), 0),
        "cf_sni_host": str(wg.get("cf_sni_host", "") or "").strip(),
        "cf_path_prefix": str(wg.get("cf_path_prefix", "") or "").strip(),
        "cf_target_host": cf_wg_target_host,
        "cf_target_port": cf_wg_target_port,
    }
    underlay = {
        "type": "wireguard",
        "mode": "client_self_leg",
        "interface_name": interface_name,
        "peer_keepalive_seconds": int_or_default(wg.get("persistent_keepalive") or wg.get("peer_keepalive_seconds"), 25),
        "legs": [leg],
    }
    if outer_transport == "wstunnel_udp":
        underlay["prefer_mptcp_endpoint"] = True
    return underlay


def mptcp_self_leg_endpoint(candidate: dict, forward: dict, priority: int) -> dict | None:
    if str(candidate.get("probe_transport", "") or "").strip().lower() == "cf_wg":
        return None
    endpoint = self_leg_endpoint(candidate, forward)
    if endpoint is None:
        return None
    endpoint["priority"] = priority
    endpoint["dynamic_priority"] = priority
    endpoint["mptcp_direct"] = True
    endpoint["target_sidecar_host"] = str(candidate.get("target_sidecar_host", "") or "").strip()
    endpoint["target_sidecar_port"] = int_or_default(
        candidate.get("target_sidecar_port") or candidate.get("target_sidecar_mptcp_port"),
        0,
    )
    return endpoint


def append_wstunnel_direct_leg(
    lines: list[str],
    endpoint: dict,
    local_port: int,
    remote_target: str,
    default_sni: str,
    default_path_prefix: str,
) -> None:
    host = str(endpoint.get("host", "") or "").strip()
    port = int_or_default(endpoint.get("port"), 0)
    if host == "" or port <= 0:
        return
    leg_path_prefix = endpoint_path_prefix(endpoint, default_path_prefix)
    leg_sni = endpoint_sni(endpoint, default_sni)
    lines.append(
        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{local_port}:{remote_target} "
        f"--tls-sni-override {leg_sni} --http-headers \"Host: {leg_sni}\" "
        f"--http-upgrade-path-prefix \"{leg_path_prefix}\" wss://{host}:{port} &"
    )


def endpoint_path_prefix(endpoint: dict, default_path_prefix: str) -> str:
    return str(endpoint.get("path_prefix", "") or default_path_prefix or "").strip()


def endpoint_sni(endpoint: dict, default_sni: str) -> str:
    return str(endpoint.get("cf_sni_host", "") or endpoint.get("sni_host", "") or endpoint.get("cdn_host", "") or default_sni or endpoint.get("host", "") or "").strip()
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
    if bond_remote_target == "" and str(forward.get("backend_mode", "") or "").strip().lower() == "cf_mode":
        fallback_port = int_or_default(forward.get("bond_sidecar_port"), 0)
        if fallback_port <= 0:
            fallback_port = int_or_default(forward.get("bond_remote_port"), 0)
        if fallback_port > 0:
            bond_remote_target = f"127.0.0.1:{fallback_port}"
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
        self_leg_endpoints = [
            endpoint
            for candidate in selected_self_leg_candidates(forward)
            for endpoint in [self_leg_endpoint(candidate, forward)]
            if endpoint is not None
        ]
        if self_leg_endpoints:
            endpoints = self_leg_endpoints + endpoints
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
        client_id = int_or_default(agent_config.get("client_id"), 0)
        snapshot_path = f"/run/leikwan/bond-leg-health-forward-{forward_id}.json"
        lines.extend(
            [
                "export LEIKWAN_BOND_OBSERVER_TYPE='client'",
                f"export LEIKWAN_BOND_OBSERVER_ID='{client_id}'",
                f"export LEIKWAN_BOND_FORWARD_ID='{forward_id}'",
                f"export LEIKWAN_BOND_SNAPSHOT_PATH='{snapshot_path}'",
            ]
        )
        quic_via_slave = truthy(bond.get("quic_via_slave", True))
        use_local_rate_limit = speed_limit_mbps if not truthy(bond.get("disable_local_rate_limit", True)) else None
        ws_priority = int_or_default(bond.get("ws_priority"), 10)
        join_timeout_ms = adaptive_join_timeout_ms(bond, endpoints)
        single_leg_fast_path = len(endpoints) == 1 and primary_transport in ("tcp", "tcp+udp")
        selected_quic_legs = select_active_quic_legs(endpoints, bond)
        use_wss_as_active_legs = selected_quic_legs == []
        interactive_chunk_bytes = int_or_default(bond.get("interactive_chunk_bytes"), 16 * 1024)
        latency_guard_bytes = int_or_default(bond.get("latency_guard_bytes"), 256 * 1024)
        interactive_rtt_slack_ms = int_or_default(bond.get("interactive_rtt_slack_ms"), 30)
        warmup_trigger_bytes = int_or_default(bond.get("warmup_trigger_bytes"), 65536)
        warmup_slice_bytes = int_or_default(bond.get("warmup_slice_bytes"), 65536)
        warmup_leg_delay_ms = int_or_default(bond.get("warmup_leg_delay_ms"), 20)
        policy_mode = str(bond.get("policy_mode", "") or "").strip()
        latency_first = policy_mode == "latency_first"
        default_bulk_promote_bytes = 1 << 30 if latency_first else 262144
        bulk_promote_bytes = int_or_default(bond.get("bulk_promote_bytes"), default_bulk_promote_bytes)
        leg_cooldown_ms = int_or_default(bond.get("leg_cooldown_ms"), 1800000)
        udp_primary_only = truthy(bond.get("udp_primary_only", False))
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
                leg_path_prefix = endpoint_path_prefix(endpoint, path_prefix)
                leg_sni = endpoint_sni(endpoint, sni)
                if candidate_kind(endpoint) == "self_leg":
                    direct_target_port = int_or_default(endpoint.get("target_sidecar_port"), 0)
                    direct_target = f"127.0.0.1:{direct_target_port}" if direct_target_port > 0 else bond_remote_target
                    lines.append(
                        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{tcp_back}:{direct_target} "
                        f"--tls-sni-override {leg_sni} --http-headers \"Host: {leg_sni}\" "
                        f"--http-upgrade-path-prefix \"{leg_path_prefix}\" wss://{host}:{port} &"
                    )
                elif ingress_mode == "plain_tcp_mux":
                    if ingress_auth_token == "":
                        raise RuntimeError("bond mode plain_tcp_mux requires ingress_auth_token")
                    lines.append(
                        f"{bond_ingress_bin} client --listen 127.0.0.1:{tcp_back} "
                        f"--remote {host}:{port} --route-key {route_key} --auth-token {ingress_auth_token} &"
                    )
                else:
                    lines.append(
                        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{tcp_back}:{bond_remote_target} "
                        f"--tls-sni-override {leg_sni} --http-headers \"Host: {leg_sni}\" "
                        f"--http-upgrade-path-prefix \"{leg_path_prefix}\" wss://{host}:{port} &"
                    )
                tcp_ready_ports.append(tcp_back)
                endpoint_priority = int_or_default(endpoint.get("dynamic_priority"), ws_priority)
                if use_wss_as_active_legs or not standby_wss_enabled(bond):
                    tcp_leg_targets.append(render_tcp_leg(f"127.0.0.1:{tcp_back}", endpoint_priority, endpoint))
            append_wait_for_local_listener(lines, tcp_ready_ports)
            tcp_latency_flags = "--latency-first " if latency_first else ""
            single_leg_fast_path_flag = "--single-leg-fast-path " if single_leg_fast_path else ""
            lines.append(
                f"{bond_bin} client --protocol tcp --listen {tcp_listen_host}:{tcp_listen_port} "
                f"{tcp_latency_flags}"
                f"{single_leg_fast_path_flag}"
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
                leg_path_prefix = endpoint_path_prefix(endpoint, path_prefix)
                leg_sni = endpoint_sni(endpoint, sni)
                if candidate_kind(endpoint) == "self_leg":
                    direct_target_port = int_or_default(endpoint.get("target_sidecar_port"), 0)
                    direct_target = f"127.0.0.1:{direct_target_port}" if direct_target_port > 0 else bond_remote_target
                    lines.append(
                        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{udp_back}:{direct_target} "
                        f"--tls-sni-override {leg_sni} --http-headers \"Host: {leg_sni}\" "
                        f"--http-upgrade-path-prefix \"{leg_path_prefix}\" wss://{host}:{port} &"
                    )
                elif ingress_mode == "plain_tcp_mux":
                    if ingress_auth_token == "":
                        raise RuntimeError("bond mode plain_tcp_mux requires ingress_auth_token")
                    lines.append(
                        f"{bond_ingress_bin} client --listen 127.0.0.1:{udp_back} "
                        f"--remote {host}:{port} --route-key {route_key} --auth-token {ingress_auth_token} &"
                    )
                else:
                    lines.append(
                        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{udp_back}:{bond_remote_target} "
                        f"--tls-sni-override {leg_sni} --http-headers \"Host: {leg_sni}\" "
                        f"--http-upgrade-path-prefix \"{leg_path_prefix}\" wss://{host}:{port} &"
                    )
                udp_ready_ports.append(udp_back)
                endpoint_priority = int_or_default(endpoint.get("dynamic_priority"), ws_priority)
                if use_wss_as_active_legs or not standby_wss_enabled(bond):
                    udp_leg_targets.append(render_tcp_leg(f"127.0.0.1:{udp_back}", endpoint_priority, endpoint))
            append_wait_for_local_listener(lines, udp_ready_ports)
            udp_latency_flags = "--latency-first " if latency_first else ""
            if latency_first and udp_primary_only:
                udp_latency_flags += "--udp-primary-only "
            lines.append(
                f"{bond_bin} client --protocol udp --listen {udp_listen_host}:{udp_listen_port} "
                f"{udp_latency_flags}"
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
        sidecar_relay_host = remote_host
        sidecar_relay_port = remote_port
        private_self_leg_underlays = [
            underlay
            for candidate in selected_private_wg_self_leg_candidates(forward)
            for underlay in [self_leg_wg_underlay(candidate, forward)]
            if underlay is not None
        ]
        if private_self_leg_underlays:
            first_leg = private_self_leg_underlays[0].get("legs", [{}])[0]
            if isinstance(first_leg, dict):
                private_remote_host = str(first_leg.get("mptcp_remote_address", "") or first_leg.get("peer_address", "") or "").strip()
                if private_remote_host != "":
                    remote_host = private_remote_host
            selected_private_candidate = selected_private_wg_self_leg_candidates(forward)[0]
            private_remote_port = int_or_default(
                selected_private_candidate.get("target_sidecar_mptcp_port")
                or selected_private_candidate.get("target_sidecar_port")
                or remote_port,
                remote_port,
            )
            if private_remote_port > 0:
                remote_port = private_remote_port
        direct_self_leg_endpoints = [
            endpoint
            for index, candidate in enumerate(selected_mptcp_self_leg_candidates(forward))
            for endpoint in [mptcp_self_leg_endpoint(candidate, forward, index + 1)]
            if endpoint is not None
        ]
        direct_leg_base = 39000 + ((forward_id * 97) % 2000)
        direct_legs = []
        for index, endpoint in enumerate(direct_self_leg_endpoints):
            local_port = direct_leg_base + index
            direct_legs.append({
                "candidate_kind": "self_leg",
                "endpoint_id": int_or_default(endpoint.get("endpoint_id"), 0),
                "host": str(endpoint["host"]),
                "port": int(endpoint["port"]),
                "local_host": "127.0.0.1",
                "local_port": local_port,
                "priority": int_or_default(endpoint.get("dynamic_priority"), index + 1),
                "display_host": "self_leg",
                "leg_role": "primary" if index == 0 else "helper",
            })
        if direct_legs:
            remote_host = "127.0.0.1"
            remote_port = int(direct_legs[0]["local_port"])

        runtime_mptcp = render_mptcp_runtime_config(forward)
        helper_mptcp_endpoints = [
            dict(endpoint)
            for endpoint in runtime_mptcp["mptcp_endpoints"]
            if isinstance(endpoint, dict)
        ]
        rendered_mptcp_endpoints = helper_mptcp_endpoints
        preferred_private_mptcp_endpoints = []
        for underlay in private_self_leg_underlays:
            if not isinstance(underlay, dict) or not truthy(underlay.get("prefer_mptcp_endpoint", False)):
                continue
            legs = underlay.get("legs", [])
            first_leg = legs[0] if isinstance(legs, list) and legs and isinstance(legs[0], dict) else {}
            local_address = str(first_leg.get("local_address", "") or "").split("/", 1)[0].strip()
            if local_address == "":
                continue
            preferred_private_mptcp_endpoints.append({
                "endpoint_id": int_or_default(first_leg.get("endpoint_id"), 0),
                "address": local_address,
                "dev": str(underlay.get("interface_name", "") or "").strip(),
                "subflow": True,
                "signal": False,
                "backup_only": False,
                "leg_role": "primary",
                "health_role": "healthy",
            })
        if preferred_private_mptcp_endpoints:
            primary_addresses = {str(endpoint.get("address", "") or "").split("/", 1)[0].strip() for endpoint in preferred_private_mptcp_endpoints}
            standby_helper_mptcp_endpoints = []
            for endpoint in helper_mptcp_endpoints:
                address = str(endpoint.get("address", "") or "").split("/", 1)[0].strip()
                if address != "" and address in primary_addresses:
                    continue
                standby_endpoint = dict(endpoint)
                standby_endpoint.setdefault("subflow", True)
                standby_endpoint["backup_only"] = True
                standby_endpoint["leg_role"] = "standby"
                standby_endpoint.setdefault("health_role", "healthy")
                standby_helper_mptcp_endpoints.append(standby_endpoint)
            rendered_mptcp_endpoints = preferred_private_mptcp_endpoints + standby_helper_mptcp_endpoints
        rendered_subflow_limit = int_or_default(runtime_mptcp.get("subflow_limit"), 1)
        if direct_legs and not endpoints:
            rendered_subflow_limit = 1
        if preferred_private_mptcp_endpoints:
            rendered_subflow_limit = max(1, len(rendered_mptcp_endpoints))

        config_payload = {
            "forward_id": forward_id,
            "listen_port": listen_port,
            "transport": transport,
            "remote_host": remote_host,
            "remote_port": remote_port,
            "relay_host": sidecar_relay_host,
            "relay_port": sidecar_relay_port,
            "tcp_session_token": tcp_session_token,
            "udp_session_token": udp_session_token,
            "udp_mode": str(mptcp.get("udp_mode", "stream_compat") or "stream_compat"),
            "path_manager": str(mptcp.get("path_manager", "fullmesh") or "fullmesh"),
            "scheduler": str(mptcp.get("scheduler", "default") or "default"),
            "subflow_limit": rendered_subflow_limit,
            "connect_timeout_ms": int_or_default(mptcp.get("connect_timeout_ms"), 3000),
            "relay_idle_timeout_seconds": int_or_default(mptcp.get("relay_idle_timeout_seconds"), 120),
            "tcp_congestion_control": str(mptcp.get("tcp_congestion_control", "bbr") or "bbr"),
            "socket_buffer_bytes": int_or_default(mptcp.get("socket_buffer_bytes"), 8388608),
            "policy_mode": str(mptcp.get("policy_mode", "latency_first") or "latency_first"),
            "initcwnd": int_or_default(mptcp.get("initcwnd"), 80),
            "initrwnd": int_or_default(mptcp.get("initrwnd"), 80),
            "add_addr_timeout": int_or_default(mptcp.get("add_addr_timeout"), 5),
            "tcp_wmem_max": int_or_default(mptcp.get("tcp_wmem_max"), 16777216),
            "tcp_rmem_max": int_or_default(mptcp.get("tcp_rmem_max"), 16777216),
            "speed_limit_mbps": speed_limit_mbps,
            "bond_mode": "tcp" if direct_legs else str(mptcp.get("bond_mode", "") or ""),
            "direct_legs": direct_legs,
            "endpoints": endpoints,
            "allow_tcp_fallback": truthy(mptcp.get("allow_tcp_fallback", True)),
            "manage_mptcp_endpoints": truthy(mptcp.get("manage_mptcp_endpoints", True)),
            "mptcp_endpoint_device": str(mptcp.get("mptcp_endpoint_device", "") or ""),
            "mptcp_endpoint_id_base": int_or_default(mptcp.get("mptcp_endpoint_id_base"), 0),
            "mptcp_endpoints": rendered_mptcp_endpoints,
            "underlay": dict(mptcp.get("underlay", {})) if isinstance(mptcp.get("underlay", {}), dict) else {},
            "underlays": [],
        }
        if isinstance(config_payload["underlay"], dict):
            config_payload["underlay"]["local_private_key_path"] = str(pathlib.Path(str(agent_config.get("state_dir", "/var/lib/leikwan-client-agent"))) / "wireguard" / "private.key")
            config_payload["underlay"].setdefault("listen_port", int_or_default(agent_config.get("wireguard_listen_port"), 0))

        # Inject private key and listen port into each underlay for multi-interface mode
        raw_underlays = []
        if private_self_leg_underlays:
            raw_underlays.extend(private_self_leg_underlays)
        mptcp_underlays = mptcp.get("underlays", [])
        if isinstance(mptcp_underlays, list):
            for raw_underlay in mptcp_underlays:
                if not isinstance(raw_underlay, dict):
                    continue
                underlay = dict(raw_underlay)
                if preferred_private_mptcp_endpoints:
                    underlay.setdefault("mode", "slave_transit")
                    standby_legs = []
                    raw_legs = underlay.get("legs", [])
                    if isinstance(raw_legs, list):
                        for raw_leg in raw_legs:
                            if not isinstance(raw_leg, dict):
                                continue
                            leg = dict(raw_leg)
                            leg["backup_only"] = True
                            leg["leg_role"] = "standby"
                            leg.setdefault("health_role", "healthy")
                            standby_legs.append(leg)
                    if standby_legs:
                        underlay["legs"] = standby_legs
                raw_underlays.append(underlay)
        if isinstance(raw_underlays, list):
            base_listen_port = int_or_default(agent_config.get("wireguard_listen_port"), 0)
            forward_id_for_port = int_or_default(forward.get("forward_id"), 0)
            privkey_path = (pathlib.PurePosixPath(str(agent_config.get("state_dir", "/var/lib/leikwan-client-agent")).replace("\\", "/")) / "wireguard" / "private.key").as_posix()
            injected_underlays = []
            port_stride = max(1, len(raw_underlays) or 1)
            port_base = base_listen_port + max(0, (forward_id_for_port - 1)) * port_stride if base_listen_port > 0 and forward_id_for_port > 0 and not private_self_leg_underlays else base_listen_port
            for idx, raw_ul in enumerate(raw_underlays):
                if not isinstance(raw_ul, dict):
                    continue
                ul = dict(raw_ul)
                ul["local_private_key_path"] = privkey_path
                ul.setdefault("listen_port", port_base + idx if port_base > 0 else 0)
                injected_underlays.append(ul)
            config_payload["underlays"] = injected_underlays

        # Check for Rust MPTCP relay binary
        rust_relay_path = "/var/lib/leikwan-client-agent/leikwan-mptcp-relay"
        lines = ["#!/usr/bin/env bash", "set -euo pipefail"]
        if direct_self_leg_endpoints or private_self_leg_underlays:
            lines.append("trap 'kill $(jobs -p) 2>/dev/null || true' EXIT")
        lines.append("unset HTTP_PROXY HTTPS_PROXY ALL_PROXY http_proxy https_proxy all_proxy")
        lines.append("export NO_PROXY='*'")
        lines.append("export no_proxy='*'")
        for index, endpoint in enumerate(direct_self_leg_endpoints):
            direct_target_port = int_or_default(endpoint.get("target_sidecar_port"), 0)
            if direct_target_port <= 0:
                direct_target_port = sidecar_relay_port if sidecar_relay_port > 0 else 0
            append_wstunnel_direct_leg(
                lines,
                endpoint,
                direct_leg_base + index,
                f"127.0.0.1:{direct_target_port}",
                sni,
                path_prefix,
            )
        if direct_legs:
            append_wait_for_local_listener(lines, [int(leg["local_port"]) for leg in direct_legs])
        cf_wg_ports = []
        for underlay in private_self_leg_underlays:
            if not isinstance(underlay, dict):
                continue
            for leg in underlay.get("legs", []):
                if not isinstance(leg, dict):
                    continue
                if str(leg.get("outer_transport", "udp") or "udp").strip().lower() != "wstunnel_udp":
                    continue
                local_udp = int_or_default(leg.get("peer_udp_port"), 0)
                cf_host = str(leg.get("cf_dial_host", "") or "").strip()
                cf_port = int_or_default(leg.get("cf_dial_port"), 0)
                cf_sni = str(leg.get("cf_sni_host", "") or sni).strip()
                cf_path = str(leg.get("cf_path_prefix", "") or path_prefix).strip().strip("/")
                cf_target_host = str(leg.get("cf_target_host", "") or "127.0.0.1").strip()
                cf_target_port = int_or_default(leg.get("cf_target_port"), 0)
                if local_udp <= 0 or cf_host == "" or cf_port <= 0 or cf_target_host == "" or cf_target_port <= 0:
                    continue
                lines.append(
                    f"/usr/local/bin/wstunnel client -L udp://127.0.0.1:{local_udp}:{cf_target_host}:{cf_target_port}?timeout_sec=0 --tls-sni-override {cf_sni} --http-headers \"Host: {cf_sni}\" --http-upgrade-path-prefix \"{cf_path}\" wss://{cf_host}:{cf_port} &"
                )
                cf_wg_ports.append(local_udp)
        if cf_wg_ports:
            append_wait_for_local_listener(lines, cf_wg_ports)
        lines.append(f"/usr/bin/python3 {helper_path} --config {config_path} --setup-underlays || echo \"mptcp setup-underlays returned $?; continuing to runtime listener\" >&2")
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
