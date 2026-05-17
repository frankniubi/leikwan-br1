#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'set +e
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
KEY=$(printf probe-$(date +%s) | base64)
for IP in 185.220.238.143 104.16.0.3 162.159.39.4 162.159.39.7 162.159.39.6 162.159.39.29 162.159.39.8; do
  echo "=== ws handshake via $IP ==="
  timeout 8 curl -vk --http1.1 --connect-timeout 4 --max-time 7 --resolve "$SNI:2053:$IP" \
    -H "Host: $SNI" -H "Connection: Upgrade" -H "Upgrade: websocket" \
    -H "Sec-WebSocket-Key: $KEY" -H "Sec-WebSocket-Version: 13" \
    "https://$SNI:2053/$PATHP" -o /tmp/wsprobe.out 2>&1 | sed -n "1,80p"
  echo "curl_rc=${PIPESTATUS[0]} out_size=$(wc -c </tmp/wsprobe.out 2>/dev/null || echo 0)"
  echo
 done
'