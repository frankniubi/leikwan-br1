#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
ROUTE='07289d1f5ed25399'
AUTH='2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
REMOTEHOST='47.116.11.144'
EP=316
make_token(){ local port=$1; PORT=$port SECRET=$SECRET python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode(); port=int(os.environ['PORT'])
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
run_case(){
  name=$1; shift
  lp=$((15000 + RANDOM % 10000)); ip=$((25000 + RANDOM % 10000))
  echo "=== CASE $name listen=$lp ingress=$ip $(date -Is) ==="
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:$ip --remote ${REMOTEHOST}:7000 --route-key "$ROUTE" --auth-token "$AUTH" >/tmp/case-$name-ingress.log 2>&1 & ipid=$!
  for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$ip),timeout=.2); s.close()
PY
  done
  TOKEN=$(make_token 5201)
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$lp --legs "tcp://127.0.0.1:$ip?priority=10&endpoint_id=${EP}&display_host=${REMOTEHOST}" --session-token "$TOKEN" "$@" >/tmp/case-$name-bond.log 2>&1 & bpid=$!
  for i in $(seq 1 80); do ss -ltn | grep -q ":$lp " && break || sleep .1; done
  sleep 1
  timeout 28 iperf3 -c 127.0.0.1 -p $lp -R -b 300M -t 10 --connect-timeout 3000 >/tmp/case-$name-iperf.log 2>&1
  rc=$?
  echo rc=$rc
  python3 - <<PY
import re, pathlib
text=pathlib.Path('/tmp/case-$name-iperf.log').read_text(errors='ignore')
vals=[]
for line in text.splitlines():
 m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line)
 if m and 'sender' not in line and 'receiver' not in line: vals.append(float(m.group(1)))
print('vals', vals, 'max', max(vals) if vals else None, 'avg', round(sum(vals)/len(vals),2) if vals else None)
print(text[:1800])
PY
  echo '--- key bond ---'
  grep -E 'session established|recv_reset|local_read_failed|failed to connect upstream|entering cooldown|failed to read bond frame header|invalid bond frame magic|no bond legs|WARN|ERROR' /tmp/case-$name-bond.log | tail -80 || true
  kill $bpid $ipid 2>/dev/null || true
  sleep 2
}
run_case baseline --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 600 --connect-timeout-ms 3500 --join-timeout-ms 800 --interactive-chunk-bytes 8192 --latency-guard-bytes 65536 --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 30 --bulk-promote-bytes 262144 --leg-cooldown-ms 1000
run_case conservative --chunk-size 16384 --max-window-bytes 1048576 --retransmit-ms 1000 --connect-timeout-ms 3500 --join-timeout-ms 800 --interactive-chunk-bytes 4096 --latency-guard-bytes 32768 --warmup-trigger-bytes 1073741824 --warmup-slice-bytes 16384 --warmup-leg-delay-ms 100 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 30000
run_case bigwindow --chunk-size 65536 --max-window-bytes 33554432 --retransmit-ms 300 --connect-timeout-ms 3500 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1073741824 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 30000
REMOTE
