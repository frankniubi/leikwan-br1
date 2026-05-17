set +e
echo '--- quick banner ---'
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(10):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:32].decode('latin1','replace')))
 time.sleep(.2)
print('ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:5])
PY
echo '--- iperf f47 onegood ---'
timeout 25 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 8 --connect-timeout 3000 2>&1 | sed -n '1,120p'; echo iperf_rc=${PIPESTATUS[0]}
echo '--- post logs ---'
journalctl -u leikwan-client-47-onegood.service --since '-2 min' --no-pager -o short-iso | grep -E 'session established|failed|invalid bond frame|hello|cooldown|local_read|local write|WARN|ERROR|recv_reset' | tail -120 || true