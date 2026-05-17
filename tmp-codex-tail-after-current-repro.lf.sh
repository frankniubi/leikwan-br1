#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== client journal last 10m current repro ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "journalctl -u leikwan-client-47-onegood.service --since '-10 min' --no-pager -o short-iso | grep -E 'upstream|local_read_failed|local_write_failed|recv_reset|send_reset|open|ack|fin|target|failed|cooldown|lifecycle' | tail -240 || true"
echo '=== slave wstunnel service last 10m ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "journalctl -u wstunnel-svc-2-317.service --since '-10 min' --no-pager -o short-iso | grep -E '019e331|07289|Opening TCP|TLS handshake|Closing|Invalid status|failed|ERROR|WARN|8899' | tail -240 || true"