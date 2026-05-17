set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-persistent-ssh-rtt; rm -rf "$BASE"; mkdir -p "$BASE"
ROUTE=07289d1f5ed25399; SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
EP=317; HOST=47.116.104.15; AUTH=85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:28640 --remote ${HOST}:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ing.log" 2>&1 & echo $! >"$BASE/ing.pid"
for i in $(seq 1 50); do python3 - <<'PY' >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',28640),timeout=.2); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={'target_host':'127.0.0.1','target_port':22,'target_protocol':'tcp','service_id':2,'forward_id':47,'issued_at':int(time.time())-30,'expires_at':int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
RUST_LOG=warn /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:28642 --legs 'tcp://127.0.0.1:28640?priority=10&endpoint_id=317&display_host=47.116.104.15' --session-token "$TOKEN" --connect-timeout-ms 3000 --join-timeout-ms 800 --leg-cooldown-ms 30000 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
for i in $(seq 1 80); do ss -ltn | grep -q ':28642 ' && break || sleep .1; done
sleep 1
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(20):
 t=time.time()
 try:
  s=socket.create_connection(('127.0.0.1',28642),timeout=1.0); s.settimeout(2.0); data=s.recv(64); s.close(); dt=(time.time()-t)*1000
  if data.startswith(b'SSH-'): vals.append(dt)
  else: errs.append('bad:'+repr(data[:20]))
 except Exception as e: errs.append(type(e).__name__+':'+str(e)[:60])
 time.sleep(.15)
print('PERSISTENT ep317 target22 ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:5])
PY
grep -E 'session established|invalid bond frame|failed to read bond frame|failed to connect|no bond legs|WARN|ERROR' "$BASE/bond.log" | tail -80 || true
REMOTE