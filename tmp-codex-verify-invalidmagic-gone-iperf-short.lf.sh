#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE316=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)

echo '=== generate short forward47 traffic and inspect logs ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
  echo date=$(date -Is)
  echo --pre-state--
  systemctl is-active leikwan-client-47.service
  timeout 8 bash -c "iperf3 -c 127.0.0.1 -p 11035 -b 300M -t 5 --connect-timeout 3000" > /tmp/codex-iperf47-short.out 2>&1
  rc=$?
  echo iperf_rc=$rc
  cat /tmp/codex-iperf47-short.out
  echo --logs-since-restart--
  journalctl -u leikwan-client-47.service --since "2026-05-16 10:06:34" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|failed to establish|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|local_write_failed|accepted downstream|error|WARN" | tail -220 || true
  echo --snapshot--
  cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
'

echo '=== endpoint316 post-traffic logs ==='
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE316 'set +e
  echo date=$(date -Is)
  systemctl is-active leikwan-slave-agent.service
  systemctl is-active wstunnel-svc-2-316.service
  journalctl -u wstunnel-svc-2-316.service -u leikwan-tcp-mux-7000.service --since "2026-05-16 22:06:02" --no-pager -o short-iso | grep -E "Parse\(Method\)|Invalid status code|Opening TCP|Accepting|Error|WARN|INFO" | tail -120 || true
  echo --agent-log--
  journalctl -u leikwan-slave-agent.service --since "2026-05-16 22:06:00" --no-pager -o short-iso | grep -E "requested slave self-update|ERROR|WARNING|publish|wstunnel-svc-2-316" | tail -80 || true
'
