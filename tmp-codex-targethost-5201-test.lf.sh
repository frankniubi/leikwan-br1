#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SLAVE='47.116.104.15:7000'
SIDE='185.220.238.143'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH=$AUTH SECRET=$SECRET SLAVE=$SLAVE SIDE=$SIDE bash -s" <<'REMOTE'
set +euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
BASE=/tmp/codex-targethost-5201-test
rm -rf "$BASE"; mkdir -p "$BASE"
make_token(){ THOST="$1" TPORT="$2" python3 - <<'PY'
import os,base64,hashlib,hmac,json,time
secret=os.environ['SECRET'].encode(); target=int(os.environ['TPORT']); th=os.environ['THOST']
payload={"target_host":th,"target_port":target,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
run_case(){
  local thost="$1"; local tport="$2"; local label="${thost//./_}_${tport}"; local lp=$3; local ip=$4
  echo "=== CASE target=$thost:$tport listen=$lp ingress=$ip $(date -Is) ==="
  pkill -f "leikwan-bond client --protocol tcp --listen 127\\.0\\.0\\.1:${lp}" 2>/dev/null || true
  pkill -f "leikwan-ingress client --listen 127\\.0\\.0\\.1:${ip}" 2>/dev/null || true
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${ip} --remote "$SLAVE" --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/${label}.ingress" 2>&1 & echo $! >"$BASE/${label}.ingress.pid"
  for i in $(seq 1 50); do ss -ltn | grep -q ":${ip} " && break || sleep .1; done
  TOKEN=$(make_token "$thost" "$tport")
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${lp} --legs "tcp://127.0.0.1:${ip}?priority=10&endpoint_id=317&display_host=47.116.104.15" --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 500 --connect-timeout-ms 3000 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1048576 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 1800000 >"$BASE/${label}.bond" 2>&1 & echo $! >"$BASE/${label}.bond.pid"
  for i in $(seq 1 60); do ss -ltn | grep -q ":${lp} " && break || sleep .1; done
  sleep 1
  timeout 25 iperf3 -c 127.0.0.1 -p "$lp" -R -b 300M -t 8 --connect-timeout 3000 >"$BASE/${label}.iperf" 2>&1; echo rc=$?
  grep -E 'connected|Mbits/sec|Gbits/sec|sender|receiver|error|interrupt' "$BASE/${label}.iperf" || cat "$BASE/${label}.iperf"
  echo '-- markers --'; grep -E 'session established|send_stream_open|recv_stream_open_ack|recv_reset|local_read_failed|local_write_failed|upstream|failed|WARN|ERROR|cooldown' "$BASE/${label}.bond" | tail -80 || true
  kill $(cat "$BASE/${label}.bond.pid" "$BASE/${label}.ingress.pid") 2>/dev/null || true
  sleep 2
}
run_case 127.0.0.1 5201 11901 23901
run_case "$SIDE" 5201 11902 23902
REMOTE
