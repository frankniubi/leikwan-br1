set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== $(hostname) $(date -Is) ==="
echo --direct tcp sidecar5201--
python3 - <<'PY'
import socket,time
for host,port in [('185.220.238.143',5201),('127.0.0.1',11035)]:
  vals=[]; errs=[]
  for i in range(5):
    t=time.time()
    try:
      s=socket.create_connection((host,port),timeout=3); vals.append(round((time.time()-t)*1000,1)); s.close()
    except Exception as e: errs.append(type(e).__name__+':'+str(e))
    time.sleep(.1)
  print(host,port,'vals',vals,'errs',errs)
PY
echo --iperf f47--
timeout 14 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 6 --connect-timeout 3000 2>&1 | tee /tmp/codex-f47-iperf-now.out
rc=${PIPESTATUS[0]}; echo iperf_rc=$rc
echo --service status--; systemctl is-active leikwan-client-47.service; ss -ltnp | grep ':11035' || true
echo --bond snapshot--; cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null | python3 -m json.tool | sed -n '1,220p' || true
echo --recent logs key--; journalctl -u leikwan-client-47.service --since '-2 min' --no-pager -o short-iso | grep -E 'Invalid status code|520|invalid bond frame magic|failed to read bond frame header|session established|recv_reset|failed to connect upstream|Opening TCP|preferred_leg|send_stream_open|recv_stream_open_ack|local_write_failed|ERROR|WARN' | tail -220 || true
REMOTE