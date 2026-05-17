#!/usr/bin/env bash
set -euo pipefail
sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
set -u
echo date=$(date -Is)
systemctl is-active leikwan-client-agent.service || true
systemctl is-active leikwan-client-47.service || true
systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,80p' || true
echo ---listeners
ss -ltnp | egrep ':(11035|21739|21740|2053|8899|8898|5201)\b' || true
echo ---processes
pgrep -af 'leikwan-client-47|leikwan-bond|wstunnel|leikwan-ingress|mptcp-forward-47' || true
echo ---start
sed -n '1,240p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
echo ---mptcpjson
if test -f /etc/leikwan/mptcp-forward-47.json; then python3 -m json.tool /etc/leikwan/mptcp-forward-47.json | sed -n '1,160p'; else echo no-mptcp-json; fi
echo ---journal
journalctl -u leikwan-client-47.service -n 140 --no-pager -o short-iso | tail -140 || true
REMOTE
