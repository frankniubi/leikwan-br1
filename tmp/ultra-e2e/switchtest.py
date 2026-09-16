#!/usr/bin/env python3
"""Per-second timeline of one UDP + one TCP flow through the local forward.

Purpose: measure how long a forwarding path stays broken after the relay it is
using disappears, i.e. the real failover time.
"""
from __future__ import annotations

import argparse
import json
import os
import socket
import struct
import sys
import threading
import time

MAGIC = b"SWCH"
HDR = struct.Struct("!Qd")
HDR_LEN = len(MAGIC) + HDR.size


def enc(host, port):
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def payload(seq):
    return MAGIC + HDR.pack(seq, time.monotonic())


def parse(packet):
    idx = packet.find(MAGIC)
    if idx < 0 or len(packet) - idx < HDR_LEN:
        return None
    return HDR.unpack(packet[idx + len(MAGIC):idx + HDR_LEN])


class Timeline(threading.Thread):
    def __init__(self, local, peer_udp, peer_tcp, pps, duration, out_path):
        super().__init__(daemon=True)
        self.local, self.peer_udp, self.peer_tcp = local, peer_udp, peer_tcp
        self.pps, self.duration, self.out_path = pps, duration, out_path
        self.stop = threading.Event()
        self.lock = threading.Lock()
        self.sent = self.recv = 0
        self.rtts = []
        self.tcp_ok = self.tcp_breaks = 0
        self.events = []

    def udp_loop(self):
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.settimeout(0.2)
        interval = 1.0 / self.pps
        start = time.monotonic()
        next_at = start
        seq = 0
        pending = {}
        while not self.stop.is_set() and time.monotonic() - start < self.duration:
            now = time.monotonic()
            if now < next_at:
                time.sleep(min(next_at - now, 0.02))
                continue
            next_at += interval
            seq += 1
            with self.lock:
                self.sent += 1
                pending[seq] = time.monotonic()
            sock.sendto(b"\x00\x00\x00" + enc(*self.peer_udp) + payload(seq), self.local)
            try:
                packet, _ = sock.recvfrom(4096)
            except socket.timeout:
                continue
            parsed = parse(packet)
            if parsed is None:
                continue
            got_seq, sent_at = parsed
            rtt = (time.monotonic() - sent_at) * 1000.0
            with self.lock:
                self.recv += 1
                self.rtts.append(rtt)
                pending.pop(got_seq, None)
        sock.close()

    def tcp_loop(self):
        start = time.monotonic()
        while not self.stop.is_set() and time.monotonic() - start < self.duration:
            try:
                conn = socket.create_connection(self.local, timeout=4.0)
                conn.settimeout(4.0)
                conn.sendall(enc(*self.peer_tcp))
                while not self.stop.is_set() and time.monotonic() - start < self.duration:
                    conn.sendall(payload(1))
                    data = b""
                    while len(data) < HDR_LEN:
                        chunk = conn.recv(HDR_LEN - len(data))
                        if not chunk:
                            raise ConnectionResetError("eof")
                        data += chunk
                    with self.lock:
                        self.tcp_ok += 1
                    time.sleep(1.0)
            except Exception as exc:
                with self.lock:
                    self.tcp_breaks += 1
                    self.events.append({"at": round(time.time() - start, 1), "kind": "tcp_break", "detail": type(exc).__name__})
                self.stop.wait(0.2)

    def run(self):
        threading.Thread(target=self.udp_loop, daemon=True).start()
        threading.Thread(target=self.tcp_loop, daemon=True).start()
        start = time.monotonic()
        with open(self.out_path, "w") as handle:
            while not self.stop.is_set() and time.monotonic() - start < self.duration + 2:
                time.sleep(1.0)
                elapsed = round(time.monotonic() - start, 2)
                with self.lock:
                    rtts = sorted(self.rtts)
                    self.rtts = []
                    rec = {
                        "t": elapsed,
                        "sent": self.sent,
                        "recv": self.recv,
                        "lost": self.sent - self.recv,
                        "rtt_p50": round(rtts[len(rtts) // 2], 1) if rtts else None,
                        "tcp_ok": self.tcp_ok,
                        "tcp_breaks": self.tcp_breaks,
                    }
                handle.write(json.dumps(rec) + "\n")
                handle.flush()
                print(json.dumps(rec), flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--duration", type=int, default=300)
    ap.add_argument("--pps", type=int, default=30)
    ap.add_argument("--local-port", type=int, default=10001)
    ap.add_argument("--peer", default="185.220.238.143")
    ap.add_argument("--udp-port", type=int, default=60000)
    ap.add_argument("--tcp-port", type=int, default=60001)
    ap.add_argument("--out", default="/root/switchtest/timeline.jsonl")
    args = ap.parse_args()
    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    runner = Timeline(("127.0.0.1", args.local_port), (args.peer, args.udp_port), (args.peer, args.tcp_port),
                      args.pps, args.duration, args.out)
    runner.start()
    runner.join(timeout=args.duration + 10)
    runner.stop.set()
    time.sleep(0.5)
    print(json.dumps({"done": True, "sent": runner.sent, "recv": runner.recv, "tcp_ok": runner.tcp_ok,
                      "tcp_breaks": runner.tcp_breaks, "events": runner.events[-20:]}), flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
