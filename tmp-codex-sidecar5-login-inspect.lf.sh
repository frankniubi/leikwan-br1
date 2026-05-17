#!/usr/bin/env bash
set -euo pipefail
SLAVE=47.116.11.144
SPASS='@XwDQ6H5mgMXbrGD'
SIDE=185.220.238.143
AGENT_TOKEN='dfc1b1a986d578611616385321fe1387df1ea36ebd794c14'
MASTER='https://v3.leikwanhost.com'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE 'set +e
 echo "=== slave $(hostname) $(date -Is) sidecar access prep ==="
 command -v sshpass || (apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y sshpass)
 echo "-- sidecar tcp ports --"; for p in 22 14950 8899 2053 5201; do timeout 4 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1 && echo "$p open" || echo "$p closed"; done
'
# Try sidecar passwords via slave jump, inspect only if login works.
for pass in 'Frank1212.' 'Frank20021212.' 'GK@Sp7z%T!s4*w5P'; do
  echo "=== try sidecar via slave with candidate pass length ${#pass} ==="
  set +e
  sshpass -p "$SPASS" ssh "${SSH_OPTS[@]}" root@$SLAVE "sshpass -p '$pass' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o ConnectTimeout=8 root@$SIDE 'echo SIDE_OK; hostname; date -Is; grep -n \"AGENT_VERSION\" /usr/local/bin/leikwan-sidecar-agent.py 2>/dev/null | head -3; systemctl is-active leikwan-bond-sidecar-5-agent.service leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service 2>/dev/null || true; ss -ltnp | grep -E \":(2053|8899|5201|2600[0-9]|260[0-9][0-9])\" || true; ls -l /var/lib/leikwan-sidecar-5 /etc/leikwan-sidecar-5 2>/dev/null | head -80'" 
  rc=$?
  set -e
  if [ $rc -eq 0 ]; then
    echo "LOGIN_OK_PASS=$pass" > /tmp/codex_sidecar_login_ok.env
    exit 0
  fi
done
exit 1