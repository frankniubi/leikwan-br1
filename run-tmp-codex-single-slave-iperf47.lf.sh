#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
BASE=/tmp/codex-single-slave-iperf47
rm -rf "$BASE"; mkdir -p "$BASE"
ROUTE='07289d1f5ed25399'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
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
printf 'endpoint,host,rc,avg_mbps,max_mbps,recv_mbps,send_mbps,cooldowns,local_write_failed,local_read_failed,initial_fail,first_lines\n'
while IFS='|' read -r ep host auth; do
  l1=$(free_port); l2=$(free_port); token=$(mk_token)
  /usr/local/bin/leikwan-ingress client --listen 127.0.0.1:$l1 --remote $host:7000 --route-key "$ROUTE" --auth-token "$auth" >"$BASE/ing-$ep.log" 2>&1 & echo $! >"$BASE/ing-$ep.pid"
  for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$l1),timeout=.25); s.close()
PY
  done
  RUST_LOG=info /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$l2 \
    --legs "tcp://127.0.0.1:$l1?priority=10&endpoint_id=$ep&display_host=$host" --session-token "$token" \
    --chunk-size 65536 --max-window-bytes 33554432 --retransmit-ms 800 --connect-timeout-ms 4000 --join-timeout-ms 800 \
    --interactive-chunk-bytes 8192 --latency-guard-bytes 65536 --warmup-trigger-bytes 1073741824 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 80 \
    --bulk-promote-bytes 1073741824 --leg-cooldown-ms 30000 >"$BASE/bond-$ep.log" 2>&1 & echo $! >"$BASE/bond-$ep.pid"
  for i in $(seq 1 80); do ss -ltn | grep -q ":$l2 " && break || sleep .1; done
  sleep 1
  timeout 24 iperf3 -c 127.0.0.1 -p $l2 -R -b 300M -t 10 --connect-timeout 3000 >"$BASE/ip-$ep.out" 2>&1; rc=$?
  python3 - "$ep" "$host" "$rc" "$BASE/ip-$ep.out" "$BASE/bond-$ep.log" <<'PY'
import sys,re,pathlib,csv
ep,host,rc,outp,bondp=sys.argv[1:]
out=pathlib.Path(outp).read_text(errors='ignore')
bond=pathlib.Path(bondp).read_text(errors='ignore')
vals=[]; recv=send=None
for line in out.splitlines():
    m=re.search(r'\s([0-9.]+)\s+Mbits/sec', line)
    if m and 'sender' not in line and 'receiver' not in line: vals.append(float(m.group(1)))
    if m and 'receiver' in line: recv=float(m.group(1))
    if m and 'sender' in line: send=float(m.group(1))
first=' | '.join(out.splitlines()[:6]).replace(',', ';')[:280]
row=[ep,host,rc,round(sum(vals)/len(vals),2) if vals else '',max(vals) if vals else '',recv if recv is not None else '',send if send is not None else '',bond.count('entering cooldown'),bond.count('local_write_failed'),bond.count('local_read_failed'),bond.count('failed to connect initial'),first]
print(','.join(map(str,row)))
PY
  kill $(cat "$BASE/bond-$ep.pid") $(cat "$BASE/ing-$ep.pid") 2>/dev/null || true
  rm -f "$BASE/bond-$ep.pid" "$BASE/ing-$ep.pid"
  sleep 1
done < "$BASE/eps.tsv"
REMOTE