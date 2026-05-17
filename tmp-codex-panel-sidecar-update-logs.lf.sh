#!/usr/bin/env bash
set -euo pipefail
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'set +e
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
echo "=== app/panel logs for sidecar update since recent ==="
find storage /www/wwwlogs /www/server/panel/vhost/nginx -maxdepth 3 -type f 2>/dev/null | grep -E "(log|access|error)" | tail -80
for p in storage/logs/*.log /www/wwwlogs/*v3* /www/wwwlogs/*leikwan* /www/server/panel/vhost/nginx/*v3*; do
  [ -f "$p" ] || continue
  echo "--- $p"
  grep -E "sidecar-agent|install-bond-sidecar|sidecar/heartbeat|codex-sidecar5|185\.220\.238\.143|dfc1b1a986" "$p" 2>/dev/null | tail -80
 done
'