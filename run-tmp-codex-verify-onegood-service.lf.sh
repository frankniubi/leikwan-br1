#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
sleep 1
echo "status agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service)/$(systemctl is-enabled leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
echo '-- sockets --'; ss -ltnp | grep -E ':(11035|2274[0-4])\b' || true
echo '-- iperf run1 10s --'
timeout 25 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 3000 2>&1; echo rc1=$?
sleep 3
echo '-- iperf run2 10s --'
timeout 25 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 3000 2>&1; echo rc2=$?
echo '-- key logs since service restart --'
journalctl -u leikwan-client-47-onegood.service --since '5 min ago' --no-pager -o short-iso | egrep -i 'started|session established|invalid bond frame|failed to read bond frame|timed out waiting|entering cooldown|local_write_failed|local_read_failed|failed to connect initial|control socket|ERROR|WARN' | tail -220 || true
REMOTE