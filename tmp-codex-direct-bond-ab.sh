#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
cat > /tmp/codex-direct-bond-ab.sh <<'RS'
#!/usr/bin/env bash
set +e
BASE=/etc/leikwan/mptcp-forward-47.json
RELAY=/var/lib/leikwan-client-agent/leikwan-mptcp-relay
WST=/usr/local/bin/wstunnel
EDGE=$(python3 - <<'PY'
import json
j=json.load(open('/etc/leikwan/mptcp-forward-47.json'))
print((j.get('direct_legs') or [{}])[0].get('host') or '162.159.39.4')
PY
)
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
for target in 8898 8899; do
  lp=$((41000 + target - 8800)); listen=$((11200 + target - 8800))
  python3 - "$target" "$lp" "$listen" <<'PY'
import json, pathlib, sys
target=int(sys.argv[1]); lp=int(sys.argv[2]); listen=int(sys.argv[3])
base=json.load(open('/etc/leikwan/mptcp-forward-47.json'))
cfg=dict(base)
cfg.update({
  'listen_port': listen,
  'remote_host': '127.0.0.1',
  'remote_port': lp,
  'relay_host': '127.0.0.1',
  'relay_port': target,
  'bond_mode': 'tcp',
  'endpoints': [],
  'underlays': [],
  'mptcp_endpoints': [],
  'manage_mptcp_endpoints': False,
  'subflow_limit': 1,
  'direct_legs': [{
    'candidate_kind':'self_leg', 'local_host':'127.0.0.1', 'local_port':lp, 'priority':1, 'display_host':f'self_leg_{target}', 'leg_role':'primary'
  }],
})
pathlib.Path(f'/tmp/mptcp-f47-direct-bond-{target}.json').write_text(json.dumps(cfg, indent=2)+'\n')
PY
  echo ===direct-bond-target-$target edge-$EDGE lp-$lp listen-$listen===
  pkill -f "mptcp-f47-direct-bond-$target.json" 2>/dev/null || true
  pkill -f "127.0.0.1:${lp}:127.0.0.1:${target}" 2>/dev/null || true
  $WST client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${EDGE}:2053 >/tmp/wst-direct-bond-${target}.out 2>/tmp/wst-direct-bond-${target}.err &
  wpid=$!
  sleep 1
  $RELAY client --config /tmp/mptcp-f47-direct-bond-${target}.json >/tmp/relay-direct-bond-${target}.out 2>/tmp/relay-direct-bond-${target}.err &
  rpid=$!
  sleep 2
  ss -ltnp | grep -E ":(${lp}|${listen})" || true
  echo ===iperf-target-$target===
  timeout 18 iperf3 -c 127.0.0.1 -p ${listen} -R -b 300M -t 8 --json
  echo iperf_rc=$?
  echo ===logs-target-$target===
  echo ---wst-err---; sed -n '1,160p' /tmp/wst-direct-bond-${target}.err
  echo ---relay-err---; sed -n '1,180p' /tmp/relay-direct-bond-${target}.err
  kill $rpid $wpid 2>/dev/null || true
  wait $rpid $wpid 2>/dev/null || true
 done
RS
chmod +x /tmp/codex-direct-bond-ab.sh
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex-direct-bond-ab.sh root@114.80.33.50:/tmp/codex-direct-bond-ab.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash /tmp/codex-direct-bond-ab.sh'