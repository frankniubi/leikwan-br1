#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +euo pipefail
ROUTE=07289d1f5ed25399
EDGE=${EDGE:-162.159.39.4}
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
BASE=/tmp/codex-slave317-bond-direct-now
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
echo "=== slave317 direct CF-bond test $(date -Is) host=$(hostname) edge=$EDGE ==="
for b in /usr/local/bin/wstunnel /usr/local/bin/leikwan-bond /var/lib/leikwan-client-agent/leikwan-mptcp-relay /usr/local/bin/leikwan-ingress; do echo ---$b; ls -l $b 2>/dev/null; $b --help 2>&1 | head -6 2>/dev/null || true; done
python3 - <<'PY'
import socket,time,os
edge=os.environ.get('EDGE','162.159.39.4')
vals=[]; errs=[]
for i in range(8):
 t=time.time()
 try:
  s=socket.create_connection((edge,2053),timeout=2); vals.append(round((time.time()-t)*1000,1)); s.close()
 except Exception as e: errs.append(repr(e))
 time.sleep(.08)
print('edge_tcp_ms',vals,'errs',errs[:3])
PY
if [ ! -x /usr/local/bin/leikwan-bond ]; then echo 'NO_BOND_ON_SLAVE'; exit 0; fi
for target in 22 5201; do
  lp_wst=$((36100+target%1000)); lp_bond=$((37100+target%1000))
  echo "=== target=$target wst=$lp_wst bond=$lp_bond ==="
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp_wst}:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst-$target.log" 2>&1 & echo $! >"$BASE/wst-$target.pid"
  for i in $(seq 1 40); do timeout .2 bash -lc "</dev/tcp/127.0.0.1/$lp_wst" >/dev/null 2>&1 && break || sleep .1; done
  TOKEN=$(TARGET=$target python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'; target=int(os.environ['TARGET'])
payload={"target_host":"127.0.0.1","target_port":target,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${lp_bond} --legs "tcp://127.0.0.1:${lp_wst}?priority=10&display_host=self_leg" --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 500 --connect-timeout-ms 3000 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1048576 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 5000 >"$BASE/bond-$target.log" 2>&1 & echo $! >"$BASE/bond-$target.pid"
  for i in $(seq 1 60); do timeout .2 bash -lc "</dev/tcp/127.0.0.1/$lp_bond" >/dev/null 2>&1 && break || sleep .1; done
  if [ "$target" = 22 ]; then
    python3 - "$lp_bond" <<'PY'
import socket,sys,time,statistics
lp=int(sys.argv[1]); vals=[]; errs=[]
for i in range(10):
 t=time.time(); data=b''
 try:
  s=socket.create_connection(('127.0.0.1',lp),timeout=3); s.settimeout(4); data=s.recv(96); s.close(); ms=(time.time()-t)*1000
  if data.startswith(b'SSH-'): vals.append(ms)
  print({'i':i+1,'ok':data.startswith(b'SSH-'),'ms':round(ms,1),'data':data[:40].decode('latin1','replace')})
 except Exception as e: errs.append(repr(e)); print({'i':i+1,'err':repr(e)})
 time.sleep(.2)
print('summary',{'min':round(min(vals),1) if vals else None,'avg':round(statistics.mean(vals),1) if vals else None,'vals':[round(v,1) for v in vals],'errs':errs[:3]})
PY
  else
    timeout 18 iperf3 -c 127.0.0.1 -p "$lp_bond" -R -b 300M -t 8 --connect-timeout 3000 >"$BASE/iperf-$target.out" 2>&1; echo iperf_rc=$?; cat "$BASE/iperf-$target.out"
  fi
  echo '-- wst key --'; grep -E 'Invalid status|failed to do websocket|Opening TCP|TLS handshake|Closing|ERROR|WARN' "$BASE/wst-$target.log" | tail -80 || true
  echo '-- bond key --'; grep -E 'session established|failed to connect initial|failed to establish|invalid bond frame magic|failed to read bond frame|recv_reset|local_read_failed|local_write_failed|cooldown|WARN|ERROR|send_stream_open|recv_stream_open_ack' "$BASE/bond-$target.log" | tail -160 || true
  kill $(cat "$BASE/bond-$target.pid" "$BASE/wst-$target.pid") 2>/dev/null || true
  sleep 1
done
REMOTE
