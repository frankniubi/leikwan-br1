#!/usr/bin/env bash
set -euo pipefail
PASS_SLAVE='b+z@Qk860a+6UsVW'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null)
sshpass -p "$PASS_SLAVE" ssh "${SSH_OPTS[@]}" root@101.133.142.65 'hostname; command -v nc || command -v ncat || command -v netcat || true; command -v sshpass || true; timeout 5 bash -lc "</dev/tcp/161.248.136.183/14950" && echo sidecar_port_open || echo sidecar_port_closed'