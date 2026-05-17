#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== client $(date -Is) ==="
echo agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null)
for u in leikwan-client-47.service leikwan-client-47-onegood.service; do echo "$u $(systemctl is-active $u 2>/dev/null)/$(systemctl is-enabled $u 2>/dev/null) pid=$(systemctl show -p MainPID --value $u 2>/dev/null)"; done
echo '-- processes --'; ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-bond client|leikwan-ingress client|11035|2274[0-9]' | grep -v grep || true
echo '-- listeners --'; ss -ltnp | grep -E ':(11035|2274[0-9]|32501)\b' || true
echo '-- f47 current runtime json --'; python3 - <<'PY'
import json, os
for p in ['/etc/leikwan/mptcp-forward-47.json','/var/lib/leikwan-client-agent/forward-47.json','/var/lib/leikwan-client-agent/bond-leg-health-forward-47.json','/run/leikwan/bond-leg-health-forward-47.json']:
 print('---',p)
 if os.path.exists(p):
  try: print(json.dumps(json.load(open(p)),ensure_ascii=False,indent=2)[:4000])
  except Exception as e: print('err',e,open(p,errors='replace').read()[:2000])
 else: print('missing')
PY
echo '-- onegood log counts 2h --'
python3 - <<'PY'
import subprocess
out=subprocess.run(['journalctl','-u','leikwan-client-47-onegood.service','--since','-2 hours','--no-pager','-o','cat'],text=True,capture_output=True).stdout
for pat in ['invalid bond frame magic','failed to read bond frame header','timed out waiting for bond hello-ack','no bond legs acknowledged','bond client TCP transport session established','entering cooldown','local read failed','local write failed','recv_reset','failed to connect initial bond leg']:
 print(f'{pat}={out.count(pat)}')
print('-- tail key --')
for line in out.splitlines()[-300:]:
 if any(p in line for p in ['invalid bond frame magic','failed to read bond frame header','timed out waiting','no bond legs','session established','entering cooldown','local read failed','recv_reset','failed to connect initial']): print(line)
PY
REMOTE
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "=== slave317 $(date -Is) ==="
for u in wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service leikwan-slave-agent.service; do echo "$u $(systemctl is-active $u 2>/dev/null)/$(systemctl is-enabled $u 2>/dev/null) pid=$(systemctl show -p MainPID --value $u 2>/dev/null)"; done
echo '-- process/listeners --'; ss -ltnp | grep -E ':(7000|7002|7329)\b' || true; ps -eo pid,ppid,etimes,cmd | grep -E 'wstunnel-svc-2-317|wstunnel client|leikwan-ingress' | grep -v grep || true
echo '-- metadata --'; python3 - <<'PY'
import json,pathlib
for p in ['/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json','/var/lib/leikwan-slave/routes-7000.json']:
 print('---',p)
 q=pathlib.Path(p)
 if q.exists():
  try: print(json.dumps(json.loads(q.read_text()),ensure_ascii=False,indent=2)[:3500])
  except Exception as e: print('err',e,q.read_text(errors='replace')[:1200])
 else: print('missing')
PY
echo '-- wst log counts 2h --'
python3 - <<'PY'
import subprocess
out=subprocess.run(['journalctl','-u','wstunnel-svc-2-317.service','-u','leikwan-tcp-mux-7000.service','--since','-2 hours','--no-pager','-o','cat'],text=True,capture_output=True).stdout
for pat in ['Invalid status code: 520','failed to do websocket handshake','Opening TCP connection','Closing local','empty response line','failed','ERROR','WARN']:
 print(f'{pat}={out.count(pat)}')
print('-- tail key --')
for line in out.splitlines()[-260:]:
 if any(p in line for p in ['Invalid status code','failed to do websocket','Opening TCP','Closing local','empty response','failed','ERROR','WARN','8899','5201']): print(line)
PY
REMOTE