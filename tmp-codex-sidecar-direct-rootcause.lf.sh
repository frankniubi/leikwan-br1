#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "SIDE=$SIDE SIDEPASS='$SIDEPASS' bash -s" <<'OUTER'
set -euo pipefail
sshpass -p "$SIDEPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 root@$SIDE 'bash -s' <<'REMOTE'
set +e
echo "=== sidecar $(hostname) $(date -Is) ==="
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service cf-forward-47.service leikwan-mptcp-sidecar-5.service leikwan-bond-sidecar-5-iperf3-5201.service leikwan-bond-sidecar-5-iperf3-5202.service; do
  echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"
  systemctl cat "$u" 2>/dev/null | sed -n '1,80p' | sed "s/^/  /"
done
echo '--- listeners ---'; ss -ltnp | grep -E ':(2053|8899|8898|5201|5202|260[0-9][0-9][0-9]|7002|7013)\b' || true
echo '--- routes ---'
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-5/cf-managed.json /usr/local/bin/leikwan-wss-mux.py /etc/systemd/system/cf-forward-47.service; do
 [ -e "$p" ] || continue
 echo "### $p"; ls -l "$p"; sed -n '1,220p' "$p"
done
echo '--- recent logs key ---'
journalctl -u leikwan-bond-sidecar-5.service -u leikwan-bond-sidecar-5-cf-2053.service -u cf-forward-47.service --since '-6 hours' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|failed to accept bond leg|07289|8899|260|GET|websocket|accepted|target|ERROR|WARN|Started|Stopped|listen' | tail -260 || true
REMOTE
OUTER
