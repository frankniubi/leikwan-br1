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
import subprocess
import time
import urllib.error
import urllib.request

AGENT_VERSION = "2026.04.19.1"
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
        for key in ("service_name", "runtime_config_path", "state_dir"):
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

    def cf_uot_service_name(self, forward_id: int) -> str:
        return self.service_name() + f"-cf-uot-{forward_id}"

    def cf_uot_runtime_config_path(self, forward_id: int) -> pathlib.Path:
        return self.state_dir / f"cf-uot-{forward_id}.json"

    def cf_uot_start_script_path(self, forward_id: int) -> pathlib.Path:
        return self.state_dir / f"start-cf-uot-{forward_id}.sh"

    def allocate_cf_backend_port(self, service_name: str) -> int:
        used = {
            int(item["backend_port"])
            for item in self.load_managed_cf_metadata()
            if int(item.get("backend_port", 0) or 0) > 0 and str(item.get("service_name", "")) != service_name
        }
        for port in range(26000, 26100):
            if port not in used:
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
        unit_name = service_name or self.service_name()
        result = subprocess.run(
            f"systemctl is-active {unit_name}.service",
            shell=True,
            text=True,
            capture_output=True,
            check=False,
        )
        return result.stdout.strip() == "active"

    def disable_runtime(self, service_name: str, unit_path: pathlib.Path, start_script_path: pathlib.Path | None = None, runtime_config_path: pathlib.Path | None = None) -> None:
        subprocess.run(f"systemctl stop {service_name}.service", shell=True, check=False)
        subprocess.run(f"systemctl disable {service_name}.service", shell=True, check=False)
        if unit_path.exists():
            unit_path.unlink()
        if start_script_path is not None and start_script_path.exists():
            start_script_path.unlink()
        if runtime_config_path is not None and runtime_config_path.exists():
            runtime_config_path.unlink()
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
        config_patch = dict(management.get("config_patch", {})) if isinstance(management.get("config_patch", {}), dict) else {}
        if config_patch != {}:
            self.apply_config_patch(config_patch)

        explicit_request_token = str(management.get("agent_update_request_token", "") or "")
        auto_request_token = self.auto_update_request_token(management)
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
                rendered_routes: list[dict[str, Any]] = []
                for route in routes:
                    if not isinstance(route, dict):
                        continue
                    service_name = str(route.get("service_name", "") or "").strip()
                    path_prefix = str(route.get("path_prefix", "") or "").strip()
                    target = str(route.get("target", "") or "").strip()
                    if service_name == "" or path_prefix == "" or target == "":
                        continue
                    backend_port = self.allocate_cf_backend_port(service_name)
                    preview_path = self.state_dir / f"{service_name}.service.preview"
                    preview_path.write_text(
                        self.render_cf_backend_unit(service_name, backend_port, target, path_prefix),
                        encoding="utf-8",
                    )
                    rendered_routes.append(
                        {
                            "forward_id": int(route.get("forward_id", 0) or 0),
                            "service_name": service_name,
                            "path_prefix": path_prefix,
                            "target": target,
                            "backend_port": backend_port,
                            "transport_mode": str(route.get("transport_mode", "tcp") or "tcp"),
                            "shadow_route_key": str(route.get("shadow_route_key", "") or ""),
                        }
                    )
                self.save_managed_cf_metadata(rendered_routes)
                cf_routes_path.write_text(json.dumps({"routes": rendered_routes}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
                unit_body = self.render_cf_mux_unit(
                    listen_port,
                    str(cf_runtime.get("tls_cert_path", "") or ""),
                    str(cf_runtime.get("tls_key_path", "") or ""),
                    cf_routes_path,
                )
                unit_changed = write_text_if_changed(cf_unit_path, unit_body)
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
                f'BOND_SERVER="{self.bond_binary_path()}"',
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
                f"exec /usr/bin/python3 /usr/local/bin/leikwan-udp-uot-merge.py --config {udp_runtime_path}",
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
            }
            runtime_changed = write_text_if_changed(secondary_runtime_path, json.dumps(runtime_config, ensure_ascii=False, indent=2) + "\n", mode=0o600)
            rust_relay_path = pathlib.Path(str(self.state_dir)) / "leikwan-mptcp-relay"
            start_script_lines = [
                "#!/usr/bin/env bash",
                "set -euo pipefail",
                f'RUST_RELAY="{rust_relay_path}"',
                'if [ -x "$RUST_RELAY" ]; then',
                f'    exec "$RUST_RELAY" server --config {secondary_runtime_path}',
                'else',
                f'    exec /usr/bin/python3 {self.runtime_bin()} --config {secondary_runtime_path}',
                'fi',
            ]
            start_script_changed = write_text_if_changed(secondary_start_script, "\n".join(start_script_lines) + "\n", mode=0o755)
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
            if unit_changed or runtime_changed or start_script_changed:
                subprocess.run("systemctl daemon-reload", shell=True, check=False)
            if unit_changed or runtime_changed or start_script_changed or not self.systemd_is_active(secondary_service_name):
                subprocess.run(f"systemctl enable {secondary_service_name}.service", shell=True, check=False)
                subprocess.run(f"systemctl restart {secondary_service_name}.service", shell=True, check=False)
        else:
            self.disable_runtime(secondary_service_name, secondary_unit_path, secondary_start_script, secondary_runtime_path)

    def heartbeat_once(self) -> None:
        stats = self.load_runtime_stats()
        master = str(self.config.get("master_url", "")).rstrip("/")
        token = str(self.config.get("agent_token", ""))
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
