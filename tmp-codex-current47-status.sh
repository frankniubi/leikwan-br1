#!/usr/bin/env bash
set -euo pipefail
PASS_CLIENT='Frank20021212.'
sshpass -p "$PASS_CLIENT" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@114.80.33.50 'set +e
printf "now="; date -u
systemctl is-active leikwan-client-47.service; echo active_rc=$?
systemctl status leikwan-client-47.service --no-pager -l | sed -n "1,180p"
echo ===ps===
ps -ef | grep -E "leikwan-client-start-47|leikwan-mptcp-relay|wstunnel client.*39559" | grep -v grep || true
echo ===ss===
ss -ltnp | grep -E ":(11035|39559|8898|8899)" || true
echo ===script===
sed -n "1,240p" /usr/local/bin/leikwan-client-start-47.sh
echo ===unit===
systemctl cat leikwan-client-47.service
echo ===journal47full===
journalctl -u leikwan-client-47.service --since "-10 min" --no-pager | tail -n 260
echo ===dmesg-tail===
dmesg -T | tail -n 80
'