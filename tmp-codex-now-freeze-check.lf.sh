#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
echo "host=$(hostname) date=$(date -Is)"
echo "agent active=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true) enabled=$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
for u in leikwan-client-47.service leikwan-client-47-onegood.service; do
  echo "$u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"
done
echo '-- listeners --'
ss -ltnp | grep -E ':(11035|22740|32501)\b' || true
echo '-- processes --'
ps -eo pid,ppid,etimes,cmd | grep -E '11035|22740|32501|leikwan-client-47-onegood|leikwan-client-47\.service' | grep -v grep || true
echo '-- start47 --'
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
echo '-- onegood --'
sed -n '1,180p' /usr/local/bin/leikwan-client-start-47-onegood.sh 2>/dev/null || true
echo '-- logs --'
journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since '-20 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame|session established|recv_reset|local_read_failed|local_write_failed|cooldown|failed|ERROR|WARN|upstream' | tail -160 || true
REMOTE
