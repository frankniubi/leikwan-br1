#!/usr/bin/env python3
"""Sparse UDP check through the tunnel: one packet every few seconds."""
import json, socket, struct, time

LOCAL = ("127.0.0.1", 10001)
TARGET = ("185.220.238.143", 60000)


def enc(host, port):
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(4.0)
ok = 0
for i in range(15):
    pkt = b"\x00\x00\x00" + enc(*TARGET) + b"SPARSE%03d" % i
    t0 = time.monotonic()
    s.sendto(pkt, LOCAL)
    try:
        data, _ = s.recvfrom(4096)
        rtt = (time.monotonic() - t0) * 1000
        ok += 1
        print("sparse %2d ok %7.1fms len=%d" % (i, rtt, len(data)), flush=True)
    except socket.timeout:
        print("sparse %2d timeout" % i, flush=True)
    time.sleep(2.0)
print("sparse ok=%d/15" % ok)
try:
    d = json.load(open("/run/ultra/forward-170.status.json"))
    print(json.dumps({k: d.get(k) for k in ["state", "ha_failures", "udp_active_associations", "udp_setup_failures",
                                            "udp_keepalive_acks", "bytes_in", "bytes_out", "latest_tcp", "active_udp"]},
                     indent=1)[:1200])
    print("probe:", open("/var/lib/leikwan-ss-none-probe/events.jsonl").read().strip().splitlines()[-1])
except Exception as exc:
    print("status error", exc)
