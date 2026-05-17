#!/usr/bin/env bash
set +euo pipefail
CLIENT=114.80.33.50
PASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
echo '=== tcp probe ==='
timeout 5 bash -c "cat < /dev/tcp/$CLIENT/22" 2>&1 | head -c 160 | cat -v; echo; echo rc=${PIPESTATUS[0]}
echo '=== ssh debug ==='
sshpass -p "$PASS" ssh -vvv "${SSH_OPTS[@]}" root@$CLIENT 'echo ok' 2>&1 | tail -160
