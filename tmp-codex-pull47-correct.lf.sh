#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=root@114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh -T "${SSH_OPTS[@]}" "$CLIENT" 'bash -s' <<'REMOTE'
set +e
python3 - <<'PY'
import json, pathlib, urllib.request, urllib.error
cfg=json.loads(pathlib.Path('/etc/leikwan-client-agent/config.json').read_text())
url=cfg['master_url'].rstrip() + '/api/client/configs/pull'
req=urllib.request.Request(url, headers={'Authorization':'Bearer '+cfg['agent_token'], 'User-Agent':'Codex-Debug/1.0'})
try:
 with urllib.request.urlopen(req, timeout=30) as r:
  data=json.loads(r.read().decode())
 print('ok', data.get('ok'), 'forwards', len(data.get('forwards',[])))
 for f in data.get('forwards',[]):
  if int(f.get('forward_id',0) or 0)==47:
   print(json.dumps(f, ensure_ascii=False, indent=2)[:50000])
except Exception as e:
 print(type(e).__name__, e)
 if hasattr(e,'read'):
  print(e.read().decode(errors='ignore')[:1000])
PY
REMOTE