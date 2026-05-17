set -uo pipefail
echo "SLAVE=$(hostname) $(date -Is)"
echo '--- direct sidecar ssh from slave ---'
SSHPASS="$SIDEPASS" sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 root@185.220.238.143 'bash -s' <<'SIDE'
set -uo pipefail
echo "SIDE=$(hostname) $(date -Is)"
echo --units--
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-bond-sidecar-5-iperf3-5201.service leikwan-bond-sidecar-5-iperf3-5202.service; do echo "$u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo --ss--
ss -ltnp | grep -E ':(2053|8899|8898|5201|5202|2600[0-9])\b' || true
echo --routes--
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-agent/cf-routes-2053.json /etc/systemd/system/leikwan-bond-sidecar-5.service /etc/systemd/system/leikwan-bond-sidecar-5-cf-2053.service /usr/local/bin/leikwan-wss-mux.py; do [ -e "$p" ] && { echo "###$p"; sed -n '1,180p' "$p" | head -180; }; done
echo --logs--
journalctl -u leikwan-bond-sidecar-5.service -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5-iperf3-5201.service --since '-30 min' --no-pager -o short-iso | grep -E '07289|target|token|bond|hello|invalid|failed|error|WARN|ERROR|5201|5202|400|520|connection|accepted|8899|local_read|recv_reset|cooldown' | tail -220 || true
SIDE
echo ssh_rc=$?
