#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
source <(grep -E '^(PASS_CLIENT|SSH_OPTS)=' tmp-codex-live-converge4.sh)
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SIDECAR_HOST='185.220.238.143'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8)
echo "=== test slave access ==="
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'hostname; date; ss -ltnp | head -5' || exit 1
echo "=== inspect sidecar via slave hop ==="
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" -J root@$SLAVE_HOST root@$SIDECAR_HOST 'set +e
hostname; date
echo ===services===
systemctl --no-pager -l status leikwan-bond-sidecar-5-cf-2053.service 2>/dev/null | sed -n "1,80p"
echo ---
systemctl --no-pager -l status leikwan-bond-sidecar-5.service 2>/dev/null | sed -n "1,80p"
echo ---
systemctl --no-pager -l status leikwan-mptcp-sidecar-5.service 2>/dev/null | sed -n "1,80p"
echo ===listeners===
ss -ltnp | grep -E ":(2053|8898|8899|26001|26002)" || true
echo ===routes===
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-5/*.json; do [ -f "$p" ] && echo ---$p && sed -n "1,220p" "$p"; done
echo ===mux-hash-and-backend-port-code===
ls -l /usr/local/bin/leikwan-wss-mux.py 2>/dev/null; sha256sum /usr/local/bin/leikwan-wss-mux.py 2>/dev/null; grep -n "backend_port\|target" /usr/local/bin/leikwan-wss-mux.py | head -80
echo ===recent-cf-log===
journalctl -u leikwan-bond-sidecar-5-cf-2053.service -n 160 --no-pager 2>/dev/null | tail -n 160
echo ===recent-sidecar-log===
journalctl -u leikwan-bond-sidecar-5.service -n 120 --no-pager 2>/dev/null | tail -n 120
' || true