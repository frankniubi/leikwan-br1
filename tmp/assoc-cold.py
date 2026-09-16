#!/usr/bin/env python3
"""Cold vs warm UDP association latency through the forward-170 facade.

(a) 5 FRESH sockets, one DNS query each, 2 s apart  -> first-datagram-on-new-association
(b) 1 socket reused for 5 queries, 2 s apart        -> steady state control
Wire format: b"\x00\x00\x00" + b"\x01" + inet_aton(host) + !H port + payload
"""
import socket, struct, time

LOCAL = ("127.0.0.1", 10001)
INNER = ("1.1.1.1", 53)


def enc(h, p):
    return b"\x01" + socket.inet_aton(h) + struct.pack("!H", p)


def q(i):
    return struct.pack("!HHHHHH", i & 0xFFFF, 0x0100, 1, 0, 0, 0) + b"\x07example\x03com\x00" + struct.pack("!HH", 1, 1)


def one(sock, txid):
    pkt = b"\x00\x00\x00" + enc(*INNER) + q(txid)
    t0 = time.monotonic()
    sock.sendto(pkt, LOCAL)
    try:
        data, _ = sock.recvfrom(4096)
        rtt = (time.monotonic() - t0) * 1000.0
        body = data[3:]
        off = 1 + 4 + 2 if body[0] == 1 else (1 + 16 + 2 if body[0] == 4 else 1 + 1 + body[1] + 2)
        pay = body[off:]
        rid = struct.unpack("!H", pay[:2])[0]
        flags = struct.unpack("!H", pay[2:4])[0]
        return ("ok" if (rid == txid and flags & 0x8000) else "mismatch"), rtt, len(pay)
    except socket.timeout:
        return "timeout", (time.monotonic() - t0) * 1000.0, 0


print("== (a) fresh socket, first datagram (new association) ==")
for i in range(5):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.settimeout(5.0)
    st, rtt, n = one(s, 0x5000 + i)
    print("  cold#%d %-8s %8.1f ms  len=%d" % (i + 1, st, rtt, n))
    s.close()
    time.sleep(2.0)

print("== (b) one reused socket (steady state) ==")
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.settimeout(5.0)
for i in range(5):
    st, rtt, n = one(s, 0x5100 + i)
    print("  warm#%d %-8s %8.1f ms  len=%d" % (i + 1, st, rtt, n))
    time.sleep(2.0)
s.close()
