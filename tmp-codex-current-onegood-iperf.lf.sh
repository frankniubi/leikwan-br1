#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== client onegood current + iperf ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
echo "agent_enabled=$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true) agent_active=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)"
echo "svc47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
echo "listeners:"; ss -ltnp | grep -E ':(11035|22740|29140|29141)' || true
echo '--- recent bond warnings/established before iperf ---'
journalctl -u leikwan-client-47-onegood.service --since '-5 min' --no-pager -o short-iso | grep -E 'established|invalid bond frame magic|failed to read bond frame header|no bond legs|entering cooldown|local_write_failed|WARN|ERROR' | tail -80 || true
echo '--- iperf reverse -b 300M -t 10 ---'
timeout 25 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 3000 > /tmp/codex-onegood47-iperf-now.out 2>&1
rc=$?
echo iperf_rc=$rc
cat /tmp/codex-onegood47-iperf-now.out
echo '--- recent bond after iperf ---'
journalctl -u leikwan-client-47-onegood.service --since '-3 min' --no-pager -o short-iso | grep -E 'established|invalid bond frame magic|failed to read bond frame header|no bond legs|entering cooldown|local_write_failed|stream|WARN|ERROR' | tail -140 || true
REMOTE

echo '=== endpoint317 slave logs ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since "-8 min" --no-pager -o short-iso | grep -E "Opening TCP|Invalid status|failed to do websocket|Closing|plain tcp mux|bond|8899|07289|ERROR|WARN" | tail -180 || true'