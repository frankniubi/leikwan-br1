#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'set -e
latest=$(ls -td /root/codex-backups/mptcp-relay-* 2>/dev/null | head -1 || true)
echo backup=$latest
if [ -n "$latest" ] && [ -f "$latest/leikwan-mptcp-relay.before" ]; then
  install -m 0755 "$latest/leikwan-mptcp-relay.before" /var/lib/leikwan-client-agent/leikwan-mptcp-relay
  echo restored
fi
sha256sum /var/lib/leikwan-client-agent/leikwan-mptcp-relay || true
systemctl restart leikwan-client-47.service
sleep 5
systemctl is-active leikwan-client-47.service || true
ss -ltnp | grep -E ":(11035|39559)" || true
journalctl -u leikwan-client-47.service -n 30 --no-pager
'