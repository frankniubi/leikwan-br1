#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
echo "agent enabled/active:"; systemctl is-enabled leikwan-client-agent.service 2>/dev/null; systemctl is-active leikwan-client-agent.service 2>/dev/null
echo "services:"; systemctl is-active leikwan-client-47.service leikwan-client-47-onegood.service 2>/dev/null || true
systemctl status leikwan-client-47-onegood.service --no-pager -l 2>/dev/null | sed -n '1,80p'
echo "listeners:"; ss -ltnp | grep -E ':(11035|22740|291|39559)' || true
echo "processes:"; ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan-client-47|leikwan-bond client|leikwan-ingress client|iperf3' | grep -v grep | sed -n '1,160p'
echo "recent onegood logs:"; journalctl -u leikwan-client-47-onegood.service --since '-10 min' --no-pager -o short-iso | tail -120
REMOTE
