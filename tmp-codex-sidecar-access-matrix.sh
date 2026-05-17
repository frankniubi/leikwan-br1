#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SIDE_PASS='GK@Sp7z%T!s4*w5P'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
declare -a TARGETS=(
  '185.220.238.143:22'
  '185.220.238.143:14950'
  '185.220.238.143:14969'
  '161.248.136.183:14950'
  '161.248.136.183:22'
  '161.248.136.183:14969'
)
mapfile -t SLAVES < <(awk -F'|' 'NF>=4 {print $1"|"$3"|"$4}' shanghais.txt)
for s in "${SLAVES[@]}"; do
  IFS='|' read -r shost suser spass <<<"$s"
  echo "===== via $shost ====="
  for t in "${TARGETS[@]}"; do
    thost=${t%:*}; tport=${t#*:}
    printf '%s ' "$t"
    # first raw TCP from slave
    sshpass -p "$spass" ssh "${SSH_OPTS[@]}" "$suser@$shost" "timeout 4 bash -lc '</dev/tcp/$thost/$tport' >/dev/null 2>&1 && echo tcp_open || echo tcp_fail" 2>/dev/null || echo slave_ssh_fail
    # then actual SSH through proxy using both passwords
    out=$(sshpass -p "$SIDE_PASS" ssh "${SSH_OPTS[@]}" -o PreferredAuthentications=password -o PubkeyAuthentication=no \
      -o ProxyCommand="sshpass -p '$spass' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -W %h:%p $suser@$shost" \
      -p "$tport" root@"$thost" 'echo SIDE_OK $(hostname) $(date -u +%s)' 2>&1 </dev/null | tail -n 2 || true)
    echo "ssh_probe: ${out//$SIDE_PASS/<redacted>}"
  done
  echo
done