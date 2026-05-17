set -euo pipefail
ROOT=/mnt/c/Users/frankniubi/Downloads/leikwan-br1
PASS_CLIENT=$(python3 - <<'PY'
import re, pathlib
s=pathlib.Path('/mnt/c/Users/frankniubi/Downloads/leikwan-br1/docs/superpowers/handoffs/2026-05-16-shanghai-tokyo-selfleg-automation-handoff.md').read_text(encoding='utf-8', errors='ignore')
m=re.search(r'Real client in scope.*?Password: `([^`]+)`', s, re.S)
print(m.group(1) if m else '')
PY
)
export SSHPASS="$PASS_CLIENT"
sshpass -e ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'bash -s' <<'RS'
set +e
echo "=== current ports/processes ==="
ps -ef | grep -E "wstunnel client|leikwan-bond client" | grep -v grep
LP=$(grep -oE "127\.0\.0\.1:[0-9]+:127\.0\.0\.1:8899" /usr/local/bin/leikwan-client-start-47.sh | head -1 | sed -E "s/.*127\.0\.0\.1:([0-9]+):127.*/\1/")
echo direct_leg_port=$LP
echo "=== direct target tcp/iperf ==="
timeout 5 bash -lc '</dev/tcp/185.220.238.143/5201' >/dev/null 2>&1; echo tcp_185_5201_rc=$?
timeout 20 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 --json 2>&1; echo iperf_direct_target_rc=$?
echo "=== local direct WST leg iperf ==="
timeout 20 iperf3 -c 127.0.0.1 -p "$LP" -R -b 300M -t 5 --json 2>&1; echo iperf_direct_wst_rc=$?
echo "=== forward47 bond listener iperf ==="
timeout 20 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 5 --json 2>&1; echo iperf_f47_rc=$?
echo "=== recent f47 logs ==="
journalctl -u leikwan-client-47.service --since '-3 min' --no-pager -n 180
RS
