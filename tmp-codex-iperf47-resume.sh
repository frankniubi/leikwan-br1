set -euo pipefail
export SSHPASS='Frank20021212.'
sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no root@114.80.33.50 'bash -s' <<'RS'
set +e
echo ===iperf local target direct===
timeout 8 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 3 2>&1 | sed -n '1,60p'
echo rc=${PIPESTATUS[0]}
echo ===local through 11035===
timeout 12 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 5 2>&1 | sed -n '1,80p'
echo rc=${PIPESTATUS[0]}
echo ===after logs===
journalctl -u leikwan-client-47.service --since '-25 sec' --no-pager | tail -100
RS
