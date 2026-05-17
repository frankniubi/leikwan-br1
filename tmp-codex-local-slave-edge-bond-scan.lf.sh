#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
ROUTE='07289d1f5ed25399'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
EDGES='162.159.39.4 162.159.39.6 162.159.39.7 162.159.39.8 162.159.39.29 104.16.0.3 104.16.0.4 172.64.0.3 162.159.36.3'
python3 - <<'PY' > /tmp/codex_shanghai_creds.tsv
from pathlib import Path
for raw in Path('shanghais.txt').read_bytes().replace(b'\r',b'\n').splitlines():
    if not raw.strip(): continue
    parts=raw.decode('utf-8',errors='ignore').split('|')
    if len(parts)>=4: print('\t'.join([parts[0],parts[2],parts[3]]))
PY
while IFS=$'\t' read -r host user pass; do
  [ -z "${host:-}" ] && continue
  echo "=== SLAVE_LOCAL_BOND host=$host $(date -Is) ==="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" "ROUTE='$ROUTE' SECRET='$SECRET' EDGES='$EDGES' bash -s" <<'REMOTE'
set +euo pipefail
BASE=/tmp/codex-edge-bond-local-$$; mkdir -p "$BASE"
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -s "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; rm -rf "$BASE"; }
trap cleanup EXIT
mk_token(){ python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode()
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
free_port(){ python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
}
if ! command -v /usr/local/bin/wstunnel >/dev/null || ! command -v /usr/local/bin/leikwan-bond >/dev/null; then echo 'missing binaries'; exit 0; fi
hostname; date -Is
for edge in $EDGES; do
  wport=$(free_port); lport=$(free_port); token=$(mk_token)
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${wport}:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers "Host: nrt1.nbwly.com" --http-upgrade-path-prefix "$ROUTE" wss://${edge}:2053 >"$BASE/wst-$edge.log" 2>&1 & echo $! >"$BASE/wst-$edge.pid"
  for i in $(seq 1 50); do python3 - <<PY >/dev/null 2>&1 && break || sleep .05
import socket
s=socket.create_connection(('127.0.0.1',$wport),timeout=.2); s.close()
PY
  done
  RUST_LOG=warn /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${lport} --legs "tcp://127.0.0.1:${wport}?priority=10&display_host=$edge" --session-token "$token" --chunk-size 65536 --max-window-bytes 8388608 --retransmit-ms 500 --connect-timeout-ms 3000 --join-timeout-ms 800 --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 --warmup-trigger-bytes 1048576 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 0 --bulk-promote-bytes 1073741824 --leg-cooldown-ms 5000 >"$BASE/bond-$edge.log" 2>&1 & echo $! >"$BASE/bond-$edge.pid"
  for i in $(seq 1 80); do ss -ltn | grep -q ":$lport " && break || sleep .05; done
  python3 - "$edge" "$lport" "$BASE/bond-$edge.log" "$BASE/wst-$edge.log" <<'PY'
import socket,sys,time,pathlib,re,statistics
edge,lport,bp,wp=sys.argv[1],int(sys.argv[2]),pathlib.Path(sys.argv[3]),pathlib.Path(sys.argv[4])
vals=[]; errs=[]; banners=[]
for i in range(5):
    t=time.time()
    try:
        s=socket.create_connection(('127.0.0.1',lport),timeout=3.0)
        s.settimeout(3.0)
        data=s.recv(80)
        dt=(time.time()-t)*1000
        s.close()
        vals.append(dt); banners.append(data[:24].decode('latin1','replace').strip())
    except Exception as e:
        errs.append(type(e).__name__+':'+str(e))
    time.sleep(.1)
bond=bp.read_text(errors='ignore') if bp.exists() else ''
wst=wp.read_text(errors='ignore') if wp.exists() else ''
keys=[]
for text in [bond,wst]:
    for pat in ['Invalid status code: 520','failed to do websocket handshake','invalid bond frame magic','failed to read bond frame header','no bond legs acknowledged','session established','Connection refused','Broken pipe']:
        c=text.count(pat)
        if c: keys.append(f'{pat}={c}')
print('EDGE_RESULT', edge, 'ok', len(vals), 'fail', len(errs), 'min', round(min(vals),1) if vals else None, 'avg', round(statistics.mean(vals),1) if vals else None, 'banners', banners[:2], 'errs', errs[:2], 'keys', ';'.join(keys))
PY
  kill $(cat "$BASE/bond-$edge.pid") 2>/dev/null || true
  kill $(cat "$BASE/wst-$edge.pid") 2>/dev/null || true
  sleep .2
done
REMOTE
done < /tmp/codex_shanghai_creds.tsv
