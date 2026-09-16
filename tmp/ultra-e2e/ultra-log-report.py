#!/usr/bin/env python3
"""Summarise ultra-client runtime logs for the silent-relay investigation.

Usage: python ultra-log-report.py <log> [<log> ...]

Accepts either the Go runtime log (raw "tcp stream ended ..." lines) or the
client app log that embeds it. Reports, per forward:

  * paired stream outcomes: OK (downstream bytes > 0), SILENT (upstream bytes > 0
    and downstream bytes == 0), idle (nothing moved in either direction)
  * the quarantine lifetime distribution, split into the two failure modes seen
    on 2026-09-14: INSTANT (<= 250 ms, the relay closed right after its ACK) and
    SILENT-HANG (~1000 ms, the relay accepted and then answered nothing)
  * setup failures ("no healthy authorized candidate") and the longest window in
    which the forward could not serve a single stream
  * the longest run of minutes with no successful stream at all

Nothing here is specific to one incident; it is the reusable read-out for any
future silent-relay report.
"""
import datetime
import re
import sys
from collections import Counter, defaultdict

END = re.compile(
    r"(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d).*tcp stream ended forward_id=(\d+) "
    r"direction=(\w+) reason=(\w+) generation=(\w+) selection_ref=(\S+) "
    r"duration_ms=(\d+) bytes=(\d+)"
)
QUAR = re.compile(
    r"(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d).*tcp candidate quarantined forward_id=(\d+) "
    r"selection_ref=(\S+) reason=(\w+) lifetime_ms=(\d+) upstream_bytes=(\d+)"
)
FAIL = re.compile(r"(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d).*tcp stream setup failed forward_id=(\d+)")
GEN = re.compile(r"tcp stream ended forward_id=(\d+) direction=\w+ reason=\w+ generation=(\w+)")
LEASE = re.compile(r"(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d).*ultra lease selection started forward_id=(\d+) candidates=(\d+)")
NOTLISTEN = re.compile(r"(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d).*forward (\d+): local port (\d+) not listening")


def parse(path):
    out = dict(ends=[], quars=[], fails=[], gens=defaultdict(set), leases=[], notlisten=[])
    for line in open(path, encoding="utf-8", errors="replace"):
        m = END.search(line)
        if m:
            t = datetime.datetime.strptime(m.group(1), "%Y-%m-%d %H:%M:%S")
            dur = int(m.group(7))
            out["ends"].append(dict(
                t=t, start=t - datetime.timedelta(milliseconds=dur), fwd=int(m.group(2)),
                dir=m.group(3), gen=m.group(5)[:8], sel=m.group(6), dur=dur, by=int(m.group(8))))
            out["gens"][int(m.group(2))].add(m.group(5)[:8])
            continue
        m = QUAR.search(line)
        if m:
            out["quars"].append((m.group(1), int(m.group(2)), int(m.group(5)), int(m.group(6))))
            continue
        m = FAIL.search(line)
        if m:
            out["fails"].append((m.group(1), int(m.group(2))))
            continue
        m = LEASE.search(line)
        if m:
            out["leases"].append((m.group(1), int(m.group(2)), int(m.group(3))))
            continue
        m = NOTLISTEN.search(line)
        if m:
            out["notlisten"].append((m.group(1), int(m.group(2)), m.group(3)))
            continue
        m = GEN.search(line)
        if m:
            out["gens"][int(m.group(1))].add(m.group(2))
            continue
    return out


def pair_streams(ends):
    """Join the two directions of one stream by (forward, generation, sel, start)."""
    out = []
    for fwd in sorted({e["fwd"] for e in ends}):
        sub = [e for e in ends if e["fwd"] == fwd]
        used = set()
        for i, up in enumerate(sub):
            if i in used or up["dir"] != "upstream":
                continue
            best = None
            for j, dn in enumerate(sub):
                if j in used or dn["dir"] != "downstream" or dn["sel"] != up["sel"] or dn["gen"] != up["gen"]:
                    continue
                delta = abs((dn["start"] - up["start"]).total_seconds())
                if delta <= 0.05 and (best is None or delta < best[0]):
                    best = (delta, j, dn)
            if best is None:
                continue
            used.add(i)
            used.add(best[1])
            dn = best[2]
            out.append(dict(fwd=fwd, start=min(up["start"], dn["start"]), up=up["by"], down=dn["by"]))
    return out


