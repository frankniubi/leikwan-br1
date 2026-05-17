#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'set +e
  echo "=== slave agent version/hash/config ==="; grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -3; sha256sum /usr/local/bin/leikwan-slave-agent.py /etc/leikwan-slave/config.json 2>/dev/null || true; sed -n "1,200p" /etc/leikwan-slave/config.json 2>/dev/null || true
  echo "=== live routes ==="; for p in /var/lib/leikwan-slave/listeners/routes-7000.json /var/lib/leikwan-slave/*.json /var/lib/leikwan-slave-agent/*.json; do [ -f "$p" ] && echo ---$p && sed -n "1,260p" "$p"; done
  echo "=== grep metadata and units ==="; grep -R "07289d1f5ed25399\|wstunnel-svc-2-316\|backend_protocol\|ingress_protocol\|backend_port\|route_key" -n /var/lib/leikwan-slave /var/lib/leikwan-slave-agent /etc/systemd/system 2>/dev/null | head -260
'
