#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
cat > /tmp/codex-target-vs-wst.sh <<'RS'
#!/usr/bin/env bash
set +e
echo ===env===
hostname; date -u; command -v timeout; command -v iperf3
echo ===target-direct-185-5201===
timeout 15 iperf3 -c 185.220.238.143 -p 5201 -R -b 300M -t 5 --json
printf 'direct_rc=%s\n' "$?"
echo ===local-wst-39559-to-8899===
timeout 15 iperf3 -c 127.0.0.1 -p 39559 -R -b 300M -t 5 --json
printf 'wst_rc=%s\n' "$?"
echo ===local-forward-11035===
timeout 20 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 5 --json
printf 'forward_rc=%s\n' "$?"
echo ===recent-service===
journalctl -u leikwan-client-47.service --since '-3 min' --no-pager | tail -n 160
RS
chmod +x /tmp/codex-target-vs-wst.sh
sshpass -p "$PASS_CLIENT" scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null /tmp/codex-target-vs-wst.sh root@114.80.33.50:/tmp/codex-target-vs-wst.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'bash /tmp/codex-target-vs-wst.sh'