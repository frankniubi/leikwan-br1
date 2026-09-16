#!/bin/bash
export LC_ALL=C
set -u
APP="/Applications/LeiKwan Bridge.app"
EXE="$APP/Contents/MacOS/LeikwanBridge.Mac"
pkill -f 'LeiKwan Bridge.app/Contents/MacOS' 2>/dev/null; pkill -f 'Resources/bin/ultra-client' 2>/dev/null; sleep 3
STATE="$HOME/Library/Application Support/LeiKwan/ClientAgent/state"
"$EXE" --headless --panel https://v3.leikwanhost.com --client-id 3 --status-file /tmp/lkb-hst.json > /tmp/lkb-h2.out 2>&1 &
PID=$!
sleep 45
find "$STATE/runtime" -name status.json -print0 2>/dev/null | while IFS= read -r -d '' f; do
  python3 -c '
import json, sys
d = json.load(open(sys.argv[1]))
att = d.get("runtime_attestation")
print("file:", sys.argv[1].rsplit("/", 2)[-2])
print("   keys:", sorted(d.keys()))
print("   runtime_attestation:", json.dumps(att)[:240] if att else "MISSING")
' "$f"
  break
done
kill -TERM $PID 2>/dev/null
sleep 12
echo MAC-ATTEST2-DONE
