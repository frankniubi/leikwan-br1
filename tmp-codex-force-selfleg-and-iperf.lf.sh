#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
echo '=== stop agent and force one clean apply via current client_agent.py ==='
systemctl stop leikwan-client-agent.service || true
python3 - <<'PY'
import json, pathlib, urllib.request, importlib.util, sys, subprocess, time
cfgp=pathlib.Path('/etc/leikwan-client-agent/config.json')
cfg=json.loads(cfgp.read_text())
url=cfg['master_url'].rstrip('/') + '/api/client/configs/pull'
req=urllib.request.Request(url, headers={'Authorization':'Bearer '+cfg['agent_token'], 'User-Agent':'Leikwan-Client-Agent/2026.05.17.3', 'Accept':'application/json'})
with urllib.request.urlopen(req, timeout=30) as r:
 data=json.loads(r.read().decode())
f=next(x for x in data['forwards'] if int(x.get('forward_id',0) or 0)==47)
spec=importlib.util.spec_from_file_location('ca','/usr/local/bin/leikwan-client-agent.py')
ca=importlib.util.module_from_spec(spec); spec.loader.exec_module(ca)
agent=ca.ClientAgent(cfgp)
sel=agent.select_forward_candidates(json.loads(json.dumps(f)))
print('selected', [(x.get('candidate_kind'),x.get('endpoint_id'),x.get('measured_rtt_ms'),x.get('ready'),x.get('last_error'),x.get('selected_primary')) for x in sel[:4]])
agent.apply_forward(f)
time.sleep(3)
PY
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
ss -ltnp | egrep ':11035\b' || true
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
data=json.loads(p.read_text())
print(json.dumps({k:data.get(k) for k in ['remote_host','remote_port','relay_host','relay_port','subflow_limit','mptcp_endpoints','underlays']}, indent=2)[:12000])
PY
echo '=== wait service settle ==='
sleep 4
ss -ltnp | egrep ':11035\b' || true
echo '=== direct private WG iperf 10s ==='
iperf3 -c 10.204.53.42 -p 5201 -R -b 300M -t 10 --connect-timeout 5000 2>&1 || true
echo '=== forward 47 iperf 10s ==='
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 5000 2>&1 || true
echo '=== start agent after test ==='
systemctl start leikwan-client-agent.service || true
systemctl is-active leikwan-client-agent.service leikwan-client-47.service 2>/dev/null || true
REMOTE