#!/usr/bin/env bash
set -euo pipefail
passfile="$1"
sshpass -f "$passfile" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 root@47.116.11.144 'bash -s' <<'REMOTE'
set -uo pipefail
echo "SLAVE316 $(hostname) $(date -Is)"
echo --units--
for u in leikwan-tcp-mux-7000.service wstunnel-svc-2-316.service; do echo "$u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo --process--
ps -eo pid,ppid,lstart,cmd | grep -E 'leikwan-ingress mux|wstunnel.*07289|wstunnel-svc-2-316|127\.0\.0\.1:7013|8899|2053' | grep -v grep || true
echo --ss--
ss -ltnp | grep -E ':(7000|7013|7002|8899|2053)\b' || true
echo --files--
for p in /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json /etc/systemd/system/wstunnel-svc-2-316.service /var/lib/leikwan-slave/listeners/7000.json /etc/systemd/system/leikwan-tcp-mux-7000.service; do [ -e "$p" ] && { echo "###$p"; sed -n '1,220p' "$p" | head -220; }; done
echo --probe-ingress-ping--
AUTH=$(python3 - <<'PY'
import json
p='/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json'
d=json.load(open(p)); print(d.get('ingress_auth_token',''))
PY
)
/usr/local/bin/leikwan-ingress probe --remote 127.0.0.1:7000 --route-key 07289d1f5ed25399 --auth-token "$AUTH" >/tmp/codex-probe316.out 2>&1; echo rc=$?; cat /tmp/codex-probe316.out
echo --tcp to sidecar/edge--
python3 - <<'PY'
import socket,time
for host,port in [('185.220.238.143',8899),('185.220.238.143',2053),('162.159.39.4',2053),('162.159.39.29',2053),('104.16.0.3',2053)]:
 vals=[]; errs=[]
 for i in range(3):
  t=time.time()
  try:
   s=socket.create_connection((host,port),timeout=2); s.close(); vals.append(round((time.time()-t)*1000,1))
  except Exception as e: errs.append(type(e).__name__+':'+str(e))
 print(host,port,'vals',vals,'errs',errs[:2])
PY
echo --logs--
journalctl -u leikwan-tcp-mux-7000.service -u wstunnel-svc-2-316.service --since '-90 min' --no-pager -o short-iso | grep -E 'Opening TCP|TLS handshake|Invalid status|websocket|Closing|plain tcp mux|open route|failed|error|WARN|ERROR|8899|07289|520|Connection refused|reset' | tail -260 || true
REMOTE