#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
BASE=/tmp/codex-sidecar-reach-targets
rm -rf "$BASE"; mkdir -p "$BASE"
ROUTE=07289d1f5ed25399
EDGE=162.159.39.4
echo "=== target path matrix $(date -Is) ==="
for target in 22 8899 8898 5201 5202 14950 14969 14985; do
  lp=$((39400+target%1000))
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst-$target.log" 2>&1 & echo $! >"$BASE/wst-$target.pid"
  for i in $(seq 1 35); do timeout .2 bash -c "</dev/tcp/127.0.0.1/$lp" >/dev/null 2>&1 && break || sleep .1; done
  python3 - "$lp" "$target" <<'PY'
import socket,sys,time
lp=int(sys.argv[1]); target=int(sys.argv[2]); t=time.time(); data=b''; err=''
try:
 s=socket.create_connection(('127.0.0.1',lp),timeout=3); s.settimeout(1.5)
 if target in (8899,8898):
  # send empty-ish bad handshake length to see if listener closes after accepting
  s.sendall((1).to_bytes(4,'big')+b'{')
 else:
  pass
 try: data=s.recv(80)
 except Exception as e: err=type(e).__name__+':'+str(e)
 s.close()
except Exception as e: err=type(e).__name__+':'+str(e)
print({'target':target,'ms':round((time.time()-t)*1000,1),'data_hex':data[:24].hex(),'data_txt':data[:40].decode('latin1','replace'),'err':err})
PY
  status=$(grep -E 'Invalid status code|failed to do websocket|Closing local|Opening TCP|TLS handshake' "$BASE/wst-$target.log" | tail -4 | tr '\n' '|' | sed 's/\x1b\[[0-9;]*m//g')
  echo "markers target=$target $status"
  kill $(cat "$BASE/wst-$target.pid") 2>/dev/null || true
  sleep .3
done
REMOTE