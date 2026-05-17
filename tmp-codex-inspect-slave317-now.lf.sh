#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "=== slave317 state $(date -Is) host=$(hostname) ==="
echo '--- services active ---'
systemctl is-active leikwan-slave-agent.service wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service || true
systemctl is-enabled leikwan-slave-agent.service wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service || true
echo '--- cmdlines ---'
for u in wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service leikwan-slave-agent.service; do echo "### $u"; pid=$(systemctl show -p MainPID --value $u 2>/dev/null); echo pid=$pid; [ "${pid:-0}" != 0 ] && [ -r /proc/$pid/cmdline ] && tr '\0' ' ' </proc/$pid/cmdline; echo; done
echo '--- configs ---'
for f in /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json /var/lib/leikwan-slave/listener-routes-7000.json /etc/systemd/system/wstunnel-svc-2-317.service /etc/systemd/system/leikwan-tcp-mux-7000.service; do [ -f "$f" ] && { echo "### $f"; sed -n '1,220p' "$f"; }; done
echo '--- listeners ---'
ss -ltnp | grep -E ':(7000|8899|8898|2053|5201|14969)' || true
echo '--- recent logs ---'
journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service -u leikwan-slave-agent.service --since '-90 min' --no-pager -o short-iso | grep -E '317|07289|8899|8898|5201|Opening TCP|Invalid status|invalid bond|bond frame|plain tcp mux|route|accepted|ERROR|WARN|websocket|target|backend' | tail -240 || true
REMOTE