#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "=== sidecar service state via endpoint316 $(date -Is) ==="
BASE=/tmp/codex-sidecar-systemctl-via-316
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29340 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
sleep .3
mk_token(){ python3 - "$1" <<'PY'
import base64,hashlib,hmac,json,sys,time
port=int(sys.argv[1]); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":port,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
}
for tgt in 8899 8898 2053 5201 22; do
  bp=$((29350 + tgt % 100))
  token=$(mk_token "$tgt")
  /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$bp --legs 'tcp://127.0.0.1:29340?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$token" --connect-timeout-ms 3000 --join-timeout-ms 900 >"$BASE/bond-$tgt.log" 2>&1 & echo $! >"$BASE/bond-$tgt.pid"
  sleep .8
  python3 - "$tgt" "$bp" <<'PY'
import socket, sys, time
tgt=int(sys.argv[1]); port=int(sys.argv[2])
vals=[]; errs=[]
for i in range(3):
    t=time.time()
    try:
        s=socket.create_connection(('127.0.0.1',port),timeout=2); s.settimeout(1)
        data=b''
        try: data=s.recv(32)
        except Exception: pass
        s.close(); vals.append(round((time.time()-t)*1000,1))
    except Exception as e: errs.append(type(e).__name__+':'+str(e))
    time.sleep(.1)
print({'target':tgt,'ok':bool(vals),'vals':vals,'errs':errs[:2]})
PY
  kill $(cat "$BASE/bond-$tgt.pid") 2>/dev/null || true
  sleep .2
done
printf '%s\n' '--- ingress log ---'; tail -80 "$BASE/ingress.log" || true
for f in "$BASE"/bond-*.log; do echo "---$f"; grep -E 'established|failed|invalid|early eof|no bond|rejected|upstream|Connection refused|WARN|ERROR' "$f" | tail -40; done
REMOTE
