#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47-onegood.service 2>/dev/null || true
# kill leaked probes on 11035 and old local ingress ports
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035' 2>/dev/null || true
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:2274' 2>/dev/null || true
pkill -f 'iperf3 -s -p 5201' 2>/dev/null || true
systemctl restart leikwan-client-47.service
sleep 4
echo "services agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true) f47=$(systemctl is-active leikwan-client-47.service) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|5201)\b' || true
echo '--- cfg summary ---'
python3 - <<'PY'
import json
j=json.load(open('/etc/leikwan/mptcp-forward-47.json'))
print({k:j.get(k) for k in ['remote_host','remote_port','relay_host','relay_port','bond_mode']})
print('direct_legs', j.get('direct_legs'))
print('endpoints', [(e.get('endpoint_id'),e.get('host'),e.get('port'),e.get('leg_role')) for e in j.get('endpoints',[])[:6]])
PY
echo '--- 3 banner probes target5201 expect iperf not banner (just connect/read) ---'
python3 - <<'PY'
import socket,time
for i in range(3):
 t=time.time(); err=''; data=b''
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=3); s.settimeout(3); data=s.recv(16); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 print(i, round((time.time()-t)*1000,1), data.hex(), err)
PY
echo '--- iperf reverse -b 300M -t 10 ---'
timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 3000 > /tmp/codex-f47-official-iperf.out 2>&1
rc=$?; echo rc=$rc; cat /tmp/codex-f47-official-iperf.out
echo '--- logs key ---'
journalctl -u leikwan-client-47.service --since '-2 min' --no-pager -o short-iso | grep -E 'client relay listening|accepted local|connected MPTCP|sent handshake|client connection error|splice|bond leg|invalid|failed|WARN|ERROR' | tail -180 || true
REMOTE
