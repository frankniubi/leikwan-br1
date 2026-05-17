set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
echo "=== freeze auto update: disable leikwan-client-agent ==="
systemctl disable --now leikwan-client-agent.service || true
echo "agent_enabled=$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
echo "agent_active=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)"
echo "date=$(date -Is) host=$(hostname)"
echo "client47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
echo "listeners:"; ss -ltnp | grep -E ':(11035|22740|29140|29141|43003|43279|43280|43281|43282|43283|43284)' || true
echo "forward47 files:"; ls -l /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-client-start-47.sh /usr/local/bin/leikwan-client-start-47-onegood.sh 2>/dev/null || true
echo "agent update state:"; sed -n '1,120p' /var/lib/leikwan-client-agent/agent_update_state.json 2>/dev/null || true
echo "recent client47 logs:"; journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since '-20 min' --no-pager -o short-iso | tail -160 || true
REMOTE
