#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
ROUTE=07289d1f5ed25399
SECRET=1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0
EDGE=162.159.39.4
BASE=/tmp/codex-sidecar-ssh-via-bond
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
/usr/local/bin/wstunnel client -L tcp://127.0.0.1:29220:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers 'Host: nrt1.nbwly.com' --http-upgrade-path-prefix "$ROUTE" wss://$EDGE:2053 >"$BASE/wstunnel.log" 2>&1 & echo $! >"$BASE/wstunnel.pid"
sleep 1
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time,os
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode()
def b64(x): return base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:29222 --legs 'tcp://127.0.0.1:29220?priority=10&display_host=self_leg&role=primary' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 600 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
sleep 2
echo '=== banner ==='
python3 - <<'PY'
import socket,time
try:
 s=socket.create_connection(('127.0.0.1',29222),timeout=5); s.settimeout(5); print(repr(s.recv(128))); s.close()
except Exception as e: print('banner_error',repr(e))
PY
for pass in 'GK@Sp7z%T!s4*w5P' 'Frank1212.' 'Frank20021212.' '@XwDQ6H5mgMXbrGD' 'RQRUbOkeh7ZpCkDa' 'U3I+sVL^%IXyqMX0' 'TnJKTGse+JMJFR%t' 'b+z@Qk860a+6UsVW'; do
  echo "=== try pass hash $(python3 - <<PY
import hashlib
print(hashlib.sha256(b'''$pass''').hexdigest()[:8])
PY
) ==="
  set +e
  SSHPASS="$pass" sshpass -e ssh -p 29222 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=8 root@127.0.0.1 'echo SIDE_LOGIN_OK; hostname; date -Is; id; ss -ltnp | grep -E ":(22|2053|8899|5201|2600[0-9])" || true; systemctl is-active leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-sidecar-agent.service codex-iperf3-5201.service 2>/dev/null || true' </dev/null >"$BASE/ssh.out" 2>&1
  rc=$?
  set -e
  cat "$BASE/ssh.out" | tail -60
  if grep -q SIDE_LOGIN_OK "$BASE/ssh.out"; then exit 0; fi
done
echo '=== logs ==='
echo '--- wstunnel'; tail -80 "$BASE/wstunnel.log" || true
echo '--- bond'; tail -120 "$BASE/bond.log" || true
exit 2
REMOTE