set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%%IXyqMX0'
TARGET=root@185.220.238.143
SSH_COMMON=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
PROXY="sshpass -p '$SPASS' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -W %h:%p root@$SLAVE"
for TPASS in 'GK@Sp7z%%T!s4*w5P' 'Frank1212.' 'Frank20021212.' 'Frank20021212' 'root' ''; do
  echo "-- try len=${#TPASS} --"
  if sshpass -p "$TPASS" ssh "${SSH_COMMON[@]}" -o ProxyCommand="$PROXY" "$TARGET" 'echo OK $(hostname) $(date -Is); systemctl is-active leikwan-bond-sidecar-5.service 2>/dev/null || true; exit' 2>/tmp/codex-sidecar-ssh.err; then
    echo "SUCCESS len=${#TPASS}"; exit 0
  else
    rc=$?; echo "fail rc=$rc err=$(tail -3 /tmp/codex-sidecar-ssh.err | tr '\n' ' ')"
  fi
done
exit 1
