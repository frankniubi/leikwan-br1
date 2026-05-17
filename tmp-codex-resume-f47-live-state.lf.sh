#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== freeze-auto-update $(date -Is) ==="
systemctl disable --now leikwan-client-agent.service 2>&1 || true
systemctl reset-failed leikwan-client-agent.service 2>&1 || true
echo -n "agent_enabled="; systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true
echo -n "agent_active="; systemctl is-active leikwan-client-agent.service 2>/dev/null || true
echo -n "client47_enabled="; systemctl is-enabled leikwan-client-47.service 2>/dev/null || true
echo -n "client47_active="; systemctl is-active leikwan-client-47.service 2>/dev/null || true

echo "=== client47 status ==="
systemctl status leikwan-client-47.service --no-pager -l | sed -n '1,90p'

echo "=== files ==="
for f in /usr/local/bin/leikwan-client-start-47.sh /etc/leikwan/mptcp-forward-47.json /run/leikwan/bond-leg-health-forward-47.json /var/lib/leikwan-client-agent/applied_forwards.json /var/lib/leikwan-client-agent/bond-endpoint-health.json /var/lib/leikwan-client-agent/self-leg-best-ip-cache.json; do
  echo "--- $f ---"
  if [ -e "$f" ]; then
    ls -l "$f"
    case "$f" in
      *.json) python3 -m json.tool "$f" 2>/dev/null | sed -n '1,220p' || sed -n '1,220p' "$f" ;;
      *) sed -n '1,260p' "$f" ;;
    esac
  else
    echo missing
  fi
done

echo "=== procs/listeners ==="
ps -eo pid,etimes,cmd | grep -E 'leikwan-client-start-47|mptcp-forward-47|11035|leikwan-bond|leikwan-mptcp|wstunnel client|leikwan-ingress client' | grep -v grep | sed -n '1,240p'
ss -ltnp | grep -E ':(11035|217[0-9][0-9]|291[0-9][0-9])' || true

echo "=== recent client47 logs ==="
journalctl -u leikwan-client-47.service --since '-30 min' --no-pager -o short-iso | tail -180
REMOTE
