#!/usr/bin/env bash
set -euo pipefail
sshpass -p 'Frank20021212.' ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 root@114.80.33.50 'bash -s' <<'REMOTE'
set +e
before=$(mktemp)
after=$(mktemp)
journalctl -u leikwan-client-47.service -n 220 --no-pager -o short-iso > "$before" 2>/dev/null
printf 'date=%s\n' "$(date -Is)"
printf 'service=%s\n' "$(systemctl is-active leikwan-client-47.service 2>/dev/null)"
printf 'listener: '; ss -ltnp | grep ':11035 ' || true
printf '\n=== iperf reverse -b 300M -t 12 ===\n'
timeout 35 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 12 --connect-timeout 3000 2>&1 | tee /tmp/codex-f47-current-iperf.out
printf '\n=== new journal key ===\n'
journalctl -u leikwan-client-47.service --since '2 minutes ago' --no-pager -o short-iso | egrep -i 'accepted local|session established|failed|invalid|magic|cooldown|reset|520|handshake|local_(read|write)_failed|Broken|error|warn|stream_open|ack' | tail -180
printf '\n=== leg snapshot ===\n'
cat /run/leikwan/bond-leg-health-forward-47.json 2>/dev/null || true
printf '\n=== service after ===\n'
systemctl is-active leikwan-client-47.service || true
ss -ltnp | egrep ':(11035|21739|21740)\b' || true
REMOTE