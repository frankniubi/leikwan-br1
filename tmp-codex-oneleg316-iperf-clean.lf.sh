#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-oneleg-316-iperf-clean
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
pkill -f 'iperf3.*5201' 2>/dev/null || true
pkill -f 'leikwan-ingress client --listen 127.0.0.1:29460' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127.0.0.1:29461' 2>/dev/null || true
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29460 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
sleep .4
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29461 --legs 'tcp://127.0.0.1:29460?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 800 --bulk-promote-bytes 1048576 --leg-cooldown-ms 1000 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
sleep 1
for i in $(seq 1 20); do if python3 - <<'PY' >/dev/null 2>&1
import socket
s=socket.create_connection(('127.0.0.1',29461),timeout=.5); s.close()
PY
then break; fi; sleep .2; done
echo '=== short connect ==='
python3 - <<'PY'
import socket,time
for i in range(3):
 t=time.time()
 try:
  s=socket.create_connection(('127.0.0.1',29461),timeout=2); s.close(); print('ok',i,round((time.time()-t)*1000,1))
 except Exception as e: print('err',i,repr(e))
PY
echo '=== iperf reverse 300M 12s ==='
timeout 24 iperf3 -c 127.0.0.1 -p 29461 -R -b 300M -t 12 --connect-timeout 3000 --json >"$BASE/iperf.json" 2>"$BASE/iperf.err" || true
cat "$BASE/iperf.err" || true
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/tmp/codex-oneleg-316-iperf-clean/iperf.json')
text=p.read_text(errors='ignore') if p.exists() else ''
print(text[:500] if text and not text.lstrip().startswith('{') else '')
try:
 d=json.loads(text)
 print({'error':d.get('error'), 'seconds': d.get('end',{}).get('sum_received',{}).get('seconds'), 'bps_recv': d.get('end',{}).get('sum_received',{}).get('bits_per_second'), 'bps_sent': d.get('end',{}).get('sum_sent',{}).get('bits_per_second')})
 for interval in d.get('intervals',[])[:20]:
  s=interval.get('sum',{})
  print('interval',s.get('start'),s.get('end'),s.get('bits_per_second'),s.get('retransmits'))
except Exception as e:
 print('json_parse_err',repr(e),'len',len(text)); print(text[-1000:])
PY
echo '=== bond warnings ==='; grep -E 'session established|failed|reset|invalid|timeout|ERROR|WARN|bench|cooldown|local_write_failed' "$BASE/bond.log" | tail -160 || true
echo '=== ingress tail ==='; tail -80 "$BASE/ingress.log" || true
REMOTE