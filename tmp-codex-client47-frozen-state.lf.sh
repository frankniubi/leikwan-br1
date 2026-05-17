set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH_OPTS[@]}" root@$CLIENT 'set +e
 echo "=== client47 frozen state $(date -Is) ==="
 systemctl status leikwan-client-47.service --no-pager -l | sed -n "1,80p"
 echo ---start-script---; sed -n "1,260p" /usr/local/bin/leikwan-client-start-47.sh 2>/dev/null || true
 echo ---config files---; for f in /etc/leikwan/mptcp-forward-47.json /var/lib/leikwan-client-agent/applied_forwards.json /run/leikwan/bond-leg-health-forward-47.json; do echo ###$f; [ -e $f ] && cat $f | python3 -m json.tool | sed -n "1,220p" || echo missing; done
 echo ---procs---; ps -eo pid,etimes,cmd | grep -E "leikwan-client-start-47|mptcp-forward-47|11035|21739|leikw.*bond|leikw.*mptcp|wstunnel client|leikwan-ingress client" | grep -v grep | sed -n "1,240p"
 echo ---listeners---; ss -ltnp | grep -E ":(11035|2173[0-9]|2174[0-9]|291|39559)" || true
'