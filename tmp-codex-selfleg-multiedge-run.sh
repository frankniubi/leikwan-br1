#!/usr/bin/env bash
set -euo pipefail
cat >/tmp/codex_selfleg_multiedge_test.sh <<'RS'
#!/usr/bin/env bash
set -euo pipefail
ROUTE=07289d1f5ed25399
SNI=nrt1.nbwly.com
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
BASE=/tmp/codex-selfleg-multiedge
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
make_token(){ PORT="$1" SECRET="$SECRET" python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
port=int(os.environ['PORT']); secret=os.environ['SECRET'].encode()
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
wait_port(){ local p=$1; for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && return 0 || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.15); s.close()
PY
 done; return 1; }
run_case(){
  local name=$1; shift; local target=$1; shift; local edges=("$@")
  local listen=$((31000 + target % 1000 + ${#edges[@]}))
  local basep=$((32000 + target % 1000 + ${#edges[@]} * 10))
  echo "=== case=$name target=$target edges=${edges[*]} $(date -Is) ==="
  local legs=(); local i=0
  for edge in "${edges[@]}"; do
    local lp=$((basep+i))
    /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$ROUTE" wss://${edge}:2053 >"$BASE/wst-${name}-${i}.log" 2>&1 & echo $! >"$BASE/wst-${name}-${i}.pid"
    wait_port "$lp" || true
    role=primary; [ $i -gt 0 ] && role=helper
    legs+=("tcp://127.0.0.1:${lp}?priority=$((10+i*10))&endpoint_id=$i&display_host=${edge}&role=${role}")
    i=$((i+1))
  done
  TOKEN=$(make_token "$target")
  legs_csv=$(IFS=,; echo "${legs[*]}")
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${listen} --legs "$legs_csv" --session-token "$TOKEN" \
    --chunk-size 262144 --max-window-bytes 33554432 --retransmit-ms 500 --connect-timeout-ms 3500 --join-timeout-ms 1200 \
    --interactive-chunk-bytes 32768 --latency-guard-bytes 524288 --warmup-trigger-bytes 524288 --warmup-slice-bytes 131072 --warmup-leg-delay-ms 0 \
    --bulk-promote-bytes 1048576 --leg-cooldown-ms 5000 --latency-first >"$BASE/bond-${name}.log" 2>&1 & echo $! >"$BASE/bond-${name}.pid"
  wait_port "$listen" || true
  sleep 2
  if [ "$target" = 22 ]; then
    python3 - <<PY
import socket,time,statistics
vals=[]; errs=[]
for i in range(10):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',$listen),timeout=5); s.settimeout(5); data=s.recv(96); s.close()
 except Exception as e: err=type(e).__name__+':'+str(e)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),err,data[:16].hex()))
 time.sleep(.1)
print('BANNER ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals],'errs',errs[:3])
PY
  else
    timeout 25 iperf3 -c 127.0.0.1 -p "$listen" -R -b 300M -t 8 --connect-timeout 3000 >"$BASE/iperf-${name}.out" 2>&1 || true
    cat "$BASE/iperf-${name}.out"
    python3 - "$BASE/iperf-${name}.out" <<'PY'
import pathlib,re,sys
text=pathlib.Path(sys.argv[1]).read_text(errors='ignore')
vals=[float(m.group(1)) for line in text.splitlines() if (m:=re.search(r'\s([0-9.]+)\s+Mbits/sec',line)) and 'sender' not in line and 'receiver' not in line]
print('IPERF intervals',vals,'max',max(vals) if vals else None,'avg',round(sum(vals)/len(vals),2) if vals else None)
PY
  fi
  echo '-- key bond --'; grep -E 'session established|failed to connect initial|failed to establish|invalid bond|failed to read bond frame|early eof|entering cooldown|recv_reset|local_read_failed|local_write_failed|WARN|ERROR' "$BASE/bond-${name}.log" | tail -160 || true
  echo '-- key wst --'; grep -hE 'Invalid status|websocket handshake|Opening TCP|TLS handshake|Closing|WARN|ERROR' "$BASE"/wst-${name}-*.log | tail -160 || true
  kill $(cat "$BASE/bond-${name}.pid" "$BASE"/wst-${name}-*.pid 2>/dev/null) 2>/dev/null || true
  sleep 2
}
run_case e6_29_4_ssh 22 162.159.39.6 162.159.39.29 162.159.39.4
run_case e6_29_4_p5202 5202 162.159.39.6 162.159.39.29 162.159.39.4
sleep 4
run_case e6_29_4_p5201 5201 162.159.39.6 162.159.39.29 162.159.39.4
RS
chmod +x /tmp/codex_selfleg_multiedge_test.sh
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
bash /tmp/codex_selfleg_multiedge_test.sh