set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
EDGE=162.159.39.4
ROUTE='07289d1f5ed25399'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "EDGE=$EDGE ROUTE=$ROUTE bash -s" <<'REMOTE'
set +e
echo "date=$(date -Is) host=$(hostname)"
# ensure edge current
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
BASE=/tmp/codex-raw-wst317-iperf5202
rm -rf "$BASE"; mkdir -p "$BASE"
cleanup(){ set +e; kill $(cat "$BASE"/*.pid 2>/dev/null) 2>/dev/null || true; }
trap cleanup EXIT
for target in 5201 5202; do
  LP=$((34000+target-5200))
  echo "=== target=$target lp=$LP ==="
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${LP}:127.0.0.1:${target} --tls-sni-override nrt1.nbwly.com --http-headers "Host: nrt1.nbwly.com" --http-upgrade-path-prefix "$ROUTE" wss://${EDGE}:2053 >"$BASE/wst-$target.log" 2>&1 & echo $! >"$BASE/wst-$target.pid"
  for i in $(seq 1 40); do timeout .2 bash -c "</dev/tcp/127.0.0.1/$LP" >/dev/null 2>&1 && break || sleep .1; done
  timeout 18 iperf3 -c 127.0.0.1 -p "$LP" -R -b 300M -t 8 --connect-timeout 3000 >"$BASE/iperf-$target.out" 2>&1; rc=$?
  echo rc=$rc; cat "$BASE/iperf-$target.out"
  echo '-- wst log markers --'; grep -E 'Invalid status|failed to do websocket|Opening TCP|TLS handshake|Closing|ERROR|WARN' "$BASE/wst-$target.log" | tail -80 || true
  kill $(cat "$BASE/wst-$target.pid") 2>/dev/null || true
  sleep 1
done
REMOTE