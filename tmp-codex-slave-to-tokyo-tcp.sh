#!/usr/bin/env bash
set +e
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
for shost in 47.116.11.144 47.116.134.3 47.116.104.15 8.133.170.123 101.133.142.65; do
  spass=$(awk -F'|' -v h="$shost" '$1==h {print $4}' shanghais.txt)
  echo "=== $shost tcp to tokyo sidecar ==="
  sshpass -p "$spass" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 root@$shost "for p in 22 8899 8898 2053 14950; do timeout 3 bash -lc '</dev/tcp/185.220.238.143/'\$p >/dev/null 2>&1 && echo \$p open || echo \$p fail; done" 2>&1 | sed -n '1,60p'
done
