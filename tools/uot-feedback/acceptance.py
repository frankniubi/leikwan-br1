#!/usr/bin/env python3
"""Credential-free, local acceptance calculations for the UoT scheduler.

The evaluator accepts either one normalized run JSON file or a small evidence
manifest that points at separate run/summary JSON files. Synthetic output is
explicitly marked non-formal and exists only to exercise the evidence pipeline.
"""

from __future__ import annotations

import argparse
import hashlib
import ipaddress
import json
import math
import pathlib
import random
import sys
from typing import Any, Iterable


FORMAL_DURATION_SECONDS = 60
FORMAL_OFFERED_RATE_BPS = 300_000_000
RESERVED_PRODUCTION_PORT = 20_013

REQUIRED = {
    "duration_seconds",
    "mode",
    "pair_id",
    "workload",
    "offered_rate_bps",
    "useful_rate_bps",
    "queue_p99_ms",
    "scheduler_drop_ratio",
    "ingress_channel_full",
    "control_queue_drops",
    "udp_send_errors",
    "zero_rate_seconds",
    "jain_fairness",
    "memory_bound_violations",
    "receiver_loss_ratio",
    "loaded_rtt_p99_ms",
    "inner_tcp_retransmissions",
    "direction",
    "datagram_bytes",
    "topology_id",
    "active_legs",
    "feedback_capable_active_legs",
    "legacy_fallback_active_legs",
}

DEFAULT_THRESHOLDS = {
    "min_actual_offered_ratio": 0.99,
    "min_useful_rate_bps": 270_000_000,
    "max_queue_p99_ms": 10.0,
    "max_scheduler_drop_ratio": 0.001,
    "max_deadline_drops": 0,
    "max_udp_send_errors": 0,
    "max_zero_rate_seconds": 0,
    "min_jain_fairness": 0.90,
    "max_memory_bound_violations": 0,
    "max_receiver_loss_ratio": 0.005,
    "max_latency_drift_ms": 10.0,
    "max_pair_latency_regression_ms": 10.0,
}

_MISSING = object()


def percentile(values: Iterable[float], quantile: float) -> float:
    """Return the nearest-rank percentile for a finite, non-empty sample."""
    samples = sorted(float(value) for value in values)
    if not samples:
        raise ValueError("percentile requires at least one sample")
    if not 0.0 <= quantile <= 1.0:
        raise ValueError("percentile quantile must be between 0 and 1")
    if any(not math.isfinite(value) for value in samples):
        raise ValueError("percentile samples must be finite")
    rank = max(1, math.ceil(len(samples) * quantile))
    return samples[rank - 1]


def compute_jain_fairness(values: Iterable[float]) -> float:
    rates = [float(value) for value in values]
    if not rates:
        return 1.0
    if any(not math.isfinite(value) or value < 0 for value in rates):
        raise ValueError("fairness values must be finite and non-negative")
    total = sum(rates)
    total_squares = sum(value * value for value in rates)
    if total_squares == 0:
        return 1.0
    return (total * total) / (len(rates) * total_squares)


def _reject_json_constant(value: str) -> None:
    raise ValueError(f"non-finite value: {value}")


def _read_json(path: pathlib.Path) -> dict[str, Any]:
    value = json.loads(
        path.read_text(encoding="utf-8"),
        parse_constant=_reject_json_constant,
    )
    if not isinstance(value, dict):
        raise ValueError(f"JSON root must be an object: {path}")
    _validate_finite(value)
    return value


def _validate_finite(value: Any, path: str = "root") -> None:
    if isinstance(value, bool) or value is None:
        return
    if isinstance(value, float) and not math.isfinite(value):
        raise ValueError(f"non-finite value: {path}")
    if isinstance(value, dict):
        for key, child in value.items():
            _validate_finite(child, f"{path}.{key}")
    elif isinstance(value, list):
        for index, child in enumerate(value):
            _validate_finite(child, f"{path}[{index}]")


def _deep_merge(target: dict[str, Any], source: dict[str, Any]) -> dict[str, Any]:
    for key, value in source.items():
        if isinstance(value, dict) and isinstance(target.get(key), dict):
            _deep_merge(target[key], value)
        else:
            target[key] = value
    return target


def _object(value: Any) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def _list(value: Any) -> list[Any]:
    return value if isinstance(value, list) else []


def _first(*values: Any, default: Any = _MISSING) -> Any:
    for value in values:
        if value is not None:
            return value
    if default is _MISSING:
        raise ValueError("required measurement is missing")
    return default


def _counter(value: Any) -> int:
    if isinstance(value, dict):
        value = value.get("packets", value.get("count", 0))
    return int(value or 0)


