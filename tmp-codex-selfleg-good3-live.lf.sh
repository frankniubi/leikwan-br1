set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
pkill -f 'wstunnel client .*07289d1f5ed25399.*nrt1\.nbwly\.com' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:' 2>/dev/null || true
BASE=/tmp/codex-selfleg-multiedge-now; rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
ROUTE=07289d1f5ed25399; SNI=nrt1.nbwly.com; SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
make_token(){ PORT="$1" SECRET="$SECRET" python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
port=int(os.environ['PORT']); secret=os.environ['SECRET'].encode();
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
wait_port(){ local p=$1; for i in $(seq 1 70); do python3 - <<PY >/dev/null 2>&1 && return 0 || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.15); s.close()
PY
 done; return 1; }
run_case(){
  name=$1; target=$2; shift 2; edges=("$@"); listen=$((41000 + target % 1000)); basep=$((42000 + target % 1000))
  echo "=== MULTIEDGE $name target=$target edges=${edges[*]} $(date -Is) ==="
  legs=(); i=0
  for edge in "${edges[@]}"; do
    lp=$((basep+i))
    /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$ROUTE" wss://${edge}:2053 >"$BASE/wst-$name-$i.log" 2>&1 & echo $! >"$BASE/wst-$name-$i.pid"
    wait_port "$lp" || true
    role=primary; [ $i -gt 0 ] && role=helper
    legs+=("tcp://127.0.0.1:${lp}?priority=$((10+i*5))&endpoint_id=$i&display_host=${edge}&role=${role}")
    i=$((i+1))
  done
  token=$(make_token "$target"); legs_csv=$(IFS=,; echo "${legs[*]}")
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${listen} --legs "$legs_csv" --session-token "$token" \
    --chunk-size 131072 --max-window-bytes 33554432 --retransmit-ms 500 --connect-timeout-ms 3500 --join-timeout-ms 1200 \
    --interactive-chunk-bytes 32768 --latency-guard-bytes 524288 --warmup-trigger-bytes 524288 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 \
    --bulk-promote-bytes 1048576 --leg-cooldown-ms 10000 --latency-first >"$BASE/bond-$name.log" 2>&1 & echo $! >"$BASE/bond-$name.pid"
  wait_port "$listen" || true; sleep 1.5
  if [ "$target" = 22 ]; then
    python3 - <<PY
import socket,time,statistics
vals=[]; errs=[]
for i in range(15):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',$listen),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:16].hex()))
 time.sleep(.1)
print('BANNER ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:5])
PY
  else
    timeout 30 iperf3 -c 127.0.0.1 -p "$listen" -R -b 300M -t 10 --connect-timeout 3000 >"$BASE/iperf-$name.out" 2>&1 || true
    cat "$BASE/iperf-$name.out"
    python3 - "$BASE/iperf-$name.out" <<'PY'
import pathlib,re,sys
text=pathlib.Path(sys.argv[1]).read_text(errors='ignore')
vals=[float(m.group(1)) for line in text.splitlines() if (m:=re.search(r'\s([0-9.]+)\s+Mbits/sec',line)) and 'sender' not in line and 'receiver' not in line]
print('IPERF intervals',vals,'max',max(vals) if vals else None,'avg',round(sum(vals)/len(vals),2) if vals else None)
PY
  fi
  echo '-- key bond --'; grep -E 'session established|failed to connect initial|failed to establish|invalid bond|failed to read bond frame|early eof|entering cooldown|recv_reset|local_read_failed|local_write_failed|WARN|ERROR' "$BASE/bond-$name.log" | tail -120 || true
  echo '-- key wst --'; grep -hE 'Invalid status|websocket handshake|Opening TCP|TLS handshake|Closing|WARN|ERROR' "$BASE"/wst-$name-*.log | tail -100 || true
  kill $(cat "$BASE/bond-$name.pid" "$BASE"/wst-$name-*.pid 2>/dev/null) 2>/dev/null || true; sleep 2
}
run_case good3ssh 22 162.159.39.6 162.159.39.7 162.159.39.4
run_case good3iperf 5201 162.159.39.6 162.159.39.7 162.159.39.4
REMOTE