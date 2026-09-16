#!/usr/bin/env python3
"""Correlate ss-none probe failures with ultra-forward restarts and agent kills."""
import collections
import datetime
import json
import subprocess


def run(args):
    return subprocess.run(args, capture_output=True, text=True).stdout


def parse_iso(text):
    # journalctl -o short-iso --utc -> 2026-09-13T06:18:33+0000
    return datetime.datetime.strptime(text.split()[0][:19] + "+0000", "%Y-%m-%dT%H:%M:%S%z").timestamp()


events = [json.loads(line) for line in open("/var/lib/leikwan-ss-none-probe/events.jsonl") if line.strip()]
fails = [e for e in events if not e.get("ok")]

agent = run(["journalctl", "-u", "leikwan-client-agent", "--no-pager", "--since", "-48h", "-o", "short-iso", "--utc"])
forward = run(["journalctl", "-u", "ultra-forward-170", "--no-pager", "--since", "-48h", "-o", "short-iso", "--utc"])

restarts = [parse_iso(l) for l in forward.splitlines() if "Started ultra-forward-170" in l]
kills = [parse_iso(l) for l in agent.splitlines() if "Watchdog timeout" in l]
listener = [parse_iso(l) for l in agent.splitlines() if "Ultra listener absent" in l]
agent_restarts = [parse_iso(l) for l in agent.splitlines() if "Scheduled restart job" in l]

print("probe samples=%d failures=%d (%.2f%%)" % (len(events), len(fails), 100.0 * len(fails) / max(1, len(events))))
print("last 48h: forward-170 starts=%d agent watchdog kills=%d agent restarts=%d listener-absent=%d"
      % (len(restarts), len(kills), len(agent_restarts), len(listener)))

def in_window(t, stamps, before, after):
    return any(t - before <= s <= t + after for s in stamps)

buckets = collections.Counter()
reasons = collections.Counter()
for e in fails:
    t = e.get("timestamp")
    if not isinstance(t, (int, float)):
        buckets["no_timestamp"] += 1
        continue
    if in_window(t, restarts, 10.0, 45.0):
        buckets["forward_restart_window"] += 1
    elif in_window(t, kills, 5.0, 90.0):
        buckets["agent_watchdog_window"] += 1
    elif in_window(t, agent_restarts, 5.0, 90.0):
        buckets["agent_restart_window"] += 1
    else:
        buckets["unexplained"] += 1
        reasons[e.get("error_code", "")] += 1

print("attribution:", dict(buckets))
print("unexplained reasons:", reasons.most_common(8))
print("all reasons:", collections.Counter(e.get("error_code", "") for e in fails).most_common(8))

# hourly view for the last 24h: failures vs restarts
now = datetime.datetime.now(datetime.timezone.utc).timestamp()
hours = collections.defaultdict(lambda: [0, 0, 0])
for e in events:
    t = e.get("timestamp")
    if not isinstance(t, (int, float)) or now - t > 86400:
        continue
    key = datetime.datetime.fromtimestamp(t, datetime.timezone.utc).strftime("%m-%dT%H")
    hours[key][0] += 1
    if not e.get("ok"):
        hours[key][1] += 1
for s in restarts:
    if now - s <= 86400:
        key = datetime.datetime.fromtimestamp(s, datetime.timezone.utc).strftime("%m-%dT%H")
        hours[key][2] += 1
print("hour samples fails restarts")
for key in sorted(hours):
    sample, fail, restart = hours[key]
    print("  %s %4d %4d %4d" % (key, sample, fail, restart))

# gap analysis: how long is the tunnel unusable in the worst episodes
fails_sorted = sorted(e["timestamp"] for e in fails if isinstance(e.get("timestamp"), (int, float)) and now - e["timestamp"] <= 86400)
runs = []
for t in fails_sorted:
    if runs and t - runs[-1][-1] <= 60:
        runs[-1].append(t)
    else:
        runs.append([t])
runs.sort(key=len, reverse=True)
print("worst failure clusters (last 24h):")
for r in runs[:6]:
    print("   %s  %d consecutive samples  span=%.0fs" % (
        datetime.datetime.fromtimestamp(r[0], datetime.timezone.utc).strftime("%H:%M:%S"), len(r), r[-1] - r[0]))
