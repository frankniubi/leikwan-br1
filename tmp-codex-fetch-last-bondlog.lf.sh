#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
for f in /tmp/codex-bond5201-now/bond.log /tmp/codex-bond5201-now/ingress.log /tmp/codex-bond5201-now/iperf.out; do echo "--- $f ---"; [ -f "$f" ] && sed -n '1,260p' "$f" | tail -260; done
REMOTE