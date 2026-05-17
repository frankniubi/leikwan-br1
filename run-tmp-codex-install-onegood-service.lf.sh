#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47-onegood.service 2>/dev/null || true
cat >/usr/local/bin/leikwan-client-start-47-onegood.sh <<'SH'
#!/usr/bin/env bash
set -euo pipefail
trap 'kill $(jobs -p) 2>/dev/null || true' EXIT
ROUTE='07289d1f5ed25399'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
LISTEN=11035
BASE_PORT=22740
cat >/tmp/leikwan-f47-shanghai-cf-legs.tsv <<'EOF'
316|47.116.11.144|2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
317|47.116.104.15|85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9
318|101.133.142.65|bd5eb739e2676216288eba117a8e893d767fe6f7edb2260a11a728f44f7e3307
319|47.116.134.3|48d684c9ff3c574205a556230f438faa3faf89f9113b4a28057d16dd7b792a53
320|8.133.170.123|a5216e9a1b67b4519013060c24e06ab3951755b32b68ee17f4122cceae637f72
EOF
legs=()
idx=0
while IFS='|' read -r eid host auth; do
  port=$((BASE_PORT + idx))
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${port} --remote ${host}:7000 --route-key "$ROUTE" --auth-token "$auth" &
  legs+=("tcp://127.0.0.1:${port}?priority=10&endpoint_id=${eid}&display_host=${host}")
  idx=$((idx + 1))
done </tmp/leikwan-f47-shanghai-cf-legs.tsv
for p in $(seq ${BASE_PORT} $((BASE_PORT + idx - 1))); do
  for i in $(seq 1 80); do
    python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', ${p}), timeout=.25); s.close()
PY
  done
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
legs_csv=$(IFS=,; echo "${legs[*]}")
exec /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
  --legs "$legs_csv" \
  --session-token "$TOKEN" \
  --chunk-size 65536 --max-window-bytes 33554432 --retransmit-ms 800 \
  --connect-timeout-ms 4000 --join-timeout-ms 1000 \
  --interactive-chunk-bytes 65536 --latency-guard-bytes 1048576 \
  --warmup-trigger-bytes 1073741824 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 20 \
  --bulk-promote-bytes 1073741824 --leg-cooldown-ms 30000 --latency-first
SH
chmod +x /usr/local/bin/leikwan-client-start-47-onegood.sh
cat >/etc/systemd/system/leikwan-client-47-onegood.service <<'UNIT'
[Unit]
Description=Leikwan Client 47 one-good Shanghai-Tokyo CF bond path
After=network.target

[Service]
Type=simple
Environment=RUST_LOG=warn
ExecStart=/bin/bash /usr/local/bin/leikwan-client-start-47-onegood.sh
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now leikwan-client-47-onegood.service
sleep 2
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service)/$(systemctl is-enabled leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|2274[0-4])\b' || true
journalctl -u leikwan-client-47-onegood.service -n 20 --no-pager -o short-iso | tail -20
REMOTE