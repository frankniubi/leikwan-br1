#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
printf '=== local curl ===\n'
for url in http://127.0.0.1/artifacts/sidecar-agent.py https://v3.leikwanhost.com/artifacts/sidecar-agent.py https://v3-mirror.leikwanhost.com/artifacts/sidecar-agent.py; do
  echo "-- $url"
  curl -k -fsSL --max-time 10 "$url" | head -5 || true
  curl -k -fsSI --max-time 10 "$url" | head -10 || true
done
printf '=== vhost files ===\n'
for d in /www/server/panel/vhost/nginx /www/server/panel/vhost/apache /etc/nginx /etc/nginx/conf.d; do [ -d "$d" ] && { echo "-- $d"; grep -R "v3-mirror\|leikwan-v3\|artifacts" -n "$d" 2>/dev/null | head -80; }; done
REMOTE