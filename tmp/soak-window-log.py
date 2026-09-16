#!/usr/bin/env python3
"""Filter the leikwan GUI log to a UTC time window and surface ultra/HA/restart/error lines."""
import re, sys, datetime

path, start_s, end_s = sys.argv[1], sys.argv[2], sys.argv[3]
start = datetime.datetime.fromisoformat(start_s.replace("Z", "+00:00"))
end = datetime.datetime.fromisoformat(end_s.replace("Z", "+00:00"))

ts_re = re.compile(r"^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.(\d{3}) ([+-]\d{2}:\d{2}) (\[[A-Z]+\]) (.*)$")
KEY = re.compile(r"ultra|restart|\\bHA\\b|handover|ha_event|fallback|reconnect|disconnect|error|fail|timeout|reset|eof|degrad|switch|selection_ref|assoc", re.I)
LVL = re.compile(r"\[(WRN|ERR|FTL)\]")

win = []
hits = []
levels = {}
with open(path, "r", encoding="utf-8", errors="replace") as fh:
    for line in fh:
        m = ts_re.match(line.rstrip("\n"))
        if not m:
            continue
        t = datetime.datetime.strptime(m.group(1) + " " + m.group(3), "%Y-%m-%d %H:%M:%S %z")
        levels[m.group(4)] = levels.get(m.group(4), 0) + 1
        if start <= t <= end:
            win.append(line.rstrip("\n"))
            if KEY.search(m.group(5)):
                hits.append(line.rstrip("\n"))

print("=== window %s .. %s ===" % (start_s, end_s))
print("lines in window: %d ; keyword hits: %d" % (len(win), len(hits)))
print("level counts (whole file): %s" % levels)
print("\n--- keyword hits ---")
for h in hits:
    print(h)
