set -euo pipefail
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +euo pipefail
echo "date=$(date -Is)"
echo "-- sidecar public tcp --"
for p in 22 2053 8898 8899 5201 5202 14950 14969; do timeout 2 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1 && echo "$p open" || echo "$p fail"; done
echo "-- direct sidecar5201 iperf -R -b 300M --"
timeout 18 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 8 --connect-timeout 3000 2>&1 | sed -n '1,80p'; echo rc=${PIPESTATUS[0]}
echo "-- local onegood 5201 no reverse --"
timeout 18 iperf3 -c 127.0.0.1 -p 11035 -b 300M -t 8 --connect-timeout 3000 2>&1 | sed -n '1,80p'; echo rc=${PIPESTATUS[0]}
REMOTE