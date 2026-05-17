#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
echo '=== freeze agent for clean data-plane test ==='
systemctl stop leikwan-client-agent.service || true
sleep 2
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
echo '=== route/state ==='
ip route get 10.204.53.42 2>/dev/null || true
ss -ltnp | egrep ':11035\b' || true
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
data=json.loads(p.read_text())
print(json.dumps({k:data.get(k) for k in ['remote_host','remote_port','relay_host','relay_port','subflow_limit','mptcp_endpoints','underlays']}, indent=2)[:12000])
PY
echo '=== quick tcp probes ==='
python3 - <<'PY'
import socket,time
for host,port in [('10.204.53.42',8898),('10.204.53.42',5201),('127.0.0.1',11035)]:
 vals=[]; errs=[]
 for i in range(3):
  s=socket.socket(); s.settimeout(2); t=time.time()
  try: s.connect((host,port)); vals.append(round((time.time()-t)*1000,1))
  except Exception as e: errs.append(str(e))
  finally: s.close()
 print(host,port,'ok',len(vals),'ms',vals,'errs',errs)
PY
echo '=== iperf reverse 300M 15s ==='
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 15 --connect-timeout 5000 2>&1 || true
echo '=== post logs ==='
journalctl -u leikwan-client-47.service -n 120 --no-pager -o short-iso | tail -120 || true
echo '=== restart agent ==='
systemctl start leikwan-client-agent.service || true
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
REMOTE