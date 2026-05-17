#!/usr/bin/env bash
set -euo pipefail
PANEL=10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" root@$PANEL 'bash -s' <<'REMOTE'
set +e
echo host=$(hostname) date=$(date -Is)
for d in /www/wwwroot/v3.leikwanhost.com/leikwan-v3 /www/wwwroot/v3.leikwanhost.com /www/wwwroot/leikwan-v3; do echo ---$d; ls -la $d 2>/dev/null | head -40; done
find /www/wwwroot/v3.leikwanhost.com -maxdepth 3 -type f -name 'Bootstrap.php' -o -name 'bootstrap.php' 2>/dev/null | head -30
REMOTE