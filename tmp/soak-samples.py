#!/usr/bin/env python3
"""Summarise the ultra status-file samples embedded in a soak summary.json."""
import json, sys

path = sys.argv[1]
doc = json.load(open(path, encoding="utf-8"))
samples = doc.get("samples") or []
print("window: %s .. %s (%d samples)" % (doc.get("started"), samples[-1]["at"] if samples else "-", len(samples)))

first = samples[0].get("ultra", {})
last = samples[-1].get("ultra", {})
keys = ["state", "ha_failures", "ha_events", "candidate_feedback", "udp_active_associations",
        "udp_keepalive_acks", "udp_setup_failures", "udp_last_ack_at", "bytes_in", "bytes_out",
        "policy_generation", "detected_ingress_zone", "updated_at", "runtime_attestation",
        "active_udp", "latest_tcp", "udp_runtime_session"]
print("\n-- first sample --")
for k in keys:
    if k in first:
        print("  %-24s %s" % (k, json.dumps(first[k])[:300]))
print("-- last sample --")
for k in keys:
    if k in last:
        v = json.dumps(last[k])[:300]
        print("  %-24s %s" % (k, v))

print("\n-- scalar deltas (last - first) --")
for k in sorted(set(first) & set(last)):
    a, b = first[k], last[k]
    if isinstance(a, (int, float)) and isinstance(b, (int, float)) and not isinstance(a, bool):
        if a != b:
            print("  %-24s %s -> %s  (delta %+g)" % (k, a, b, b - a))

print("\n-- state transitions --")
prev = None
for s in samples:
    st = s.get("ultra", {}).get("state")
    if st != prev:
        print("  %s  state=%s" % (s["at"], st))
        prev = st

print("\n-- udp_active_associations transitions --")
prev = None
for s in samples:
    v = s.get("ultra", {}).get("udp_active_associations")
    if v != prev:
        print("  %s  assoc=%s" % (s["at"], v))
        prev = v

print("\n-- ha_events unique --")
seen = set()
for s in samples:
    for ev in s.get("ultra", {}).get("ha_events") or []:
        key = json.dumps(ev, sort_keys=True)
        if key not in seen:
            seen.add(key)
            print("  %s" % key)

print("\n-- candidate_feedback unique (last 12) --")
seen = []
for s in samples:
    for ev in s.get("ultra", {}).get("candidate_feedback") or []:
        key = json.dumps(ev, sort_keys=True)
        if key not in seen:
            seen.append(key)
for k in seen[-12:]:
    print("  %s" % k)
print("  (%d unique total)" % len(seen))

print("\n-- selection refs over time --")
prev = None
for s in samples:
    u = s.get("ultra", {})
    cur = (u.get("active_udp") or {}).get("selection_ref"), (u.get("active_udp") or {}).get("rtt_ms"), (u.get("latest_tcp") or {}).get("selection_ref")
    if cur != prev:
        print("  %s  active_udp ref=%s rtt=%s | latest_tcp ref=%s" % (s["at"], cur[0], cur[1], cur[2]))
        prev = cur
