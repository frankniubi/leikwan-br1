set -euo pipefail
SLAVE_PASS='@XwDQ6H5mgMXbrGD'
SIDECAR_PASS='GK@Sp7z%T!s4*w5P'
SLAVE='47.116.11.144'
# Run nested from slave so sidecar access remains through slave hop.
sshpass -p "$SLAVE_PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$SLAVE \
  "SSHPASS=\"$SIDECAR_PASS\" sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 root@185.220.238.143 'hostname; date -Is; whoami; echo OK'" 
