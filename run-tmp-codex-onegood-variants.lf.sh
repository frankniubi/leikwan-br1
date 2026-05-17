#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
BASE=/tmp/codex-onegood-variants; rm -rf "$BASE"; mkdir -p "$BASE"
ROUTE=07289d1f5ed25399; SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
cat > "$BASE/eps.tsv" <<'EOF'
316|47.116.11.144|2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
317|47.116.104.15|85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9
318|101.133.142.65|bd5eb739e2676216288eba117a8e893d767fe6f7edb2260a11a728f44f7e3307
319|47.116.134.3|48d684c9ff3c574205a556230f438faa3faf89f9113b4a28057d16dd7b792a53
320|8.133.170.123|a5216e9a1b67b4519013060c24e06ab3951755b32b68ee17f4122cceae637f72
EOF
mk_token(){ python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
free_port(){ python3 - <<'PY'
import socket
s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1]); s.close()
PY
}
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
start_ing(){ ep=$1; idx=$2; line=$(grep "^$ep|" "$BASE/eps.tsv"); IFS='|' read -r ep2 host auth <<<"$line"; port=$((29000+idx)); /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:$port --remote $host:7000 --route-key $ROUTE --auth-token $auth >"$BASE/ing-$ep.log" 2>&1 & echo $! >"$BASE/ing-$ep.pid"; echo "tcp://127.0.0.1:$port?priority=10&endpoint_id=$ep&display_host=$host"; }
run_case(){ name=$1; eps=$2; chunk=$3; win=$4; retrans=$5; inter=$6; guard=$7; warm=$8; bulk=$9; cooldown=${10}; latency=${11}; shift 11 || true
 echo "=== $name eps=$eps chunk=$chunk win=$win retrans=$retrans inter=$inter guard=$guard warm=$warm bulk=$bulk cooldown=$cooldown latency=$latency ==="
 idx=0; legs=()
 for ep in ${eps//,/ }; do legs+=("$(start_ing $ep $idx)"); idx=$((idx+1)); done
 for p in $(seq 29000 $((29000+idx-1))); do for i in $(seq 1 50); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.25); s.close()
PY
 done; done
 listen=$(free_port); token=$(mk_token); legs_csv=$(IFS=,; echo "${legs[*]}")
 latarg=''; [ "$latency" = yes ] && latarg='--latency-first'
 RUST_LOG=warn /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$listen --legs "$legs_csv" --session-token "$token" --chunk-size $chunk --max-window-bytes $win --retransmit-ms $retrans --connect-timeout-ms 4000 --join-timeout-ms 1000 --interactive-chunk-bytes $inter --latency-guard-bytes $guard --warmup-trigger-bytes $warm --warmup-slice-bytes $chunk --warmup-leg-delay-ms 20 --bulk-promote-bytes $bulk --leg-cooldown-ms $cooldown $latarg >"$BASE/$name.bond" 2>&1 & echo $! >"$BASE/$name.pid"
 for i in $(seq 1 80); do ss -ltn | grep -q ":$listen " && break || sleep .1; done
 sleep 1
 set +e; timeout 24 iperf3 -c 127.0.0.1 -p $listen -R -b 300M -t 10 --connect-timeout 3000 >"$BASE/$name.out" 2>&1; rc=$?; set -e
 python3 - "$name" "$rc" "$BASE/$name.out" "$BASE/$name.bond" <<'PY'
import sys,re,pathlib
name,rc,outp,bp=sys.argv[1:]; out=pathlib.Path(outp).read_text(errors='ignore'); bond=pathlib.Path(bp).read_text(errors='ignore')
vals=[]; recv=send=None
for line in out.splitlines():
 m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line)
 if m and 'sender' not in line and 'receiver' not in line: vals.append(float(m.group(1)))
 if m and 'receiver' in line: recv=float(m.group(1))
 if m and 'sender' in line: send=float(m.group(1))
print('RESULT',name,'rc',rc,'avg',round(sum(vals)/len(vals),2) if vals else None,'max',max(vals) if vals else None,'send',send,'recv',recv,'cool',bond.count('entering cooldown'),'lw',bond.count('local_write_failed'),'lr',bond.count('local_read_failed'),'initfail',bond.count('failed to connect initial'))
print('\n'.join(out.splitlines()[:17]))
PY
 kill $(cat "$BASE/$name.pid") 2>/dev/null || true
 for f in "$BASE"/ing-*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; rm -f "$f"; done
 sleep 1
}
run_case c1_all_sticky 316,317,318,319,320 65536 33554432 800 65536 1048576 1073741824 1073741824 30000 yes
run_case c2_all_no_cool 316,317,318,319,320 65536 33554432 800 65536 1048576 1073741824 1073741824 0 yes
run_case c3_316320_sticky 316,320 65536 33554432 800 65536 1048576 1073741824 1073741824 30000 yes
run_case c4_316317320_warm 316,317,320 65536 33554432 800 65536 1048576 65536 1073741824 30000 no
run_case c5_all_bigchunk 316,317,318,319,320 262144 33554432 1000 262144 2097152 524288 1073741824 30000 yes
REMOTE