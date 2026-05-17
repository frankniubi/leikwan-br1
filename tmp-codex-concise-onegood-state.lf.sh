set -u
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== client concise ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null || true)"
ss -ltnp | grep -E ':(11035|22740)' || true
echo '--- banner summary ---'
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(20):
 t=time.time(); ok=False; err=''; data=b''
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=3); s.settimeout(3); data=s.recv(80); ok=data.startswith(b'SSH-'); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if ok: vals.append(ms)
 else: errs.append((round(ms,1),err,data[:16].hex()))
 time.sleep(.1)
print('ok_count',len(vals),'fail_count',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals[:20]],'errs',errs[:5])
PY
echo '--- direct sidecar iperf last ---'; cat /tmp/codex-direct-sidecar5201.out 2>/dev/null || true
echo '--- onegood iperf last ---'; cat /tmp/codex-onegood47-current-iperf.out 2>/dev/null || true
echo '--- key log counts last 10m ---'
for pat in 'invalid bond frame magic' 'failed to read bond frame header' 'timed out waiting for bond hello-ack' 'bond client TCP transport session established' 'entering cooldown' 'local_read_failed' 'local_write_failed'; do c=$(journalctl -u leikwan-client-47-onegood.service --since '-10 min' --no-pager | grep -c "$pat" || true); echo "$pat=$c"; done
echo '--- recent key lines ---'
journalctl -u leikwan-client-47-onegood.service --since '-4 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|hello-ack|session established|entering cooldown|local_read_failed|local_write_failed|Connection reset|Broken pipe|WARN|ERROR' | tail -80 || true
REMOTE
echo '=== slave317 concise ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
systemctl is-active wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service || true
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service); echo pid=$pid; [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
cat /var/lib/leikwan-slave/services/wstunnel-svc-2-317.json 2>/dev/null | python3 -m json.tool | sed -n '1,220p' || true
echo '--- ports ---'; ss -ltnp | grep -E ':(7000|22740|8899|8898)' || true
echo '--- key log counts last 10m ---'
for pat in 'Invalid status code: 520' 'failed to do websocket handshake' 'Opening TCP connection' 'Closing' 'Connection refused' 'error' 'WARN'; do c=$(journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since '-10 min' --no-pager | grep -ci "$pat" || true); echo "$pat=$c"; done
echo '--- recent key lines ---'
journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since '-4 min' --no-pager -o short-iso | grep -E 'Invalid status code|failed to do websocket|Opening TCP|Closing|plain tcp mux|accepted|error|WARN|127.0.0.1:8899|07289|520' | tail -120 || true
REMOTE