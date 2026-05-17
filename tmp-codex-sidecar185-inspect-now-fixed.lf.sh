#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SIDEPASS='GK@Sp7z%T!s4*w5P'
SPASS_ESC=${SPASS//%/%%}
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
PROXY="sshpass -p '$SPASS_ESC' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=10 -W %h:%p root@$SLAVE"
sshpass -p "$SIDEPASS" ssh "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" root@$SIDE 'bash -s' <<'REMOTE'
set +e
echo "=== sidecar185 $(date -Is) host=$(hostname) ==="
echo '-- services --'
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-mptcp-sidecar-5.service leikwan-bond-sidecar-5-iperf3-5201.service leikwan-bond-sidecar-5-iperf3-5202.service codex-iperf3-5201.service codex-iperf3-5202.service leikwan-sidecar-agent.service; do echo "$u $(systemctl is-active $u 2>/dev/null)/$(systemctl is-enabled $u 2>/dev/null) pid=$(systemctl show -p MainPID --value $u 2>/dev/null)"; done
echo '-- listeners --'
ss -ltnp | grep -E ':(2053|8899|8898|5201|5202|2600[0-9]|14969|1498[0-9])' || true
echo '-- processes --'
ps -eo pid,ppid,etimes,cmd | grep -E 'leikwan|wstunnel|iperf3|wss' | grep -v grep | sed -n '1,160p'
echo '-- routes/files --'
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar/cf-routes-2053.json /var/lib/leikwan-sidecar-agent/cf-routes-2053.json /etc/systemd/system/leikwan-bond-sidecar-5-cf-2053.service /etc/systemd/system/leikwan-bond-sidecar-5.service /usr/local/bin/leikwan-wss-mux.py /etc/leikwan-sidecar-5/bond-runtime.json /var/lib/leikwan-sidecar-5/bond-runtime.json; do
  [ -e "$p" ] || continue
  echo "--- $p"; ls -l "$p"; sha256sum "$p" 2>/dev/null || true; sed -n '1,240p' "$p" 2>/dev/null | head -240
done
echo '-- local target probes --'
python3 - <<'PY'
import socket,time,statistics
for host,port in [('127.0.0.1',8899),('127.0.0.1',8898),('127.0.0.1',5201),('127.0.0.1',5202),('127.0.0.1',2053)]:
    vals=[]; errs=[]
    for i in range(5):
        t=time.time()
        try:
            s=socket.create_connection((host,port),timeout=1.5); vals.append(round((time.time()-t)*1000,1)); s.close()
        except Exception as e: errs.append(type(e).__name__+':'+str(e))
        time.sleep(.05)
    print(host,port,'vals',vals,'errs',errs)
PY
echo '-- recent sidecar logs --'
journalctl -u leikwan-bond-sidecar-5.service -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5-iperf3-5201.service -u leikwan-bond-sidecar-5-iperf3-5202.service -u leikwan-sidecar-agent.service --since '-2 hours' --no-pager -o short-iso | grep -E '07289|token verified|target=|bond leg|handshake|Invalid status|failed|error|WARN|ERROR|5201|5202|Opening|Closing|400|520|accepted|127.0.0.1:8899' | tail -260 || true
REMOTE