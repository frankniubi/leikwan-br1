set -euo pipefail
printf '=== segmented iperf forward47 self-leg ===\n'
which iperf3 || true
printf '\n-- direct sidecar public 185.220.238.143:5201 --\n'
timeout 12 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 2>&1 || true
printf '\n-- local direct WST leg 127.0.0.1:21739 --\n'
timeout 12 iperf3 -c 127.0.0.1 -p 21739 -R -b 300M -t 5 2>&1 || true
printf '\n-- forward47 listener 127.0.0.1:11035 --\n'
timeout 12 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 5 2>&1 || true
printf '\n=== logs after tests ===\n'
journalctl -u leikwan-client-47.service -n 80 --no-pager -o short-iso 2>/dev/null | tail -80 || true
