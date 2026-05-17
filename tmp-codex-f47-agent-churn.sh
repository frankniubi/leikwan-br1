#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
now=$(date +%s)
for f in /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-client-start-47.sh /usr/local/bin/leikwan-client-agent.py /var/lib/leikwan-client-agent/bond-endpoint-health.json /var/lib/leikwan-client-agent/latency-primary-state.json /var/lib/leikwan-client-agent/config_pull_state.json; do echo ===$f===; [ -e $f ] && stat -c "%s %y" $f && sha256sum $f | cut -d" " -f1 || echo missing; done
echo ===active pid cmd===; systemctl show -p MainPID,ActiveEnterTimestamp,ExecMainStartTimestamp leikwan-client-47.service; ps -o pid,lstart,cmd -p $(systemctl show -p MainPID --value leikwan-client-47.service) || true
echo ===agent recent cause full===; journalctl -u leikwan-client-agent.service --since "-2 min" --no-pager | tail -n 220
'