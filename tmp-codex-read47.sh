#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/codex-read47.py <<'PY'
import json, pathlib
p = pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
print('json_exists', p.exists())
if p.exists():
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
    }, ensure_ascii=False, indent=2))
sp = pathlib.Path('/usr/local/bin/leikwan-client-start-47.sh')
print('script_exists', sp.exists())
if sp.exists():
    print(sp.read_text())
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-read47.py root@114.80.33.50:/tmp/codex-read47.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 "python3 /tmp/live_pull_selfleg.py; echo '==='; python3 /tmp/probe_only47.py; echo '==='; python3 /tmp/codex-read47.py"