#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
# Freeze agents and force one known-good edge for slave317; use validated path 162.159.39.4.
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-slave-agent.service >/dev/null 2>&1 || true
EDGE=162.159.39.4
META=/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json
python3 - <<'PY'
import json,pathlib
p=pathlib.Path('/var/lib/leikwan-slave/services/wstunnel-svc-2-317.json')
d=json.loads(p.read_text())
d['selected_cf_ip']='162.159.39.4'
d['target']='127.0.0.1:8899'
d['backend_protocol']='wss_backend'
cf=d.setdefault('cf_mode',{})
cf['manual_minimum_good_path_edge']='162.159.39.4'
cf['probe_target']='127.0.0.1:8899'
cf['probe_samples']=3
cf['min_success_samples']=3
cf['cache_ttl_seconds']=600
p.write_text(json.dumps(d,ensure_ascii=False,indent=2)+'\n')
PY
cat >/etc/systemd/system/wstunnel-svc-2-317.service <<'UNIT'
[Unit]
Description=Leikwan CF WSS Backend wstunnel-svc-2-317 forced 162.159.39.4
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/wstunnel client -L tcp://127.0.0.1:7002:127.0.0.1:8899 --tls-sni-override nrt1.nbwly.com --http-headers "Host: nrt1.nbwly.com" --http-upgrade-path-prefix 07289d1f5ed25399 wss://162.159.39.4:2053
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now leikwan-tcp-mux-7000.service >/dev/null 2>&1 || true
systemctl restart wstunnel-svc-2-317.service
sleep 1
echo "SLAVE agent=$(systemctl is-active leikwan-slave-agent.service 2>/dev/null)/$(systemctl is-enabled leikwan-slave-agent.service 2>/dev/null) mux=$(systemctl is-active leikwan-tcp-mux-7000.service) wst=$(systemctl is-active wstunnel-svc-2-317.service) pid=$(systemctl show -p MainPID --value wstunnel-svc-2-317.service)"
tr '\0' ' ' </proc/$(systemctl show -p MainPID --value wstunnel-svc-2-317.service)/cmdline; echo
ss -ltnp | grep -E ':(7000|7002)\b' || true
REMOTE
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -euo pipefail
systemctl disable --now leikwan-client-agent.service >/dev/null 2>&1 || true
systemctl stop leikwan-client-47.service leikwan-client-47-onegood.service >/dev/null 2>&1 || true
systemctl disable leikwan-client-47.service >/dev/null 2>&1 || true
pkill -f 'leikwan-ingress client --listen 127\.0\.0\.1:22740' 2>/dev/null || true
pkill -f 'leikwan-bond client --protocol tcp --listen 127\.0\.0\.1:11035' 2>/dev/null || true
cat >/usr/local/bin/leikwan-client-start-47-onegood.sh <<'EOS'
#!/usr/bin/env bash
set -euo pipefail
trap 'kill $(jobs -p) 2>/dev/null || true' EXIT
ROUTE='07289d1f5ed25399'
AUTH='85553dabcea48c3f159383056a6e75957681ba8d7fde1f5d2cc2a2cca87bc7c9'
SECRET='1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
INGRESS_PORT=22740
LISTEN=11035
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:${INGRESS_PORT} --remote 47.116.104.15:7000 --route-key "$ROUTE" --auth-token "$AUTH" &
for i in $(seq 1 80); do
  python3 - <<PY >/dev/null 2>&1 && break || sleep 0.1
import socket
s=socket.create_connection(('127.0.0.1', ${INGRESS_PORT}), timeout=.25); s.close()
PY
done
TOKEN=$(python3 - <<'PY'
import base64,hashlib,hmac,json,time
secret=b'1a0db91dc97a424d6b94af0d8895be129e6f80c928f1bea0'
payload={"target_host":"127.0.0.1","target_port":22,"target_protocol":"tcp","service_id":2,"forward_id":47,"issued_at":int(time.time())-30,"expires_at":int(time.time())+86400}
raw=json.dumps(payload,separators=(',',':')).encode(); b64=lambda x: base64.urlsafe_b64encode(x).decode().rstrip('=')
print(b64(raw)+'.'+b64(hmac.new(secret,raw,hashlib.sha256).digest()))
PY
)
exec /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${LISTEN} \
  --legs "tcp://127.0.0.1:${INGRESS_PORT}?priority=10&endpoint_id=317&display_host=47.116.104.15" \
  --session-token "$TOKEN" \
  --chunk-size 65536 --max-window-bytes 8388608 --retransmit-ms 500 \
  --connect-timeout-ms 3000 --join-timeout-ms 800 \
  --interactive-chunk-bytes 16384 --latency-guard-bytes 262144 \
  --warmup-trigger-bytes 1048576 --warmup-slice-bytes 65536 --warmup-leg-delay-ms 0 \
  --bulk-promote-bytes 1073741824 --leg-cooldown-ms 5000
EOS
chmod 755 /usr/local/bin/leikwan-client-start-47-onegood.sh
cat >/etc/systemd/system/leikwan-client-47-onegood.service <<'EOS'
[Unit]
Description=Leikwan Client 47 one-good Shanghai-Tokyo CF bond path target22
After=network.target

[Service]
Type=simple
Environment=RUST_LOG=info
ExecStart=/bin/bash /usr/local/bin/leikwan-client-start-47-onegood.sh
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOS
systemctl daemon-reload
systemctl enable --now leikwan-client-47-onegood.service >/dev/null
sleep 2
echo "CLIENT agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null) f47=$(systemctl is-active leikwan-client-47.service 2>/dev/null)/$(systemctl is-enabled leikwan-client-47.service 2>/dev/null) onegood=$(systemctl is-active leikwan-client-47-onegood.service)/$(systemctl is-enabled leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|22740)\b' || true
python3 - <<'PY'
import socket,time,statistics
vals=[]; errs=[]
for i in range(20):
 t=time.time(); data=b''
 try:
  s=socket.create_connection(('127.0.0.1',11035),timeout=2); s.settimeout(3); data=s.recv(96); s.close();
  if data.startswith(b'SSH-'): vals.append((time.time()-t)*1000)
  else: errs.append(('bad',data[:20].decode('latin1','replace')))
 except Exception as e: errs.append((type(e).__name__,str(e)))
 time.sleep(.1)
print('BANNER_SUMMARY ok',len(vals),'fail',len(errs),'min',round(min(vals),1) if vals else None,'avg',round(statistics.mean(vals),1) if vals else None,'vals',[round(v,1) for v in vals[:10]],'errs',errs[:5])
PY
echo '-- log counts 90s --'
journalctl -u leikwan-client-47-onegood.service --since '-90 sec' --no-pager -o cat | python3 - <<'PY'
import sys
s=sys.stdin.read()
for p in ['invalid bond frame magic','failed to read bond frame header','no bond legs acknowledged','bond client TCP transport session established','entering cooldown','recv_reset','local_write_failed','local_read_failed']:
 print(p, s.count(p))
PY
REMOTE
