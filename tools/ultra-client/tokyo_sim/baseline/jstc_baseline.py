#!/usr/bin/env python3
"""jstc -> Tokyo raw-path baseline for the game-sim experiment.

UDP: 60 pps for 60 s to 185.220.238.143:60000, mixed sizes 64/512/1200,
     seq+timestamp payload (echoed verbatim by the Tokyo echo server).
TCP: 20 sequential echo connections to 185.220.238.143:60001 (512 B payload).

Prints a JSON result block on stdout.
"""
import json
import socket
import struct
import sys
import threading
import time

HOST = "185.220.238.143"
UDP_PORT = 60000
TCP_PORT = 60001
PPS = 60
WINDOW_S = 60.0
SIZES = (64, 512, 1200)
TCP_COUNT = 20
TCP_PAYLOAD_SIZE = 512


def percentile(sorted_values, pct):
    if not sorted_values:
        return None
    pos = int(round(pct / 100.0 * (len(sorted_values) - 1)))
    if pos < 0:
        pos = 0
    if pos >= len(sorted_values):
        pos = len(sorted_values) - 1
    return sorted_values[pos]


def summarize(values_ms):
    if not values_ms:
        return None
    s = sorted(values_ms)
    n = len(s)
    mean = sum(s) / n
    jitter = sum(abs(values_ms[i] - values_ms[i - 1]) for i in range(1, n)) / (n - 1) if n > 1 else 0.0
    return {
        "n": n, "min": s[0], "mean": mean,
        "p50": percentile(s, 50), "p90": percentile(s, 90),
        "p99": percentile(s, 99), "p999": percentile(s, 99.9),
        "max": s[-1], "jitter_ms": jitter,
    }


def build_payload(size, seq, sent_ns):
    payload = bytearray(size)
    payload[0:4] = b"JSTC"
    struct.pack_into(">Q", payload, 4, seq)
    struct.pack_into(">Q", payload, 12, sent_ns)
    for i in range(20, size):
        payload[i] = (31 * i + 7) & 0xFF
    return bytes(payload)


def udp_baseline():
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, 4 * 1024 * 1024)
    sock.settimeout(0.05)
    dst = (HOST, UDP_PORT)
    rtts = {}
    recv_by_size = {}
    sent = [0]
    stopped = threading.Event()

    def reader():
        while not stopped.is_set():
            try:
                data, _ = sock.recvfrom(65535)
            except socket.timeout:
                continue
            except OSError:
                return
            if len(data) < 20 or data[0:4] != b"JSTC":
                continue
            rseq = struct.unpack(">Q", data[4:12])[0]
            sent_ns = struct.unpack(">Q", data[12:20])[0]
            if rseq not in rtts:
                rtts[rseq] = time.time_ns() - sent_ns
                recv_by_size[SIZES[rseq % len(SIZES)]] = recv_by_size.get(SIZES[rseq % len(SIZES)], 0) + 1

    thread = threading.Thread(target=reader, daemon=True)
    thread.start()
    interval = 1.0 / PPS
    start = time.time()
    seq = 0
    next_t = start
    while time.time() - start < WINDOW_S:
        size = SIZES[seq % len(SIZES)]
        payload = build_payload(size, seq, time.time_ns())
        try:
            sock.sendto(payload, dst)
        except OSError as exc:
            print("udp send error: %s" % exc, file=sys.stderr)
            break
        sent[0] += 1
        seq += 1
        next_t += interval
        delay = next_t - time.time()
        if delay > 0:
            time.sleep(delay)
    end = time.time()
    time.sleep(2.0)  # drain grace
    stopped.set()
    sock.close()
    loss = sent[0] - len(rtts)
    per_size = []
    for size in SIZES:
        sz_sent = sum(1 for i in range(sent[0]) if SIZES[i % len(SIZES)] == size)
        sz_recv = recv_by_size.get(size, 0)
        per_size.append({"size": size, "sent": sz_sent, "recv": sz_recv, "loss": sz_sent - sz_recv})
    return {
        "target": "%s:%d" % (HOST, UDP_PORT), "pps": PPS, "window_s": WINDOW_S,
        "actual_s": round(end - start, 3), "sent": sent[0], "recv": len(rtts),
        "loss": loss, "loss_pct": round(loss * 100.0 / sent[0], 4) if sent[0] else None,
        "rtt_ms": summarize([v / 1e6 for v in rtts.values()]),
        "per_size": per_size,
    }


def build_tcp_payload(index, size):
    payload = bytearray(size)
    payload[0:4] = b"JSTC"
    struct.pack_into(">I", payload, 4, index)
    for offset in range(8, size):
        payload[offset] = (17 * offset + 3) & 0xFF
    return bytes(payload)


def tcp_baseline():
    connect_ms = []
    first_byte_ms = []
    echo_ms = []
    ok = 0
    for index in range(TCP_COUNT):
        payload = build_tcp_payload(index, TCP_PAYLOAD_SIZE)
        try:
            t0 = time.time()
            sock = socket.create_connection((HOST, TCP_PORT), timeout=10)
            connect_ms.append((time.time() - t0) * 1000)
            sock.settimeout(10)
            t1 = time.time()
            sock.sendall(payload)
            data = b""
            first_byte = None
            while len(data) < len(payload):
                chunk = sock.recv(len(payload) - len(data))
                if not chunk:
                    break
                if first_byte is None:
                    first_byte = time.time()
                    first_byte_ms.append((first_byte - t1) * 1000)
                data += chunk
            if data == payload:
                echo_ms.append((time.time() - t1) * 1000)
                ok += 1
            sock.close()
        except OSError as exc:
            print("tcp conn %d failed: %s" % (index, exc), file=sys.stderr)
    return {
        "target": "%s:%d" % (HOST, TCP_PORT), "attempts": TCP_COUNT, "ok": ok,
        "connect_ms": summarize(connect_ms), "first_byte_ms": summarize(first_byte_ms),
        "echo_ms": summarize(echo_ms),
    }


def main():
    result = {
        "host": socket.gethostname(),
        "uname": " ".join(__import__("os").uname()),
        "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "udp": udp_baseline(),
        "tcp": tcp_baseline(),
    }
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
