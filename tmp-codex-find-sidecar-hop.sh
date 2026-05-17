#!/usr/bin/env bash
set -euo pipefail
SIDE_HOST='161.248.136.183'
SIDE_PORT='14950'
SIDE_PASS='GK@Sp7z%T!s4*w5P'
declare -a SLAVES=(
'47.116.11.144|@XwDQ6H5mgMXbrGD'
'47.116.134.3|RQRUbOkeh7ZpCkDa'
'47.116.104.15|U3I+sVL^%IXyqMX0'
'8.133.170.123|TnJKTGse+JMJFR%t'
'101.133.142.65|b+z@Qk860a+6UsVW'
)
for item in "${SLAVES[@]}"; do
  host=${item%%|*}; pass=${item#*|}
  echo "=== slave $host sidecar tcp probe ==="
  sshpass -p "$pass" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=6 root@$host "hostname; timeout 5 bash -lc '</dev/tcp/$SIDE_HOST/$SIDE_PORT' && echo sidecar_ssh_open || echo sidecar_ssh_fail" || echo slave_ssh_fail
  echo "=== proxy ssh via $host ==="
  PROXY="sshpass -p '$pass' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=6 -W %h:%p root@$host"
  sshpass -p "$SIDE_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ProxyCommand="$PROXY" -p "$SIDE_PORT" root@$SIDE_HOST 'echo sidecar_ok; hostname; date' && exit 0 || echo proxy_fail
  echo
 done
exit 1