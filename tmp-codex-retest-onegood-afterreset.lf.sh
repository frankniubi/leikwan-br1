#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== retest current onegood after sidecar iperf reset ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl restart leikwan-client-47-onegood.service
sleep 4
echo "date=$(date -Is) onegood=$(systemctl is-active leikwan-client-47-onegood.service) agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|22740)' || true
echo '-- banner target through current onegood (should be iperf target 5201 so not SSH) --'
echo '-- iperf reverse -b 300M 15s --'
timeout 30 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 15 --connect-timeout 3000 > /tmp/codex-onegood47-afterreset-R.out 2>&1
rc=$?; echo rc=$rc; cat /tmp/codex-onegood47-afterreset-R.out
echo '-- iperf upload -b 300M 10s --'
timeout 25 iperf3 -c 127.0.0.1 -p 11035 -b 300M -t 10 --connect-timeout 3000 > /tmp/codex-onegood47-afterreset-upload.out 2>&1
rc=$?; echo rc=$rc; cat /tmp/codex-onegood47-afterreset-upload.out
echo '-- logs --'
journalctl -u leikwan-client-47-onegood.service --since '-2 min' --no-pager -o short-iso | grep -E 'session established|recv_reset|local_read_failed|local_write_failed|failed to connect upstream|entering cooldown|invalid bond frame magic|failed to read bond frame header|WARN|ERROR' | tail -200 || true
echo '-- health --'; cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
REMOTE
echo '=== slave logs ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since "-3 min" --no-pager -o short-iso | grep -E "Invalid status|failed to do websocket|Opening TCP|Closing|WARN|ERROR|520|8899|07289" | tail -160 || true'
