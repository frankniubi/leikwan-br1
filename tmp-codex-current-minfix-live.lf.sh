#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
EDGE='162.159.39.4'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo "=== CLIENT freeze/current $(date -Is) ==="
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
echo "host=$(hostname) date=$(date -Is) agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
for u in leikwan-client-47.service leikwan-client-47-onegood.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) MainPID=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listeners 11035/22740/32501 --'; ss -ltnp | grep -E ':(11035|22740|32501)\b' || true
echo '-- scoped processes --'; ps -eo pid,ppid,etimes,cmd | grep -E '11035|22740|leikwan-client-47-onegood|leikwan-client-47\.service' | grep -v grep || true
echo '-- onegood start script head --'; sed -n '1,120p' /usr/local/bin/leikwan-client-start-47-onegood.sh 2>/dev/null || true
echo '-- onegood logs key 15m --'; journalctl -u leikwan-client-47-onegood.service --since '-15 min' --no-pager -o short-iso | grep -E 'session established|failed to connect initial|invalid bond frame magic|failed to read bond frame|recv_reset|local_read_failed|local_write_failed|cooldown|WARN|ERROR' | tail -100 || true
REMOTE

echo "=== SLAVE317 current $(date -Is) ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE bash -s" <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
echo "slave_agent=$(systemctl is-active leikwan-slave-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-slave-agent.service 2>/dev/null || true)"
for u in wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) MainPID=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listeners 7000/7002 --'; ss -ltnp | grep -E ':(7000|7002)\b' || true
echo '-- wstunnel cmd --'; pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service 2>/dev/null); [ -n "$pid" ] && [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
echo '-- metadata --'; python3 - <<'PY'
import json,pathlib
p=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
if p.exists():
 d=json.loads(p.read_text())
 print(json.dumps({k:d.get(k) for k in ['service_name','forward_id','endpoint_id','listen_port','backend_port','target','selected_cf_ip','ingress_protocol','backend_protocol','backend_mode','transport_mode']}, ensure_ascii=False, indent=2))
 print('cf_mode', json.dumps(d.get('cf_mode',{}), ensure_ascii=False)[:1000])
PY
echo '-- edge tcp ms --'; python3 - <<'PY'
import os,socket,time,statistics
edge=os.environ.get('EDGE','162.159.39.4'); vals=[]; errs=[]
for i in range(10):
 t=time.time()
 try:
  s=socket.create_connection((edge,2053),timeout=2); vals.append((time.time()-t)*1000); s.close()
 except Exception as e: errs.append(repr(e))
 time.sleep(.08)
print('edge',edge,'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:3])
PY
echo '-- wstunnel logs key 20m --'; journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since '-20 min' --no-pager -o short-iso | grep -E 'Opening TCP|TLS handshake|Invalid status|failed to do websocket|Closing|error|WARN|ERROR|07289|8899|520' | tail -120 || true
REMOTE

echo "=== SIDECAR via slave317 current $(date -Is) ==="
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "SIDE=$SIDE SIDEPASS='$SIDEPASS' bash -s" <<'OUTER'
set +e
command -v sshpass >/dev/null 2>&1 || (apt-get update >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y sshpass >/dev/null)
sshpass -p "$SIDEPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o ConnectTimeout=12 -o ConnectionAttempts=1 root@$SIDE 'bash -s' <<'REMOTE'
set +e
echo "host=$(hostname) date=$(date -Is)"
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service leikwan-bond-sidecar-5-iperf3-5201.service leikwan-bond-sidecar-5-iperf3-5202.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) MainPID=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listeners --'; ss -ltnp | grep -E ':(2053|8899|8898|5201|5202|2600[0-9])\b' || true
echo '-- route files --'
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-5/mptcp-forward-routes.json /etc/leikwan-sidecar-5/bond-runtime.json /etc/systemd/system/leikwan-bond-sidecar-5-cf-2053.service /usr/local/bin/leikwan-wss-mux.py; do [ -e "$p" ] || continue; echo "--- $p"; ls -l "$p"; sed -n '1,220p' "$p" | head -220; done
echo '-- local port probes --'; python3 - <<'PY'
import socket,time,statistics
for port in [8899,8898,5201,5202]:
 vals=[]; errs=[]
 for i in range(3):
  t=time.time()
  try:
   s=socket.create_connection(('127.0.0.1',port),timeout=1.5); vals.append((time.time()-t)*1000); s.close()
  except Exception as e: errs.append(type(e).__name__+':'+str(e))
 print(port,'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'errs',errs[:2])
PY
echo '-- sidecar logs key 30m --'; journalctl -u leikwan-bond-sidecar-5.service -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5-iperf3-5201.service -u leikwan-bond-sidecar-5-iperf3-5202.service --since '-30 min' --no-pager -o short-iso | grep -E '07289|target=|token|bond leg|handshake|failed|error|WARN|ERROR|5201|5202|400|520|connection|accepted|8899' | tail -220 || true
REMOTE
OUTER