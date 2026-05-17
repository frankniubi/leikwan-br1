#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== agent service ===";
  systemctl is-enabled leikwan-client-agent.service || true;
  systemctl status --no-pager -l leikwan-client-agent.service | sed -n "1,80p" || true;
  echo "=== agent journal ===";
  journalctl -u leikwan-client-agent.service -n 60 --no-pager || true;
'