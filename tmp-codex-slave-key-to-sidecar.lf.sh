#!/usr/bin/env bash
set -euo pipefail
SLAVES=(
 '47.116.11.144|@XwDQ6H5mgMXbrGD'
 '47.116.104.15|U3I+sVL^%IXyqMX0'
 '101.133.142.65|b+z@Qk860a+6UsVW'
 '47.116.134.3|RQRUbOkeh7ZpCkDa'
 '8.133.170.123|TnJKTGse+JMJFR%t'
)
for row in "${SLAVES[@]}"; do
  IFS='|' read -r host pass <<<"$row"
  echo "===== slave $host ====="
  sshpass -p "$pass" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@$host 'bash -s' <<'REMOTE' || true
set +e
hostname; date -Is
ls -l /root/.ssh 2>/dev/null || true
for p in 22 14950 14969; do timeout 3 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1 && echo tcp_$p=open || echo tcp_$p=fail; done
for key in /root/.ssh/id_* /root/.ssh/*side* /root/.ssh/*tokyo* /root/.ssh/*nrt*; do
  [ -f "$key" ] || continue
  case "$key" in *.pub|*known_hosts*|*authorized_keys*) continue;; esac
  echo "--- try key $key ---"
  ssh -i "$key" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=publickey -o PasswordAuthentication=no -o IdentitiesOnly=yes -o ConnectTimeout=8 root@185.220.238.143 'echo SIDE_KEY_OK; hostname; date -Is; id; grep -m1 AGENT_VERSION /usr/local/bin/leikwan-sidecar-agent.py 2>/dev/null || true; systemctl is-active leikwan-bond-sidecar-5-agent.service 2>/dev/null || true' </dev/null 2>&1 | tail -80
  rc=${PIPESTATUS[0]}
  [ $rc -eq 0 ] && exit 0
done
exit 3
REMOTE
  echo
done