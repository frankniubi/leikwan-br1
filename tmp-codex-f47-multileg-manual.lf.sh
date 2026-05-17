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
cat >/tmp/codex-f47-endpoints.tsv <<'EOF'
316|47.116.11.144|2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
317|47.116.104.15|85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9
318|101.133.142.65|bd5eb739e2676216288eba117a8e893d767fe6f7edb2260a11a728f44f7e3307
319|47.116.134.3|48d684c9ff3c574205a556230f438faa3faf89f9113b4a28057d16dd7b792a53
320|8.133.170.123|a5216e9a1b67b4519013060c24e06ab3951755b32b68ee17f4122cceae637f72
EOF
ROUTE='07289d1f5ed25399'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
TARGET=5201
LISTEN=15047
BASE=26000
# clean previous
for p in $(seq 26016 26020); do pkill -f "leikwan-ingress client --listen 127\.0\.0\.1:${p}" 2>/dev/null || true; done
pkill -f "leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:${LISTEN}" 2>/dev/null || true
rm -f /tmp/codex-f47-multileg.*
legs=()
while IFS='|' read -r ep host auth; do
  port=$((BASE + ep - 300))
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${port} --remote ${host}:7000 --route-key "$ROUTE" --auth-token "$auth" > /tmp/codex-f47-multileg.ingress-${ep}.log 2>&1 &
  legs+=("tcp://127.0.0.1:${port}?priority=10&endpoint_id=${ep}&display_host=${host}")
done </tmp/codex-f47-endpoints.tsv
for p in $(seq 26016 26020); do
  for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.25); s.close()
PY
  done
done
TOKEN=$(TARGET=$TARGET SECRET=$SECRET python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode(); port=int(os.environ['TARGET'])
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
legs_csv=$(IFS=,; echo "${legs[*]}")
echo "legs=$legs_csv"
RUST_LOG=info LEIKWAN_BOND_FORWARD_ID=47 LEIKWAN_BOND_OBSERVER_ID=3 LEIKWAN_BOND_SNAPSHOT_PATH=/tmp/codex-f47-multileg-health.json /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
  --legs "$legs_csv" --session-token "$TOKEN" \
  --chunk-size 65536 --max-window-bytes 33554432 --retransmit-ms 800 \
  --connect-timeout-ms 4000 --join-timeout-ms 1000 \
  --interactive-chunk-bytes 8192 --latency-guard-bytes 65536 \
  --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 20 \
  --bulk-promote-bytes 131072 --leg-cooldown-ms 30000 > /tmp/codex-f47-multileg.bond.log 2>&1 &
bpid=$!
for i in $(seq 1 100); do ss -ltn | grep -q ":${LISTEN} " && break || sleep 0.1; done
sleep 2
echo "=== multileg listener ==="; ss -ltnp | grep -E ":(${LISTEN}|2601[6-9]|26020)" || true
echo "=== iperf reverse multileg 15s -b 300M ==="
timeout 35 iperf3 -c 127.0.0.1 -p ${LISTEN} -R -b 300M -t 15 --connect-timeout 3000 > /tmp/codex-f47-multileg-iperfR.out 2>&1
rc=$?; echo iperf_rc=$rc; cat /tmp/codex-f47-multileg-iperfR.out
echo "=== iperf upload multileg 10s -b 300M ==="
timeout 25 iperf3 -c 127.0.0.1 -p ${LISTEN} -b 300M -t 10 --connect-timeout 3000 > /tmp/codex-f47-multileg-iperfU.out 2>&1
rc=$?; echo iperfU_rc=$rc; cat /tmp/codex-f47-multileg-iperfU.out
echo "=== bond log key ==="; grep -E 'session established|added leg|recv_reset|local_read_failed|failed to connect upstream|entering cooldown|failed to read bond frame header|invalid bond frame magic|no bond legs|WARN|ERROR|stage' /tmp/codex-f47-multileg.bond.log | tail -240 || true
echo "=== health ==="; cat /tmp/codex-f47-multileg-health.json 2>/dev/null || true
kill $bpid 2>/dev/null || true
pkill -f "leikwan-ingress client --listen 127\.0\.0\.1:260" 2>/dev/null || true
REMOTE
  rc=$?
  set -e
  [ $rc -eq 0 ] && exit 0
  echo "attempt=$attempt rc=$rc" >&2
  sleep $((attempt*8))
done
exit 1
