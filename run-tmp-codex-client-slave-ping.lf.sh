#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
BASE=/tmp/codex-tcp-ping-via-ingress
rm -rf "$BASE"; mkdir -p "$BASE"
ROUTE=07289d1f5ed25399
cat > "$BASE/eps.tsv" <<'EOF'
316|47.116.11.144|2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
317|47.116.104.15|85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9
318|101.133.142.65|bd5eb739e2676216288eba117a8e893d767fe6f7edb2260a11a728f44f7e3307
319|47.116.134.3|48d684c9ff3c574205a556230f438faa3faf89f9113b4a28057d16dd7b792a53
320|8.133.170.123|a5216e9a1b67b4519013060c24e06ab3951755b32b68ee17f4122cceae637f72
EOF
free_port(){ python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
}
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
while IFS='|' read -r ep host auth; do
 p=$(free_port)
 /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:$p --remote $host:7000 --route-key "$ROUTE" --auth-token "$auth" >"$BASE/ing-$ep.log" 2>&1 & echo $! >"$BASE/ing-$ep.pid"
 for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.25); s.close()
PY
 done
 python3 - "$ep" "$host" "$p" <<'PY'
import sys,socket,time,statistics
ep,host,port=sys.argv[1],sys.argv[2],int(sys.argv[3]); vals=[]; errs=[]
for i in range(20):
 t=time.time()
 try:
  s=socket.create_connection(('127.0.0.1',port),timeout=2); vals.append((time.time()-t)*1000); s.close()
 except Exception as e: errs.append(type(e).__name__+':'+str(e))
 time.sleep(.05)
print(ep,host,'ok',len(vals),'min',round(min(vals),1) if vals else None,'p50',round(statistics.median(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'max',round(max(vals),1) if vals else None,'errs',errs[:3])
PY
 kill $(cat "$BASE/ing-$ep.pid") 2>/dev/null || true
 sleep .2
done < "$BASE/eps.tsv"
REMOTE