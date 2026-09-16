#!/usr/bin/env python3
"""UDP path diagnostics through the local Ultra listener (SS-none wire format)."""
import json, os, socket, struct, time

LOCAL = ("127.0.0.1", 10001)


def enc(host, port):
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def dns_query(ident):
    return struct.pack("!HHHHHH", ident, 0x0100, 1, 0, 0, 0) + b"\x07example\x03com\x00" + struct.pack("!HH", 1, 1)


def udp_try(target, payload, timeout=6.0, label=""):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.settimeout(timeout)
    pkt = b"\x00\x00\x00" + enc(*target) + payload
    t0 = time.monotonic()
    try:
        s.sendto(pkt, LOCAL)
        data, _ = s.recvfrom(4096)
        rtt = (time.monotonic() - t0) * 1000
        head = data[:16].hex()
        print("%-28s ok %6.1fms len=%d head=%s" % (label, rtt, len(data), head))
        return True
    except socket.timeout:
        print("%-28s timeout after %.1fs" % (label, timeout))
    except OSError as exc:
        print("%-28s error %s" % (label, exc))
    finally:
        s.close()
    return False


def tcp_try(target, timeout=6.0, label=""):
    try:
        c = socket.create_connection(LOCAL, timeout=timeout)
        c.settimeout(timeout)
        c.sendall(enc(*target) + b"GET /cdn-cgi/trace HTTP/1.1\r\nHost: one.one.one.one\r\nConnection: close\r\n\r\n")
        data = b""
        while len(data) < 64:
            chunk = c.recv(64)
            if not chunk:
                break
            data += chunk
        c.close()
        print("%-28s ok len=%d head=%r" % (label, len(data), data[:24]))
        return True
    except Exception as exc:
        print("%-28s error %s: %s" % (label, type(exc).__name__, exc))
    return False


print("== UDP via tunnel ==")
for i in range(3):
    udp_try(("1.1.1.1", 53), dns_query(0x1000 + i), label="dns 1.1.1.1:53 #%d" % (i + 1))
for i in range(5):
    udp_try(("185.220.238.143", 60000), b"SOAK" + struct.pack("!Qd", i, time.monotonic()) + b"\x00" * 8,
            label="echo 60000 #%d" % (i + 1))
udp_try(("185.220.238.143", 10001), b"\x00" * 12, label="ss-port 10001")
print("== TCP via tunnel ==")
tcp_try(("1.1.1.1", 80), label="http 1.1.1.1:80")
tcp_try(("185.220.238.143", 60001), label="echo 60001")
print("== status ==")
try:
    d = json.load(open("/run/ultra/forward-170.status.json"))
    keys = ["state", "ha_failures", "udp_active_associations", "udp_setup_failures", "udp_keepalive_acks",
            "bytes_in", "bytes_out", "latest_tcp", "active_udp", "updated_at", "runtime_attestation"]
    print(json.dumps({k: d.get(k) for k in keys if k in d}, indent=1)[:1800])
    fb = d.get("candidate_feedback") or []
    print("candidate_feedback last 4:", json.dumps(fb[-4:])[:700])
except Exception as exc:
    print("status error", exc)
