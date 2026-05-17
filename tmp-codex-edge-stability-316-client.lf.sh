#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
EDGES=(162.159.39.4 162.159.39.7 162.159.39.8 162.159.39.29 162.159.39.6 104.16.0.3)
set_edge() {
  local edge=$1
  sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE='$edge' bash -s" <<'REMOTE'
set -euo pipefail
svc=/etc/systemd/system/wstunnel-svc-2-316.service
meta=/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json
python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json')
if meta.exists():
    data=json.loads(meta.read_text())
    data['selected_cf_ip']=edge
    data.setdefault('cf_mode', {})['manual_minimum_good_path_edge']=edge
    meta.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-316.service')
text=svc.read_text()
text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text)
svc.write_text(text)
PY
systemctl daemon-reload
systemctl restart wstunnel-svc-2-316.service
sleep 0.6
systemctl is-active wstunnel-svc-2-316.service >/dev/null
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-316.service)
cmd=$(tr '\0' ' ' </proc/$pid/cmdline)
echo "$cmd"
REMOTE
}
probe_client() {
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE='$ROUTE' AUTH='$AUTH' SECRET='$SECRET' bash -s" <<'REMOTE'
set -euo pipefail
pkill -f 'leikwan-ingress client --listen 127.0.0.1:29140' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127.0.0.1:29141' 2>/dev/null || true
LOGDIR=/tmp/codex-oneleg-316
rm -rf "$LOGDIR"; mkdir -p "$LOGDIR"
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29140 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$LOGDIR/ingress.log" 2>&1 & echo $! >"$LOGDIR/ingress.pid"
sleep 0.25
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
secret=os.environ['SECRET'].encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29141 --legs 'tcp://127.0.0.1:29140?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 900 >"$LOGDIR/bond.log" 2>&1 & echo $! >"$LOGDIR/bond.pid"
python3 - <<'PY'
import socket,time,json
started=time.time(); banner=b''; err=''
for i in range(80):
    try:
        s=socket.create_connection(('127.0.0.1',29141),timeout=0.18)
        s.settimeout(2.0)
        try: banner=s.recv(80)
        except Exception as e: err=type(e).__name__+':'+str(e)[:60]
        s.close(); break
    except Exception as e:
        err=type(e).__name__+':'+str(e)[:60]
        time.sleep(0.05)
print(json.dumps({'ok': banner.startswith(b'SSH-'), 'ms': round((time.time()-started)*1000,1), 'banner': banner[:40].decode('latin1','replace'), 'err': err}))
PY
sleep 0.4
python3 - <<'PY'
from pathlib import Path
import json
bond=Path('/tmp/codex-oneleg-316/bond.log').read_text(errors='ignore') if Path('/tmp/codex-oneleg-316/bond.log').exists() else ''
ing=Path('/tmp/codex-oneleg-316/ingress.log').read_text(errors='ignore') if Path('/tmp/codex-oneleg-316/ingress.log').exists() else ''
print(json.dumps({'established':bond.count('session established'), 'eof':bond.count('failed to read bond frame header'), 'no_legs':bond.count('no bond legs acknowledged'), 'ingress_tail':'\n'.join(ing.splitlines()[-3:])[:300]}))
PY
kill $(cat "$LOGDIR/bond.pid") $(cat "$LOGDIR/ingress.pid") 2>/dev/null || true
REMOTE
}
: > tmp-codex-edge-stability-316-results.jsonl
for edge in "${EDGES[@]}"; do
  echo "===== edge $edge ====="
  set_edge "$edge" | sed -n '1,3p'
  sleep 0.5
  for n in 1 2 3; do
    echo "-- trial $n"
    out=$(probe_client 2>&1 || true)
    echo "$out" | sed -n '1,8p'
    python3 - "$edge" "$n" "$out" <<'PY' >> tmp-codex-edge-stability-316-results.jsonl
import json,sys,re
edge=sys.argv[1]; n=int(sys.argv[2]); text=sys.argv[3]
objs=[]
for line in text.splitlines():
    line=line.strip()
    if line.startswith('{'):
        try: objs.append(json.loads(line))
        except Exception: pass
row={'edge':edge,'trial':n,'raw':text[:400]}
for obj in objs: row.update(obj)
print(json.dumps(row,ensure_ascii=False))
PY
    sleep 0.7
  done
  echo
done
python3 - <<'PY'
import json,collections,statistics
rows=[json.loads(l) for l in open('tmp-codex-edge-stability-316-results.jsonl',encoding='utf-8')]
print('=== SUMMARY ===')
by=collections.defaultdict(list)
for r in rows: by[r['edge']].append(r)
for edge,items in by.items():
    oks=[r for r in items if r.get('ok')]
    ms=[r.get('ms') for r in oks if isinstance(r.get('ms'),(int,float))]
    print(edge,'ok',len(oks),'/',len(items),'min_ms',min(ms) if ms else None,'avg_ms',round(statistics.mean(ms),1) if ms else None,'eof',sum(int(r.get('eof',0) or 0) for r in items),'no_legs',sum(int(r.get('no_legs',0) or 0) for r in items))
PY