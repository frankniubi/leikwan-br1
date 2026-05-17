#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== runtime status ===";
  systemctl status --no-pager -l leikwan-client-47.service | sed -n "1,80p" || true;
  echo "=== iperf3 reverse 11035 ===";
  timeout 20 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 || true;
  echo "=== recent 47 logs ===";
  journalctl -u leikwan-client-47.service -n 80 --no-pager || true;
'