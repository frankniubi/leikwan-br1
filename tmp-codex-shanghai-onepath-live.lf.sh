#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
while IFS='|' read -r host region user pass rest; do
  [ -n "$host" ] || continue
  echo "===== $host ====="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'bash -s' <<'REMOTE' || true
set +e
echo "host=$(hostname) time=$(date -Is)"
echo '-- tcp latency to tokyo sidecar 185.220.238.143 ports --'
python3 - <<'PY'
import socket,time,statistics
host='185.220.238.143'
for port in [8898,8899,2053,5201,22]:
    vals=[]; errs=[]
    for i in range(5):
        t=time.time()
        try:
            s=socket.create_connection((host,port),timeout=2); s.close(); vals.append((time.time()-t)*1000)
        except Exception as e: errs.append(type(e).__name__+':'+str(e)[:60])
        time.sleep(.1)
    if vals: print(f'{host}:{port} min={min(vals):.1f} avg={statistics.mean(vals):.1f} vals='+','.join(f'{v:.1f}' for v in vals))
    else: print(f'{host}:{port} FAIL {errs[:2]}')
PY
echo '-- route 07289 on slave --'
for p in /var/lib/leikwan-slave-agent/listeners/routes-7000.json /var/lib/leikwan-slave-agent/routes-7000.json /var/lib/leikwan-slave/services/wstunnel-svc-2-*.json /var/lib/leikwan-slave-agent/services/wstunnel-svc-2-*.json; do
  [ -e "$p" ] && { echo "--- $p"; grep -nE '07289d1f5ed25399|backend_protocol|ingress_protocol|backend_host|backend_port|target|cf_mode|dial_host|selected_cf_ip|8898|8899' "$p" | head -80; }
done
echo '-- services --'
systemctl is-active leikwan-slave-agent.service leikwan-tcp-mux-7000.service 2>/dev/null || true
ss -ltnp | grep -E ':(7000|8898|8899|2053|521[0-9]+|621[0-9]+)' | head -80 || true
journalctl -u leikwan-tcp-mux-7000.service -u leikwan-slave-agent.service --since '-30 min' --no-pager -o short-iso 2>/dev/null | grep -E '07289d1f5ed25399|invalid bond|bond frame|route|backend|ERROR|WARN|plain tcp mux|tcp mux|wstunnel-svc' | tail -80 || true
REMOTE
  echo
done < shanghais.txt
