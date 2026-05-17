#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
sshpass -p "$CPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
cat >/tmp/codex_multi_selfleg_test.sh <<'SH'
#!/usr/bin/env bash
set -euo pipefail
ROUTE='07289d1f5ed25399'; SNI='nrt1.nbwly.com'; SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
LISTEN=${LISTEN:-11036}; BASE=${BASE:-23800}; TARGET=${TARGET:-5201}; MODE=${MODE:-iperf}
EDGES=(162.159.39.6 162.159.39.4 162.159.39.29 162.159.39.8)
cleanup(){ kill $(jobs -p) 2>/dev/null || true; }
trap cleanup EXIT
rm -f /tmp/codex-multi-selfleg-*.log /tmp/codex-multi-selfleg-iperf.out
legs=(); idx=0
for edge in "${EDGES[@]}"; do
  p=$((BASE+idx))
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${p}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$ROUTE" wss://${edge}:2053 >/tmp/codex-multi-selfleg-wst-${idx}.log 2>&1 &
  legs+=("tcp://127.0.0.1:${p}?priority=$((10+idx))&endpoint_id=$((900+idx))&display_host=self_${edge}")
  idx=$((idx+1))
done
for p in $(seq $BASE $((BASE+idx-1))); do
  for i in $(seq 1 80); do python3 - <<PY >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',$p),timeout=.2); s.close()
PY
  done
done
TOKEN=$(TARGET=$TARGET SECRET=$SECRET python3 - <<'PY'
import os,base64,hashlib,hmac,json,time
secret=os.environ['SECRET'].encode(); target=int(os.environ['TARGET'])
payload={"target_host":"127.0.0.1","target_port":target,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
legs_csv=$(IFS=,; echo "${legs[*]}")
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} --legs "$legs_csv" --session-token "$TOKEN" \
 --chunk-size 262144 --max-window-bytes 33554432 --retransmit-ms 1000 --connect-timeout-ms 4000 --join-timeout-ms 1200 \
 --interactive-chunk-bytes 262144 --latency-guard-bytes 2097152 --warmup-trigger-bytes 524288 --warmup-slice-bytes 262144 --warmup-leg-delay-ms 20 \
 --bulk-promote-bytes 524288 --leg-cooldown-ms 30000 --latency-first >/tmp/codex-multi-selfleg-bond.log 2>&1 &
for i in $(seq 1 100); do ss -ltn | grep -q ":${LISTEN} " && break || sleep .1; done
sleep 1
if [ "$MODE" = banner ]; then
python3 - <<PY
import socket,time,statistics
vals=[]; errs=[]
for i in range(10):
 t=time.time(); data=b''; e=''
 try:
  s=socket.create_connection(('127.0.0.1',$LISTEN),timeout=4); s.settimeout(4); data=s.recv(96); s.close()
 except Exception as ex: e=type(ex).__name__+':'+str(ex)
 ms=(time.time()-t)*1000
 if data.startswith(b'SSH-'): vals.append(ms)
 else: errs.append((round(ms,1),e,data[:16].hex()))
 time.sleep(.15)
print('BANNER', {'ok':len(vals),'min':round(min(vals),1) if vals else None,'avg':round(statistics.mean(vals),1) if vals else None,'vals':[round(v,1) for v in vals], 'errs':errs[:4]})
PY
else
 timeout 30 iperf3 -c 127.0.0.1 -p ${LISTEN} -R -b 300M -t 12 --connect-timeout 3000 >/tmp/codex-multi-selfleg-iperf.out 2>&1 || true
 cat /tmp/codex-multi-selfleg-iperf.out
fi
echo '--- bond key ---'
grep -E 'session established|failed to connect initial|no bond legs|failed to read bond frame|invalid bond frame|cooldown|local_read_failed|local_write_failed|WARN|ERROR' /tmp/codex-multi-selfleg-bond.log | tail -200 || true
echo '--- wst key ---'
for f in /tmp/codex-multi-selfleg-wst-*.log; do echo "### $f"; grep -E 'Invalid status|failed to do websocket|Opening TCP|TLS handshake|WARN|ERROR' "$f" | tail -60 || true; done
SH
chmod +x /tmp/codex_multi_selfleg_test.sh
echo '=== banner multi selfleg ==='
MODE=banner TARGET=22 LISTEN=11036 BASE=23800 bash /tmp/codex_multi_selfleg_test.sh
echo '=== iperf multi selfleg target5201 ==='
sleep 3
MODE=iperf TARGET=5201 LISTEN=11036 BASE=23800 bash /tmp/codex_multi_selfleg_test.sh
REMOTE