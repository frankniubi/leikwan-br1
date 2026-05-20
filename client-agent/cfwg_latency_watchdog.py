#!/usr/bin/env python3
from __future__ import annotations

import json
import logging
import math
import pathlib
import re
import subprocess
import time
from dataclasses import dataclass, field
from typing import Callable, Iterable


@dataclass
class CommandResult:
    returncode: int
    stdout: str = ""
    stderr: str = ""


@dataclass
class CfwgSample:
    at: float
    rtt_ms: float | None
    ok: bool


@dataclass
class CfwgForwardState:
    samples: list[CfwgSample] = field(default_factory=list)
    next_probe_at: float = 0.0
    bad_windows: int = 0
    last_restart_at: float = 0.0
    last_event: str = ""
    best_rtt_ms: float | None = None


class CfwgLatencyWatchdog:
    """ICMP latency watchdog for client-side CF-WG self-leg underlays.

    The older shell watchdog only notices stale WireGuard handshakes. This
    watchdog measures the actual operator signal for CFWG: ICMP RTT to the WG
    peer bound to the lw-cfwg-* interface. Sustained high RTT/loss restarts the
    per-forward client service, which recreates the wstunnel TCP/WSS path.
    """

    def __init__(
        self,
        state_dir: str | pathlib.Path,
        config: dict | None = None,
        command_runner: Callable[[list[str]], CommandResult] | None = None,
        time_fn: Callable[[], float] | None = None,
        runtime_config_dir: str | pathlib.Path = "/etc/leikwan",
    ) -> None:
        self.state_dir = pathlib.Path(state_dir)
        self.config = self.normalize_config(config or {})
        self.command_runner = command_runner or self.default_command_runner
        self.time_fn = time_fn or time.time
        self.runtime_config_dir = pathlib.Path(runtime_config_dir)
        self.states: dict[int, CfwgForwardState] = {}
        self.state_path = self.state_dir / "cfwg-latency-watchdog-state.json"
        self._last_state_write_at = 0.0
        self._latest_forwards_by_id: dict[int, dict] = {}
        self.load_state_snapshot()

    @staticmethod
    def normalize_config(config: dict) -> dict:
        enabled = bool(config.get("enabled", False))
        forward_ids = []
        raw_forward_ids = config.get("forward_ids", [])
        if isinstance(raw_forward_ids, list):
            for raw in raw_forward_ids:
                try:
                    forward_id = int(raw)
                except (TypeError, ValueError):
                    continue
                if forward_id > 0:
                    forward_ids.append(forward_id)

        baseline_rtt_ms: dict[int, float] = {}
        default_baseline_rtt_ms: float | None = None
        raw_baseline = config.get("baseline_rtt_ms", None)
        if isinstance(raw_baseline, dict):
            for raw_key, raw_value in raw_baseline.items():
                try:
                    key = int(raw_key)
                    value = float(raw_value)
                except (TypeError, ValueError):
                    continue
                if key > 0 and value > 0:
                    baseline_rtt_ms[key] = value
        elif raw_baseline is not None:
            try:
                value = float(raw_baseline)
            except (TypeError, ValueError):
                value = 0.0
            if value > 0:
                default_baseline_rtt_ms = value

        raw_actions = config.get("restart_actions", config.get("restart_action", ["wstunnel"]))
        if isinstance(raw_actions, str):
            restart_actions = [raw_actions]
        elif isinstance(raw_actions, list):
            restart_actions = [str(item) for item in raw_actions]
        else:
            restart_actions = ["wstunnel"]
        normalized_actions: list[str] = []
        for action in restart_actions:
            normalized = str(action or "").strip().lower().replace("-", "_")
            if normalized in {"", "service"}:
                normalized = "wstunnel"
            if normalized not in {"wstunnel", "forward_service", "client_service", "wireguard", "wg", "client_agent", "rotate_cf_ip", "cf_ip", "path_correction"}:
                continue
            if normalized not in normalized_actions:
                normalized_actions.append(normalized)
        if not normalized_actions:
            normalized_actions = ["wstunnel"]

        return {
            "enabled": enabled,
            "forward_ids": sorted(set(forward_ids)),
            "probe_interval_seconds": max(0.0, float(config.get("probe_interval_seconds", 5.0) if config.get("probe_interval_seconds") is not None else 5.0)),
            "window_samples": max(1, int(config.get("window_samples", 12) or 12)),
            "critical_rtt_ms": max(1.0, float(config.get("critical_rtt_ms", 55.0) if config.get("critical_rtt_ms") is not None else 55.0)),
            "critical_rtt_delta_ms": max(0.0, float(config.get("critical_rtt_delta_ms", 0.0) if config.get("critical_rtt_delta_ms") is not None else 0.0)),
            "baseline_rtt_ms": baseline_rtt_ms,
            "default_baseline_rtt_ms": default_baseline_rtt_ms,
            "restart_actions": normalized_actions,
            "critical_loss_pct": min(1.0, max(0.0, float(config.get("critical_loss_pct", 0.20) if config.get("critical_loss_pct") is not None else 0.20))),
            "dwell_windows": max(1, int(config.get("dwell_windows", config.get("escalation_dwell_windows", 2)) or 2)),
            "cooldown_seconds": max(0.0, float(config.get("cooldown_seconds", 300.0) if config.get("cooldown_seconds") is not None else 300.0)),
            "ping_timeout_seconds": max(1, int(config.get("ping_timeout_seconds", 1) or 1)),
            "state_write_interval_seconds": max(5.0, float(config.get("state_write_interval_seconds", 30.0) or 30.0)),
            "relative_degradation_factor": max(1.0, float(config.get("relative_degradation_factor", 1.35) if config.get("relative_degradation_factor") is not None else 1.35)),
            "min_relative_degradation_ms": max(0.0, float(config.get("min_relative_degradation_ms", 8.0) if config.get("min_relative_degradation_ms") is not None else 8.0)),
            "baseline_smoothing": min(1.0, max(0.0, float(config.get("baseline_smoothing", 0.20) if config.get("baseline_smoothing") is not None else 0.20))),
            "pin_ttl_seconds": max(60.0, float(config.get("pin_ttl_seconds", 600.0) if config.get("pin_ttl_seconds") is not None else 600.0)),
            "enable_cf_ip_rotation": bool(config.get("enable_cf_ip_rotation", False)),
        }

    def update_config(self, config: dict | None) -> None:
        self.config = self.normalize_config(config or {})

    @staticmethod
    def default_command_runner(command: list[str]) -> CommandResult:
        result = subprocess.run(command, text=True, capture_output=True, check=False)
        return CommandResult(returncode=result.returncode, stdout=result.stdout, stderr=result.stderr)

    def tick(self, forwards: Iterable[dict]) -> None:
        if not bool(self.config.get("enabled", False)):
            return
        forward_list = [forward for forward in forwards if isinstance(forward, dict)]
        for forward in forward_list:
            try:
                forward_id = int(forward.get("forward_id", 0) or 0)
            except (TypeError, ValueError):
                continue
            if forward_id > 0:
                self._latest_forwards_by_id[forward_id] = dict(forward)
        desired_forward_ids = self.configured_forward_ids(forward_list)
        for forward_id in sorted(desired_forward_ids):
            target = self.load_target(forward_id)
            if target is None:
                continue
            self.probe_forward(forward_id, target["interface_name"], target["peer_address"])

    def configured_forward_ids(self, forwards: Iterable[dict]) -> set[int]:
        configured = set(self.config.get("forward_ids", []) or [])
        active_ids: set[int] = set()
        for forward in forwards:
            if not isinstance(forward, dict):
                continue
            if str(forward.get("service_status", "active") or "active") != "active":
                continue
            try:
                forward_id = int(forward.get("forward_id", 0) or 0)
            except (TypeError, ValueError):
                continue
            if forward_id <= 0:
                continue
            if configured and forward_id not in configured:
                continue
            active_ids.add(forward_id)
        return active_ids if active_ids else configured

    def load_target(self, forward_id: int) -> dict | None:
        path = self.runtime_config_dir / f"mptcp-forward-{forward_id}.json"
        if not path.exists():
            return None
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            logging.debug("cfwg latency watchdog: cannot read %s: %s", path, exc)
            return None
        if not isinstance(payload, dict):
            return None
        underlays = payload.get("underlays", [])
        if not isinstance(underlays, list):
            underlays = []
        if isinstance(payload.get("underlay"), dict):
            underlays.append(payload["underlay"])
        for underlay in underlays:
            if not isinstance(underlay, dict):
                continue
            interface_name = str(underlay.get("interface_name", "") or "").strip()
            if not interface_name.startswith("lw-cfwg-"):
                continue
            if str(underlay.get("mode", "") or "").strip() not in {"", "client_self_leg"}:
                continue
            legs = underlay.get("legs", [])
            if not isinstance(legs, list):
                legs = []
            for leg in legs:
                if not isinstance(leg, dict):
                    continue
                if str(leg.get("outer_transport", "") or "").strip().lower() != "wstunnel_udp":
                    continue
                peer_address = str(leg.get("peer_address", "") or leg.get("mptcp_remote_address", "") or "").split("/", 1)[0].strip()
                if peer_address == "":
                    continue
                return {"interface_name": interface_name, "peer_address": peer_address}
        return None

    @staticmethod
    def parse_ping_rtt_ms(output: str) -> float | None:
        match = re.search(r"time[=<]([0-9]+(?:\.[0-9]+)?)\s*ms", output)
        if not match:
            return None
        try:
            return float(match.group(1))
        except ValueError:
            return None

    @staticmethod
    def percentile(values: list[float], fraction: float) -> float:
        if not values:
            return math.inf
        ordered = sorted(values)
        if len(ordered) == 1:
            return ordered[0]
        index = min(len(ordered) - 1, max(0, math.ceil((len(ordered) * fraction)) - 1))
        return ordered[index]

    def probe_forward(self, forward_id: int, interface_name: str, peer_address: str) -> None:
        now = self.time_fn()
        state = self.states.setdefault(forward_id, CfwgForwardState())
        if now < state.next_probe_at:
            return
        state.next_probe_at = now + float(self.config.get("probe_interval_seconds", 5.0) if self.config.get("probe_interval_seconds") is not None else 5.0)
        timeout_seconds = int(self.config.get("ping_timeout_seconds", 1) or 1)
        command = ["ping", "-I", interface_name, "-c", "1", "-W", str(timeout_seconds), peer_address]
        result = self.command_runner(command)
        rtt_ms = self.parse_ping_rtt_ms(result.stdout + "\n" + result.stderr) if result.returncode == 0 else None
        ok = result.returncode == 0 and rtt_ms is not None
        state.samples.append(CfwgSample(at=now, rtt_ms=rtt_ms, ok=ok))
        max_samples = int(self.config.get("window_samples", 12) or 12)
        state.samples = state.samples[-max_samples:]
        if len(state.samples) < max_samples:
            return
        self.evaluate_window(forward_id, interface_name, peer_address, state)
        state.samples = []

    def baseline_rtt_ms(self, forward_id: int, state: CfwgForwardState) -> float | None:
        per_forward = self.config.get("baseline_rtt_ms", {})
        if isinstance(per_forward, dict):
            for key in (forward_id, str(forward_id)):
                if key not in per_forward:
                    continue
                try:
                    value = float(per_forward[key])
                except (TypeError, ValueError):
                    continue
                if value > 0:
                    return value
        default_baseline = self.config.get("default_baseline_rtt_ms")
        if default_baseline is not None:
            try:
                value = float(default_baseline)
            except (TypeError, ValueError):
                return None
            return value if value > 0 else None
        return state.best_rtt_ms

    def update_best_rtt(self, state: CfwgForwardState, rtts: list[float]) -> None:
        if not rtts:
            return
        observed_best = min(rtts)
        if observed_best <= 0:
            return
        if state.best_rtt_ms is None or observed_best < state.best_rtt_ms:
            state.best_rtt_ms = observed_best
            return
        smoothing = float(self.config.get("baseline_smoothing", 0.20) if self.config.get("baseline_smoothing") is not None else 0.20)
        if smoothing > 0 and observed_best < state.best_rtt_ms * 1.10:
            state.best_rtt_ms = (state.best_rtt_ms * (1.0 - smoothing)) + (observed_best * smoothing)

    def cf_candidate_hosts(self, forward_id: int) -> list[str]:
        forward = self._latest_forwards_by_id.get(forward_id, {})
        candidates = forward.get("candidate_pool", [])
        if not isinstance(candidates, list):
            return []
        hosts: list[str] = []
        for candidate in candidates:
            if not isinstance(candidate, dict):
                continue
            if str(candidate.get("probe_transport", "") or "").strip().lower() != "cf_wg":
                continue
            for raw_key in ("self_leg_cf_candidates",):
                raw_values = candidate.get(raw_key, [])
                if isinstance(raw_values, list):
                    for raw in raw_values:
                        host = str(raw or "").strip()
                        if host and host not in hosts:
                            hosts.append(host)
            for raw in (candidate.get("dial_connect_ip"), candidate.get("cf_dial_host")):
                host = str(raw or "").strip()
                if host and host not in hosts:
                    hosts.append(host)
            wg = candidate.get("self_leg_wg", {})
            if isinstance(wg, dict):
                host = str(wg.get("cf_dial_host", "") or "").strip()
                if host and host not in hosts:
                    hosts.append(host)
        return hosts

    def current_cf_pin(self, forward_id: int) -> str:
        path = self.state_dir / "cfwg-watchdog-pin" / f"{forward_id}.json"
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return ""
        if not isinstance(payload, dict):
            return ""
        expires_at = float(payload.get("expires_at", 0) or 0)
        now = self.time_fn()
        if expires_at > 0 and expires_at <= now:
            return ""
        return str(payload.get("cf_dial_host_pin", "") or "").strip()

    def write_next_cf_pin(self, forward_id: int) -> str:
        hosts = self.cf_candidate_hosts(forward_id)
        if len(hosts) < 2:
            return ""
        current = self.current_cf_pin(forward_id)
        if current not in hosts:
            current = hosts[0]
        next_host = hosts[(hosts.index(current) + 1) % len(hosts)]
        path = self.state_dir / "cfwg-watchdog-pin" / f"{forward_id}.json"
        path.parent.mkdir(parents=True, exist_ok=True)
        payload = {
            "cf_dial_host_pin": next_host,
            "expires_at": self.time_fn() + float(self.config.get("pin_ttl_seconds", 600.0) or 600.0),
            "updated_at": self.time_fn(),
            "reason": "cfwg_latency_watchdog",
        }
        path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
        return next_host

    def restart_runtime(self, forward_id: int, interface_name: str) -> list[str]:
        service_name = f"leikwan-client-{forward_id}.service"
        executed: list[str] = []
        service_restarted = False
        wireguard_restarted = False
        for action in self.config.get("restart_actions", ["wstunnel"]):
            normalized = str(action or "").strip().lower().replace("-", "_")
            if normalized in {"rotate_cf_ip", "cf_ip", "path_correction"}:
                if bool(self.config.get("enable_cf_ip_rotation", False)):
                    try:
                        pinned = self.write_next_cf_pin(forward_id)
                    except OSError as exc:
                        logging.warning("cfwg latency watchdog failed to write cf pin for forward=%s: %s", forward_id, exc)
                        pinned = ""
                    if pinned:
                        executed.append(f"cf_pin:{pinned}")
                continue
            if normalized in {"wstunnel", "forward_service", "client_service"}:
                if not service_restarted:
                    self.command_runner(["systemctl", "restart", service_name])
                    executed.append(service_name)
                    service_restarted = True
            elif normalized in {"wireguard", "wg"}:
                if interface_name and not wireguard_restarted:
                    wg_service = f"wg-quick@{interface_name}.service"
                    self.command_runner(["systemctl", "restart", wg_service])
                    executed.append(wg_service)
                    wireguard_restarted = True
            elif normalized == "client_agent":
                if not service_restarted:
                    self.command_runner(["systemctl", "restart", service_name])
                    executed.append(service_name)
                    service_restarted = True
                self.command_runner(["systemctl", "restart", "leikwan-client-agent.service"])
                executed.append("leikwan-client-agent.service")
        if not any(not item.startswith("cf_pin:") for item in executed):
            self.command_runner(["systemctl", "restart", service_name])
            executed.append(service_name)
        return executed

    def evaluate_window(self, forward_id: int, interface_name: str, peer_address: str, state: CfwgForwardState) -> None:
        samples = state.samples[-int(self.config.get("window_samples", 12) or 12):]
        loss_pct = len([sample for sample in samples if not sample.ok]) / max(1, len(samples))
        rtts = [float(sample.rtt_ms) for sample in samples if sample.ok and sample.rtt_ms is not None]
        p95 = self.percentile(rtts, 0.95)
        avg = sum(rtts) / len(rtts) if rtts else math.inf
        critical_loss = loss_pct >= float(self.config.get("critical_loss_pct", 0.20) if self.config.get("critical_loss_pct") is not None else 0.20)
        critical_rtt_ms = float(self.config.get("critical_rtt_ms", 55.0) if self.config.get("critical_rtt_ms") is not None else 55.0)
        critical_delta_ms = float(self.config.get("critical_rtt_delta_ms", 0.0) if self.config.get("critical_rtt_delta_ms") is not None else 0.0)
        baseline = self.baseline_rtt_ms(forward_id, state)
        absolute_rtt_bad = p95 >= critical_rtt_ms
        delta_rtt_bad = baseline is not None and critical_delta_ms > 0 and p95 >= (baseline + critical_delta_ms)
        relative_factor = float(self.config.get("relative_degradation_factor", 1.35) if self.config.get("relative_degradation_factor") is not None else 1.35)
        min_relative_delta = float(self.config.get("min_relative_degradation_ms", 8.0) if self.config.get("min_relative_degradation_ms") is not None else 8.0)
        relative_rtt_bad = (
            baseline is not None
            and relative_factor > 1.0
            and p95 >= max(baseline * relative_factor, baseline + min_relative_delta)
        )
        critical = critical_loss or absolute_rtt_bad or delta_rtt_bad or relative_rtt_bad
        if not critical:
            self.update_best_rtt(state, rtts)
            if state.bad_windows:
                logging.info(
                    "cfwg latency watchdog forward=%s recovered interface=%s peer=%s avg=%.1f p95=%.1f loss=%.0f%% baseline=%s",
                    forward_id,
                    interface_name,
                    peer_address,
                    avg,
                    p95,
                    loss_pct * 100.0,
                    f"{baseline:.1f}" if baseline is not None else "none",
                )
            state.bad_windows = 0
            self.maybe_write_state_snapshot()
            return
        state.bad_windows += 1
        dwell_windows = int(self.config.get("dwell_windows", 2) or 2)
        if state.bad_windows < dwell_windows:
            return
        now = self.time_fn()
        cooldown_seconds = float(self.config.get("cooldown_seconds", 300.0) if self.config.get("cooldown_seconds") is not None else 300.0)
        if now - state.last_restart_at < cooldown_seconds:
            return
        restarted = self.restart_runtime(forward_id, interface_name)
        state.last_restart_at = now
        state.bad_windows = 0
        state.last_event = "restart:" + ",".join(restarted)
        logging.warning(
            "cfwg latency watchdog restarted %s: interface=%s peer=%s avg=%.1fms p95=%.1fms loss=%.0f%% threshold_rtt=%.1f baseline=%s threshold_delta=%.1f relative_factor=%.2f min_relative_delta=%.1f threshold_loss=%.0f%%",
            ",".join(restarted),
            interface_name,
            peer_address,
            avg,
            p95,
            loss_pct * 100.0,
            critical_rtt_ms,
            f"{baseline:.1f}" if baseline is not None else "none",
            critical_delta_ms,
            relative_factor,
            min_relative_delta,
            float(self.config.get("critical_loss_pct", 0.20) if self.config.get("critical_loss_pct") is not None else 0.20) * 100.0,
        )
        self.write_state_snapshot()

    def load_state_snapshot(self) -> None:
        if not self.state_path.exists():
            return
        try:
            payload = json.loads(self.state_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            logging.debug("cfwg latency watchdog: failed to load state: %s", exc)
            return
        if not isinstance(payload, dict):
            return
        for raw_forward_id, raw_state in payload.items():
            if not isinstance(raw_state, dict):
                continue
            try:
                forward_id = int(raw_forward_id)
            except (TypeError, ValueError):
                continue
            if forward_id <= 0:
                continue
            state = CfwgForwardState()
            try:
                best = raw_state.get("best_rtt_ms")
                if best is not None:
                    best_value = float(best)
                    if best_value > 0:
                        state.best_rtt_ms = best_value
                state.last_restart_at = float(raw_state.get("last_restart_at", 0.0) or 0.0)
                state.bad_windows = max(0, int(raw_state.get("bad_windows", 0) or 0))
                state.last_event = str(raw_state.get("last_event", "") or "")
            except (TypeError, ValueError):
                pass
            self.states[forward_id] = state

    def maybe_write_state_snapshot(self) -> None:
        now = self.time_fn()
        interval = float(self.config.get("state_write_interval_seconds", 30.0) or 30.0)
        if now - self._last_state_write_at < interval:
            return
        self._last_state_write_at = now
        self.write_state_snapshot()

    def write_state_snapshot(self) -> None:
        try:
            self.state_dir.mkdir(parents=True, exist_ok=True)
            payload = {
                str(forward_id): {
                    "last_restart_at": state.last_restart_at,
                    "bad_windows": state.bad_windows,
                    "last_event": state.last_event,
                    "best_rtt_ms": state.best_rtt_ms,
                    "samples": [
                        {"at": sample.at, "ok": sample.ok, "rtt_ms": sample.rtt_ms}
                        for sample in state.samples[-int(self.config.get("window_samples", 12) or 12):]
                    ],
                }
                for forward_id, state in self.states.items()
            }
            self.state_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
        except OSError as exc:
            logging.debug("cfwg latency watchdog: failed to write state: %s", exc)
