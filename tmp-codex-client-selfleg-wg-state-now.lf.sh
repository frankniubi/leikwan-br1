#!/usr/bin/env bash
set -euo pipefail
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
echo '=== client now ==='; date -Is; hostname
systemctl is-enabled leikwan-client-agent.service 2>/dev/null; systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
grep -m1 AGENT_VERSION /usr/local/bin/leikwan-client-agent.py 2>/dev/null || true
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py 2>/dev/null || true
ss -ltnup | egrep ':(11035|14969|8898|8899|2053|5201)\b' || true
echo '-- wg --'; wg show interfaces 2>/dev/null || true; wg show lw-self-47 2>/dev/null || true; ip -4 addr show dev lw-self-47 2>/dev/null || true
python3 - <<'PY'
import json, pathlib
for p in ['/var/lib/leikwan-client-agent/config_pull_state.json','/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/applied.json','/var/lib/leikwan-client-agent/applied-forwards.json','/var/lib/leikwan-client-agent/agent_update_state.json']:
 print('---',p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  print(json.dumps(data if 'mptcp-forward-47' in p else data.get('47', data), ensure_ascii=False, indent=2)[:10000])
 except Exception as e: print(type(e).__name__, e)
PY
echo '-- logs --'; journalctl -u leikwan-client-agent.service -u leikwan-client-47.service -n 220 --no-pager -o short-iso | egrep -i '47|self|wg|wireguard|mptcp|restart|failed|error|selected|private|direct|probe|update' | tail -180 || true
REMOTE
