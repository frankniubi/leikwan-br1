#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +euo pipefail
echo "date=$(date -Is)"; echo "currently target22 onegood=$(systemctl is-active leikwan-client-47-onegood.service)"
# Direct public iperf short health first
for p in 5201 5202; do echo "-- direct public sidecar iperf p=$p --"; timeout 14 iperf3 -c 185.220.238.143 -p $p -R -b 300M -t 5 --connect-timeout 3000 2>&1 | sed -n '1,90p'; done
# Swap onegood target token to 5201, restart, retest.
python3 - <<'PY'
from pathlib import Path
p=Path('/usr/local/bin/leikwan-client-start-47-onegood.sh')
s=p.read_text()
s=s.replace('"target_port":22','"target_port":5201')
p.write_text(s)
PY
systemctl restart leikwan-client-47-onegood.service
sleep 2
echo "onegood=$(systemctl is-active leikwan-client-47-onegood.service) pid=$(systemctl show -p MainPID --value leikwan-client-47-onegood.service)"
ss -ltnp | grep -E ':(11035|22740)\b' || true
for mode in R F; do
  echo "-- onegood iperf $mode --"
  if [ "$mode" = R ]; then flag='-R'; else flag=''; fi
  timeout 25 bash -c "iperf3 -c 127.0.0.1 -p 11035 $flag -b 300M -t 8 --connect-timeout 3000" > /tmp/codex-onegood-5201-$mode.out 2>&1; rc=$?; echo rc=$rc; sed -n '1,120p' /tmp/codex-onegood-5201-$mode.out; sleep 2
done
echo '-- logs key --'
journalctl -u leikwan-client-47-onegood.service --since '-3 min' --no-pager -o short-iso | grep -E 'session established|invalid bond frame magic|failed to read bond frame header|no bond legs|recv_reset|local_read_failed|local_write_failed|entering cooldown|WARN|ERROR' | tail -160 || true
REMOTE
