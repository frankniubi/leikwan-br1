#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
python3 - <<'PY' > /tmp/shanghai_hosts.tsv
from pathlib import Path
raw=Path('shanghais.txt').read_bytes().replace(b'\r', b'\n').splitlines()
for line in raw:
    if not line.strip():
        continue
    parts=line.decode('utf-8', errors='ignore').split('|')
    if len(parts) >= 4:
        print('\t'.join([parts[0], parts[2], parts[3]]))
PY
while IFS=$'\t' read -r host user pass; do
  [ -z "${host:-}" ] && continue
  echo "--- $host ---"
  sshpass -p "$pass" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py "$user@$host:/usr/local/bin/leikwan-slave-agent.py.tmp" >/dev/null
  sshpass -p "$pass" ssh -n "${SSH_OPTS[@]}" "$user@$host" 'set -euo pipefail
    install -m 0755 /usr/local/bin/leikwan-slave-agent.py.tmp /usr/local/bin/leikwan-slave-agent.py
    rm -f /usr/local/bin/leikwan-slave-agent.py.tmp
    python3 -m py_compile /usr/local/bin/leikwan-slave-agent.py
    grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
    systemctl restart leikwan-slave-agent.service
    sleep 1
    systemctl is-active leikwan-slave-agent.service
  '
done < /tmp/shanghai_hosts.tsv
