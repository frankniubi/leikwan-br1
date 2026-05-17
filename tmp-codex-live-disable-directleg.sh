#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'python3 - <<'"'"'PY'"'"'
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
j=json.loads(p.read_text())
print('before bond_mode', j.get('bond_mode'), 'direct_legs', len(j.get('direct_legs') or []), 'remote', j.get('remote_host'), j.get('remote_port'), 'relay', j.get('relay_host'), j.get('relay_port'))
j['bond_mode']=''
j['direct_legs']=[]
# use current healthy primary sidecar address from relay_host, not the local WST port
j['remote_host']=j.get('relay_host') or j.get('remote_host')
j['remote_port']=j.get('relay_port') or j.get('remote_port')
p.write_text(json.dumps(j, indent=2)+'\n')
print('after bond_mode', j.get('bond_mode'), 'direct_legs', len(j.get('direct_legs') or []), 'remote', j.get('remote_host'), j.get('remote_port'))
PY
systemctl restart leikwan-client-47.service
sleep 3
ss -ltnp | grep -E ":11035" || true
journalctl -u leikwan-client-47.service --since "-20 sec" --no-pager | tail -n 80
' 