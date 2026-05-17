#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
for attempt in 1 2 3 4; do
  set +e
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
cat >/usr/local/bin/leikwan-client-start-47-onegood.sh <<'EOS'
#!/usr/bin/env bash
set -euo pipefail
trap 'kill $(jobs -p) 2>/dev/null || true' EXIT
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
INGRESS_PORT=22740
LISTEN=11035
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${INGRESS_PORT} --remote 47.116.104.15:7000 --route-key "$ROUTE" --auth-token "$AUTH" &
for i in $(seq 1 50); do
  python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', ${INGRESS_PORT}), timeout=.2); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+86400}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
exec /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
  --legs "tcp://127.0.0.1:${INGRESS_PORT}?priority=10&endpoint_id=317&display_host=47.116.104.15" \
  --session-token "$TOKEN" \
  --chunk-size 524288 --max-window-bytes 33554432 --retransmit-ms 150 \
  --connect-timeout-ms 3000 --join-timeout-ms 200 \
  --interactive-chunk-bytes 65536 --latency-guard-bytes 1048576 \
  --warmup-trigger-bytes 1048576 --warmup-slice-bytes 524288 --warmup-leg-delay-ms 0 \
  --bulk-promote-bytes 1073741824 --leg-cooldown-ms 1000
EOS
chmod 755 /usr/local/bin/leikwan-client-start-47-onegood.sh
systemctl restart leikwan-client-47-onegood.service
sleep 4
systemctl is-active leikwan-client-47-onegood.service
ss -ltnp | grep -E ':(11035|22740)' || true
echo '--- warmup wait + logs ---'
journalctl -u leikwan-client-47-onegood.service --since '-20 sec' --no-pager -o short-iso | tail -70
sleep 45
echo '--- iperf after settle ---'
timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 20 --connect-timeout 3000 > /tmp/codex-onegood47-iperf2.out 2>&1
rc=$?
echo iperf_rc=$rc
cat /tmp/codex-onegood47-iperf2.out
echo '--- logs ---'
journalctl -u leikwan-client-47-onegood.service --since '-2 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|no bond legs|session established|failed|WARN|ERROR|cooldown|accepted' | tail -160 || true
REMOTE
  rc=$?
  set -e
  if [ $rc -eq 0 ]; then exit 0; fi
  echo attempt=$attempt rc=$rc
  sleep $((attempt*10))
done
exit 1
