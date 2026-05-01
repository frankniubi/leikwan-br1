#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import pathlib
import shutil
import subprocess
import time
from typing import Any


def load_json(path: pathlib.Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(payload, dict):
        raise RuntimeError(f"config must be an object: {path}")
    return payload


def run_command(parts: list[str], check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(parts, text=True, capture_output=True, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(
            f"command failed: {' '.join(parts)}\nstdout={result.stdout}\nstderr={result.stderr}"
        )
    return result


def truthy(value: object) -> bool:
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def netns_config(config: dict[str, Any]) -> dict[str, Any]:
    netns = config.get("netns", {})
    return netns if isinstance(netns, dict) else {}


def require_netns(config: dict[str, Any]) -> dict[str, Any]:
    payload = netns_config(config)
    if not truthy(payload.get("enabled", False)):
        raise RuntimeError("netns mode is not enabled for this forward")
    required = [
        "name",
        "host_veth",
        "namespace_veth",
        "subnet",
        "host_address",
        "namespace_address",
    ]
    for key in required:
        if str(payload.get(key, "") or "").strip() == "":
            raise RuntimeError(f"netns config missing {key}")
    return payload


def netns_exists(name: str) -> bool:
    result = run_command(["ip", "netns", "list"], check=False)
    for line in result.stdout.splitlines():
        if line.strip().split()[:1] == [name]:
            return True
    return False


def ensure_veth_pair(name: str, host_veth: str, namespace_veth: str) -> None:
    host_exists = run_command(["ip", "link", "show", host_veth], check=False).returncode == 0
    namespace_exists = (
        run_command(["ip", "netns", "exec", name, "ip", "link", "show", namespace_veth], check=False).returncode == 0
    )
    if host_exists and namespace_exists:
        return
    if host_exists:
        run_command(["ip", "link", "delete", host_veth], check=False)
    run_command(["ip", "link", "add", host_veth, "type", "veth", "peer", "name", namespace_veth])
    run_command(["ip", "link", "set", namespace_veth, "netns", name])


def ensure_iptables_available() -> str:
    for candidate in ("iptables", "/usr/sbin/iptables"):
        resolved = shutil.which(candidate) or (candidate if pathlib.Path(candidate).exists() else "")
        if resolved != "":
            return resolved
    raise RuntimeError("iptables is required for per-forward netns mode")


def ensure_rule(iptables_bin: str, args: list[str]) -> None:
    if run_command([iptables_bin, *args], check=False).returncode != 0:
        add_args = args[:]
        try:
            index = add_args.index("-C")
            add_args[index] = "-A"
        except ValueError:
            add_args.insert(0, "-A")
        run_command([iptables_bin, *add_args])


def delete_rule(iptables_bin: str, args: list[str]) -> None:
    delete_args = args[:]
    try:
        index = delete_args.index("-C")
        delete_args[index] = "-D"
    except ValueError:
        delete_args.insert(0, "-D")
    while run_command([iptables_bin, *args], check=False).returncode == 0:
        run_command([iptables_bin, *delete_args], check=False)


def ensure_nat_rules(payload: dict[str, Any]) -> None:
    iptables_bin = ensure_iptables_available()
    subnet = str(payload["subnet"])
    host_veth = str(payload["host_veth"])
    ensure_rule(
        iptables_bin,
        ["-C", "FORWARD", "-i", host_veth, "-s", subnet, "-j", "ACCEPT"],
    )
    ensure_rule(
        iptables_bin,
        ["-C", "FORWARD", "-o", host_veth, "-d", subnet, "-m", "conntrack", "--ctstate", "RELATED,ESTABLISHED", "-j", "ACCEPT"],
    )
    ensure_rule(
        iptables_bin,
        ["-t", "nat", "-C", "POSTROUTING", "-s", subnet, "!", "-d", subnet, "-j", "MASQUERADE"],
    )


def delete_nat_rules(payload: dict[str, Any]) -> None:
    try:
        iptables_bin = ensure_iptables_available()
    except RuntimeError:
        return
    subnet = str(payload["subnet"])
    host_veth = str(payload["host_veth"])
    delete_rule(
        iptables_bin,
        ["-C", "FORWARD", "-i", host_veth, "-s", subnet, "-j", "ACCEPT"],
    )
    delete_rule(
        iptables_bin,
        ["-C", "FORWARD", "-o", host_veth, "-d", subnet, "-m", "conntrack", "--ctstate", "RELATED,ESTABLISHED", "-j", "ACCEPT"],
    )
    delete_rule(
        iptables_bin,
        ["-t", "nat", "-C", "POSTROUTING", "-s", subnet, "!", "-d", subnet, "-j", "MASQUERADE"],
    )


def setup(config: dict[str, Any]) -> None:
    payload = require_netns(config)
    name = str(payload["name"])
    host_veth = str(payload["host_veth"])
    namespace_veth = str(payload["namespace_veth"])
    host_address = str(payload["host_address"])
    namespace_address = str(payload["namespace_address"])

    if not netns_exists(name):
        run_command(["ip", "netns", "add", name])

    ensure_veth_pair(name, host_veth, namespace_veth)

    run_command(["ip", "addr", "replace", f"{host_address}/30", "dev", host_veth])
    run_command(["ip", "link", "set", host_veth, "up"])
    run_command(["ip", "netns", "exec", name, "ip", "link", "set", "lo", "up"])
    run_command(["ip", "netns", "exec", name, "ip", "addr", "replace", f"{namespace_address}/30", "dev", namespace_veth])
    run_command(["ip", "netns", "exec", name, "ip", "link", "set", namespace_veth, "up"])
    run_command(["ip", "netns", "exec", name, "ip", "route", "replace", "default", "via", host_address, "dev", namespace_veth])
    run_command(["sysctl", "-w", "net.ipv4.ip_forward=1"], check=False)
    ensure_nat_rules(payload)


def cleanup(config: dict[str, Any]) -> None:
    payload = require_netns(config)
    name = str(payload["name"])
    host_veth = str(payload["host_veth"])

    delete_nat_rules(payload)

    pids_result = run_command(["ip", "netns", "pids", name], check=False)
    pids = [pid.strip() for pid in pids_result.stdout.splitlines() if pid.strip() != ""]
    for pid in pids:
        run_command(["kill", "-TERM", pid], check=False)
    if pids:
        time.sleep(0.2)
    pids_result = run_command(["ip", "netns", "pids", name], check=False)
    pids = [pid.strip() for pid in pids_result.stdout.splitlines() if pid.strip() != ""]
    for pid in pids:
        run_command(["kill", "-KILL", pid], check=False)

    run_command(["ip", "link", "delete", host_veth], check=False)
    run_command(["ip", "netns", "delete", name], check=False)


def main() -> int:
    parser = argparse.ArgumentParser(description="Leikwan per-forward network namespace helper")
    parser.add_argument("command", choices=["setup", "cleanup"])
    parser.add_argument("--config", required=True)
    args = parser.parse_args()

    if shutil.which("ip") is None:
        raise RuntimeError("iproute2 is required for per-forward netns mode")

    config = load_json(pathlib.Path(args.config))
    if args.command == "setup":
        setup(config)
    else:
        cleanup(config)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
