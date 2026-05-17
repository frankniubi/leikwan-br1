#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +euo pipefail
echo "date=$(date -Is) host=$(hostname)"
echo -- services --
for u in leikwan-slave-agent.service leikwan-tcp-mux-7000.service wstunnel-svc-2-317.service; do echo "$u $(systemctl is-active $u 2>/dev/null)/$(systemctl is-enabled $u 2>/dev/null) pid=$(systemctl show -p MainPID --value $u 2>/dev/null)"; done
echo -- cmdlines --
for u in leikwan-tcp-mux-7000.service wstunnel-svc-2-317.service; do pid=$(systemctl show -p MainPID --value $u 2>/dev/null); echo "$u pid=$pid"; if [ -n "${pid:-}" ] && [ "$pid" != 0 ]; then tr '\0' ' ' </proc/$pid/cmdline; echo; fi; done
echo -- service json --
sed -n '1,220p' /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json 2>/dev/null || true
echo -- routes --
for p in /var/lib/leikwan-slave/routes-7000.json /var/lib/leikwan-slave/*route*7000*; do [ -f "$p" ] && echo "FILE $p" && cat "$p"; done
echo -- ports --
ss -ltnp | grep -E ':(7000|7002|8899)\b' || true
echo -- logs --
journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since '-20 min' --no-pager -o short-iso | grep -E 'Invalid status|failed to do websocket|Opening TCP|Closing|accepted|route|backend|error|WARN|ERROR|8899|07289' | tail -160 || true
REMOTE
