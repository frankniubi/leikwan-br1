#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'set +e
export NO_PROXY="*" no_proxy="*"
echo ===time===; date -u
echo ===target tcp connect===
timeout 5 bash -c "</dev/tcp/185.220.238.143/5201"; echo tcp_rc=$?
echo ===direct iperf normal===
timeout 20 iperf3 -c 185.220.238.143 -p 5201 -b 300M -t 5 --json; echo iperf_normal_rc=$?
echo ===direct iperf reverse===
timeout 20 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 --json; echo iperf_reverse_rc=$?
echo ===current service logs tail===
journalctl -u leikwan-client-47.service --since "-3 min" --no-pager | tail -n 120
'