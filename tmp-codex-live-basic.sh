#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
PASS_PANEL='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)

echo '=== client basic ==='
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'hostname; uname -a; whoami; ip -brief addr | sed -n "1,8p"; systemctl is-active leikwan-client-agent.service || true; systemctl is-active leikwan-client-47.service || true; ss -ltnp | grep 11035 || true'

echo '=== panel basic ==='
sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'hostname; whoami; php -r "echo \"panel_ok\\n\";"'
