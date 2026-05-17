set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1)
while IFS='|' read -r host region user pass rest; do
  [ -n "$host" ] || continue
  echo "===== SHANGHAI $host ====="
  sshpass -p "$pass" ssh "${SSH_OPTS[@]}" "$user@$host" 'set +e
    echo "time=$(date -Is) host=$(hostname)"
    echo "-- port reach to tokyo sidecar --"
    for p in 8899 8898 2053 5201 22; do timeout 4 bash -lc "</dev/tcp/185.220.238.143/$p" >/dev/null 2>&1 && echo "185.220.238.143:$p open" || echo "185.220.238.143:$p fail"; done
    echo "-- service states --"
    systemctl is-active leikwan-slave-agent.service leikwan-tcp-mux-7000.service 2>/dev/null || true
    systemctl list-units --type=service --all "wstunnel-svc-2-*.service" "leikwan-tcp-mux-7000.service" --no-legend --no-pager | sed -n "1,120p"
    echo "-- unit snippets path/token/target/backend --"
    grep -R "07289d1f5ed25399\|185.220.238.143\|8899\|8898\|leikwan-ingress backend\|wstunnel server" -n /etc/systemd/system /etc/leikwan* /var/lib/leikwan* 2>/dev/null | sed -n "1,180p"
    echo "-- recent route/bond errors --"
    journalctl -u leikwan-tcp-mux-7000.service -u leikwan-slave-agent.service --since "-45 min" --no-pager -o short-iso 2>/dev/null | grep -E "07289d1f5ed25399|8899|invalid bond frame|failed to read bond|Parse\(Method\)|ERROR|WARN|accepted|route|backend|wstunnel-svc|target" | tail -180 || true
  ' 2>&1 | sed -e 's/\r$//'
  echo
done < shanghais.txt