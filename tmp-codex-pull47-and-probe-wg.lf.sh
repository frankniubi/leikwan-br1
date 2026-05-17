#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
python3 - <<'PY'
import json, pathlib, urllib.request
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
url=cfg['master_url'].rstrip('/') + '/api/client/pull-configs'
req=urllib.request.Request(url, headers={'Authorization':'Bearer '+cfg['agent_token'], 'User-Agent':'Codex-Debug/1.0'})
with urllib.request.urlopen(req, timeout=20) as r:
 data=json.loads(r.read().decode())
for f in data.get('forwards',[]):
 if int(f.get('forward_id',0) or 0)==47:
  print(json.dumps(f, ensure_ascii=False, indent=2)[:30000])
PY
printf '\n=== private wg reachability ===\n'
ping -c 5 -W 1 10.204.53.42 || true
python3 - <<'PY'
import socket, time
for host,port in [('10.204.53.42',8898),('10.204.53.42',8899),('10.204.53.42',5201),('10.203.201.187',8898),('10.203.201.179',8898)]:
 vals=[]; errs=[]
 for i in range(5):
  s=socket.socket(); s.settimeout(1.5); t=time.time()
  try:
   s.connect((host,port)); vals.append((time.time()-t)*1000)
  except Exception as e: errs.append(str(e))
  finally: s.close()
 print(host,port,'ok',len(vals),'ms', [round(v,1) for v in vals], 'errs', errs[:2])
PY
REMOTE