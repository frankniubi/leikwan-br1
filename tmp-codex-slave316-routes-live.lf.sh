#!/usr/bin/env bash
set -euo pipefail
SLAVE='47.116.11.144'
PASS='@XwDQ6H5mgMXbrGD'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$SLAVE 'bash -s' <<'REMOTE'
set +e
for p in /var/lib/leikwan-slave/listeners/routes-7000.json /var/lib/leikwan-slave/services/wstunnel-svc-2-316.json /var/lib/leikwan-slave/runtime/services/wstunnel-svc-2-316.json /var/lib/leikwan-slave-agent/listeners/routes-7000.json /var/lib/leikwan-slave-agent/services/wstunnel-svc-2-316.json; do
  echo ---$p
  if [ -e "$p" ]; then ls -l "$p"; sed -n '1,260p' "$p"; fi
done
echo === find json ===
find /var/lib -maxdepth 4 \( -name '*316*.json' -o -name 'routes-7000.json' \) -print -exec sh -c 'echo --- {}; sed -n "1,120p" "{}"' \;
REMOTE
