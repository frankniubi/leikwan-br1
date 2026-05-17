#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.104.15
SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1)
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE "SIDE=$SIDE bash -s" <<'REMOTE'
set +e
echo "slave=$(hostname) date=$(date -Is)"
echo '-- ssh files --'
ls -la /root/.ssh 2>/dev/null || true
find /root/.ssh /var/lib/leikwan-slave-agent /var/lib/leikwan-slave /etc/leikwan* -maxdepth 2 -type f 2>/dev/null | while read -r f; do
  case "$f" in
    *id_*|*key*|*ssh*|*pem*) echo "CAND $f"; ls -l "$f";;
  esac
done
echo '-- key auth attempts --'
for k in /root/.ssh/id_rsa /root/.ssh/id_ed25519 /root/.ssh/sidecar* /var/lib/leikwan-slave-agent/*key* /var/lib/leikwan-slave/*key* /etc/leikwan*/*key*; do
  [ -f "$k" ] || continue
  echo "TRYKEY $k"
  ssh -T -i "$k" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=publickey -o ConnectTimeout=8 root@$SIDE 'echo SIDE_OK; hostname; date -Is; id' 2>&1 | sed -n '1,12p'
done
echo '-- agent/default pubkey attempt --'
ssh -T -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 root@$SIDE 'echo SIDE_OK; hostname; date -Is; id' 2>&1 | sed -n '1,20p'
REMOTE