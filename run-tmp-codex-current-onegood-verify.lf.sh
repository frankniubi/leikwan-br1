#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== current onegood iperf -b 300M ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is)"
echo "services: agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true) f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|22740)' || true
echo '--- pre logs ---'
journalctl -u leikwan-client-47-onegood.service --since '-3 min' --no-pager -o short-iso | grep -E 'session established|invalid bond frame magic|failed to read bond frame header|recv_reset|local_write_failed|entering cooldown|WARN|ERROR|accepted|Opening' | tail -80 || true
echo '--- iperf 15s reverse -b 300M ---'
timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 15 --connect-timeout 3000 > /tmp/codex-onegood47-current-iperf.out 2>&1
rc=$?
echo iperf_rc=$rc
cat /tmp/codex-onegood47-current-iperf.out
echo '--- post logs ---'
journalctl -u leikwan-client-47-onegood.service --since '-1 min' --no-pager -o short-iso | grep -E 'session established|invalid bond frame magic|failed to read bond frame header|recv_reset|local_write_failed|entering cooldown|WARN|ERROR|accepted|lifecycle recv_fin|local_eof|send_fin' | tail -200 || true
REMOTE

echo '=== endpoint317 slave current/logs ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
systemctl is-active wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service || true
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service); echo pid=$pid; [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
cat /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json 2>/dev/null | python3 -m json.tool | sed -n '1,180p' || true
echo '--- recent logs ---'
journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since '-5 min' --no-pager -o short-iso | grep -E 'Invalid status code|failed to do websocket|Opening TCP|Doing TLS|Closing|plain tcp mux|accepted|ERROR|WARN|8899|07289' | tail -180 || true
REMOTE