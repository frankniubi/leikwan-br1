#!/usr/bin/env python3
"""Compact digest of the soak summary."""
import json
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
doc = json.loads(path.read_text())
print("elapsed_s", doc["elapsed_s"], "target", json.dumps(doc["target"]))
for block in doc["udp"]:
    rtt = block["rtt_ms"]
    print("\n== %s ==" % block["name"])
    print("  sent=%d recv=%d loss=%.3f%% permanent_loss=%d dup=%d unknown=%d" % (
        block["sent"], block["recv"], block["loss_pct"], block["permanent_loss"], block["duplicates"], block["unknown_replies"]))
    print("  rtt min=%s p50=%s p90=%s p95=%s p99=%s max=%s mean=%s" % (
        rtt.get("min"), rtt.get("p50"), rtt.get("p90"), rtt.get("p95"), rtt.get("p99"), rtt.get("max"), rtt.get("mean")))
    print("  jitter mean=%s p95=%s" % (rtt.get("jitter_mean"), rtt.get("jitter_p95")))
    print("  burst=%s big=%s" % (json.dumps(block["burst"]), json.dumps(block["big_packet"])))
    print("  send_errors=%s recv_errors=%s" % (json.dumps(block["send_errors"]), json.dumps(block["recv_errors"])))
    minutes = block["by_minute"]
    worst = sorted(minutes, key=lambda m: m["loss_pct"], reverse=True)[:6]
    print("  minutes=%d  worst:" % len(minutes))
    for m in worst:
        print("    min %3d sent=%4d recv=%4d loss=%.2f%%" % (m["minute"], m["sent"], m["recv"], m["loss_pct"]))
    nonzer = [m for m in minutes if m["loss_pct"] > 0]
    print("  minutes with any loss: %d / %d" % (len(nonzer), len(minutes)))
    print("  loss runs >=3: %s" % json.dumps(block["loss_runs_ge3"][:10]))
for block in doc["tcp"]:
    print("\n== %s ==" % block["name"])
    print("  connects=%d breaks=%d heartbeats=%d/%d loss=%.3f%%" % (
        block["connects"], block["breaks"], block["heartbeats_ok"], block["heartbeats_sent"], block["heartbeat_loss_pct"]))
    rtt = block["rtt_ms"]
    print("  rtt p50=%s p95=%s p99=%s max=%s jitter_mean=%s" % (
        rtt.get("p50"), rtt.get("p95"), rtt.get("p99"), rtt.get("max"), rtt.get("jitter_mean")))
    print("  outages=%s" % json.dumps(block["outages"][:10]))
samples = doc.get("samples") or []
print("\nsamples=%d" % len(samples))
if samples:
    first, last = samples[0], samples[-1]
    for key in ("unit", "ultra", "probe"):
        a = first.get(key, {})
        b = last.get(key, {})
        if key == "unit":
            print("  unit first: pid=%s restarts=%s | last: pid=%s restarts=%s" % (
                a.get("MainPID"), a.get("NRestarts"), b.get("MainPID"), b.get("NRestarts")))
        elif key == "ultra":
            print("  ultra first: state=%s ha_failures=%s | last: state=%s ha_failures=%s udp_assoc=%s" % (
                a.get("state"), a.get("ha_failures"), b.get("state"), b.get("ha_failures"), b.get("udp_active_associations")))
        elif key == "probe":
            print("  probe last: ok=%s availability=%s" % (b.get("state"), b.get("availability_percent")))
