#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)

echo '=== prepare endpoint317: freeze slave agent ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'systemctl disable --now leikwan-slave-agent.service >/dev/null 2>&1 || true; systemctl is-active leikwan-slave-agent.service || true; systemctl is-active wstunnel-svc-2-317.service leikwan-tcp-mux-7000.service || true'

scan_edge() {
  local edge="$1"
  echo "=== EDGE $edge $(date -Is) ==="
  sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE='$edge' python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-317.service')
text=svc.read_text()
text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text)
svc.write_text(text)
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
if meta.exists():
    data=json.loads(meta.read_text())
    data['selected_cf_ip']=edge
    data.setdefault('cf_mode',{})['manual_minimum_good_path_edge']=edge
    meta.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
PY
systemctl daemon-reload; systemctl restart wstunnel-svc-2-317.service; sleep .7; systemctl is-active wstunnel-svc-2-317.service" >/dev/null || { echo "edge=$edge slave_update_failed"; return; }
  sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "EDGE='$edge' ROUTE='$ROUTE' AUTH='$AUTH' SECRET='$SECRET' bash -s" <<'REMOTE'
set +e
BASE=/tmp/codex-edge-scan-317
mkdir -p "$BASE"
EDGE=${EDGE:?}; ROUTE=${ROUTE:?}; AUTH=${AUTH:?}; SECRET=${SECRET:?}
# Use deterministic edge-derived ports to avoid collisions.
last=${EDGE##*.}
ingress=$((24000 + last))
listen=$((25000 + last))
pkill -f "leikwan-ingress client --listen 127\.0\.0\.1:${ingress}" 2>/dev/null || true
pkill -f "leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:${listen}" 2>/dev/null || true
rm -f "$BASE/$EDGE".*
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${ingress} --remote 47.116.104.15:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/$EDGE.ingress" 2>&1 & ipid=$!
for i in $(seq 1 40); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$ingress),timeout=.25); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode(); payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${listen} \
  --legs "tcp://127.0.0.1:${ingress}?priority=10&endpoint_id=317&display_host=47.116.104.15" \
  --session-token "$TOKEN" --chunk-size 65536 --max-window-bytes 8388608 --retransmit-ms 500 \
  --connect-timeout-ms 2800 --join-timeout-ms 700 --interactive-chunk-bytes 8192 \
  --latency-guard-bytes 65536 --warmup-trigger-bytes 1048576 --warmup-slice-bytes 65536 \
  --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 2000 >"$BASE/$EDGE.bond" 2>&1 & bpid=$!
for i in $(seq 1 60); do ss -ltn | grep -q ":${listen} " && break || sleep .1; done
python3 - <<PY >"$BASE/$EDGE.result"
import socket,time,json,statistics
vals=[]; errs=[]
for i in range(5):
    t=time.time(); data=b''; err=''
    try:
        s=socket.create_connection(('127.0.0.1',$listen),timeout=3.5)
        s.settimeout(3.5)
        data=s.recv(96)
        s.close()
    except Exception as e:
        err=type(e).__name__+':'+str(e)
    ms=(time.time()-t)*1000
    if data.startswith(b'SSH-'):
        vals.append(round(ms,1))
    else:
        errs.append({'ms':round(ms,1),'err':err,'hex':data[:16].hex()})
    time.sleep(.15)
print(json.dumps({'edge':'$EDGE','ok':len(vals),'fail':len(errs),'min':min(vals) if vals else None,'avg':round(statistics.mean(vals),1) if vals else None,'vals':vals,'errs':errs[:2]}, ensure_ascii=False))
PY
cat "$BASE/$EDGE.result"
echo '--bond markers--'
grep -E 'session established|failed to read bond frame header|invalid bond frame magic|Invalid status code|failed to do websocket|no bond legs|timed out waiting|WARN|ERROR' "$BASE/$EDGE.bond" | tail -8 || true
echo '--ingress markers--'
tail -5 "$BASE/$EDGE.ingress" || true
kill $bpid $ipid 2>/dev/null || true
REMOTE
}

# A focused set around known fast Shanghai CF anycast edges.
edges=(162.159.39.{1..30} 162.159.36.{1..12} 172.64.0.{1..8} 104.16.0.{1..8})
for edge in "${edges[@]}"; do scan_edge "$edge"; done

echo '=== restore known usable 162.159.39.4 ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE='162.159.39.4' python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-317.service')
text=svc.read_text(); text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text); svc.write_text(text)
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
if meta.exists():
 data=json.loads(meta.read_text()); data['selected_cf_ip']=edge; data.setdefault('cf_mode',{})['manual_minimum_good_path_edge']=edge; meta.write_text(json.dumps(data, ensure_ascii=False, indent=2)+'\n')
PY
systemctl daemon-reload; systemctl restart wstunnel-svc-2-317.service; sleep 1; systemctl is-active wstunnel-svc-2-317.service" || true