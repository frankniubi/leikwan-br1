#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo '=== slave316 process and route before permanent no-churn candidate ==='
systemctl is-active leikwan-slave-agent.service wstunnel-svc-2-316.service leikwan-tcp-mux-7000.service 2>/dev/null || true
systemctl is-enabled leikwan-slave-agent.service 2>/dev/null || true
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-316.service 2>/dev/null); echo pid=$pid; [ "$pid" -gt 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
sed -n '1,220p' /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json 2>/dev/null || true
echo '--- listener routes ---'; sed -n '1,220p' /var/lib/leikwan-slave/listener-routes-7000.json 2>/dev/null || true
echo '--- logs ---'; journalctl -u wstunnel-svc-2-316.service -u leikwan-tcp-mux-7000.service --since '-10 min' --no-pager -o short-iso | grep -E 'Invalid status|Opening TCP|Closing|ERROR|WARN|leikwan|accepted|route' | tail -160 || true
REMOTE