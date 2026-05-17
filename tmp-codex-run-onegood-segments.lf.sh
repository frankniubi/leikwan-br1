set -u
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is)"
echo "services: agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true) f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|22740|39559|5201)' || true
echo '--- onegood unit ---'; systemctl cat leikwan-client-47-onegood.service | sed -n '1,120p'
echo '--- onegood start ---'; sed -n '1,220p' /usr/local/bin/leikwan-client-start-47-onegood.sh 2>/dev/null || true
echo '--- pre key logs ---'
journalctl -u leikwan-client-47-onegood.service --since '-15 min' --no-pager -o short-iso | grep -E 'session established|invalid bond frame magic|failed to read bond frame header|recv_reset|local_write_failed|entering cooldown|WARN|ERROR|accepted|Opening|no bond legs|hello-ack' | tail -120 || true
echo '--- banner rtt 15 ---'
python3 - <<'PY'
import socket,time,statistics
vals=[]
for i in range(15):
    t=time.time(); ok=False; err=''; data=b''
    try:
        s=socket.create_connection(('127.0.0.1',11035),timeout=3); s.settimeout(3); data=s.recv(80); ok=data.startswith(b'SSH-'); s.close()
    except Exception as e: err=repr(e)
    ms=(time.time()-t)*1000
    if ok: vals.append(ms)
    print({'i':i+1,'ok':ok,'ms':round(ms,1),'data':data[:28].decode('latin1','replace'),'err':err})
    time.sleep(.2)
if vals: print('summary', {'min':round(min(vals),1),'avg':round(statistics.mean(vals),1),'vals':[round(v,1) for v in vals]})
PY
echo '--- direct sidecar iperf -R -b 300M 5s (if reachable from client) ---'
timeout 18 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 --connect-timeout 3000 > /tmp/codex-direct-sidecar5201.out 2>&1; echo direct_rc=$?; cat /tmp/codex-direct-sidecar5201.out
echo '--- onegood forward iperf -R -b 300M 8s ---'
timeout 30 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 8 --connect-timeout 3000 > /tmp/codex-onegood47-current-iperf.out 2>&1; echo onegood_rc=$?; cat /tmp/codex-onegood47-current-iperf.out
echo '--- post key logs ---'
journalctl -u leikwan-client-47-onegood.service --since '-3 min' --no-pager -o short-iso | grep -E 'session established|invalid bond frame magic|failed to read bond frame header|recv_reset|local_write_failed|entering cooldown|WARN|ERROR|accepted|Opening|no bond legs|hello-ack|local_eof|send_fin|recv_fin' | tail -220 || true
REMOTE