def _sum_counter_map(value: Any, *, exclude: set[str] | None = None) -> int:
    if not isinstance(value, dict):
        return 0
    excluded = exclude or set()
    return sum(_counter(counter) for key, counter in value.items() if key not in excluded)


def _validate_explicit_required_values(run: dict[str, Any]) -> None:
    string_fields = {"mode", "pair_id", "workload", "direction", "topology_id"}
    for key in REQUIRED & run.keys():
        value = run[key]
        if key in string_fields:
            if not isinstance(value, str) or not value:
                raise ValueError(f"metric must be a non-empty string: {key}")
        elif (
            isinstance(value, bool)
            or not isinstance(value, (int, float))
            or not math.isfinite(float(value))
        ):
            raise ValueError(f"metric must be a finite number: {key}")


def _validate_normalized_ranges(run: dict[str, Any]) -> None:
    if run["mode"] not in {"legacy", "feedback_drr"}:
        raise ValueError(f"unsupported scheduler mode: {run['mode']}")
    non_negative = REQUIRED - {"mode", "pair_id", "workload", "direction", "topology_id"}
    for key in non_negative:
        value = run[key]
        if (
            isinstance(value, bool)
            or not isinstance(value, (int, float))
            or not math.isfinite(float(value))
        ):
            raise ValueError(f"metric must be a finite number: {key}")
        if value < 0:
            raise ValueError(f"metric must be non-negative: {key}")
    for key in ("scheduler_drop_ratio", "jain_fairness", "receiver_loss_ratio"):
        if run[key] > 1:
            raise ValueError(f"ratio metric must not exceed one: {key}")
    if not 1 <= int(run["datagram_bytes"]) <= 65_535:
        raise ValueError("datagram_bytes must be between 1 and 65535")


