#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'set +e
echo ===status===
systemctl status leikwan-client-47.service --no-pager -l | sed -n "1,120p"
echo ===ss===
ss -ltnp | grep -E ":(11035|39559)" || true
echo ===journal===
journalctl -u leikwan-client-47.service -n 160 --no-pager
'