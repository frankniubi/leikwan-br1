#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== update artifacts ===";
  ls -l /var/lib/leikwan-client-agent 2>/dev/null | sed -n "1,120p";
  echo "=== update state ===";
  cat /var/lib/leikwan-client-agent/update-state.json 2>/dev/null || true;
  echo;
  echo "=== agent unit ===";
  systemctl cat leikwan-client-agent.service;
  echo "=== any updater units ===";
  systemctl list-units --all | grep -i leikwan | sed -n "1,120p";
  echo "=== update logs grep ===";
  journalctl --no-pager | grep -i "legacy-478b15682c00f06f005cb9c0cb7f49bcbcfd101b:update\|client self-update\|leikwan-client-agent" | tail -n 120 || true;
'