#!/usr/bin/env bash
set -euo pipefail
BASE=/tmp/codex-selfleg-direct-vs-bond
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
ROUTE=07289d1f5ed25399; SNI=nrt1.nbwly.com; EDGE=162.159.39.6
wait_port(){ local p=$1; for i in $(seq 1 70); do python3 - <<PY >/dev/null 2>&1 && return 0 || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.15); s.close()
PY
 done; return 1; }
for target in 22 5201; do
  lp=$((38000+target%1000))
  echo "=== direct-wst edge=$EDGE target=$target lp=$lp $(date -Is) ==="
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst-$target.log" 2>&1 & echo $! >"$BASE/wst-$target.pid"
  wait_port "$lp" || true
  sleep .8
  if [ "$target" = 22 ]; then
    python3 - <<PY
import socket,time,statistics
vals=[]; errs=[]
for i in range(12):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',$lp),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:16].hex()))
 time.sleep(.1)
print('BANNER ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:3])
PY
  else
    echo '-- reverse iperf -b 300M --'
    timeout 26 iperf3 -c 127.0.0.1 -p "$lp" -R -b 300M -t 10 --connect-timeout 3000 >"$BASE/iperf-r.out" 2>&1 || true
    cat "$BASE/iperf-r.out"
    echo '-- forward iperf -b 300M --'
    timeout 18 iperf3 -c 127.0.0.1 -p "$lp" -b 300M -t 6 --connect-timeout 3000 >"$BASE/iperf-f.out" 2>&1 || true
    cat "$BASE/iperf-f.out"
  fi
  echo '-- wst key --'; grep -E 'Invalid status|websocket handshake|Opening TCP|TLS handshake|Closing|WARN|ERROR' "$BASE/wst-$target.log" | tail -160 || true
  kill $(cat "$BASE/wst-$target.pid") 2>/dev/null || true
  sleep 2
done