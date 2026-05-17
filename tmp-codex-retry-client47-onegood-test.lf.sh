#!/usr/bin/env bash
set -u
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
remote_client(){
  local rc=1
  for attempt in 1 2 3 4 5 6; do
    sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname) agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null) onegood=$(systemctl is-active leikwan-client-47-onegood.service 2>/dev/null) svc47=$(systemctl is-active leikwan-client-47.service 2>/dev/null)"
echo '--- listeners/processes ---'
ss -ltnp | grep -E ':(11035|22740)' || true
ps -eo pid,etimes,cmd | grep -E 'leikwan-client-start-47-onegood|leikwan-bond client --protocol tcp --listen 127.0.0.1:11035|leikwan-ingress client --listen 127.0.0.1:22740' | grep -v grep || true
echo '--- banner RTT probe ---'
python3 - <<'PY'
import socket,time,statistics
vals=[]; failures=0
for i in range(12):
 t=time.time(); ok=False; data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=2); s.settimeout(3); data=s.recv(96); ok=data.startswith(b'SSH-'); s.close()
 except Exception as e: err=repr(e); failures+=1
 ms=(time.time()-t)*1000
 if ok: vals.append(ms)
 print({'i':i+1,'ok':ok,'ms':round(ms,1),'data':data[:36].decode('latin1','replace'),'err':err})
 time.sleep(.3)
print('banner_summary', {'ok':len(vals),'failures':failures,'min':round(min(vals),1) if vals else None,'avg':round(statistics.mean(vals),1) if vals else None,'vals':[round(v,1) for v in vals]})
PY
for mode in fwd rev; do
  echo "--- iperf $mode -b 300M -t 8 ---"
  flag=''
  [ "$mode" = rev ] && flag='-R'
  timeout 26 bash -c "iperf3 -c 127.0.0.1 -p 11035 $flag -b 300M -t 8 --connect-timeout 3000" > /tmp/codex-iperf47-$mode.out 2>&1
  rc=$?
  echo iperf_${mode}_rc=$rc
  cat /tmp/codex-iperf47-$mode.out
  sleep 2
done
echo '--- bond snapshot ---'
cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
echo '--- logs key ---'
journalctl -u leikwan-client-47-onegood.service --since '-8 min' --no-pager -o short-iso | grep -E 'invalid bond frame magic|failed to read bond frame header|no bond legs|session established|TCP transport|failed|WARN|ERROR|cooldown|recv_reset|local_write_failed|stream_open|close_timeout|local_eof|send_fin|recv_stream_open_ack' | tail -260 || true
REMOTE
    rc=$?
    if [ $rc -eq 0 ]; then return 0; fi
    echo "client ssh attempt $attempt failed rc=$rc" >&2
    sleep $((attempt*6))
  done
  return $rc
}
remote_client
rc=$?
echo "client_test_rc=$rc"
echo '=== slave317 logs ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-317.service -u leikwan-tcp-mux-7000.service --since "-10 min" --no-pager -o short-iso | grep -E "Invalid status code|failed to do websocket|Opening TCP|TLS handshake|Closing|plain tcp mux|ERROR|WARN|8899|07289" | tail -220 || true'
exit $rc