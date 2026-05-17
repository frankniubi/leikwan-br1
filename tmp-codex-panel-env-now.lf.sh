#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && pwd && ls -la | sed -n "1,80p" && find /www/wwwroot/v3.leikwanhost.com -maxdepth 3 -name ".env" -o -name "*.env" 2>/dev/null | sed -n "1,40p" && for f in .env master/.env ../.env; do [ -f "$f" ] && echo ---$f && sed -n "1,80p" "$f" | sed -E "s/(PASSWORD=).*/\1***REDACTED***/"; done'
