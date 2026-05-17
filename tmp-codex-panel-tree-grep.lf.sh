#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'
PASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'set +e
  echo "=== tree ==="; ls -la /www/wwwroot/v3.leikwanhost.com/leikwan-v3 | sed -n "1,80p"; find /www/wwwroot/v3.leikwanhost.com/leikwan-v3 -maxdepth 3 -name autoload.php -o -name .env | sed -n "1,80p"
  echo "=== grep f47/token route key ==="; grep -R "07289d1f5ed25399\|forward.*47\|wstunnel-svc-2-316\|backend_protocol" -n /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master /www/wwwroot/v3.leikwanhost.com/leikwan-v3/storage /tmp 2>/dev/null | head -180
'
