#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SLAVE='47.116.104.15:7000'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH=$AUTH SECRET=$SECRET SLAVE=$SLAVE bash -s" <<'REMOTE'
set +euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
BASE=/tmp/codex-now-5201-repro
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
make_token(){ python3 - <<'PY'
import os,base64,hashlib,hmac,json,time
secret=os.environ['SECRET'].encode(); target=int(os.environ.get('TARGET','5201'))
payload={"target_host":"127.0.0.1","target_port":target,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:23801 --remote "$SLAVE" --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
for i in $(seq 1 50); do timeout .2 bash -c '</dev/tcp/127.0.0.1/23801' >/dev/null 2>&1 && break || sleep .1; done
TOKEN=$(TARGET=5201 make_token)
RUST_LOG=debug /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:11801 --legs 'tcp://127.0.0.1:23801?priority=10&endpoint_id=317&display_host=47.116.104.15' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 500 --connect-timeout-ms 3000 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1048576 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 1800000 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
for i in $(seq 1 60); do ss -ltn | grep -q ':11801 ' && break || sleep .1; done
sleep 1
echo '=== quick tcp banner through sidecar 5201 should be iperf not banner ==='
timeout 4 bash -c '</dev/tcp/127.0.0.1/11801'; echo tcp_open_rc=$?
echo '=== iperf reverse ==='
timeout 35 iperf3 -c 127.0.0.1 -p 11801 -R -b 300M -t 12 --connect-timeout 3000 >"$BASE/iperfR.out" 2>&1; echo iperf_rc=$?
cat "$BASE/iperfR.out"
echo '=== bond log tail ==='; grep -E 'session established|send_stream_open|recv_stream_open_ack|local_|recv_reset|failed|WARN|ERROR|cooldown|upstream|close_timeout' "$BASE/bond.log" | tail -220 || tail -220 "$BASE/bond.log"
echo '=== ingress log ==='; tail -120 "$BASE/ingress.log"
REMOTE
