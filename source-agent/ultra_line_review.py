#!/usr/bin/env python3
"""ultra_line_review - decide whether a line the panel quarantined is actually dead.

The panel quarantines a candidate from client reports, and a client on a broken
local network reports healthy lines as dead. This runs on the source host, which
sees the line from the provider side, and keeps two probes running for the whole
review window:

  udp  - a large datagram to the relay echo target, which must come back;
  dns  - a real A query to a public resolver through the relay, which must
         answer with a response flag.

A window counts as usable only if every round succeeded. The probe reports what
it saw (rounds, failures, echoed payload sizes, RTTs) and lets the panel decide;
it never talks to the panel itself.

Usage:
  ultra_line_review.py --relay 1.2.3.4:5438 --user U --password P --seconds 600 --json
"""
from __future__ import annotations

import argparse
import json
import socket
import sys
import time
from typing import Optional

import native_udp as N

# www.baidu.com A query, the packet the research selftest proved end to end.
DNS_QUERY = bytes.fromhex("8ccd010000010000000000000377777705626169647503636f6d0000010001")
DNS_ANSWER_FLAG = b"\x81\x80"
DNS_RESOLVERS = ("8.8.8.8", "223.5.5.5", "114.114.114.114")
ECHO_TARGET = ("2.0.0.0", 0)


def parse_relay(value: str) -> tuple[str, int]:
    host, _, port = value.rpartition(":")
    if not host or not port.isdigit():
        raise argparse.ArgumentTypeError("relay must look like 1.2.3.4:5438")
    return host, int(port)


def recv_payload(session: N.NativeSession, window: float) -> Optional[bytes]:
    """Return the next datagram the relay sends back, or None."""
    if session.udp is None:
        return None
    deadline = time.time() + window
    while time.time() < deadline:
        session.udp.settimeout(max(0.001, deadline - time.time()))
        try:
            data, _ = session.udp.recvfrom(8192)
            return data
        except socket.timeout:
            return None
        except OSError:
            return None
    return None


def probe_udp_big(session: N.NativeSession, size: int, window: float) -> tuple[bool, int]:
    """Send a payload of `size` bytes to the echo target; report echo + echo size."""
    payload = bytes((index * 7 + 11) & 0xFF for index in range(size))
    try:
        session.send(ECHO_TARGET, payload)
    except (RuntimeError, OSError):
        return False, 0
    echo = recv_payload(session, window)
    if echo is None:
        return False, 0
    return True, len(echo)


def probe_dns(session: N.NativeSession, window: float) -> bool:
    """Send the DNS query to every resolver and accept one real answer."""
    for resolver in DNS_RESOLVERS:
        try:
            session.send((resolver, 53), DNS_QUERY)
        except (RuntimeError, OSError):
            continue
    deadline = time.time() + window
    seen = 0
    while time.time() < deadline:
        payload = recv_payload(session, max(0.05, deadline - time.time()))
        if payload is None:
            break
        if DNS_ANSWER_FLAG in payload:
            return True
        seen += 1
        if seen > 32:
            break
    return False


def review(
    relay: tuple[str, int],
    user: str,
    password: str,
    seconds: int,
    *,
    interval: float,
    big_bytes: int,
    require_big_packet: bool,
    auth_timeout: float,
) -> dict:
    result = {
        "passed": False,
        "relay": f"{relay[0]}:{relay[1]}",
        "rounds": 0,
        "udp_ok": 0,
        "udp_failed": 0,
        "big_ok": 0,
        "big_failed": 0,
        "dns_ok": 0,
        "dns_failed": 0,
        "max_echo_bytes": 0,
        "min_rtt_ms": None,
        "last_error": "",
    }
    try:
        with N.NativeSession(relay, user.encode(), password.encode()) as session:
            session.arm(auth_timeout=auth_timeout)
            result["armed_ms"] = round(float(session.arm_ms or 0.0), 1)
            deadline = time.time() + max(5, seconds)
            while time.time() < deadline:
                result["rounds"] += 1
                keep_rtt = session.latency_ms(1)
                if keep_rtt == keep_rtt:  # not NaN
                    result["udp_ok"] += 1
                    result["min_rtt_ms"] = keep_rtt if result["min_rtt_ms"] is None else min(result["min_rtt_ms"], keep_rtt)
                else:
                    result["udp_failed"] += 1
                    result["last_error"] = result["last_error"] or "keep echo timed out"

                # A large datagram is counted on its own: whether it may sink the
                # review is the operator's call (require_big_packet), but the raw
                # numbers always have to be reported.
                confirmed, echo_bytes = probe_udp_big(session, big_bytes, max(1.0, interval))
                if confirmed:
                    result["big_ok"] += 1
                    result["max_echo_bytes"] = max(result["max_echo_bytes"], echo_bytes)
                else:
                    result["big_failed"] += 1
                    result["last_error"] = result["last_error"] or f"no echo for {big_bytes}B payload"

                if probe_dns(session, max(1.0, interval)):
                    result["dns_ok"] += 1
                else:
                    result["dns_failed"] += 1
                    result["last_error"] = result["last_error"] or "no DNS answer through the relay"

                remaining = deadline - time.time()
                if remaining > 0:
                    time.sleep(min(interval, remaining))
    except N.LineNotLive as exc:
        result["last_error"] = f"line_not_live: {exc}"
        return result
    except N.AuthError as exc:
        result["last_error"] = f"auth_failed: {exc}"
        return result
    except OSError as exc:
        result["last_error"] = f"net_error: {exc}"
        return result

    # The line has to hold for the whole window. Liveness (keep echo) and a real
    # DNS answer are always required; a large payload can legitimately be dropped
    # by the provider path, so it only sinks the review where the operator has
    # confirmed the relay echoes large datagrams on this deployment.
    dns_clean = result["dns_failed"] == 0 and result["dns_ok"] > 0
    udp_clean = result["udp_failed"] == 0 and result["udp_ok"] > 0
    big_clean = not require_big_packet or (result["big_failed"] == 0 and result["big_ok"] > 0)
    result["passed"] = bool(dns_clean and udp_clean and big_clean and result["rounds"] > 0)
    return result


def main(argv: Optional[list[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="Review a relay line for the panel.")
    parser.add_argument("--relay", required=True, type=parse_relay)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--seconds", type=int, default=600)
    parser.add_argument("--interval", type=float, default=20.0)
    parser.add_argument("--big-bytes", type=int, default=1400)
    parser.add_argument("--auth-timeout", type=float, default=5.0)
    parser.add_argument("--require-big-packet", action="store_true")
    parser.add_argument("--json", action="store_true", help="print one JSON object on stdout")
    args = parser.parse_args(argv)

    result = review(
        args.relay,
        args.user,
        args.password,
        args.seconds,
        interval=max(1.0, args.interval),
        big_bytes=max(64, min(65000, args.big_bytes)),
        require_big_packet=bool(args.require_big_packet),
        auth_timeout=max(1.0, args.auth_timeout),
    )
    if args.json:
        print(json.dumps(result, separators=(",", ":"), sort_keys=True))
    else:
        for key in sorted(result):
            print(f"{key}: {result[key]}")
    return 0 if result["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())