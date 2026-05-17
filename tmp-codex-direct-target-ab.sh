#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
cat > /tmp/codex-direct-target-ab.sh <<'RS'
#!/usr/bin/env bash
set +e
BASE=/etc/leikwan/mptcp-forward-47.json
RELAY=/var/lib/leikwan-client-agent/leikwan-mptcp-relay
WST=/usr/local/bin/wstunnel
python3 - <<'PY'
import json, pathlib
base=json.load(open('/etc/leikwan/mptcp-forward-47.json'))
leg=(base.get('direct_legs') or [{}])[0]
edge=leg.get('host') or '162.159.39.7'
for target, lp, listen in [(8898,39560,11135),(8899,39561,11136)]:
    cfg=dict(base)
    cfg['listen_port']=listen
    cfg['remote_host']='127.0.0.1'
    cfg['remote_port']=lp
    cfg['bond_mode']=''
    cfg['direct_legs']=[]
    cfg['subflow_limit']=1
    # leave tokens/endpoints as-is; relay does not setup endpoints here
    pathlib.Path(f'/tmp/mptcp-f47-direct-{target}.json').write_text(json.dumps(cfg, indent=2)+'\n')
print(edge)
PY
EDGE=$(python3 - <<'PY'
import json
j=json.load(open('/etc/leikwan/mptcp-forward-47.json'))
print((j.get('direct_legs') or [{}])[0].get('host') or '162.159.39.7')
PY
)
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
for target in 8898 8899; do
  if [ "$target" = 8898 ]; then lp=39560; listen=11135; else lp=39561; listen=11136; fi
  echo ===target-$target-start===
  pkill -f "mptcp-f47-direct-$target.json" 2>/dev/null || true
  pkill -f "127.0.0.1:${lp}:127.0.0.1:${target}" 2>/dev/null || true
  $WST client -L tcp://127.0.0.1:${lp}:127.0.0.1:${target} --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${EDGE}:2053 >/tmp/wst-direct-${target}.out 2>/tmp/wst-direct-${target}.err &
  wpid=$!
  sleep 1
  $RELAY client --config /tmp/mptcp-f47-direct-${target}.json >/tmp/relay-direct-${target}.out 2>/tmp/relay-direct-${target}.err &
  rpid=$!
  sleep 2
  ss -ltnp | grep -E ":(${lp}|${listen})" || true
  echo ===target-$target-iperf===
  timeout 18 iperf3 -c 127.0.0.1 -p ${listen} -R -b 300M -t 8 --json
  echo iperf_rc=$?
  echo ===target-$target-logs===
  echo ---wst-err---; sed -n '1,120p' /tmp/wst-direct-${target}.err
  echo ---relay-err---; sed -n '1,120p' /tmp/relay-direct-${target}.err
  kill $rpid $wpid 2>/dev/null || true
  wait $rpid $wpid 2>/dev/null || true
 done
RS
chmod +x /tmp/codex-direct-target-ab.sh
sshpass -p "$PASS_CLIENT" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex-direct-target-ab.sh root@114.80.33.50:/tmp/codex-direct-target-ab.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'bash /tmp/codex-direct-target-ab.sh'