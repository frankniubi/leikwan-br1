#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=12 -o PreferredAuthentications=password -o PubkeyAuthentication=no)
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" -J root@$SLAVE_HOST root@185.220.238.143 'bash -s' <<'REMOTE'
set +e
hostname; date -Is; whoami
echo === services ===
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service cf-forward-47.service cf-forward-59.service; do
  echo ---$u
  systemctl is-active $u 2>/dev/null
  systemctl status $u --no-pager -l 2>/dev/null | sed -n '1,55p'
done
echo === listeners ===
ss -ltnp | grep -E ':(2053|8898|8899|5201|2600[0-9]|2601[0-9])' || true
echo === files ===
for p in /var/lib/leikwan-sidecar-agent/cf-routes-2053.json /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-agent/mptcp-forward-routes.json /usr/local/bin/leikwan-wss-mux.py /usr/local/bin/leikwan-bond /usr/local/bin/leikwan-sidecar-agent.py; do
  echo ---$p
  if [ -e "$p" ]; then
    ls -l "$p"
    sha256sum "$p" 2>/dev/null || true
    if [ -f "$p" ]; then sed -n '1,180p' "$p" 2>/dev/null | head -180; fi
  fi
done
echo === journal cf/bond recent ===
journalctl -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5.service -u cf-forward-47.service -u cf-forward-59.service --since '-20 min' --no-pager -o short-iso | tail -240 || true
REMOTE
