#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SIDE_HOST='185.220.238.143'
SIDE_PASS='GK@Sp7z%T!s4*w5P'
SSH_BASE=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
PROXY="sshpass -p '$SLAVE_PASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -W %h:%p root@$SLAVE_HOST"
sshpass -p "$SIDE_PASS" ssh "${SSH_BASE[@]}" -o ProxyCommand="$PROXY" root@$SIDE_HOST 'bash -s' <<'REMOTE'
set +e
echo "=== sidecar identity ==="; hostname; date -Is; whoami
echo "=== listeners ==="; ss -ltnp | grep -E ':(2053|8898|8899|5201|2600[0-9]|2601[0-9])' || true
echo "=== service states ==="
for u in leikwan-bond-sidecar-5-cf-2053.service leikwan-bond-sidecar-5.service leikwan-mptcp-sidecar-5.service cf-forward-47.service cf-forward-59.service iperf3.service leikwan-managed-iperf3.service; do
  echo "--- $u"; systemctl is-active "$u" 2>/dev/null || true; systemctl status "$u" --no-pager -l 2>/dev/null | sed -n '1,45p' || true
done
echo "=== files/routes ==="
for p in /var/lib/leikwan-sidecar-agent/cf-routes-2053.json /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-agent/mptcp-forward-routes.json /var/lib/leikwan-sidecar-5/mptcp-forward-routes.json /usr/local/bin/leikwan-wss-mux.py /usr/local/bin/leikwan-sidecar-agent.py; do
  echo "--- $p"; if [ -e "$p" ]; then ls -l "$p"; sha256sum "$p" 2>/dev/null || true; sed -n '1,220p' "$p" 2>/dev/null || true; else echo missing; fi
done
echo "=== process list ==="; ps -eo pid,etimes,cmd | grep -E 'leikwan|wss|mux|iperf|5201|8899|8898|2053' | grep -v grep | sed -n '1,220p'
echo "=== recent logs ==="; journalctl -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5.service -u cf-forward-47.service -u leikwan-sidecar-agent.service --since '-15 min' --no-pager -o short-iso | tail -260 || true
echo "=== local connect probes ==="; python3 - <<'PY'
import socket,time
for port in [5201,8899,8898,2053]:
    t=time.time()
    try:
        s=socket.create_connection(('127.0.0.1',port),timeout=1)
        s.close(); print(port,'open',round((time.time()-t)*1000,1))
    except Exception as e:
        print(port,'fail',repr(e))
PY
REMOTE
