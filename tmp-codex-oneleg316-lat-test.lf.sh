set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-oneleg-316-lat
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29160 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
sleep .5
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29161 --legs 'tcp://127.0.0.1:29160?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 800 --bulk-promote-bytes 1048576 --leg-cooldown-ms 1000 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
sleep 1
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(10):
    t=time.time()
    try:
        s=socket.create_connection(('127.0.0.1',29161),timeout=3); s.settimeout(3)
        data=s.recv(64)
        dt=(time.time()-t)*1000
        vals.append(dt)
        print('try',i,'ms',round(dt,1),'data',data[:32].decode('latin1','replace'))
        s.close()
    except Exception as e:
        errs.append(repr(e)); print('try',i,'err',repr(e))
    time.sleep(.2)
print('summary', {'count':len(vals),'min':round(min(vals),1) if vals else None,'avg':round(statistics.mean(vals),1) if vals else None,'errs':errs[:3]})
PY
echo '=== bond tail ==='; tail -100 "$BASE/bond.log" | sed -n '1,100p'
REMOTE