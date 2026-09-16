#!/usr/bin/env python3
"""DNS-via-tunnel availability probe for the Ultra forward-170 facade (fixed reply parsing).

Reply shape on the wire:  3-byte SOCKS5 UDP prefix | SS address header | payload
so the DNS body starts at offset 3 + (1 + 4 + 2) for an IPv4 source address.
One long-lived UDP socket, 1 query/second (strictly paced).
"""
from __future__ import annotations

import datetime
import json
import socket
import struct
import sys
import time

LOCAL = ("127.0.0.1", 10001)
INNER = ("1.1.1.1", 53)


def utcnow():
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def enc(host, port):
    return b"\x01" + socket.inet_aton(host) + struct.pack("!H", port)


def dns_query(ident):
    return struct.pack("!HHHHHH", ident, 0x0100, 1, 0, 0, 0) + b"\x07example\x03com\x00" + struct.pack("!HH", 1, 1)


def strip_reply(data):
    """Return (payload, note) after the 3-byte prefix + SS address header."""
    if len(data) < 4:
        return None, "short frame %d" % len(data)
    body = data[3:]
    atyp = body[0]
    if atyp == 1:
        off = 1 + 4 + 2
    elif atyp == 4:
        off = 1 + 16 + 2
    elif atyp == 3:
        off = 1 + 1 + body[1] + 2
    else:
        return None, "atyp %d" % atyp
    if len(body) < off:
        return None, "truncated header"
    return body[off:], None


def pct(vals, q):
    if not vals:
        return None
    o = sorted(vals)
    if len(o) == 1:
        return o[0]
    pos = (len(o) - 1) * q
    lo = int(pos)
    hi = min(lo + 1, len(o) - 1)
    return o[lo] + (o[hi] - o[lo]) * (pos - lo)


duration = int(sys.argv[1]) if len(sys.argv) > 1 else 600
out = sys.argv[2] if len(sys.argv) > 2 else "dns-probe.json"
rate = float(sys.argv[3]) if len(sys.argv) > 3 else 1.0

sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.settimeout(5.0)

start = time.monotonic()
sent = ok = timeouts = badreply = 0
rtts = []
stalls = []
notes = {}
minutes = {}
first_rtt = None

print("%s dns-tunnel-probe start duration=%ds rate=%.1f/s inner=%s:%d" % (utcnow(), duration, rate, INNER[0], INNER[1]), flush=True)

idx = 0
while True:
    slot_at = start + idx / rate
    if slot_at - start >= duration:
        break
    now = time.monotonic()
    if slot_at > now:
        time.sleep(min(slot_at - now, 0.5))
        continue
    idx += 1
    txid = 0x4000 + (idx & 0x3FFF)
    pkt = b"\x00\x00\x00" + enc(*INNER) + dns_query(txid)
    minute = int((time.monotonic() - start) // 60)
    slot = minutes.setdefault(minute, {"sent": 0, "ok": 0, "rtt": []})
    slot["sent"] += 1
    sent += 1
    t0 = time.monotonic()
    try:
        sock.sendto(pkt, LOCAL)
        data, _ = sock.recvfrom(4096)
        rtt = (time.monotonic() - t0) * 1000.0
        payload, note = strip_reply(data)
        if payload is None or len(payload) < 12:
            badreply += 1
            notes[note or "short payload"] = notes.get(note or "short payload", 0) + 1
        else:
            rid = struct.unpack("!H", payload[:2])[0]
            flags = struct.unpack("!H", payload[2:4])[0]
            if rid != txid or not (flags & 0x8000):
                badreply += 1
                notes["mismatch rid=%04x tx=%04x fl=%04x" % (rid, txid, flags)] = notes.get("mismatch", 0) + 1
            else:
                ok += 1
                slot["ok"] += 1
                rtts.append(rtt)
                slot["rtt"].append(rtt)
                if first_rtt is None:
                    first_rtt = rtt
                if rtt > 500.0:
                    stalls.append({"at": utcnow(), "minute": minute, "rtt_ms": round(rtt, 1)})
    except socket.timeout:
        timeouts += 1
    except OSError as exc:
        badreply += 1
        notes["oserror:" + type(exc).__name__] = notes.get("oserror:" + type(exc).__name__, 0) + 1

elapsed = time.monotonic() - start
by_minute = []
for m in sorted(minutes):
    s = minutes[m]
    by_minute.append({
        "minute": m, "sent": s["sent"], "ok": s["ok"],
        "loss_pct": round(100.0 * (s["sent"] - s["ok"]) / s["sent"], 2) if s["sent"] else 0.0,
        "rtt_p50": round(pct(s["rtt"], 0.5), 2) if s["rtt"] else None,
        "rtt_max": round(max(s["rtt"]), 2) if s["rtt"] else None,
    })

summary = {
    "started": utcnow(), "elapsed_s": round(elapsed, 1),
    "inner": "%s:%d" % INNER, "local": "%s:%d" % LOCAL, "rate_per_s": rate,
    "sent": sent, "ok": ok, "timeouts": timeouts, "bad_replies": badreply,
    "bad_reply_notes": notes,
    "availability_pct": round(100.0 * ok / sent, 3) if sent else 0.0,
    "loss_pct": round(100.0 * (sent - ok) / sent, 3) if sent else 0.0,
    "cold_start_rtt_ms": round(first_rtt, 2) if first_rtt is not None else None,
    "rtt_ms": {
        "samples": len(rtts),
        "min": round(min(rtts), 2) if rtts else None,
        "p50": round(pct(rtts, 0.5), 2) if rtts else None,
        "p90": round(pct(rtts, 0.9), 2) if rtts else None,
        "p95": round(pct(rtts, 0.95), 2) if rtts else None,
        "p99": round(pct(rtts, 0.99), 2) if rtts else None,
        "max": round(max(rtts), 2) if rtts else None,
        "mean": round(sum(rtts) / len(rtts), 2) if rtts else None,
    },
    "jitter_ms": {
        "mean": round(sum(abs(rtts[i] - rtts[i - 1]) for i in range(1, len(rtts))) / (len(rtts) - 1), 2) if len(rtts) > 1 else None,
        "max": round(max(abs(rtts[i] - rtts[i - 1]) for i in range(1, len(rtts))), 2) if len(rtts) > 1 else None,
    },
    "stalls_over_500ms": len(stalls),
    "stall_samples": stalls[:40],
    "by_minute": by_minute,
}
with open(out, "w") as fh:
    json.dump(summary, fh, indent=1)
print("%s dns-tunnel-probe done sent=%d ok=%d timeouts=%d bad=%d loss=%.3f%% avail=%.3f%% rtt p50=%s p95=%s max=%s stalls>500ms=%d" % (
    utcnow(), sent, ok, timeouts, badreply, summary["loss_pct"], summary["availability_pct"],
    summary["rtt_ms"]["p50"], summary["rtt_ms"]["p95"], summary["rtt_ms"]["max"], len(stalls)), flush=True)
print("summary -> " + out, flush=True)
