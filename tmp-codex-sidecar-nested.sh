#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SIDECAR_HOST='161.248.136.183'
SIDECAR_PORT='14950'
SIDECAR_PASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
echo '=== install sshpass on slave? ==='
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'command -v sshpass || (apt-get update >/dev/null 2>&1 && apt-get install -y sshpass >/dev/null 2>&1 || yum install -y sshpass >/dev/null 2>&1 || true); command -v sshpass || true; (timeout 5 bash -lc "cat < /dev/null > /dev/tcp/161.248.136.183/14950" && echo sidecar_ssh_open) || echo sidecar_ssh_fail'
echo '=== nested sidecar direct from slave ==='
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST "sshpass -p '$SIDECAR_PASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 -p $SIDECAR_PORT root@$SIDECAR_HOST 'hostname; date; ss -ltnp | grep -E \":(2053|8898|8899|26001|26002|14969)\" || true'" 