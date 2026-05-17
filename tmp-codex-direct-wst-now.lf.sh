#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
ROUTE=07289d1f5ed25399
EDGE=162.159.39.4
BASE=/tmp/codex-sidecar-direct-wst-now
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
echo "=== client direct wst to sidecar $(date -Is) ==="
for target in 22 5201 5202 8899; do
  lp=$((35000+target%10000))
  echo "--- target=$target lp=$lp ---"
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst-$target.log" 2>&1 & echo $! >"$BASE/wst-$target.pid"
  for i in $(seq 1 40); do timeout .2 bash -lc "</dev/tcp/127.0.0.1/$lp" >/dev/null 2>&1 && break || sleep .1; done
  if [ "$target" = 22 ]; then
    python3 - "$lp" <<'PY'
import socket,sys,time
lp=int(sys.argv[1])
try:
 s=socket.create_connection(('127.0.0.1',lp),timeout=4); s.settimeout(4); t=time.time(); data=s.recv(96); print({'ok':data.startswith(b'SSH-'),'ms':round((time.time()-t)*1000,1),'data':data[:64].decode('latin1','replace')}); s.close()
except Exception as e: print({'err':repr(e)})
PY
  elif [ "$target" = 8899 ]; then
    python3 - "$lp" <<'PY'
import socket,sys,time,base64,hashlib,hmac,json
lp=int(sys.argv[1]); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
tok=b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest())
hs=json.dumps({"protocol":"tcp","session_token":tok,"forward_id":47}).encode()
try:
 s=socket.create_connection(('127.0.0.1',lp),timeout=4); s.settimeout(4); s.sendall(len(hs).to_bytes(4,'big')+hs); t=time.time(); data=s.recv(96); print({'ok':data.startswith(b'SSH-'),'ms':round((time.time()-t)*1000,1),'data':data[:64].decode('latin1','replace')}); s.close()
except Exception as e: print({'err':repr(e)})
PY
  else
    timeout 15 iperf3 -c 127.0.0.1 -p "$lp" -R -b 300M -t 5 --connect-timeout 3000 >"$BASE/iperf-$target.out" 2>&1; echo rc=$?; cat "$BASE/iperf-$target.out"
  fi
  echo '-- wst key log --'; grep -E 'Invalid status|failed to do websocket|Opening TCP|TLS handshake|Closing|ERROR|WARN' "$BASE/wst-$target.log" | tail -60 || true
  kill $(cat "$BASE/wst-$target.pid") 2>/dev/null || true; sleep 1
done
REMOTE
