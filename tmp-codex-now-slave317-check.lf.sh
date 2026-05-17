#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
for u in leikwan-slave-agent.service wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service; do echo "$u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listen 7000/7002 --'; ss -ltnp | grep -E ':(7000|7002)\b' || true
echo '-- routes --'; cat /var/lib/leikwan-slave/listeners/routes-7000.json 2>/dev/null || true
echo; echo '-- meta --'; cat /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json 2>/dev/null || true
echo; echo '-- unit wstunnel --'; cat /etc/systemd/system/wstunnel-svc-2-317.service 2>/dev/null || true
echo; echo '-- unit mux --'; cat /etc/systemd/system/leikwan-tcp-mux-7000.service 2>/dev/null || true
echo; echo '-- active cmdlines --'; for p in $(systemctl show -p MainPID --value wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service 2>/dev/null); do [ "$p" != 0 ] && tr '\0' ' ' </proc/$p/cmdline && echo; done
echo '-- logs --'; journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since '-30 min' --no-pager -o short-iso | grep -E 'plain tcp mux|mux connection|open route|failed|empty|WARN|ERROR|Opening TCP|Invalid status|handshake|520|8899|07289' | tail -200 || true
REMOTE
