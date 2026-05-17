#!/usr/bin/env bash
set +e
OUT=$(sshpass -p 'Frank20021212.' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=20 -o ConnectionAttempts=1 root@114.80.33.50 'echo ok; hostname; date -Is' 2>&1)
rc=$?
echo rc=$rc
printf '%s\n' "$OUT" | cat -v
