#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo "=== CLIENT F47 snapshot $(date -Is) ==="
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
for u in leikwan-client-47.service leikwan-client-47-onegood.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) MainPID=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- f47/onegood units --'; for p in /etc/systemd/system/leikwan-client-47.service /etc/systemd/system/leikwan-client-47-onegood.service; do [ -e "$p" ] && { echo "--- $p"; sed -n '1,80p' "$p"; }; done
echo '-- f47 start --'; sed -n '1,220p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
echo '-- onegood start --'; sed -n '1,220p' /usr/local/bin/leikwan-client-start-47-onegood.sh 2>/dev/null || true
echo '-- listeners --'; ss -ltnp | grep -E ':(11035|22740|22739|21739|21740|39559|7000|32501)\b' || true
echo '-- recent f47/onegood logs --'; journalctl -u leikwan-client-47.service -u leikwan-client-47-onegood.service --since '-4 hours' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP|local_read_failed|local_write_failed|recv_reset|cooldown|WARN|ERROR|started|Stopped' | tail -220 || true
REMOTE

echo "=== SLAVE317 snapshot $(date -Is) ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
for u in leikwan-slave-agent.service wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) MainPID=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listeners --'; ss -ltnp | grep -E ':(7000|7001|7002|7329)\b' || true
echo '-- service metadata --'; for p in /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json /var/lib/leikwan-slave/routes-7000.json /etc/systemd/system/wstunnel-svc-2-317.service /etc/systemd/system/leikwan-tcp-mux-7000.service; do [ -e "$p" ] && { echo "--- $p"; sed -n '1,220p' "$p"; }; done
echo '-- wstunnel cmd --'; pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service 2>/dev/null); [ -n "$pid" ] && [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
echo '-- recent logs --'; journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service -u leikwan-slave-agent.service --since '-4 hours' --no-pager -o short-iso | grep -E '07289|8899|8898|7002|invalid bond|bond frame|Opening TCP|TLS handshake|Invalid status|failed to do websocket|plain tcp mux|route|backend|ERROR|WARN|failed' | tail -220 || true
REMOTE

echo "=== SIDECAR5 via slave317 snapshot $(date -Is) ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "SIDE=$SIDE SIDEPASS='$SIDEPASS' bash -s" <<'OUTER'
set +e
command -v sshpass >/dev/null 2>&1 || (apt-get update >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y sshpass >/dev/null)
sshpass -p "$SIDEPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 root@$SIDE 'bash -s' <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service leikwan-bond-sidecar-5-iperf3-5201.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) MainPID=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listeners --'; ss -ltnp | grep -E ':(2053|8899|8898|5201|5202)\b' || true
echo '-- route/config files --'; for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /etc/systemd/system/leikwan-bond-sidecar-5.service /etc/systemd/system/leikwan-bond-sidecar-5-cf-2053.service /var/lib/leikwan-sidecar-5/bond-runtime.json /etc/leikwan-sidecar-5/bond-runtime.json; do [ -e "$p" ] && { echo "--- $p"; sed -n '1,220p' "$p"; }; done
echo '-- local probes --'; python3 - <<'PY'
import socket,time,statistics
for port in [8899,8898,5201,5202]:
 vals=[]; errs=[]
 for i in range(3):
  t=time.time()
  try:
   s=socket.create_connection(('127.0.0.1',port),timeout=1.5); vals.append((time.time()-t)*1000); s.close()
  except Exception as e: errs.append(type(e).__name__+':'+str(e))
 print(port,'ok',len(vals),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:2])
PY
echo '-- recent logs --'; journalctl -u leikwan-bond-sidecar-5.service -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5-iperf3-5201.service --since '-4 hours' --no-pager -o short-iso | grep -E '07289|target=|token|bond leg|handshake|invalid bond|failed|error|WARN|ERROR|5201|5202|400|520|connection|accepted|8899|local_read|recv_reset|cooldown' | tail -260 || true
REMOTE
OUTER