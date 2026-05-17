set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SIDE_HOST='185.220.238.143'
SIDE_PASS_CANDIDATES=('Frank1212.' 'Frank20021212.' 'GK@Sp7z%T!s4*w5P')
PORTS=(22 14950)
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=6 -o ConnectionAttempts=1 -o PreferredAuthentications=password -o PubkeyAuthentication=no)
mapfile -t SLAVES < <(awk -F'|' 'NF>=4 {print $1"|"$3"|"$4}' shanghais.txt)
for s in "${SLAVES[@]}"; do
  IFS='|' read -r shost suser spass <<<"$s"
  echo "===== via $shost ====="
  for port in "${PORTS[@]}"; do
    printf 'tcp %s:%s -> ' "$SIDE_HOST" "$port"
    sshpass -p "$spass" ssh "${SSH_OPTS[@]}" "$suser@$shost" "timeout 4 bash -lc '</dev/tcp/$SIDE_HOST/$port' >/dev/null 2>&1 && echo open || echo fail" 2>/dev/null || echo slave_ssh_failed
    for sidepass in "${SIDE_PASS_CANDIDATES[@]}"; do
      out=$(sshpass -p "$sidepass" ssh "${SSH_OPTS[@]}" -p "$port" \
        -o ProxyCommand="sshpass -p '$spass' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=6 -W %h:%p $suser@$shost" \
        root@"$SIDE_HOST" 'echo SIDE_OK; hostname; date -Is; id' 2>&1 </dev/null || true)
      if echo "$out" | grep -q SIDE_OK; then echo "ssh_ok port=$port pass_index"; echo "$out" | tail -4; exit 0; fi
    done
  done
  echo
 done
exit 1
