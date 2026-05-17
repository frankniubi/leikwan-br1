#!/usr/bin/env bash
set -euo pipefail
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
sleep 65
echo "=== services $(date -Is) ==="
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
grep -m1 AGENT_VERSION /usr/local/bin/leikwan-client-agent.py || true
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py /var/lib/leikwan-client-agent/leikwan-mptcp-relay 2>/dev/null || true
echo "=== listeners ==="
ss -ltnp | egrep ':(11035|8898|8899|2053|14969|5201|5202)\b' || true
echo "=== wg ==="
wg show interfaces 2>/dev/null || true
wg show lw-self-47 2>/dev/null || true
ip -4 addr show dev lw-self-47 2>/dev/null || true
ip route get 10.204.53.42 2>/dev/null || true
echo "=== json ==="
python3 - <<'PY'
import json, pathlib
for p in ['/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/config_pull_state.json','/var/lib/leikwan-client-agent/applied.json','/var/lib/leikwan-client-agent/agent_update_state.json','/var/lib/leikwan-client-agent/self_leg_cf_best_ips.json']:
 print('---',p)
 try:
  data=json.loads(pathlib.Path(p).read_text())
  obj=data if 'mptcp-forward-47' in p or 'self_leg' in p or 'agent_update' in p else data.get('47', data)
  print(json.dumps(obj, ensure_ascii=False, indent=2)[:12000])
 except Exception as e: print(type(e).__name__, e)
PY
echo "=== probe private wg target ==="
python3 - <<'PY'
import socket,time
for host,port in [('10.204.53.42',8898),('10.204.53.42',8899),('185.220.238.143',8898),('185.220.238.143',8899)]:
 vals=[]; errs=[]
 for i in range(3):
  t=time.time()
  try:
   s=socket.create_connection((host,port),timeout=2); vals.append(round((time.time()-t)*1000,1)); s.close()
  except Exception as e: errs.append(type(e).__name__+':'+str(e))
  time.sleep(.2)
 print(host,port,'vals',vals,'errs',errs[:2])
PY
echo "=== journal tail ==="
journalctl -u leikwan-client-agent.service -u leikwan-client-47.service -n 260 --no-pager -o short-iso | egrep -i '47|self|wg|wireguard|private|mptcp|restart|failed|error|selected|bootstrap|probe|running kernel|applied forward' | tail -220 || true
REMOTE