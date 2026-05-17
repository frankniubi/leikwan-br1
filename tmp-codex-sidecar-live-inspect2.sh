#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o PreferredAuthentications=password -o PubkeyAuthentication=no)
echo '=== sidecar via shanghai slave hop ==='
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" -J root@$SLAVE_HOST root@185.220.238.143 'bash -s' <<'RS'
set +e
hostname; date -Is; whoami
echo === services ===
systemctl is-active leikwan-bond-sidecar-5-cf-2053.service leikwan-bond-sidecar-5.service leikwan-mptcp-sidecar-5.service 2>/dev/null || true
systemctl status leikwan-bond-sidecar-5-cf-2053.service --no-pager -l | sed -n '1,80p' || true
echo === listeners ===
ss -ltnp | grep -E ':(2053|8898|8899|2600[0-9]|2601[0-9])' || true
echo === routes/files ===
for p in /var/lib/leikwan-sidecar-agent/cf-routes-2053.json /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-agent/mptcp-forward-routes.json /var/lib/leikwan-sidecar-5/mptcp-forward-routes.json /usr/local/bin/leikwan-wss-mux.py /usr/local/bin/leikwan-sidecar-agent.py; do
  if [ -e "$p" ]; then
    echo ---$p
    ls -l "$p"
    sha256sum "$p" 2>/dev/null || true
    sed -n '1,220p' "$p" 2>/dev/null || true
  fi
done
echo === cf backend units ===
for u in $(systemctl list-units --all --no-legend 'cf-forward*' 2>/dev/null | awk '{print $1}'); do
  echo ---$u
  systemctl status "$u" --no-pager -l | sed -n '1,60p'
done
echo === recent cf logs ===
journalctl -u leikwan-bond-sidecar-5-cf-2053.service --since '-10 min' --no-pager -n 180 || true
echo === recent f47 backend logs ===
journalctl -u cf-forward-47.service --since '-10 min' --no-pager -n 180 || true
echo === direct local backend probes ===
python3 - <<'PY'
import socket, time
for port in [2053,26001,26002,26003,26004,8899,8898]:
    t=time.time()
    try:
        s=socket.create_connection(('127.0.0.1',port), timeout=1)
        s.close()
        print(port,'open',round((time.time()-t)*1000,1))
    except Exception as e:
        print(port,'fail',repr(e))
PY
RS
