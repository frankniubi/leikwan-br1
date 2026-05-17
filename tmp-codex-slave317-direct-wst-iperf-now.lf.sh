set -euo pipefail
HOST='root@47.116.104.15'
PASS='U3I+sVL^%IXyqMX0'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$HOST" 'bash -s' <<'REMOTE'
set -euo pipefail
printf 'date=%s host=%s\n' "$(date -Is)" "$(hostname)"
for p in 5201 5202; do
  echo "-- direct wstunnel iperf target $p via local backend 7002 -b 300M --"
  timeout 15 iperf3 -c 127.0.0.1 -p 7002 -R -b 300M -t 5 --connect-timeout 3000 >"/tmp/codex-directwst-$p.txt" 2>&1 || true
  cat "/tmp/codex-directwst-$p.txt"
done
echo "-- recent wstunnel --"
journalctl -u wstunnel-svc-2-317.service --since '-2 min' --no-pager -o short-iso | grep -E 'Opening TCP connection|Invalid status code|520|handshake|Closing|Connection refused|error|WARN|INFO' | tail -160 || true
REMOTE
