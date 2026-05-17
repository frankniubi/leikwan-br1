set -euo pipefail
PASS='@XwDQ6H5mgMXbrGD'
HOST='47.116.11.144'
sshpass -p "$PASS" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$HOST 'set -e; hostname; command -v sshpass || true; command -v nc || command -v netcat || true; timeout 3 bash -lc "</dev/tcp/185.220.238.143/22" && echo port22-open || echo port22-fail; timeout 3 bash -lc "</dev/tcp/185.220.238.143/14950" && echo port14950-open || echo port14950-fail' 
