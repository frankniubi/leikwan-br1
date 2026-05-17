#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex-helper-ab.sh <<'RS'
#!/usr/bin/env bash
set +e
BASE=/etc/leikwan/mptcp-forward-47.json
RELAY=/var/lib/leikwan-client-agent/leikwan-mptcp-relay
python3 - <<'PY'
import json, pathlib
base=json.load(open('/etc/leikwan/mptcp-forward-47.json'))
# Plain MPTCP single connection to helper-side sidecar relay.
cfg=dict(base)
cfg['listen_port']=11347
cfg['bond_mode']=''
cfg['direct_legs']=[]
cfg['remote_host']=base.get('relay_host') or base.get('remote_host')
cfg['remote_port']=base.get('relay_port') or base.get('remote_port')
cfg['manage_mptcp_endpoints']=False
pathlib.Path('/tmp/mptcp-f47-helper-plain.json').write_text(json.dumps(cfg, indent=2)+'\n')
# Application bond across WG helper legs only.
cfg2=dict(base)
cfg2['listen_port']=11348
cfg2['bond_mode']='tcp'
cfg2['direct_legs']=[]
cfg2['remote_host']=base.get('relay_host') or base.get('remote_host')
cfg2['remote_port']=base.get('relay_port') or base.get('remote_port')
cfg2['manage_mptcp_endpoints']=False
pathlib.Path('/tmp/mptcp-f47-helper-bond.json').write_text(json.dumps(cfg2, indent=2)+'\n')
print('plain remote', cfg['remote_host'], cfg['remote_port'])
print('bond relay', cfg2['relay_host'], cfg2['relay_port'], 'underlays', len(cfg2.get('underlays') or []))
PY
for mode in plain bond; do
  listen=$([ "$mode" = plain ] && echo 11347 || echo 11348)
  cfg=/tmp/mptcp-f47-helper-${mode}.json
  echo ===start-$mode $cfg===
  pkill -f "$cfg" 2>/dev/null || true
  $RELAY client --config "$cfg" >/tmp/relay-helper-${mode}.out 2>/tmp/relay-helper-${mode}.err &
  pid=$!
  sleep 2
  ss -ltnp | grep ":$listen" || true
  echo ===iperf-$mode===
  timeout 20 iperf3 -c 127.0.0.1 -p $listen -R -b 300M -t 8 --json
  echo iperf_rc=$?
  echo ===logs-$mode===
  sed -n '1,220p' /tmp/relay-helper-${mode}.err
  kill $pid 2>/dev/null || true
  wait $pid 2>/dev/null || true
 done
RS
chmod +x /tmp/codex-helper-ab.sh
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-helper-ab.sh root@114.80.33.50:/tmp/codex-helper-ab.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash /tmp/codex-helper-ab.sh'