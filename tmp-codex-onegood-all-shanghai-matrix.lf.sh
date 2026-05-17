#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
CPASS='Frank20021212.'
ROUTE='07289d1f5ed25399'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
TMP=/tmp/codex-f47-endpoints.tsv
: > "$TMP"
remote_cmd=$(cat <<'RCMD'
python3 - <<'PY'
import json, pathlib
for p in pathlib.Path('/var/lib/leikwan-slave/services').glob('wstunnel-svc-2-*.json'):
    try:
        d=json.loads(p.read_text())
    except Exception:
        continue
    if int(d.get('forward_id') or 0)==47 and d.get('status')=='active':
        print('|'.join(str(x or '') for x in [d.get('endpoint_id'), d.get('service_name'), d.get('ingress_auth_token'), d.get('selected_cf_ip'), d.get('backend_port')]))
PY
RCMD
)
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
  host=${host%$'\r'}; user=${user%$'\r'}; pass=${pass%$'\r'}
  [ -n "${host:-}" ] || continue
  line=$(sshpass -p "$pass" ssh -n "${SSH_OPTS[@]}" "$user@$host" "$remote_cmd" 2>/dev/null | tail -1 || true)
  if [ -n "$line" ]; then
    ep=${line%%|*}
    echo "$ep|$host|$line" >> "$TMP"
  else
    echo "missing_endpoint_for=$host" >&2
  fi
done < shanghais.txt
sort -n "$TMP" -o "$TMP"
echo '=== endpoint data ==='
cat "$TMP"
[ -s "$TMP" ] || { echo 'no endpoints collected' >&2; exit 1; }
for attempt in 1 2 3 4; do
  set +e
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'cat >/tmp/codex-f47-endpoints.tsv' < "$TMP"
  rc=$?
  set -e
  [ $rc -eq 0 ] && break
  echo "upload attempt $attempt failed rc=$rc" >&2
  sleep $((attempt*8))
  [ $attempt -eq 4 ] && exit $rc
done
for attempt in 1 2 3 4; do
  set +e
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE SECRET=$SECRET bash -s" <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
ROUTE=${ROUTE:?}
SECRET=${SECRET:?}
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
run_one() {
  local ep="$1" host="$2" service="$3" auth="$4" edge="$5" backend="$6" target="$7" mode="$8"
  local add=0; [ "$target" = 5201 ] && add=2000
  local listen=$((12000 + (ep % 1000) + add))
  local ingress=$((23000 + (ep % 1000) + add))
  echo "=== ep=$ep host=$host edge=$edge backend=$backend target=$target mode=$mode $(date -Is) ==="
  pkill -f "leikwan-ingress client --listen 127\.0\.0\.1:${ingress}" 2>/dev/null || true
  pkill -f "leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:${listen}" 2>/dev/null || true
  rm -f /tmp/codex-mx-${ep}-${target}.ingress /tmp/codex-mx-${ep}-${target}.bond /tmp/codex-mx-${ep}-${target}.out
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${ingress} --remote ${host}:7000 --route-key "$ROUTE" --auth-token "$auth" > /tmp/codex-mx-${ep}-${target}.ingress 2>&1 &
  ipid=$!
  for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1',$ingress),timeout=.25); s.close()
PY
  done
  TOKEN=$(make_token "$target")
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${listen} \
    --legs "tcp://127.0.0.1:${ingress}?priority=10&endpoint_id=${ep}&display_host=${host}" \
    --session-token "$TOKEN" \
    --chunk-size 65536 --max-window-bytes 8388608 --retransmit-ms 600 \
    --connect-timeout-ms 3500 --join-timeout-ms 800 \
    --interactive-chunk-bytes 8192 --latency-guard-bytes 65536 \
    --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 30 \
    --bulk-promote-bytes 262144 --leg-cooldown-ms 1000 > /tmp/codex-mx-${ep}-${target}.bond 2>&1 &
  bpid=$!
  for i in $(seq 1 80); do ss -ltn | grep -q ":${listen} " && break || sleep 0.1; done
  sleep 1
  if [ "$mode" = banner ]; then
    python3 - <<PY | tee /tmp/codex-mx-${ep}-${target}.out
import socket,time,statistics
vals=[]; errs=[]
for i in range(10):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',$listen),timeout=4); s.settimeout(4); data=s.recv(80); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:16].hex()))
 time.sleep(.12)
print('RESULT ep=$ep target=$target ok=%d fail=%d min=%s avg=%s vals=%s errs=%s' % (len(vals),len(errs),round(min(vals),1) if vals else None,round(statistics.mean(vals),1) if vals else None,[round(v,1) for v in vals],errs[:3]))
PY
  else
    timeout 18 iperf3 -c 127.0.0.1 -p "$listen" -R -b 300M -t 6 --connect-timeout 3000 > /tmp/codex-mx-${ep}-${target}.out 2>&1 || true
    python3 - <<PY
import re, pathlib
text=pathlib.Path('/tmp/codex-mx-${ep}-${target}.out').read_text(errors='ignore')
vals=[]
for line in text.splitlines():
    m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line)
    if m and 'sender' not in line and 'receiver' not in line:
        vals.append(float(m.group(1)))
print('RESULT ep=$ep target=$target intervals', vals, 'max', max(vals) if vals else None, 'avg', round(sum(vals)/len(vals),2) if vals else None)
print(text[:1400])
PY
  fi
  echo '--- key bond ---'
  grep -E 'session established|recv_reset|local_read_failed|failed to connect upstream|entering cooldown|failed to read bond frame header|invalid bond frame magic|no bond legs|WARN|ERROR' /tmp/codex-mx-${ep}-${target}.bond | tail -40 || true
  kill $bpid $ipid 2>/dev/null || true
  sleep 1
}
while IFS='|' read -r ep host ep2 service auth edge backend; do
  [ -n "$ep" ] || continue
  run_one "$ep" "$host" "$service" "$auth" "$edge" "$backend" 22 banner
  run_one "$ep" "$host" "$service" "$auth" "$edge" "$backend" 5201 iperf
done < /tmp/codex-f47-endpoints.tsv
REMOTE
  rc=$?
  set -e
  [ $rc -eq 0 ] && exit 0
  echo "client matrix attempt $attempt failed rc=$rc" >&2
  sleep $((attempt*10))
done
exit 1
