#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" scp "${SSH_OPTS[@]}" client-agent/client_agent.py "$CLIENT:/usr/local/bin/leikwan-client-agent.py.tmp" >/dev/null
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set -euo pipefail
  install -m 0755 /usr/local/bin/leikwan-client-agent.py.tmp /usr/local/bin/leikwan-client-agent.py
  rm -f /usr/local/bin/leikwan-client-agent.py.tmp
  python3 -m py_compile /usr/local/bin/leikwan-client-agent.py
  grep -n "AGENT_VERSION" /usr/local/bin/leikwan-client-agent.py | head -1
  sha256sum /usr/local/bin/leikwan-client-agent.py
  systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
  systemctl is-enabled leikwan-client-agent.service 2>&1 || true
  systemctl is-active leikwan-client-agent.service 2>&1 || true
'