set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
EDGE=162.159.39.4
ROUTE='07289d1f5ed25399'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE ROUTE=$ROUTE bash -s" <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
# ensure edge current
python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-317.service')
text=svc.read_text(); text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text); svc.write_text(text)
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
if meta.exists():
 data=json.loads(meta.read_text()); data['selected_cf_ip']=edge; data.setdefault('cf_mode',{})['manual_minimum_good_path_edge']=edge; meta.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
PY
systemctl daemon-reload; systemctl restart wstunnel-svc-2-317.service; sleep 1; systemctl is-active wstunnel-svc-2-317.service
BASE=/tmp/codex-raw-wst317
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
LP=33940
/usr/local/bin/wstunnel client -L tcp://127.0.0.1:${LP}:127.0.0.1:22 --tls-sni-override nrt1.nbwly.com --http-headers "Host: nrt1.nbwly.com" --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst.log" 2>&1 & echo $! >"$BASE/wst.pid"
for i in $(seq 1 40); do timeout .2 bash -c "</dev/tcp/127.0.0.1/$LP" >/dev/null 2>&1 && break || sleep .1; done
python3 - <<'PY'
import socket,time,statistics
vals=[]; fails=[]
for i in range(12):
 t=time.time(); ok=False; data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',33940),timeout=3); s.settimeout(3); data=s.recv(80); ok=data.startswith(b'SSH-'); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if ok: vals.append(ms)
 else: fails.append({'ms':round(ms,1),'err':err,'data':data[:16].decode('latin1','replace')})
 time.sleep(.1)
print({'ok':len(vals),'fail':len(fails),'min':round(min(vals),1) if vals else None,'avg':round(statistics.mean(vals),1) if vals else None,'vals':[round(v,1) for v in vals], 'fails':fails[:3]})
PY
echo '-- wst log --'; grep -E 'Invalid status|failed to do websocket|Opening TCP|TLS handshake|Closing|ERROR|WARN' "$BASE/wst.log" | tail -80 || true
REMOTE