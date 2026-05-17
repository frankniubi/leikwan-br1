#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)

echo '=== restart client forward47 ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
  systemctl restart leikwan-client-47.service
  sleep 4
  systemctl is-active leikwan-client-47.service
  systemctl status leikwan-client-47.service --no-pager -l | sed -n "1,28p"
  echo --script-head--
  sed -n "1,45p" /usr/local/bin/leikwan-client-start-47.sh
  echo --listeners--
  ss -ltnp | grep -E ":(11035|21739|21740|21741|21742|21743|21744)" || true
  echo --logs-last--
  journalctl -u leikwan-client-47.service --since "-90 sec" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|failed to establish|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|local_write_failed" | tail -160 || true
  echo --snapshot--
  cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
'
