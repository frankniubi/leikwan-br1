#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
ROUTE='07289d1f5ed25399'; AUTH='2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e'; SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'; REMOTEHOST='47.116.11.144'
make_token(){ PORT=$1 SECRET=$SECRET python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
raw=json.dumps({"target_host":"127.0.0.1","target_port":int(os.environ['PORT']),"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600},separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(os.environ['SECRET'].encode(),raw,hashlib.sha256).digest()))
PY
}
IPORT=28616; LPORT=14616
pkill -f "leikwan-ingress client --listen 127.0.0.1:$IPORT" 2>/dev/null || true
pkill -f "leikwan-bond client --protocol tcp --listen 127.0.0.1:$LPORT" 2>/dev/null || true
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:$IPORT --remote ${REMOTEHOST}:7000 --route-key "$ROUTE" --auth-token "$AUTH" >/tmp/cap-ingress.log 2>&1 & ipid=$!
for i in $(seq 1 60); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$IPORT),timeout=.2); s.close()
PY
done
TOKEN=$(make_token 5201)
RUST_LOG=debug /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$LPORT --legs "tcp://127.0.0.1:$IPORT?priority=10&endpoint_id=316&display_host=${REMOTEHOST}" --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --retransmit-ms 600 --connect-timeout-ms 3500 --join-timeout-ms 800 --interactive-chunk-bytes 8192 --latency-guard-bytes 65536 --warmup-trigger-bytes 65536 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 30 --bulk-promote-bytes 262144 --leg-cooldown-ms 1000 >/tmp/cap-bond.log 2>&1 & bpid=$!
for i in $(seq 1 80); do ss -ltn | grep -q ":$LPORT " && break || sleep .1; done
# capture loopback payload around iperf control and traffic
(tcpdump -i lo -s 256 -c 80 -nn -X "tcp port $LPORT or tcp port $IPORT" > /tmp/cap-lo.txt 2>/tmp/cap-tcpdump.err) & tpid=$!
sleep .5
timeout 12 iperf3 -c 127.0.0.1 -p $LPORT -R -b 300M -t 4 --connect-timeout 3000 >/tmp/cap-iperf.log 2>&1; rc=$?
sleep 1
kill $tpid 2>/dev/null || true; wait $tpid 2>/dev/null || true
kill $bpid $ipid 2>/dev/null || true
printf 'iperf_rc=%s\n' "$rc"; cat /tmp/cap-iperf.log
echo '--- bond key ---'; grep -E 'session established|stream|local_read_failed|local_write_failed|recv_reset|send_stream|recv_stream|send_fin|recv_fin|send_ack|recv_ack|entering cooldown|failed to read bond frame header' /tmp/cap-bond.log | tail -180 || true
echo '--- tcpdump err ---'; cat /tmp/cap-tcpdump.err || true
echo '--- tcpdump data ---'; sed -n '1,220p' /tmp/cap-lo.txt
REMOTE
