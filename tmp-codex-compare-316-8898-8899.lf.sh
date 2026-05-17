#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)

echo '=== endpoint316 service/meta/cmd ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
systemctl is-active wstunnel-svc-2-316.service leikwan-tcp-mux-7000.service || true
cat /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json 2>/dev/null || true
echo '--- unit ---'; sed -n '1,80p' /etc/systemd/system/wstunnel-svc-2-316.service 2>/dev/null || true
echo '--- mux routes ---'; sed -n '1,200p' /var/lib/leikwan-slave/listeners/plain-tcp-routes-7000.json 2>/dev/null || sed -n '1,200p' /var/lib/leikwan-slave/plain-tcp-routes-7000.json 2>/dev/null || find /var/lib/leikwan-slave -maxdepth 3 -type f -name '*7000*.json' -print -exec sed -n '1,120p' {} \; 2>/dev/null
pid=$(systemctl show -p MainPID --value wstunnel-svc-2-316.service 2>/dev/null); echo pid=$pid; [ "$pid" != 0 ] && tr '\0' ' ' </proc/$pid/cmdline; echo
ss -ltnp | grep -E ':(7000|7013|8899|2053)' || true
REMOTE

echo '=== compare endpoint316 8898/8899 bond banner via client ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
BASE=/tmp/codex-compare-316-8898-8899; rm -rf "$BASE"; mkdir -p "$BASE"
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29540 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
sleep .4
for tgt in 8898 8899 22; do
 token=$(python3 - "$tgt" <<'PY'
import base64,hashlib,hmac,json,sys,time
p=int(sys.argv[1]); secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":p,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x:base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
 )
 bp=$((29500 + tgt % 100))
 /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:$bp --legs 'tcp://127.0.0.1:29540?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$token" --connect-timeout-ms 3000 --join-timeout-ms 900 >"$BASE/bond-$tgt.log" 2>&1 & echo $! >"$BASE/bond-$tgt.pid"
 sleep 1.5
 python3 - "$tgt" "$bp" <<'PY'
import socket,sys,time
tgt=int(sys.argv[1]); port=int(sys.argv[2])
for i in range(2):
 t=time.time(); data=b''; err=''
 try:
  s=socket.create_connection(('127.0.0.1',port),timeout=3); s.settimeout(2.5)
  try: data=s.recv(64)
  except Exception as e: err=type(e).__name__+':'+str(e)
  s.close(); ok=True
 except Exception as e: ok=False; err=type(e).__name__+':'+str(e)
 print({'target':tgt,'ok':ok,'ms':round((time.time()-t)*1000,1),'data':data.decode('latin1','replace'),'err':err})
 time.sleep(.2)
PY
 kill $(cat "$BASE/bond-$tgt.pid") 2>/dev/null || true
 echo "--- bond $tgt summary ---"; grep -E 'established|failed to read|invalid bond|no bond|WARN|ERROR|Connection refused|local_write' "$BASE/bond-$tgt.log" | tail -60 || true
 sleep .3
done
echo '--- ingress log ---'; tail -100 "$BASE/ingress.log" || true
REMOTE

echo '=== endpoint316 recent slave logs ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'journalctl -u wstunnel-svc-2-316.service -u leikwan-tcp-mux-7000.service --since "-10 min" --no-pager -o short-iso | grep -E "Opening TCP|Invalid status|failed|Closing|plain tcp mux|8898|8899|ERROR|WARN|07289" | tail -200 || true'
