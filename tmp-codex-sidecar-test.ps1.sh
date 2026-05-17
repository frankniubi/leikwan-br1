#!/usr/bin/env bash
set -euo pipefail
SLAVE_HOST='101.133.142.65'
SLAVE_PASS='b+z@Qk860a+6UsVW'
SIDECAR_HOST='185.220.238.143'
SIDECAR_PASS='GK@Sp7z%T!s4*w5P'
SSH_COMMON=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10)
PROXY="sshpass -p '$SLAVE_PASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -W %h:%p root@$SLAVE_HOST"
sshpass -p "$SIDECAR_PASS" ssh "${SSH_COMMON[@]}" -o ProxyCommand="$PROXY" root@$SIDECAR_HOST 'echo __REMOTE_START__; hostname; id; whoami; date; pwd; uname -a; echo __REMOTE_END__'