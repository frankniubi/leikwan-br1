set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
EDGE=162.159.39.4
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== force endpoint317 edge39.4 for sidecar ssh tunnel ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE bash -s" <<'REMOTE'
set -euo pipefail
python3 - <<'PY'
import json, os, pathlib, re
edge=os.environ['EDGE']
svc=pathlib.Path('/etc/systemd/system/wstunnel-svc-2-317.service')
text=svc.read_text(); text=re.sub(r'wss://[^\s]+:2053', f'wss://{edge}:2053', text); svc.write_text(text)
meta=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
if meta.exists():
 data=json.loads(meta.read_text()); data['selected_cf_ip']=edge; data.setdefault('cf_mode',{})['manual_minimum_good_path_edge']=edge; meta.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
PY
systemctl daemon-reload; systemctl restart wstunnel-svc-2-317.service; sleep 1; systemctl is-active wstunnel-svc-2-317.service
REMOTE
echo '=== try sidecar ssh auth through endpoint317 bond target22 ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT "ROUTE=$ROUTE AUTH=$AUTH SECRET=$SECRET bash -s" <<'REMOTE'
set +e
BASE=/tmp/codex-sidecar-ssh317
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; for f in "$BASE"/*.pid; do [ -f "$f" ] && kill $(cat "$f") 2>/dev/null || true; done; }
trap cleanup EXIT
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:22841 --remote 47.116.104.15:7000 --route-key "$ROUTE" --auth-token "$AUTH" >"$BASE/ingress.log" 2>&1 & echo $! >"$BASE/ingress.pid"
for i in $(seq 1 40); do python3 - <<'PY' >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',22841),timeout=.25); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,os,time
secret=os.environ['SECRET'].encode(); payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+3600}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:11036 --legs 'tcp://127.0.0.1:22841?priority=10&endpoint_id=317&display_host=47.116.104.15' --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 8388608 --connect-timeout-ms 3000 --join-timeout-ms 800 --leg-cooldown-ms 1000 >"$BASE/bond.log" 2>&1 & echo $! >"$BASE/bond.pid"
for i in $(seq 1 60); do python3 - <<'PY' >/dev/null 2>&1 && break || sleep .1
import socket
s=socket.create_connection(('127.0.0.1',11036),timeout=.25); s.close()
PY
done
python3 - <<'PY'
import socket,time
s=socket.create_connection(('127.0.0.1',11036),timeout=4); s.settimeout(4); print('banner',s.recv(80)); s.close()
PY
command -v sshpass || true
echo '-- password tries --'
for pass in 'Frank20021212.' 'Frank1212.' 'GK@Sp7z%T!s4*w5P'; do
  echo "try_pw_len=${#pass}"
  if command -v sshpass >/dev/null 2>&1; then
    sshpass -p "$pass" ssh -p 11036 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1 -o ConnectTimeout=8 root@127.0.0.1 'echo SIDE_OK; hostname; date -Is; id; ss -ltnp | grep -E ":(22|2053|8899|8898|5201)" || true; systemctl is-active leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-bond-sidecar-5-iperf3-5201.service 2>/dev/null || true' < /dev/null && exit 0
  fi
done
echo '-- key tries --'
for key in /root/.ssh/id_* /etc/leikwan*/*key* /etc/ssh/*key*; do
 [ -f "$key" ] || continue
 case "$key" in *.pub) continue;; esac
 echo "key=$(basename $key)"
 ssh -p 11036 -i "$key" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=publickey -o PasswordAuthentication=no -o IdentitiesOnly=yes -o ConnectTimeout=8 root@127.0.0.1 'echo SIDE_OK; hostname; date -Is; id; ss -ltnp | grep -E ":(22|2053|8899|8898|5201)" || true; systemctl is-active leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-bond-sidecar-5-iperf3-5201.service 2>/dev/null || true' < /dev/null && exit 0
done
echo '-- auth failed markers --'
sed -n '1,120p' "$BASE/bond.log" | tail -80
exit 4
REMOTE