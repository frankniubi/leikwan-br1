set -euo pipefail
mapfile -t SLAVES < <(awk -F'|' 'NF>=4 {print $1"|"$3"|"$4}' /mnt/c/Users/frankniubi/Downloads/leikwan-br1/shanghais.txt)
for s in "${SLAVES[@]}"; do
  IFS='|' read -r shost suser spass <<<"$s"
  echo "===== $shost ====="
  sshpass -p "$spass" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 "$suser@$shost" "ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 root@185.220.238.143 'echo KEY_SIDE_OK; hostname; id' 2>&1 || true; ls -l ~/.ssh 2>/dev/null || true; for k in ~/.ssh/*.pub; do [ -f \"\$k\" ] && echo PUB \$k && cat \"\$k\"; done" || true
 done
