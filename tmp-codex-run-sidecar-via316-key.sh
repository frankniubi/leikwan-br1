#!/usr/bin/env bash
set -euo pipefail
SLAVE_PASS_FILE="$1"
sshpass -f "$SLAVE_PASS_FILE" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=8 root@47.116.11.144 'bash -s' <<'REMOTE'
set -uo pipefail
echo "SLAVE=$(hostname) $(date -Is)"
echo --slave-to-sidecar-key--
ssh -T -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 root@185.220.238.143 'bash -s' <<'SIDE'
set -uo pipefail
echo "SIDE=$(hostname) $(date -Is)"
echo --units--
for u in leikwan-bond-sidecar-5.service leikwan-bond-sidecar-5-cf-2053.service leikwan-bond-sidecar-5-iperf3-5201.service leikwan-bond-sidecar-5-iperf3-5202.service leikwan-sidecar-agent.service; do echo "$u active=$(systemctl is-active $u 2>/dev/null || true) enabled=$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo --process--
ps -eo pid,ppid,lstart,cmd | grep -E 'leikwan-bond|leikwan-mptcp-relay|leikwan-wss-mux|iperf3|2053|8899|5201|5202' | grep -v grep || true
echo --ss--
ss -ltnp | grep -E ':(2053|8899|8898|5201|5202|2600[0-9])\b' || true
echo --hashes--
sha256sum /usr/local/bin/leikwan-bond /usr/local/bin/leikwan-wss-mux.py /usr/local/bin/leikwan-sidecar-agent.py 2>/dev/null || true
/usr/local/bin/leikwan-bond --version 2>/dev/null || true
echo --routes--
for p in /var/lib/leikwan-sidecar-5/cf-routes-2053.json /var/lib/leikwan-sidecar-agent/cf-routes-2053.json /etc/systemd/system/leikwan-bond-sidecar-5.service /etc/systemd/system/leikwan-bond-sidecar-5-cf-2053.service /usr/local/bin/leikwan-wss-mux.py; do [ -e "$p" ] && { echo "###$p"; sed -n '1,220p' "$p" | head -220; }; done
echo --logs--
journalctl -u leikwan-bond-sidecar-5.service -u leikwan-bond-sidecar-5-cf-2053.service -u leikwan-bond-sidecar-5-iperf3-5201.service --since '-60 min' --no-pager -o short-iso | grep -E '07289|target|token|bond|hello|invalid|failed|error|WARN|ERROR|5201|5202|400|520|connection|accepted|8899|local_read|recv_reset|cooldown' | tail -260 || true
SIDE
REMOTE