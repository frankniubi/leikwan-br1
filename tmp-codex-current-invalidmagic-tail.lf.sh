#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== client invalid/f47 logs current ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since "-3 hours" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|520|local_write_failed|recv_reset|fatal|ERROR|WARN" | tail -240 || true'
echo '=== slave316 invalid logs current ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-316.service -u leikwan-tcp-mux-7000.service --since "-3 hours" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|Invalid status code|failed to do websocket|Opening TCP connection|Closing|plain tcp mux|ERROR|WARN" | tail -240 || true'
