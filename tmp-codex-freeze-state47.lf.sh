set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== freeze client agent ==="
systemctl disable --now leikwan-client-agent.service 2>&1 || true
systemctl is-enabled leikwan-client-agent.service 2>&1 || true
systemctl is-active leikwan-client-agent.service 2>&1 || true
echo "=== f47 service/listener ==="
systemctl is-active leikwan-client-47.service 2>&1 || true
systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,80p' || true
ss -ltnp | grep -E ':(11035|291|5201|8899|8898)' || true
echo "=== f47 files ==="
for f in /etc/leikwan/mptcp-forward-47.json /usr/local/bin/leikwan-client-start-47.sh /var/lib/leikwan-client-agent/latency-primary-state.json /var/lib/leikwan-client-agent/bond-endpoint-health.json /var/lib/leikwan-client-agent/self-leg-best-ip-cache.json; do
 echo "--- $f"; if [ -e "$f" ]; then sed -n '1,220p' "$f"; else echo missing; fi; done
echo "=== recent f47 logs ==="
journalctl -u leikwan-client-47.service --since '-20 min' --no-pager -o short-iso | tail -180 || true
REMOTE