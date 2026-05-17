#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'set +e
echo ===pre===
date -u
systemctl is-active leikwan-client-agent.service
systemctl is-active leikwan-client-47.service
ss -ltnp | grep -E ":(11035|39559)" || true
echo ===iperf===
timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 15 --json
rc=$?
echo iperf_rc=$rc
echo ===post===
date -u
systemctl is-active leikwan-client-47.service
journalctl -u leikwan-client-47.service --since "-1 min" --no-pager | tail -n 140
echo ===agent===
journalctl -u leikwan-client-agent.service --since "-1 min" --no-pager | grep -E "forward 47|refreshed|restarted|failed|probe|health|applying" || true
'