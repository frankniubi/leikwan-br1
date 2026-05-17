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
BASE=/tmp/codex-onepath-tune-5202
rm -rf "$BASE"; mkdir -p "$BASE"
make_token(){ TARGET="$1" python3 - <<'PY'
import os,base64,hashlib,hmac,json,time
secret=os.environ['SECRET'].encode(); target=int(os.environ['TARGET'])
payload={"target_host":"127.0.0.1","target_port":target,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
run_case(){
  local target=$1; local rtx=$2; local chunk=$3; local window=$4; local label="t${target}-r${rtx}-c${chunk}-w${window}"
  local ip=$((23600 + target % 100 + rtx/100)); local bp=$((11600 + target % 100 + rtx/100))
  echo "=== CASE $label $(date -Is) ingress=$ip bond=$bp ==="
  pkill -f "leikwan-ingress client --listen 127\\.0\\.0\\.1:${ip}" 2>/dev/null || true
  pkill -f "leikwan-bond client --protocol tcp --listen 127\\.0\\.0\\.1:${bp}" 2>/dev/null || true
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${ip} --remote "$SLAVE" --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/${label}.ingress" 2>&1 & echo $! >"$BASE/${label}.ingress.pid"
  for i in $(seq 1 50); do timeout .2 bash -c "</dev/tcp/127.0.0.1/$ip" >/dev/null 2>&1 && break || sleep .1; done
  TOKEN=$(make_token "$target")
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${bp} --legs "tcp://127.0.0.1:${ip}?priority=10&endpoint_id=317&display_host=47.116.104.15" --session-token "$TOKEN" --chunk-size "$chunk" --max-window-bytes "$window" --retransmit-ms "$rtx" --connect-timeout-ms 3000 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1048576 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 1800000 >"$BASE/${label}.bond" 2>&1 & echo $! >"$BASE/${label}.bond.pid"
  for i in $(seq 1 60); do ss -ltn | grep -q ":${bp} " && break || sleep .1; done
  sleep 1
  echo "-- upload --"
  timeout 18 iperf3 -c 127.0.0.1 -p "$bp" -b 300M -t 8 --connect-timeout 3000 >"$BASE/${label}.up" 2>&1; echo up_rc=$?; grep -E 'Mbits/sec|Gbits/sec|error|interrupt|connected|sender|receiver' "$BASE/${label}.up" || cat "$BASE/${label}.up"
  sleep 1
  echo "-- reverse --"
  timeout 18 iperf3 -c 127.0.0.1 -p "$bp" -R -b 300M -t 8 --connect-timeout 3000 >"$BASE/${label}.down" 2>&1; echo down_rc=$?; grep -E 'Mbits/sec|Gbits/sec|error|interrupt|connected|sender|receiver' "$BASE/${label}.down" || cat "$BASE/${label}.down"
  echo "-- bond markers --"; grep -E 'session established|recv_reset|local_read_failed|local_write_failed|entering cooldown|failed|WARN|ERROR|lifecycle close_timeout|upstream' "$BASE/${label}.bond" | tail -80 || true
  kill $(cat "$BASE/${label}.bond.pid" "$BASE/${label}.ingress.pid") 2>/dev/null || true
  sleep 2
}
run_case 5202 500 131072 8388608
run_case 5202 1500 65536 16777216
run_case 5202 2500 65536 33554432
run_case 5201 1500 65536 16777216
REMOTE