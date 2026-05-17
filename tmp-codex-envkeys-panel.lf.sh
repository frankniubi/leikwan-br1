#!/usr/bin/env bash
set -euo pipefail
export SSHPASS='Frank1212.'
sshpass -e ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@10.0.10.196 'bash -s' <<'REMOTE'
set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
for f in .env master/.env; do
  echo "--- $f"
  if [ -f "$f" ]; then
    sed -n '1,80p' "$f" | sed -E 's/(PASSWORD|PASS|TOKEN|SECRET|KEY)=.*/\1=REDACTED/g'
  else
    echo missing
  fi
done
REMOTE