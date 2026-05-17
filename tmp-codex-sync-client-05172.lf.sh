#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" scp "${SSH_OPTS[@]}" client-agent/client_agent.py artifacts/versions.json "$CLIENT:/tmp/" >/dev/null
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
install -m 0755 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json /var/lib/leikwan-client-agent/self-leg-best-ip-forward-47.json 2>/dev/null || true
python3 - <<'PY'
import importlib.util,pathlib,hashlib
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py')
spec=importlib.util.spec_from_file_location('ca',str(p)); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
print('version',ca.AGENT_VERSION,'sha',hashlib.sha256(p.read_bytes()).hexdigest(),p.stat().st_size)
PY
systemctl restart leikwan-client-agent.service
sleep 45
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
python3 - <<'PY'
import json,pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
try:
 data=json.loads(p.read_text())
 print(json.dumps({k:data.get(k) for k in ['remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs']},ensure_ascii=False,indent=2))
 print('underlays',[(u.get('mode'),u.get('interface_name'),u.get('legs',[{}])[0].get('mptcp_remote_address')) for u in data.get('underlays',[])])
except Exception as e: print(type(e).__name__,e)
PY
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
journalctl -u leikwan-client-agent.service -u leikwan-client-47.service -n 140 --no-pager -o short-iso | egrep -i '47|self|wg|private|mptcp|restart|error|failed|selected|bootstrap' | tail -120 || true
REMOTE
