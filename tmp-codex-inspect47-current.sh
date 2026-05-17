#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 '
  echo "=== service status ===";
  systemctl is-active leikwan-client-agent.service || true;
  systemctl is-active leikwan-client-47.service || true;
  echo "=== rendered files ===";
  sed -n "1,220p" /usr/local/bin/leikwan-client-start-47.sh || true;
  echo "=== mptcp-forward-47.json ===";
  python3 - <<"PY"
import json, pathlib
p = pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
print(json.dumps(json.loads(p.read_text()), ensure_ascii=False, indent=2))
PY
  echo "=== current processes ===";
  ps -ef | grep -E "leikwan-client-start-47|wstunnel|leikwan-mptcp-relay" | grep -v grep || true;
'