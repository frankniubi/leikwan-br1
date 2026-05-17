#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'set +e
echo ===pre===
date -u
systemctl is-active leikwan-client-agent.service
systemctl is-active leikwan-client-47.service
python3 - <<"PY"
import json
j=json.load(open("/etc/leikwan/mptcp-forward-47.json"))
print({k:j.get(k) for k in ["bond_mode","remote_host","remote_port","relay_host","relay_port","direct_legs"]})
PY
ss -ltnp | grep -E ":(11035|39559)" || true
echo ===iperf1===
timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 15 --json
printf "iperf1_rc=%s\n" "$?"
echo ===iperf2===
timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 15 --json
printf "iperf2_rc=%s\n" "$?"
echo ===post===
systemctl is-active leikwan-client-47.service
ss -ltnp | grep -E ":(11035|39559)" || true
echo ===journal47===
journalctl -u leikwan-client-47.service --since "-3 min" --no-pager | tail -n 220
echo ===agent47===
journalctl -u leikwan-client-agent.service --since "-3 min" --no-pager | grep -E "forward 47|refreshed|restarted|failed|probe|health|applying" || true
'