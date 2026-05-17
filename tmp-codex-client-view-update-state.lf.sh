#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set -u
printf '=== agent update state files ===\n'
for p in /var/lib/leikwan-client-agent/agent_update_state.json /var/lib/leikwan-sidecar-5/agent_update_state.json /var/lib/leikwan-sidecar/agent_update_state.json; do
 echo ---$p; cat $p 2>/dev/null || true; echo
done
printf '=== sidecar-related files/processes visible? ===\n'
ls -l /usr/local/bin/leikwan-sidecar-agent.py /usr/local/bin/leikwan-sidecar-update 2>/dev/null || true
pgrep -af 'sidecar|update|install-bond-sidecar' || true
printf '=== local downloaded public artifact check from client ===\n'
TMP=/tmp/codex-art-side.py
curl -k -fsSL --max-time 20 https://v3-mirror.leikwanhost.com/artifacts/sidecar-agent.py -o $TMP && sha256sum $TMP && grep -m1 AGENT_VERSION $TMP || true
REMOTE