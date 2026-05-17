set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-oneleg-316-iperf
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29150 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
sleep .5
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
payload={"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29151 --legs 'tcp://127.0.0.1:29150?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 800 --bulk-promote-bytes 1048576 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
sleep 2
echo '=== listener ==='; ss -ltnp | grep -E ':(29150|29151)' || true
echo '=== iperf ==='
set +e
iperf3 -c 127.0.0.1 -p 29151 -R -b 300M -t 10 -J >"$BASE/iperf.json" 2>"$BASE/iperf.err"
rc=$?
set -e
echo rc=$rc
cat "$BASE/iperf.err" || true
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/tmp/codex-oneleg-316-iperf/iperf.json')
text=p.read_text(errors='ignore') if p.exists() else ''
print(text[:5000])
try:
    d=json.loads(text)
    end=d.get('end',{})
    for key in ['sum_received','sum_sent']:
        if key in end:
            s=end[key]; print(key, 'bits_per_second=', s.get('bits_per_second'), 'seconds=', s.get('seconds'), 'bytes=', s.get('bytes'))
except Exception as e:
    print('json_parse_error',repr(e))
PY
echo '=== bond log ==='; sed -n '1,220p' "$BASE/bond.log"
echo '=== ingress log ==='; sed -n '1,120p' "$BASE/ingress.log"
exit $rc
REMOTE