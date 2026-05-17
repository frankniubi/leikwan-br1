#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
sshpass -p "$CPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo '=== bond help ==='
/usr/local/bin/leikwan-bond --version 2>&1 || true
/usr/local/bin/leikwan-bond client --help 2>&1 | sed -n '1,220p'
echo '=== active onegood/script ==='
systemctl is-active leikwan-client-47-onegood.service 2>/dev/null; systemctl is-enabled leikwan-client-47-onegood.service 2>/dev/null
sed -n '1,220p' /usr/local/bin/leikwan-client-start-47-onegood.sh 2>/dev/null || true
REMOTE