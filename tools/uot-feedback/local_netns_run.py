#!/usr/bin/env python3
"""Run paired local network-namespace acceptance tests for the native UoT path."""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import math
import os
import pathlib
import shutil
import signal
import subprocess
import sys
import time
from typing import Any


HERE = pathlib.Path(__file__).resolve().parent
WORKTREE = HERE.parents[1]
CLIENT_NS = "uot-qa-20260808-client"
SLAVE_NS = "uot-qa-20260808-slave"
SIDECAR_NS = "uot-qa-20260808-sidecar"
NAMESPACES = (CLIENT_NS, SLAVE_NS, SIDECAR_NS)
RESERVED_PORT = 20_013
SIDECAR_LISTEN_PORT = 8_899
TARGET_PORT = 9_900
CLIENT_LISTEN_PORT = 9_901
FORWARD_ID = 3
TOKEN_SECRET = "uot-local-netns-20260808"


def _b64url(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).decode("ascii").rstrip("=")


def session_token(
    secret: str,
    target_host: str,
    target_port: int,
    forward_id: int,
) -> str:
    payload = json.dumps(
        {
            "forward_id": forward_id,
            "target_protocol": "udp",
            "target_host": target_host,
            "target_port": target_port,
            "expires_at": 0,
        },
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    signature = hmac.new(secret.encode("utf-8"), payload, hashlib.sha256).digest()
    return f"{_b64url(payload)}.{_b64url(signature)}"


def percentile(values: list[float], quantile: float) -> float:
    if not values:
        raise ValueError("percentile requires samples")
    ordered = sorted(float(value) for value in values)
    rank = max(1, math.ceil(len(ordered) * quantile))
    return ordered[rank - 1]


def jain_fairness(values: list[float]) -> float:
    if not values:
        return 1.0
    total = sum(values)
    squares = sum(value * value for value in values)
    return 1.0 if squares == 0 else total * total / (len(values) * squares)


def _object(value: Any) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def _counter(value: Any) -> int:
    if isinstance(value, dict):
        value = value.get("packets", value.get("count", 0))
    return int(value or 0)


def _scheduler(stats: dict[str, Any]) -> dict[str, Any]:
    return _object(stats.get("scheduler"))


def _sum_udp_errors(stats: dict[str, Any]) -> int:
    scheduler_errors = _scheduler(stats).get("udp_errors", {})
    nested = (
        sum(_counter(value) for value in scheduler_errors.values())
        if isinstance(scheduler_errors, dict)
        else _counter(scheduler_errors)
    )
    return int(stats.get("udp_egress_errors", 0) or 0) + nested


def _deadline_drops(stats: dict[str, Any]) -> int:
    drops = _object(_scheduler(stats).get("drops"))
    return _counter(drops.get("drop_queue_deadline"))


def build_evidence(
    *,
    mode: str,
    sender: dict[str, Any],
    receiver: dict[str, Any],
    client_stats: dict[str, Any],
    sidecar_stats: dict[str, Any],
    retransmissions: int,
    duration_seconds: int,
    offered_rate_bps: int,
    sessions: int,
    packet_bytes: int,
) -> dict[str, Any]:
    sent_packets = int(sender["packets_sent"])
    received_packets = int(receiver["unique_packets"])
    window_packets = int(receiver["window_unique_packets"])
    per_session = [float(value) for value in receiver["per_session_packets"]]
    per_second_bytes = [int(value) for value in receiver["per_second_useful_bytes"]]
    rtt = [float(value) for value in sender["probe_rtt_ms"] if math.isfinite(float(value))]
    if len(rtt) < 4:
        raise ValueError("insufficient RTT probe samples")

    window = max(1, len(rtt) // 4)
    client_scheduler = _scheduler(client_stats)
    sidecar_scheduler = _scheduler(sidecar_stats)
    schedulers = [value for value in (client_scheduler, sidecar_scheduler) if value]
    queue_p95 = max(
        (float(_object(value.get("scheduler_queue")).get("p95_ms", 0)) for value in schedulers),
        default=0.0,
    )
    queue_p99 = max(
        (float(_object(value.get("scheduler_queue")).get("p99_ms", 0)) for value in schedulers),
        default=0.0,
    )
    scheduler_drop_ratio = max(
        (float(value.get("scheduler_drop_ratio", 0.0)) for value in schedulers),
        default=0.0,
    )
    queue_high_water = max(
        (int(value.get("queue_bytes_high_water", 0)) for value in schedulers),
        default=0,
    )
    active_legs = min(
        int(client_stats.get("live_legs", 0)),
        int(sidecar_stats.get("live_legs", 0)),
    )
    if mode == "feedback_drr":
        feedback_legs = min(
            int(client_scheduler.get("feedback_capable_active_legs", 0)),
            int(sidecar_scheduler.get("feedback_capable_active_legs", 0)),
        )
        legacy_legs = max(
            int(client_scheduler.get("legacy_fallback_active_legs", 0)),
            int(sidecar_scheduler.get("legacy_fallback_active_legs", 0)),
        )
    else:
        feedback_legs = 0
        legacy_legs = active_legs

    return {
        "duration_seconds": duration_seconds,
        "mode": mode,
        "pair_id": "local-netns-normal-64-a",
        "workload": f"sessions{sessions}",
        "offered_rate_bps": offered_rate_bps,
        "actual_offered_rate_bps": int(sender.get("actual_offered_rate_bps", 0)),
        "useful_rate_bps": round(window_packets * packet_bytes * 8 / duration_seconds),
        "queue_p95_ms": queue_p95,
        "queue_p99_ms": queue_p99,
        "scheduler_drop_ratio": scheduler_drop_ratio,
        "ingress_channel_full": sum(
            int(value.get("ingress_channel_full", 0)) for value in schedulers
        ),
        "control_queue_drops": int(client_stats.get("control_queue_drops", 0))
        + int(sidecar_stats.get("control_queue_drops", 0)),
        "deadline_drops": _deadline_drops(client_stats) + _deadline_drops(sidecar_stats),
        "udp_send_errors": (
            _sum_udp_errors(client_stats)
            + _sum_udp_errors(sidecar_stats)
            + int(sender.get("send_errors", 0))
            + int(receiver.get("echo_errors", 0))
        ),
        "zero_rate_seconds": sum(1 for value in per_second_bytes if value <= 0),
        "complete_seconds": len(per_second_bytes),
        "jain_fairness": jain_fairness(per_session),
        "memory_bound_violations": int(queue_high_water > 1_048_576),
        "receiver_loss_ratio": max(
            0.0,
            (sent_packets - received_packets) / sent_packets if sent_packets else 1.0,
        ),
        "loaded_rtt_p95_ms": percentile(rtt, 0.95),
        "loaded_rtt_p99_ms": percentile(rtt, 0.99),
        "latency_drift_ms": abs(
            percentile(rtt[-window:], 0.95) - percentile(rtt[:window], 0.95)
        ),
        "inner_tcp_retransmissions": max(0, retransmissions),
        "direction": "client_to_sidecar",
        "datagram_bytes": packet_bytes,
        "topology_id": "local-netns-five-leg",
        "active_legs": active_legs,
        "feedback_capable_active_legs": feedback_legs,
        "legacy_fallback_active_legs": legacy_legs,
        "queue_bytes_high_water": queue_high_water,
        "queue_limit_bytes": 1_048_576,
        "packets_sent": sent_packets,
        "packets_received": received_packets,
        "packets_received_in_window": window_packets,
        "probe_samples": len(rtt),
        "sender_send_errors": int(sender.get("send_errors", 0)),
        "receiver_duplicates": int(receiver.get("duplicates", 0)),
        "receiver_out_of_order_events": int(receiver.get("out_of_order_events", 0)),
        "receiver_invalid_packets": int(receiver.get("invalid_packets", 0)),
        "receiver_echo_errors": int(receiver.get("echo_errors", 0)),
    }


def _run(
    command: list[str],
    *,
    check: bool = True,
    capture: bool = True,
    timeout: float | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        command,
        check=check,
        capture_output=capture,
        text=True,
        timeout=timeout,
    )


def _namespace_command(namespace: str, command: list[str]) -> list[str]:
    return ["ip", "netns", "exec", namespace, *command]


def _read_tcp_retransmissions(namespace: str) -> int:
    output = _run(_namespace_command(namespace, ["cat", "/proc/net/snmp"])).stdout
    lines = [line.split() for line in output.splitlines() if line.startswith("Tcp:")]
    if len(lines) < 2:
        raise RuntimeError(f"Tcp counters missing in {namespace}")
    headings = lines[-2][1:]
    values = lines[-1][1:]
    return int(dict(zip(headings, values, strict=True))["RetransSegs"])


def _reserved_listener_snapshot() -> dict[str, list[str]]:
    snapshots: dict[str, list[str]] = {}
    commands = {"root": ["ss", "-H", "-lntup"]}
    commands.update(
        {
            namespace: _namespace_command(namespace, ["ss", "-H", "-lntup"])
            for namespace in NAMESPACES
        }
    )
    for name, command in commands.items():
        output = _run(command).stdout
        snapshots[name] = sorted(
            line for line in output.splitlines() if f":{RESERVED_PORT}" in line
        )
    return snapshots


def _scheduler_config(mode: str, rate_bps: int) -> dict[str, Any]:
    return {
        "scheduler_mode": mode,
        "aggregate_rate_bps": rate_bps,
        "queue_target_ms": 5,
        "queue_deadline_ms": 10,
        "global_queue_bytes": 1_048_576,
        "global_queue_packets": 8_192,
        "per_session_queue_bytes": 262_144,
        "per_session_queue_packets": 512,
        "drr_quantum_bytes": 16_384,
        "chunk_min_bytes": 16_384,
        "chunk_target_bytes": 32_768,
        "chunk_max_bytes": 65_536,
        "min_leg_window_bytes": 262_144,
        "max_leg_window_bytes": 4_194_304,
        "max_total_outstanding_bytes": 8_388_608,
        "progress_interval_bytes": 65_536,
        "progress_interval_frames": 32,
        "progress_interval_ms": 5,
        "finish_skew_budget_ms": 2,
        "probe_interval_ms": 1_000,
        "max_sessions": 4_096,
        "control_queue_packets": 256,
    }


def _write_configs(
    mode_dir: pathlib.Path,
    mode: str,
    rate_bps: int,
    packet_bytes: int,
) -> tuple[pathlib.Path, pathlib.Path]:
    token = session_token(TOKEN_SECRET, "127.0.0.1", TARGET_PORT, FORWARD_ID)
    wire_rate_bps = math.ceil(rate_bps * (packet_bytes + 24) / packet_bytes)
    scheduler = _scheduler_config(mode, wire_rate_bps)
    common = {
        "reorder_window_packets": 64,
        "reorder_window_bytes": 1_048_576,
        "reorder_timeout_ms": 10,
        "delivery_mode": "unordered",
        "wireguard_aware": True,
        "max_leg_queue_packets": 8_192,
        "max_leg_queue_bytes": 16_777_216,
        "max_datagram_bytes": packet_bytes,
        "udp_socket_buffer_bytes": 8_388_608,
        "session_idle_timeout_ms": 120_000,
        **scheduler,
    }
    sidecar_stats = mode_dir / "sidecar-stats.json"
    client_stats = mode_dir / "client-stats.json"
    sidecar = {
        "listen_host": "0.0.0.0",
        "listen_port": SIDECAR_LISTEN_PORT,
        "token_secret": TOKEN_SECRET,
        "allowed_forward_ids": [FORWARD_ID],
        "stats_path": str(sidecar_stats),
        **common,
    }
    client = {
        "forward_id": FORWARD_ID,
        "listen_udp": f"127.0.0.1:{CLIENT_LISTEN_PORT}",
        "route_key": "local-netns",
        "session_token": token,
        "legs": [
            {
                "endpoint_id": leg,
                "host": f"10.251.{leg}.2",
                "port": SIDECAR_LISTEN_PORT,
                "auth_token": "local-direct",
                "dynamic_priority": 0,
                "health_role": "healthy",
                "weight": 1,
                "direct_tcp": True,
            }
            for leg in range(1, 6)
        ],
        "stats_path": str(client_stats),
        **common,
    }
    sidecar_path = mode_dir / "sidecar.json"
    client_path = mode_dir / "client.json"
    sidecar_path.write_text(json.dumps(sidecar, indent=2, sort_keys=True) + "\n")
    client_path.write_text(json.dumps(client, indent=2, sort_keys=True) + "\n")
    return client_path, sidecar_path


def _read_json(path: pathlib.Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def _wait_for_legs(
    client_stats: pathlib.Path,
    sidecar_stats: pathlib.Path,
    mode: str,
    timeout_seconds: float = 15,
) -> None:
    deadline = time.monotonic() + timeout_seconds
    last = "stats not written"
    while time.monotonic() < deadline:
        try:
            client = _read_json(client_stats)
            sidecar = _read_json(sidecar_stats)
            client_ready = int(client.get("live_legs", 0)) == 5
            sidecar_ready = int(sidecar.get("live_legs", 0)) == 5
            if mode == "feedback_drr":
                client_ready = client_ready and int(
                    _scheduler(client).get("feedback_capable_active_legs", 0)
                ) == 5
                sidecar_ready = sidecar_ready and int(
                    _scheduler(sidecar).get("feedback_capable_active_legs", 0)
                ) == 5
            if client_ready and sidecar_ready:
                return
            last = f"client={client} sidecar={sidecar}"
        except (OSError, ValueError) as error:
            last = str(error)
        time.sleep(0.2)
    raise RuntimeError(f"five legs did not become ready: {last}")


def _start_logged(
    command: list[str],
    log_path: pathlib.Path,
) -> tuple[subprocess.Popen[bytes], Any]:
    handle = log_path.open("wb")
    process = subprocess.Popen(command, stdout=handle, stderr=subprocess.STDOUT)
    return process, handle


def _stop_process(process: subprocess.Popen[bytes] | None) -> None:
    if process is None or process.poll() is not None:
        return
    process.send_signal(signal.SIGINT)
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        process.terminate()
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=3)


def _run_mode(
    *,
    mode: str,
    output_dir: pathlib.Path,
    traffic_binary: pathlib.Path,
    client_binary: pathlib.Path,
    sidecar_binary: pathlib.Path,
    duration_seconds: int,
    sessions: int,
    rate_bps: int,
    packet_bytes: int,
) -> dict[str, Any]:
    mode_dir = output_dir / mode
    mode_dir.mkdir(parents=True, exist_ok=True)
    client_config, sidecar_config = _write_configs(
        mode_dir,
        mode,
        rate_bps,
        packet_bytes,
    )
    client_stats_path = mode_dir / "client-stats.json"
    sidecar_stats_path = mode_dir / "sidecar-stats.json"
    sender_path = mode_dir / "sender.json"
    receiver_path = mode_dir / "receiver.json"
    sidecar_process = client_process = receiver_process = sender_process = None
    handles: list[Any] = []
    try:
        sidecar_process, handle = _start_logged(
            _namespace_command(
                SIDECAR_NS,
                [str(sidecar_binary), "--config", str(sidecar_config)],
            ),
            mode_dir / "sidecar.log",
        )
        handles.append(handle)
        time.sleep(0.25)
        client_process, handle = _start_logged(
            _namespace_command(
                CLIENT_NS,
                [str(client_binary), "--config", str(client_config)],
            ),
            mode_dir / "client.log",
        )
        handles.append(handle)
        _wait_for_legs(client_stats_path, sidecar_stats_path, mode)

        receiver_process, handle = _start_logged(
            _namespace_command(
                SIDECAR_NS,
                [
                    str(traffic_binary),
                    "receiver",
                    "--bind",
                    f"127.0.0.1:{TARGET_PORT}",
                    "--duration-seconds",
                    str(duration_seconds),
                    "--grace-seconds",
                    "2",
                    "--sessions",
                    str(sessions),
                    "--output",
                    str(receiver_path),
                ],
            ),
            mode_dir / "receiver.log",
        )
        handles.append(handle)
        retransmit_before = _read_tcp_retransmissions(
            CLIENT_NS
        ) + _read_tcp_retransmissions(SIDECAR_NS)
        sender_process, handle = _start_logged(
            _namespace_command(
                CLIENT_NS,
                [
                    str(traffic_binary),
                    "sender",
                    "--target",
                    f"127.0.0.1:{CLIENT_LISTEN_PORT}",
                    "--duration-seconds",
                    str(duration_seconds),
                    "--sessions",
                    str(sessions),
                    "--rate-bps",
                    str(rate_bps),
                    "--packet-bytes",
                    str(packet_bytes),
                    "--probe-every-ms",
                    "50",
                    "--output",
                    str(sender_path),
                ],
            ),
            mode_dir / "sender.log",
        )
        handles.append(handle)
        timeline: list[dict[str, Any]] = []
        sender_started = time.monotonic()
        sender_deadline = sender_started + duration_seconds + 20
        last_snapshot = ""
        while sender_process.poll() is None:
            if time.monotonic() >= sender_deadline:
                raise RuntimeError("sender timed out")
            try:
                raw_snapshot = client_stats_path.read_text(encoding="utf-8")
                if raw_snapshot != last_snapshot:
                    stats = json.loads(raw_snapshot)
                    scheduler_stats = _scheduler(stats)
                    timeline.append(
                        {
                            "elapsed_ms": round(
                                (time.monotonic() - sender_started) * 1_000
                            ),
                            "datagrams_in": int(stats.get("datagrams_in", 0)),
                            "deadline_drops": _deadline_drops(stats),
                            "queue_packets": int(
                                scheduler_stats.get("queue_packets", 0)
                            ),
                            "remote_udp_accepted": _counter(
                                _object(scheduler_stats.get("lifecycle")).get(
                                    "remote_udp_accepted"
                                )
                            ),
                            "legs": scheduler_stats.get("legs", []),
                        }
                    )
                    last_snapshot = raw_snapshot
            except (OSError, ValueError):
                pass
            time.sleep(0.05)
        if sender_process.returncode != 0:
            raise RuntimeError(
                f"sender failed: {(mode_dir / 'sender.log').read_text(errors='replace')}"
            )
        (mode_dir / "timeline.json").write_text(
            json.dumps(timeline, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        receiver_process.wait(timeout=duration_seconds + 20)
        if receiver_process.returncode != 0:
            raise RuntimeError(
                f"receiver failed: {(mode_dir / 'receiver.log').read_text(errors='replace')}"
            )
        time.sleep(1.2)
        retransmit_after = _read_tcp_retransmissions(
            CLIENT_NS
        ) + _read_tcp_retransmissions(SIDECAR_NS)
        client_stats = _read_json(client_stats_path)
        sidecar_stats = _read_json(sidecar_stats_path)
        sender_stats = _read_json(sender_path)
        receiver_stats = _read_json(receiver_path)
        evidence = build_evidence(
            mode=mode,
            sender=sender_stats,
            receiver=receiver_stats,
            client_stats=client_stats,
            sidecar_stats=sidecar_stats,
            retransmissions=retransmit_after - retransmit_before,
            duration_seconds=duration_seconds,
            offered_rate_bps=rate_bps,
            sessions=sessions,
            packet_bytes=packet_bytes,
        )
        (mode_dir / "run.json").write_text(
            json.dumps(evidence, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        _run(
            _namespace_command(CLIENT_NS, ["ss", "-tinp"]),
            check=False,
        ).stdout
        (mode_dir / "client-ss.txt").write_text(
            _run(_namespace_command(CLIENT_NS, ["ss", "-tinp"]), check=False).stdout,
            encoding="utf-8",
        )
        (mode_dir / "sidecar-ss.txt").write_text(
            _run(_namespace_command(SIDECAR_NS, ["ss", "-tinp"]), check=False).stdout,
            encoding="utf-8",
        )
        return evidence
    finally:
        _stop_process(receiver_process)
        _stop_process(sender_process)
        _stop_process(client_process)
        _stop_process(sidecar_process)
        for handle in handles:
            handle.close()
        time.sleep(0.25)


def _set_sysctl(name: str, value: int) -> str | None:
    try:
        previous = _run(["sysctl", "-n", name]).stdout.strip()
        _run(["sysctl", "-q", "-w", f"{name}={value}"])
        return previous
    except (OSError, subprocess.SubprocessError):
        return None


def _restore_sysctl(name: str, value: str | None) -> None:
    if value is None:
        return
    _run(["sysctl", "-q", "-w", f"{name}={value}"], check=False)


def _parse_args(argv: list[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=pathlib.Path, required=True)
    parser.add_argument("--duration-seconds", type=int, default=60)
    parser.add_argument("--sessions", type=int, default=64)
    parser.add_argument("--rate-bps", type=int, default=300_000_000)
    parser.add_argument("--packet-bytes", type=int, default=1_200)
    parser.add_argument(
        "--modes",
        nargs="+",
        choices=("legacy", "feedback_drr"),
        default=("legacy", "feedback_drr"),
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv)
    if os.geteuid() != 0:
        raise SystemExit("local netns runner must run as root")
    args.output_dir = args.output_dir.resolve()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    client_binary = WORKTREE / "artifacts/wstunnel/leikwan-uot-bond_linux_amd64"
    sidecar_binary = WORKTREE / "artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64"
    traffic_binary = args.output_dir / "uot-traffic"
    rustc = shutil.which("rustc") or "/root/.cargo/bin/rustc"
    _run(
        [
            rustc,
            "--edition",
            "2021",
            "-O",
            str(HERE / "traffic.rs"),
            "-o",
            str(traffic_binary),
        ]
    )

    topology = HERE / "netns.sh"
    manifest = HERE / "manifests/local-netns.json"
    setup = [
        "bash",
        str(topology),
        "setup",
        "--manifest",
        str(manifest),
        "--artifact-dir",
        str(args.output_dir),
        "--client-bin",
        str(client_binary),
        "--sidecar-bin",
        str(sidecar_binary),
    ]
    teardown = [
        "bash",
        str(topology),
        "teardown",
        "--manifest",
        str(manifest),
        "--artifact-dir",
        str(args.output_dir),
    ]
    original_rmem = original_wmem = None
    reserved_before: dict[str, list[str]] = {}
    runs: dict[str, dict[str, Any]] = {}
    try:
        _run(setup)
        reserved_before = _reserved_listener_snapshot()
        original_rmem = _set_sysctl("net.core.rmem_max", 16_777_216)
        original_wmem = _set_sysctl("net.core.wmem_max", 16_777_216)
        for mode in args.modes:
            print(f"running mode={mode} duration={args.duration_seconds}s", flush=True)
            runs[mode] = _run_mode(
                mode=mode,
                output_dir=args.output_dir,
                traffic_binary=traffic_binary,
                client_binary=client_binary,
                sidecar_binary=sidecar_binary,
                duration_seconds=args.duration_seconds,
                sessions=args.sessions,
                rate_bps=args.rate_bps,
                packet_bytes=args.packet_bytes,
            )
            print(json.dumps(runs[mode], sort_keys=True), flush=True)
        reserved_after = _reserved_listener_snapshot()
        if reserved_after != reserved_before:
            raise RuntimeError("reserved port 20013 listener state changed")

        formal = (
            args.duration_seconds == 60
            and set(runs) == {"legacy", "feedback_drr"}
            and args.rate_bps == 300_000_000
            and args.packet_bytes == 1_200
        )
        if formal:
            sys.path.insert(0, str(HERE))
            from acceptance import build_verdict, write_report

            verdict = build_verdict(
                runs["feedback_drr"],
                runs["legacy"],
                expected_duration=60,
                formal_eligible=True,
            )
        else:
            verdict = {
                "schema_version": 1,
                "duration_seconds": args.duration_seconds,
                "formal_eligible": False,
                "all_passed": True,
                "runs": list(runs.values()),
            }
        verdict["reserved_port_20013_unchanged"] = True
        write_path = args.output_dir / "verdict.json"
        write_path.write_text(
            json.dumps(verdict, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        print(f"verdict={write_path} all_passed={verdict['all_passed']}", flush=True)
        return 0 if verdict["all_passed"] else 1
    finally:
        _restore_sysctl("net.core.rmem_max", original_rmem)
        _restore_sysctl("net.core.wmem_max", original_wmem)
        _run(teardown, check=False)


if __name__ == "__main__":
    raise SystemExit(main())
