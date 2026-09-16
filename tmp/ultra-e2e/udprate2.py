#!/usr/bin/env python3
"""UDP loss and latency through the tunnel at a requested bitrate."""
from __future__ import annotations

import argparse
import json
import socket
import struct
import threading
import time

MAGIC = b"RATE"
HDR = struct.Struct("!Qd")
HDR_LEN = len(MAGIC) + HDR.size


def encode_address(host: str, port: int) -> bytes:
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def payload(seq: int, size: int) -> bytes:
    head = MAGIC + HDR.pack(seq, time.monotonic())
    return head + b"\x00" * max(0, size - len(head))


def parse(packet: bytes):
    idx = packet.find(MAGIC)
    if idx < 0 or len(packet) - idx < HDR_LEN:
        return None
    return HDR.unpack(packet[idx + len(MAGIC):idx + HDR_LEN])


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--mbps", type=float, default=10.0)
    ap.add_argument("--size", type=int, default=1200)
    ap.add_argument("--seconds", type=float, default=10.0)
    ap.add_argument("--local-port", type=int, default=10001)
    ap.add_argument("--target-host", default="185.220.238.143")
    ap.add_argument("--target-port", type=int, default=60000)
    args = ap.parse_args()

    local = ("127.0.0.1", args.local_port)
    target = (args.target_host, args.target_port)
    frame = 3 + 7 + args.size
    pps = max(1, int((args.mbps * 1_000_000 / 8) / frame))
    interval = 1.0 / pps

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 8 * 1024 * 1024)
    sock.settimeout(0.05)

    state = {"sent": 0, "recv": 0}
    pending = {}
    rtts = []
    stop = threading.Event()

    def sender():
        start = time.monotonic()
        next_at = start
        seq = 0
        while not stop.is_set() and time.monotonic() - start < args.seconds:
            now = time.monotonic()
            if now < next_at:
                time.sleep(min(next_at - now, 0.001))
                continue
            next_at += interval
            seq += 1
            pending[seq] = now
            sock.sendto(b"\x00\x00\x00" + encode_address(*target) + payload(seq, args.size), local)
            state["sent"] += 1

    start = time.monotonic()
    worker = threading.Thread(target=sender, daemon=True)
    worker.start()
    while time.monotonic() - start < args.seconds:
        try:
            packet, _ = sock.recvfrom(4096)
        except socket.timeout:
            continue
        parsed = parse(packet)
        if parsed is None:
            continue
        got, sent_at = parsed
        if pending.pop(got, None) is None:
            continue
        state["recv"] += 1
        rtts.append((time.monotonic() - sent_at) * 1000.0)
    stop.set()
    worker.join(timeout=2)
    elapsed = time.monotonic() - start
    sock.close()
    rtts.sort()
    sent, recv = state["sent"], state["recv"]
    result = {
        "target_mbps": args.mbps,
        "achieved_mbps": round(sent * frame * 8 / elapsed / 1_000_000, 2),
        "size": args.size,
        "pps": pps,
        "sent": sent,
        "recv": recv,
        "loss_pct": round(100.0 * (sent - recv) / max(1, sent), 3),
        "rtt_p50": round(rtts[len(rtts) // 2], 1) if rtts else None,
        "rtt_p95": round(rtts[int(len(rtts) * 0.95)], 1) if rtts else None,
        "rtt_max": round(rtts[-1], 1) if rtts else None,
    }
    print(json.dumps(result), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
