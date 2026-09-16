#!/bin/bash
export LC_ALL=C
DIR="$HOME/Library/Application Support/LeiKwan/ClientAgent"
ls -la "$DIR"
echo "--- config.json (masked) ---"
python3 - "$DIR/config.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
for k, v in sorted(d.items()):
    if isinstance(v, str) and len(v) > 24:
        print("%-28s len=%d %s…" % (k, len(v), v[:8]))
    elif isinstance(v, list):
        print("%-28s list[%d]" % (k, len(v)))
    else:
        print("%-28s %r" % (k, v))
PY
echo "--- secrets.json (masked) ---"
if [ -f "$DIR/secrets.json" ]; then
python3 - "$DIR/secrets.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
for k, v in sorted(d.items()):
    if isinstance(v, str):
        print("%-40s len=%d %s…" % (k, len(v), v[:6]))
    else:
        print("%-40s %r" % (k, type(v).__name__))
PY
else
  echo "no secrets.json"
fi
echo MAC-ID-DONE
