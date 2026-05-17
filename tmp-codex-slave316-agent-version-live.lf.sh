#!/usr/bin/env bash
set -euo pipefail
SLAVE='47.116.11.144'
PASS='@XwDQ6H5mgMXbrGD'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$SLAVE 'bash -s' <<'REMOTE'
set +e
for p in /usr/local/bin/leikwan-slave-agent.py /usr/local/bin/slave_agent.py /opt/leikwan/slave-agent/slave_agent.py; do
  echo ---$p
  if [ -e "$p" ]; then
    grep -n "AGENT_VERSION\|def publish_task\|mptcp_underlay and mptcp_underlay\|backend_mode\|cf_mode\|use_plain_tcp_backend" "$p" | head -80
    sed -n '2050,2175p' "$p" | head -140
  fi
done
systemctl cat leikwan-slave-agent.service 2>/dev/null || true
REMOTE
