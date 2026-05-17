#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SIDECAR_HOST='161.248.136.183'
SIDECAR_PORT='14950'
SIDECAR_PASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
PROXY="sshpass -p '$SLAVE_PASS' ssh -W %h:%p -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@$SLAVE_HOST"
echo '=== sidecar via proxycommand ==='
sshpass -p "$SIDECAR_PASS" ssh "${SSH_OPTS[@]}" -o ProxyCommand="$PROXY" -p "$SIDECAR_PORT" root@$SIDECAR_HOST 'hostname; date; id; ss -ltnp | grep -E ":(2053|8898|8899|26001|26002|14969)" || true'