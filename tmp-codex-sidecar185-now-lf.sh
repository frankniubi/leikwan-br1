#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "SIDE=$SIDE SIDEPASS='$SIDEPASS' bash -s" <<'OUTER'
set +e
command -v sshpass >/dev/null 2>&1 || (apt-get update >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y sshpass >/dev/null)
sshpass -p "$SIDEPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o ConnectTimeout=12 -o ConnectionAttempts=1 root@$SIDE 'bash -s' <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service leikwan-bond-sidecar-5-iperf3-5201.service leikwan-bond-sidecar-5-iperf3-5202.service; do
  echo "$u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"
done
echo '-- listeners --'; ss -ltnp | grep -E ':(2053|8899|8898|5201|5202|2600[0-9])\b' || true
echo '-- route files --'
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-5/mptcp-forward-routes.json /etc/leikwan-sidecar-5/bond-runtime.json /etc/systemd/system/leikwan-bond-sidecar-5-cf-2053.service /etc/systemd/system/leikwan-bond-sidecar-5.service /usr/local/bin/leikwan-wss-mux.py; do
  [ -e "$p" ] || continue
  echo "--- $p"; ls -l "$p"; sed -n '1,220p' "$p" | head -220
done
echo '-- local probes --'
python3 - <<'PY'
import socket,time,statistics
for port in [8899,8898,5201,5202,2053]:
 vals=[]; errs=[]
 for i in range(3):
  t=time.time()
  try:
   s=socket.create_connection(('127.0.0.1',port),timeout=1.5); vals.append((time.time()-t)*1000); s.close()
  except Exception as e: errs.append(type(e).__name__+':'+str(e))
 print(port,'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:2])
PY
echo '-- logs --'
journalctl -u leikwan-bond-sidecar-5.service -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5-iperf3-5201.service -u leikwan-bond-sidecar-5-iperf3-5202.service --since '-60 min' --no-pager -o short-iso | grep -E '07289|target=|token|bond|handshake|failed|error|WARN|ERROR|5201|5202|400|520|connection|accepted|8899|127\.0\.0\.1' | tail -260 || true
REMOTE
OUTER
