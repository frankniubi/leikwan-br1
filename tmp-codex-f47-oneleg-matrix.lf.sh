#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-f47-oneleg-matrix
rm -rf "$BASE"; mkdir -p "$BASE"
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
ROUTE=07289d1f5ed25399
cleanup(){ set +e; if [ -d "$BASE" ]; then for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; fi; }
trap cleanup EXIT
mk_token(){ python3 - "$1" "$2" <<'PY'
import base64,hashlib,hmac,json,sys,time
host=sys.argv[1]; port=int(sys.argv[2]); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":host,"target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
free_port(){ python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
}
cat > "$BASE/legs.txt" <<'EOF'
self|0|wstunnel|162.159.39.4|2053|
self8|0|wstunnel|162.159.39.8|2053|
316|316|ingress|47.116.11.144|7000|2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
317|317|ingress|47.116.104.15|7000|85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9
318|318|ingress|101.133.142.65|7000|bd5eb739e2676216288eba117a8e893d767fe6f7edb2260a11a728f44f7e3307
319|319|ingress|47.116.134.3|7000|48d684c9ff3c574205a556230f438faa3faf89f9113b4a28057d16dd7b792a53
320|320|ingress|8.133.170.123|7000|a5216e9a1b67b4519013060c24e06ab3951755b32b68ee17f4122cceae637f72
EOF
printf '{"time":"%s","tests":[\n' "$(date -Is)"
first=1
while IFS='|' read -r name eid kind host port auth; do
  [ -n "$name" ] || continue
  l1=$(free_port); l2=$(free_port)
  logp="$BASE/leg-$name.log"; bondlog="$BASE/bond-$name.log"
  if [ "$kind" = wstunnel ]; then
    /usr/local/bin/wstunnel client -L tcp://127.0.0.1:$l1:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix "$ROUTE" wss://$host:2053 >"$logp" 2>&1 & echo $! >"$BASE/leg-$name.pid"
  else
    /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:$l1 --remote $host:$port --route-key "$ROUTE" --auth-token "$auth" >"$logp" 2>&1 & echo $! >"$BASE/leg-$name.pid"
  fi
  sleep 0.25
  token=$(mk_token 127.0.0.1 22)
  legurl="tcp://127.0.0.1:$l1?priority=10&display_host=$name"
  [ "$eid" != 0 ] && legurl="tcp://127.0.0.1:$l1?priority=10&endpoint_id=$eid&display_host=$host"
  /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$l2 --legs "$legurl" --session-token "$token" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 600 >"$bondlog" 2>&1 & echo $! >"$BASE/bond-$name.pid"
  sleep 0.45
  python3 - "$name" "$kind" "$host" "$l2" "$logp" "$bondlog" <<'PY' >"$BASE/out-$name.json" 2>/dev/null || true
import json, socket, sys, time, pathlib, re
name,kind,host,lport,logp,bondlog=sys.argv[1],sys.argv[2],sys.argv[3],int(sys.argv[4]),pathlib.Path(sys.argv[5]),pathlib.Path(sys.argv[6])
start=time.time(); ok=False; banner=''; err=''
try:
    s=socket.create_connection(('127.0.0.1',lport),timeout=4)
    s.settimeout(4)
    banner=s.recv(100).decode('latin1','replace')
    ok=bool(banner)
    s.close()
except Exception as e:
    err=repr(e)
elapsed=(time.time()-start)*1000
lt=logp.read_text(errors='ignore') if logp.exists() else ''
bt=bondlog.read_text(errors='ignore') if bondlog.exists() else ''
print(json.dumps({
    'name': name, 'kind': kind, 'host': host, 'ok': ok, 'elapsed_ms': round(elapsed,1), 'banner': banner.strip(), 'error': err,
    'wstunnel_520': 'Invalid status code: 520' in lt,
    'bond_reset': 'recv_reset' in bt,
    'bond_established': 'transport session established' in bt,
    'bond_tail': '\n'.join(bt.splitlines()[-6:])[:700],
    'leg_tail': '\n'.join(lt.splitlines()[-6:])[:700],
}, ensure_ascii=False))
PY
  if [ $first -eq 0 ]; then printf ',\n'; fi; first=0
  cat "$BASE/out-$name.json"
  kill $(cat "$BASE/bond-$name.pid") $(cat "$BASE/leg-$name.pid") 2>/dev/null || true
  sleep 0.2
done < "$BASE/legs.txt"
printf '\n]}\n'
REMOTE
