#!/usr/bin/env python3
"""Measure real failover time: kill every relay the runtime is using, watch the flow.

Blocks the remote endpoints the ultra runtime currently talks to (a dedicated
iptables chain, so nothing else is ever touched), then reports how many seconds
the UDP flow and the TCP flow stayed broken, and which relay it moved to.
"""
import json
import pathlib
import re
import subprocess
import sys
import time

CHAIN = "LEIKWAN_SWITCHTEST"
DURATION = int(sys.argv[1]) if len(sys.argv) > 1 else 240
DOWN_SECONDS = int(sys.argv[2]) if len(sys.argv) > 2 else 60
OUT = pathlib.Path("/root/switchtest")


def sh(cmd):
    return subprocess.run(cmd, shell=True, text=True, capture_output=True).stdout


def setup_chain():
    sh("iptables -N %s 2>/dev/null; true" % CHAIN)
    sh("iptables -F %s" % CHAIN)
    sh("iptables -C OUTPUT -j %s 2>/dev/null || iptables -I OUTPUT -j %s" % (CHAIN, CHAIN))


def teardown_chain():
    sh("iptables -F %s 2>/dev/null; true" % CHAIN)
    sh("iptables -D OUTPUT -j %s 2>/dev/null; true" % CHAIN)
    sh("iptables -X %s 2>/dev/null; true" % CHAIN)


IPV4 = re.compile(r"^\d{1,3}(?:\.\d{1,3}){3}$")


def remotes():
    """Public endpoints the runtime talks to right now, split by protocol."""
    tcp, udp = set(), set()
    for protocol, flag in (("tcp", "-tnp"), ("udp", "-unp")):
        for line in sh("ss %s 2>/dev/null | grep ultra-client" % flag).splitlines():
            parts = line.split()
            if len(parts) < 5:
                continue
            peer = parts[4].rsplit(":", 1)[0].strip("[]")
            peer = peer.replace("::ffff:", "")
            if not IPV4.match(peer):
                continue
            if peer.startswith("127.") or peer == "0.0.0.0":
                continue
            (tcp if protocol == "tcp" else udp).add(peer)
    return tcp, udp


OUT.mkdir(parents=True, exist_ok=True)
timeline = OUT / "timeline.jsonl"
if timeline.exists():
    timeline.unlink()
teardown_chain()
setup_chain()

flow = subprocess.Popen(["python3", "/root/switchtest.py", "--duration", str(DURATION), "--out", str(timeline)],
                        stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
flow_start = time.time()
print("flow started; settling for 45 s", flush=True)
time.sleep(45)

tcp_before, udp_before = remotes()
targets = sorted(tcp_before | udp_before)
for target in targets:
    sh("iptables -A %s -d %s -j DROP" % (CHAIN, target))
blocked_at = time.time() - flow_start
print("blocked at t=%.1fs: %s" % (blocked_at, targets), flush=True)
time.sleep(DOWN_SECONDS)
sh("iptables -F %s" % CHAIN)
restored_at = time.time() - flow_start
print("unblocked at t=%.1fs" % restored_at, flush=True)
time.sleep(30)

tcp_after, udp_after = remotes()
try:
    flow.wait(timeout=DURATION + 30)
except subprocess.TimeoutExpired:
    flow.kill()
teardown_chain()

records = [json.loads(line) for line in timeline.read_text().splitlines() if line.strip()]
baseline = [r for r in records if r["t"] < blocked_at]
after = [r for r in records if r["t"] >= blocked_at]
worst = 0.0
gap_start = None
gap_records = []
for rec in after:
    bad = rec["lost"] > max(3, rec["sent"] * 0.03)
    if bad:
        if gap_start is None:
            gap_start = rec["t"]
        gap_records.append(rec)
        worst = rec["t"]
    elif gap_start is not None:
        break
summary = {
    "baseline_loss_pct": round(100.0 * sum(r["lost"] for r in baseline) / max(1, sum(r["sent"] for r in baseline)), 2),
    "blocked_targets": targets,
    "blocked_at_s": round(blocked_at, 1),
    "unblocked_at_s": round(restored_at, 1),
    "outage_start_s": gap_start,
    "outage_last_bad_s": worst,
    "outage_seconds": round(worst - gap_start, 1) if gap_start is not None else 0.0,
    "loss_in_outage_pct": round(100.0 * sum(r["lost"] for r in gap_records) / max(1, sum(r["sent"] for r in gap_records)), 1),
    "tcp_breaks_total": records[-1]["tcp_breaks"] if records else None,
    "relay_before": sorted(tcp_before | udp_before),
    "relay_after": sorted(tcp_after | udp_after),
    "moved": sorted((tcp_after | udp_after) - (tcp_before | udp_before)),
}
print(json.dumps(summary, indent=1), flush=True)
