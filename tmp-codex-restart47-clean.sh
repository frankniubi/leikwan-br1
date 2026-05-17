#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== restart 47 cleanly ===";
  systemctl restart leikwan-client-47.service;
  sleep 5;
  systemctl is-active leikwan-client-47.service || true;
  ss -ltnp | grep 11035 || true;
  echo "=== 47 recent logs ===";
  journalctl -u leikwan-client-47.service -n 80 --no-pager || true;
'