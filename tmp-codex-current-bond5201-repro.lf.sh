#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SLAVE='47.116.104.15:7000'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH=$AUTH SECRET=$SECRET SLAVE=$SLAVE bash -s" <<'REMOTE'
set +euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
BASE=/tmp/codex-bond5201-now
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:22901 --remote "$SLAVE" --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
for i in $(seq 1 60); do timeout .2 bash -c '</dev/tcp/127.0.0.1/22901' >/dev/null 2>&1 && break || sleep .1; done
TOKEN=$(python3 - <<'PY'
import os,base64,hashlib,hmac,json,time
secret=os.environ['SECRET'].encode(); payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:11101 --legs 'tcp://127.0.0.1:22901?priority=10&endpoint_id=317&display_host=47.116.104.15' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 500 --connect-timeout-ms 3000 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1048576 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 5000 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
for i in $(seq 1 60); do ss -ltn | grep -q ':11101 ' && break || sleep .1; done
sleep 1
echo "=== warmup one byte/bad connect ==="
python3 - <<'PY'
import socket,time
try:
 s=socket.create_connection(('127.0.0.1',11101),timeout=3); s.settimeout(3); s.sendall(b'X');
 try: print('recv',s.recv(16))
 except Exception as e: print('recv_err',repr(e))
 s.close()
except Exception as e: print('conn_err',repr(e))
PY
echo "=== iperf ==="
timeout 30 iperf3 -c 127.0.0.1 -p 11101 -R -b 300M -t 10 --connect-timeout 3000 >"$BASE/iperf.out" 2>&1; echo rc=$?; cat "$BASE/iperf.out"
echo "=== bond key ==="; grep -E 'bond leg connected|bond session|first leg|failed to connect initial|failed to read bond frame|invalid bond frame|recv_reset|local_read_failed|local_write_failed|cooldown|all bond|WARN|ERROR' "$BASE/bond.log" | tail -200 || cat "$BASE/bond.log"
echo "=== ingress key ==="; tail -100 "$BASE/ingress.log" || true
REMOTE