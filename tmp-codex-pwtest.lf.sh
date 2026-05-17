#!/usr/bin/env bash
set -euo pipefail
for i in 1 2 3; do
  echo TRY=$i
  set +e
  sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ConnectionAttempts=1 root@114.80.33.50 'bash -s' <<'REMOTE'
echo PWTEST
id
whoami
echo 'Frank20021212.' | sudo -S id || true
systemctl is-active leikwan-client-agent.service 2>&1 || true
REMOTE
  rc=$?
  set -e
  [ $rc -eq 0 ] && break
  echo rc=$rc
  sleep 4
done