#!/usr/bin/env python3
"""Where does the ss-none probe's 1.37 s TCP latency come from?"""
import socket
import struct
import time

LOCAL = ("127.0.0.1", 10001)


def enc(host, port):
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def timed(label, target, payload, until=0, limit=64 * 1024, timeout=12.0):
    t0 = time.monotonic()
    try:
        c = socket.create_connection(LOCAL, timeout=timeout)
        t_conn = (time.monotonic() - t0) * 1000
        c.settimeout(timeout)
        c.sendall(enc(*target) + payload)
        data = b""
        recv_marks = []
        while len(data) < limit:
            chunk = c.recv(4096)
            if not chunk:
                break
            data += chunk
            recv_marks.append(((time.monotonic() - t0) * 1000, len(chunk)))
            if isinstance(until, bytes) and until and until in data:
                break
            if isinstance(until, int) and until and len(data) >= until:
                break
        c.close()
        print("%-34s connect=%.0fms first=%.0fms n_recv=%d total=%.0fms bytes=%d marks=%s"
              % (label, t_conn, recv_marks[0][0] if recv_marks else -1, len(recv_marks),
                 (time.monotonic() - t0) * 1000, len(data),
                 [(round(m), n) for m, n in recv_marks[:6]]))
    except Exception as exc:
        print("%-34s %s: %s" % (label, type(exc).__name__, exc))


HTTP = (b"GET /cdn-cgi/trace HTTP/1.1\r\nHost: one.one.one.one\r\nConnection: close\r\n\r\n")
timed("http 1.1.1.1:80 (probe shape)", ("1.1.1.1", 80), HTTP, until=b"HTTP/1.")
timed("http 1.1.1.1:80 (to EOF)", ("1.1.1.1", 80), HTTP, until=0)
timed("echo 60001 23B", ("185.220.238.143", 60001), b"X" * 23, until=23)
timed("echo 60001 4096B", ("185.220.238.143", 60001), b"Y" * 4096, until=4096)
timed("echo 60001 16384B", ("185.220.238.143", 60001), b"Z" * 16384, until=16384)
