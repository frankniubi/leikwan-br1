#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8)
while IFS='|' read -r host region user pass rest || [ -n "${host:-}" ]; do
 [ -n "$host" ] || continue
 echo "===== $host key sidecar test ====="
 sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'set +e
  echo host=$(hostname) time=$(date -Is)
  ls -la ~/.ssh 2>/dev/null || true
  echo "-- batch default --"; ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 root@185.220.238.143 "echo SIDE_OK; hostname; id; systemctl is-active leikwan-sidecar-agent.service leikwan-bond-sidecar-5-agent.service 2>/dev/null || true" 2>&1 | sed -n "1,60p"
  for f in ~/.ssh/id_* ~/.ssh/*key* ~/.ssh/*.pem; do [ -f "$f" ] || continue; echo "-- batch $f --"; chmod 600 "$f" 2>/dev/null; ssh -i "$f" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 root@185.220.238.143 "echo SIDE_OK; hostname; id" 2>&1 | sed -n "1,40p"; done
 ' 2>&1 | sed -n '1,140p'
 echo
done < shanghais.txt
