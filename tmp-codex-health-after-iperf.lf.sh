set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +euo pipefail
echo "date=$(date -Is)"
echo "-- run f47 onegood reverse 10s and snapshot health --"
timeout 25 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 10 --connect-timeout 3000 > /tmp/codex-f47-health-iperf.out 2>&1; echo iperf_rc=$?; cat /tmp/codex-f47-health-iperf.out
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/run/leikwan/bond-leg-health-forward-47.json')
print('-- health --')
if p.exists():
 d=json.loads(p.read_text())
 print(json.dumps(d, indent=2)[:12000])
else: print('missing')
PY
echo "-- recent onegood key logs --"
journalctl -u leikwan-client-47-onegood.service --since '-2 min' --no-pager -o short-iso | grep -E 'session established|entering cooldown|recv_reset|local_read_failed|local_write_failed|failed to connect|failed to establish|invalid bond frame|read bond frame|WARN|ERROR|lifecycle' | tail -220 || true
REMOTE