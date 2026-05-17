#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
  echo "=== time ==="; date -Is
  echo "=== service/unit/script state ==="; systemctl is-active leikwan-client-47.service leikwan-client-agent.service || true; systemctl cat leikwan-client-47.service | sed -n "1,120p" || true
  echo "=== existing config/state files ==="; find /etc/leikwan /var/lib/leikwan-client-agent /run/leikwan -maxdepth 2 -type f \( -name "*47*" -o -name "applied*" -o -name "*self*" -o -name "bond-endpoint-health.json" \) -printf "%TY-%Tm-%Td %TH:%TM:%TS %s %p\n" 2>/dev/null | sort | tail -80 || true
  echo "=== iperf forward47 -b 300M ===";
  timeout 15 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 8 --connect-timeout 3000 > /tmp/codex-iperf47-$(date +%s).out 2>&1
  rc=$?; echo iperf_rc=$rc; tail -80 /tmp/codex-iperf47-*.out | tail -100
  echo "=== recent logs exact ===";
  journalctl -u leikwan-client-47.service --since "-6 min" --no-pager -o short-iso | tail -260
  echo "=== key filtered ===";
  journalctl -u leikwan-client-47.service --since "-6 min" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|timed out waiting|hello-ack|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|520|plain tcp ingress|local_write_failed|recv_reset|connection error|WARN|ERROR|failed" | tail -220 || true
'
