#!/bin/bash
export LC_ALL=C
set -u
APP="/Applications/LeiKwan Bridge.app"
EXE="$APP/Contents/MacOS/LeikwanBridge.Mac"
pkill -f 'LeiKwan Bridge.app/Contents/MacOS' 2>/dev/null; pkill -f 'Resources/bin/ultra-client' 2>/dev/null; sleep 3
STATE="$HOME/Library/Application Support/LeiKwan/ClientAgent/state"
rm -f /tmp/lkb-hlog20260914.log
"$EXE" --headless --panel https://v3.leikwanhost.com --client-id 3 --status-file /tmp/lkb-hst.json --print-status > /tmp/lkb-h.out 2>&1 &
PID=$!
sleep 70
echo "--- runtime status files ---"
find "$STATE/runtime" -name 'status.json' 2>/dev/null | head -5
for f in $(find "$STATE/runtime" -name 'status.json' 2>/dev/null | head -2); do
  echo "== $f"
  python3 - "$f" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
print("keys:", sorted(d.keys())[:18])
print("runtime_attestation:", json.dumps(d.get("runtime_attestation"))[:220])
PY
done
echo "--- client log heartbeat lines ---"
LOG=$(ls -t "$HOME/Library/Logs/LeiKwan/ClientAgent"/client-*.log 2>/dev/null | head -1)
echo "log=$LOG"
grep -iE 'heartbeat|attestation|usage' "$LOG" 2>/dev/null | tail -8
kill -TERM $PID 2>/dev/null
sleep 12
echo MAC-ATTEST-PROBE-DONE
