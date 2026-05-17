#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== helpers ===";
  sed -n "1,220p" /tmp/live_pull_selfleg.py 2>/dev/null || true;
  echo "=== probe_only47 ===";
  sed -n "1,240p" /tmp/probe_only47.py 2>/dev/null || true;
  echo "=== final_probe_apply47 ===";
  sed -n "1,260p" /tmp/final_probe_apply47.py 2>/dev/null || true;
  echo "=== rendered forward 47 config ===";
  python3 - <<'\''PY'\''
import json, pathlib
for p in [pathlib.Path('/etc/leikwan/mptcp-forward-47.json'), pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh')]:
    print('---', p, 'exists=', p.exists())
    if p.exists() and p.suffix == '.json':
        data = json.loads(p.read_text())
        print(json.dumps({
            'remote_host': data.get('remote_host'),
            'remote_port': data.get('remote_port'),
            'relay_host': data.get('relay_host'),
            'relay_port': data.get('relay_port'),
            'bond_mode': data.get('bond_mode'),
            'direct_legs': data.get('direct_legs'),
            'endpoints': data.get('endpoints'),
            'mptcp_endpoints': data.get('mptcp_endpoints'),
        }, indent=2))
    elif p.exists():
        print(p.read_text())
PY
'