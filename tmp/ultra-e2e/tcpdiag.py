#!/usr/bin/env python3
"""TCP connect cost through the tunnel, and candidate feedback evidence."""
import json
import socket
import struct
import time

LOCAL = ("127.0.0.1", 10001)
TARGET = ("185.220.238.143", 60001)


def enc(host, port):
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


print("== 5 sequential fresh TCP connections (SS-none) ==")
for i in range(5):
    t0 = time.monotonic()
    try:
        c = socket.create_connection(LOCAL, timeout=8.0)
        t_conn = (time.monotonic() - t0) * 1000
        c.settimeout(8.0)
        payload = b"PING%02d" % i
        c.sendall(enc(*TARGET) + payload)
        data = c.recv(len(payload))
        t_first = (time.monotonic() - t0) * 1000
        print("conn %d: tcp_connect=%.0fms first_byte=%.0fms echo=%r" % (i, t_conn, t_first, data))
        c.close()
    except Exception as exc:
        print("conn %d: %s: %s" % (i, type(exc).__name__, exc))
    time.sleep(1.0)

print("== candidate feedback evidence ==")
try:
    d = json.load(open("/run/ultra/forward-170.status.json"))
    fb = d.get("candidate_feedback") or []
    print("entries:", len(fb))
    for item in fb[-6:]:
        print("  ", item.get("protocol"), item.get("outcome"), item.get("reason"), item.get("observed_at"))
    print("ha_events last 3:", json.dumps((d.get("ha_events") or [])[-3:]))
    print("ha_failures:", d.get("ha_failures"), "udp_active:", d.get("udp_active_associations"),
          "keepalive_acks:", d.get("udp_keepalive_acks"))
except Exception as exc:
    print("status error", exc)
