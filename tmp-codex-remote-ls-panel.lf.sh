#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank1212.'
sshpass -e ssh -T \
  -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 \
  root@10.0.10.196 'bash -s' <<'REMOTE'
set -x
hostname
pwd
ls -la /www/wwwroot/v3.leikwanhost.com/leikwan-v3 | head || true
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 || exit 20
pwd
ls -la master | head
find . -maxdepth 4 -name autoload.php -o -name Config.php | head -40
REMOTE