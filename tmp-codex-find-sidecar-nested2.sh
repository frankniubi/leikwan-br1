#!/usr/bin/env bash
set -euo pipefail
SIDE_PASS='GK@Sp7z%T!s4*w5P'
declare -a SLAVES=(
'47.116.11.144|@XwDQ6H5mgMXbrGD'
'47.116.134.3|RQRUbOkeh7ZpCkDa'
'47.116.104.15|U3I+sVL^%IXyqMX0'
'8.133.170.123|TnJKTGse+JMJFR%t'
'101.133.142.65|b+z@Qk860a+6UsVW'
)
declare -a TARGETS=('185.220.238.143|22' '185.220.238.143|14950' '161.248.136.183|14950' '161.248.136.183|22')
for s in "${SLAVES[@]}"; do
  shost=${s%%|*}; spass=${s#*|}
  echo "===== SLAVE $shost ====="
  for t in "${TARGETS[@]}"; do
    thost=${t%%|*}; tport=${t#*|}
    echo "--- target $thost:$tport ---"
    # Use nested ssh from slave to keep sidecar access through the slave hop and avoid ProxyCommand % expansion issues.
    sshpass -p "$spass" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=7 root@$shost "SIDE_PASS='$(printf "%s" "$SIDE_PASS" | sed "s/'/'\\''/g")'; timeout 5 bash -lc '</dev/tcp/$thost/$tport' && echo tcp_open || echo tcp_fail; timeout 10 sshpass -p \"\$SIDE_PASS\" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 -o ConnectionAttempts=1 -p $tport root@$thost 'echo sidecar_ssh_ok; hostname; date; id' 2>&1 || true" || echo slave_ssh_fail
  done
 done