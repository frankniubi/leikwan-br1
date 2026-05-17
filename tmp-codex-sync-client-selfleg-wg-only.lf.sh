#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" scp "${SSH_OPTS[@]}" client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py "$CLIENT:/tmp/" >/dev/null
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl stop leikwan-client-agent.service >/dev/null 2>&1 || true
install -m 0755 /tmp/client_agent.py /usr/local/bin/leikwan-client-agent.py
install -m 0755 /tmp/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
install -m 0755 /tmp/leikwan_mptcp_client.py /usr/local/bin/leikwan-mptcp-client.py
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py
rm -f /var/lib/leikwan-client-agent/self_leg_cf_best_ips.json /var/lib/leikwan-client-agent/self-leg-best-ip-forward-47.json 2>/dev/null || true
python3 - <<'PY'
import importlib.util, pathlib, hashlib
for q in ['/usr/local/bin/leikwan-client-agent.py','/usr/local/bin/install_forward_runtime.py','/usr/local/bin/leikwan-mptcp-client.py']:
 pp=pathlib.Path(q); print(q, hashlib.sha256(pp.read_bytes()).hexdigest(), pp.stat().st_size)
p=pathlib.Path('/usr/local/bin/leikwan-client-agent.py'); spec=importlib.util.spec_from_file_location('ca',str(p)); ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca); print('version', ca.AGENT_VERSION)
PY
systemctl enable --now leikwan-client-agent.service >/dev/null 2>&1 || systemctl restart leikwan-client-agent.service
sleep 45
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
python3 - <<'PY'
import json,pathlib
for p in ['/var/lib/leikwan-client-agent/config_pull_state.json','/var/lib/leikwan-client-agent/applied.json','/etc/leikwan/mptcp-forward-47.json']:
 print('---',p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  obj=data if p.endswith('mptcp-forward-47.json') else data.get('47', data)
  print(json.dumps(obj, ensure_ascii=False, indent=2)[:10000])
 except Exception as e: print(type(e).__name__, e)
PY
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
ss -ltnp | egrep ':(11035|8898|8899|2053|5201|14969)\b' || true
journalctl -u leikwan-client-agent.service -u leikwan-client-47.service -n 220 --no-pager -o short-iso | egrep -i '47|self|wg|wireguard|private|mptcp|restart|failed|error|selected|bootstrap|probe' | tail -180 || true
REMOTE