#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)

echo '=== install temporary iperf3 server on sidecar via slave forwarded shell ==='
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'set -euo pipefail
  ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 root@185.220.238.143 "bash -s" <<"REMOTE"
set +e
echo host=$(hostname) date=$(date -Is)
if ! command -v iperf3 >/dev/null 2>&1; then
  apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y iperf3
fi
cat >/etc/systemd/system/codex-iperf3-5201.service <<"UNIT"
[Unit]
Description=Codex temporary iperf3 server for forward47 target
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/iperf3 -s -p 5201
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now codex-iperf3-5201.service
sleep 1
systemctl is-active codex-iperf3-5201.service
ss -ltnp | grep :5201 || true
systemctl status codex-iperf3-5201.service --no-pager -l | sed -n "1,40p"
REMOTE
'

echo '=== test direct sidecar 5201 and forward47 after target is up ==='
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
  echo date=$(date -Is)
  echo --direct-sidecar5201--
  timeout 12 bash -c "iperf3 -c 185.220.238.143 -p 5201 -b 300M -t 5 --connect-timeout 3000" > /tmp/codex-direct-sidecar5201-after.out 2>&1
  echo rc=$?; cat /tmp/codex-direct-sidecar5201-after.out
  echo --restart-f47--
  systemctl restart leikwan-client-47.service
  sleep 4
  journalctl -u leikwan-client-47.service --since "-8 sec" --no-pager -o short-iso | tail -80
  echo --forward47-iperf--
  timeout 18 bash -c "iperf3 -c 127.0.0.1 -p 11035 -b 300M -t 10 --connect-timeout 3000" > /tmp/codex-f47-iperf-300-after.out 2>&1
  echo rc=$?; cat /tmp/codex-f47-iperf-300-after.out
  echo --logs--
  journalctl -u leikwan-client-47.service --since "-45 sec" --no-pager -o short-iso | grep -E "invalid bond frame magic|failed to read bond frame header|failed to establish|no bond legs|bond client TCP transport session established|Invalid status code|Opening TCP connection|recv_reset|Connection refused|local_write_failed|WARN|ERROR" | tail -220 || true
  echo --snapshot--
  cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
'
