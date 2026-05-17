#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== client freeze/status $(date -Is) ==="
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
echo "client_agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "client47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-47.service 2>/dev/null || true)"
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-47-onegood.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|22740|11036|2273|291)' || true
ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-client-47|leikwan-bond client|leikwan-ingress client' | grep -v grep || true
echo '--- recent bond key ---'
journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since '-20 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|no bond legs|session established|recv_reset|local_write_failed|local_read_failed|cooldown|ERROR|WARN' | tail -120 || true
REMOTE
