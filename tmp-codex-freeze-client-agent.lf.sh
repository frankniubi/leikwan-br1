set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set -euo pipefail
  echo "=== freeze client agent $(date -Is) ==="
  systemctl disable --now leikwan-client-agent.service || true
  systemctl reset-failed leikwan-client-agent.service || true
  systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true
  systemctl is-active leikwan-client-agent.service 2>/dev/null || true
  echo "client47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true)"
  ss -ltnp | grep ":11035" || true
'