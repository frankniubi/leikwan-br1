#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== client targeted state $(date -Is) ==="
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true) f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
echo '--- onegood unit ---'
systemctl cat leikwan-client-47-onegood.service 2>/dev/null | sed -n '1,220p'
echo '--- start scripts 47/onegood refs ---'
ls -l /usr/local/bin/*47* /etc/systemd/system/*47* 2>/dev/null | sed -n '1,120p'
for f in /usr/local/bin/leikwan-client-start-47.sh /usr/local/bin/leikwan-client-start-47-onegood.sh /tmp/*onegood* /etc/leikwan/*47*; do [ -f "$f" ] && { echo "### $f"; sed -n '1,220p' "$f"; }; done
echo '--- local iperf servers ---'
ss -ltnp | grep -E ':(5201|5202|5203|11035|22740|8899|8898)' || true
ps -ef | grep -E 'iperf3|leikwan-bond|leikwan-ingress client --listen 127.0.0.1:22740' | grep -v grep || true
echo '--- onegood journal last 80 raw ---'
journalctl -u leikwan-client-47-onegood.service --since '-30 min' --no-pager -o short-iso | tail -120
REMOTE