def longest_outage(fails):
    """Longest span with no more than 60 s of quiet, from first to last failure."""
    if not fails:
        return None
    stamps = sorted(datetime.datetime.strptime(t, "%Y-%m-%d %H:%M:%S") for t, _ in fails)
    start = prev = stamps[0]
    best = (0, start, start)
    for s in stamps[1:]:
        if (s - prev).total_seconds() > 60:
            if (prev - start).total_seconds() > best[0]:
                best = ((prev - start).total_seconds(), start, prev)
            start = s
        prev = s
    if (prev - start).total_seconds() > best[0]:
        best = ((prev - start).total_seconds(), start, prev)
    return best


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 2
    data = dict(ends=[], quars=[], fails=[], gens=defaultdict(set), leases=[], notlisten=[])
    for path in argv[1:]:
        part = parse(path)
        for k in ("ends", "quars", "fails", "leases", "notlisten"):
            data[k].extend(part[k])
        for k, v in part["gens"].items():
            data["gens"][k] |= v
    if not data["ends"] and not data["fails"]:
        print("no ultra runtime events found")
        return 1

    stamps = [e["t"] for e in data["ends"]]
    print("logs      : %s" % ", ".join(argv[1:]))
    if stamps:
        print("span      : %s -> %s" % (min(stamps), max(stamps)))
    print("streams   : %d end-events, %d reconstructed" % (len(data["ends"]), len(pair_streams(data["ends"]))))
    print("quarantine: %d   setup-failed: %d   lease-selections: %d   listener-warnings: %d"
          % (len(data["quars"]), len(data["fails"]), len(data["leases"]), len(data["notlisten"])))

    streams = pair_streams(data["ends"])
    per_min = defaultdict(lambda: [0, 0])
    for s in streams:
        if s["down"] is None:
            continue
        bucket = per_min[(s["fwd"], s["start"].strftime("%H:%M"))]
        if s["down"] > 0:
            bucket[0] += 1
        elif s["up"] > 0:
            bucket[1] += 1

    for fwd in sorted({s["fwd"] for s in streams}):
        rows = [s for s in streams if s["fwd"] == fwd]
        ok = sum(1 for s in rows if s["down"] and s["down"] > 0)
        sil = sum(1 for s in rows if s["down"] == 0 and s["up"] > 0)
        idle = sum(1 for s in rows if s["down"] == 0 and s["up"] == 0)
        total = ok + sil + idle
        print()
        print("forward %d: streams=%d  OK=%d  SILENT=%d  idle=%d  silent_rate=%.1f%%  generations=%d"
              % (fwd, total, ok, sil, idle, 100.0 * sil / max(1, ok + sil), len(data["gens"].get(fwd, ()))))
        q = [x for x in data["quars"] if x[1] == fwd]
        instant = sum(1 for x in q if x[2] <= 250)
        hang = sum(1 for x in q if 900 <= x[2] <= 1100)
        print("  quarantine lifetime: INSTANT(<=250ms)=%d  SILENT-HANG(~1s)=%d  other=%d  max=%s ms"
              % (instant, hang, len(q) - instant - hang, max([x[2] for x in q], default="-")) )
        f = [x for x in data["fails"] if x[1] == fwd]
        window = longest_outage(f)
        if window:
            print("  setup failures: %d, %s -> %s, longest unbroken window %.0f s (%s -> %s)"
                  % (len(f), f[0][0][11:], f[-1][0][11:], window[0], window[1].strftime("%H:%M:%S"), window[2].strftime("%H:%M:%S")))
        else:
            print("  setup failures: 0")
        mins = sorted(m for (fw, m) in per_min if fw == fwd)
        worst = cur = 0
        for m in mins:
            o, s2 = per_min[(fwd, m)]
            cur = cur + 1 if (s2 > 0 and o == 0) else 0
            worst = max(worst, cur)
        print("  longest run of minutes with zero successful streams: %d" % worst)
        peak = Counter({m: per_min[(fwd, m)][1] for m in mins})
        top = ", ".join("%s(%d/%d)" % (m, n, n + per_min[(fwd, m)][0]) for m, n in peak.most_common(5) if n)
        print("  worst minutes (SILENT/total): %s" % (top or "none"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
