#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'set +e
  echo "=== slave316 time ==="; hostname; date -Is
  echo "=== service states ==="; for u in leikwan-slave-agent.service leikwan-tcp-mux-7000.service leikwan-wss-mux-7000.service wstunnel-svc-2-316.service; do echo ---$u; systemctl is-active $u 2>/dev/null || true; done
  echo "=== listener/process ==="; ss -ltnp | grep -E ":(7000|7[0-9]{3}|8899|8898|2053)" || true; ps -eo pid,etimes,cmd | grep -E "leikwan-ingress|wstunnel|slave-agent|7000|wstunnel-svc-2-316" | grep -v grep | sed -n "1,180p"
  echo "=== route files metadata ==="; for p in /var/lib/leikwan-slave-agent/routes-7000.json /var/lib/leikwan-slave-agent/wstunnel-svc-2-316.json /etc/systemd/system/wstunnel-svc-2-316.service /etc/systemd/system/leikwan-tcp-mux-7000.service; do echo ---$p; [ -e $p ] && sed -n "1,220p" $p || echo missing; done
  echo "=== recent logs ==="; journalctl -u leikwan-tcp-mux-7000.service -u wstunnel-svc-2-316.service -u leikwan-slave-agent.service --since "-8 min" --no-pager -o short-iso | tail -240
'
