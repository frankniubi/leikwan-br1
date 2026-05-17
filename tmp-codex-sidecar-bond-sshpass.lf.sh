#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
BASE=/tmp/codex-sidecar-ssh-via-bond-pass
ROUTE=07289d1f5ed25399
AUTH=2f35a4d2c26fb846460aa07156eaec75bced6fdcced7b645b22e830ee863ce7e
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:29440 --remote 47.116.11.144:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
sleep .5
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29442 --legs 'tcp://127.0.0.1:29440?priority=10&endpoint_id=316&display_host=47.116.11.144' --session-token "$TOKEN" --connect-timeout-ms 3000 --join-timeout-ms 900 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
for i in $(seq 1 50); do python3 - <<'PY' >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',29442),timeout=.3); s.close()
PY
done
python3 - <<'PY'
import socket
s=socket.create_connection(('127.0.0.1',29442),timeout=5); s.settimeout(4); print('banner',repr(s.recv(96))); s.close()
PY
passwords=( 'GK@Sp7z%T!s4*w5P' 'Frank1212.' 'Frank20021212.' 'Frank20021212' 'root' )
for p in "${passwords[@]}"; do
  echo "=== try password len=${#p} ==="
  set +e
  sshpass -p "$p" ssh -p 29442 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=8 root@127.0.0.1 'echo SIDE_LOGIN_OK; hostname; date -Is; id; grep -m1 AGENT_VERSION /usr/local/bin/leikwan-sidecar-agent.py 2>/dev/null || true; systemctl is-active leikwan-bond-sidecar-5-agent.service leikwan-sidecar-agent.service 2>/dev/null || true; exit' </dev/null >"$BASE/pass.out" 2>&1
  rc=$?
  set -e
  cat "$BASE/pass.out" | tail -80
  if grep -q SIDE_LOGIN_OK "$BASE/pass.out"; then exit 0; fi
done
exit 1
REMOTE