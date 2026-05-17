#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=127.0.0.1
SIDEPORT=22
ROUTE='07289d1f5ed25399'
EDGE='162.159.39.4'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE ROUTE=$ROUTE bash -s" <<'REMOTE'
set +euo pipefail
EDGE=${EDGE:-162.159.39.4}
ROUTE=${ROUTE:-07289d1f5ed25399}
BASE=/tmp/codex-path-check-now
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
python3 - <<'PY'
import socket,time,statistics,os
edge=os.environ.get('EDGE')
vals=[]; errs=[]
for i in range(8):
 t=time.time()
 try:
  s=socket.create_connection((edge,2053),timeout=2); vals.append((time.time()-t)*1000); s.close()
 except Exception as e: errs.append(repr(e))
 time.sleep(.1)
print('edge_tcp_ms', [round(v,1) for v in vals], 'min', round(min(vals),1) if vals else None, 'avg', round(statistics.mean(vals),1) if vals else None, 'errs', errs[:3])
PY
for target in 22 8899 5201; do
 lp=$((38300+target%1000))
 echo "=== raw wst target=$target lp=$lp ==="
 /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst-$target.log" 2>&1 & echo $! >"$BASE/wst-$target.pid"
 for i in $(seq 1 60); do timeout .2 bash -c "</dev/tcp/127.0.0.1/$lp" >/dev/null 2>&1 && break || sleep .1; done
 if [ "$target" = 22 ]; then
  python3 - "$lp" <<'PY'
import socket,sys,time,statistics
lp=int(sys.argv[1]); vals=[]; errs=[]
for i in range(8):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',lp),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:24].decode('latin1','replace')))
 time.sleep(.15)
print('banner_ok',len(vals),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:4])
PY
 elif [ "$target" = 8899 ]; then
  python3 - "$lp" <<'PY'
import socket,sys,time,json,base64,hashlib,hmac
lp=int(sys.argv[1]); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
tok=b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest())
hs=json.dumps({"protocol":"tcp","session_token":tok,"forward_id":47,"bond_session_id":123456,"leg_priority":10,"leg_direct":False}).encode()
try:
 s=socket.create_connection(('127.0.0.1',lp),timeout=4); s.settimeout(4); s.sendall(len(hs).to_bytes(4,'big')+hs); t=time.time(); data=s.recv(16); print('bond_backend_first_bytes', data.hex(), data[:16]); s.close()
except Exception as e: print('bond_backend_err',repr(e))
PY
 else
  timeout 12 iperf3 -c 127.0.0.1 -p "$lp" -R -b 300M -t 5 --connect-timeout 3000 >"$BASE/iperf-$target.out" 2>&1; echo rc=$?; cat "$BASE/iperf-$target.out"
 fi
 echo '-- wst key --'; grep -E 'Opening TCP|TLS handshake|Invalid status|failed to do websocket|Closing|ERROR|WARN' "$BASE/wst-$target.log" | tail -80 || true
 kill $(cat "$BASE/wst-$target.pid") 2>/dev/null || true
 sleep 1
done
REMOTE