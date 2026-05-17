#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
 echo "=== segmented f47 traffic $(date -Is) ==="
 for cmd in \
   "timeout 8 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 --connect-timeout 3000" \
   "timeout 8 iperf3 -c 127.0.0.1 -p 21739 -R -b 300M -t 5 --connect-timeout 3000" \
   "timeout 12 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 7 --connect-timeout 3000"; do
   echo "--- $cmd"; bash -lc "$cmd"; echo rc=$?; sleep 1
 done
 echo "=== post logs key ==="; journalctl -u leikwan-client-47.service --since "-2 min" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|session established|accepted downstream|Opening TCP connection|Invalid status code|520|WARN|ERROR|failed|Connection refused|recv_reset" | tail -220 || true
 echo "=== snapshot ==="; cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
'