set -u
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
for i in 1 2 3 4 5; do
  echo "===try:$i==="
  sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'systemctl disable --now leikwan-client-agent.service >/tmp/codex-disable-agent.out 2>&1 || true; echo agent_enabled=$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true); echo agent_active=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true); echo f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true); echo onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true); date -Is; hostname; ss -ltnp | grep -E ":(11035|22740|39559|7000)" || true' && exit 0
  sleep 2
done
exit 1