#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && pwd && hostname && ls -ld /www/wwwroot/*leikwan* && find /www/wwwroot -maxdepth 2 -name versions.json -o -name sidecar_agent.py | sed -n "1,80p" && php -r '\''require "master/src/Config.php"; $c=new Leikwan\Master\Config("master/.env"); echo "APP_URL=".$c->get("APP_URL","")."\n"; echo "ARTIFACT_BASE_URL=".$c->get("ARTIFACT_BASE_URL","")."\n";'\''' 