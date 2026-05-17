#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'; PPASS='Frank1212.'
SSH_OPTS=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
printf 'repo source: '; sha256sum sidecar-agent/sidecar_agent.py; stat -c '%y %s' sidecar-agent/sidecar_agent.py
printf 'artifact source: '; sha256sum artifacts/sidecar-agent.py; stat -c '%y %s' artifacts/sidecar-agent.py
printf 'public artifact: '; ls -l master/public/artifacts/sidecar-agent.py 2>/dev/null && sha256sum master/public/artifacts/sidecar-agent.py || true
find master/public -maxdepth 3 -type f \( -name '*sidecar*' -o -name 'client-agent.py' -o -name 'versions.json' \) -printf '%p %s %TY-%Tm-%Td %TH:%TM\n' | sort
REMOTE