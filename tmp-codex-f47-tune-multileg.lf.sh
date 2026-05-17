#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
for attempt in 1 2 3 4; do
 set +e
 sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
ROUTE='07289d1f5ed25399'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
cat >/tmp/codex-f47-endpoints.tsv <<'EOF'
316|47.116.11.144|2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
317|47.116.104.15|85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9
318|101.133.142.65|bd5eb739e2676216288eba117a8e893d767fe6f7edb2260a11a728f44f7e3307
319|47.116.134.3|48d684c9ff3c574205a556230f438faa3faf89f9113b4a28057d16dd7b792a53
320|8.133.170.123|a5216e9a1b67b4519013060c24e06ab3951755b32b68ee17f4122cceae637f72
EOF
make_token(){ TARGET=$1 SECRET=$SECRET python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode(); port=int(os.environ['TARGET'])
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
run_case(){
  name=$1; eps=$2; chunk=$3; window=$4; retrans=$5; cooldown=$6; extra=$7
  echo "=== CASE $name eps=$eps chunk=$chunk win=$window retrans=$retrans cooldown=$cooldown extra=$extra $(date -Is) ==="
  pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:27' 2>/dev/null || true
  pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:151' 2>/dev/null || true
  sleep .5
  legs=(); idx=0
  for ep in ${eps//,/ }; do
    line=$(grep "^$ep|" /tmp/codex-f47-endpoints.tsv); IFS='|' read -r ep2 host auth <<<"$line"
    port=$((27000 + idx)); idx=$((idx+1))
    /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${port} --remote ${host}:7000 --route-key "$ROUTE" --auth-token "$auth" > /tmp/codex-tune-${name}-${ep}.ingress 2>&1 &
    legs+=("tcp://127.0.0.1:${port}?priority=10&endpoint_id=${ep}&display_host=${host}")
  done
  for p in $(seq 27000 $((27000+idx-1))); do for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.25); s.close()
PY
  done; done
  listen=$((15100 + RANDOM % 500))
  token=$(make_token 5201)
  legs_csv=$(IFS=,; echo "${legs[*]}")
  RUST_LOG=warn /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${listen} --legs "$legs_csv" --session-token "$token" \
    --chunk-size "$chunk" --max-window-bytes "$window" --retransmit-ms "$retrans" --connect-timeout-ms 4000 --join-timeout-ms 1000 \
    --interactive-chunk-bytes 8192 --latency-guard-bytes 65536 --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 20 \
    --bulk-promote-bytes 131072 --leg-cooldown-ms "$cooldown" $extra > /tmp/codex-tune-${name}.bond 2>&1 &
  bpid=$!
  for i in $(seq 1 100); do ss -ltn | grep -q ":${listen} " && break || sleep .1; done
  sleep 2
  timeout 30 iperf3 -c 127.0.0.1 -p ${listen} -R -b 300M -t 12 --connect-timeout 3000 > /tmp/codex-tune-${name}.out 2>&1 || true
  python3 - <<PY
import re, pathlib
text=pathlib.Path('/tmp/codex-tune-${name}.out').read_text(errors='ignore')
vals=[float(m.group(1)) for line in text.splitlines() if (m:=re.search(r'\s([0-9.]+)\s+Mbits/sec', line)) and 'sender' not in line and 'receiver' not in line]
recv=None; send=None
for line in text.splitlines():
 if 'receiver' in line:
  m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line); recv=float(m.group(1)) if m else recv
 if 'sender' in line:
  m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line); send=float(m.group(1)) if m else send
print('SUMMARY $name vals', vals, 'avg', round(sum(vals)/len(vals),2) if vals else None, 'max', max(vals) if vals else None, 'send', send, 'recv', recv)
print(text[:1200])
PY
  echo 'key:'; grep -E 'session established|recv_reset|local_read_failed|local_write_failed|entering cooldown|failed to read bond frame|WARN|ERROR' /tmp/codex-tune-${name}.bond | tail -35 || true
  kill $bpid 2>/dev/null || true
  pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:27' 2>/dev/null || true
  sleep 2
}
run_case A_317318319320 317,318,319,320 65536 33554432 800 30000 ""
run_case B_318319320 318,319,320 65536 33554432 800 30000 ""
run_case C_316318319 316,318,319 65536 33554432 800 30000 ""
run_case D_319only 319 65536 8388608 800 30000 ""
run_case E_smallwin 317,318,319,320 32768 4194304 1000 30000 ""
run_case F_latencyfirst 317,318,319,320 65536 16777216 800 30000 "--latency-first"
REMOTE
 rc=$?
 set -e
 [ $rc -eq 0 ] && exit 0
 echo attempt=$attempt rc=$rc >&2
 sleep $((attempt*8))
done
exit 1
