#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
for attempt in 1 2 3 4 5; do
  set +e
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SLAVE='47.116.104.15:7000'
make_token() {
  local port="$1"
  PORT="$port" SECRET="$SECRET" python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode(); port=int(os.environ['PORT'])
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
run_case() {
  local target="$1" listen="$2" ingress="$3" mode="$4"
  echo "=== CASE target=$target listen=$listen ingress=$ingress mode=$mode $(date -Is) ==="
  pkill -f "leikwan-ingress client --listen 127\.0\.0\.1:${ingress}" 2>/dev/null || true
  pkill -f "leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:${listen}" 2>/dev/null || true
  rm -f /tmp/codex-matrix-${target}.ingress.log /tmp/codex-matrix-${target}.bond.log /tmp/codex-matrix-${target}.out
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${ingress} --remote "$SLAVE" --route-key "$ROUTE" --auth-token "$AUTH" > /tmp/codex-matrix-${target}.ingress.log 2>&1 &
  ipid=$!
  for i in $(seq 1 50); do python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', $ingress), timeout=.2); s.close()
PY
  done
  TOKEN=$(make_token "$target")
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${listen} \
    --legs "tcp://127.0.0.1:${ingress}?priority=10&endpoint_id=317&display_host=47.116.104.15" \
    --session-token "$TOKEN" \
    --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 \
    --connect-timeout-ms 3000 --join-timeout-ms 800 \
    --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 \
    --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 20 \
    --bulk-promote-bytes 1048576 --leg-cooldown-ms 3000 > /tmp/codex-matrix-${target}.bond.log 2>&1 &
  bpid=$!
  for i in $(seq 1 80); do ss -ltn | grep -q ":${listen} " && break || sleep 0.1; done
  sleep 1
  if [ "$mode" = banner ]; then
    python3 - <<PY | tee /tmp/codex-matrix-${target}.out
import socket,time,statistics
vals=[]; errs=[]
for i in range(12):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',$listen),timeout=4); s.settimeout(4); data=s.recv(120); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:40].decode('latin1','replace')))
 time.sleep(.15)
print('banner_ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals], 'errs', errs[:5])
PY
  else
    timeout 22 iperf3 -c 127.0.0.1 -p "$listen" -R -b 300M -t 8 --connect-timeout 3000 > /tmp/codex-matrix-${target}.out 2>&1 || true
    cat /tmp/codex-matrix-${target}.out
  fi
  echo '--- bond log tail ---'; grep -E 'session established|recv_reset|failed|invalid bond frame magic|hello-ack|no active|WARN|ERROR|accepted' /tmp/codex-matrix-${target}.bond.log | tail -80 || tail -80 /tmp/codex-matrix-${target}.bond.log || true
  echo '--- ingress log tail ---'; tail -40 /tmp/codex-matrix-${target}.ingress.log || true
  kill $bpid $ipid 2>/dev/null || true
  sleep 1
}
run_case 22 11222 22822 banner
run_case 5201 11201 22801 iperf
run_case 5202 11202 22802 iperf
REMOTE
  rc=$?
  set -e
  if [ $rc -eq 0 ]; then exit 0; fi
  echo "ssh attempt $attempt rc=$rc" >&2
  sleep $((attempt*8))
done
exit 1
