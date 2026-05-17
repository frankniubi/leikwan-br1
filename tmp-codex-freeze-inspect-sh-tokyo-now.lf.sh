#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143; SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== freeze client unrelated ==='
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
for u in leikwan-client-40.service leikwan-client-48.service leikwan-client-59.service; do systemctl stop "$u" >/dev/null 2>&1 || true; systemctl disable "$u" >/dev/null 2>&1 || true; done
pkill -f 'leikwan-client-start-59|--listen 0\.0\.0\.0:32501|--listen 127\.0\.0\.1:32501|:2218[3-9]|:2238[3-9]' 2>/dev/null || true
sleep 1
echo "client date=$(date -Is) agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null)"
for u in leikwan-client-47-onegood.service leikwan-client-47.service leikwan-client-59.service; do echo "$u $(systemctl is-active $u 2>/dev/null)/$(systemctl is-enabled $u 2>/dev/null) pid=$(systemctl show -p MainPID --value $u 2>/dev/null)"; done
ss -ltnp | grep -E ':(11035|22740|32501|2218[3-9]|2238[3-9])\b' || true
REMOTE

echo '=== slave317 service/path ==='
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-slave-agent.service >/dev/null 2>&1 || true
echo "slave date=$(date -Is) agent=$(systemctl is-active leikwan-slave-agent.service 2>/dev/null)/$(systemctl is-enabled leikwan-slave-agent.service 2>/dev/null)"
for u in leikwan-tcp-mux-7000.service wstunnel-svc-2-317.service; do echo "$u $(systemctl is-active $u 2>/dev/null)/$(systemctl is-enabled $u 2>/dev/null) pid=$(systemctl show -p MainPID --value $u 2>/dev/null)"; done
ss -ltnp | grep -E ':(7000|7002)\b' || true
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service 2>/dev/null); [ -n "$pid" ] && [ "$pid" != 0 ] && { echo -n 'wst_cmd='; tr '\0' ' ' </proc/$pid/cmdline; echo; }
echo '-- routes --'; sed -n '1,160p' /var/lib/leikwan-slave/routes-7000.json 2>/dev/null || true
echo '-- svc json --'; sed -n '1,200p' /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json 2>/dev/null || true
REMOTE

echo '=== sidecar via slave317 ==='
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE "SIDE=$SIDE SIDEPASS='$SIDEPASS' bash -s" <<'OUTER'
set +e
command -v sshpass >/dev/null 2>&1 || (apt-get update >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y sshpass >/dev/null)
sshpass -p "$SIDEPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 root@$SIDE 'bash -s' <<'REMOTE'
set +e
echo "sidecar date=$(date -Is) host=$(hostname)"
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service leikwan-bond-sidecar-5-iperf3-5201.service leikwan-bond-sidecar-5-iperf3-5202.service; do echo "$u $(systemctl is-active $u 2>/dev/null)/$(systemctl is-enabled $u 2>/dev/null) pid=$(systemctl show -p MainPID --value $u 2>/dev/null)"; done
ss -ltnp | grep -E ':(2053|8899|8898|5201|5202)\b' || true
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /etc/leikwan-sidecar-5/cf-routes-2053.json /etc/systemd/system/leikwan-bond-sidecar-5.service /etc/systemd/system/leikwan-bond-sidecar-5-cf-2053.service; do [ -e "$p" ] && { echo "--- $p"; sed -n '1,180p' "$p"; }; done
python3 - <<'PY'
import socket,time,statistics
for port in [8899,8898,2053,5201,5202]:
 vals=[]; errs=[]
 for i in range(5):
  t=time.time()
  try:
   s=socket.create_connection(('127.0.0.1',port),timeout=1.5); vals.append((time.time()-t)*1000); s.close()
  except Exception as e: errs.append(type(e).__name__+':'+str(e))
 print('CONNECT',port,'ok',len(vals),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:2])
PY
REMOTE
OUTER