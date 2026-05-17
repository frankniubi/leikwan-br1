#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
for p in /var/lib/leikwan-client-agent/leikwan-mptcp-relay /usr/local/bin/leikwan-bond; do echo ---$p; ls -l $p; sha256sum $p 2>/dev/null; file $p; $p --help 2>&1 | head -80; done
echo '--- config 47 ---'; python3 -m json.tool /etc/leikwan/mptcp-forward-47.json 2>/dev/null | sed -n '1,240p'
echo '--- start 47 ---'; sed -n '1,180p' /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null
REMOTE