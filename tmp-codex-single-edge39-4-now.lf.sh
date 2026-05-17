#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
BASE=/tmp/codex-selfleg-single-current; rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
ROUTE=07289d1f5ed25399; SNI=nrt1.nbwly.com; EDGE=162.159.39.4; SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
mk_token(){ python3 - "$1" <<'PY'
import base64,hashlib,hmac,json,time,sys
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'; port=int(sys.argv[1])
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
wait_port(){ local p=$1; for i in $(seq 1 80); do python3 - <<PY >/dev/null 2>&1 && return 0 || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.15); s.close()
PY
 done; return 1; }
free_port(){ python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
}
for target in 22 5201; do
  lp=$(free_port); bp=$(free_port); echo "=== edge=$EDGE target=$target wst=$lp bond=$bp $(date -Is) ==="
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst-$target.log" 2>&1 & echo $! >"$BASE/wst-$target.pid"
  wait_port "$lp" || true
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${bp} --legs "tcp://127.0.0.1:${lp}?priority=10&endpoint_id=0&display_host=self_leg&role=primary" --session-token "$(mk_token $target)" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 400 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1073741824 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 30000 --latency-first >"$BASE/bond-$target.log" 2>&1 & echo $! >"$BASE/bond-$target.pid"
  wait_port "$bp" || true
  sleep 1
  if [ "$target" = 22 ]; then
    python3 - "$bp" <<'PY'
import socket,time,statistics,sys
p=int(sys.argv[1]); vals=[]; errs=[]
for i in range(8):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',p),timeout=4); s.settimeout(4); data=s.recv(80); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:24].hex()))
 time.sleep(.1)
print('BANNER ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:3])
PY
  else
    echo '-- iperf reverse -b 300M -t 12 --'
    timeout 30 iperf3 -c 127.0.0.1 -p "$bp" -R -b 300M -t 12 --connect-timeout 3000 >"$BASE/iperf-r.out" 2>&1 || true
    cat "$BASE/iperf-r.out"
  fi
  echo '-- bond key --'; grep -E 'session established|connected|cooldown|local_read_failed|local_write_failed|WARN|ERROR|failed|reset' "$BASE/bond-$target.log" | tail -140 || true
  echo '-- wst key --'; grep -E 'Invalid status|websocket handshake|Opening TCP|TLS handshake|Closing|WARN|ERROR' "$BASE/wst-$target.log" | tail -140 || true
  kill $(cat "$BASE/bond-$target.pid") $(cat "$BASE/wst-$target.pid") 2>/dev/null || true
  sleep 2
done
REMOTE
