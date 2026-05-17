#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'set +e
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
for IP in 185.220.238.143 162.159.39.4 162.159.39.7 162.159.39.6 162.159.39.29; do
  KEY=$(openssl rand -base64 16 2>/dev/null || python3 - <<PY
import os,base64; print(base64.b64encode(os.urandom(16)).decode())
PY
)
  echo "=== valid ws handshake via $IP key=$KEY ==="
  timeout 10 curl -vk --http1.1 --connect-timeout 4 --max-time 8 --resolve "$SNI:2053:$IP" \
    -H "Host: $SNI" -H "Connection: Upgrade" -H "Upgrade: websocket" \
    -H "Sec-WebSocket-Key: $KEY" -H "Sec-WebSocket-Version: 13" \
    "https://$SNI:2053/$PATHP" -o /tmp/wsprobe-valid.out 2>&1 | sed -n "1,100p"
  echo "curl_rc=${PIPESTATUS[0]} out_size=$(wc -c </tmp/wsprobe-valid.out 2>/dev/null || echo 0)"
  echo
 done
'