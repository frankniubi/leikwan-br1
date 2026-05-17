#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php -r '\''require "vendor/autoload.php"; echo "ok\n";'\'' 2>/dev/null || true; echo ===grep sidecar===; grep -R "185.220.238.143\|161.248.136.183\|14950\|nrt1.nbwly.com" -n . 2>/dev/null | head -80; echo ===mysql try===; grep -R "DB_\|database" -n .env config 2>/dev/null | head -40'