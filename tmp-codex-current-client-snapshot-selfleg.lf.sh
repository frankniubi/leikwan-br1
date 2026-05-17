set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
echo "=== CLIENT SNAPSHOT $(date -Is) ==="
echo "hostname=$(hostname) kernel=$(uname -r)"
for u in leikwan-client-agent.service leikwan-client-47.service leikwan-client-47-onegood.service; do
  echo "UNIT $u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"
done
echo '--- listeners ---'
ss -ltnp | grep -E ':(11035|2274[0-9]|[0-9]{4})\b' | head -80 || true
echo '--- processes ---'
ps -eo pid,ppid,cmd | grep -E 'leikwan|wstunnel|iperf3' | grep -v grep | head -120 || true
echo '--- bins ---'
for b in /usr/local/bin/leikwan-bond /usr/local/bin/leikwan-ingress /usr/local/bin/wstunnel /usr/local/bin/leikwan-client-agent.py /usr/local/bin/leikwan-client-start-47.sh /usr/local/bin/leikwan-client-start-47-onegood.sh; do
  if [ -e "$b" ]; then sha256sum "$b" 2>/dev/null || true; ls -l "$b"; else echo "MISSING $b"; fi
done
echo '--- f47 jsons ---'
for f in /etc/leikwan/mptcp-forward-47.json /etc/leikwan/forward-47.json /run/leikwan/bond-leg-health-forward-47.json /var/lib/leikwan-client/self-leg-best-ip-forward-47.json /var/lib/leikwan-client/latency-primary-state.json; do
  echo "### $f"; [ -f "$f" ] && sed -n '1,220p' "$f" || echo missing
done
echo '--- recent onegood log ---'
journalctl -u leikwan-client-47-onegood.service -n 80 --no-pager -o short-iso 2>/dev/null | tail -80 || true
echo '--- recent f47 log ---'
journalctl -u leikwan-client-47.service -n 60 --no-pager -o short-iso 2>/dev/null | tail -60 || true
REMOTE