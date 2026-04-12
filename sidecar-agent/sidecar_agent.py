#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import logging
import os
import pathlib
import shutil
import subprocess
import time
import urllib.error
import urllib.request

AGENT_VERSION = "2026.04.12.1"
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
}


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

    def load_runtime_stats(self) -> dict:
        if not self.runtime_stats_path.exists():
            return {}
        try:
            payload = json.loads(self.runtime_stats_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            return {}
        return payload if isinstance(payload, dict) else {}

    def save_pull_state(self, payload: dict) -> None:
        self.pull_state_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    def service_name(self) -> str:
        return str(self.config.get("service_name", DEFAULT_CONFIG["service_name"]) or DEFAULT_CONFIG["service_name"])

    def service_unit_path(self) -> pathlib.Path:
        return pathlib.Path(f"/etc/systemd/system/{self.service_name()}.service")

    def runtime_config_path(self) -> pathlib.Path:
        return pathlib.Path(str(self.config.get("runtime_config_path", DEFAULT_CONFIG["runtime_config_path"]) or DEFAULT_CONFIG["runtime_config_path"]))

    def runtime_bin(self) -> str:
        return str(self.config.get("runtime_bin", DEFAULT_CONFIG["runtime_bin"]) or DEFAULT_CONFIG["runtime_bin"])

    def systemd_is_active(self) -> bool:
        result = subprocess.run(
            f"systemctl is-active {self.service_name()}.service",
            shell=True,
            text=True,
            capture_output=True,
            check=False,
        )
        return result.stdout.strip() == "active"

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

    def apply_management(self, management: dict) -> None:
        self.ensure_relay_binary()
        underlay = dict(management.get("underlay", {})) if isinstance(management.get("underlay", {}), dict) else {}
        if underlay != {}:
            underlay["local_private_key_path"] = str(self.wireguard_private_key_path())
            underlay.setdefault("listen_port", self.wireguard_listen_port())
        mptcp_policy = dict(management.get("mptcp_policy", {})) if isinstance(management.get("mptcp_policy", {}), dict) else {}
        # Handle new multi-interface underlays format
        raw_underlays = management.get("underlays", [])
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
            "listen_host": str(management.get("bind_host", "0.0.0.0") or "0.0.0.0"),
            "listen_port": int(management.get("listen_port", 0) or 0),
            "token_secret": str(management.get("token_secret", "") or ""),
            "stats_path": str(self.runtime_stats_path),
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
        }
        runtime_config_path = self.runtime_config_path()
        runtime_changed = write_text_if_changed(runtime_config_path, json.dumps(runtime_config, ensure_ascii=False, indent=2) + "\n", mode=0o600)

        # Check for Rust MPTCP relay binary; fall back to Python runtime
        rust_relay_path = pathlib.Path(str(self.state_dir)) / "leikwan-mptcp-relay"
        start_script_lines = [
            "#!/usr/bin/env bash",
            "set -euo pipefail",
            f'RUST_RELAY="{rust_relay_path}"',
            'if [ -x "$RUST_RELAY" ]; then',
            f'    exec "$RUST_RELAY" server --config {runtime_config_path}',
            'else',
            f'    exec /usr/bin/python3 {self.runtime_bin()} --config {runtime_config_path}',
            'fi',
        ]
        start_script_path = self.state_dir / "start-sidecar.sh"
        start_script_changed = write_text_if_changed(start_script_path, "\n".join(start_script_lines) + "\n", mode=0o755)

        unit_body = f"""[Unit]
Description=Leikwan MPTCP Sidecar
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
        unit_changed = write_text_if_changed(self.service_unit_path(), unit_body)
        if unit_changed or runtime_changed or start_script_changed:
            subprocess.run("systemctl daemon-reload", shell=True, check=False)
        if unit_changed or runtime_changed or start_script_changed or not self.systemd_is_active():
            subprocess.run(f"systemctl enable {self.service_name()}.service", shell=True, check=False)
            subprocess.run(f"systemctl restart {self.service_name()}.service", shell=True, check=False)

    def heartbeat_once(self) -> None:
        stats = self.load_runtime_stats()
        master = str(self.config.get("master_url", "")).rstrip("/")
        token = str(self.config.get("agent_token", ""))
        wireguard_public_key = self.ensure_wireguard_identity()
        payload = {
            "status": "ok",
            "version": AGENT_VERSION,
            "usage_bytes_in": int(stats.get("bytes_in", 0) or 0),
            "usage_bytes_out": int(stats.get("bytes_out", 0) or 0),
            "supports_mptcp": self.running_kernel_supports_mptcp(),
            "wireguard_public_key": wireguard_public_key,
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
        }
        response = http_json("POST", f"{master}/api/sidecar/heartbeat", token, payload)
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
            }
        )

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
