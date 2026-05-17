#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
cat > /tmp/dump_forward47_state.py <<'PY'
import json, pathlib
files = [
    '/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json',
    '/var/lib/leikwan-client-agent/config_pull_state.json',
    '/var/lib/leikwan-client-agent/applied.json',
    '/etc/leikwan/mptcp-forward-47.json',
    '/usr/local/bin/leikwan-client-start-47.sh',
]
for path in files:
    p = pathlib.Path(path)
    print(f'=== {path} exists={p.exists()} ===')
    if not p.exists():
        continue
    text = p.read_text(encoding='utf-8', errors='ignore')
    if path.endswith('applied.json'):
        data = json.loads(text)
        print(json.dumps(data.get('47', {}), ensure_ascii=False, indent=2))
    else:
        print(text)
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/dump_forward47_state.py root@114.80.33.50:/tmp/dump_forward47_state.py >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 /tmp/dump_forward47_state.py'