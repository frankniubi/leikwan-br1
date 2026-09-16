#!/usr/bin/env python3
"""Failover A/B: make every relay but the busiest one half-dead.

Existing connections to those relays are dropped and every NEW connection is
redirected to a local listener that accepts and closes without sending a byte -
the shape that produced the 2026-09-13 outage (dial succeeds, EOF, no data). One
relay is left clean, so a runtime that reacts to the failure has somewhere to go.
The clean relay is the one that has moved the most acknowledged bytes.
"""
import json
import pathlib
import re
import subprocess
import sys
import time

CHAIN = "LEIKWAN_SWITCHAB"
NAT_CHAIN = "LEIKWAN_SWITCHAB_NAT"
BLACKHOLE_PORT = 19999
DURATION = int(sys.argv[1]) if len(sys.argv) > 1 else 190
DIRTY_SECONDS = int(sys.argv[2]) if len(sys.argv) > 2 else 75
OUT = pathlib.Path("/root/switchtest")


def sh(cmd):
    return subprocess.run(cmd, shell=True, text=True, capture_output=True).stdout


def teardown():
    sh("iptables -F %s 2>/dev/null; iptables -D OUTPUT -j %s 2>/dev/null; iptables -X %s 2>/dev/null" % (CHAIN, CHAIN, CHAIN))
    sh("iptables -t nat -F %s 2>/dev/null; iptables -t nat -D OUTPUT -j %s 2>/dev/null; iptables -t nat -X %s 2>/dev/null" % (NAT_CHAIN, NAT_CHAIN, NAT_CHAIN))
    sh("pkill -f blackhole.py 2>/dev/null; true")


def forward_pid():
    return sh("systemctl show ultra-forward-170 -p MainPID --value").strip()


def acked_by_peer():
    """peer IP -> bytes_acked, for forward-170's own sockets only.

    Every forward runs its own runtime process with its own canary sockets, so
    scoping by PID is what keeps this from picking another forward's relay.
    """
    pid = forward_pid()
    if not pid or pid == "0":
        raise SystemExit("forward-170 has no main pid")
    totals = {}
    current = None
    for line in sh("ss -tinp 2>/dev/null | grep -A1 'pid=%s,' | grep -v grep" % pid).splitlines():
        if "bytes_acked:" in line:
            if current:
                totals[current] = totals.get(current, 0) + int(re.search(r"bytes_acked:(\d+)", line).group(1))
                current = None
            continue
        parts = line.split()
        if len(parts) >= 5 and ":" in parts[4]:
            peer = parts[4].rsplit(":", 1)[0].strip("[]").replace("::ffff:", "")
            current = peer if re.match(r"^\d{1,3}(\.\d{1,3}){3}$", peer) and not peer.startswith("127.") else None
    return totals


OUT.mkdir(parents=True, exist_ok=True)
timeline = OUT / "timeline.jsonl"
if timeline.exists():
    timeline.unlink()
teardown()
sh("iptables -N %s; iptables -I OUTPUT -j %s" % (CHAIN, CHAIN))
sh("iptables -t nat -N %s; iptables -t nat -I OUTPUT -j %s" % (NAT_CHAIN, NAT_CHAIN))
subprocess.Popen(["python3", "/root/blackhole.py", str(BLACKHOLE_PORT)], stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
time.sleep(1)

flow = subprocess.Popen(["python3", "/root/switchtest.py", "--duration", str(DURATION), "--out", str(timeline)],
                        stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
flow_start = time.time()
print("flow started; settling 30 s", flush=True)
time.sleep(30)

first = acked_by_peer()
print("bytes_acked by relay: %s" % json.dumps(first), flush=True)
time.sleep(6)
second = acked_by_peer()
deltas = {peer: second.get(peer, 0) - first.get(peer, 0) for peer in second}
print("bytes_acked deltas over 6 s: %s" % json.dumps(deltas), flush=True)
if not second:
    flow.kill()
    teardown()
    raise SystemExit("no relay socket visible for forward-170")
# Make the relay that is carrying the traffic half-dead. Its siblings are left
# untouched: they are only dialed on demand, so a runtime that reacts has
# somewhere to go and a runtime that does not keeps walking into the same wall.
target = max(deltas, key=lambda key: deltas[key]) if any(value > 0 for value in deltas.values()) else max(second, key=lambda key: second[key])
dirty = [target]
clean = "left untouched"
for peer in dirty:
    sh("iptables -A %s -d %s -p tcp -j DROP" % (CHAIN, peer))
    sh("iptables -t nat -A %s -p tcp -d %s -j REDIRECT --to-ports %d" % (NAT_CHAIN, peer, BLACKHOLE_PORT))
dirty_at = time.time() - flow_start
print("half-dead: %s | clean: %s (t=%.1fs)" % (dirty, clean, dirty_at), flush=True)
time.sleep(DIRTY_SECONDS)
sh("iptables -F %s" % CHAIN)
sh("iptables -t nat -F %s" % NAT_CHAIN)
clean_at = time.time() - flow_start
print("relays restored at t=%.1fs" % clean_at, flush=True)
try:
    flow.wait(timeout=DURATION + 30)
except subprocess.TimeoutExpired:
    flow.kill()
teardown()

records = [json.loads(line) for line in timeline.read_text().splitlines() if line.strip()]
for index, rec in enumerate(records):
    prev = records[index - 1] if index else {"sent": 0, "recv": 0, "tcp_ok": 0, "tcp_breaks": 0}
    rec["d_sent"] = rec["sent"] - prev["sent"]
    rec["d_recv"] = rec["recv"] - prev["recv"]
    rec["d_tcp_ok"] = rec["tcp_ok"] - prev["tcp_ok"]
    rec["d_tcp_breaks"] = rec["tcp_breaks"] - prev["tcp_breaks"]

before = [r for r in records if r["t"] < dirty_at]
during = [r for r in records if dirty_at <= r["t"] < clean_at]
first_tcp_ok = next((r["t"] for r in records if r["t"] > dirty_at and r["d_tcp_ok"] > 0), None)
summary = {
    "dirty_relays": dirty,
    "clean_relay": clean,
    "dirty_at_s": round(dirty_at, 1),
    "clean_at_s": round(clean_at, 1),
    "tcp_dead_seconds_while_dirty": round(sum(1.0 for r in during if r["d_tcp_ok"] <= 0), 1),
    "tcp_first_ok_after_dirty_s": round(first_tcp_ok, 1) if first_tcp_ok else None,
    "tcp_switch_seconds": round(first_tcp_ok - dirty_at, 1) if first_tcp_ok else None,
    "tcp_breaks_while_dirty": sum(r["d_tcp_breaks"] for r in during),
    "recovered_before_restore": (first_tcp_ok is not None and first_tcp_ok < clean_at),
    "baseline_loss_pct": round(100.0 * sum(r["d_sent"] - r["d_recv"] for r in before) / max(1, sum(r["d_sent"] for r in before)), 2),
    "udp_loss_while_dirty_pct": round(100.0 * sum(r["d_sent"] - r["d_recv"] for r in during) / max(1, sum(r["d_sent"] for r in during)), 2),
}
print(json.dumps(summary, indent=1), flush=True)