def summarize_run(raw: dict[str, Any], *, expected_duration: int = FORMAL_DURATION_SECONDS) -> dict[str, Any]:
    """Normalize flat metrics or nested sender/receiver/scheduler summaries."""
    _validate_finite(raw)
    combined: dict[str, Any] = {}
    if isinstance(raw.get("run"), dict):
        _deep_merge(combined, raw["run"])
    if isinstance(raw.get("summary"), dict):
        _deep_merge(combined, raw["summary"])
    _deep_merge(combined, {key: value for key, value in raw.items() if key not in {"run", "summary"}})
    _validate_explicit_required_values(combined)

    sender = _object(combined.get("sender"))
    receiver = _object(combined.get("receiver"))
    scheduler = _object(combined.get("scheduler"))
    normalized = dict(combined)
    available_required = {key for key in REQUIRED if key in combined}

    duration = int(
        _first(
            combined.get("duration_seconds"),
            sender.get("duration_seconds"),
            receiver.get("duration_seconds"),
            default=expected_duration,
        )
    )
    normalized["duration_seconds"] = duration
    normalized["mode"] = _first(combined.get("mode"), combined.get("scheduler_mode"), default="")
    if "duration_seconds" in sender or "duration_seconds" in receiver:
        available_required.add("duration_seconds")
    if "scheduler_mode" in combined:
        available_required.add("mode")
    normalized["offered_rate_bps"] = int(
        _first(combined.get("offered_rate_bps"), sender.get("offered_rate_bps"), default=0)
    )
    if "offered_rate_bps" in sender:
        available_required.add("offered_rate_bps")

    useful_bytes = _first(receiver.get("useful_bytes"), combined.get("useful_bytes"), default=None)
    if combined.get("useful_rate_bps") is not None:
        useful_rate_bps = int(combined["useful_rate_bps"])
    elif useful_bytes is not None and duration > 0:
        useful_rate_bps = round(float(useful_bytes) * 8.0 / duration)
    else:
        useful_rate_bps = 0
    normalized["useful_rate_bps"] = useful_rate_bps
    if useful_bytes is not None:
        available_required.add("useful_rate_bps")

    sent_packets = _first(
        sender.get("packets_sent"),
        sender.get("sent_packets"),
        combined.get("packets_sent"),
        default=None,
    )
    received_packets = _first(
        receiver.get("packets_received"),
        receiver.get("received_packets"),
        combined.get("packets_received"),
        default=None,
    )
    if combined.get("receiver_loss_ratio") is not None:
        receiver_loss_ratio = float(combined["receiver_loss_ratio"])
    elif sent_packets is not None and received_packets is not None and float(sent_packets) > 0:
        receiver_loss_ratio = max(0.0, (float(sent_packets) - float(received_packets)) / float(sent_packets))
    else:
        receiver_loss_ratio = 0.0
    normalized["receiver_loss_ratio"] = receiver_loss_ratio
    if sent_packets is not None and received_packets is not None:
        available_required.add("receiver_loss_ratio")

    per_second = _list(
        _first(
            receiver.get("per_second_useful_bytes"),
            receiver.get("per_second_useful_rate_bps"),
            combined.get("per_second_useful_bytes"),
            default=[],
        )
    )
    normalized["complete_seconds"] = int(
        _first(combined.get("complete_seconds"), len(per_second) if per_second else None, default=duration)
    )
    normalized["zero_rate_seconds"] = int(
        _first(
            combined.get("zero_rate_seconds"),
            sum(1 for value in per_second if float(value) <= 0) if per_second else None,
            default=0,
        )
    )
    if per_second:
        available_required.add("zero_rate_seconds")

    session_values = _list(
        _first(
            receiver.get("per_session_rates_bps"),
            receiver.get("per_session_useful_bytes"),
            combined.get("per_session_rates_bps"),
            default=[],
        )
    )
    normalized["jain_fairness"] = float(
        _first(
            combined.get("jain_fairness"),
            scheduler.get("jain_fairness"),
            compute_jain_fairness(session_values) if session_values else None,
            default=1.0,
        )
    )
    if "jain_fairness" in scheduler or session_values:
        available_required.add("jain_fairness")

    queue_samples = _list(
        _first(
            combined.get("queue_delay_ms"),
            scheduler.get("queue_delay_ms"),
            default=[],
        )
    )
    scheduler_queue = _object(scheduler.get("scheduler_queue"))
    normalized["queue_p95_ms"] = float(
        _first(
            combined.get("queue_p95_ms"),
            scheduler_queue.get("p95_ms"),
            percentile(queue_samples, 0.95) if queue_samples else None,
            combined.get("queue_p99_ms"),
            default=0.0,
        )
    )
    normalized["queue_p99_ms"] = float(
        _first(
            combined.get("queue_p99_ms"),
            scheduler_queue.get("p99_ms"),
            percentile(queue_samples, 0.99) if queue_samples else None,
            default=0.0,
        )
    )
    if "p99_ms" in scheduler_queue or queue_samples:
        available_required.add("queue_p99_ms")

    latency_samples = _list(
        _first(
            combined.get("latency_ms"),
            combined.get("loaded_rtt_ms"),
            receiver.get("latency_ms"),
            default=[],
        )
    )
    normalized["loaded_rtt_p95_ms"] = float(
        _first(
            combined.get("loaded_rtt_p95_ms"),
            percentile(latency_samples, 0.95) if latency_samples else None,
            combined.get("loaded_rtt_p99_ms"),
            default=0.0,
        )
    )
    normalized["loaded_rtt_p99_ms"] = float(
        _first(
            combined.get("loaded_rtt_p99_ms"),
            percentile(latency_samples, 0.99) if latency_samples else None,
            default=0.0,
        )
    )
    if latency_samples:
        available_required.add("loaded_rtt_p99_ms")
    if combined.get("latency_drift_ms") is not None:
        latency_drift_ms = float(combined["latency_drift_ms"])
    elif latency_samples:
        window = max(1, len(latency_samples) // 4)
        latency_drift_ms = abs(
            percentile(latency_samples[-window:], 0.95)
            - percentile(latency_samples[:window], 0.95)
        )
    else:
        latency_drift_ms = 0.0
    normalized["latency_drift_ms"] = latency_drift_ms

    offered_packets = _first(
        scheduler.get("offered_packets"),
        scheduler.get("admitted_packets"),
        _object(scheduler.get("lifecycle")).get("offered"),
        default=0,
    )
    offered_packets = _counter(offered_packets)
    dropped_packets = _first(scheduler.get("dropped_packets"), default=None)
    if dropped_packets is None:
        dropped_packets = _sum_counter_map(
            scheduler.get("drops"),
            exclude={"drop_invalid_progress"},
        )
    dropped_packets = _counter(dropped_packets)
    if combined.get("scheduler_drop_ratio") is not None:
        scheduler_drop_ratio = float(combined["scheduler_drop_ratio"])
    elif scheduler.get("scheduler_drop_ratio") is not None:
        scheduler_drop_ratio = float(scheduler["scheduler_drop_ratio"])
    else:
        scheduler_drop_ratio = dropped_packets / offered_packets if offered_packets else 0.0
    normalized["scheduler_drop_ratio"] = scheduler_drop_ratio
    if (
        "scheduler_drop_ratio" in scheduler
        or "dropped_packets" in scheduler
        or "drops" in scheduler
    ):
        available_required.add("scheduler_drop_ratio")

    normalized["ingress_channel_full"] = int(
        _first(
            combined.get("ingress_channel_full"),
            scheduler.get("ingress_channel_full"),
            default=0,
        )
    )
    if "ingress_channel_full" in scheduler:
        available_required.add("ingress_channel_full")
    normalized["control_queue_drops"] = int(
        _first(
            combined.get("control_queue_drops"),
            scheduler.get("control_queue_drops"),
            default=0,
        )
    )
    if "control_queue_drops" in scheduler:
        available_required.add("control_queue_drops")

    drops = _object(scheduler.get("drops"))
    normalized["deadline_drops"] = int(
        _first(
            combined.get("deadline_drops"),
            scheduler.get("deadline_drops"),
            _counter(drops.get("drop_queue_deadline")) if drops else None,
            default=0,
        )
    )
    udp_errors = scheduler.get("udp_errors")
    udp_error_count = _sum_counter_map(udp_errors) if isinstance(udp_errors, dict) else _counter(udp_errors)
    normalized["udp_send_errors"] = int(
        _first(
            combined.get("udp_send_errors"),
            scheduler.get("udp_send_errors"),
            combined.get("udp_egress_errors"),
            udp_error_count if udp_errors is not None else None,
            default=0,
        )
    )
    if (
        "udp_send_errors" in scheduler
        or "udp_errors" in scheduler
        or "udp_egress_errors" in combined
    ):
        available_required.add("udp_send_errors")

    normalized["queue_bytes_high_water"] = int(
        _first(
            combined.get("queue_bytes_high_water"),
            scheduler.get("queue_bytes_high_water"),
            default=0,
        )
    )
    normalized["queue_limit_bytes"] = int(
        _first(
            combined.get("queue_limit_bytes"),
            scheduler.get("queue_limit_bytes"),
            combined.get("global_queue_bytes"),
            default=0,
        )
    )
    computed_bound_violation = int(
        normalized["queue_limit_bytes"] > 0
        and normalized["queue_bytes_high_water"] > normalized["queue_limit_bytes"]
    )
    normalized["memory_bound_violations"] = int(
        _first(
            combined.get("memory_bound_violations"),
            scheduler.get("memory_bound_violations"),
            computed_bound_violation,
            default=0,
        )
    )
    if (
        "memory_bound_violations" in scheduler
        or (
            ("queue_bytes_high_water" in combined or "queue_bytes_high_water" in scheduler)
            and (
                "queue_limit_bytes" in combined
                or "queue_limit_bytes" in scheduler
                or "global_queue_bytes" in combined
            )
        )
    ):
        available_required.add("memory_bound_violations")

    legs = [leg for leg in _list(combined.get("legs")) if isinstance(leg, dict)]
    normalized["active_legs"] = int(
        _first(
            combined.get("active_legs"),
            scheduler.get("active_legs"),
            sum(1 for leg in legs if leg.get("active")) if legs else None,
            default=0,
        )
    )
    if "active_legs" in scheduler or legs:
        available_required.add("active_legs")
    normalized["feedback_capable_active_legs"] = int(
        _first(
            combined.get("feedback_capable_active_legs"),
            scheduler.get("feedback_capable_active_legs"),
            default=0,
        )
    )
    if "feedback_capable_active_legs" in scheduler:
        available_required.add("feedback_capable_active_legs")
    normalized["legacy_fallback_active_legs"] = int(
        _first(
            combined.get("legacy_fallback_active_legs"),
            scheduler.get("legacy_fallback_active_legs"),
            default=0,
        )
    )
    if "legacy_fallback_active_legs" in scheduler:
        available_required.add("legacy_fallback_active_legs")

    missing = sorted(REQUIRED - available_required)
    if missing:
        raise ValueError(f"missing manifest keys: {', '.join(missing)}")
    _validate_finite(normalized)
    _validate_normalized_ranges(normalized)
    return normalized


def load_run(
    path: pathlib.Path,
    *,
    expected_duration: int = FORMAL_DURATION_SECONDS,
    formal: bool = True,
) -> dict[str, Any]:
    """Load a direct run or merge unique evidence paths from a manifest."""
    path = pathlib.Path(path).resolve()
    manifest = _read_json(path)
    evidence = manifest.get("evidence")
    if evidence is None:
        combined = manifest
    else:
        if not isinstance(evidence, dict) or not evidence:
            raise ValueError("evidence must be a non-empty object")
        combined = {key: value for key, value in manifest.items() if key != "evidence"}
        seen: set[pathlib.Path] = set()
        for label, relative in evidence.items():
            if not isinstance(relative, str) or not relative:
                raise ValueError(f"evidence path must be a string: {label}")
            evidence_path = pathlib.Path(relative)
            if not evidence_path.is_absolute():
                evidence_path = path.parent / evidence_path
            evidence_path = evidence_path.resolve()
            if evidence_path in seen:
                raise ValueError(f"duplicate evidence path: {evidence_path}")
            seen.add(evidence_path)
            document = _read_json(evidence_path)
            if label in {"run", "summary"}:
                _deep_merge(combined, document)
            else:
                existing = _object(combined.get(label))
                combined[label] = _deep_merge(existing, document)

    run = summarize_run(combined, expected_duration=expected_duration)
    if formal and run["duration_seconds"] != expected_duration:
        raise ValueError(
            f"formal duration must be {expected_duration} seconds, got {run['duration_seconds']}"
        )
    if formal and run["offered_rate_bps"] != FORMAL_OFFERED_RATE_BPS:
        raise ValueError(
            f"formal offered rate must be {FORMAL_OFFERED_RATE_BPS}, got {run['offered_rate_bps']}"
        )
    return run


def load_manifest(path: pathlib.Path) -> dict[str, Any]:
    """Compatibility helper for callers that need strict direct-manifest loading."""
    return load_run(path, expected_duration=FORMAL_DURATION_SECONDS, formal=True)


def evaluate_absolute(
    run: dict[str, Any],
    *,
    expected_duration: int = FORMAL_DURATION_SECONDS,
    thresholds: dict[str, float] | None = None,
) -> list[str]:
    limits = DEFAULT_THRESHOLDS | (thresholds or {})
    queue_limit = int(run.get("queue_limit_bytes", 0))
    queue_high_water = int(run.get("queue_bytes_high_water", 0))
    checks = [
        ("duration_seconds", run["duration_seconds"] == expected_duration),
        ("offered_rate_bps", run["offered_rate_bps"] == FORMAL_OFFERED_RATE_BPS),
        (
            "actual_offered_rate_bps",
            run.get("actual_offered_rate_bps", run["offered_rate_bps"])
            >= run["offered_rate_bps"] * limits["min_actual_offered_ratio"],
        ),
        ("useful_rate_bps", run["useful_rate_bps"] >= limits["min_useful_rate_bps"]),
        ("queue_p99_ms", run["queue_p99_ms"] <= limits["max_queue_p99_ms"]),
        (
            "scheduler_drop_ratio",
            run["scheduler_drop_ratio"] <= limits["max_scheduler_drop_ratio"],
        ),
        ("ingress_channel_full", run["ingress_channel_full"] == 0),
        ("control_queue_drops", run["control_queue_drops"] == 0),
        ("deadline_drops", run.get("deadline_drops", 0) <= limits["max_deadline_drops"]),
        ("udp_send_errors", run["udp_send_errors"] <= limits["max_udp_send_errors"]),
        ("zero_rate_seconds", run["zero_rate_seconds"] <= limits["max_zero_rate_seconds"]),
        ("complete_seconds", run.get("complete_seconds", expected_duration) == expected_duration),
        ("jain_fairness", run["jain_fairness"] >= limits["min_jain_fairness"]),
        (
            "memory_bound_violations",
            run["memory_bound_violations"] <= limits["max_memory_bound_violations"],
        ),
        (
            "receiver_loss_ratio",
            run["receiver_loss_ratio"] <= limits["max_receiver_loss_ratio"],
        ),
        ("latency_drift_ms", run.get("latency_drift_ms", 0.0) <= limits["max_latency_drift_ms"]),
        (
            "feedback_capable_active_legs",
            run["mode"] != "feedback_drr"
            or run["feedback_capable_active_legs"] == run["active_legs"],
        ),
        (
            "legacy_fallback_active_legs",
            run["mode"] != "feedback_drr" or run["legacy_fallback_active_legs"] == 0,
        ),
        ("queue_bound", queue_limit == 0 or queue_high_water <= queue_limit),
    ]
    return [name for name, passed in checks if not passed]


def evaluate_pair(
    feedback: dict[str, Any],
    legacy: dict[str, Any],
    *,
    thresholds: dict[str, float] | None = None,
) -> list[str]:
    limits = DEFAULT_THRESHOLDS | (thresholds or {})
    identity = (
        "pair_id",
        "workload",
        "duration_seconds",
        "offered_rate_bps",
        "direction",
        "datagram_bytes",
        "topology_id",
    )
    if any(feedback[key] != legacy[key] for key in identity):
        raise ValueError("paired runs do not share workload identity")
    failures: list[str] = []
    if (
        feedback["receiver_loss_ratio"] > legacy["receiver_loss_ratio"]
        or feedback["receiver_loss_ratio"] > limits["max_receiver_loss_ratio"]
    ):
        failures.append("receiver_loss_ratio")
    for key in ("loaded_rtt_p95_ms", "loaded_rtt_p99_ms"):
        if feedback.get(key, 0.0) - legacy.get(key, 0.0) > limits["max_pair_latency_regression_ms"]:
            failures.append(key)
    if feedback["inner_tcp_retransmissions"] > legacy["inner_tcp_retransmissions"]:
        failures.append("inner_tcp_retransmissions")
    return failures


def sha256_file(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with pathlib.Path(path).open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_report(report: dict[str, Any] | list[dict[str, Any]], output: pathlib.Path) -> None:
    if isinstance(report, list):
        report = {
            "runs": report,
            "all_passed": all(not run.get("failures") for run in report),
        }
    output = pathlib.Path(output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def build_verdict(
    feedback: dict[str, Any],
    legacy: dict[str, Any] | None,
    *,
    expected_duration: int,
    formal_eligible: bool,
    seed: int | None = None,
) -> dict[str, Any]:
    feedback_failures = evaluate_absolute(feedback, expected_duration=expected_duration)
    runs = [{**feedback, "failures": feedback_failures}]
    pair_failures: list[str] = []
    if legacy is not None:
        legacy_failures = evaluate_absolute(legacy, expected_duration=expected_duration)
        runs.append({**legacy, "failures": legacy_failures})
        pair_failures = evaluate_pair(feedback, legacy)
    report: dict[str, Any] = {
        "schema_version": 1,
        "duration_seconds": expected_duration,
        "formal_eligible": formal_eligible,
        "all_passed": all(not run["failures"] for run in runs) and not pair_failures,
        "pair_failures": pair_failures,
        "runs": runs,
    }
    if seed is not None:
        report["seed"] = seed
    return report


def validate_topology(topology: dict[str, Any]) -> None:
    marker = topology.get("marker")
    if not isinstance(marker, str) or not marker.startswith("uot-qa-"):
        raise ValueError("topology marker must start with uot-qa-")
    if topology.get("hop_order") != ["client", "slave", "sidecar"]:
        raise ValueError("topology hop_order must be client -> slave -> sidecar")
    namespaces = topology.get("namespaces")
    if not isinstance(namespaces, dict) or set(namespaces) != {"client", "slave", "sidecar"}:
        raise ValueError("topology must define client, slave, and sidecar namespaces")
    if any(not isinstance(name, str) or marker not in name for name in namespaces.values()):
        raise ValueError("namespace names must contain the topology marker")
    reserved_ports = topology.get("reserved_ports", [])
    if RESERVED_PRODUCTION_PORT not in reserved_ports:
        raise ValueError("reserved port 20013 must be declared")

    legs = topology.get("legs")
    if not isinstance(legs, list) or len(legs) != 5:
        raise ValueError("local topology must define exactly five legs")
    ingress_ports: set[int] = set()
    for leg in legs:
        if not isinstance(leg, dict):
            raise ValueError("each leg must be an object")
        port = int(leg.get("ingress_port", 0))
        if not 7000 <= port <= 9000 or port == RESERVED_PRODUCTION_PORT:
            raise ValueError(f"leg ingress port is outside 7000-9000: {port}")
        if port in ingress_ports:
            raise ValueError(f"duplicate leg ingress port: {port}")
        ingress_ports.add(port)
        for key in ("client_addr", "slave_addr", "slave_sidecar_addr", "sidecar_addr"):
            interface = ipaddress.ip_interface(leg[key])
            if not interface.ip.is_private:
                raise ValueError(f"topology address must be private: {leg[key]}")

    workloads = _object(topology.get("workloads"))
    if int(workloads.get("duration_seconds", 0)) != FORMAL_DURATION_SECONDS:
        raise ValueError("local topology duration must be 60 seconds")
    if int(workloads.get("sessions64_rate_bps", 0)) > FORMAL_OFFERED_RATE_BPS:
        raise ValueError("sessions64 rate exceeds the 300 Mbps bound")
    if int(workloads.get("tunnel32_rate_bps", 0)) > FORMAL_OFFERED_RATE_BPS:
        raise ValueError("tunnel32 rate exceeds the 300 Mbps bound")
    if int(workloads.get("combined_each_rate_bps", 0)) * 2 > FORMAL_OFFERED_RATE_BPS:
        raise ValueError("combined rate exceeds the 300 Mbps bound")

    forbidden_keys = {"password", "token", "secret", "ssh_host", "private_key", "credential"}

    def walk(value: Any) -> None:
        if isinstance(value, dict):
            for key, child in value.items():
                if key.lower() in forbidden_keys:
                    raise ValueError(f"credential field is forbidden in local topology: {key}")
                walk(child)
        elif isinstance(value, list):
            for child in value:
                walk(child)

    walk(topology)


def _synthetic_runs(topology: dict[str, Any], duration: int, seed: int) -> tuple[dict[str, Any], dict[str, Any]]:
    validate_topology(topology)
    if duration != FORMAL_DURATION_SECONDS:
        raise ValueError("synthetic formal-shape runs must use 60 seconds")
    rng = random.Random(seed)
    jitter = rng.randint(0, 4) / 10.0
    common = {
        "duration_seconds": duration,
        "pair_id": "synthetic-sessions64-a",
        "workload": "sessions64",
        "offered_rate_bps": FORMAL_OFFERED_RATE_BPS,
        "actual_offered_rate_bps": FORMAL_OFFERED_RATE_BPS,
        "direction": "client_to_sidecar",
        "datagram_bytes": int(topology["workloads"]["datagram_bytes"]),
        "topology_id": topology.get("topology_id", "local-netns-five-leg"),
        "active_legs": len(topology["legs"]),
        "queue_p95_ms": 7.0 + jitter,
        "deadline_drops": 0,
        "ingress_channel_full": 0,
        "control_queue_drops": 0,
        "udp_send_errors": 0,
        "zero_rate_seconds": 0,
        "complete_seconds": duration,
        "jain_fairness": 0.96,
        "memory_bound_violations": 0,
        "latency_drift_ms": 2.0 + jitter,
        "queue_bytes_high_water": 500_000,
        "queue_limit_bytes": 1_000_000,
    }
    legacy = {
        **common,
        "mode": "legacy",
        "useful_rate_bps": 271_000_000,
        "queue_p99_ms": 9.5,
        "scheduler_drop_ratio": 0.0008,
        "receiver_loss_ratio": 0.004,
        "loaded_rtt_p95_ms": 24.0,
        "loaded_rtt_p99_ms": 28.0,
        "inner_tcp_retransmissions": 10,
        "feedback_capable_active_legs": 0,
        "legacy_fallback_active_legs": len(topology["legs"]),
    }
    feedback = {
        **common,
        "mode": "feedback_drr",
        "useful_rate_bps": 273_000_000,
        "queue_p99_ms": 8.8 + jitter,
        "scheduler_drop_ratio": 0.0007,
        "receiver_loss_ratio": 0.003,
        "loaded_rtt_p95_ms": 27.0 + jitter,
        "loaded_rtt_p99_ms": 33.0 + jitter,
        "inner_tcp_retransmissions": 8,
        "feedback_capable_active_legs": len(topology["legs"]),
        "legacy_fallback_active_legs": 0,
    }
    return feedback, legacy


def _write_hash_manifest(root: pathlib.Path, output: pathlib.Path) -> None:
    root = root.resolve()
    output = output.resolve()
    lines = []
    for path in sorted(candidate for candidate in root.rglob("*") if candidate.is_file()):
        if path.resolve() == output:
            continue
        relative = path.relative_to(root).as_posix()
        lines.append(f"{sha256_file(path)}  {relative}")
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text("\n".join(lines) + ("\n" if lines else ""), encoding="utf-8")


def _matrix_plan(topology_path: pathlib.Path, output_dir: pathlib.Path, duration: int, seed: int) -> dict[str, Any]:
    topology = _read_json(topology_path)
    validate_topology(topology)
    rng = random.Random(seed)
    rows = [
        {"case": workload, "mode": mode, "impairment": impairment}
        for workload in ("sessions64", "tunnel32", "combined")
        for impairment in ("none", "delay10", "loss05")
        for mode in ("legacy", "feedback_drr")
    ]
    rng.shuffle(rows)
    script = pathlib.Path(__file__).with_name("netns.sh")
    for row in rows:
        row["command"] = [
            str(script),
            "run",
            "--case",
            row["case"],
            "--mode",
            row["mode"],
            "--impairment",
            row["impairment"],
            "--artifact-dir",
            str(output_dir),
            "--duration-seconds",
            str(duration),
        ]
    return {
        "schema_version": 1,
        "executed": False,
        "duration_seconds": duration,
        "seed": seed,
        "topology": str(topology_path.resolve()),
        "runs": rows,
    }


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Evaluate local UoT feedback scheduler evidence")
    subparsers = parser.add_subparsers(dest="command", required=True)

    evaluate = subparsers.add_parser("evaluate", help="evaluate run/summary JSON evidence")
    evaluate.add_argument("--manifest", type=pathlib.Path, required=True)
    evaluate.add_argument("--legacy", type=pathlib.Path)
    evaluate.add_argument("--output-dir", type=pathlib.Path, default=pathlib.Path("."))
    evaluate.add_argument("--output", type=pathlib.Path, help="compatibility alias for verdict path")
    evaluate.add_argument("--duration-seconds", type=int, default=FORMAL_DURATION_SECONDS)

    synthetic = subparsers.add_parser("synthetic", help="write deterministic non-formal sample evidence")
    synthetic.add_argument("--manifest", type=pathlib.Path, required=True, help="local topology manifest")
    synthetic.add_argument("--output-dir", type=pathlib.Path, required=True)
    synthetic.add_argument("--duration-seconds", type=int, default=FORMAL_DURATION_SECONDS)
    synthetic.add_argument("--seed", type=int, default=20_260_808)
    synthetic.add_argument("--dry-run", action="store_true")

    hash_parser = subparsers.add_parser("hash", help="write sorted SHA-256 evidence list")
    hash_parser.add_argument("--root", type=pathlib.Path, required=True)
    hash_parser.add_argument("--output", type=pathlib.Path, required=True)

    matrix = subparsers.add_parser("run-matrix", help="build a bounded local-netns run plan")
    matrix.add_argument("--manifest", "--topology", dest="manifest", type=pathlib.Path, required=True)
    matrix.add_argument("--output-dir", "--artifact-dir", dest="output_dir", type=pathlib.Path, required=True)
    matrix.add_argument("--duration-seconds", type=int, default=FORMAL_DURATION_SECONDS)
    matrix.add_argument("--seed", type=int, default=20_260_808)
    matrix.add_argument("--dry-run", action="store_true")
    matrix.add_argument("--synthetic", action="store_true")
    return parser


def main(argv: list[str] | None = None) -> int:
    arguments = list(sys.argv[1:] if argv is None else argv)
    commands = {"evaluate", "synthetic", "hash", "run-matrix"}
    if arguments and arguments[0] not in commands and arguments[0] not in {"-h", "--help"}:
        command = "synthetic" if "--synthetic" in arguments else "evaluate"
        arguments = [arg for arg in arguments if arg != "--synthetic"]
        arguments.insert(0, command)
    args = _parser().parse_args(arguments)
    try:
        if args.command == "evaluate":
            feedback = load_run(
                args.manifest,
                expected_duration=args.duration_seconds,
                formal=True,
            )
            legacy = (
                load_run(args.legacy, expected_duration=args.duration_seconds, formal=True)
                if args.legacy
                else None
            )
            report = build_verdict(
                feedback,
                legacy,
                expected_duration=args.duration_seconds,
                formal_eligible=legacy is not None,
            )
            output = args.output or (args.output_dir / "verdict.json")
            write_report(report, output)
            return 0 if report["all_passed"] else 1

        if args.command == "synthetic":
            topology = _read_json(args.manifest)
            feedback, legacy = _synthetic_runs(topology, args.duration_seconds, args.seed)
            plan = {
                "duration_seconds": args.duration_seconds,
                "seed": args.seed,
                "formal_eligible": False,
                "output_dir": str(args.output_dir.resolve()),
            }
            if args.dry_run:
                print(json.dumps(plan, indent=2, sort_keys=True))
                return 0
            args.output_dir.mkdir(parents=True, exist_ok=True)
            write_report(feedback, args.output_dir / "run-feedback.json")
            write_report(legacy, args.output_dir / "run-legacy.json")
            report = build_verdict(
                feedback,
                legacy,
                expected_duration=args.duration_seconds,
                formal_eligible=False,
                seed=args.seed,
            )
            write_report(report, args.output_dir / "verdict.json")
            return 0 if report["all_passed"] else 1

        if args.command == "hash":
            _write_hash_manifest(args.root, args.output)
            return 0

        if args.command == "run-matrix":
            if args.duration_seconds != FORMAL_DURATION_SECONDS:
                raise ValueError("run-matrix duration must be 60 seconds")
            plan = _matrix_plan(
                args.manifest,
                args.output_dir,
                args.duration_seconds,
                args.seed,
            )
            if args.dry_run:
                print(json.dumps(plan, indent=2, sort_keys=True))
                return 0
            args.output_dir.mkdir(parents=True, exist_ok=True)
            write_report(plan, args.output_dir / "matrix-plan.json")
            if args.synthetic:
                topology = _read_json(args.manifest)
                feedback, legacy = _synthetic_runs(topology, args.duration_seconds, args.seed)
                report = build_verdict(
                    feedback,
                    legacy,
                    expected_duration=args.duration_seconds,
                    formal_eligible=False,
                    seed=args.seed,
                )
                write_report(report, args.output_dir / "verdict.json")
            return 0
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"error: {error}", file=sys.stderr)
        return 2
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
