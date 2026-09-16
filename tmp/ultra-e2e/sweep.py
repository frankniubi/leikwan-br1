#!/usr/bin/env python3
"""UDP behaviour under sustained load through the Ultra listener."""
import json, socket, struct, time

LOCAL = ("127.0.0.1", 10001)
TARGET = ("185.220.238.143", 60000)


def enc(host, port):
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def new_sock(timeout=2.0):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.settimeout(timeout)
    return s


def pkt(seq):
    return b"\x00\x00\x00" + enc(*TARGET) + b"SWEEP" + struct.pack("!I", seq)


def run(label, pps, seconds, s, wait_first=False):
    sent = recv = 0
    rtts = []
    if wait_first:
        s.sendto(pkt(0), LOCAL)
        try:
            s.recvfrom(4096)
            print("%-34s first reply ok" % label)
        except socket.timeout:
            print("%-34s first reply TIMEOUT" % label)
    interval = 1.0 / pps
    end = time.monotonic() + seconds
    next_at = time.monotonic()
    seq = 1
    while time.monotonic() < end:
        now = time.monotonic()
        if now < next_at:
            time.sleep(min(next_at - now, 0.02))
            continue
        next_at += interval
        seq += 1
        t0 = time.monotonic()
        s.sendto(pkt(seq), LOCAL)
        sent += 1
        try:
            s.recvfrom(4096)
            recv += 1
            rtts.append((time.monotonic() - t0) * 1000)
        except socket.timeout:
            pass
    loss = 100.0 * (sent - recv) / sent if sent else 0.0
    med = sorted(rtts)[len(rtts) // 2] if rtts else float("nan")
    print("%-34s pps=%3d sent=%4d recv=%4d loss=%6.1f%% p50=%.0fms" % (label, pps, sent, recv, loss, med), flush=True)


print("== A: cold start, immediate flood ==")
s = new_sock()
run("A flood-from-cold", 30, 20, s)
s.close()

print("== B: one packet first (association setup), then flood ==")
s = new_sock(timeout=6.0)
s.sendto(pkt(0), LOCAL)
try:
    t0 = time.monotonic()
    s.recvfrom(4096)
    print("B first reply %.0fms" % ((time.monotonic() - t0) * 1000))
except socket.timeout:
    print("B first reply TIMEOUT")
s.settimeout(2.0)
run("B established then 30pps", 30, 20, s)
s.close()

print("== C: rate sweep on established association ==")
for rate in (2, 5, 10, 20, 30):
    s = new_sock(timeout=6.0)
    s.sendto(pkt(0), LOCAL)
    try:
        s.recvfrom(4096)
    except socket.timeout:
        print("C rate=%2d association setup TIMEOUT" % rate)
    s.settimeout(2.0)
    run("C rate=%2d" % rate, rate, 12, s)
    s.close()
    time.sleep(2)
