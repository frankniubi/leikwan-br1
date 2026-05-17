#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 '
  cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
  echo "=== remote artifact versions ==="
  sed -n "1,240p" artifacts/versions.json 2>/dev/null || true
  echo "=== remote artifact files ==="
  sha256sum artifacts/client-agent.py artifacts/install-client-agent.sh artifacts/install-forward-runtime.py artifacts/leikwan-mptcp-client.py 2>/dev/null || true
'