#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVES=("47.116.11.144|@XwDQ6H5mgMXbrGD|316" "47.116.104.15|U3I+sVL^%IXyqMX0|317" "101.133.142.65|b+z@Qk860a+6UsVW|318" "47.116.134.3|RQRUbOkeh7ZpCkDa|319" "8.133.170.123|TnJKTGse+JMJFR%t|320")
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== client invalid magic history ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
for u in leikwan-client-47.service leikwan-client-47-onegood.service leikwan-client-59.service; do
 echo "--- $u ---"
 journalctl -u "$u" --since '2026-05-16 00:00:00' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|timed out waiting for bond hello-ack|no bond legs acknowledged|failed to connect initial bond leg|Invalid status code|session established|Opening TCP' | tail -120 || true
 done
REMOTE
for item in "${SLAVES[@]}"; do
  host=${item%%|*}; rest=${item#*|}; pass=${rest%%|*}; eid=${rest##*|}
  echo "=== slave $eid $host invalid magic/wst history ==="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" root@$host 'bash -s' <<REMOTE || true
set +e
for u in leikwan-slave-agent.service wstunnel-svc-2-$eid.service leikwan-tcp-mux-7000.service; do
 echo "--- \$u ---"
 journalctl -u "\$u" --since '2026-05-16 00:00:00' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|timed out waiting|no bond legs|failed to connect initial|Invalid status code: 520|failed to do websocket|Opening TCP|empty response|route|backend|8899|8898|07289' | tail -80 || true
 done
 echo '-- service json --'; sed -n '1,180p' /var/lib/leikwan-slave/services/wstunnel-svc-2-$eid.json 2>/dev/null || true
REMOTE
done